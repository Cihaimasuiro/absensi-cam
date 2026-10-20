<?php

namespace App\Http\Requests\Web\Student;

use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Student|string|null $student */
        $student = $this->route('student');
        $studentId = $student instanceof Student ? $student->id : $student;

        return [
            'school_id'    => ['nullable', 'exists:schools,id'],
            'classroom_id' => ['nullable', 'exists:classrooms,id'],
            'code'         => ['required', 'string', 'max:50', Rule::unique('students', 'code')->ignore($studentId)],
            'name'         => ['required', 'string', 'max:150'],
            'email'        => ['nullable', 'email', 'max:191', Rule::unique('students', 'email')->ignore($studentId)],
            'phone'        => ['nullable', 'string', 'max:30'],
            'role'         => ['nullable', 'string', 'max:50'],
            'is_active'    => ['boolean'],
            'has_consent'  => ['nullable', 'boolean'],
        ];
    }
}
