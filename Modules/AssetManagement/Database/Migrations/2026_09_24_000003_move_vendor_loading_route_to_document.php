<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('heavy_equipment_vendor_loading_documents')) {
            return;
        }

        if (! Schema::hasColumn('heavy_equipment_vendor_loading_documents', 'route_id')) {
            Schema::table('heavy_equipment_vendor_loading_documents', function (Blueprint $table) {
                $table->unsignedInteger('route_id')->nullable()->after('destination');
                $table->foreign('route_id', 'he_vendor_doc_route_fk')
                    ->references('id')->on('heavy_equipment_routes')->nullOnDelete();
                $table->string('route_name_snapshot')->nullable()->after('route_id');
            });
        }

        if (! Schema::hasTable('heavy_equipment_vendor_loadings')) {
            return;
        }

        DB::table('heavy_equipment_vendor_loading_documents')
            ->orderBy('id')
            ->chunkById(100, function ($documents) {
                foreach ($documents as $document) {
                    $lines = DB::table('heavy_equipment_vendor_loadings')
                        ->where('loading_document_id', $document->id)
                        ->get(['route_id', 'route_name_snapshot']);

                    if ($lines->isEmpty()) {
                        continue;
                    }

                    $signatures = $lines->map(function ($line) {
                        if (! empty($line->route_id)) {
                            return 'id:'.(int) $line->route_id;
                        }

                        $label = preg_replace('/\s+/u', ' ', trim((string) $line->route_name_snapshot));
                        return $label === '' ? 'none' : 'label:'.mb_strtolower($label, 'UTF-8');
                    })->unique()->values();

                    if ($signatures->count() !== 1) {
                        DB::table('heavy_equipment_vendor_loading_documents')
                            ->where('id', $document->id)
                            ->update(['metadata_conflict' => true]);
                        continue;
                    }

                    $routeId = $lines->first()->route_id ? (int) $lines->first()->route_id : null;
                    $routeLabel = $lines->pluck('route_name_snapshot')
                        ->map(fn ($value) => trim((string) $value))
                        ->first(fn ($value) => $value !== '');

                    if ($routeId) {
                        $route = DB::table('heavy_equipment_routes')
                            ->where('business_id', $document->business_id)
                            ->where('id', $routeId)
                            ->first(['name']);
                        if ($route) {
                            $routeLabel = $route->name;
                        } else {
                            $routeId = null;
                        }
                    }

                    DB::table('heavy_equipment_vendor_loading_documents')
                        ->where('id', $document->id)
                        ->update([
                            'route_id' => $routeId,
                            'route_name_snapshot' => $routeLabel,
                        ]);
                }
            });
    }

    public function down()
    {
        if (Schema::hasTable('heavy_equipment_vendor_loading_documents') && Schema::hasColumn('heavy_equipment_vendor_loading_documents', 'route_id')) {
            Schema::table('heavy_equipment_vendor_loading_documents', function (Blueprint $table) {
                $table->dropForeign('he_vendor_doc_route_fk');
                $table->dropColumn(['route_id', 'route_name_snapshot']);
            });
        }
    }
};
