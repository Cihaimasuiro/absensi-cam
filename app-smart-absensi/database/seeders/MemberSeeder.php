<?php

namespace Database\Seeders;

use App\Domain\Member\Models\Member;
use Illuminate\Database\Seeder;

class MemberSeeder extends Seeder
{
    public function run(): void
    {
        $members = [
            ['employee_number' => 'EMP-001', 'name' => 'Budi Santoso',    'department' => 'IT',        'branch' => 'Pusat', 'organization' => 'Smart Absen Corp'],
            ['employee_number' => 'EMP-002', 'name' => 'Sari Dewi',       'department' => 'HR',        'branch' => 'Pusat', 'organization' => 'Smart Absen Corp'],
            ['employee_number' => 'EMP-003', 'name' => 'Ahmad Fauzi',     'department' => 'Finance',   'branch' => 'Pusat', 'organization' => 'Smart Absen Corp'],
            ['employee_number' => 'EMP-004', 'name' => 'Rina Marlina',    'department' => 'Marketing', 'branch' => 'Pusat', 'organization' => 'Smart Absen Corp'],
            ['employee_number' => 'EMP-005', 'name' => 'Dodi Prasetya',   'department' => 'IT',        'branch' => 'Cabang Bandung', 'organization' => 'Smart Absen Corp'],
        ];

        foreach ($members as $data) {
            Member::firstOrCreate(
                ['employee_number' => $data['employee_number']],
                array_merge($data, ['is_active' => true])
            );
        }
    }
}
