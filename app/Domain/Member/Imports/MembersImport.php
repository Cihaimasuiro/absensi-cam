<?php

namespace App\Domain\Member\Imports;

use App\Domain\Member\Models\Member;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class MembersImport implements ToModel, WithHeadingRow, WithValidation, WithBatchInserts, WithChunkReading
{
    public function model(array $row)
    {
        return new Member([
            'code'  => $row['code'] ?? $row['nis'] ?? $row['nip'],
            'name'  => $row['name'] ?? $row['nama'],
            'email' => $row['email'] ?? null,
            'phone' => $row['phone'] ?? $row['telepon'] ?? null,
            'role'  => $row['role'] ?? 'student',
            'is_active' => true,
        ]);
    }

    public function rules(): array
    {
        return [
            '*.code'  => ['required', 'string', 'max:50', Rule::unique('members', 'code')],
            '*.name'  => ['required', 'string', 'max:150'],
            '*.email' => ['nullable', 'email', 'max:191', Rule::unique('members', 'email')],
            '*.phone' => ['nullable', 'string', 'max:30'],
            '*.role'  => ['nullable', 'string', 'max:50'],
        ];
    }

    public function prepareForValidation($data, $index)
    {
        // Support common column names
        $data['code'] = $data['code'] ?? $data['nis'] ?? $data['nip'] ?? null;
        $data['name'] = $data['name'] ?? $data['nama'] ?? null;
        $data['phone'] = $data['phone'] ?? $data['telepon'] ?? null;
        
        return $data;
    }

    public function batchSize(): int
    {
        return 100;
    }

    public function chunkSize(): int
    {
        return 100;
    }
}
