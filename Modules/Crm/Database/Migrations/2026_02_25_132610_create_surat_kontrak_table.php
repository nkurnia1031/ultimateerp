<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surat_kontrak', function (Blueprint $table) {
            $table->id();

            $table->string('pekerjaan'); // nama pekerjaan / project

            $table->unsignedBigInteger('contact_id'); // relasi ke contacts.id
            $table->string('jabatan_pihak_2')->nullable();
            $table->unsignedBigInteger('business_id'); // relasi ke contacts.id

            $table->string('kapal_mv')->nullable(); // nama kapal

            $table->string('lokasi_muat')->nullable();
            $table->string('lokasi_bongkar')->nullable();
            $table->string('jangka_waktu')->nullable();

            $table->date('mulai_kerja')->nullable();
            $table->date('selesai')->nullable();
            $table->text('notes')->nullable();
            $table->text('atribut_tambahan')->nullable();

            $table->string('nomor')->unique(); // nomor surat kontrak

            $table->timestamps();
        });
        DB::statement("ALTER TABLE surat_kontrak MODIFY contact_id INT(10) UNSIGNED");
        DB::statement("ALTER TABLE surat_kontrak MODIFY business_id INT(10) UNSIGNED");

        Schema::table('surat_kontrak', function (Blueprint $table) {
            // Foreign Key
            $table->foreign('contact_id')
                ->references('id')
                ->on('contacts')
                ->onDelete('cascade');
            $table->foreign('business_id')
                ->references('id')
                ->on('business')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surat_kontrak');
    }
};
