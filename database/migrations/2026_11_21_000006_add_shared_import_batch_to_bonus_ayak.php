<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bonus_ayak')) {
            return;
        }

        if (! Schema::hasColumn('bonus_ayak', 'user_import_batch_id')) {
            Schema::table('bonus_ayak', function (Blueprint $table) {
                $table->unsignedBigInteger('user_import_batch_id')->nullable();
            });
        }

        if (! $this->hasIndex('bonus_ayak', 'bonus_ayak_user_import_batch_idx')) {
            Schema::table('bonus_ayak', function (Blueprint $table) {
                $table->index('user_import_batch_id', 'bonus_ayak_user_import_batch_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bonus_ayak')) {
            return;
        }

        if ($this->hasIndex('bonus_ayak', 'bonus_ayak_user_import_batch_idx')) {
            Schema::table('bonus_ayak', function (Blueprint $table) {
                $table->dropIndex('bonus_ayak_user_import_batch_idx');
            });
        }
        if (Schema::hasColumn('bonus_ayak', 'user_import_batch_id')) {
            Schema::table('bonus_ayak', function (Blueprint $table) {
                $table->dropColumn('user_import_batch_id');
            });
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
