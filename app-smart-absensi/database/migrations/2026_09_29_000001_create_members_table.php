<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 50)->unique()->comment('NIK / nomor karyawan');
            $table->string('name', 100)->index();
            $table->string('department', 100)->nullable();
            $table->string('branch', 100)->nullable()->comment('Cabang / lokasi');
            $table->string('organization', 100)->nullable()->comment('Organisasi / divisi');
            $table->boolean('is_active')->default(true)->index();
            $table->json('face_embedding')->nullable()->comment('InsightFace 512-dim vector dari edge node');
            $table->timestamp('embedding_updated_at')->nullable()->comment('Kapan embedding terakhir disync dari edge node');
            $table->timestamps();

            $table->index(['department', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
