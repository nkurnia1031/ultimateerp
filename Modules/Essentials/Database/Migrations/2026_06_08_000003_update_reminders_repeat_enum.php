<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE essentials_reminders MODIFY `repeat` ENUM('one_time', 'every_day', 'every_week', 'every_month', 'every_quarter', 'every_year') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE essentials_reminders MODIFY `repeat` ENUM('one_time', 'every_day', 'every_week', 'every_month') NOT NULL");
    }
};
