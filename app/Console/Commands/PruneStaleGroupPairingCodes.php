<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pruned from: Facenox maintenance.py /cleanup concept
 * Removes expired, unused pairing codes — they are single-use and time-limited.
 */
class PruneStaleGroupPairingCodes extends Command
{
    protected $signature = 'app:prune-stale-pairing-codes';

    protected $description = 'Delete expired unused pairing_codes rows';

    public function handle(): int
    {
        $deleted = DB::table('pairing_codes')
            ->where('expires_at', '<', now())
            ->whereNull('used_at')
            ->delete();

        Log::info("app:prune-stale-pairing-codes: deleted {$deleted} expired unused pairing codes.");
        $this->info("Pruned {$deleted} expired pairing codes.");

        return self::SUCCESS;
    }
}
