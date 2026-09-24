<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('heavy_equipment_vendor_loading_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by');
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->string('preview_path')->nullable();
            $table->string('status', 32)->default('queued_preview');
            $table->string('stage', 64)->default('queued');
            $table->unsignedInteger('total_sheets')->default(0);
            $table->unsignedInteger('processed_sheets')->default(0);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('ready_rows')->default(0);
            $table->unsignedInteger('issue_rows')->default(0);
            $table->unsignedInteger('missing_date_rows')->default(0);
            $table->unsignedInteger('imported_documents')->default(0);
            $table->unsignedInteger('imported_lines')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->longText('overrides_json')->nullable();
            $table->longText('permissions_json')->nullable();
            $table->longText('results_json')->nullable();
            $table->longText('errors_json')->nullable();
            $table->text('last_message')->nullable();
            $table->longText('error_message')->nullable();
            $table->dateTime('last_heartbeat_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_by', 'created_at'], 'hvl_import_owner_created_idx');
            $table->index(['business_id', 'status'], 'hvl_import_business_status_idx');
            $table->index('expires_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('heavy_equipment_vendor_loading_import_batches');
    }
};
