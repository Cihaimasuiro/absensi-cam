<?php

namespace App\Http\Requests\API\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

class PairDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (!env('PAIRING_ALLOW_HTTP', false) && !$this->isSecure()) {
            return false; // Force HTTPS unless explicitly disabled for development
        }

        return true; 
    }

    public function rules(): array
    {
        return [
            'code'          => ['required', 'string', 'size:9'],
            'device_name'   => ['required', 'string', 'max:100'],
            'fw_version'    => ['required', 'string', 'max:30'],
            'model_version' => ['required', 'string', 'max:60'],
        ];
    }
}
