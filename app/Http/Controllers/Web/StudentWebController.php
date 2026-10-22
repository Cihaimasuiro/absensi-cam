<?php

namespace App\Http\Controllers\Web;

use App\Domain\Enrollment\Actions\EnrollFace;
use App\Domain\Student\Queries\StudentRosterQuery;
use App\Domain\Student\Models\Student;
use App\Domain\School\Models\Classroom;
use App\Domain\School\Models\School;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Student\StoreStudentRequest;
use App\Http\Requests\Web\Student\UpdateStudentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentWebController extends Controller
{
    public function __construct(
        protected EnrollFace $enrollFace,
        protected StudentRosterQuery $rosterQuery,
    ) {}

    public function index(Request $request)
    {
        $isRoster = $request->has('classroom_id') 
            || $request->filled('search') 
            || $request->filled('filter') 
            || $request->boolean('view_all');

        $classrooms = Classroom::active()->select(['id', 'name', 'school_id', 'type', 'start_time'])->orderBy('name')->get();
        $schools = School::active()->select(['id', 'name'])->orderBy('name')->get();

        if (! $isRoster) {
            $classroomsWithStats = Classroom::active()
                ->with(['school:id,name', 'building:id,name'])
                ->withCount([
                    'students' => fn ($q) => $q->where('is_active', true),
                    'students as enrolled_count' => fn ($q) => $q->where('is_active', true)->whereHas('faceTemplate'),
                ])
                ->orderBy('name')
                ->get();

            $unassignedCount = Student::where('is_active', true)->whereNull('classroom_id')->count();
            $unassignedEnrolled = Student::where('is_active', true)->whereNull('classroom_id')->whereHas('faceTemplate')->count();
            $totalMembers = Student::where('is_active', true)->count();
            $totalEnrolled = Student::where('is_active', true)->whereHas('faceTemplate')->count();

            return view('members.index', [
                'isRoster'            => false,
                'classroomsWithStats' => $classroomsWithStats,
                'unassignedCount'     => $unassignedCount,
                'unassignedEnrolled'  => $unassignedEnrolled,
                'totalMembers'        => $totalMembers,
                'totalEnrolled'       => $totalEnrolled,
                'classrooms'          => $classrooms,
                'schools'             => $schools,
            ]);
        }

        $activeClassroom = null;
        if ($request->classroom_id && $request->classroom_id !== 'unassigned') {
            $activeClassroom = Classroom::with(['school:id,name', 'building:id,name'])->find($request->classroom_id);
        }

        $students = $this->rosterQuery->paginate($request);

        return view('members.index', [
            'isRoster'        => true,
            'students'        => $students,
            'activeClassroom' => $activeClassroom,
            'classrooms'      => $classrooms,
            'schools'         => $schools,
        ]);
    }

    public function store(StoreStudentRequest $request)
    {
        $validated = $request->validated();

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

    public function update(UpdateStudentRequest $request, Student $student)
    {
        $validated = $request->validated();

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
            // Hapus juga face template jika consent ditarik
            if ($student->faceTemplate) {
                $student->faceTemplate->delete();
            }
            activity('student')->performedOn($student)->causedBy(auth()->user())->log('Consent withdrawn');
        }

        return redirect()->route('students.index')->with('success', 'Anggota berhasil diperbarui.');
    }

    public function destroy(Student $student)
    {
        $student->delete(); // soft-delete memicu sinkronisasi tombstone
        
        activity('student')
            ->performedOn($student)
            ->causedBy(auth()->user())
            ->log('Student deleted');

        return redirect()->route('students.index')->with('success', 'Anggota dihapus dan akan dikeluarkan dari perangkat.');
    }
    public function downloadTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Domain\Student\Exports\StudentsTemplateExport,
            'Template_Impor_Anggota.xlsx'
        );
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
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            $messages = [];
            foreach ($failures as $failure) {
                $messages[] = "Baris {$failure->row()}: " . implode(', ', $failure->errors());
            }
            // batasi ke 3 error pertama agar flash message tidak terlalu besar
            if (count($messages) > 3) {
                $messages = array_slice($messages, 0, 3);
                $messages[] = '...dan kesalahan lainnya.';
            }
            return redirect()->route('students.index')->with('error', 'Validasi gagal: ' . implode(' | ', $messages));
        } catch (\Throwable $e) {
            Log::error('Student import error: ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->route('students.index')->with('error', 'Gagal mengimpor data. Format file tidak sesuai atau terjadi kesalahan sistem.');
        }
    }


    public function enrollStore(\App\Http\Requests\Web\EnrollFaceRequest $request, Student $student)
    {
        $allowDuplicate = false;
        if ($request->boolean('allow_duplicate')) {
            $user = $request->user();
            if ($user && $user->can('enrollment.bypass-duplicate')) {
                $allowDuplicate = true;
            }
        }

        try {
            $this->enrollFace->execute(
                $student,
                $request->file('photo'),
                $allowDuplicate
            );
        } catch (\App\Domain\Enrollment\Exceptions\DuplicateFaceException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Enrollment berhasil diproses. Template wajah telah aktif.'
        ]);
    }

    public function photo(Student $student)
    {
        $this->authorize('viewPhoto', $student);

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
            'zip_file' => ['required', 'file', 'mimes:zip', 'max:51200'], // maksimal 50MB
            'has_consent' => ['required', 'boolean'],
        ]);

        try {
            $zip = new \ZipArchive;
            $res = $zip->open($request->file('zip_file')->path());
            if ($res === true) {
                // Pemeriksaan awal terhadap Zip Slip
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if (str_contains($stat['name'], '../') || str_contains($stat['name'], '..\\')) {
                        $zip->close();
                        return redirect()->route('students.index')->with('error', 'ZIP file contains invalid paths (Zip Slip detected).');
                    }
                }

                // Ekstrak ke folder temp
                $extractPath = storage_path('app/tmp/bulk_enroll_' . \Illuminate\Support\Str::uuid());
                if (!\Illuminate\Support\Facades\File::exists($extractPath)) {
                    \Illuminate\Support\Facades\File::makeDirectory($extractPath, 0755, true);
                }
                
                $zip->extractTo($extractPath);
                $zip->close();
                
                // Baca semua file
                $files = \Illuminate\Support\Facades\File::allFiles($extractPath);
                $successCount = 0;
                $skippedCount = 0;
                $errors = [];

                foreach ($files as $file) {
                    $filename = $file->getFilename();
                    
                    // Lewati file tersembunyi dan non-gambar
                    if (str_starts_with($filename, '.')) continue;
                    $ext = strtolower($file->getExtension());
                    if (!in_array($ext, ['jpg', 'jpeg', 'png'])) continue;

                    $code = pathinfo($filename, PATHINFO_FILENAME);
                    
                    $student = Student::where('code', $code)->first();
                    if ($student) {
                        // Pastikan consent tercatat
                        if (!$student->hasActiveConsent() && $request->boolean('has_consent')) {
                            $student->consents()->create([
                                'given_at'     => now(),
                                'text_version' => 'v1.0 (bulk)',
                                'recorded_by'  => auth()->id(),
                            ]);
                        }
                        
                        // Buat UploadedFile palsu
                        $uploadedFile = new \Illuminate\Http\UploadedFile(
                            $file->getPathname(),
                            $file->getFilename(),
                            \Illuminate\Support\Facades\File::mimeType($file->getPathname()),
                            null,
                            true
                        );

                        $this->enrollFace->execute($student, $uploadedFile);
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
        } catch (\Throwable $e) {
            Log::error('Bulk enroll ZIP extraction failed: ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->route('students.index')->with('error', 'Gagal memproses berkas ZIP. Pastikan file valid.');
        } finally {
            if (isset($extractPath) && \Illuminate\Support\Facades\File::exists($extractPath)) {
                \Illuminate\Support\Facades\File::deleteDirectory($extractPath);
            }
        }
    }
}
