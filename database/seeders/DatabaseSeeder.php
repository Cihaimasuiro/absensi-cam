<?php

namespace Database\Seeders;

use App\Domain\User\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(SchoolSeeder::class);

        if (!app()->environment('production')) {
            $adminEmail = env('ADMIN_DEFAULT_EMAIL', 'admin@smartabsen.local');
            $adminUser = User::firstOrCreate(
                ['email' => $adminEmail],
                [
                    'name'     => 'Admin Smart Absen',
                    'password' => 'password',
                ]
            );

            if (!$adminUser->hasRole('super_admin')) {
                $adminUser->assignRole('super_admin');
            }
        }
    }
}
