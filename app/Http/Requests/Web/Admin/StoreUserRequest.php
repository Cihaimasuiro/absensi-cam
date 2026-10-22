<?php

namespace App\Http\Requests\Web\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('user.manage') ?? false;
    }

    public function rules(): array
    {
        $allowedRoles = $this->user()?->hasRole('super_admin')
            ? Role::pluck('name')->toArray()
            : Role::where('name', '!=', 'super_admin')->pluck('name')->toArray();

        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', 'string', Rule::in($allowedRoles)],
        ];
    }
}
