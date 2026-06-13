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
        Schema::create('reminder_email_digests', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->index();
            $table->date('send_date');
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->enum('status', ['processing', 'sent', 'failed'])->default('processing');
            $table->unsignedInteger('item_count')->default(0);
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->longText('error_message')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'send_date'], 'reminder_email_digest_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_email_digests');
    }
};
