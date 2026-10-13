<?php

namespace App\Http\Requests\API\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

class StoreDevicePairingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hostname'      => ['required', 'string', 'max:100'],
            'mac_address'   => ['nullable', 'string', 'max:32'],
            'model_version' => ['nullable', 'string', 'max:60'],
            'ip_address'    => ['nullable', 'ip'],
        ];
    }
}
