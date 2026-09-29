<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('name', 100)->comment('Nama snapshot saat hadir (denormalized untuk audit)');
            $table->string('department', 100)->nullable();
            $table->string('device_id', 50)->index()->comment('ID edge node Orange Pi Lite 2');
            $table->timestamp('attended_at')->index()->comment('Waktu absen dari edge node');
            $table->float('confidence')->nullable()->comment('Confidence score InsightFace (0-1)');
            $table->string('status', 20)->default('hadir')->comment('hadir, izin, sakit, alfa');
            $table->timestamps();

            // Composite unique: satu orang hanya satu absen per device per jam
            $table->unique(['name', 'device_id', 'attended_at']);
            $table->index(['attended_at', 'department']);
            $table->index(['member_id', 'attended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
