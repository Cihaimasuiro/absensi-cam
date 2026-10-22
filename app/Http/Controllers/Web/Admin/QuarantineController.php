<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Device\Models\Device;
use App\Domain\Enrollment\Models\BiometricQuarantine;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Enrollment\Services\FaceEncryptionService;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateFaceEmbedding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        $quarantinedCount = 0;
        $healthyCount = 0;
        $integrityReasons = ['decryption_failed', 'empty_ciphertext', 'invalid_embedding_length'];

        FaceTemplate::query()
            ->select(['id', 'student_id', 'embedding_enc', 'model_version'])
            ->chunkById(250, function ($templates) use (&$quarantinedCount, &$healthyCount, $encryptionService, $integrityReasons) {
                $corruptedItems = [];
                $healthyStudentIds = [];

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
                        $corruptedItems[] = [
                            'student_id'    => $template->student_id,
                            'reason'        => $reason,
                            'error_details' => $details,
                        ];
                    } else {
                        $healthyStudentIds[] = $template->student_id;
                    }
                }

                // Proses massal item rusak: insert jika belum aktif di karantina
                if (!empty($corruptedItems)) {
                    $chunkCorruptedIds = array_column($corruptedItems, 'student_id');
                    $alreadyQuarantined = BiometricQuarantine::whereIn('student_id', $chunkCorruptedIds)
                        ->where('status', 'quarantined')
                        ->pluck('student_id')
                        ->toArray();

                    $toInsert = [];
                    foreach ($corruptedItems as $item) {
                        if (!in_array($item['student_id'], $alreadyQuarantined)) {
                            $toInsert[] = [
                                'student_id'     => $item['student_id'],
                                'reason'         => $item['reason'],
                                'error_details'  => $item['error_details'],
                                'status'         => 'quarantined',
                                'quarantined_at' => now(),
                                'created_at'     => now(),
                                'updated_at'     => now(),
                            ];
                        }
                    }

                    if (!empty($toInsert)) {
                        BiometricQuarantine::insert($toInsert);
                    }
                    $quarantinedCount += count($corruptedItems);
                }

                // Selesaikan item sehat secara massal (HANYA jika sebelumnya dikarantina karena kegagalan integritas)
                if (!empty($healthyStudentIds)) {
                    BiometricQuarantine::whereIn('student_id', $healthyStudentIds)
                        ->where('status', 'quarantined')
                        ->whereIn('reason', $integrityReasons)
                        ->update([
                            'status'           => 'resolved',
                            'resolved_at'      => now(),
                            'resolved_by'      => auth()->id(),
                            'resolution_notes' => 'Otomatis terselesaikan melalui scan integritas biometrik.',
                        ]);
                    $healthyCount += count($healthyStudentIds);
                }
            });

        activity('quarantine')
            ->causedBy(auth()->user())
            ->withProperties(['healthy' => $healthyCount, 'quarantined' => $quarantinedCount])
            ->log("Biometric integrity scan completed: {$healthyCount} healthy, {$quarantinedCount} quarantined");

        return redirect()->route('admin.quarantine.index')
            ->with('success', "Pemeriksaan selesai: {$healthyCount} template sehat, {$quarantinedCount} template dikarantina.");
    }

    public function reEnroll(BiometricQuarantine $quarantine)
    {
        $this->authorize('quarantine.view');

        $student = $quarantine->student;
        if (!$student) {
            return back()->with('error', 'Data siswa tidak ditemukan.');
        }

        if (!$student->hasActiveConsent()) {
            return back()->with('error', "Gagal mendaftarkan ulang: Siswa {$student->name} tidak memiliki persetujuan biometrik aktif (UU PDP No. 27/2022).");
        }

        $template = $student->faceTemplate;
        $photoPath = $template?->photo_path ?? "faces/{$student->id}.jpg";

        if (!Storage::disk('local')->exists($photoPath)) {
            return back()->with('error', "Foto anggota {$student->name} tidak ditemukan di server ({$photoPath}). Silakan unggah foto baru melalui menu Anggota.");
        }

        // Salin file ke tmp untuk pemrosesan melalui queue (meniru action EnrollFace)
        $ext = pathinfo($photoPath, PATHINFO_EXTENSION) ?: 'jpg';
        $tmpFilename = "reenroll_{$student->id}_" . Str::uuid() . ".{$ext}";
        Storage::disk('local')->copy($photoPath, "tmp/{$tmpFilename}");
        $tmpPath = Storage::disk('local')->path("tmp/{$tmpFilename}");

        // Dispatch background job pada queue enrollments
        GenerateFaceEmbedding::dispatch(
            (string) $student->id,
            $tmpPath,
            $photoPath,
            true,
            config('app.model_version'),
            false,
            $quarantine->id,
            auth()->id()
        )->onQueue('enrollments');

        $quarantine->update([
            'resolution_notes' => 'Tugas re-enrollment telah dimasukkan ke antrean pemrosesan (enrollments queue).',
        ]);

        activity('quarantine')
            ->performedOn($student)
            ->causedBy(auth()->user())
            ->log("Dispatched background re-enrollment for student {$student->name}");

        return redirect()->route('admin.quarantine.index')
            ->with('success', "Proses ekstraksi ulang wajah untuk {$student->name} telah dimasukkan ke antrean latar belakang (enrollments queue).");
    }

    public function resolve(Request $request, BiometricQuarantine $quarantine)
    {
        $this->authorize('quarantine.view');

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $notes = $validated['notes'] ?? 'Ditandai selesai secara manual oleh administrator.';

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
