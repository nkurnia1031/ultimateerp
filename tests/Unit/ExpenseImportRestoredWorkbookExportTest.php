<?php

namespace Tests\Unit;

use App\Exports\ExpenseImportRestoredWorkbookExport;
use PHPUnit\Framework\TestCase;

class ExpenseImportRestoredWorkbookExportTest extends TestCase
{
    public function test_it_rebuilds_workbook_rows_and_headers_from_stored_upload_payloads(): void
    {
        $export = new ExpenseImportRestoredWorkbookExport([
            ['LOCATION' => 'Kantor', 'REFERENCE NO' => 'REF-001', 'TOTAL AMOUNT' => 125000],
            ['LOCATION' => 'Gudang', 'REFERENCE NO' => 'REF-002', 'TOTAL AMOUNT' => 45000, 'TAGS' => 'Operasional'],
        ]);

        $this->assertSame(['LOCATION', 'REFERENCE NO', 'TOTAL AMOUNT', 'TAGS'], $export->headings());
        $this->assertSame([
            ['Kantor', 'REF-001', 125000, null],
            ['Gudang', 'REF-002', 45000, 'Operasional'],
        ], $export->array());
        $this->assertSame('Expense Import', $export->title());
    }
}
