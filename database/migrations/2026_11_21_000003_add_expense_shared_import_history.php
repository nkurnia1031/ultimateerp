<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_import_batches')) {
            Schema::table('user_import_batches', function (Blueprint $table) {
                if (! Schema::hasColumn('user_import_batches', 'source_key')) {
                    $table->string('source_key', 64)->nullable()->after('importer_key');
                }
                if (! Schema::hasColumn('user_import_batches', 'search_text')) {
                    $table->longText('search_text')->nullable()->after('options_json');
                }
            });

            if (! $this->hasIndex('user_import_batches', 'user_import_source_key_unique')) {
                Schema::table('user_import_batches', function (Blueprint $table) {
                    $table->unique(['business_id', 'importer_key', 'source_key'], 'user_import_source_key_unique');
                });
            }
        }

        if (Schema::hasTable('expense_import_batches')) {
            Schema::table('expense_import_batches', function (Blueprint $table) {
                if (! Schema::hasColumn('expense_import_batches', 'user_import_batch_id')) {
                    $table->unsignedBigInteger('user_import_batch_id')->nullable()->after('created_by');
                }
            });

            if (! $this->hasIndex('expense_import_batches', 'expense_import_shared_batch_unique')) {
                Schema::table('expense_import_batches', function (Blueprint $table) {
                    $table->unique('user_import_batch_id', 'expense_import_shared_batch_unique');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('expense_import_batches')) {
            if ($this->hasIndex('expense_import_batches', 'expense_import_shared_batch_unique')) {
                Schema::table('expense_import_batches', function (Blueprint $table) {
                    $table->dropUnique('expense_import_shared_batch_unique');
                });
            }
            if (Schema::hasColumn('expense_import_batches', 'user_import_batch_id')) {
                Schema::table('expense_import_batches', function (Blueprint $table) {
                    $table->dropColumn('user_import_batch_id');
                });
            }
        }

        if (Schema::hasTable('user_import_batches')) {
            if ($this->hasIndex('user_import_batches', 'user_import_source_key_unique')) {
                Schema::table('user_import_batches', function (Blueprint $table) {
                    $table->dropUnique('user_import_source_key_unique');
                });
            }

            $columns = array_values(array_filter(['source_key', 'search_text'], fn ($column) => Schema::hasColumn('user_import_batches', $column)));
            if ($columns !== []) {
                Schema::table('user_import_batches', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('".$table."')"))
                ->contains(fn ($row) => ($row->name ?? null) === $index);
        }
        if ($connection->getDriverName() === 'mysql') {
            return collect(DB::select('SHOW INDEX FROM `'.$table.'`'))
                ->contains(fn ($row) => ($row->Key_name ?? null) === $index);
        }

        $indexes = $connection->getDoctrineSchemaManager()->listTableIndexes($table);

        return isset($indexes[strtolower($index)]);
    }
};
