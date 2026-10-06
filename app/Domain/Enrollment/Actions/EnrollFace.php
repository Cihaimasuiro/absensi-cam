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

        // Create a tmp copy for the binary to process
        $tmpFilename = "enroll_{$student->id}_" . Str::uuid() . ".{$photo->extension()}";
        $photo->storeAs('tmp', $tmpFilename, 'local');
        $tmpPath = \Illuminate\Support\Facades\Storage::disk('local')->path("tmp/{$tmpFilename}");

        // Dispatch job: pass only tmpPath, and tell it to delete the tmpPath after processing
        GenerateFaceEmbedding::dispatch($student->id, $tmpPath, null, true)
            ->onQueue('enrollments');

        return $tmpPath;
    }
}
