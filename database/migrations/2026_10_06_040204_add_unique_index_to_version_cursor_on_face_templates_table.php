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
            $table->unique('version_cursor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('face_templates', function (Blueprint $table) {
            $table->dropUnique(['version_cursor']);
        });
    }
};
