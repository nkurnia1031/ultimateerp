<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expense_import_batches')) {
            Schema::create('expense_import_batches', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->string('file_name')->nullable();
                $table->string('file_path')->nullable();
                $table->string('file_hash', 64)->nullable()->index();
                $table->string('status', 20)->default('preview')->index();
                $table->string('operation', 20)->default('preview');
                $table->string('process_state', 20)->default('queued')->index();
                $table->string('stage', 30)->nullable();
                $table->unsignedInteger('progress_current')->default(0);
                $table->unsignedInteger('progress_total')->default(0);
                $table->unsignedTinyInteger('progress_percent')->default(0);
                $table->string('progress_message')->nullable();
                $table->json('summary')->nullable();
                $table->json('date_overrides')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('heartbeat_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamp('cancel_requested_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('rolled_back_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('expense_import_preview_rows')) {
            Schema::create('expense_import_preview_rows', function (Blueprint $table) {
                $table->id();
                $table->uuid('batch_id');
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedInteger('source_row')->nullable();
                $table->string('group_key')->nullable();
                $table->string('status', 30)->index();
                $table->json('errors')->nullable();
                $table->json('raw_payload')->nullable();
                $table->json('normalized_payload')->nullable();
                $table->boolean('is_importable')->default(false)->index();
                $table->string('skip_reason')->nullable();
                $table->timestamps();

                $table->index(['batch_id', 'business_id']);
                $table->index(['batch_id', 'status', 'source_row']);
                $table->unique(['batch_id', 'source_row'], 'expense_preview_batch_source_row_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_import_preview_rows');
        Schema::dropIfExists('expense_import_batches');
    }
};
