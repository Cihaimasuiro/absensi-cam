<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Student\Models\Consent;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsentController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('consent.manage');

        $status = $request->query('status', 'all');
        $search = $request->query('search');

        $consents = Consent::with(['student.classroom', 'recorder'])
            ->when($status === 'active', function ($q) {
                $q->whereNull('withdrawn_at');
            })
            ->when($status === 'withdrawn', function ($q) {
                $q->whereNotNull('withdrawn_at');
            })
            ->when($search, function ($q, $search) {
                $q->whereHas('student', function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                       ->orWhere('code', 'like', "%{$search}%");
                })->orWhere('guardian_name', 'like', "%{$search}%");
            })
            ->latest('given_at')
            ->paginate(25)
            ->withQueryString();

        $totalActive = Consent::whereNull('withdrawn_at')->count();
        $totalWithdrawn = Consent::whereNotNull('withdrawn_at')->count();
        $totalStudents = Student::count();

        // Siswa tanpa consent aktif untuk dropdown "Record Consent"
        $studentsWithoutConsent = Student::whereDoesntHave('activeConsent')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return view('admin.consents.index', compact(
            'consents',
            'status',
            'search',
            'totalActive',
            'totalWithdrawn',
            'totalStudents',
            'studentsWithoutConsent'
        ));
    }

    public function store(Request $request)
    {
        $this->authorize('consent.manage');

        $data = $request->validate([
            'student_id'        => ['required', 'uuid', 'exists:students,id'],
            'guardian_name'     => ['nullable', 'string', 'max:150'],
            'guardian_relation' => ['nullable', 'string', 'max:50'],
            'notes'             => ['nullable', 'string', 'max:500'],
            'text_version'      => ['nullable', 'string', 'max:20'],
        ]);

        $consent = Consent::create([
            'student_id'        => $data['student_id'],
            'given_at'          => now(),
            'text_version'      => $data['text_version'] ?: 'v1.0',
            'recorded_by'       => auth()->id(),
            'guardian_name'     => $data['guardian_name'] ?? null,
            'guardian_relation' => $data['guardian_relation'] ?? null,
            'notes'             => $data['notes'] ?? null,
        ]);

        $student = Student::find($data['student_id']);

        activity('consent')
            ->performedOn($consent)
            ->causedBy(auth()->user())
            ->withProperties(['student_id' => $student->id, 'student_name' => $student->name])
            ->log("Biometric consent recorded for student {$student->name}");

        return redirect()->route('admin.consents.index')
            ->with('success', "Persetujuan biometrik untuk {$student->name} berhasil dicatat.");
    }

    public function withdraw(Consent $consent)
    {
        $this->authorize('consent.manage');

        if ($consent->withdrawn_at !== null) {
            return back()->with('error', 'Persetujuan biometrik ini sudah ditarik sebelumnya.');
        }

        $student = null;

        DB::transaction(function () use ($consent, &$student) {
            $consent->update(['withdrawn_at' => now()]);
            $student = $consent->student;

            // Soft delete berantai ke face template agar tombstone terkirim ke terminal edge (UU PDP No. 27/2022)
            if ($student && $student->faceTemplate && !$student->faceTemplate->trashed()) {
                $student->faceTemplate->delete();
            }
        });

        activity('consent')
            ->performedOn($consent)
            ->causedBy(auth()->user())
            ->withProperties(['student_id' => $student?->id, 'student_name' => $student?->name])
            ->log("Biometric consent withdrawn for student {$student?->name} and face template tombstoned");

        return redirect()->route('admin.consents.index')
            ->with('success', "Persetujuan biometrik {$student?->name} ditarik. Template wajah telah ditombstone.");
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorize('consent.manage');

        $status = $request->query('status', 'all');

        $query = Consent::with(['student.classroom', 'recorder'])
            ->when($status === 'active', fn ($q) => $q->whereNull('withdrawn_at'))
            ->when($status === 'withdrawn', fn ($q) => $q->whereNotNull('withdrawn_at'))
            ->latest('given_at');

        $filename = 'biometric_consents_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'ID Siswa', 'Kode Anggota', 'Nama Anggota', 'Kelas/Grup', 'Status', 'Waktu Diberikan', 'Waktu Ditarik', 'Nama Wali', 'Hubungan Wali', 'Versi Kebijakan', 'Dicatat Oleh']);

            $query->chunk(500, function ($consents) use ($handle) {
                foreach ($consents as $c) {
                    $student = $c->student;
                    $statusStr = $c->withdrawn_at ? 'DITARIK' : 'AKTIF';
                    $recorder = $c->recorder ? $c->recorder->name : 'Sistem';

                    fputcsv($handle, [
                        $c->id,
                        $student?->id ?? '—',
                        $student?->code ?? '—',
                        $student?->name ?? '—',
                        $student?->classroom?->name ?? '—',
                        $statusStr,
                        $c->given_at ? $c->given_at->format('Y-m-d H:i:s') : '—',
                        $c->withdrawn_at ? $c->withdrawn_at->format('Y-m-d H:i:s') : '—',
                        $c->guardian_name ?? '—',
                        $c->guardian_relation ?? '—',
                        $c->text_version ?? 'v1.0',
                        $recorder,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
