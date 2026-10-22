<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Schedule — Smart Absensi
|--------------------------------------------------------------------------
| Ported from (startup retention purge) and
| maintenance.py (on-demand cleanup endpoints) into Laravel's task scheduler.
| PRD §12.3 governs all retention windows.
|--------------------------------------------------------------------------
*/

// PRD §12.3 — log perangkat: 7 hari
Schedule::command('app:prune-device-logs')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Kode pairing kedaluwarsa yang tak terpakai — tidak diwajibkan PRD, sekadar housekeeping
Schedule::command('app:prune-stale-pairing-codes')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// PRD §12.3 — riwayat absensi: dapat diatur per organisasi (ATTENDANCE_RETENTION_DAYS=0 → nonaktif)
Schedule::command('app:prune-attendance-logs')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

// PRD §12.3 + UU PDP — template wajah dihapus saat persetujuan ditarik
// Runs hourly so a revocation is acted on quickly (tombstone propagates on next edge sync)
Schedule::command('app:tombstone-revoked-consent')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
