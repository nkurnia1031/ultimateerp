<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['bans', 'ban_pemasangan', 'ban_riwayats'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('id');
            });
            Schema::table($tableName, function (Blueprint $table) {
                $table->id()->first();
            });
        }
    }

    public function down(): void
    {
        // rollback id tidak bisa otomatis, karena tergantung tipe kolom lama
        // jadi hanya placeholder
    }
};
