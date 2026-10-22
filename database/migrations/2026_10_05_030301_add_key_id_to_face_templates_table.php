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
            $table->string('key_id', 64)->nullable()->after('embedding_enc');
        });
    }

    /**
     * Batalkan (reverse) migration.
     */
    public function down(): void
    {
        Schema::table('face_templates', function (Blueprint $table) {
            $table->dropColumn('key_id');
        });
    }
};
