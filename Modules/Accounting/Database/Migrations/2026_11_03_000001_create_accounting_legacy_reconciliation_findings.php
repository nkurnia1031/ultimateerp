<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_legacy_reconciliation_audits')) {
            Schema::create('accounting_legacy_reconciliation_audits', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('business_id');
                $table->unsignedSmallInteger('year');
                $table->char('audit_key', 64);
                $table->char('source_manifest_sha256', 64)->nullable();
                $table->unsignedInteger('finding_count')->default(0);
                $table->dateTime('completed_at');
                $table->timestamps();

                $table->unique(['business_id', 'year', 'audit_key'], 'alra_business_year_audit_unique');
                $table->index(['business_id', 'completed_at'], 'alra_business_completed_index');
            });
        }

        if (Schema::hasTable('accounting_legacy_reconciliation_findings')) {
            return;
        }

        Schema::create('accounting_legacy_reconciliation_findings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedSmallInteger('year');
            $table->string('type', 40);
            $table->string('component', 40);
            $table->char('finding_key', 64);
            $table->char('source_identity', 64);
            $table->string('source_type', 100)->nullable();
            $table->text('source_number')->nullable();
            $table->date('source_date')->nullable();
            $table->string('account_no', 100)->default('');
            $table->string('account_name')->default('');
            $table->text('description')->nullable();
            $table->decimal('expected_amount', 22, 4)->nullable();
            $table->decimal('actual_amount', 22, 4)->nullable();
            $table->decimal('variance', 22, 4);
            $table->longText('evidence');
            $table->string('status', 24)->default('open');
            $table->boolean('is_current')->default(true);
            $table->char('last_audit_key', 64);
            $table->dateTime('last_audited_at');
            $table->longText('resolution_history')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'finding_key'], 'alrf_business_finding_unique');
            $table->index(['business_id', 'is_current', 'status'], 'alrf_business_current_status_index');
            $table->index(['business_id', 'year', 'type', 'is_current'], 'alrf_business_year_type_index');
            $table->index(['business_id', 'source_date'], 'alrf_business_source_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_legacy_reconciliation_findings');
        Schema::dropIfExists('accounting_legacy_reconciliation_audits');
    }
};
