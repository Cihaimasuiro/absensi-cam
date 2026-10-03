<?php

namespace App\Console\Commands;

use App\Domain\Device\Models\DeviceLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pruned from: Facenox lifespan.py:127 cleanup_old_data() pattern
 * PRD §12.3 — device logs retained 7 days on server.
 */
class PruneDeviceLogs extends Command
{
    protected $signature = 'app:prune-device-logs
                            {--dry-run : Show count without deleting}
                            {--days=7 : Override retention window in days}';

    protected $description = 'Delete device_logs rows older than 7 days (PRD §12.3)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $query = DeviceLog::where('received_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $count = $query->count();
            $this->line("[dry-run] Would delete {$count} device log rows older than {$days} days.");
            return self::SUCCESS;
        }

        $deleted = $query->delete();

        Log::info("app:prune-device-logs: deleted {$deleted} rows older than {$days} days.");
        $this->info("Pruned {$deleted} device log rows.");

        return self::SUCCESS;
    }
}
