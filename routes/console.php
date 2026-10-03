<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Schedule — Smart Absensi
|--------------------------------------------------------------------------
| Ported from Facenox lifespan.py (startup retention purge) and
| maintenance.py (on-demand cleanup endpoints) into Laravel's task scheduler.
| PRD §12.3 governs all retention windows.
|--------------------------------------------------------------------------
*/

// PRD §12.3 — device logs: 7 days
Schedule::command('app:prune-device-logs')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Expired unused pairing codes — no PRD requirement, common housekeeping
Schedule::command('app:prune-stale-pairing-codes')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// PRD §12.3 — attendance history: org-configurable (ATTENDANCE_RETENTION_DAYS=0 → disabled)
Schedule::command('app:prune-attendance-logs')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

// PRD §12.3 + UU PDP — face templates deleted when consent withdrawn
// Runs hourly so a revocation is acted on quickly (tombstone propagates on next edge sync)
Schedule::command('app:tombstone-revoked-consent')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
