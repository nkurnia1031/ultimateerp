<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_legacy_reconciliation_audits')
            && ! Schema::hasColumn('accounting_legacy_reconciliation_audits', 'scope_key')) {
            Schema::table('accounting_legacy_reconciliation_audits', function (Blueprint $table): void {
                $table->string('scope_key', 120)->default('legacy-annual')->after('year');
                $table->index(['business_id', 'scope_key', 'completed_at'], 'alra_business_scope_completed_index');
            });
        }

        if (Schema::hasTable('accounting_legacy_reconciliation_findings')
            && ! Schema::hasColumn('accounting_legacy_reconciliation_findings', 'scope_key')) {
            Schema::table('accounting_legacy_reconciliation_findings', function (Blueprint $table): void {
                $table->string('scope_key', 120)->default('legacy-annual')->after('year');
                $table->index(['business_id', 'scope_key', 'is_current'], 'alrf_business_scope_current_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('accounting_legacy_reconciliation_findings')
            && Schema::hasColumn('accounting_legacy_reconciliation_findings', 'scope_key')) {
            Schema::table('accounting_legacy_reconciliation_findings', function (Blueprint $table): void {
                $table->dropIndex('alrf_business_scope_current_index');
                $table->dropColumn('scope_key');
            });
        }

        if (Schema::hasTable('accounting_legacy_reconciliation_audits')
            && Schema::hasColumn('accounting_legacy_reconciliation_audits', 'scope_key')) {
            Schema::table('accounting_legacy_reconciliation_audits', function (Blueprint $table): void {
                $table->dropIndex('alra_business_scope_completed_index');
                $table->dropColumn('scope_key');
            });
        }
    }
};
