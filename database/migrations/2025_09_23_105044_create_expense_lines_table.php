<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_lines', function (Blueprint $table) {
            $table->id();

            // sementara pakai unsignedInteger dulu
            $table->unsignedInteger('transaction_id');

            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('document')->nullable();

            $table->timestamps();
        });

        // ubah kolom transaction_id jadi int(10) unsigned pakai raw SQL
        DB::statement("ALTER TABLE expense_lines MODIFY transaction_id INT(10) UNSIGNED");

        // baru tambahkan foreign key constraint
        Schema::table('expense_lines', function (Blueprint $table) {
            $table->foreign('transaction_id')
                ->references('id')->on('transactions')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_lines');
    }
};
