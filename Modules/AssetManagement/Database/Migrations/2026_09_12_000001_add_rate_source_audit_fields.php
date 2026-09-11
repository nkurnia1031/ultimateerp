<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('heavy_equipment_route_rates')) {
            return;
        }

        Schema::table('heavy_equipment_route_rates', function (Blueprint $table) {
            if (! Schema::hasColumn('heavy_equipment_route_rates', 'source_status')) {
                $table->string('source_status', 20)->default('imported')->after('source_unit_text');
            }
            if (! Schema::hasColumn('heavy_equipment_route_rates', 'source_modified_at')) {
                $table->timestamp('source_modified_at')->nullable()->after('source_status');
            }
            if (! Schema::hasColumn('heavy_equipment_route_rates', 'source_modified_by')) {
                $table->integer('source_modified_by')->unsigned()->nullable()->after('source_modified_at');
            }
        });

        $hasForeign = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'heavy_equipment_route_rates')
            ->where('CONSTRAINT_NAME', 'he_route_rate_source_modified_by_fk')
            ->exists();

        if (Schema::hasColumn('heavy_equipment_route_rates', 'source_modified_by') && ! $hasForeign) {
            Schema::table('heavy_equipment_route_rates', function (Blueprint $table) {
                $table->foreign('source_modified_by', 'he_route_rate_source_modified_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('heavy_equipment_route_rates')) {
            return;
        }

        if (Schema::hasColumn('heavy_equipment_route_rates', 'source_modified_by')) {
            Schema::table('heavy_equipment_route_rates', function (Blueprint $table) {
                $table->dropForeign('he_route_rate_source_modified_by_fk');
            });
        }

        Schema::table('heavy_equipment_route_rates', function (Blueprint $table) {
            foreach (['source_modified_by', 'source_modified_at', 'source_status'] as $column) {
                if (Schema::hasColumn('heavy_equipment_route_rates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
