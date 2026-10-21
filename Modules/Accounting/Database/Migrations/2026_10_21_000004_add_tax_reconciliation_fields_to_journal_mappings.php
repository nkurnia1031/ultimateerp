<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_acc_trans_mappings')) {
            return;
        }

        $columnsToAdd = array_values(array_filter([
            ! Schema::hasColumn('accounting_acc_trans_mappings', 'tax_reconciliation_status') ? 'tax_reconciliation_status' : null,
            ! Schema::hasColumn('accounting_acc_trans_mappings', 'tax_reconciliation_note') ? 'tax_reconciliation_note' : null,
            ! Schema::hasColumn('accounting_acc_trans_mappings', 'journal_tax_amount') ? 'journal_tax_amount' : null,
            ! Schema::hasColumn('accounting_acc_trans_mappings', 'calculated_tax_amount') ? 'calculated_tax_amount' : null,
            ! Schema::hasColumn('accounting_acc_trans_mappings', 'journal_total_amount') ? 'journal_total_amount' : null,
            ! Schema::hasColumn('accounting_acc_trans_mappings', 'recalculated_final_total') ? 'recalculated_final_total' : null,
            ! Schema::hasColumn('accounting_acc_trans_mappings', 'source_tax_snapshot') ? 'source_tax_snapshot' : null,
        ]));
        if ($columnsToAdd === []) {
            return;
        }

        Schema::table('accounting_acc_trans_mappings', function (Blueprint $table) use ($columnsToAdd) {
            if (in_array('tax_reconciliation_status', $columnsToAdd, true)) {
                $table->string('tax_reconciliation_status', 40)->nullable();
            }
            if (in_array('tax_reconciliation_note', $columnsToAdd, true)) {
                $table->text('tax_reconciliation_note')->nullable();
            }
            if (in_array('journal_tax_amount', $columnsToAdd, true)) {
                $table->decimal('journal_tax_amount', 22, 4)->nullable();
            }
            if (in_array('calculated_tax_amount', $columnsToAdd, true)) {
                $table->decimal('calculated_tax_amount', 22, 4)->nullable();
            }
            if (in_array('journal_total_amount', $columnsToAdd, true)) {
                $table->decimal('journal_total_amount', 22, 4)->nullable();
            }
            if (in_array('recalculated_final_total', $columnsToAdd, true)) {
                $table->decimal('recalculated_final_total', 22, 4)->nullable();
            }
            if (in_array('source_tax_snapshot', $columnsToAdd, true)) {
                $table->json('source_tax_snapshot')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounting_acc_trans_mappings')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('accounting_acc_trans_mappings', 'tax_reconciliation_status') ? 'tax_reconciliation_status' : null,
            Schema::hasColumn('accounting_acc_trans_mappings', 'tax_reconciliation_note') ? 'tax_reconciliation_note' : null,
            Schema::hasColumn('accounting_acc_trans_mappings', 'journal_tax_amount') ? 'journal_tax_amount' : null,
            Schema::hasColumn('accounting_acc_trans_mappings', 'calculated_tax_amount') ? 'calculated_tax_amount' : null,
            Schema::hasColumn('accounting_acc_trans_mappings', 'journal_total_amount') ? 'journal_total_amount' : null,
            Schema::hasColumn('accounting_acc_trans_mappings', 'recalculated_final_total') ? 'recalculated_final_total' : null,
            Schema::hasColumn('accounting_acc_trans_mappings', 'source_tax_snapshot') ? 'source_tax_snapshot' : null,
        ]));

        if ($columns !== []) {
            Schema::table('accounting_acc_trans_mappings', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
