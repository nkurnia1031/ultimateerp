<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_legacy_source_findings')) {
            return;
        }

        Schema::create('accounting_legacy_source_findings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('legacy_import_run_id');
            $table->char('source_identity', 64);
            $table->string('source_type', 100);
            $table->string('source_number', 191)->nullable();
            $table->date('source_date')->nullable();
            $table->decimal('source_amount', 22, 4)->nullable();
            $table->string('reason_code', 100);
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->string('status', 24)->default('open');
            $table->string('target_record_type', 64)->nullable();
            $table->unsignedBigInteger('target_record_id')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->json('resolution_history')->nullable();
            $table->timestamps();

            $table->unique(
                ['business_id', 'legacy_import_run_id', 'source_identity'],
                'alsrf_business_run_identity_unique'
            );
            $table->index(['business_id', 'status', 'source_date'], 'alsrf_business_status_date_index');
            $table->index(['business_id', 'source_type', 'status'], 'alsrf_business_type_status_index');
            $table->index(['business_id', 'reason_code', 'status'], 'alsrf_business_reason_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_legacy_source_findings');
    }
};
