<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('account_transactions')
            && ! Schema::hasColumn('account_transactions', 'journal_import_row_id')) {
            Schema::table('account_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('journal_import_row_id')->nullable()->after('journal_import_id');
                $table->index('journal_import_row_id', 'account_transactions_journal_import_row_idx');
            });
        }

        if (Schema::hasTable('account_transactions') && DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `account_transactions` MODIFY `sub_type` ENUM('opening_balance', 'fund_transfer', 'deposit', 'withdrawal', 'reversal') NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('account_transactions') && DB::connection()->getDriverName() === 'mysql') {
            $hasNewTypes = DB::table('account_transactions')
                ->whereIn('sub_type', ['withdrawal', 'reversal'])
                ->exists();
            if ($hasNewTypes) {
                throw new RuntimeException('Cannot remove withdrawal/reversal subtypes while account transactions still use them.');
            }
            DB::statement("ALTER TABLE `account_transactions` MODIFY `sub_type` ENUM('opening_balance', 'fund_transfer', 'deposit') NULL");
        }

        if (Schema::hasTable('account_transactions')
            && Schema::hasColumn('account_transactions', 'journal_import_row_id')) {
            Schema::table('account_transactions', function (Blueprint $table) {
                $table->dropIndex('account_transactions_journal_import_row_idx');
                $table->dropColumn('journal_import_row_id');
            });
        }
    }
};
