<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['solar_mess', 'solar_pool', 'solar_stock'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('id');
            });
            Schema::table($tableName, function (Blueprint $table) {
                $table->id()->first();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['solar_mess', 'solar_pool', 'solar_stock'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropTimestamps();
            });
        }
    }
};
