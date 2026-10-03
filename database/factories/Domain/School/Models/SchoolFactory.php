<?php

namespace Database\Factories\Domain\School\Models;

use App\Domain\School\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SchoolFactory extends Factory
{
    protected $model = School::class;

    public function definition(): array
    {
        return [
            'name'      => $this->faker->company(),
            'code'      => strtoupper(Str::random(6)),
            'is_active' => true,
        ];
    }
}


