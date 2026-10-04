<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['solar_stock', 'solar_pool', 'solar_mess'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'asset_id')) {
                    $table->unsignedInteger('asset_id')->nullable()->index();
                }
                if (! Schema::hasColumn($tableName, 'driver_id')) {
                    $table->unsignedInteger('driver_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('solar_stock')) {
            Schema::table('solar_stock', function (Blueprint $table) {
                if (! Schema::hasColumn('solar_stock', 'unit')) {
                    $table->string('unit', 255)->nullable();
                }
                if (! Schema::hasColumn('solar_stock', 'operator')) {
                    $table->string('operator', 255)->nullable();
                }
            });
        }

        if (Schema::hasTable('solar_import_batches')
            && ! Schema::hasColumn('solar_import_batches', 'reference_mappings')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->json('reference_mappings')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['solar_stock', 'solar_pool', 'solar_mess'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['asset_id', 'driver_id'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('solar_stock')) {
            Schema::table('solar_stock', function (Blueprint $table) {
                foreach (['unit', 'operator'] as $column) {
                    if (Schema::hasColumn('solar_stock', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('solar_import_batches')
            && Schema::hasColumn('solar_import_batches', 'reference_mappings')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->dropColumn('reference_mappings');
            });
        }
    }
};
