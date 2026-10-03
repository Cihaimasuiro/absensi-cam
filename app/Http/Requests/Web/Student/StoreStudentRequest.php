<?php

namespace App\Http\Requests\Web\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:50', 'unique:students,employee_number'],
            'name'            => ['required', 'string', 'max:100'],
            'department'      => ['nullable', 'string', 'max:100'],
            'building'          => ['nullable', 'string', 'max:100'],
            'school'    => ['nullable', 'string', 'max:100'],
        ];
    }
}
