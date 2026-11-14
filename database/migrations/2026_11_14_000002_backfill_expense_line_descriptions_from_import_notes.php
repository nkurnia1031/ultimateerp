<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCE_FILE = 'export_biaya 2024.xlsx 1 - 100.xlsx';
    private const SOURCE_IMPORT_BATCH = 213;

    public function up(): void
    {
        if (! Schema::hasTable('expense_lines')
            || ! Schema::hasTable('transactions')
            || ! Schema::hasColumn('expense_lines', 'description')
            || ! Schema::hasColumn('expense_lines', 'note')
            || ! Schema::hasColumn('transactions', 'import_file')
            || ! Schema::hasColumn('transactions', 'import_batch')) {
            return;
        }

        DB::table('expense_lines')
            ->join('transactions', 'transactions.id', '=', 'expense_lines.transaction_id')
            ->where('transactions.type', 'expense')
            ->where('transactions.import_file', self::SOURCE_FILE)
            ->where('transactions.import_batch', self::SOURCE_IMPORT_BATCH)
            ->whereNotNull('expense_lines.note')
            ->where('expense_lines.note', '<>', '')
            ->where(function ($query) {
                $query->whereNull('expense_lines.description')
                    ->orWhere('expense_lines.description', '');
            })
            ->update(['expense_lines.description' => DB::raw('expense_lines.note')]);
    }

    public function down(): void
    {
        // Keep copied operational descriptions on rollback. The source note is
        // retained, and users may edit the description after this migration.
    }
};
