<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_pairing_requests', function (Blueprint $table) {
            $table->id();
            $table->string('pairing_code', 16)->unique()->comment('Kode visual singkat, misal 839-204');
            $table->string('poll_token_hash', 64)->unique()->comment('SHA-256 hash dari poll token rahasia milik perangkat');
            $table->string('hostname', 100);
            $table->string('mac_address', 32)->nullable()->index()->comment('MAC address fisik untuk verifikasi stiker/hardware');
            $table->string('ip_address', 45)->nullable();
            $table->string('model_version', 60)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'claimed', 'expired'])->default('pending')->index();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->text('encrypted_payload')->nullable()->comment('Kredensial terenkripsi; dihapus segera setelah status claimed');
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pairing_requests');
    }
};
