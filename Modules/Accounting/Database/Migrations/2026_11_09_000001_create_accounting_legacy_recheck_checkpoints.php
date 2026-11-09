<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_legacy_recheck_checkpoints')) {
            return;
        }

        Schema::create('accounting_legacy_recheck_checkpoints', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_import_run_id');
            $table->unsignedInteger('business_id');
            $table->string('phase', 40);
            $table->string('cursor', 500)->nullable();
            $table->unsignedBigInteger('processed')->default(0);
            $table->unsignedBigInteger('total')->nullable();
            $table->longText('counts')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['legacy_import_run_id', 'business_id', 'phase'], 'alrc_run_business_phase_unique');
            $table->index(['business_id', 'phase'], 'alrc_business_phase_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_legacy_recheck_checkpoints');
    }
};
