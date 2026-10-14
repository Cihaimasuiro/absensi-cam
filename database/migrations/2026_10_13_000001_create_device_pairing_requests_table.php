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
            $table->string('pairing_code', 16)->index()->comment('Kode visual singkat 6 karakter (huruf kapital + angka tanpa ambigu)');
            $table->string('poll_token_hash', 64)->unique()->comment('SHA-256 hash dari poll token rahasia milik perangkat');
            $table->string('hostname', 100);
            $table->string('mac_address', 32)->nullable()->index()->comment('MAC address fisik untuk verifikasi hardware');
            $table->string('ip_address', 45)->nullable();
            $table->string('engine_version', 60)->nullable()->comment('Versi engine edge-engine-rs');
            $table->string('model_version', 60)->nullable()->comment('Versi model face recognition');
            $table->enum('status', ['pending', 'approved', 'rejected', 'consumed', 'expired'])->default('pending')->index();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pairing_requests');
    }
};
