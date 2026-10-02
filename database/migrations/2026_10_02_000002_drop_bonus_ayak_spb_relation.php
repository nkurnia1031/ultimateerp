<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bonus_ayak_trip');
    }

    public function down(): void
    {
        if (Schema::hasTable('bonus_ayak_trip')
            || ! Schema::hasTable('bonus_ayak')
            || ! Schema::hasTable('heavy_equipment_trips')
            || ! Schema::hasTable('business')) {
            return;
        }

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
};
