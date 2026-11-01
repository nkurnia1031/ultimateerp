<?php

namespace Tests\Unit;

use Modules\Accounting\Services\LegacyOperationalEvidenceReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class LegacyOperationalEvidenceReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-operational-evidence-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    public function test_synthetic_xlsx_and_json_sources_merge_by_identity_and_keep_product_detail_once(): void
    {
        $workbookPath = 'PEMBELIAN/purchases_list_01-01-2022_31-12-2022.xlsx';
        $jsonPath = 'PEMBELIAN/Pembelian 2022 page1.json';
        $this->writeWorkbook($workbookPath);
        $this->writeJson($jsonPath);

        $files = [];
        foreach ([$workbookPath, $jsonPath] as $path) {
            $absolutePath = $this->directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            $files[] = [
                'path' => $path,
                'sha256' => hash_file('sha256', $absolutePath),
                'classification' => str_ends_with($path, '.json')
                    ? 'operational_detail_json'
                    : 'purchase_operational_source',
            ];
        }

        $result = (new LegacyOperationalEvidenceReader())->read($files, $this->directory, 2022);
        $document = array_values($result['documents'])[0];

        $this->assertSame(1, $result['document_count']);
        $this->assertSame(2, $result['source_record_count']);
        $this->assertSame('matched', $document['status']);
        $this->assertSame('PUR-2022-001', $document['source_number']);
        $this->assertSame(100.0, $document['base']);
        $this->assertSame(10.0, $document['tax']);
        $this->assertSame(110.0, $document['total']);
        $this->assertCount(1, $document['lines']);
        $this->assertSame('Part A', $document['lines'][0]['product_name']);
        $this->assertCount(2, $document['provenance']);
    }

    public function test_summary_and_detail_exports_complement_each_other_without_duplicating_lines(): void
    {
        $summary = $this->document('operational-summary.xlsx', 110, 10, 100);
        $detail = $this->document('operational-detail.json', 110, null, null);
        $detail['lines'] = [$this->line('Part A', 2, 50)];

        $merged = (new LegacyOperationalEvidenceReader())->mergeDocuments([$summary, $detail]);
        $document = array_values($merged['documents'])[0];

        $this->assertSame('matched', $document['status']);
        $this->assertSame(2, $merged['source_record_count']);
        $this->assertSame(1, $merged['duplicate_source_count']);
        $this->assertCount(1, $document['lines']);
        $this->assertSame(100.0, $document['base']);
        $this->assertSame(10.0, $document['tax']);
        $this->assertSame(110.0, $document['total']);
    }

    public function test_detail_sources_are_combined_only_when_their_sum_matches_the_shared_base(): void
    {
        $first = $this->document('purchase-lines-a.xlsx', 110, 10, 100);
        $first['lines'] = [$this->line('Part A', 1, 60)];
        $second = $this->document('purchase-lines-b.xlsx', 110, 10, 100);
        $second['lines'] = [$this->line('Part B', 1, 40)];

        $merged = (new LegacyOperationalEvidenceReader())->mergeDocuments([$first, $second]);
        $document = array_values($merged['documents'])[0];

        $this->assertSame('matched', $document['status']);
        $this->assertCount(2, $document['lines']);
        $this->assertEquals(100, array_sum(array_column($document['lines'], 'line_base')));
    }

    public function test_conflicting_totals_stay_reviewable(): void
    {
        $first = $this->document('sales-list.xlsx', 110, 10, 100);
        $second = $this->document('sales-json.json', 120, 10, 110);

        $merged = (new LegacyOperationalEvidenceReader())->mergeDocuments([$first, $second]);
        $document = array_values($merged['documents'])[0];

        $this->assertSame('conflict', $document['status']);
        $this->assertSame(1, $merged['conflict_count']);
        $this->assertStringContainsString('Nominal total berbeda', $document['review_reason']);
    }

    private function document(string $path, float $total, ?float $tax, ?float $base): array
    {
        return [
            'source_type' => 'Purchase Invoice',
            'source_number' => 'PUR-2022-001',
            'date' => '2022-03-12',
            'total' => $total,
            'tax' => $tax,
            'base' => $base,
            'lines' => [],
            'expense_lines' => [],
            'provenance' => [[
                'path' => $path,
                'sha256' => hash('sha256', $path),
                'row' => 10,
                'source_identity' => hash('sha256', 'purchase|PUR-2022-001|2022-03-12'),
            ]],
        ];
    }

    private function line(string $name, float $quantity, float $base): array
    {
        return [
            'product_name' => $name,
            'product_code' => '',
            'quantity' => $quantity,
            'unit_price' => $base / $quantity,
            'line_base' => $base,
            'tax' => null,
            'discount_percent' => 0,
            'description' => '',
            'source_row' => 11,
        ];
    }

    private function writeWorkbook(string $relativePath): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Tanggal', 'Nomor', 'Supplier', 'Deskripsi', 'Nama Produk', 'Kuantitas', 'Harga per unit', 'Subtotal', 'Pajak', 'Total'],
            ['2022-03-12', 'PUR-2022-001', 'Supplier A', 'Pembelian part', 'Part A', 2, 50, 100, 10, 110],
        ]);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function writeJson(string $relativePath): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, json_encode([
            'purchases_lists' => [
                'transactions' => [[
                    'transaction_date' => '2022-03-12',
                    'transaction_no' => 'PUR-2022-001',
                    'person' => ['name' => 'Supplier A'],
                    'description' => 'Pembelian part',
                    'original_amount' => 110,
                    'tax_amount' => 10,
                    'gross_taxable_amount' => 100,
                    'product' => ['name' => 'Part A'],
                    'quantity_unit' => '2 Pcs',
                    'unit_price' => 50,
                    'taxable_amount_per_line' => 100,
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
