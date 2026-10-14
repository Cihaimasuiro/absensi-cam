<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_pairing_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('device_pairing_requests', 'engine_version')) {
                $table->string('engine_version', 60)->nullable()->after('ip_address')->comment('Versi engine edge-engine-rs');
            }
            if (!Schema::hasColumn('device_pairing_requests', 'client_reported_ip')) {
                $table->string('client_reported_ip', 45)->nullable()->after('ip_address')->comment('IP yang dilaporkan oleh perangkat');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE device_pairing_requests MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'consumed', 'claimed', 'expired') DEFAULT 'pending'");
        } else {
            Schema::table('device_pairing_requests', function (Blueprint $table) {
                $table->string('status', 30)->default('pending')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('device_pairing_requests', function (Blueprint $table) {
            if (Schema::hasColumn('device_pairing_requests', 'client_reported_ip')) {
                $table->dropColumn('client_reported_ip');
            }
            if (Schema::hasColumn('device_pairing_requests', 'engine_version')) {
                $table->dropColumn('engine_version');
            }
        });
    }
};
