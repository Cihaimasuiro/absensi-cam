<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Domain\User\Models\User::firstOrCreate(
    ['email' => 'admin@admin.com'],
    ['name' => 'System Admin', 'password' => bcrypt('password')]
);
echo "User ID: " . $user->id . "\n";
