<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Jalankan seeder database.
     */
    public function run(): void
    {
        // Reset cache role dan permission
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'student.view',
            'student.photo.view',
            'student.create',
            'student.update',
            'student.delete',
            'student.enroll',
            'enrollment.bypass-duplicate',
            'device.view',
            'device.manage',
            'school.manage',
            'report.view',
            'report.export',
            'user.manage',
            'audit.view',
            'quarantine.view',
            'consent.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

        $superAdmin->syncPermissions(Permission::all());

        $admin->syncPermissions([
            'student.view',
            'student.photo.view',
            'student.create',
            'student.update',
            'student.delete',
            'student.enroll',
            'device.view',
            'device.manage',
            'school.manage',
            'report.view',
            'report.export',
            'audit.view',
            'quarantine.view',
            'consent.manage',
        ]);

        $user->syncPermissions([
            'student.view',
            'report.view',
        ]);
    }
}
