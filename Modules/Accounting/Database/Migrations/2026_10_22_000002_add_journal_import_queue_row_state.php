<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_journal_import_rows')) {
            return;
        }

        $columns = [
            'processing_status' => ! Schema::hasColumn('accounting_journal_import_rows', 'processing_status'),
            'processing_attempts' => ! Schema::hasColumn('accounting_journal_import_rows', 'processing_attempts'),
            'processing_message' => ! Schema::hasColumn('accounting_journal_import_rows', 'processing_message'),
            'processing_error' => ! Schema::hasColumn('accounting_journal_import_rows', 'processing_error'),
            'processing_started_at' => ! Schema::hasColumn('accounting_journal_import_rows', 'processing_started_at'),
            'processed_at' => ! Schema::hasColumn('accounting_journal_import_rows', 'processed_at'),
            'journal_mapping_id' => ! Schema::hasColumn('accounting_journal_import_rows', 'journal_mapping_id'),
            'reconciliation_status' => ! Schema::hasColumn('accounting_journal_import_rows', 'reconciliation_status'),
            'journal_total' => ! Schema::hasColumn('accounting_journal_import_rows', 'journal_total'),
            'business_total' => ! Schema::hasColumn('accounting_journal_import_rows', 'business_total'),
            'difference_amount' => ! Schema::hasColumn('accounting_journal_import_rows', 'difference_amount'),
            'difference_approved_by' => ! Schema::hasColumn('accounting_journal_import_rows', 'difference_approved_by'),
            'difference_approved_at' => ! Schema::hasColumn('accounting_journal_import_rows', 'difference_approved_at'),
            'difference_approval_reason' => ! Schema::hasColumn('accounting_journal_import_rows', 'difference_approval_reason'),
        ];

        Schema::table('accounting_journal_import_rows', function (Blueprint $table) use ($columns) {
            if ($columns['processing_status']) {
                $table->string('processing_status', 20)->default('pending');
            }
            if ($columns['processing_attempts']) {
                $table->unsignedInteger('processing_attempts')->default(0);
            }
            if ($columns['processing_message']) {
                $table->text('processing_message')->nullable();
            }
            if ($columns['processing_error']) {
                $table->text('processing_error')->nullable();
            }
            if ($columns['processing_started_at']) {
                $table->dateTime('processing_started_at')->nullable();
            }
            if ($columns['processed_at']) {
                $table->dateTime('processed_at')->nullable();
            }
            if ($columns['journal_mapping_id']) {
                $table->unsignedBigInteger('journal_mapping_id')->nullable();
            }
            if ($columns['reconciliation_status']) {
                $table->string('reconciliation_status', 20)->default('not_checked');
            }
            if ($columns['journal_total']) {
                $table->decimal('journal_total', 22, 4)->nullable();
            }
            if ($columns['business_total']) {
                $table->decimal('business_total', 22, 4)->nullable();
            }
            if ($columns['difference_amount']) {
                $table->decimal('difference_amount', 22, 4)->nullable();
            }
            if ($columns['difference_approved_by']) {
                $table->unsignedInteger('difference_approved_by')->nullable();
            }
            if ($columns['difference_approved_at']) {
                $table->dateTime('difference_approved_at')->nullable();
            }
            if ($columns['difference_approval_reason']) {
                $table->text('difference_approval_reason')->nullable();
            }
        });

        if (! $this->hasIndex('accounting_journal_import_rows', 'ajir_import_processing_index')) {
            Schema::table('accounting_journal_import_rows', function (Blueprint $table) {
                $table->index(['journal_import_id', 'processing_status'], 'ajir_import_processing_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounting_journal_import_rows')) {
            return;
        }

        if ($this->hasIndex('accounting_journal_import_rows', 'ajir_import_processing_index')) {
            Schema::table('accounting_journal_import_rows', function (Blueprint $table) {
                $table->dropIndex('ajir_import_processing_index');
            });
        }

        $columns = [
            'processing_status', 'processing_attempts', 'processing_message', 'processing_error',
            'processing_started_at', 'processed_at', 'journal_mapping_id', 'reconciliation_status',
            'journal_total', 'business_total', 'difference_amount', 'difference_approved_by',
            'difference_approved_at', 'difference_approval_reason',
        ];
        $columns = array_values(array_filter($columns, function ($column) {
            return Schema::hasColumn('accounting_journal_import_rows', $column);
        }));

        if ($columns !== []) {
            Schema::table('accounting_journal_import_rows', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::connection()->getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        if ($driver === 'sqlite') {
            foreach (DB::select('PRAGMA index_list("'.$table.'")') as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }
        }

        return false;
    }
};
