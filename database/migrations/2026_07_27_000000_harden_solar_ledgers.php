<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['solar_stock', 'solar_pool', 'solar_mess'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'business_id')) {
                    $table->unsignedBigInteger('business_id')->nullable()->after('id')->index();
                }
                if (! Schema::hasColumn($tableName, 'source')) {
                    $table->string('source', 20)->default('manual')->after('business_id');
                }
                if (! Schema::hasColumn($tableName, 'source_sheet')) {
                    $table->string('source_sheet', 30)->nullable()->after('source');
                }
                if (! Schema::hasColumn($tableName, 'source_row')) {
                    $table->unsignedInteger('source_row')->nullable()->after('source_sheet');
                }
                if (! Schema::hasColumn($tableName, 'source_hash')) {
                    $table->string('source_hash', 64)->nullable()->after('source_row');
                }
            });

            if (Schema::hasColumn($tableName, 'business_id') && Schema::hasColumn($tableName, 'source_hash')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->unique(
                        ['business_id', 'source_hash'],
                        $tableName.'_business_source_hash_unique'
                    );
                });
            }
        }

        if (Schema::hasTable('solar_mess') && ! Schema::hasColumn('solar_mess', 'nama_kapal')) {
            Schema::table('solar_mess', function (Blueprint $table) {
                $table->string('nama_kapal')->nullable()->after('keterangan');
            });
        }

        if (! Schema::hasTable('solar_opening_balances')) {
            Schema::create('solar_opening_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->string('ledger', 20);
                $table->date('effective_date');
                $table->decimal('actual_balance', 20, 4)->nullable();
                $table->decimal('meter_balance', 20, 4)->nullable();
                $table->decimal('quantity_balance', 20, 4)->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'ledger'], 'solar_opening_business_ledger_unique');
                $table->index(['business_id', 'effective_date'], 'solar_opening_business_date_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('solar_opening_balances');

        if (Schema::hasTable('solar_mess') && Schema::hasColumn('solar_mess', 'nama_kapal')) {
            Schema::table('solar_mess', function (Blueprint $table) {
                $table->dropColumn('nama_kapal');
            });
        }

        foreach (['solar_stock', 'solar_pool', 'solar_mess'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'source_hash')) {
                    $table->dropUnique($tableName.'_business_source_hash_unique');
                }
            });

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $columns = array_values(array_filter(
                    ['business_id', 'source', 'source_sheet', 'source_row', 'source_hash'],
                    fn ($column) => Schema::hasColumn($tableName, $column)
                ));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
