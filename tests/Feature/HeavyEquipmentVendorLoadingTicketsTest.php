<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HeavyEquipmentVendorLoadingTicketsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\Timezone::class);

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated Loading Vendor tests.');
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

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('surname')->nullable();
            $table->string('user_type')->nullable();
            $table->softDeletes();
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('heavy_equipment_routes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('heavy_equipment_vendor_loading_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('document_no');
            $table->string('goods_name')->nullable();
            $table->string('goods_type')->nullable();
            $table->string('vessel_name')->nullable();
            $table->string('destination')->nullable();
            $table->unsignedInteger('supplier_id')->nullable();
            $table->string('supplier_name_snapshot')->nullable();
            $table->unsignedInteger('customer_id')->nullable();
            $table->string('customer_name_snapshot')->nullable();
            $table->unsignedInteger('route_id')->nullable();
            $table->string('route_name_snapshot')->nullable();
            $table->string('source_sheet')->nullable();
            $table->timestamps();
        });
        Schema::create('heavy_equipment_vendor_loadings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('loading_document_id')->nullable();
            $table->string('ticket_no')->nullable();
            $table->string('loading_no')->nullable();
            $table->date('loading_date')->nullable();
            $table->date('load_date')->nullable();
            $table->date('unload_date')->nullable();
            $table->string('vendor_unit_name')->nullable();
            $table->string('vendor_driver_name')->nullable();
            $table->decimal('gross_tons', 22, 4)->nullable();
            $table->decimal('tare_tons', 22, 4)->nullable();
            $table->decimal('net_tons', 22, 4)->nullable();
            $table->decimal('billable_tons', 22, 4)->nullable();
            $table->decimal('vendor_total_amount', 22, 4)->nullable();
        });

        DB::table('heavy_equipment_vendor_loading_documents')->insert([
            ['id' => 10, 'business_id' => 7, 'document_no' => 'DOC-10', 'source_sheet' => 'Sheet A', 'supplier_name_snapshot' => 'Supplier A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'business_id' => 7, 'document_no' => 'DOC-11', 'source_sheet' => 'Sheet B', 'supplier_name_snapshot' => 'Supplier B', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'business_id' => 8, 'document_no' => 'DOC-20', 'source_sheet' => 'Sheet Private', 'supplier_name_snapshot' => 'Private Supplier', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('heavy_equipment_vendor_loadings')->insert([
            ['id' => 101, 'loading_document_id' => 10, 'ticket_no' => 'TK-101', 'loading_no' => 'LD-101', 'loading_date' => '2026-11-19', 'vendor_unit_name' => 'Truk A', 'vendor_driver_name' => 'Supir A', 'gross_tons' => 10, 'tare_tons' => 2, 'net_tons' => 8, 'billable_tons' => 8, 'vendor_total_amount' => 800000],
            ['id' => 102, 'loading_document_id' => 10, 'ticket_no' => 'TK-102', 'loading_no' => 'LD-102', 'loading_date' => '2026-11-19', 'vendor_unit_name' => 'Truk B', 'vendor_driver_name' => 'Supir B', 'gross_tons' => 11, 'tare_tons' => 2, 'net_tons' => 9, 'billable_tons' => 9, 'vendor_total_amount' => 900000],
            ['id' => 103, 'loading_document_id' => 10, 'ticket_no' => 'TK-103', 'loading_no' => 'LD-103', 'loading_date' => '2026-11-20', 'vendor_unit_name' => 'Truk C', 'vendor_driver_name' => 'Supir C', 'gross_tons' => 12, 'tare_tons' => 2, 'net_tons' => 10, 'billable_tons' => 10, 'vendor_total_amount' => 1000000],
            ['id' => 104, 'loading_document_id' => 11, 'ticket_no' => 'TK-104', 'loading_no' => 'LD-104', 'loading_date' => '2026-11-19', 'vendor_unit_name' => 'Truk D', 'vendor_driver_name' => 'Supir D', 'gross_tons' => 13, 'tare_tons' => 2, 'net_tons' => 11, 'billable_tons' => 11, 'vendor_total_amount' => 1100000],
            ['id' => 105, 'loading_document_id' => 20, 'ticket_no' => 'TK-105', 'loading_no' => 'LD-105', 'loading_date' => '2026-11-19', 'vendor_unit_name' => 'Truk Private', 'vendor_driver_name' => 'Supir Private', 'gross_tons' => 14, 'tare_tons' => 2, 'net_tons' => 12, 'billable_tons' => 12, 'vendor_total_amount' => 1200000],
        ]);
    }

    public function test_index_returns_clickable_ticket_dates_for_each_document(): void
    {
        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->actingAs($this->admin())
            ->withSession($this->businessSession())
            ->get(route('asset.fleet.vendor-loadings.data'));

        $response->assertOk()->assertJsonCount(2, 'data');
        $rows = collect($response->json('data'));
        $documentDates = $rows->firstWhere('document_no', 'DOC-10')['ticket_dates'];
        $otherDocumentDates = $rows->firstWhere('document_no', 'DOC-11')['ticket_dates'];
        $dateButtons = $documentDates;
        $this->assertStringContainsString('data-date="2026-11-19"', $dateButtons);
        $this->assertStringContainsString('data-date="2026-11-20"', $dateButtons);
        $this->assertStringNotContainsString('2026-11-20', $otherDocumentDates);

        $view = file_get_contents(base_path('Modules/AssetManagement/Resources/views/fleet/vendor_loadings/index.blade.php'));
        $this->assertMatchesRegularExpression('/<th>Sheet sumber<\/th><th>Tanggal tiket<\/th><th>Bendera \/ Supplier<\/th>/', $view);
        $this->assertStringContainsString("on('click', '.vendor-loading-ticket-date'", $view);
        $this->assertStringContainsString('id="vendor-loading-tickets-modal"', $view);
    }

    public function test_ticket_list_is_limited_to_selected_document_date_and_business(): void
    {
        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->actingAs($this->admin())
            ->withSession($this->businessSession())
            ->get(route('asset.fleet.vendor-loadings.documents.tickets', ['id' => 10, 'date' => '2026-11-19', 'start' => 0, 'length' => 1]));

        $response->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 2)
            ->assertJsonCount(1, 'data');
        $this->assertSame('TK-101', $response->json('data.0.ticket_no'));

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->actingAs($this->admin())
            ->withSession($this->businessSession())
            ->get(route('asset.fleet.vendor-loadings.documents.tickets', ['id' => 20, 'date' => '2026-11-19']))
            ->assertNotFound();
    }

    public function test_document_detail_uses_searchable_paginated_table_for_scoped_ticket_lines(): void
    {
        $view = file_get_contents(base_path('Modules/AssetManagement/Resources/views/fleet/vendor_loadings/document_show.blade.php'));
        $controller = file_get_contents(base_path('Modules/AssetManagement/Http/Controllers/HeavyEquipmentVendorLoadingController.php'));

        $this->assertStringContainsString("@include('layouts.partials.fleet-datatables')", $view);
        $this->assertStringContainsString('@foreach($document->lines as $index => $line)', $view);
        $this->assertStringContainsString("window.initFleetDataTable('#vendor-document-lines'", $view);
        $this->assertStringContainsString('searching: true', $view);
        $this->assertStringContainsString('paging: true', $view);
        $this->assertStringContainsString('lineTable.rows().nodes().to$()', $view);
        $this->assertStringContainsString('lineTable.row(row.get(0)).remove()', $view);
        $this->assertStringContainsString('$this->document($id)->load([\'supplier\', \'customer\', \'route\', \'lines.project\', \'lines.loadingDocument\'])', $controller);
        $this->assertStringContainsString('HeavyEquipmentVendorLoadingDocument::where(\'business_id\', $this->businessId())->findOrFail($id)', $controller);
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

            public function hasAnyPermission(...$permissions): bool
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
