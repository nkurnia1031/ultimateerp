<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('heavy_equipment_rate_batches')) {
            return;
        }

        Schema::table('heavy_equipment_rate_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('heavy_equipment_rate_batches', 'user_import_batch_id')) {
                $table->unsignedBigInteger('user_import_batch_id')->nullable()->after('id');
                $table->unique('user_import_batch_id', 'he_rate_batch_user_import_unique');
                $table->foreign('user_import_batch_id', 'he_rate_batch_user_import_fk')
                    ->references('id')->on('user_import_batches')->nullOnDelete();
            }
            if (! Schema::hasColumn('heavy_equipment_rate_batches', 'revert_snapshot_json')) {
                $table->longText('revert_snapshot_json')->nullable()->after('error_message');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('heavy_equipment_rate_batches')) {
            return;
        }

        Schema::table('heavy_equipment_rate_batches', function (Blueprint $table) {
            if (Schema::hasColumn('heavy_equipment_rate_batches', 'user_import_batch_id')) {
                $table->dropForeign('he_rate_batch_user_import_fk');
                $table->dropUnique('he_rate_batch_user_import_unique');
                $table->dropColumn('user_import_batch_id');
            }
            if (Schema::hasColumn('heavy_equipment_rate_batches', 'revert_snapshot_json')) {
                $table->dropColumn('revert_snapshot_json');
            }
        });
    }
};
