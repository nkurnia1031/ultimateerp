<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSuratKontrakTemplatesTable extends Migration
{
    public function up()
    {
        Schema::create('surat_kontrak_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name', 190);
            $table->boolean('is_active')->default(1);
            $table->boolean('is_default')->default(0);
            $table->string('schema_version', 20)->default('1.0');
            $table->string('letterhead_mode', 20)->default('components');
            $table->longText('components');
            $table->longText('body_html')->nullable();
            $table->string('logo_2')->nullable();
            $table->string('letterhead_image')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'is_active', 'is_default'], 'skt_business_status_idx');
            $table->foreign('business_id', 'skt_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('created_by', 'skt_created_by_fk')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by', 'skt_updated_by_fk')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('surat_kontrak_templates');
    }
}
