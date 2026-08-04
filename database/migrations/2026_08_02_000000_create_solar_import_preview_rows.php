<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('solar_import_batches') && ! Schema::hasColumn('solar_import_batches', 'date_overrides')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->json('date_overrides')->nullable()->after('opening_snapshot');
            });
        }
        if (Schema::hasTable('solar_import_batches') && ! Schema::hasColumn('solar_import_batches', 'file_path')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->string('file_path')->nullable()->after('file_hash');
            });
        }

        if (! Schema::hasTable('solar_import_preview_rows')) {
            Schema::create('solar_import_preview_rows', function (Blueprint $table) {
                $table->id();
                $table->uuid('batch_id');
                $table->unsignedBigInteger('business_id')->index();
                $table->string('ledger', 20);
                $table->unsignedInteger('source_row')->nullable();
                $table->text('raw_date')->nullable();
                $table->date('normalized_date')->nullable();
                $table->string('status', 30)->index();
                $table->string('matched_by', 30)->nullable();
                $table->boolean('balance_only')->default(false);
                $table->json('differences')->nullable();
                $table->json('excel_payload')->nullable();
                $table->json('app_payload')->nullable();
                $table->boolean('is_importable')->default(false)->index();
                $table->timestamps();

                $table->index(['batch_id', 'business_id']);
                $table->index(['batch_id', 'ledger', 'source_row']);
                $table->unique(['batch_id', 'ledger', 'source_row'], 'solar_preview_batch_ledger_row_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('solar_import_preview_rows');
        if (Schema::hasTable('solar_import_batches')
            && (Schema::hasColumn('solar_import_batches', 'date_overrides')
                || Schema::hasColumn('solar_import_batches', 'file_path'))) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $columns = [];
                if (Schema::hasColumn('solar_import_batches', 'date_overrides')) {
                    $columns[] = 'date_overrides';
                }
                if (Schema::hasColumn('solar_import_batches', 'file_path')) {
                    $columns[] = 'file_path';
                }
                $table->dropColumn($columns);
            });
        }
    }
};
