<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('heavy_equipment_vendor_loading_import_batches')
            || Schema::hasColumn('heavy_equipment_vendor_loading_import_batches', 'user_import_batch_id')) {
            return;
        }

        Schema::table('heavy_equipment_vendor_loading_import_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('user_import_batch_id')->nullable()->after('id');
            $table->foreign('user_import_batch_id', 'hvl_batch_shared_history_fk')
                ->references('id')->on('user_import_batches')->nullOnDelete();
            $table->unique('user_import_batch_id', 'hvl_batch_shared_history_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('heavy_equipment_vendor_loading_import_batches')
            || ! Schema::hasColumn('heavy_equipment_vendor_loading_import_batches', 'user_import_batch_id')) {
            return;
        }

        Schema::table('heavy_equipment_vendor_loading_import_batches', function (Blueprint $table) {
            $table->dropUnique('hvl_batch_shared_history_unique');
            $table->dropForeign('hvl_batch_shared_history_fk');
            $table->dropColumn('user_import_batch_id');
        });
    }
};
