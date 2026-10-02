<?php

namespace Database\Factories\Domain\School\Models;

use App\Domain\School\Models\Building;
use App\Domain\School\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BuildingFactory extends Factory
{
    protected $model = Building::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name'      => $this->faker->streetName() . ' Building',
            'code'      => strtoupper(Str::random(4)),
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ];
    }
}


