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

        $filename = "enroll_{$student->id}_" . Str::uuid() . ".{$photo->extension()}";
        $photo->storeAs('tmp', $filename, 'local');
        $tmpPath = \Illuminate\Support\Facades\Storage::disk('local')->path("tmp/{$filename}");

        GenerateFaceEmbedding::dispatch($student->id, $tmpPath)->onQueue('enrollments');

        return $tmpPath;
    }
}
