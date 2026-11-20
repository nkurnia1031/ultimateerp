<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AssetManagement\Entities\HeavyEquipmentTrip;
use Modules\AssetManagement\Services\HeavyEquipmentTripService;
use Tests\TestCase;

class HeavyEquipmentSpbImportPreviewEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated SPB import preview tests.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('heavy_equipment_routes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('assets', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('asset_code')->nullable();
            $table->string('name');
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('surname')->nullable();
            $table->string('username')->nullable();
            $table->string('user_type')->nullable();
            $table->string('status')->nullable();
            $table->softDeletes();
        });
        Schema::create('heavy_equipment_trips', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('ticket_no')->nullable();
            $table->string('source_fingerprint')->nullable();
        });

        DB::table('heavy_equipment_routes')->insert(['id' => 1, 'business_id' => 7, 'name' => 'Rute A']);
        DB::table('assets')->insert([
            ['id' => 1, 'business_id' => 7, 'asset_code' => 'UNIT-OLD', 'name' => 'Unit Lama'],
            ['id' => 2, 'business_id' => 7, 'asset_code' => 'UNIT-NEW', 'name' => 'Unit Resmi'],
            ['id' => 3, 'business_id' => 8, 'asset_code' => 'UNIT-PRIVATE', 'name' => 'Unit Business Lain'],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'business_id' => 7, 'first_name' => 'Sopir Lama', 'surname' => '', 'user_type' => 'user'],
            ['id' => 2, 'business_id' => 7, 'first_name' => 'Nama Resmi', 'surname' => '', 'user_type' => 'user'],
            ['id' => 3, 'business_id' => 8, 'first_name' => 'Private', 'surname' => '', 'user_type' => 'user'],
        ]);
    }

    public function test_editing_unit_and_driver_links_updates_rows_with_the_same_original_labels_and_import_uses_the_corrections(): void
    {
        $token = '00000000-0000-4000-8000-000000000001';
        Cache::put($this->cacheKey($token), [
            'business_id' => 7,
            'user_id' => 999,
            'preview' => ['rows' => $this->rowsWithRepeatedLabels()],
        ], now()->addMinutes(30));

        $response = $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession())
            ->post(route('asset.fleet.trips.import.rows.update', ['rowIndex' => 0]), $this->editInput($token));

        $response->assertOk()->assertJsonPath('summary.ready_rows', 3)->assertJsonPath('updated_rows.0.row.asset_id', 2);
        $payload = Cache::get($this->cacheKey($token));
        $rows = $payload['preview']['rows'];
        $this->assertSame(2, $rows[0]['asset_id']);
        $this->assertSame(2, $rows[1]['asset_id']);
        $this->assertSame('Unit Resmi', $rows[1]['asset_label']);
        $this->assertSame(2, $rows[0]['driver_id']);
        $this->assertSame(2, $rows[1]['driver_id']);
        $this->assertSame('Nama Resmi', $rows[1]['driver_label']);
        $this->assertSame(1, $rows[2]['asset_id']);
        $this->assertSame(1, $rows[2]['driver_id']);

        $tripService = \Mockery::mock(HeavyEquipmentTripService::class);
        $tripService->shouldReceive('create')->times(3)->withArgs(function (array $row, int $businessId, int $userId) {
            return $businessId === 7
                && $userId === 999
                && ($row['asset_id'] ?? null) === (($row['ticket_no'] ?? null) === 'TK-C' ? 1 : 2)
                && ($row['driver_id'] ?? null) === (($row['ticket_no'] ?? null) === 'TK-C' ? 1 : 2);
        })->andReturn(new HeavyEquipmentTrip());
        $this->app->instance(HeavyEquipmentTripService::class, $tripService);

        $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession())
            ->post(route('asset.fleet.trips.import.confirm'), ['token' => $token])
            ->assertRedirect(route('asset.fleet.trips.index'));
    }

    public function test_preview_edit_rejects_a_unit_from_another_business(): void
    {
        $token = '00000000-0000-4000-8000-000000000002';
        Cache::put($this->cacheKey($token), [
            'business_id' => 7,
            'user_id' => 999,
            'preview' => ['rows' => $this->rowsWithRepeatedLabels()],
        ], now()->addMinutes(30));

        $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession())
            ->post(route('asset.fleet.trips.import.rows.update', ['rowIndex' => 0]), array_merge($this->editInput($token), [
                'asset_id' => 3,
                'asset_label' => 'Unit Business Lain',
            ]))
            ->assertUnprocessable();

        $payload = Cache::get($this->cacheKey($token));
        $this->assertSame(1, $payload['preview']['rows'][0]['asset_id']);
        $this->assertSame(1, $payload['preview']['rows'][1]['asset_id']);
    }

    private function rowsWithRepeatedLabels(): array
    {
        return [
            $this->row(0, 'Truk Mentah A', 'Supir Mentah A', 'TK-A'),
            $this->row(1, ' truk mentah a ', 'supir mentah a', 'TK-B'),
            $this->row(2, 'Truk Lain', 'Supir Lain', 'TK-C'),
        ];
    }

    private function row(int $index, string $assetLabel, string $driverLabel, string $ticket): array
    {
        return [
            'ticket_no' => $ticket,
            'asset_label' => $assetLabel,
            'driver_label' => $driverLabel,
            'source_asset_label' => $assetLabel,
            'source_driver_label' => $driverLabel,
            'asset_id' => 1,
            'driver_id' => 1,
            'route_id' => 1,
            'route_name' => 'Rute A',
            'trip_date' => '2026-11-01',
            'load_date' => '2026-11-01',
            'unload_date' => null,
            'gross_tons' => 12,
            'tare_tons' => 2,
            'actual_tons' => 10,
            'received_tons' => 10,
            'billable_tons' => 10,
            'sale_rate_per_ton' => 100,
            'origin' => null,
            'destination' => null,
            'variance_kg' => 0,
            'tolerance_kg' => 0,
            'claim_rate_per_kg' => 0,
            'other_deduction_total' => 0,
            'rate_override_reason' => 'Tarif dari workbook SPB.',
            'source_fingerprint' => 'fingerprint-'.$index,
            'source_sheet' => 'Sheet 1',
            'source_row' => $index + 2,
            'row_errors' => [],
            'row_error_fields' => [],
        ];
    }

    private function editInput(string $token): array
    {
        return [
            'token' => $token,
            'ticket_no' => 'TK-A',
            'asset_label' => 'Unit Resmi',
            'asset_id' => 2,
            'asset_mode' => 'existing',
            'driver_label' => 'Nama Resmi',
            'driver_id' => 2,
            'driver_mode' => 'existing',
            'trip_date' => '2026-11-01',
            'load_date' => '2026-11-01',
            'unload_date' => '',
            'gross_tons' => 12,
            'tare_tons' => 2,
            'actual_tons' => 10,
            'received_tons' => 10,
            'billable_tons' => 10,
            'sale_rate_per_ton' => 100,
            'origin' => '',
            'destination' => '',
            'route_name' => 'Rute A',
            'variance_kg' => 0,
            'tolerance_kg' => 0,
            'claim_rate_per_kg' => 0,
            'other_deduction_total' => 0,
            'rate_override_reason' => 'Tarif dari workbook SPB.',
        ];
    }

    private function cacheKey(string $token): string
    {
        return 'heavy_equipment_spb_import:999:'.$token;
    }

    private function admin(): \Illuminate\Foundation\Auth\User
    {
        $admin = new class extends \Illuminate\Foundation\Auth\User
        {
            public function can($ability, $arguments = [])
            {
                return $ability === 'superadmin';
            }
        };
        $admin->forceFill([
            'id' => 999,
            'business_id' => 7,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'surname' => 'Admin',
            'user_type' => 'user',
            'allow_login' => 1,
        ]);

        return $admin;
    }

    private function businessSession(): array
    {
        return [
            'user' => ['business_id' => 7],
            'business' => ['currency_symbol_placement' => 'before', 'currency_precision' => 2],
            'currency' => ['symbol' => 'Rp', 'decimal_separator' => ',', 'thousand_separator' => '.'],
        ];
    }
}
