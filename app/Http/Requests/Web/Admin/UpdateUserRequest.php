<?php

namespace App\Http\Requests\Web\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('user.manage') ?? false;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id ?? $this->route('user');

        $allowedRoles = $this->user()?->hasRole('super_admin')
            ? Role::pluck('name')->toArray()
            : Role::where('name', '!=', 'super_admin')->pluck('name')->toArray();

        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:8'],
            'role'     => ['required', 'string', Rule::in($allowedRoles)],
        ];
    }
}
