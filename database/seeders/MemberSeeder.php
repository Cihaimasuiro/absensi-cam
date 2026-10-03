<?php

namespace Database\Seeders;

use App\Domain\Member\Models\Member;
use App\Domain\Organization\Models\Group;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Seeder;

class MemberSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(
            ['code' => 'SMART-CORP'],
            ['name' => 'Smart Absen Corp', 'is_active' => true]
        );

        $deptIT  = Group::firstOrCreate(['organization_id' => $org->id, 'code' => 'IT'],  ['name' => 'IT',        'type' => 'department']);
        $deptHR  = Group::firstOrCreate(['organization_id' => $org->id, 'code' => 'HR'],  ['name' => 'HR',        'type' => 'department']);
        $deptFin = Group::firstOrCreate(['organization_id' => $org->id, 'code' => 'FIN'], ['name' => 'Finance',   'type' => 'department']);
        $deptMkt = Group::firstOrCreate(['organization_id' => $org->id, 'code' => 'MKT'], ['name' => 'Marketing', 'type' => 'department']);

        $members = [
            ['code' => 'EMP-001', 'name' => 'Budi Santoso',  'group_id' => $deptIT->id,  'role' => 'employee'],
            ['code' => 'EMP-002', 'name' => 'Sari Dewi',     'group_id' => $deptHR->id,  'role' => 'employee'],
            ['code' => 'EMP-003', 'name' => 'Ahmad Fauzi',   'group_id' => $deptFin->id, 'role' => 'employee'],
            ['code' => 'EMP-004', 'name' => 'Rina Marlina',  'group_id' => $deptMkt->id, 'role' => 'employee'],
            ['code' => 'EMP-005', 'name' => 'Dodi Prasetya', 'group_id' => $deptIT->id,  'role' => 'employee'],
        ];

        foreach ($members as $data) {
            Member::firstOrCreate(
                ['organization_id' => $org->id, 'code' => $data['code']],
                array_merge($data, ['organization_id' => $org->id, 'is_active' => true])
            );
        }
    }
}
