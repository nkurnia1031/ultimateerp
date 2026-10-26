<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_accounts_transactions', function (Blueprint $table) {
            $table->index(
                ['accounting_account_id', 'operation_date', 'id'],
                'aat_account_date_id_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('accounting_accounts_transactions', function (Blueprint $table) {
            $table->dropIndex('aat_account_date_id_index');
        });
    }
};
