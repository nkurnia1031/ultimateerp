<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HeavyEquipmentTripActionsTest extends TestCase
{
    private string $temporaryViewDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated SPB action tests.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->temporaryViewDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ultimateerp-spb-test-'.uniqid();
        File::makeDirectory($this->temporaryViewDirectory.'/layouts/partials', 0755, true);
        File::put($this->temporaryViewDirectory.'/layouts/app.blade.php', '@yield("content")');
        File::put($this->temporaryViewDirectory.'/layouts/partials/fleet-datatables.blade.php', '');
        app('view.finder')->prependLocation($this->temporaryViewDirectory);

        Schema::create('heavy_equipment_routes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('assets', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('surname')->nullable();
            $table->string('user_type')->nullable();
        });
        Schema::create('pjt_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
        });
        Schema::create('heavy_equipment_trips', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('trip_no');
            $table->string('ticket_no')->nullable();
            $table->date('trip_date');
            $table->date('load_date')->nullable();
            $table->date('unload_date')->nullable();
            $table->unsignedInteger('project_id')->nullable();
            $table->unsignedInteger('customer_id')->nullable();
            $table->unsignedInteger('route_id');
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('driver_id');
            $table->decimal('actual_tons', 22, 4)->default(0);
            $table->decimal('billable_tons', 22, 4)->default(0);
            $table->decimal('revenue_total', 22, 4)->default(0);
            $table->decimal('driver_pay_total', 22, 4)->default(0);
            $table->decimal('gross_contribution', 22, 4)->default(0);
            $table->string('status')->default('draft');
            $table->string('invoice_status')->default('unbilled');
            $table->string('payroll_status')->default('unsettled');
            $table->string('source_workbook')->nullable();
            $table->timestamps();
        });
        Schema::create('heavy_equipment_trip_billings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('trip_id');
        });
        Schema::create('heavy_equipment_driver_settlements', function (Blueprint $table) {
            $table->increments('id');
            $table->string('settlement_no');
        });
        Schema::create('heavy_equipment_settlement_trips', function (Blueprint $table) {
            $table->unsignedInteger('settlement_id');
            $table->unsignedInteger('trip_id');
            $table->timestamps();
        });

        DB::table('heavy_equipment_routes')->insert(['id' => 1, 'business_id' => 7, 'name' => 'Rute Utama', 'is_active' => true]);
        DB::table('assets')->insert(['id' => 1, 'business_id' => 7, 'name' => 'Truk 1']);
        DB::table('users')->insert(['id' => 1, 'business_id' => 7, 'first_name' => 'Sopir', 'last_name' => 'Satu', 'surname' => '', 'user_type' => 'user']);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryViewDirectory)) {
            File::deleteDirectory($this->temporaryViewDirectory);
        }

        parent::tearDown();
    }

    public function test_index_shows_edit_and_delete_for_manual_and_imported_mutable_spb_only(): void
    {
        $importedId = $this->createTrip(101, 'draft', 'rekap.xlsx');
        $manualId = $this->createTrip(102, 'completed', null);
        $verifiedId = $this->createTrip(103, 'verified', 'rekap.xlsx');

        $response = $this->withoutMiddleware(['authh', 'SetSessionData', 'language', 'timezone', 'AdminSidebarMenu'])
            ->actingAs($this->admin())
            ->withSession($this->businessSession())
            ->get(route('asset.fleet.trips.index'));

        $response->assertOk()
            ->assertSee(route('asset.fleet.trips.edit', $importedId))
            ->assertSee(route('asset.fleet.trips.destroy', $importedId))
            ->assertSee(route('asset.fleet.trips.edit', $manualId))
            ->assertSee(route('asset.fleet.trips.destroy', $manualId))
            ->assertDontSee(route('asset.fleet.trips.edit', $verifiedId))
            ->assertDontSee(route('asset.fleet.trips.destroy', $verifiedId));
    }

    public function test_mutable_spb_can_be_deleted_from_the_index_route(): void
    {
        $tripId = $this->createTrip(201, 'draft', 'rekap.xlsx');

        $response = $this->withoutMiddleware(['authh', 'SetSessionData', 'language', 'timezone', 'AdminSidebarMenu'])
            ->actingAs($this->admin())
            ->withSession($this->businessSession())
            ->delete(route('asset.fleet.trips.destroy', $tripId));

        $response->assertRedirect(route('asset.fleet.trips.index'));
        $this->assertDatabaseMissing('heavy_equipment_trips', ['id' => $tripId]);
    }

    public function test_spb_that_is_billed_or_settled_cannot_be_deleted(): void
    {
        $billedTripId = $this->createTrip(301, 'draft', 'rekap.xlsx');
        $settledTripId = $this->createTrip(302, 'completed', null);
        DB::table('heavy_equipment_trip_billings')->insert(['trip_id' => $billedTripId]);
        DB::table('heavy_equipment_driver_settlements')->insert(['id' => 1, 'settlement_no' => 'ST-1']);
        DB::table('heavy_equipment_settlement_trips')->insert([
            'settlement_id' => 1,
            'trip_id' => $settledTripId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$billedTripId, $settledTripId] as $tripId) {
            $this->withoutMiddleware(['authh', 'SetSessionData', 'language', 'timezone', 'AdminSidebarMenu'])
                ->actingAs($this->admin())
                ->withSession($this->businessSession())
                ->delete(route('asset.fleet.trips.destroy', $tripId))
                ->assertStatus(422);

            $this->assertDatabaseHas('heavy_equipment_trips', ['id' => $tripId]);
        }
    }

    private function createTrip(int $id, string $status, ?string $sourceWorkbook): int
    {
        DB::table('heavy_equipment_trips')->insert([
            'id' => $id,
            'business_id' => 7,
            'trip_no' => 'SPB-'.$id,
            'ticket_no' => 'TICKET-'.$id,
            'trip_date' => '2026-11-01',
            'route_id' => 1,
            'asset_id' => 1,
            'driver_id' => 1,
            'actual_tons' => 10,
            'billable_tons' => 10,
            'revenue_total' => 1000,
            'driver_pay_total' => 500,
            'gross_contribution' => 500,
            'status' => $status,
            'invoice_status' => 'unbilled',
            'payroll_status' => 'unsettled',
            'source_workbook' => $sourceWorkbook,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function admin(): User
    {
        $admin = new class extends User
        {
            public function can($ability, $arguments = [])
            {
                return $ability === 'superadmin';
            }
        };
        $admin->forceFill([
            'id' => 999,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'surname' => 'Admin',
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
