<?php

namespace Tests\Unit;

use Modules\Accounting\Services\LegacyImportSourceCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LegacyImportSourceCatalogTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-import-catalog-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    public function test_inventory_classifies_source_and_control_files_and_marks_exact_duplicates(): void
    {
        $this->write('journal_01-01-2021_31-12-2025.xlsx', 'journal bytes');
        $this->write('journal_01-01-2022_31-12-2022.xlsm', 'macro-enabled journal bytes');
        $this->write('copy/journal_01-01-2021_31-12-2025.xlsx', 'journal bytes');
        $this->write('PENJUALAN/sales_list_01-01-2024_31-12-2024.xlsx', 'sales bytes');
        $this->write('PEMBELIAN/purchases_list_01-01-2024_31-12-2024.xlsx', 'purchase bytes');
        $this->write('BIAYA/ExpenseDetails_01-01-2024_31-12-2024.xlsx', 'expense details');
        $this->write('json/lampiran.json', '[]');
        $this->write('BIAYA/4514030_errors.csv', 'error report');
        $this->write('general_ledger_01-01-2010_06-09-2025.xlsx', 'ledger control');
        $this->write('Buku Besar 2022 - Complete.xlsm', 'book reference');

        $inventory = (new LegacyImportSourceCatalog())->scan($this->directory);
        $byPath = [];
        foreach ($inventory['files'] as $file) {
            $byPath[$file['path']] = $file;
        }

        $this->assertSame(10, $inventory['file_count']);
        $this->assertSame('journal_posting_source', $byPath['journal_01-01-2021_31-12-2025.xlsx']['classification']);
        $this->assertSame('journal_posting_source', $byPath['journal_01-01-2022_31-12-2022.xlsm']['classification']);
        $this->assertSame('sales_operational_source', $byPath['PENJUALAN/sales_list_01-01-2024_31-12-2024.xlsx']['classification']);
        $this->assertSame('purchase_operational_source', $byPath['PEMBELIAN/purchases_list_01-01-2024_31-12-2024.xlsx']['classification']);
        $this->assertSame('expense_detail_source', $byPath['BIAYA/ExpenseDetails_01-01-2024_31-12-2024.xlsx']['classification']);
        $this->assertSame('attachment_metadata', $byPath['json/lampiran.json']['classification']);
        $this->assertSame('excluded_template_or_error', $byPath['BIAYA/4514030_errors.csv']['classification']);
        $this->assertSame('general_ledger_control', $byPath['general_ledger_01-01-2010_06-09-2025.xlsx']['classification']);
        $this->assertSame('book_reference', $byPath['Buku Besar 2022 - Complete.xlsm']['classification']);
        $this->assertSame(1, $inventory['duplicate_file_count']);
        $this->assertSame('copy/journal_01-01-2021_31-12-2025.xlsx', $byPath['journal_01-01-2021_31-12-2025.xlsx']['duplicate_of']);
    }

    public function test_year_filter_keeps_overlapping_sources_and_yearless_shared_metadata(): void
    {
        $this->write('journal_01-01-2021_31-12-2025.xlsx', 'journal');
        $this->write('PENJUALAN/sales_list_01-01-2024_31-12-2024.xlsx', 'sales');
        $this->write('json/lampiran.json', '[]');

        $inventory = (new LegacyImportSourceCatalog())->scan($this->directory, 2022);
        $paths = array_column($inventory['files'], 'path');

        $this->assertContains('journal_01-01-2021_31-12-2025.xlsx', $paths);
        $this->assertContains('json/lampiran.json', $paths);
        $this->assertNotContains('PENJUALAN/sales_list_01-01-2024_31-12-2024.xlsx', $paths);
    }

    public function test_missing_or_unreadable_directory_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        (new LegacyImportSourceCatalog())->scan($this->directory.DIRECTORY_SEPARATOR.'missing');
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $parent = dirname($path);
        if (! is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($path, $contents);
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
