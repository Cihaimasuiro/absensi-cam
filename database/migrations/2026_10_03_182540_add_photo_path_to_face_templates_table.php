<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     */
    public function up(): void
    {
        Schema::table('face_templates', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('student_id')->comment('Foto asli untuk ekstraksi ulang model');
        });
    }

    /**
     * Batalkan (reverse) migration.
     */
    public function down(): void
    {
        Schema::table('face_templates', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
