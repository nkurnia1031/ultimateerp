<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transaction_sell_line_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('transaction_sell_line_id');
            $table->unsignedBigInteger('tag_id');
            $table->index('transaction_sell_line_id');
            $table->index('tag_id');
            $table->unique(['transaction_sell_line_id', 'tag_id'], 'transaction_sell_line_tags_line_id_tag_id_unique');
            $table->foreign('transaction_sell_line_id', 'transaction_sell_line_tags_line_id_foreign')
                ->references('id')
                ->on('transaction_sell_lines')
                ->onDelete('cascade');
            $table->foreign('tag_id', 'transaction_sell_line_tags_tag_id_foreign')
                ->references('id')
                ->on('tags')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('transaction_sell_line_tags');
    }
};
