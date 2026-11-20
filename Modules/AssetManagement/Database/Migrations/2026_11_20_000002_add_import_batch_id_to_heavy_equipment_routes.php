<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('heavy_equipment_routes') || Schema::hasColumn('heavy_equipment_routes', 'source_import_batch_id')) {
            return;
        }

        Schema::table('heavy_equipment_routes', function (Blueprint $table) {
            $table->unsignedBigInteger('source_import_batch_id')->nullable()->index('he_route_import_batch_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('heavy_equipment_routes') || ! Schema::hasColumn('heavy_equipment_routes', 'source_import_batch_id')) {
            return;
        }

        Schema::table('heavy_equipment_routes', function (Blueprint $table) {
            $table->dropIndex('he_route_import_batch_idx');
            $table->dropColumn('source_import_batch_id');
        });
    }
};
