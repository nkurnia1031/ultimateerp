<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$repoRoot = dirname(__DIR__, 3);
$sourceId = preg_replace('/[^A-Za-z0-9_-]/', '-', $argv[1] ?? uniqid('CI-SPB-', true));
$directory = $repoRoot.'/storage/app/e2e-fixtures';

if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
    throw new RuntimeException('Unable to create the SPB E2E workbook fixture directory.');
}

$path = $directory.'/spb-import-'.$sourceId.'.xlsx';
$spreadsheet = new Spreadsheet();
$spreadsheet->getActiveSheet()->fromArray([
    ['No Polisi', 'No Tiket', 'Tanggal muat', 'Tanggal bongkar', 'Pks', 'Pabrik', 'Diterima', 'OA', 'Driver'],
    ['CI-SPB-TRUCK', $sourceId, '2026-11-20', '2026-11-20', 'CI PKS', 'CI Pabrik', 10, 1000, 'CI SPB Driver'],
], null, 'A1');

(new Xlsx($spreadsheet))->save($path);
$spreadsheet->disconnectWorksheets();

fwrite(STDOUT, $path.PHP_EOL);
