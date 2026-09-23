<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('heavy_equipment_vendor_loadings', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->string('loading_no');
            $table->string('ticket_no')->nullable();
            $table->date('loading_date');
            $table->date('load_date')->nullable();
            $table->date('unload_date')->nullable();
            $table->integer('supplier_id')->unsigned()->nullable();
            $table->foreign('supplier_id')->references('id')->on('contacts')->nullOnDelete();
            $table->string('supplier_name_snapshot')->nullable();
            $table->string('vendor_driver_name')->nullable();
            $table->string('vendor_unit_name')->nullable();
            $table->string('vendor_driver_code')->nullable();
            $table->string('vendor_unit_code')->nullable();
            $table->integer('route_id')->unsigned()->nullable();
            $table->foreign('route_id')->references('id')->on('heavy_equipment_routes')->nullOnDelete();
            $table->string('route_name_snapshot')->nullable();
            $table->integer('project_id')->unsigned()->nullable();
            $table->foreign('project_id')->references('id')->on('pjt_projects')->nullOnDelete();
            $table->integer('customer_id')->unsigned()->nullable();
            $table->foreign('customer_id')->references('id')->on('contacts')->nullOnDelete();
            $table->decimal('gross_tons', 22, 4)->nullable();
            $table->decimal('tare_tons', 22, 4)->nullable();
            $table->decimal('net_tons', 22, 4)->nullable();
            $table->decimal('received_tons', 22, 4)->nullable();
            $table->decimal('billable_tons', 22, 4)->nullable();
            $table->decimal('vendor_rate_per_ton', 22, 4)->nullable();
            $table->decimal('vendor_base_amount', 22, 4)->nullable();
            $table->decimal('claim_adjustment', 22, 4)->default(0);
            $table->decimal('other_deduction', 22, 4)->default(0);
            $table->decimal('vendor_total_amount', 22, 4)->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->integer('expense_transaction_id')->unsigned()->nullable();
            $table->foreign('expense_transaction_id', 'he_vendor_loading_expense_fk')->references('id')->on('transactions')->nullOnDelete();
            $table->string('expense_status', 20)->default('not_linked')->index();
            $table->string('source_workbook')->nullable();
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_fingerprint', 64)->nullable();
            $table->text('notes')->nullable();
            $table->integer('created_by')->unsigned()->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->integer('verified_by')->unsigned()->nullable();
            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'loading_no'], 'he_vendor_loading_no_unique');
            $table->index(['business_id', 'loading_date'], 'he_vendor_loading_date_idx');
            $table->index(['business_id', 'source_fingerprint'], 'he_vendor_loading_source_idx');
            $table->index(['business_id', 'supplier_id'], 'he_vendor_loading_supplier_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('heavy_equipment_vendor_loadings');
    }
};
