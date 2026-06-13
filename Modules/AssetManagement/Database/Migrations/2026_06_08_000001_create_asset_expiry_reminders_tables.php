<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('asset_expiry_reminders', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('asset_id')->index();
            $table->enum('reminder_type', ['stnk', 'tbpkp']);
            $table->date('expiry_date');
            $table->date('email_start_date');
            $table->date('daily_start_date');
            $table->date('daily_end_date');
            $table->unsignedInteger('calendar_owner_id');
            $table->date('last_email_sent_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['asset_id', 'reminder_type'], 'asset_expiry_unique');
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');
        });

        Schema::create('asset_expiry_reminder_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('asset_expiry_reminder_id');
            $table->unsignedInteger('reminder_id');
            $table->date('reminder_date');
            $table->timestamps();

            $table->unique(['asset_expiry_reminder_id', 'reminder_date'], 'asset_expiry_item_unique');
            $table->foreign('asset_expiry_reminder_id', 'asset_expiry_items_parent_fk')
                ->references('id')
                ->on('asset_expiry_reminders')
                ->onDelete('cascade');
            $table->foreign('reminder_id', 'asset_expiry_items_reminder_fk')
                ->references('id')
                ->on('essentials_reminders')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_expiry_reminder_items');
        Schema::dropIfExists('asset_expiry_reminders');
    }
};
