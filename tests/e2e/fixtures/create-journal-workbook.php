<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$repoRoot = dirname(__DIR__, 3);
$sourceNumber = preg_replace('/[^A-Za-z0-9_-]/', '-', $argv[1] ?? uniqid('CI-', true));
$directory = $repoRoot.'/storage/app/e2e-fixtures';

if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
    throw new RuntimeException('Unable to create the E2E workbook fixture directory.');
}

$path = $directory.'/journal-import-'.$sourceNumber.'.xlsx';
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray([
    ['GitHub E2E Journal Import fixture', null, null, null, null],
    [null, null, null, null, null],
    ['01/05/2026 - 01/05/2026', null, null, null, null],
    ['Nomor Akun', 'Akun', 'Deskripsi', 'Debit', 'Kredit'],
    [null, null, null, null, null],
    [null, null, null, null, null],
    [null, 'Journal Entry #'.$sourceNumber.' | 01/05/2026', null, null, null],
    ['CI-1000', 'CI Test Cash', 'E2E journal debit', 1250, 0],
    ['CI-3000', 'CI Test Equity', 'E2E journal credit', 0, 1250],
], null, 'A1');

(new Xlsx($spreadsheet))->save($path);
$spreadsheet->disconnectWorksheets();

fwrite(STDOUT, $path.PHP_EOL);
