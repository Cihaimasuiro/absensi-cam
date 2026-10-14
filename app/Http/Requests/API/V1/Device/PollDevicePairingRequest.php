<?php

namespace App\Http\Requests\API\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

class PollDevicePairingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('poll_token') && $this->bearerToken()) {
            $this->merge(['poll_token' => $this->bearerToken()]);
        }
    }

    public function rules(): array
    {
        return [
            'poll_token' => ['required', 'string', 'min:32', 'max:128'],
        ];
    }
}
