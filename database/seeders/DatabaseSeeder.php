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
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'admin@smartabsen.local'],
            [
                'name'     => 'Admin Smart Absen',
                'password' => 'password',
            ]
        );

        $this->call(RoleSeeder::class);
        $this->call(SchoolSeeder::class);
    }
}
