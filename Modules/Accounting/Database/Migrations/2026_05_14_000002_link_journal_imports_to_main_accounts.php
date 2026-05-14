<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'journal_import_id')) {
                $table->unsignedBigInteger('journal_import_id')->nullable()->after('id');
                $table->index('journal_import_id');
            }
        });

        Schema::table('account_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('account_transactions', 'journal_import_id')) {
                $table->unsignedBigInteger('journal_import_id')->nullable()->after('id');
                $table->index('journal_import_id');
            }
        });

        Schema::table('accounting_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_accounts', 'account_id')) {
                $table->unsignedInteger('account_id')->nullable()->after('id');
                $table->index('account_id');
            }
        });
    }

    public function down()
    {
        Schema::table('accounting_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounting_accounts', 'account_id')) {
                $table->dropIndex(['account_id']);
                $table->dropColumn('account_id');
            }
        });

        Schema::table('account_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('account_transactions', 'journal_import_id')) {
                $table->dropIndex(['journal_import_id']);
                $table->dropColumn('journal_import_id');
            }
        });

        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'journal_import_id')) {
                $table->dropIndex(['journal_import_id']);
                $table->dropColumn('journal_import_id');
            }
        });
    }
};
