<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HeavyEquipmentTripActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\Timezone::class);

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
            $table->softDeletes();
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
            $table->softDeletes();
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

    public function test_index_template_exposes_edit_and_delete_for_mutable_rows(): void
    {
        $template = file_get_contents(base_path('Modules/AssetManagement/Resources/views/fleet/trips/index.blade.php'));
        $this->assertStringContainsString('@if($canManageTrips && $trip->canBeChanged())', $template);
        $this->assertStringContainsString('route(\'asset.fleet.trips.edit\', $trip->id)', $template);
        $this->assertStringContainsString('route(\'asset.fleet.trips.destroy\', $trip->id)', $template);
        $this->assertStringContainsString('>Edit</a>', $template);
        $this->assertStringContainsString('>Hapus</button>', $template);
    }

    public function test_mutable_spb_can_be_deleted_from_the_index_route(): void
    {
        $tripId = $this->createTrip(201, 'draft', 'rekap.xlsx');

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
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
        $verifiedTripId = $this->createTrip(303, 'verified', 'rekap.xlsx');
        $cancelledTripId = $this->createTrip(304, 'cancelled', null);
        DB::table('heavy_equipment_trip_billings')->insert(['trip_id' => $billedTripId]);
        DB::table('heavy_equipment_driver_settlements')->insert(['id' => 1, 'settlement_no' => 'ST-1']);
        DB::table('heavy_equipment_settlement_trips')->insert([
            'settlement_id' => 1,
            'trip_id' => $settledTripId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$billedTripId, $settledTripId, $verifiedTripId, $cancelledTripId] as $tripId) {
            $this->withHeader('X-Requested-With', 'XMLHttpRequest')
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

    private function admin(): \Illuminate\Foundation\Auth\User
    {
        $admin = new class extends \Illuminate\Foundation\Auth\User
        {
            public function can($ability, $arguments = [])
            {
                return $ability === 'superadmin';
            }

            public function hasRole($role): bool
            {
                return false;
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
