<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bonus_ayak')) {
            Schema::table('bonus_ayak', function (Blueprint $table) {
                if (! Schema::hasColumn('bonus_ayak', 'business_id')) {
                    $table->unsignedInteger('business_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('bonus_ayak', 'driver_id')) {
                    $table->unsignedInteger('driver_id')->nullable()->after('nama_operator');
                }
                if (! Schema::hasColumn('bonus_ayak', 'asset_id')) {
                    $table->unsignedInteger('asset_id')->nullable()->after('unit');
                }
                if (! Schema::hasColumn('bonus_ayak', 'created_by')) {
                    $table->unsignedInteger('created_by')->nullable()->after('bonus');
                }
            });

            $this->addForeignKeys('bonus_ayak');
        }

        if (! Schema::hasTable('bonus_ayak_kasbon')) {
            Schema::create('bonus_ayak_kasbon', function (Blueprint $table) {
                $table->increments('id');
                $table->date('tanggal');
                $table->string('nama_operator', 100);
                $table->decimal('jumlah_kasbon', 15, 2)->default(0);
                $table->unsignedInteger('business_id')->nullable();
                $table->unsignedInteger('driver_id')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'tanggal', 'driver_id']);
            });
        } else {
            Schema::table('bonus_ayak_kasbon', function (Blueprint $table) {
                if (! Schema::hasColumn('bonus_ayak_kasbon', 'business_id')) {
                    $table->unsignedInteger('business_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('bonus_ayak_kasbon', 'driver_id')) {
                    $table->unsignedInteger('driver_id')->nullable()->after('nama_operator');
                }
                if (! Schema::hasColumn('bonus_ayak_kasbon', 'created_by')) {
                    $table->unsignedInteger('created_by')->nullable()->after('jumlah_kasbon');
                }
            });
        }

        $this->backfillRelations();
        $this->addForeignKeys('bonus_ayak_kasbon');
    }

    public function down(): void
    {
        foreach (['bonus_ayak', 'bonus_ayak_kasbon'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropForeign($tableName.'_business_fk');
                $table->dropForeign($tableName.'_driver_fk');
                $table->dropForeign($tableName.'_creator_fk');
                if ($tableName === 'bonus_ayak') {
                    $table->dropForeign($tableName.'_asset_fk');
                }
                foreach (['business_id', 'driver_id', 'asset_id', 'created_by'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function addForeignKeys(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $table->foreign('business_id', $tableName.'_business_fk')
                ->references('id')->on('business')->nullOnDelete();
            $table->foreign('driver_id', $tableName.'_driver_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by', $tableName.'_creator_fk')
                ->references('id')->on('users')->nullOnDelete();

            if ($tableName === 'bonus_ayak') {
                $table->foreign('asset_id', $tableName.'_asset_fk')
                    ->references('id')->on('assets')->nullOnDelete();
            }
        });
    }

    private function backfillRelations(): void
    {
        $businessIds = DB::table('business')->pluck('id')->map(fn ($id) => (int) $id)->values();
        $fallbackBusinessId = $businessIds->count() === 1 ? $businessIds->first() : null;

        $users = DB::table('users')->select('id', 'business_id', 'surname', 'first_name', 'last_name', 'username')->get();
        $assets = DB::table('assets')->select('id', 'business_id', 'name', 'asset_code')->get();

        $userCandidates = function (string $value) use ($users): array {
            $needle = $this->normalize($value);
            if ($needle === '') {
                return [];
            }

            return $users->filter(function ($user) use ($needle) {
                $fullName = trim(implode(' ', array_filter([$user->surname, $user->first_name, $user->last_name])));
                return in_array($needle, [$this->normalize($fullName), $this->normalize($user->username)], true);
            })->values()->all();
        };

        $assetCandidates = function (string $value) use ($assets): array {
            $needle = $this->normalize($value);
            if ($needle === '') {
                return [];
            }

            return $assets->filter(function ($asset) use ($needle) {
                return in_array($needle, [$this->normalize($asset->name), $this->normalize($asset->asset_code)], true);
            })->values()->all();
        };

        if (Schema::hasTable('bonus_ayak')) {
            DB::table('bonus_ayak')->orderBy('id')->chunkById(250, function ($rows) use ($userCandidates, $assetCandidates, $fallbackBusinessId) {
                foreach ($rows as $row) {
                    $drivers = $userCandidates((string) $row->nama_operator);
                    $units = $assetCandidates((string) $row->unit);
                    $driver = count($drivers) === 1 ? $drivers[0] : null;
                    $asset = count($units) === 1 ? $units[0] : null;
                    $businessId = $this->resolvedBusinessId($driver, $asset, $fallbackBusinessId);

                    $updates = [];
                    if ($driver && empty($row->driver_id)) {
                        $updates['driver_id'] = $driver->id;
                    }
                    if ($asset && empty($row->asset_id)) {
                        $updates['asset_id'] = $asset->id;
                    }
                    if ($businessId && empty($row->business_id)) {
                        $updates['business_id'] = $businessId;
                    }
                    if ($updates !== []) {
                        DB::table('bonus_ayak')->where('id', $row->id)->update($updates);
                    }
                }
            });
        }

        if (Schema::hasTable('bonus_ayak_kasbon')) {
            DB::table('bonus_ayak_kasbon')->orderBy('id')->chunkById(250, function ($rows) use ($userCandidates, $fallbackBusinessId) {
                foreach ($rows as $row) {
                    $drivers = $userCandidates((string) $row->nama_operator);
                    $driver = count($drivers) === 1 ? $drivers[0] : null;
                    $businessId = $driver?->business_id ?: $fallbackBusinessId;
                    $updates = [];
                    if ($driver && empty($row->driver_id)) {
                        $updates['driver_id'] = $driver->id;
                    }
                    if ($businessId && empty($row->business_id)) {
                        $updates['business_id'] = $businessId;
                    }
                    if ($updates !== []) {
                        DB::table('bonus_ayak_kasbon')->where('id', $row->id)->update($updates);
                    }
                }
            });
        }
    }

    private function resolvedBusinessId(?object $driver, ?object $asset, ?int $fallback): ?int
    {
        $ids = collect([$driver?->business_id, $asset?->business_id])->filter()->unique()->values();
        if ($ids->count() === 1) {
            return (int) $ids->first();
        }

        return $ids->isEmpty() ? $fallback : null;
    }

    private function normalize(?string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value ?? '')));
    }
};
