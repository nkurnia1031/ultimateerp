<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // bans
        Schema::table('bans', function (Blueprint $table) {
            $table->dropColumn('id');
        });
        Schema::table('bans', function (Blueprint $table) {
            $table->id()->first();
        });

        // ban_pemasangan
        Schema::table('ban_pemasangan', function (Blueprint $table) {
            $table->dropColumn('id');
        });
        Schema::table('ban_pemasangan', function (Blueprint $table) {
            $table->id()->first();
        });

        // ban_riwayats
        Schema::table('ban_riwayats', function (Blueprint $table) {
            $table->dropColumn('id');
        });
        Schema::table('ban_riwayats', function (Blueprint $table) {
            $table->id()->first();
        });
    }

    public function down(): void
    {
        // rollback id tidak bisa otomatis, karena tergantung tipe kolom lama
        // jadi hanya placeholder
        Schema::table('bans', function (Blueprint $table) {
            // $table->dropColumn('id'); // jika mau rollback manual
        });

        Schema::table('ban_pemasangan', function (Blueprint $table) {
            //
        });

        Schema::table('ban_riwayats', function (Blueprint $table) {
            //
        });
    }
};
