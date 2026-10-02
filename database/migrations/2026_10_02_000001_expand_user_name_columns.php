<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY COLUMN surname TEXT NOT NULL, MODIFY COLUMN first_name TEXT NOT NULL, MODIFY COLUMN last_name TEXT NULL');
    }

    public function down(): void
    {
        $hasLongNames = DB::table('users')
            ->whereRaw('CHAR_LENGTH(surname) > 10')
            ->orWhereRaw('CHAR_LENGTH(first_name) > 255')
            ->orWhereRaw('CHAR_LENGTH(last_name) > 255')
            ->exists();

        if ($hasLongNames) {
            throw new RuntimeException('Cannot restore the previous user name column lengths while longer names exist.');
        }

        DB::statement('ALTER TABLE users MODIFY COLUMN surname CHAR(10) NOT NULL, MODIFY COLUMN first_name VARCHAR(255) NOT NULL, MODIFY COLUMN last_name VARCHAR(255) NULL');
    }
};
