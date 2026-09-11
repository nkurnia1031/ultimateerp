<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('heavy_equipment_trips')) {
            return;
        }

        Schema::table('heavy_equipment_trips', function (Blueprint $table) {
            $columns = [
                'ticket_no' => fn () => $table->string('ticket_no')->nullable()->after('trip_no'),
                'load_date' => fn () => $table->date('load_date')->nullable()->after('trip_date'),
                'unload_date' => fn () => $table->date('unload_date')->nullable()->after('load_date'),
                'gross_tons' => fn () => $table->decimal('gross_tons', 22, 4)->nullable()->after('actual_tons'),
                'tare_tons' => fn () => $table->decimal('tare_tons', 22, 4)->nullable()->after('gross_tons'),
                'received_tons' => fn () => $table->decimal('received_tons', 22, 4)->nullable()->after('tare_tons'),
                'billable_tons' => fn () => $table->decimal('billable_tons', 22, 4)->nullable()->after('received_tons'),
                'variance_kg' => fn () => $table->decimal('variance_kg', 22, 4)->default(0)->after('billable_tons'),
                'tolerance_kg' => fn () => $table->decimal('tolerance_kg', 22, 4)->default(0)->after('variance_kg'),
                'claim_rate_per_kg' => fn () => $table->decimal('claim_rate_per_kg', 22, 4)->default(0)->after('tolerance_kg'),
                'base_revenue_total' => fn () => $table->decimal('base_revenue_total', 22, 4)->default(0)->after('revenue_total'),
                'claim_adjustment_total' => fn () => $table->decimal('claim_adjustment_total', 22, 4)->default(0)->after('base_revenue_total'),
                'other_deduction_total' => fn () => $table->decimal('other_deduction_total', 22, 4)->default(0)->after('claim_adjustment_total'),
                'final_revenue_total' => fn () => $table->decimal('final_revenue_total', 22, 4)->default(0)->after('other_deduction_total'),
                'source_workbook' => fn () => $table->string('source_workbook')->nullable()->after('notes'),
                'source_sheet' => fn () => $table->string('source_sheet')->nullable()->after('source_workbook'),
                'source_row' => fn () => $table->unsignedInteger('source_row')->nullable()->after('source_sheet'),
                'source_fingerprint' => fn () => $table->char('source_fingerprint', 64)->nullable()->after('source_row'),
            ];

            foreach ($columns as $name => $definition) {
                if (! Schema::hasColumn('heavy_equipment_trips', $name)) {
                    $definition();
                }
            }
            $table->index(['business_id', 'ticket_no'], 'he_trip_ticket_lookup_idx');
            $table->index(['business_id', 'source_fingerprint'], 'he_trip_source_lookup_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('heavy_equipment_trips')) {
            return;
        }

        Schema::table('heavy_equipment_trips', function (Blueprint $table) {
            $table->dropIndex('he_trip_ticket_lookup_idx');
            $table->dropIndex('he_trip_source_lookup_idx');
            foreach ([
                'ticket_no', 'load_date', 'unload_date', 'gross_tons', 'tare_tons', 'received_tons', 'billable_tons',
                'variance_kg', 'tolerance_kg', 'claim_rate_per_kg', 'base_revenue_total', 'claim_adjustment_total',
                'other_deduction_total', 'final_revenue_total', 'source_workbook', 'source_sheet', 'source_row', 'source_fingerprint',
            ] as $column) {
                if (Schema::hasColumn('heavy_equipment_trips', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
