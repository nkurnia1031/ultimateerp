<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProjectTaskIdToInvoiceLines extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('pjt_invoice_lines', 'project_task_id')) {
            Schema::table('pjt_invoice_lines', function (Blueprint $table) {
                $table->unsignedInteger('project_task_id')->nullable()->after('transaction_id');
                $table->foreign('project_task_id')
                    ->references('id')
                    ->on('pjt_project_tasks')
                    ->onDelete('set null');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('pjt_invoice_lines', 'project_task_id')) {
            Schema::table('pjt_invoice_lines', function (Blueprint $table) {
                $table->dropForeign(['project_task_id']);
                $table->dropColumn('project_task_id');
            });
        }
    }
}
