<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_legacy_import_runs')) {
            Schema::create('accounting_legacy_import_runs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('created_by');
                $table->unsignedSmallInteger('year_filter')->nullable();
                $table->string('source_directory', 500);
                $table->char('manifest_sha256', 64);
                $table->string('status', 32)->default('preparing');
                $table->unsignedBigInteger('journal_import_id')->nullable();
                $table->longText('summary')->nullable();
                $table->text('last_message')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status'], 'alir_business_status_index');
                $table->unique(['business_id', 'manifest_sha256'], 'alir_business_manifest_unique');
                $table->index('journal_import_id', 'alir_journal_import_index');
            });
        }

        if (! Schema::hasTable('accounting_legacy_import_sources')) {
            Schema::create('accounting_legacy_import_sources', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('legacy_import_run_id');
                $table->unsignedInteger('business_id');
                $table->string('source_path', 500);
                $table->char('sha256', 64);
                $table->string('classification', 64);
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->string('status', 32)->default('inventoried');
                $table->unsignedBigInteger('journal_import_id')->nullable();
                $table->unsignedInteger('records_read')->default(0);
                $table->unsignedInteger('records_linked')->default(0);
                $table->unsignedInteger('records_for_review')->default(0);
                $table->longText('summary')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->unique(['legacy_import_run_id', 'source_path'], 'alis_run_path_unique');
                $table->index(['business_id', 'classification', 'status'], 'alis_business_class_status_index');
                $table->index(['business_id', 'sha256'], 'alis_business_sha_index');
            });
        }

        if (! Schema::hasTable('accounting_legacy_import_crosswalks')) {
            Schema::create('accounting_legacy_import_crosswalks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('legacy_import_run_id');
                $table->unsignedBigInteger('legacy_import_source_id');
                $table->unsignedInteger('business_id');
                $table->char('source_identity', 64);
                $table->string('source_type', 100)->nullable();
                $table->text('source_number')->nullable();
                $table->date('source_date')->nullable();
                $table->unsignedBigInteger('journal_import_row_id')->nullable();
                $table->string('target_record_type', 64)->nullable();
                $table->unsignedBigInteger('target_record_id')->nullable();
                $table->string('status', 32)->default('review');
                $table->string('match_method', 32)->nullable();
                $table->text('reason')->nullable();
                $table->timestamps();

                $table->unique(
                    ['legacy_import_run_id', 'legacy_import_source_id', 'source_identity'],
                    'alic_run_source_identity_unique'
                );
                $table->index(['business_id', 'source_type', 'source_date'], 'alic_business_type_date_index');
                $table->index(['business_id', 'target_record_type', 'target_record_id'], 'alic_business_target_index');
                $table->index('journal_import_row_id', 'alic_journal_row_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_legacy_import_crosswalks');
        Schema::dropIfExists('accounting_legacy_import_sources');
        Schema::dropIfExists('accounting_legacy_import_runs');
    }
};
