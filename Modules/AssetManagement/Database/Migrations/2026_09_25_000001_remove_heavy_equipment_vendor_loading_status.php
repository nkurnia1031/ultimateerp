<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $table = 'heavy_equipment_vendor_loading_documents';
        if (! Schema::hasTable($table)) {
            return;
        }

        $foreignKeys = [];
        if (Schema::hasColumn($table, 'verified_by') && $this->foreignExists($table, 'heavy_equipment_vendor_loading_documents_verified_by_foreign')) {
            $foreignKeys[] = 'heavy_equipment_vendor_loading_documents_verified_by_foreign';
        }
        $indexes = [];
        if (Schema::hasColumn($table, 'status') && $this->indexExists($table, 'heavy_equipment_vendor_loading_documents_status_index')) {
            $indexes[] = 'heavy_equipment_vendor_loading_documents_status_index';
        }
        $columns = array_values(array_filter(['status', 'verified_by', 'verified_at'], fn ($column) => Schema::hasColumn($table, $column)));

        if ($columns !== [] || $indexes !== [] || $foreignKeys !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexes, $foreignKeys) {
                foreach ($foreignKeys as $foreignKey) {
                    $blueprint->dropForeign($foreignKey);
                }
                foreach ($indexes as $index) {
                    $blueprint->dropIndex($index);
                }
                if ($columns !== []) {
                    $blueprint->dropColumn($columns);
                }
            });
        }
    }

    public function down()
    {
        $table = 'heavy_equipment_vendor_loading_documents';
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'status')) {
                $blueprint->string('status', 20)->default('draft')->index();
            }
            if (! Schema::hasColumn($table, 'verified_by')) {
                $blueprint->unsignedInteger('verified_by')->nullable();
                $blueprint->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn($table, 'verified_at')) {
                $blueprint->dateTime('verified_at')->nullable();
            }
        });
    }

    private function foreignExists(string $table, string $foreign): bool
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $foreign)
            ->exists();
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
