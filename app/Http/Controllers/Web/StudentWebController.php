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
        $students = Student::with(['group:id,name', 'school:id,name', 'faceTemplate:id,student_id,photo_path,created_at,updated_at'])
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
                'recorded_by'  => auth()->id(),
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
                'recorded_by'  => auth()->id(),
            ]);
            activity('student')->performedOn($student)->causedBy(auth()->user())->log('Consent given');
        } elseif (!$request->boolean('has_consent') && $student->hasActiveConsent()) {
            $student->activeConsent()->update(['withdrawn_at' => now()]);
            // Also delete face template if consent is withdrawn
            if ($student->faceTemplate) {
                $student->faceTemplate->delete();
            }
            activity('student')->performedOn($student)->causedBy(auth()->user())->log('Consent withdrawn');
        }

        return redirect()->route('students.index')->with('success', 'Anggota berhasil diperbarui.');
    }

    public function destroy(Student $student)
    {
        $student->delete(); // soft-delete triggers tombstone sync
        
        activity('student')
            ->performedOn($student)
            ->causedBy(auth()->user())
            ->log('Student deleted');

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
            'message' => 'Enrollment berhasil diproses. Template wajah telah aktif.'
        ]);
    }

    public function photo(Student $student)
    {
        $template = $student->faceTemplate;
        if (! $template || ! $template->photo_path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($template->photo_path)) {
            abort(404, 'Foto wajah tidak ditemukan');
        }

        $path = \Illuminate\Support\Facades\Storage::disk('local')->path($template->photo_path);
        return response()->file($path);
    }

    public function enrollDestroy(Request $request, Student $student)
    {
        $template = $student->faceTemplate;
        if ($template) {
            if ($template->photo_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($template->photo_path)) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($template->photo_path);
                $template->photo_path = null;
            }
            $template->delete();
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Data biometrik wajah berhasil dihapus.'
            ]);
        }

        return redirect()->route('students.index')->with('success', 'Data biometrik wajah berhasil dihapus.');
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
                // Pre-flight check for Zip Slip
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if (str_contains($stat['name'], '../') || str_contains($stat['name'], '..\\')) {
                        $zip->close();
                        return redirect()->route('students.index')->with('error', 'ZIP file contains invalid paths (Zip Slip detected).');
                    }
                }

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
                $errors = [];

                foreach ($files as $file) {
                    $filename = $file->getFilename();
                    
                    // Skip hidden files and non-images
                    if (str_starts_with($filename, '.')) continue;
                    $ext = strtolower($file->getExtension());
                    if (!in_array($ext, ['jpg', 'jpeg', 'png'])) continue;

                    $code = pathinfo($filename, PATHINFO_FILENAME);
                    
                    $student = Student::where('code', $code)->first();
                    if ($student) {
                        // Make sure consent is recorded
                        if (!$student->hasActiveConsent() && $request->boolean('has_consent')) {
                            $student->consents()->create([
                                'given_at'     => now(),
                                'text_version' => 'v1.0 (bulk)',
                                'recorded_by'  => auth()->id(),
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
                        $errors[] = "File: {$filename} - unknown_student (Kode: {$code})";
                    }
                }

                $msg = "Proses pendaftaran massal dijadwalkan: $successCount siswa berhasil, $skippedCount tidak ditemukan/dilewati.";
                if (count($errors) > 0) {
                    return redirect()->route('students.index')
                        ->with('success', $msg)
                        ->with('bulk_errors', $errors);
                }

                return redirect()->route('students.index')->with('success', $msg);
            } else {
                return redirect()->route('students.index')->with('error', 'Gagal membuka berkas ZIP.');
            }
        } catch (\Exception $e) {
            return redirect()->route('students.index')->with('error', 'Gagal memproses ZIP: ' . $e->getMessage());
        } finally {
            if (isset($extractPath) && \Illuminate\Support\Facades\File::exists($extractPath)) {
                \Illuminate\Support\Facades\File::deleteDirectory($extractPath);
            }
        }
    }
}
