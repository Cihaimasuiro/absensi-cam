<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Models\Student;
use Illuminate\Support\Facades\DB;

final class StudentService
{
    public function create(array $data): Student
    {
        return DB::transaction(function () use ($data) {
            $student = Student::create(array_merge($data, ['is_active' => true]));

            activity('student')
                ->performedOn($student)
                ->log('Student created');

            return $student;
        });
    }

    public function update(Student $student, array $data): Student
    {
        return DB::transaction(function () use ($student, $data) {
            $student->update($data);

            activity('student')
                ->performedOn($student)
                ->log('Student updated');

            return $student->fresh();
        });
    }

    public function deactivate(Student $student): void
    {
        DB::transaction(function () use ($student) {
            $student->update(['is_active' => false]);

            activity('student')
                ->performedOn($student)
                ->log('Student deactivated');
        });
    }
}
