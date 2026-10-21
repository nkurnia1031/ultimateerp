<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_journal_tax_account_mappings')) {
            return;
        }

        Schema::create('accounting_journal_tax_account_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('accounting_account_id');
            $table->unsignedInteger('tax_rate_id');
            $table->unsignedInteger('created_by');
            $table->timestamps();

            $table->unique(
                ['business_id', 'accounting_account_id'],
                'ajtam_business_account_unique'
            );
            $table->index(['business_id', 'tax_rate_id'], 'ajtam_business_tax_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_tax_account_mappings');
    }
};
