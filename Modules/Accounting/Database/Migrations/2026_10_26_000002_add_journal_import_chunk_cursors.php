<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_journal_imports')) {
            return;
        }

        $columns = [
            'prepare_cursor' => ! Schema::hasColumn('accounting_journal_imports', 'prepare_cursor'),
            'tax_reconciliation_cursor' => ! Schema::hasColumn('accounting_journal_imports', 'tax_reconciliation_cursor'),
        ];

        if (in_array(true, $columns, true)) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) use ($columns) {
                if ($columns['prepare_cursor']) {
                    $table->unsignedBigInteger('prepare_cursor')->default(0);
                }
                if ($columns['tax_reconciliation_cursor']) {
                    $table->unsignedBigInteger('tax_reconciliation_cursor')->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounting_journal_imports')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('accounting_journal_imports', 'prepare_cursor') ? 'prepare_cursor' : null,
            Schema::hasColumn('accounting_journal_imports', 'tax_reconciliation_cursor') ? 'tax_reconciliation_cursor' : null,
        ]));

        if ($columns !== []) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
