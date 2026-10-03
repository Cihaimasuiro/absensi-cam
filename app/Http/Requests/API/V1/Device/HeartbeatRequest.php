<?php

namespace App\Http\Requests\API\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorized via Sanctum device token middleware
    }

    public function rules(): array
    {
        return [
            'fw_version'    => ['required', 'string', 'max:30'],
            'model_version' => ['required', 'string', 'max:60'],
            'cpu_temp'      => ['required', 'numeric', 'min:0', 'max:120'],
            'ram_free_mb'   => ['required', 'integer', 'min:0'],
            'disk_free_mb'  => ['required', 'integer', 'min:0'],
            'fps'           => ['required', 'numeric', 'min:0'],
            'outbox_len'    => ['required', 'integer', 'min:0'],
            'serial_ok'     => ['required', 'boolean'],
            'ip_address'    => ['nullable', 'ip'],
        ];
    }
}
