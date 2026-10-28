<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_general_ledger_backfill_retry_batches')) {
            Schema::table('accounting_general_ledger_backfill_retry_batches', function (Blueprint $table) {
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_batches', 'selected_items')) {
                    $table->unsignedInteger('selected_items')->default(0)->after('created_by');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_batches', 'planned_debit')) {
                    $table->decimal('planned_debit', 22, 4)->default(0)->after('total_items');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_batches', 'planned_credit')) {
                    $table->decimal('planned_credit', 22, 4)->default(0)->after('planned_debit');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_batches', 'plan_hash')) {
                    $table->string('plan_hash', 64)->nullable()->after('planned_credit');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_batches', 'preview_data')) {
                    $table->longText('preview_data')->nullable()->after('plan_hash');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_batches', 'previewed_at')) {
                    $table->dateTime('previewed_at')->nullable()->after('preview_data');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_batches', 'confirmed_by')) {
                    $table->unsignedInteger('confirmed_by')->nullable()->after('previewed_at');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_batches', 'confirmed_at')) {
                    $table->dateTime('confirmed_at')->nullable()->after('confirmed_by');
                }
            });
        }

        if (Schema::hasTable('accounting_general_ledger_backfill_retry_items')) {
            Schema::table('accounting_general_ledger_backfill_retry_items', function (Blueprint $table) {
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_items', 'plan_hash')) {
                    $table->string('plan_hash', 64)->nullable()->after('status');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_items', 'planned_debit')) {
                    $table->decimal('planned_debit', 22, 4)->default(0)->after('plan_hash');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_items', 'planned_credit')) {
                    $table->decimal('planned_credit', 22, 4)->default(0)->after('planned_debit');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_items', 'preview_message')) {
                    $table->text('preview_message')->nullable()->after('planned_credit');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_retry_items', 'preview_data')) {
                    $table->longText('preview_data')->nullable()->after('preview_message');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('accounting_general_ledger_backfill_retry_items')) {
            Schema::table('accounting_general_ledger_backfill_retry_items', function (Blueprint $table) {
                foreach (['preview_data', 'preview_message', 'planned_credit', 'planned_debit', 'plan_hash'] as $column) {
                    if (Schema::hasColumn('accounting_general_ledger_backfill_retry_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('accounting_general_ledger_backfill_retry_batches')) {
            Schema::table('accounting_general_ledger_backfill_retry_batches', function (Blueprint $table) {
                foreach (['confirmed_at', 'confirmed_by', 'previewed_at', 'preview_data', 'plan_hash', 'planned_credit', 'planned_debit', 'selected_items'] as $column) {
                    if (Schema::hasColumn('accounting_general_ledger_backfill_retry_batches', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
