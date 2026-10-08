<?php

namespace App\Domain\Enrollment\Actions;

use App\Domain\Student\Models\Student;
use App\Jobs\GenerateFaceEmbedding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class EnrollFace
{
    public function execute(Student $student, UploadedFile $photo): string
    {
        if (! $student->hasActiveConsent()) {
            throw new \InvalidArgumentException('Biometric consent is required before face enrollment.');
        }

        $ext = $photo->getClientOriginalExtension() ?: $photo->extension() ?: 'jpg';
        $permanentFilename = "faces/{$student->id}.{$ext}";

        // Simpan salinan foto permanen untuk preview/CRUD
        \Illuminate\Support\Facades\Storage::disk('local')->put(
            $permanentFilename,
            file_get_contents($photo->getRealPath())
        );

        // Buat file copy temporer untuk diekstrak python binary
        $tmpFilename = "enroll_{$student->id}_" . Str::uuid() . ".{$ext}";
        $photo->storeAs('tmp', $tmpFilename, 'local');
        $tmpPath = \Illuminate\Support\Facades\Storage::disk('local')->path("tmp/{$tmpFilename}");

        // Dispatch job: simpan photoPath permanen ke FaceTemplate
        GenerateFaceEmbedding::dispatch($student->id, $tmpPath, $permanentFilename, true)
            ->onQueue('enrollments');

        return $tmpPath;
    }
}
