<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // solar_mess
        Schema::table('solar_mess', function (Blueprint $table) {
            // hapus id lama
            $table->dropColumn('id');
        });
        Schema::table('solar_mess', function (Blueprint $table) {
            // buat ulang id sebagai auto increment primary key
            $table->id()->first();
            // tambahkan timestamps
            $table->timestamps();
        });

        // solar_pool
        Schema::table('solar_pool', function (Blueprint $table) {
            $table->dropColumn('id');
        });
        Schema::table('solar_pool', function (Blueprint $table) {
            $table->id()->first();
            $table->timestamps();
        });

        // solar_stock
        Schema::table('solar_stock', function (Blueprint $table) {
            $table->dropColumn('id');
        });
        Schema::table('solar_stock', function (Blueprint $table) {
            $table->id()->first();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('solar_mess', function (Blueprint $table) {
            $table->dropTimestamps();
            // id tidak bisa otomatis di-rollback, harus manual sesuai struktur awal
        });

        Schema::table('solar_pool', function (Blueprint $table) {
            $table->dropTimestamps();
        });

        Schema::table('solar_stock', function (Blueprint $table) {
            $table->dropTimestamps();
        });
    }
};
