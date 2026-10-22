<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Device\Models\Device;
use App\Domain\Enrollment\Models\BiometricQuarantine;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Enrollment\Services\BiometricDuplicateDetector;
use App\Domain\Enrollment\Services\FaceEncryptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class QuarantineController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('quarantine.view');

        $status = $request->query('status', 'quarantined');
        $search = $request->query('search');

        $quarantines = BiometricQuarantine::with(['student.classroom', 'device', 'resolver'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search, function ($q, $search) {
                $q->whereHas('student', function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                       ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->latest('quarantined_at')
            ->paginate(25)
            ->withQueryString();

        $devicesWithQuarantine = Device::where('quarantined_templates_count', '>', 0)->get();
        $totalActive = BiometricQuarantine::where('status', 'quarantined')->count();
        $totalResolved = BiometricQuarantine::where('status', 'resolved')->count();

        return view('admin.quarantine.index', compact(
            'quarantines',
            'devicesWithQuarantine',
            'status',
            'search',
            'totalActive',
            'totalResolved'
        ));
    }

    public function scan(FaceEncryptionService $encryptionService)
    {
        $this->authorize('quarantine.view');

        $templates = FaceTemplate::with('student')->get();
        $quarantinedCount = 0;
        $healthyCount = 0;

        foreach ($templates as $template) {
            $isCorrupted = false;
            $reason = '';
            $details = '';

            if (empty($template->embedding_enc)) {
                $isCorrupted = true;
                $reason = 'empty_ciphertext';
                $details = 'Template memiliki embedding_enc kosong di database.';
            } else {
                try {
                    $raw = $encryptionService->decrypt(
                        base64_decode($template->embedding_enc),
                        (string) $template->student_id,
                        (string) ($template->model_version ?: config('app.model_version'))
                    );

                    if (strlen($raw) !== 2048) {
                        $isCorrupted = true;
                        $reason = 'invalid_embedding_length';
                        $details = 'Ukuran vektor bukan 2048 bytes (terdeteksi: ' . strlen($raw) . ' bytes).';
                    }
                } catch (\Throwable $e) {
                    $isCorrupted = true;
                    $reason = 'decryption_failed';
                    $details = 'Gagal didekripsi dengan kunci aktif: ' . $e->getMessage();
                }
            }

            if ($isCorrupted) {
                BiometricQuarantine::firstOrCreate(
                    [
                        'student_id' => $template->student_id,
                        'status'     => 'quarantined',
                    ],
                    [
                        'reason'        => $reason,
                        'error_details' => $details,
                        'quarantined_at'=> now(),
                    ]
                );
                $quarantinedCount++;
            } else {
                // If previously marked as quarantined, mark it resolved
                BiometricQuarantine::where('student_id', $template->student_id)
                    ->where('status', 'quarantined')
                    ->update([
                        'status'           => 'resolved',
                        'resolved_at'      => now(),
                        'resolved_by'      => auth()->id(),
                        'resolution_notes' => 'Otomatis terselesaikan melalui scan integritas biometrik.',
                    ]);
                $healthyCount++;
            }
        }

        activity('quarantine')
            ->causedBy(auth()->user())
            ->withProperties(['healthy' => $healthyCount, 'quarantined' => $quarantinedCount])
            ->log("Biometric integrity scan completed: {$healthyCount} healthy, {$quarantinedCount} quarantined");

        return redirect()->route('admin.quarantine.index')
            ->with('success', "Pemeriksaan selesai: {$healthyCount} template sehat, {$quarantinedCount} template dikarantina.");
    }

    public function reEnroll(
        BiometricQuarantine $quarantine,
        FaceEncryptionService $encryptionService,
        BiometricDuplicateDetector $duplicateDetector
    ) {
        $this->authorize('quarantine.view');

        $student = $quarantine->student;
        if (!$student) {
            return back()->with('error', 'Data siswa tidak ditemukan.');
        }

        $template = $student->faceTemplate;
        $photoPath = $template?->photo_path ?? "faces/{$student->id}.jpg";

        if (!Storage::disk('local')->exists($photoPath)) {
            return back()->with('error', "Foto anggota {$student->name} tidak ditemukan di server ({$photoPath}). Silakan unggah foto baru melalui menu Anggota.");
        }

        $fullPhotoPath = Storage::disk('local')->path($photoPath);
        $pythonBin = config('app.python_bin', 'python');
        $script = base_path('packages/face_core/extract.py');
        $detectorModel = base_path('packages/models/detector.onnx');
        $recognizerModel = base_path('packages/models/recognizer.onnx');

        $result = Process::path(base_path())
            ->timeout(60)
            ->env(['PYTHONPATH' => base_path('packages')])
            ->run([$pythonBin, $script, $detectorModel, $recognizerModel, $fullPhotoPath]);

        if (!$result->successful()) {
            return back()->with('error', 'Ekstraksi wajah gagal: ' . $result->errorOutput());
        }

        $output = json_decode($result->output(), true);
        if (!$output || empty($output['success']) || empty($output['embedding_b64'])) {
            return back()->with('error', 'Hasil ekstraksi wajah tidak valid.');
        }

        $rawEmbedding = base64_decode($output['embedding_b64']);
        if (strlen($rawEmbedding) !== 2048) {
            return back()->with('error', 'Ukuran vektor embedding tidak valid (bukan 2048 bytes).');
        }

        // Check duplicates
        $duplicate = $duplicateDetector->detect($rawEmbedding, (string) $student->id);
        if ($duplicate !== null) {
            $sim = round($duplicate['similarity'], 4);
            return back()->with('error', "Gagal re-enroll: Wajah terdeteksi mirip dengan {$duplicate['student']?->name} (kemiripan: {$sim}).");
        }

        $modelVersion = config('app.model_version');
        $encryptedBlob = $encryptionService->encrypt($rawEmbedding, (string) $student->id, $modelVersion);
        $hash = hash('sha256', $rawEmbedding);

        DB::transaction(function () use ($student, $encryptedBlob, $hash, $modelVersion, $photoPath, $quarantine) {
            $nextCursor = ((int) DB::table('face_templates')->max('version_cursor')) + 1;

            FaceTemplate::updateOrCreate(
                ['student_id' => $student->id],
                [
                    'embedding_enc'  => base64_encode($encryptedBlob),
                    'embedding_hash' => $hash,
                    'model_version'  => $modelVersion,
                    'photo_path'     => $photoPath,
                    'version_cursor' => $nextCursor,
                    'deleted_at'     => null,
                ]
            );

            $quarantine->update([
                'status'           => 'resolved',
                'resolved_at'      => now(),
                'resolved_by'      => auth()->id(),
                'resolution_notes' => 'Berhasil diekstrak dan dienkripsi ulang dari foto tersimpan.',
            ]);

            Cache::forever('templates_hash_epoch', ((int) Cache::get('templates_hash_epoch', 1)) + 1);
        });

        activity('quarantine')
            ->performedOn($student)
            ->causedBy(auth()->user())
            ->log("Student {$student->name} biometric template re-enrolled from quarantine");

        return redirect()->route('admin.quarantine.index')
            ->with('success', "Template wajah {$student->name} berhasil di-reenroll dan dikeluarkan dari karantina.");
    }

    public function resolve(Request $request, BiometricQuarantine $quarantine)
    {
        $this->authorize('quarantine.view');

        $notes = $request->input('notes', 'Ditandai selesai secara manual oleh administrator.');

        $quarantine->update([
            'status'           => 'resolved',
            'resolved_at'      => now(),
            'resolved_by'      => auth()->id(),
            'resolution_notes' => $notes,
        ]);

        activity('quarantine')
            ->performedOn($quarantine)
            ->causedBy(auth()->user())
            ->log("Quarantine ID {$quarantine->id} manually resolved");

        return redirect()->route('admin.quarantine.index')
            ->with('success', 'Status karantina berhasil diperbarui.');
    }
}
