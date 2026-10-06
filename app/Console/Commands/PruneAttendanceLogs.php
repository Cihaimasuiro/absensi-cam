<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Models\AttendanceLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ported from: Facenox lifespan.py:120-130
 *   if settings.data_retention_days > 0:
 *       result = await repo.cleanup_old_data(settings.data_retention_days)
 *
 * In Laravel: config comes from env ATTENDANCE_RETENTION_DAYS.
 * Default 0 = disabled (keep forever), matching Facenox default.
 * PRD §12.3 — attendance history: org policy, no forced default limit.
 */
class PruneAttendanceLogs extends Command
{
    protected $signature = 'app:prune-attendance-logs
                            {--days= : Override ATTENDANCE_RETENTION_DAYS env}
                            {--dry-run : Show count without deleting}';

    protected $description = 'Delete old attendance_logs (controlled by ATTENDANCE_RETENTION_DAYS env, 0 = disabled)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? env('ATTENDANCE_RETENTION_DAYS', 0));

        if ($days <= 0) {
            $this->info('Retention disabled (days=0). Skipping.');
            return self::SUCCESS;
        }

        $cutoff = now('UTC')->subDays($days);
        $query  = AttendanceLog::where('captured_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $count = $query->count();
            $this->line("[dry-run] Would delete {$count} attendance log rows older than {$days} days.");
            return self::SUCCESS;
        }

        $deleted = $query->delete();

        Log::info("app:prune-attendance-logs: deleted {$deleted} rows older than {$days} days.");
        $this->info("Pruned {$deleted} attendance log rows.");

        return self::SUCCESS;
    }
}
