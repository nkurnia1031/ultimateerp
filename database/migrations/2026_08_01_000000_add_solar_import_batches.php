<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('solar_import_batches')) {
            Schema::create('solar_import_batches', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->string('file_name')->nullable();
                $table->string('file_hash', 64)->nullable()->index();
                $table->string('status', 20)->default('preview')->index();
                $table->json('counts')->nullable();
                $table->json('opening_snapshot')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('rolled_back_at')->nullable();
                $table->timestamps();
            });
        }

        foreach (['solar_stock', 'solar_pool', 'solar_mess'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'source_key')) {
                    $table->string('source_key', 64)->nullable()->after('source_hash');
                }
                if (! Schema::hasColumn($tableName, 'import_batch_id')) {
                    $table->uuid('import_batch_id')->nullable()->after('source_key')->index();
                }
            });

            // source_hash is a content fingerprint, not a row identity: two
            // legitimate identical transactions must be allowed to coexist.
            try {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->dropUnique($tableName.'_business_source_hash_unique');
                });
            } catch (Throwable $exception) {
                // Older installations may not have the hardening index.
            }

            try {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->unique(
                        ['business_id', 'source_key'],
                        $tableName.'_business_source_key_unique'
                    );
                });
            } catch (Throwable $exception) {
                // The index may already exist after a partially applied deploy.
            }
        }

        if (Schema::hasTable('solar_opening_balances')
            && ! Schema::hasColumn('solar_opening_balances', 'import_batch_id')) {
            Schema::table('solar_opening_balances', function (Blueprint $table) {
                $table->uuid('import_batch_id')->nullable()->after('ledger')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('solar_opening_balances')
            && Schema::hasColumn('solar_opening_balances', 'import_batch_id')) {
            Schema::table('solar_opening_balances', function (Blueprint $table) {
                $table->dropColumn('import_batch_id');
            });
        }

        foreach (['solar_stock', 'solar_pool', 'solar_mess'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            try {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->dropUnique($tableName.'_business_source_key_unique');
                });
            } catch (Throwable $exception) {
                // No-op for installations where the index was not created.
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $columns = array_values(array_filter(
                    ['source_key', 'import_batch_id'],
                    fn ($column) => Schema::hasColumn($tableName, $column)
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('solar_import_batches');
    }
};
