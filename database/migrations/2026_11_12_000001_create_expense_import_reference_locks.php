<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expense_import_reference_locks')) {
            return;
        }

        Schema::create('expense_import_reference_locks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->char('reference_hash', 64);
            $table->timestamps();
            $table->unique(['business_id', 'reference_hash'], 'expense_import_reference_locks_business_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_import_reference_locks');
    }
};
