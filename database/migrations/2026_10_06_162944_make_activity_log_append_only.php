<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For SQLite
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared("
                CREATE TRIGGER prevent_activity_log_update BEFORE UPDATE ON activity_log 
                BEGIN SELECT RAISE(ABORT, 'activity_log is append-only'); END;
            ");
            DB::unprepared("
                CREATE TRIGGER prevent_activity_log_delete BEFORE DELETE ON activity_log 
                BEGIN SELECT RAISE(ABORT, 'activity_log is append-only'); END;
            ");
        } 
        // For MySQL
        else if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared("
                CREATE TRIGGER prevent_activity_log_update BEFORE UPDATE ON activity_log
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only';
            ");
            DB::unprepared("
                CREATE TRIGGER prevent_activity_log_delete BEFORE DELETE ON activity_log
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only';
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS prevent_activity_log_update");
        DB::unprepared("DROP TRIGGER IF EXISTS prevent_activity_log_delete");
    }
};
