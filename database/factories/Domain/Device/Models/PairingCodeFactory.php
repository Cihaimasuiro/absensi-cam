<?php

namespace Database\Factories\Domain\Device\Models;

use App\Domain\Device\Models\PairingCode;
use App\Domain\School\Models\Building;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;


class PairingCodeFactory extends Factory
{
    protected $model = PairingCode::class;

    public function definition(): array
    {
        $plain = strtoupper($this->faker->lexify('????') . '-' . $this->faker->lexify('????'));

        return [
            'building_id' => Building::factory(),
            'code_hash'   => hash('sha256', $plain),
            'expires_at'  => now()->addMinutes(15),
            'used_at'     => null,
            'created_by'  => User::factory(),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function used(): static
    {
        return $this->state(['used_at' => now()]);
    }
}



