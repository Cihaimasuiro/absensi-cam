<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Domain\Device\Models\Device;
use Illuminate\Support\Str;
use App\Domain\School\Models\Building;
use Illuminate\Support\Facades\File;

#[Signature('device:pair-dev {--url=http://127.0.0.1:8000 : The backend URL to set in .env}')]
#[Description('Create a device and directly write its token to clients/edge-engine/.env for local development')]
class DevicePairDev extends Command
{
    public function handle()
    {
        if (app()->environment('production')) {
            $this->error('This command is only for local development.');
            return;
        }

        $building = Building::first();
        if (!$building) {
            $this->error('No building found. Run migrations and seeders first.');
            return;
        }

        $device = Device::create([
            'name' => 'Dev Edge Engine',
            'building_id' => $building->id,
            'device_code' => strtoupper(Str::random(8)),
            'status' => 'offline',
            'api_version' => '1.0',
            'model_version' => config('app.model_version'),
        ]);

        $token = $device->createToken('edge-engine', ['device'])->plainTextToken;
        $device->update(['token_hash' => hash('sha256', $token)]);

        $url = $this->option('url');
        
        // Pastikan embed key sesuai dengan config server
        $embedKey = config('app.enrollment_embed_key');
        if (empty($embedKey)) {
            $this->error('ENROLLMENT_EMBED_KEY is not configured in config/app.php');
            return self::FAILURE;
        }

        $envContent = sprintf(
            "SMART_ABSENSI_URL=%s\nSMART_ABSENSI_TOKEN=%s\nSMART_ABSENSI_DEVICE_ID=%s\nENROLLMENT_EMBED_KEY=%s\n",
            $url,
            $token,
            $device->id,
            $embedKey
        );

        $envPath = base_path('clients/edge-engine-rs/.env');
        File::put($envPath, $envContent);

        $this->info('✅ Device paired for development!');
        $this->line("Created device: {$device->name} (ID: {$device->id})");
        $this->line("Wrote token to: {$envPath}");
    }
}
