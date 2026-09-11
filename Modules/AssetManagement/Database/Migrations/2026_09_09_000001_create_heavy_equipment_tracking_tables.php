<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('heavy_equipment_routes', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->string('name');
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('created_by')->unsigned()->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });

        Schema::create('heavy_equipment_rate_batches', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->string('file_name');
            $table->string('source_hash', 64)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('source_row_count')->default(0);
            $table->unsignedInteger('valid_row_count')->default(0);
            $table->unsignedInteger('unit_count')->default(0);
            $table->unsignedInteger('route_count')->default(0);
            $table->text('error_message')->nullable();
            $table->integer('uploaded_by')->unsigned()->nullable();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('heavy_equipment_route_rates', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->integer('route_id')->unsigned();
            $table->foreign('route_id')->references('id')->on('heavy_equipment_routes')->onDelete('cascade');
            $table->integer('batch_id')->unsigned();
            $table->foreign('batch_id')->references('id')->on('heavy_equipment_rate_batches')->onDelete('cascade');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('driver_rate_per_ton', 22, 4)->default(0);
            $table->decimal('travel_allowance', 22, 4)->default(0);
            $table->decimal('sale_rate_per_ton', 22, 4)->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_unit_text')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
            $table->index(['business_id', 'route_id', 'effective_from'], 'he_route_rate_lookup_idx');
        });

        Schema::create('heavy_equipment_rate_units', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('route_rate_id')->unsigned();
            $table->foreign('route_rate_id')->references('id')->on('heavy_equipment_route_rates')->onDelete('cascade');
            $table->integer('asset_id')->unsigned()->nullable();
            $table->foreign('asset_id')->references('id')->on('assets')->nullOnDelete();
            $table->string('unit_label');
            $table->timestamps();
            $table->unique(['route_rate_id', 'unit_label']);
            $table->index(['asset_id', 'unit_label']);
        });

        Schema::create('heavy_equipment_trips', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->string('trip_no');
            $table->date('trip_date');
            $table->integer('project_id')->unsigned()->nullable();
            $table->foreign('project_id')->references('id')->on('pjt_projects')->nullOnDelete();
            $table->integer('customer_id')->unsigned()->nullable();
            $table->foreign('customer_id')->references('id')->on('contacts')->nullOnDelete();
            $table->integer('route_id')->unsigned();
            $table->foreign('route_id')->references('id')->on('heavy_equipment_routes')->restrictOnDelete();
            $table->integer('asset_id')->unsigned();
            $table->foreign('asset_id')->references('id')->on('assets')->restrictOnDelete();
            $table->integer('driver_id')->unsigned();
            $table->foreign('driver_id')->references('id')->on('users')->restrictOnDelete();
            $table->integer('route_rate_id')->unsigned()->nullable();
            $table->foreign('route_rate_id')->references('id')->on('heavy_equipment_route_rates')->nullOnDelete();
            $table->decimal('actual_tons', 22, 4);
            $table->decimal('snapshot_driver_rate_per_ton', 22, 4)->default(0);
            $table->decimal('snapshot_travel_allowance', 22, 4)->default(0);
            $table->decimal('snapshot_sale_rate_per_ton', 22, 4)->nullable();
            $table->decimal('revenue_total', 22, 4)->default(0);
            $table->decimal('variable_driver_pay', 22, 4)->default(0);
            $table->decimal('driver_pay_total', 22, 4)->default(0);
            $table->decimal('gross_contribution', 22, 4)->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->string('invoice_status', 20)->default('unbilled')->index();
            $table->string('payroll_status', 20)->default('unsettled')->index();
            $table->text('rate_override_reason')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->integer('created_by')->unsigned()->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->integer('verified_by')->unsigned()->nullable();
            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'trip_no']);
            $table->index(['business_id', 'trip_date']);
        });

        Schema::create('heavy_equipment_trip_billings', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->integer('trip_id')->unsigned();
            $table->foreign('trip_id')->references('id')->on('heavy_equipment_trips')->onDelete('cascade');
            $table->integer('transaction_id')->unsigned();
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->string('billing_type', 30);
            $table->string('invoice_line_table', 60)->nullable();
            $table->unsignedInteger('invoice_line_id')->nullable();
            $table->decimal('quantity', 22, 4);
            $table->decimal('amount', 22, 4);
            $table->integer('created_by')->unsigned()->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('trip_id');
            $table->index(['business_id', 'transaction_id'], 'he_trip_billing_tx_idx');
        });

        Schema::create('heavy_equipment_driver_settlements', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->string('settlement_no');
            $table->integer('driver_id')->unsigned();
            $table->foreign('driver_id')->references('id')->on('users')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('trip_count')->default(0);
            $table->decimal('total_tons', 22, 4)->default(0);
            $table->decimal('total_variable_pay', 22, 4)->default(0);
            $table->decimal('total_travel_allowance', 22, 4)->default(0);
            $table->decimal('total_payable', 22, 4)->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->integer('payroll_transaction_id')->unsigned()->nullable();
            $table->foreign('payroll_transaction_id', 'he_settlement_payroll_fk')->references('id')->on('transactions')->nullOnDelete();
            $table->integer('created_by')->unsigned()->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->integer('approved_by')->unsigned()->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'settlement_no'], 'he_settlement_no_unique');
            $table->index(['business_id', 'driver_id', 'period_start', 'period_end'], 'he_settlement_period_idx');
        });

        Schema::create('heavy_equipment_settlement_trips', function (Blueprint $table) {
            $table->integer('settlement_id')->unsigned();
            $table->foreign('settlement_id')->references('id')->on('heavy_equipment_driver_settlements')->onDelete('cascade');
            $table->integer('trip_id')->unsigned();
            $table->foreign('trip_id')->references('id')->on('heavy_equipment_trips')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['settlement_id', 'trip_id']);
            $table->unique('trip_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('heavy_equipment_settlement_trips');
        Schema::dropIfExists('heavy_equipment_driver_settlements');
        Schema::dropIfExists('heavy_equipment_trip_billings');
        Schema::dropIfExists('heavy_equipment_trips');
        Schema::dropIfExists('heavy_equipment_rate_units');
        Schema::dropIfExists('heavy_equipment_route_rates');
        Schema::dropIfExists('heavy_equipment_rate_batches');
        Schema::dropIfExists('heavy_equipment_routes');
    }
};
