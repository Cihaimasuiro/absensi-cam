<?php

namespace App\Http\Requests\API\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

class PollDevicePairingRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (!config('app.pairing_allow_http', false) && !$this->isSecure()) {
            return false; // Force HTTPS unless explicitly allowed
        }

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
