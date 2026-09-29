<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class SyncAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Edge Node token authentication
    }

    public function rules(): array
    {
        return [
            'device_id'             => ['required', 'string', 'max:50'],
            'records'               => ['required', 'array', 'min:1', 'max:500'],
            'records.*.name'        => ['required', 'string', 'max:100'],
            'records.*.department'  => ['nullable', 'string', 'max:100'],
            'records.*.timestamp'   => ['required', 'date_format:Y-m-d H:i:s'],
            'records.*.confidence'  => ['nullable', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
