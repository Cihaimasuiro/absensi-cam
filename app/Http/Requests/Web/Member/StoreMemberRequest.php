<?php

namespace App\Http\Requests\Web\Member;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:50', 'unique:members,employee_number'],
            'name'            => ['required', 'string', 'max:100'],
            'department'      => ['nullable', 'string', 'max:100'],
            'branch'          => ['nullable', 'string', 'max:100'],
            'organization'    => ['nullable', 'string', 'max:100'],
        ];
    }
}
