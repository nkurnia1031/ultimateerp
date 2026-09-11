<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('change_log_reads')) {
            return;
        }

        Schema::create('change_log_reads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('business_id');
            // Release IDs come from the curated config catalog, not from a user-supplied FK.
            $table->string('release_id', 100);
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(
                ['user_id', 'business_id', 'release_id'],
                'change_log_reads_user_business_release_unique'
            );
            $table->index(['business_id', 'release_id'], 'change_log_reads_business_release_idx');
            $table->foreign('user_id', 'change_log_reads_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('business_id', 'change_log_reads_business_fk')
                ->references('id')->on('business')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_log_reads');
    }
};
