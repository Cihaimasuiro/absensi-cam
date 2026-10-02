<?php

namespace Database\Factories\Domain\Student\Models;

use App\Domain\Student\Models\Student;
use App\Domain\School\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'id'        => $this->faker->uuid(),
            'school_id' => School::factory(),
            'code'      => $this->faker->unique()->numerify('NIS-#####'),
            'name'      => $this->faker->name(),
            'is_active' => true,
        ];
    }
}


