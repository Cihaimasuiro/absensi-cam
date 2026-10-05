<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Domain\Device\Models\Device;

#[Signature('app:doctor')]
#[Description('Check system health, queues, and device configurations')]
class AppDoctor extends Command
{
    public function handle()
    {
        $this->info('🩺 Smart Absensi System Doctor');
        $this->line('----------------------------------------------------');

        // 1. Check Database
        try {
            DB::connection()->getPdo();
            $this->info('✅ Database is reachable.');
        } catch (\Exception $e) {
            $this->error('❌ Database connection failed: ' . $e->getMessage());
        }

        // 2. Check Queue Worker
        $this->line("\nChecking queue worker (database connection)...");
        try {
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();
            
            $oldestJob = DB::table('jobs')->orderBy('created_at', 'asc')->first();
            $oldestMsg = 'None';
            if ($oldestJob) {
                $oldestMsg = \Carbon\Carbon::createFromTimestamp($oldestJob->created_at)->diffForHumans();
            }

            $this->info("ℹ️  Queue status: {$pendingJobs} pending jobs, {$failedJobs} failed jobs.");
            $this->info("ℹ️  -Oldest pending job: {$oldestMsg}");

            if ($pendingJobs > 0) {
                $this->warn('⚠️  There are pending jobs. If they are not decreasing (oldest job is stale), your queue worker (`php artisan queue:listen` in `npm run dev`) might be dead.');
            } else {
                $this->info('✅ Queue is empty (healthy).');
            }
        } catch (\Exception $e) {
            $this->error('❌ Failed to check queue: ' . $e->getMessage());
        }

        // 3. Check Devices
        $this->line("\nChecking Devices...");
        $devices = Device::all();
        if ($devices->isEmpty()) {
            $this->warn('⚠️  No devices found. Go to Web Dashboard to create one.');
        } else {
            $this->info("✅ Found {$devices->count()} devices.");
            foreach ($devices as $d) {
                $status = $d->isOnline() ? '<fg=green>Online</>' : '<fg=yellow>Offline</>';
                $this->line("   - {$d->name} ({$status})");
            }
        }

        $this->line('----------------------------------------------------');
        $this->info('🩺 Diagnosis complete.');
    }
}
