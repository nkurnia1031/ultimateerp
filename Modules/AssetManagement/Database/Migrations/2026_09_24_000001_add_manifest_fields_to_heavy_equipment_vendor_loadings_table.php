<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddManifestFieldsToHeavyEquipmentVendorLoadingsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('heavy_equipment_vendor_loadings')) {
            return;
        }

        Schema::table('heavy_equipment_vendor_loadings', function (Blueprint $table) {
            if (! Schema::hasColumn('heavy_equipment_vendor_loadings', 'goods_name')) {
                $table->string('goods_name')->nullable();
            }
            if (! Schema::hasColumn('heavy_equipment_vendor_loadings', 'goods_type')) {
                $table->string('goods_type')->nullable();
            }
            if (! Schema::hasColumn('heavy_equipment_vendor_loadings', 'vessel_name')) {
                $table->string('vessel_name')->nullable();
            }
            if (! Schema::hasColumn('heavy_equipment_vendor_loadings', 'destination')) {
                $table->string('destination')->nullable();
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('heavy_equipment_vendor_loadings')) {
            return;
        }

        Schema::table('heavy_equipment_vendor_loadings', function (Blueprint $table) {
            foreach (['goods_name', 'goods_type', 'vessel_name', 'destination'] as $column) {
                if (Schema::hasColumn('heavy_equipment_vendor_loadings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
