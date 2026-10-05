<?php

namespace App\Http\Controllers\Web;

use App\Domain\Student\Models\Student;
use App\Domain\School\Models\Classroom;
use App\Domain\School\Models\School;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StudentWebController extends Controller
{
    public function index(Request $request)
    {
        $students = Student::with(['group:id,name', 'school:id,name', 'faceTemplate:id,student_id'])
            ->when($request->search, fn ($q) =>
                $q->where(fn ($q) =>
                    $q->where('name', 'like', '%'.$request->search.'%')
                      ->orWhere('code', 'like', '%'.$request->search.'%')
                ))
            ->when($request->filter === 'enrolled',     fn ($q) => $q->whereHas('faceTemplate'))
            ->when($request->filter === 'non-enrolled', fn ($q) => $q->whereDoesntHave('faceTemplate'))
            ->when($request->filter === 'inactive',     fn ($q) => $q->where('is_active', false))
            ->when($request->filter !== 'inactive',     fn ($q) => $q->where('is_active', true))
            ->when($request->classroom_id, fn ($q) => $q->where('classroom_id', $request->classroom_id))
            ->select(['id', 'code', 'name', 'email', 'role', 'classroom_id', 'school_id', 'is_active', 'created_at'])
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $classrooms = Classroom::active()->select(['id', 'name', 'school_id'])->orderBy('name')->get();
        $schools = School::active()->select(['id', 'name'])->orderBy('name')->get();

        return view('students.index', compact('students', 'classrooms', 'schools'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'school_id' => ['nullable', 'exists:schools,id'],
            'classroom_id'        => ['nullable', 'exists:classrooms,id'],
            'code'            => ['required', 'string', 'max:50', 'unique:students,code'],
            'name'            => ['required', 'string', 'max:150'],
            'email'           => ['nullable', 'email', 'max:191', 'unique:students,email'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'role'            => ['nullable', 'string', 'max:50'],
            'has_consent'     => ['nullable', 'boolean'],
        ]);

        $student = Student::create(array_merge(
            \Illuminate\Support\Arr::except($validated, ['has_consent']),
            ['is_active' => true]
        ));

        if ($request->boolean('has_consent')) {
            $student->consents()->create([
                'given_at'     => now(),
                'text_version' => 'v1.0',
                'recorded_by'  => auth()->id() ?? \App\Domain\User\Models\User::first()?->id ?? 1,
            ]);
        }

        return redirect()->route('students.index')->with('success', 'Anggota berhasil ditambahkan.');
    }

    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
            'school_id' => ['nullable', 'exists:schools,id'],
            'classroom_id'        => ['nullable', 'exists:classrooms,id'],
            'code'            => ['required', 'string', 'max:50', "unique:students,code,{$student->id}"],
            'name'            => ['required', 'string', 'max:150'],
            'email'           => ['nullable', 'email', 'max:191', "unique:students,email,{$student->id}"],
            'phone'           => ['nullable', 'string', 'max:30'],
            'role'            => ['nullable', 'string', 'max:50'],
            'is_active'       => ['boolean'],
            'has_consent'     => ['nullable', 'boolean'],
        ]);

        $student->update(\Illuminate\Support\Arr::except($validated, ['has_consent']));

        if ($request->boolean('has_consent') && !$student->hasActiveConsent()) {
            $student->consents()->create([
                'given_at'     => now(),
                'text_version' => 'v1.0',
                'recorded_by'  => auth()->id() ?? \App\Domain\User\Models\User::first()?->id ?? 1,
            ]);
        } elseif (!$request->boolean('has_consent') && $student->hasActiveConsent()) {
            $student->activeConsent()->update(['withdrawn_at' => now()]);
            // Also delete face template if consent is withdrawn
            if ($student->faceTemplate) {
                $student->faceTemplate->delete();
            }
        }

        return redirect()->route('students.index')->with('success', 'Anggota berhasil diperbarui.');
    }

    public function destroy(Student $student)
    {
        $student->delete(); // soft-delete triggers tombstone sync

        return redirect()->route('students.index')->with('success', 'Anggota dihapus dan akan dikeluarkan dari perangkat.');
    }
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,xls', 'max:10240'],
        ]);

        try {
            \Maatwebsite\Excel\Facades\Excel::import(
                new \App\Domain\Student\Imports\StudentsImport, 
                $request->file('file')
            );
            return redirect()->route('students.index')->with('success', 'Data anggota berhasil diimpor.');
        } catch (\Exception $e) {
            return redirect()->route('students.index')->with('error', 'Gagal mengimpor data: ' . $e->getMessage());
        }
    }


    public function enrollStore(\App\Http\Requests\Web\EnrollFaceRequest $request, Student $student)
    {
        (new \App\Domain\Enrollment\Actions\EnrollFace)->execute($student, $request->file('photo'));

        return response()->json([
            'success' => true,
            'message' => 'Enrollment dijadwalkan. Template akan tersedia dalam beberapa detik.'
        ]);
    }

    public function bulkEnroll(Request $request)
    {
        $request->validate([
            'zip_file' => ['required', 'file', 'mimes:zip', 'max:51200'], // 50MB max
            'has_consent' => ['required', 'boolean'],
        ]);

        try {
            $zip = new \ZipArchive;
            $res = $zip->open($request->file('zip_file')->path());
            if ($res === true) {
                // Extract to temp folder
                $extractPath = storage_path('app/tmp/bulk_enroll_' . \Illuminate\Support\Str::uuid());
                if (!\Illuminate\Support\Facades\File::exists($extractPath)) {
                    \Illuminate\Support\Facades\File::makeDirectory($extractPath, 0755, true);
                }
                
                $zip->extractTo($extractPath);
                $zip->close();
                
                // Read all files
                $files = \Illuminate\Support\Facades\File::allFiles($extractPath);
                $successCount = 0;
                $skippedCount = 0;

                foreach ($files as $file) {
                    // Skip hidden files like .DS_Store
                    if (str_starts_with($file->getFilename(), '.')) continue;

                    $code = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                    
                    $student = Student::where('code', $code)->first();
                    if ($student) {
                        // Make sure consent is recorded
                        if (!$student->hasActiveConsent() && $request->boolean('has_consent')) {
                            $student->consents()->create([
                                'given_at'     => now(),
                                'text_version' => 'v1.0 (bulk)',
                                'recorded_by'  => auth()->id() ?? \App\Domain\User\Models\User::first()?->id ?? 1,
                            ]);
                        }
                        
                        // Fake an UploadedFile
                        $uploadedFile = new \Illuminate\Http\UploadedFile(
                            $file->getPathname(),
                            $file->getFilename(),
                            \Illuminate\Support\Facades\File::mimeType($file->getPathname()),
                            null,
                            true
                        );

                        (new \App\Domain\Enrollment\Actions\EnrollFace)->execute($student, $uploadedFile);
                        $successCount++;
                    } else {
                        $skippedCount++;
                    }
                }

                return redirect()->route('students.index')->with('success', "Proses pendaftaran massal dijadwalkan: $successCount siswa berhasil, $skippedCount tidak ditemukan/dilewati.");
            } else {
                return redirect()->route('students.index')->with('error', 'Gagal membuka berkas ZIP.');
            }
        } catch (\Exception $e) {
            return redirect()->route('students.index')->with('error', 'Gagal memproses ZIP: ' . $e->getMessage());
        }
    }
}
