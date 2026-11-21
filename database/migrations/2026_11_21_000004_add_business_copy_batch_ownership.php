<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $table) {
                if (! Schema::hasColumn('contacts', 'user_import_batch_id')) {
                    $table->unsignedBigInteger('user_import_batch_id')->nullable()->index();
                }
                if (! Schema::hasColumn('contacts', 'user_import_source_id')) {
                    $table->unsignedInteger('user_import_source_id')->nullable();
                }
            });
        }

        if (Schema::hasTable('expense_categories')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                if (! Schema::hasColumn('expense_categories', 'user_import_batch_id')) {
                    $table->unsignedBigInteger('user_import_batch_id')->nullable()->index();
                }
                if (! Schema::hasColumn('expense_categories', 'user_import_source_id')) {
                    $table->unsignedInteger('user_import_source_id')->nullable();
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'user_import_batch_id')) {
                    $table->unsignedBigInteger('user_import_batch_id')->nullable()->index();
                }
                if (! Schema::hasColumn('users', 'user_import_source_id')) {
                    $table->unsignedInteger('user_import_source_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['contacts', 'expense_categories', 'users'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $columns = array_values(array_filter(['user_import_batch_id', 'user_import_source_id'], fn ($column) => Schema::hasColumn($tableName, $column)));
            if ($columns !== []) {
                Schema::table($tableName, function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }
    }
};
