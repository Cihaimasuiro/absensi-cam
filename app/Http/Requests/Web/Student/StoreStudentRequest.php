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
            'school_id'    => ['nullable', 'exists:schools,id'],
            'classroom_id' => ['nullable', 'exists:classrooms,id'],
            'code'         => ['required', 'string', 'max:50', 'unique:students,code'],
            'name'         => ['required', 'string', 'max:150'],
            'email'        => ['nullable', 'email', 'max:191', 'unique:students,email'],
            'phone'        => ['nullable', 'string', 'max:30'],
            'role'         => ['nullable', 'string', 'max:50'],
            'has_consent'  => ['nullable', 'boolean'],
        ];
    }
}
