<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('solar_equipment_usages')) {
            Schema::create('solar_equipment_usages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('business_id')->index();
                $table->date('usage_date')->index();
                $table->unsignedInteger('asset_id')->nullable()->index();
                $table->string('equipment_name', 255);
                $table->decimal('quantity_gln', 20, 4)->default(0);
                $table->string('source', 20)->default('manual');
                $table->string('source_sheet', 120)->nullable();
                $table->unsignedInteger('source_row')->nullable();
                $table->string('source_hash', 64)->nullable();
                $table->string('source_key', 64)->nullable();
                $table->uuid('import_batch_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['business_id', 'source_key'], 'solar_usage_business_source_key_unique');
                $table->index(['business_id', 'usage_date', 'asset_id'], 'solar_usage_business_date_asset_index');
            });
        }

        if (Schema::hasTable('solar_import_preview_rows')
            && ! Schema::hasColumn('solar_import_preview_rows', 'source_sheet')) {
            Schema::table('solar_import_preview_rows', function (Blueprint $table) {
                $table->string('source_sheet', 120)->nullable()->after('ledger');
            });
        }

        if (Schema::hasTable('solar_import_batches')
            && ! Schema::hasColumn('solar_import_batches', 'source_sheets')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->json('source_sheets')->nullable()->after('counts');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('solar_import_preview_rows')
            && Schema::hasColumn('solar_import_preview_rows', 'source_sheet')) {
            Schema::table('solar_import_preview_rows', function (Blueprint $table) {
                $table->dropColumn('source_sheet');
            });
        }

        if (Schema::hasTable('solar_import_batches')
            && Schema::hasColumn('solar_import_batches', 'source_sheets')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->dropColumn('source_sheets');
            });
        }

        Schema::dropIfExists('solar_equipment_usages');
    }
};
