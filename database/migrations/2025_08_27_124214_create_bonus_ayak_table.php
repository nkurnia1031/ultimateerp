<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('bonus_ayak', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->string('nama_operator');
            $table->string('unit');
            $table->unsignedInteger('trip');           // total trip harian
            $table->unsignedBigInteger('bonus');       // dalam rupiah
            $table->timestamps();
            $table->index(['tanggal', 'nama_operator', 'unit']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('bonus_ayak');
    }
};
