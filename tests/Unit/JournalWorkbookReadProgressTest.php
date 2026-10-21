<?php

namespace Tests\Unit;

use Modules\Accounting\Http\Controllers\JournalEntryController;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionClass;
use Tests\TestCase;

class JournalWorkbookReadProgressTest extends TestCase
{
    public function test_reader_reports_progress_before_and_after_each_5000_row_chunk(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'journal-import-progress-'.uniqid('', true).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $rows = [];

        for ($row = 1; $row <= 10001; $row++) {
            $rows[] = [$row, 'Account', 'Journal line', 0, 0];
        }

        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $controllerReflection = new ReflectionClass(JournalEntryController::class);
            $controller = $controllerReflection->newInstanceWithoutConstructor();
            $reader = $controllerReflection->getMethod('readJournalWorkbookRows');
            $progress = [];
            $rowCount = 0;

            foreach ($reader->invoke($controller, $path, function (array $checkpoint) use (&$progress) {
                $progress[] = $checkpoint;
            }) as $row) {
                $rowCount++;
            }

            $messages = array_column($progress, 'message');

            $this->assertSame(10001, $rowCount);
            $this->assertContains('Menyiapkan baris 1-5000 / 10001 dari workbook Excel.', $messages);
            $this->assertContains('Menganalisis baris 5000 / 10001 dari workbook Excel.', $messages);
            $this->assertContains('Menyiapkan baris 5001-10000 / 10001 dari workbook Excel.', $messages);
            $this->assertContains('Menganalisis baris 10000 / 10001 dari workbook Excel.', $messages);
            $this->assertContains('Menyiapkan baris 10001-10001 / 10001 dari workbook Excel.', $messages);
            $this->assertContains('Menganalisis baris 10001 / 10001 dari workbook Excel.', $messages);
        } finally {
            @unlink($path);
        }
    }
}
