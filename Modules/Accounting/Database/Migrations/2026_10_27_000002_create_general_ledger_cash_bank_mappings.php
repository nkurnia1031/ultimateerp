<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('accounting_general_ledger_cash_bank_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('accounting_account_id');
            $table->string('classification', 20);
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'accounting_account_id'], 'gl_cash_bank_account_unique');
            $table->index(['business_id', 'classification'], 'gl_cash_bank_classification_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('accounting_general_ledger_cash_bank_mappings');
    }
};
