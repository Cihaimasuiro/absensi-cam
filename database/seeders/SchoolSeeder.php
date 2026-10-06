<?php

namespace Database\Seeders;

use App\Domain\School\Models\Building;
use App\Domain\School\Models\Classroom;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::firstOrCreate(
            ['code' => 'SMART-SCH'],
            ['name' => 'Smart Absen School', 'description' => 'A technology driven school', 'is_active' => true]
        );

        $building = Building::firstOrCreate(
            ['school_id' => $school->id, 'code' => 'MAIN-BLD'],
            ['name' => 'Gedung Utama', 'address' => 'Jl. Teknologi No 1', 'timezone' => 'Asia/Jakarta', 'is_active' => true]
        );

        $classX = Classroom::firstOrCreate(
            ['school_id' => $school->id, 'code' => 'X-IPA-1'],
            [
                'name' => 'Kelas X IPA 1',
                'building_id' => $building->id,
                'type' => 'class',
                'is_active' => true,
                'start_time' => '07:00',
                'late_tolerance_minutes' => 15,
                'track_checkout' => true,
                'biometric_consent_certified' => true,
            ]
        );

        $classXI = Classroom::firstOrCreate(
            ['school_id' => $school->id, 'code' => 'XI-IPA-1'],
            [
                'name' => 'Kelas XI IPA 1',
                'building_id' => $building->id,
                'type' => 'class',
                'is_active' => true,
                'start_time' => '07:00',
                'late_tolerance_minutes' => 15,
                'track_checkout' => true,
                'biometric_consent_certified' => true,
            ]
        );

        $staffGroup = Classroom::firstOrCreate(
            ['school_id' => $school->id, 'code' => 'GURU-STAF'],
            [
                'name' => 'Guru & Staf',
                'building_id' => $building->id,
                'type' => 'staff',
                'is_active' => true,
                'start_time' => '06:30',
                'late_tolerance_minutes' => 30,
                'track_checkout' => true,
                'biometric_consent_certified' => true,
            ]
        );

        $students = [
            ['code' => 'STU-001', 'name' => 'Budi Santoso',  'classroom_id' => $classX->id,  'role' => 'student'],
            ['code' => 'STU-002', 'name' => 'Sari Dewi',     'classroom_id' => $classX->id,  'role' => 'student'],
            ['code' => 'STU-003', 'name' => 'Ahmad Fauzi',   'classroom_id' => $classXI->id, 'role' => 'student'],
            ['code' => 'STU-004', 'name' => 'Rina Marlina',  'classroom_id' => $classXI->id, 'role' => 'student'],
            ['code' => 'STU-005', 'name' => 'Dodi Prasetya', 'classroom_id' => $classX->id,  'role' => 'student'],
            ['code' => 'EMP-001', 'name' => 'Pak Guru Budi', 'classroom_id' => $staffGroup->id, 'role' => 'teacher'],
        ];

        foreach ($students as $data) {
            Student::firstOrCreate(
                ['school_id' => $school->id, 'code' => $data['code']],
                array_merge($data, ['school_id' => $school->id, 'is_active' => true])
            );
        }
    }
}
