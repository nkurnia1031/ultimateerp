<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bonus_ayak')) {
            Schema::table('bonus_ayak', function (Blueprint $table) {
                if (! Schema::hasColumn('bonus_ayak', 'project_id')) {
                    $table->unsignedInteger('project_id')->nullable()->after('business_id');
                    $table->index(['business_id', 'project_id', 'tanggal'], 'bonus_ayak_project_period_idx');
                }
            });

            Schema::table('bonus_ayak', function (Blueprint $table) {
                $hasForeign = DB::table('information_schema.KEY_COLUMN_USAGE')
                    ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                    ->where('TABLE_NAME', 'bonus_ayak')
                    ->where('CONSTRAINT_NAME', 'bonus_ayak_project_fk')
                    ->exists();
                if (Schema::hasColumn('bonus_ayak', 'project_id') && ! $hasForeign) {
                    $table->foreign('project_id', 'bonus_ayak_project_fk')
                        ->references('id')->on('pjt_projects')->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('bonus_ayak_trip')) {
            Schema::create('bonus_ayak_trip', function (Blueprint $table) {
                $table->unsignedBigInteger('bonus_ayak_id');
                $table->unsignedInteger('trip_id');
                $table->unsignedInteger('business_id');
                $table->timestamps();
                $table->primary(['bonus_ayak_id', 'trip_id']);
                $table->unique('trip_id', 'bonus_ayak_trip_unique_trip');
                $table->index(['business_id', 'bonus_ayak_id'], 'bonus_ayak_trip_business_idx');
                $table->foreign('bonus_ayak_id', 'bonus_ayak_trip_bonus_fk')
                    ->references('id')->on('bonus_ayak')->cascadeOnDelete();
                $table->foreign('trip_id', 'bonus_ayak_trip_trip_fk')
                    ->references('id')->on('heavy_equipment_trips')->restrictOnDelete();
                $table->foreign('business_id', 'bonus_ayak_trip_business_fk')
                    ->references('id')->on('business')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('heavy_equipment_driver_settlements')) {
            Schema::table('heavy_equipment_driver_settlements', function (Blueprint $table) {
                foreach ([
                    'total_bonus_ayak' => ['decimal', 22, 4],
                    'gross_payable' => ['decimal', 22, 4],
                    'total_kasbon' => ['decimal', 22, 4],
                    'net_payable' => ['decimal', 22, 4],
                ] as $column => $definition) {
                    if (! Schema::hasColumn('heavy_equipment_driver_settlements', $column)) {
                        $table->decimal($column, $definition[1], $definition[2])->default(0)->after('total_payable');
                    }
                }
            });
        }

        if (! Schema::hasTable('heavy_equipment_settlement_bonus_ayak')) {
            Schema::create('heavy_equipment_settlement_bonus_ayak', function (Blueprint $table) {
                $table->unsignedInteger('settlement_id');
                $table->unsignedBigInteger('bonus_ayak_id');
                $table->unsignedInteger('business_id');
                $table->timestamps();
                $table->primary(['settlement_id', 'bonus_ayak_id']);
                $table->unique('bonus_ayak_id', 'he_settlement_bonus_unique');
                $table->index(['business_id', 'settlement_id'], 'he_settlement_bonus_business_idx');
                $table->foreign('settlement_id', 'he_settlement_bonus_settlement_fk')
                    ->references('id')->on('heavy_equipment_driver_settlements')->cascadeOnDelete();
                $table->foreign('bonus_ayak_id', 'he_settlement_bonus_bonus_fk')
                    ->references('id')->on('bonus_ayak')->restrictOnDelete();
                $table->foreign('business_id', 'he_settlement_bonus_business_fk')
                    ->references('id')->on('business')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('heavy_equipment_settlement_kasbons')) {
            Schema::create('heavy_equipment_settlement_kasbons', function (Blueprint $table) {
                $table->unsignedInteger('settlement_id');
                // The legacy kasbon table uses a signed INT primary key.
                $table->integer('kasbon_id');
                $table->unsignedInteger('business_id');
                $table->timestamps();
                $table->primary(['settlement_id', 'kasbon_id']);
                $table->unique('kasbon_id', 'he_settlement_kasbon_unique');
                $table->index(['business_id', 'settlement_id'], 'he_settlement_kasbon_business_idx');
                $table->foreign('settlement_id', 'he_settlement_kasbon_settlement_fk')
                    ->references('id')->on('heavy_equipment_driver_settlements')->cascadeOnDelete();
                $table->foreign('kasbon_id', 'he_settlement_kasbon_kasbon_fk')
                    ->references('id')->on('bonus_ayak_kasbon')->restrictOnDelete();
                $table->foreign('business_id', 'he_settlement_kasbon_business_fk')
                    ->references('id')->on('business')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('heavy_equipment_settlement_kasbons');
        Schema::dropIfExists('heavy_equipment_settlement_bonus_ayak');
        Schema::dropIfExists('bonus_ayak_trip');

        if (Schema::hasTable('heavy_equipment_driver_settlements')) {
            Schema::table('heavy_equipment_driver_settlements', function (Blueprint $table) {
                foreach (['total_bonus_ayak', 'gross_payable', 'total_kasbon', 'net_payable'] as $column) {
                    if (Schema::hasColumn('heavy_equipment_driver_settlements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('bonus_ayak')) {
            Schema::table('bonus_ayak', function (Blueprint $table) {
                if (Schema::hasColumn('bonus_ayak', 'project_id')) {
                    $table->dropForeign('bonus_ayak_project_fk');
                    $table->dropIndex('bonus_ayak_project_period_idx');
                    $table->dropColumn('project_id');
                }
            });
        }
    }
};
