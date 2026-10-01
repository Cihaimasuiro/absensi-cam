<?php

namespace App\Console\Commands;

use App\Domain\Member\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ported from: Facenox maintenance.py members.py:211-239
 *   is_active=False → await repo.remove_member(person_id)
 *   remove_member() deletes the Face row (biometric data)
 *
 * In Laravel: members retain SoftDeletes on face_templates.
 * A soft-delete on face_templates acts as a tombstone → edge device
 * sees op=delete on next delta sync and purges local embedding.
 *
 * PRD §12.3 — face templates deleted when consent withdrawn (UU PDP / GDPR Art.17).
 */
class TombstoneRevokedConsent extends Command
{
    protected $signature = 'app:tombstone-revoked-consent
                            {--dry-run : Show affected members without modifying data}';

    protected $description = 'Soft-delete face templates for members with no active biometric consent';

    public function handle(): int
    {
        // Members who: have no active consent AND still have a live face template
        $members = Member::whereDoesntHave('activeConsent')
            ->whereHas('faceTemplate')
            ->with('faceTemplate')
            ->get();

        if ($members->isEmpty()) {
            $this->info('No members found with revoked consent and a live face template.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("[dry-run] Would tombstone {$members->count()} face template(s):");
            $members->each(fn ($m) => $this->line("  · {$m->name} ({$m->code})"));
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($members as $member) {
            $member->faceTemplate->delete(); // SoftDelete = tombstone; edge picks up op=delete on next sync
            Log::info('Tombstoned face template: consent revoked', [
                'member_id' => $member->id,
                'member_code' => $member->code,
            ]);
            $count++;
        }

        $this->info("Tombstoned {$count} face template(s) for consent-revoked members.");
        Log::info("app:tombstone-revoked-consent: tombstoned {$count} face templates.");

        return self::SUCCESS;
    }
}
