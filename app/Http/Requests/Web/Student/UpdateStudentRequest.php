<?php

namespace App\Http\Requests\Web\Student;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['sometimes', 'string', 'max:100'],
            'department'   => ['nullable', 'string', 'max:100'],
            'building'       => ['nullable', 'string', 'max:100'],
            'school' => ['nullable', 'string', 'max:100'],
            'is_active'    => ['sometimes', 'boolean'],
        ];
    }
}
