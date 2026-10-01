<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Members — PRD §11.1 server schema, FR-S02, FR-S04
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('UUID dari server; digunakan di edge sebagai member_id');
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('code', 50)->comment('NIK / nomor anggota / NIS');
            $table->string('name', 150)->index();
            $table->string('email', 191)->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->string('role', 50)->nullable()->comment('student|employee|teacher|custom');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes()->comment('Soft-delete; tombstone dikirim ke edge');

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
            $table->index(['group_id', 'is_active']);
        });

        // Biometric consent (WAJIB per UU PDP No. 27/2022 pasal data biometrik) — FR-S04
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->uuid('member_id');
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->timestamp('given_at')->comment('Kapan persetujuan diberikan');
            $table->timestamp('withdrawn_at')->nullable()->comment('Kapan persetujuan ditarik; null = masih aktif');
            $table->string('text_version', 20)->comment('Versi teks persetujuan mis. v1.0');
            $table->foreignId('recorded_by')->constrained('users')->comment('Admin yang mencatat');
            $table->string('guardian_name', 150)->nullable()->comment('Nama wali (untuk siswa < 18 tahun)');
            $table->string('guardian_relation', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'withdrawn_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
        Schema::dropIfExists('members');
    }
};
