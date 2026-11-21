<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('solar_import_batches')) {
            return;
        }

        if (! Schema::hasColumn('solar_import_batches', 'user_import_batch_id')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->unsignedBigInteger('user_import_batch_id')->nullable()->after('created_by');
            });
        }

        if (! $this->hasIndex('solar_import_batches', 'solar_import_shared_batch_unique')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->unique('user_import_batch_id', 'solar_import_shared_batch_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('solar_import_batches')) {
            return;
        }

        if ($this->hasIndex('solar_import_batches', 'solar_import_shared_batch_unique')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->dropUnique('solar_import_shared_batch_unique');
            });
        }

        if (Schema::hasColumn('solar_import_batches', 'user_import_batch_id')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
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

        return isset($connection->getDoctrineSchemaManager()->listTableIndexes($table)[strtolower($index)]);
    }
};
