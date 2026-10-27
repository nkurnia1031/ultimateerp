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

        $missing = [
            'cancel_requested_at' => ! Schema::hasColumn('accounting_journal_imports', 'cancel_requested_at'),
            'cancel_requested_by' => ! Schema::hasColumn('accounting_journal_imports', 'cancel_requested_by'),
            'cancelled_at' => ! Schema::hasColumn('accounting_journal_imports', 'cancelled_at'),
            'cancelled_by' => ! Schema::hasColumn('accounting_journal_imports', 'cancelled_by'),
        ];

        if (in_array(true, $missing, true)) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) use ($missing) {
                if ($missing['cancel_requested_at']) {
                    $table->dateTime('cancel_requested_at')->nullable();
                }
                if ($missing['cancel_requested_by']) {
                    $table->unsignedInteger('cancel_requested_by')->nullable();
                }
                if ($missing['cancelled_at']) {
                    $table->dateTime('cancelled_at')->nullable();
                }
                if ($missing['cancelled_by']) {
                    $table->unsignedInteger('cancelled_by')->nullable();
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
            Schema::hasColumn('accounting_journal_imports', 'cancel_requested_at') ? 'cancel_requested_at' : null,
            Schema::hasColumn('accounting_journal_imports', 'cancel_requested_by') ? 'cancel_requested_by' : null,
            Schema::hasColumn('accounting_journal_imports', 'cancelled_at') ? 'cancelled_at' : null,
            Schema::hasColumn('accounting_journal_imports', 'cancelled_by') ? 'cancelled_by' : null,
        ]));

        if ($columns !== []) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
