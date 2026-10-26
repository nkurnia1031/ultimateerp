<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Services\GeneralLedgerReportService;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ReflectionClass;
use Tests\TestCase;

class GeneralLedgerExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
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

        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('gl_code')->nullable();
            $table->unsignedInteger('business_id');
            $table->string('account_primary_type')->nullable();
            $table->string('status')->nullable();
        });
        Schema::create('accounting_accounts_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('accounting_account_id');
            $table->unsignedInteger('acc_trans_mapping_id')->nullable();
            $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('transaction_payment_id')->nullable();
            $table->decimal('amount', 22, 4);
            $table->string('type');
            $table->string('sub_type')->nullable();
            $table->dateTime('operation_date');
            $table->string('note')->nullable();
        });
        Schema::create('accounting_acc_trans_mappings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('ref_no')->nullable();
            $table->string('type')->nullable();
            $table->text('note')->nullable();
            $table->string('source_record_type')->nullable();
            $table->unsignedInteger('source_record_id')->nullable();
        });
        Schema::create('system', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->text('value')->nullable();
        });
        DB::table('system')->insert(['key' => 'accounting_version', 'value' => '1.0.0']);
    }

    public function test_export_endpoint_uses_the_selected_account_and_date_range(): void
    {
        DB::table('accounting_accounts')->insert([
            ['id' => 1, 'name' => 'Kas Utama', 'gl_code' => '1001', 'business_id' => 1, 'account_primary_type' => 'asset', 'status' => 'active'],
            ['id' => 2, 'name' => 'Bank Lain', 'gl_code' => '1002', 'business_id' => 1, 'account_primary_type' => 'asset', 'status' => 'active'],
            ['id' => 3, 'name' => 'Akun Business Lain', 'gl_code' => '1003', 'business_id' => 2, 'account_primary_type' => 'asset', 'status' => 'active'],
        ]);
        DB::table('accounting_accounts_transactions')->insert([
            ['id' => 1, 'accounting_account_id' => 1, 'amount' => 100, 'type' => 'debit', 'sub_type' => 'opening_balance', 'operation_date' => '2021-12-31 10:00:00', 'note' => 'Opening sebelum periode'],
            ['id' => 2, 'accounting_account_id' => 1, 'amount' => 20, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-10 10:00:00', 'note' => 'BARIS YANG HARUS DIEKSPOR'],
            ['id' => 3, 'accounting_account_id' => 1, 'amount' => 999, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-02-10 10:00:00', 'note' => 'DI LUAR PERIODE'],
            ['id' => 4, 'accounting_account_id' => 2, 'amount' => 777, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-10 10:00:00', 'note' => 'DI LUAR FILTER AKUN'],
            ['id' => 5, 'accounting_account_id' => 3, 'amount' => 555, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-10 10:00:00', 'note' => 'DI LUAR BUSINESS'],
        ]);

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('superadmin')->andReturn(true);
        $user->shouldReceive('can')->with('accounting.view_reports')->andReturn(true);

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($user)
            ->withSession([
                'user' => ['business_id' => 1],
                'business' => ['name' => 'CV Demo', 'currency_precision' => 2, 'quantity_precision' => 2],
                'currency' => ['decimal_separator' => '.', 'thousand_separator' => ','],
            ])
            ->get(route('accounting.generalLedger.export', [
                'start_date' => '2022-01-01',
                'end_date' => '2022-01-31',
                'account_id' => 1,
            ]));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=buku-besar-2022-01-01-2022-01-31.xlsx');
        $spreadsheetPath = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($spreadsheetPath);
        $sheet = $spreadsheet->getActiveSheet();
        $workbookText = $this->spreadsheetText($spreadsheet);
        $this->assertSame('Buku Besar', $sheet->getTitle());
        $this->assertSame('A6', $sheet->getFreezePane());
        $this->assertTrue($sheet->getStyle('A4')->getFont()->getBold());
        $this->assertSame('FF294965', $sheet->getStyle('A4')->getFill()->getStartColor()->getARGB());
        $this->assertGreaterThan(12, $sheet->getColumnDimension('A')->getWidth());
        $this->assertContains('A1:I1', $sheet->getMergeCells());
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        unlink($spreadsheetPath);

        $this->assertStringContainsString('BARIS YANG HARUS DIEKSPOR', $workbookText);
        $this->assertStringContainsString('Kas Utama', $workbookText);
        $this->assertStringContainsString('100', $workbookText);
        $this->assertStringNotContainsString('DI LUAR PERIODE', $workbookText);
        $this->assertStringNotContainsString('DI LUAR FILTER AKUN', $workbookText);
        $this->assertStringNotContainsString('DI LUAR BUSINESS', $workbookText);
    }

    public function test_export_includes_accounts_with_an_opening_balance_and_no_period_postings(): void
    {
        DB::table('accounting_accounts')->insert([
            'id' => 1,
            'name' => 'Akun Dormant',
            'gl_code' => '1001',
            'business_id' => 1,
            'account_primary_type' => 'asset',
            'status' => 'active',
        ]);
        DB::table('accounting_accounts_transactions')->insert([
            'id' => 1,
            'accounting_account_id' => 1,
            'amount' => 250,
            'type' => 'debit',
            'sub_type' => 'opening_balance',
            'operation_date' => '2021-12-31 10:00:00',
            'note' => 'Saldo sebelumnya',
        ]);

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('superadmin')->andReturn(true);
        $user->shouldReceive('can')->with('accounting.view_reports')->andReturn(true);

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($user)
            ->withSession([
                'user' => ['business_id' => 1],
                'business' => ['name' => 'CV Demo', 'currency_precision' => 2, 'quantity_precision' => 2],
                'currency' => ['decimal_separator' => '.', 'thousand_separator' => ','],
            ])
            ->get(route('accounting.generalLedger.export', [
                'start_date' => '2022-01-01',
                'end_date' => '2022-01-31',
            ]));

        $response->assertOk();
        $spreadsheetPath = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($spreadsheetPath);
        $workbookText = $this->spreadsheetText($spreadsheet);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        unlink($spreadsheetPath);

        $this->assertStringContainsString('Akun Dormant', $workbookText);
        $this->assertStringContainsString('Saldo Awal', $workbookText);
        $this->assertStringContainsString('250', $workbookText);
    }

    public function test_paginated_report_carries_each_accounts_balance_across_pages(): void
    {
        DB::table('accounting_accounts')->insert([
            'id' => 1,
            'name' => 'Kas Utama',
            'gl_code' => '1001',
            'business_id' => 1,
            'account_primary_type' => 'asset',
            'status' => 'active',
        ]);
        DB::table('accounting_accounts_transactions')->insert([
            ['id' => 1, 'accounting_account_id' => 1, 'amount' => 100, 'type' => 'debit', 'sub_type' => 'opening_balance', 'operation_date' => '2021-12-31 10:00:00', 'note' => 'Opening'],
        ]);
        $periodEntries = [];
        for ($id = 2; $id <= 101; $id++) {
            $periodEntries[] = [
                'id' => $id,
                'accounting_account_id' => 1,
                'amount' => 1,
                'type' => 'debit',
                'sub_type' => 'journal_entry',
                'operation_date' => '2022-01-10 10:00:00',
                'note' => 'Posting '.$id,
            ];
        }
        $periodEntries[] = [
            'id' => 102,
            'accounting_account_id' => 1,
            'amount' => 5,
            'type' => 'credit',
            'sub_type' => 'journal_entry',
            'operation_date' => '2022-01-10 10:00:00',
            'note' => 'LAST PAGE',
        ];
        DB::table('accounting_accounts_transactions')->insert($periodEntries);

        $report = app(GeneralLedgerReportService::class)->paginate(1, '2022-01-01', '2022-01-31', null, 2);

        $this->assertSame(101, $report->total());
        $this->assertCount(1, $report->items());
        $this->assertSame('LAST PAGE', $report->items()[0]['note']);
        $this->assertSame(195.0, $report->items()[0]['balance']);
    }

    public function test_plate_uses_related_asset_unit_before_tags_and_tags_as_fallback(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
        });
        Schema::create('heavy_equipment_trips', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('route_rate_id')->nullable();
        });
        Schema::create('heavy_equipment_trip_billings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('transaction_id');
        });
        Schema::create('heavy_equipment_rate_units', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('route_rate_id');
            $table->unsignedInteger('asset_id')->nullable();
            $table->string('unit_label');
        });
        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
        });
        Schema::create('transaction_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('tag_id');
        });
        Schema::create('transaction_sell_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
        });
        Schema::create('transaction_sell_line_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_sell_line_id');
            $table->unsignedInteger('tag_id');
        });

        DB::table('assets')->insert(['id' => 101, 'business_id' => 1]);
        DB::table('heavy_equipment_trips')->insert(['id' => 201, 'asset_id' => 101, 'route_rate_id' => 301]);
        DB::table('heavy_equipment_trip_billings')->insert(['business_id' => 1, 'trip_id' => 201, 'transaction_id' => 401]);
        DB::table('heavy_equipment_rate_units')->insert(['route_rate_id' => 301, 'asset_id' => 101, 'unit_label' => 'B 9845 QZ']);
        DB::table('tags')->insert([
            ['id' => 501, 'business_id' => 1, 'name' => 'TAG SHOULD NOT WIN'],
            ['id' => 502, 'business_id' => 1, 'name' => 'TAG FALLBACK'],
            ['id' => 503, 'business_id' => 2, 'name' => 'OTHER BUSINESS TAG'],
            ['id' => 504, 'business_id' => 1, 'name' => 'SELL LINE TAG'],
        ]);
        DB::table('transaction_tags')->insert([
            ['transaction_id' => 401, 'tag_id' => 501],
            ['transaction_id' => 402, 'tag_id' => 502],
            ['transaction_id' => 402, 'tag_id' => 503],
        ]);
        DB::table('transaction_sell_lines')->insert(['id' => 601, 'transaction_id' => 403]);
        DB::table('transaction_sell_line_tags')->insert(['transaction_sell_line_id' => 601, 'tag_id' => 504]);

        $service = app(GeneralLedgerReportService::class);
        $method = (new ReflectionClass($service))->getMethod('loadPlates');
        $method->setAccessible(true);

        $this->assertSame([
            401 => 'B 9845 QZ',
            402 => 'TAG FALLBACK',
            403 => 'SELL LINE TAG',
        ], $method->invoke($service, 1, [401, 402, 403]));
    }

    private function spreadsheetText($spreadsheet): string
    {
        return implode("\n", array_map(function ($row) {
            return implode(' ', array_map('strval', $row));
        }, $spreadsheet->getActiveSheet()->toArray(null, true, true, false)));
    }
}
