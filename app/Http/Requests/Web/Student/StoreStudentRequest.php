<?php

namespace App\Http\Requests\Web\Student;

use App\Domain\School\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (empty($this->school_id) && School::where('is_active', true)->count() === 1) {
            $this->merge([
                'school_id' => School::where('is_active', true)->value('id'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'school_id'    => ['required', 'integer', Rule::exists('schools', 'id')->whereNull('deleted_at')->where('is_active', true)],
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
