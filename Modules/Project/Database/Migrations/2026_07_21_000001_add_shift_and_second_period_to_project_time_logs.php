<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShiftAndSecondPeriodToProjectTimeLogs extends Migration
{
    public function up()
    {
        Schema::table('pjt_project_time_logs', function (Blueprint $table) {
            $table->string('shift', 20)->nullable()->after('user_id');
            $table->dateTime('second_start_datetime')->nullable()->after('end_datetime');
            $table->dateTime('second_end_datetime')->nullable()->after('second_start_datetime');
        });
    }

    public function down()
    {
        Schema::table('pjt_project_time_logs', function (Blueprint $table) {
            $table->dropColumn(['shift', 'second_start_datetime', 'second_end_datetime']);
        });
    }
}
