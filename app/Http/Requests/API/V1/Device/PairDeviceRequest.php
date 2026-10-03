<?php

namespace App\Http\Requests\API\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

class PairDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint — auth via one-time pairing code, not Bearer token
    }

    public function rules(): array
    {
        return [
            'code'          => ['required', 'string', 'size:9'],  // format: K7M2-9QXA (8 chars + dash)
            'device_name'   => ['required', 'string', 'max:100'],
            'fw_version'    => ['required', 'string', 'max:30'],
            'model_version' => ['required', 'string', 'max:60'],
        ];
    }
}
