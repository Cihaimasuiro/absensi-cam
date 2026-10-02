<?php

namespace App\Http\Requests\API\V1\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorized via Sanctum device token
    }

    public function rules(): array
    {
        return [
            'records'                     => ['required', 'array', 'min:1', 'max:200'],
            'records.*.id'                => ['required', 'uuid'],
            'records.*.student_id'         => ['required', 'uuid'],
            'records.*.captured_at'       => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
            'records.*.direction'         => ['required', 'in:in,out'],
            'records.*.score'             => ['required', 'numeric', 'min:0', 'max:1'],
            'records.*.liveness_score'    => ['nullable', 'numeric', 'min:0', 'max:1'],
            'records.*.time_source'       => ['required', 'in:ntp,rtc,unsynced'],
        ];
    }
}
