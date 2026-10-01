<?php

namespace App\Http\Requests\Web\Member;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberRequest extends FormRequest
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
            'branch'       => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:100'],
            'is_active'    => ['sometimes', 'boolean'],
        ];
    }
}
