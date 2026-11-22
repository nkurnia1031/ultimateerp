<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AssetManagement\Entities\HeavyEquipmentTrip;
use Modules\AssetManagement\Services\HeavyEquipmentSpbImportService;
use Modules\AssetManagement\Services\HeavyEquipmentTripService;
use Tests\TestCase;

class HeavyEquipmentSpbImportPreviewEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\AdminSidebarMenu::class,
            \App\Http\Middleware\Timezone::class,
        ]);

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
            ['id' => 4, 'business_id' => 7, 'asset_code' => 'BM-9133', 'name' => 'BM 9133'],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'business_id' => 7, 'first_name' => 'Sopir Lama', 'last_name' => '', 'surname' => '', 'username' => '', 'user_type' => 'user'],
            ['id' => 2, 'business_id' => 7, 'first_name' => 'Nama Resmi', 'last_name' => '', 'surname' => '', 'username' => '', 'user_type' => 'user'],
            ['id' => 3, 'business_id' => 8, 'first_name' => 'Private', 'last_name' => '', 'surname' => '', 'username' => '', 'user_type' => 'user'],
            ['id' => 4, 'business_id' => 7, 'first_name' => 'Baginda Imom', 'last_name' => 'Nasution', 'surname' => 'Mr', 'username' => '', 'user_type' => 'user'],
            ['id' => 5, 'business_id' => 7, 'first_name' => 'Mr. Baginda Imom Nasution', 'last_name' => '', 'surname' => '', 'username' => '', 'user_type' => 'user'],
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

    public function test_preview_matches_honorific_variants_but_requires_explicit_choice_for_duplicate_users(): void
    {
        $preview = app(HeavyEquipmentSpbImportService::class)->preview($this->workbook('Baginda Imom Nasution'), 7);
        $row = $preview['rows'][0];

        $this->assertNull($row['driver_id']);
        $this->assertSame(100, $row['driver_match']['confidence_percent']);
        $this->assertFalse($row['driver_match']['requires_review']);
        $this->assertSame([4, 5], array_slice(array_column($row['driver_match']['candidates'], 'id'), 0, 2));
        $this->assertNotContains(3, array_column($row['driver_match']['candidates'], 'id'));
        $this->assertStringContainsString('User #', $row['driver_match']['candidates'][0]['label']);
        $this->assertContains('driver_id', array_keys($row['row_error_fields']));
        $this->assertStringContainsString('Ditemukan 2 User', $row['row_error_fields']['driver_id'][0]);
    }

    public function test_low_similarity_is_flagged_for_manual_review_and_unique_exact_match_is_linked(): void
    {
        $service = app(HeavyEquipmentSpbImportService::class);
        $lowConfidencePreview = $service->preview($this->workbook('Baginda'), 7);
        $lowConfidenceRow = $lowConfidencePreview['rows'][0];

        $this->assertNull($lowConfidenceRow['driver_id']);
        $this->assertTrue($lowConfidenceRow['driver_match']['requires_review']);
        $this->assertLessThan(60, $lowConfidenceRow['driver_match']['confidence_percent']);
        $this->assertStringContainsString('di bawah 60%', $lowConfidenceRow['row_error_fields']['driver_id'][0]);

        DB::table('users')->whereIn('id', [4, 5])->delete();
        $uniqueExactPreview = $service->preview($this->workbook('Sopir Lama'), 7);

        $this->assertSame(1, $uniqueExactPreview['rows'][0]['driver_id']);
        $this->assertSame(100, $uniqueExactPreview['rows'][0]['driver_match']['confidence_percent']);
    }

    private function rowsWithRepeatedLabels(): array
    {
        return [
            $this->row(0, 'Truk Mentah A', 'Mr. Baginda Imom Nasution', 'TK-A'),
            $this->row(1, ' truk mentah a ', 'Baginda Imom Nasution', 'TK-B'),
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

    private function workbook(string $driverName): UploadedFile
    {
        $csv = "No Polisi,No Tiket,Tanggal,Netto,Harga,Keterangan\n"
            .'BM 9133,TK-CSV,2026-11-01,10,1000,"'.$driverName.'"'."\n";

        return UploadedFile::fake()->createWithContent('spb.csv', $csv);
    }

    private function admin(): \Illuminate\Foundation\Auth\User
    {
        $admin = new class extends \Illuminate\Foundation\Auth\User
        {
            public function can($ability, $arguments = [])
            {
                return $ability === 'superadmin';
            }

            public function hasRole($roles, $guard = null): bool
            {
                return true;
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
