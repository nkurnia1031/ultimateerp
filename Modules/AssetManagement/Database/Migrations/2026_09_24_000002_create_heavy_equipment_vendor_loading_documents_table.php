<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('heavy_equipment_vendor_loadings')) {
            return;
        }

        if (! Schema::hasTable('heavy_equipment_vendor_loading_documents')) {
            Schema::create('heavy_equipment_vendor_loading_documents', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id');
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                $table->string('document_no', 100);
                $table->string('goods_name')->nullable();
                $table->string('goods_type')->nullable();
                $table->string('vessel_name')->nullable();
                $table->string('destination')->nullable();
                $table->unsignedInteger('supplier_id')->nullable();
                $table->foreign('supplier_id')->references('id')->on('contacts')->nullOnDelete();
                $table->string('supplier_name_snapshot')->nullable();
                $table->unsignedInteger('customer_id')->nullable();
                $table->foreign('customer_id')->references('id')->on('contacts')->nullOnDelete();
                $table->string('customer_name_snapshot')->nullable();
                $table->string('source_workbook')->nullable();
                $table->string('source_sheet')->nullable();
                $table->char('source_file_hash', 64)->nullable();
                $table->string('status', 20)->default('draft')->index();
                $table->unsignedInteger('expense_transaction_id')->nullable();
                $table->foreign('expense_transaction_id', 'he_vendor_doc_expense_fk')->references('id')->on('transactions')->nullOnDelete();
                $table->string('expense_status', 20)->default('not_linked')->index();
                $table->boolean('metadata_conflict')->default(false);
                $table->unsignedInteger('created_by')->nullable();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->unsignedInteger('verified_by')->nullable();
                $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
                $table->dateTime('verified_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'document_no'], 'he_vendor_doc_no_unique');
                $table->index(['business_id', 'source_workbook', 'source_sheet'], 'he_vendor_doc_source_idx');
            });
        }

        if (! Schema::hasColumn('heavy_equipment_vendor_loadings', 'loading_document_id')) {
            Schema::table('heavy_equipment_vendor_loadings', function (Blueprint $table) {
                $table->unsignedInteger('loading_document_id')->nullable()->after('id');
                $table->foreign('loading_document_id', 'he_vendor_loading_document_fk')
                    ->references('id')->on('heavy_equipment_vendor_loading_documents')->onDelete('cascade');
                $table->index(['loading_document_id', 'status'], 'he_vendor_doc_line_status_idx');
            });
        }

        // Existing line-only data is intentionally not migrated into synthetic documents.
    }

    public function down()
    {
        if (Schema::hasTable('heavy_equipment_vendor_loadings') && Schema::hasColumn('heavy_equipment_vendor_loadings', 'loading_document_id')) {
            Schema::table('heavy_equipment_vendor_loadings', function (Blueprint $table) {
                $table->dropForeign('he_vendor_loading_document_fk');
                $table->dropIndex('he_vendor_doc_line_status_idx');
                $table->dropColumn('loading_document_id');
            });
        }

        Schema::dropIfExists('heavy_equipment_vendor_loading_documents');
    }

};
