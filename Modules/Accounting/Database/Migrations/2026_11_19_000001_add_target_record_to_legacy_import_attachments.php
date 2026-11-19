<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_legacy_import_attachments')) {
            return;
        }

        Schema::table('accounting_legacy_import_attachments', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_legacy_import_attachments', 'target_record_type')) {
                $table->string('target_record_type', 100)->nullable()->after('target_transaction_id');
            }
            if (! Schema::hasColumn('accounting_legacy_import_attachments', 'target_record_id')) {
                $table->unsignedBigInteger('target_record_id')->nullable()->after('target_record_type');
            }
            if (! Schema::hasColumn('accounting_legacy_import_attachments', 'media_model_type')) {
                $table->string('media_model_type', 100)->nullable()->after('media_id');
            }
            if (! Schema::hasColumn('accounting_legacy_import_attachments', 'media_model_id')) {
                $table->unsignedBigInteger('media_model_id')->nullable()->after('media_model_type');
            }
        });

        if (! $this->indexExists('accounting_legacy_import_attachments', 'alia_target_record_index')) {
            Schema::table('accounting_legacy_import_attachments', function (Blueprint $table) {
                $table->index(['business_id', 'target_record_type', 'target_record_id'], 'alia_target_record_index');
            });
        }

        DB::table('accounting_legacy_import_attachments')
            ->whereNotNull('target_transaction_id')
            ->update([
                'target_record_type' => App\Transaction::class,
                'target_record_id' => DB::raw('target_transaction_id'),
                'media_model_type' => App\Transaction::class,
                'media_model_id' => DB::raw('target_transaction_id'),
            ]);

    }

    public function down(): void
    {
        if (! Schema::hasTable('accounting_legacy_import_attachments')) {
            return;
        }

        if ($this->indexExists('accounting_legacy_import_attachments', 'alia_target_record_index')) {
            Schema::table('accounting_legacy_import_attachments', function (Blueprint $table) {
                $table->dropIndex('alia_target_record_index');
            });
        }

        $columns = array_values(array_filter(
            ['target_record_type', 'target_record_id', 'media_model_type', 'media_model_id'],
            fn (string $column) => Schema::hasColumn('accounting_legacy_import_attachments', $column)
        ));
        if ($columns !== []) {
            Schema::table('accounting_legacy_import_attachments', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        if ($connection->getDriverName() === 'mysql') {
            return $connection->table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->exists();
        }

        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select("PRAGMA index_list('".$table."')"))
                ->contains(fn ($index) => ($index->name ?? null) === $indexName);
        }

        return false;
    }
};
