<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('face_templates', function (Blueprint $table) {
            $table->string('key_id', 64)->nullable()->after('embedding_enc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('face_templates', function (Blueprint $table) {
            $table->dropColumn('key_id');
        });
    }
};
