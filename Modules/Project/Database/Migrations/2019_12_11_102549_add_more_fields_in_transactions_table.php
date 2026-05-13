<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMoreFieldsInTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedInteger('pjt_project_id')
                    ->nullable()
                    ->after('pay_term_type');

            $table->foreign('pjt_project_id')
                ->references('id')->on('pjt_projects')
                ->onDelete('cascade');

            $table->string('pjt_title')
                ->nullable()
                ->after('pjt_project_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'pjt_project_id')) {
                $table->dropForeign(['pjt_project_id']);
                $table->dropColumn('pjt_project_id');
            }

            if (Schema::hasColumn('transactions', 'pjt_title')) {
                $table->dropColumn('pjt_title');
            }
        });
    }
}
