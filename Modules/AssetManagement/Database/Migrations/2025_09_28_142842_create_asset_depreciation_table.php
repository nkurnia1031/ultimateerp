<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_depreciation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('transaction_id');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE asset_depreciation MODIFY transaction_id INT(10) UNSIGNED");
        DB::statement("ALTER TABLE asset_depreciation MODIFY asset_id INT(10) UNSIGNED");
        Schema::table('asset_depreciation', function (Blueprint $table) {
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation');
    }
};
