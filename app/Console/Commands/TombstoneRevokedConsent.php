<?php

namespace App\Console\Commands;

use App\Domain\Student\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ported from: Facenox maintenance.py students.py:211-239
 *   is_active=False → await repo.remove_student(person_id)
 *   remove_student() deletes the Face row (biometric data)
 *
 * In Laravel: students retain SoftDeletes on face_templates.
 * A soft-delete on face_templates acts as a tombstone → edge device
 * sees op=delete on next delta sync and purges local embedding.
 *
 * PRD §12.3 — face templates deleted when consent withdrawn (UU PDP / GDPR Art.17).
 */
class TombstoneRevokedConsent extends Command
{
    protected $signature = 'app:tombstone-revoked-consent
                            {--dry-run : Show affected students without modifying data}';

    protected $description = 'Soft-delete face templates for students with no active biometric consent';

    public function handle(): int
    {
        // Students who: have no active consent AND still have a live face template
        $students = Student::whereDoesntHave('activeConsent')
            ->whereHas('faceTemplate')
            ->with('faceTemplate')
            ->get();

        if ($students->isEmpty()) {
            $this->info('No students found with revoked consent and a live face template.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("[dry-run] Would tombstone {$students->count()} face template(s):");
            $students->each(fn ($m) => $this->line("  · {$m->name} ({$m->code})"));
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($students as $student) {
            $student->faceTemplate->delete(); // SoftDelete = tombstone; edge picks up op=delete on next sync
            Log::info('Tombstoned face template: consent revoked', [
                'student_id' => $student->id,
                'student_code' => $student->code,
            ]);
            $count++;
        }

        $this->info("Tombstoned {$count} face template(s) for consent-revoked students.");
        Log::info("app:tombstone-revoked-consent: tombstoned {$count} face templates.");

        return self::SUCCESS;
    }
}
