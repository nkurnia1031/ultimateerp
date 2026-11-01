<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_legacy_import_crosswalks')
            && ! Schema::hasColumn('accounting_legacy_import_crosswalks', 'legacy_record_id')) {
            Schema::table('accounting_legacy_import_crosswalks', function (Blueprint $table) {
                $table->string('legacy_record_id', 100)->nullable()->after('source_identity');
                $table->index(['business_id', 'legacy_record_id'], 'alic_business_legacy_record_index');
            });
        }

        if (Schema::hasTable('media')) {
            Schema::table('media', function (Blueprint $table) {
                if (! Schema::hasColumn('media', 'original_name')) {
                    $table->string('original_name')->nullable()->after('file_name');
                }
                if (! Schema::hasColumn('media', 'storage_location')) {
                    $table->enum('storage_location', ['media', 'documents'])->default('media')->after('file_name');
                }
                if (! Schema::hasColumn('media', 'legacy_source_attachment_id')) {
                    $table->string('legacy_source_attachment_id', 100)->nullable()->after('storage_location');
                }
            });

            if (! $this->indexExists('media', 'media_business_legacy_source_unique')) {
                Schema::table('media', function (Blueprint $table) {
                    $table->unique(['business_id', 'legacy_source_attachment_id'], 'media_business_legacy_source_unique');
                });
            }
        }

        if (! Schema::hasTable('accounting_legacy_import_attachments')) {
            Schema::create('accounting_legacy_import_attachments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('legacy_import_run_id');
                $table->unsignedBigInteger('legacy_import_source_id');
                $table->unsignedInteger('business_id');
                $table->string('legacy_source_attachment_id', 100);
                $table->string('legacy_transaction_id', 100)->nullable();
                $table->string('source_type', 100)->nullable();
                $table->string('source_number', 255)->nullable();
                $table->text('source_url')->nullable();
                $table->string('original_file_name', 255)->nullable();
                $table->string('destination_file_name', 255)->nullable();
                $table->unsignedBigInteger('target_transaction_id')->nullable();
                $table->unsignedInteger('media_id')->nullable();
                $table->string('match_method', 32)->nullable();
                $table->string('file_action', 24)->nullable();
                $table->string('status', 24)->default('review');
                $table->text('reason')->nullable();
                $table->timestamps();

                $table->unique(['legacy_import_run_id', 'legacy_source_attachment_id'], 'alia_run_source_attachment_unique');
                $table->index(['business_id', 'status'], 'alia_business_status_index');
                $table->index(['legacy_import_source_id', 'status'], 'alia_source_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_legacy_import_attachments');

        if (Schema::hasTable('media')) {
            if ($this->indexExists('media', 'media_business_legacy_source_unique')) {
                Schema::table('media', function (Blueprint $table) {
                    $table->dropUnique('media_business_legacy_source_unique');
                });
            }
            Schema::table('media', function (Blueprint $table) {
                foreach (['original_name', 'storage_location', 'legacy_source_attachment_id'] as $column) {
                    if (Schema::hasColumn('media', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('accounting_legacy_import_crosswalks')
            && Schema::hasColumn('accounting_legacy_import_crosswalks', 'legacy_record_id')) {
            if ($this->indexExists('accounting_legacy_import_crosswalks', 'alic_business_legacy_record_index')) {
                Schema::table('accounting_legacy_import_crosswalks', function (Blueprint $table) {
                    $table->dropIndex('alic_business_legacy_record_index');
                });
            }
            Schema::table('accounting_legacy_import_crosswalks', function (Blueprint $table) {
                $table->dropColumn('legacy_record_id');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            return $connection->table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->exists();
        }

        if ($driver === 'sqlite') {
            return $connection->select("PRAGMA index_list('".$table."')") !== []
                && collect($connection->select("PRAGMA index_list('".$table."')"))
                    ->contains(fn ($index) => ($index->name ?? null) === $indexName);
        }

        return false;
    }
};
