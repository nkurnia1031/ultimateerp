<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('heavy_equipment_trips') || Schema::hasColumn('heavy_equipment_trips', 'import_batch_id')) {
            return;
        }

        Schema::table('heavy_equipment_trips', function (Blueprint $table) {
            $table->unsignedBigInteger('import_batch_id')->nullable()->index('he_trip_import_batch_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('heavy_equipment_trips') || ! Schema::hasColumn('heavy_equipment_trips', 'import_batch_id')) {
            return;
        }

        Schema::table('heavy_equipment_trips', function (Blueprint $table) {
            $table->dropIndex('he_trip_import_batch_idx');
            $table->dropColumn('import_batch_id');
        });
    }
};
