<?php

namespace Database\Factories\Domain\Device\Models;

use App\Domain\Device\Models\Device;
use App\Domain\School\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'building_id'       => Building::factory(),
            'name'              => 'Kamera ' . $this->faker->word(),
            'device_code'       => 'dev-' . str_pad($this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'fw_version'        => '1.0.0',
            'model_version'     => 'sface-2021dec',
            'status'            => 'offline',
            'last_heartbeat_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(['status' => 'online', 'last_heartbeat_at' => now()]);
    }
}


