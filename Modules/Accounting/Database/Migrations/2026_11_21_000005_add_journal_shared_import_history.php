<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_journal_imports')) {
            return;
        }

        if (! Schema::hasColumn('accounting_journal_imports', 'import_origin')) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) {
                $table->string('import_origin', 16)->default('web');
            });
        }

        if (! Schema::hasColumn('accounting_journal_imports', 'user_import_batch_id')) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) {
                $table->unsignedBigInteger('user_import_batch_id')->nullable();
            });
        }

        if (! $this->hasIndex('accounting_journal_imports', 'accounting_journal_imports_user_batch_unique')) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) {
                $table->unique('user_import_batch_id', 'accounting_journal_imports_user_batch_unique');
            });
        }

        // Older CLI imports already record this mode once posting starts. New CLI
        // batches write import_origin directly when they are created.
        DB::table('accounting_journal_imports')
            ->whereNotNull('import_options')
            ->where('import_options', 'like', '%"execution_mode":"cli"%')
            ->update(['import_origin' => 'cli']);
        DB::table('accounting_journal_imports')
            ->where('last_message', 'like', 'CLI %')
            ->update(['import_origin' => 'cli']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounting_journal_imports')) {
            return;
        }

        if ($this->hasIndex('accounting_journal_imports', 'accounting_journal_imports_user_batch_unique')) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) {
                $table->dropUnique('accounting_journal_imports_user_batch_unique');
            });
        }

        $columns = array_values(array_filter(['user_import_batch_id', 'import_origin'], function ($column) {
            return Schema::hasColumn('accounting_journal_imports', $column);
        }));
        if ($columns !== []) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row) => $row->name === $index);
        }

        return collect($connection->select('SHOW INDEX FROM `'.$table.'`'))
            ->contains(fn ($row) => $row->Key_name === $index);
    }
};
