<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $documents = 'heavy_equipment_vendor_loading_documents';
        $lines = 'heavy_equipment_vendor_loadings';

        if (! Schema::hasTable($documents) || ! Schema::hasTable($lines)) {
            return;
        }

        // Orphan line records are intentionally discarded. Every active line must belong to a document.
        DB::table($lines)->whereNull('loading_document_id')->delete();

        // Per-line mixed state and old per-line Expense links are no longer part of the document model.
        DB::table($documents)->where('status', 'mixed')->update(['status' => 'draft']);
        DB::table($documents)->where('expense_status', 'mixed')->update([
            'expense_status' => 'not_linked',
            'expense_transaction_id' => null,
        ]);

        $foreignKeys = [
            'heavy_equipment_vendor_loadings_business_id_foreign',
            'he_vendor_loading_document_fk',
            'heavy_equipment_vendor_loadings_supplier_id_foreign',
            'heavy_equipment_vendor_loadings_route_id_foreign',
            'heavy_equipment_vendor_loadings_customer_id_foreign',
            'he_vendor_loading_expense_fk',
            'heavy_equipment_vendor_loadings_verified_by_foreign',
        ];
        $foreignKeys = array_values(array_filter($foreignKeys, fn ($name) => $this->foreignExists($lines, $name)));

        $indexes = [
            'he_vendor_loading_no_unique',
            'he_vendor_loading_date_idx',
            'he_vendor_loading_source_idx',
            'he_vendor_doc_line_status_idx',
            'he_vendor_loading_supplier_idx',
            'heavy_equipment_vendor_loadings_status_index',
            'heavy_equipment_vendor_loadings_expense_status_index',
        ];
        $indexes = array_values(array_filter($indexes, fn ($name) => $this->indexExists($lines, $name)));

        $columns = [
            'business_id', 'supplier_id', 'supplier_name_snapshot', 'route_id', 'route_name_snapshot',
            'customer_id', 'goods_name', 'goods_type', 'vessel_name', 'destination', 'status',
            'expense_transaction_id', 'expense_status', 'source_workbook', 'source_sheet',
            'verified_by', 'verified_at', 'source_file_hash',
        ];
        $columns = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($lines, $column)));

        Schema::table($lines, function (Blueprint $table) use ($foreignKeys, $indexes, $columns) {
            foreach ($foreignKeys as $foreign) {
                $table->dropForeign($foreign);
            }
            foreach ($indexes as $index) {
                $table->dropIndex($index);
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        DB::statement('ALTER TABLE heavy_equipment_vendor_loadings MODIFY loading_document_id INT UNSIGNED NOT NULL');

        $normalizedIndexes = [
            'he_vendor_loading_doc_no_unique' => fn (Blueprint $table) => $table->unique(['loading_document_id', 'loading_no'], 'he_vendor_loading_doc_no_unique'),
            'he_vendor_loading_doc_date_idx' => fn (Blueprint $table) => $table->index(['loading_document_id', 'loading_date'], 'he_vendor_loading_doc_date_idx'),
            'he_vendor_loading_doc_source_idx' => fn (Blueprint $table) => $table->index(['loading_document_id', 'source_fingerprint'], 'he_vendor_loading_doc_source_idx'),
            'he_vendor_loading_doc_ticket_idx' => fn (Blueprint $table) => $table->index(['loading_document_id', 'ticket_no'], 'he_vendor_loading_doc_ticket_idx'),
        ];
        foreach ($normalizedIndexes as $name => $definition) {
            if (! $this->indexExists($lines, $name)) {
                Schema::table($lines, $definition);
            }
        }

        if (! $this->foreignExists($lines, 'he_vendor_loading_document_fk')) {
            Schema::table($lines, function (Blueprint $table) {
                $table->foreign('loading_document_id', 'he_vendor_loading_document_fk')
                    ->references('id')->on('heavy_equipment_vendor_loading_documents')->onDelete('cascade');
            });
        }

        if (Schema::hasColumn($documents, 'metadata_conflict')) {
            Schema::table($documents, function (Blueprint $table) {
                $table->dropColumn('metadata_conflict');
            });
        }
    }

    public function down()
    {
        $documents = 'heavy_equipment_vendor_loading_documents';
        $lines = 'heavy_equipment_vendor_loadings';

        if (! Schema::hasTable($lines)) {
            return;
        }

        Schema::table($lines, function (Blueprint $table) {
            $table->unsignedInteger('business_id')->nullable()->after('id');
            $table->unsignedInteger('supplier_id')->nullable();
            $table->string('supplier_name_snapshot')->nullable();
            $table->unsignedInteger('route_id')->nullable();
            $table->string('route_name_snapshot')->nullable();
            $table->unsignedInteger('customer_id')->nullable();
            $table->string('goods_name')->nullable();
            $table->string('goods_type')->nullable();
            $table->string('vessel_name')->nullable();
            $table->string('destination')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('expense_transaction_id')->nullable();
            $table->string('expense_status', 20)->nullable();
            $table->string('source_workbook')->nullable();
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('supplier_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('route_id')->references('id')->on('heavy_equipment_routes')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('expense_transaction_id', 'he_vendor_loading_expense_fk')->references('id')->on('transactions')->nullOnDelete();
            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
            $table->index('expense_status');
            $table->unique(['business_id', 'loading_no'], 'he_vendor_loading_no_unique');
            $table->index(['business_id', 'loading_date'], 'he_vendor_loading_date_idx');
            $table->index(['business_id', 'source_fingerprint'], 'he_vendor_loading_source_idx');
            $table->index(['business_id', 'supplier_id'], 'he_vendor_loading_supplier_idx');
            $table->index(['loading_document_id', 'status'], 'he_vendor_doc_line_status_idx');
        });

        DB::table($lines.' as line')
            ->join($documents.' as document', 'document.id', '=', 'line.loading_document_id')
            ->update([
                'line.business_id' => DB::raw('document.business_id'),
                'line.supplier_id' => DB::raw('document.supplier_id'),
                'line.supplier_name_snapshot' => DB::raw('document.supplier_name_snapshot'),
                'line.route_id' => DB::raw('document.route_id'),
                'line.route_name_snapshot' => DB::raw('document.route_name_snapshot'),
                'line.customer_id' => DB::raw('document.customer_id'),
                'line.goods_name' => DB::raw('document.goods_name'),
                'line.goods_type' => DB::raw('document.goods_type'),
                'line.vessel_name' => DB::raw('document.vessel_name'),
                'line.destination' => DB::raw('document.destination'),
                'line.status' => DB::raw('document.status'),
                'line.expense_transaction_id' => DB::raw('document.expense_transaction_id'),
                'line.expense_status' => DB::raw('document.expense_status'),
                'line.source_workbook' => DB::raw('document.source_workbook'),
                'line.source_sheet' => DB::raw('document.source_sheet'),
                'line.verified_by' => DB::raw('document.verified_by'),
                'line.verified_at' => DB::raw('document.verified_at'),
            ]);

        DB::statement('ALTER TABLE heavy_equipment_vendor_loadings MODIFY loading_document_id INT UNSIGNED NULL');

        if (Schema::hasTable($documents) && ! Schema::hasColumn($documents, 'metadata_conflict')) {
            Schema::table($documents, function (Blueprint $table) {
                $table->boolean('metadata_conflict')->default(false);
            });
        }
    }

    private function foreignExists(string $table, string $foreign): bool
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $foreign)
            ->exists();
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
