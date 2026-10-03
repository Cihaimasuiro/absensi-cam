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

        // Store permanent original photo (required for future model re-extraction)
        $permFilename = "photo_{$student->id}_" . Str::uuid() . ".{$photo->extension()}";
        $photo->storeAs('enrollments/photos', $permFilename, 'local');
        $permPath = \Illuminate\Support\Facades\Storage::disk('local')->path("enrollments/photos/{$permFilename}");

        // Also create a tmp copy for the binary to process (so it doesn't accidentally lock or corrupt the permanent one)
        $tmpFilename = "enroll_{$student->id}_" . Str::uuid() . ".{$photo->extension()}";
        \Illuminate\Support\Facades\Storage::disk('local')->copy("enrollments/photos/{$permFilename}", "tmp/{$tmpFilename}");
        $tmpPath = \Illuminate\Support\Facades\Storage::disk('local')->path("tmp/{$tmpFilename}");

        // Dispatch job: pass both paths, and tell it to delete the tmpPath after processing
        GenerateFaceEmbedding::dispatch($student->id, $tmpPath, "enrollments/photos/{$permFilename}", true)
            ->onQueue('enrollments');

        return $tmpPath;
    }
}
