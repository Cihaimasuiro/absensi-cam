<?php

namespace App\Http\Requests\API\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

class PairDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ip = $this->ip();
        $isLocalEnv = app()->environment('local', 'testing', 'development');

        if (!$isLocalEnv && !$this->isSecure()) {
            return false; // Force HTTPS in production
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
