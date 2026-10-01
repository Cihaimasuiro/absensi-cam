<?php

namespace App\Domain\Enrollment\Actions;

use App\Domain\Member\Models\Member;
use App\Jobs\GenerateFaceEmbedding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class EnrollFace
{
    public function execute(Member $member, UploadedFile $photo): string
    {
        if (! $member->hasActiveConsent()) {
            throw new \InvalidArgumentException('Biometric consent is required before face enrollment.');
        }

        $filename = "enroll_{$member->id}_" . Str::uuid() . ".{$photo->extension()}";
        $photo->storeAs('tmp', $filename, 'local');
        $tmpPath = storage_path("app/tmp/{$filename}");

        GenerateFaceEmbedding::dispatch($member->id, $tmpPath)->onQueue('enrollments');

        return $tmpPath;
    }
}
