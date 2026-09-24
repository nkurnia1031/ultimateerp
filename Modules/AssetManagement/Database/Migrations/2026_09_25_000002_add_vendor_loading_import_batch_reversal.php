<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('heavy_equipment_vendor_loading_documents')
            && ! Schema::hasColumn('heavy_equipment_vendor_loading_documents', 'import_batch_id')) {
            Schema::table('heavy_equipment_vendor_loading_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('import_batch_id')->nullable()->after('source_file_hash');
                $table->foreign('import_batch_id', 'he_vendor_doc_import_batch_fk')
                    ->references('id')->on('heavy_equipment_vendor_loading_import_batches')->nullOnDelete();
                $table->index('import_batch_id', 'he_vendor_doc_import_batch_idx');
            });
        }

        if (Schema::hasTable('heavy_equipment_vendor_loading_import_batches')) {
            Schema::table('heavy_equipment_vendor_loading_import_batches', function (Blueprint $table) {
                if (! Schema::hasColumn('heavy_equipment_vendor_loading_import_batches', 'reversed_at')) {
                    $table->dateTime('reversed_at')->nullable();
                }
                if (! Schema::hasColumn('heavy_equipment_vendor_loading_import_batches', 'reversed_by')) {
                    $table->unsignedInteger('reversed_by')->nullable();
                    $table->foreign('reversed_by', 'hvl_import_reversed_by_fk')
                        ->references('id')->on('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('heavy_equipment_vendor_loading_import_batches', 'reversed_documents')) {
                    $table->unsignedInteger('reversed_documents')->default(0);
                }
                if (! Schema::hasColumn('heavy_equipment_vendor_loading_import_batches', 'reversed_lines')) {
                    $table->unsignedInteger('reversed_lines')->default(0);
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('heavy_equipment_vendor_loading_import_batches')) {
            Schema::table('heavy_equipment_vendor_loading_import_batches', function (Blueprint $table) {
                if (Schema::hasColumn('heavy_equipment_vendor_loading_import_batches', 'reversed_by')) {
                    $table->dropForeign('hvl_import_reversed_by_fk');
                }
                $columns = array_values(array_filter(
                    ['reversed_at', 'reversed_by', 'reversed_documents', 'reversed_lines'],
                    fn ($column) => Schema::hasColumn('heavy_equipment_vendor_loading_import_batches', $column)
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('heavy_equipment_vendor_loading_documents')
            && Schema::hasColumn('heavy_equipment_vendor_loading_documents', 'import_batch_id')) {
            Schema::table('heavy_equipment_vendor_loading_documents', function (Blueprint $table) {
                $table->dropForeign('he_vendor_doc_import_batch_fk');
                $table->dropIndex('he_vendor_doc_import_batch_idx');
                $table->dropColumn('import_batch_id');
            });
        }
    }
};
