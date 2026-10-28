<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_general_ledger_backfill_reviews')) {
            return;
        }

        Schema::create('accounting_general_ledger_backfill_reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('source_record_type', 100);
            $table->unsignedBigInteger('source_record_id');
            $table->dateTime('operation_date')->nullable();
            $table->string('source_reference', 191)->nullable();
            $table->text('source_summary');
            $table->json('source_context')->nullable();
            $table->string('skip_reason', 100);
            $table->string('status', 24)->default('pending');
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->json('audit_log')->nullable();
            $table->timestamps();

            $table->unique(
                ['business_id', 'source_record_type', 'source_record_id'],
                'aglbr_business_source_unique'
            );
            $table->index(['business_id', 'status', 'operation_date'], 'aglbr_business_status_date_index');
            $table->index(['business_id', 'skip_reason', 'status'], 'aglbr_business_reason_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_general_ledger_backfill_reviews');
    }
};
