<?php

namespace Modules\AssetManagement\Services;

use App\Contact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingDocumentService;

class HeavyEquipmentVendorLoadingImportBatchService
{
    public function __construct(
        private HeavyEquipmentVendorLoadingImportService $importService,
        private HeavyEquipmentVendorLoadingService $loadingService,
        private HeavyEquipmentVendorLoadingDocumentService $documentService,
        private HeavyEquipmentVendorLoadingImportHistoryService $historyService
    ) {
    }

    public function process(int $batchId): void
    {
        $batch = DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->first();
        if (! $batch || $batch->status !== 'queued_import' || ! $batch->preview_path) {
            return;
        }

        DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->update([
            'status' => 'importing',
            'stage' => 'saving_sheets',
            'last_message' => 'Import berjalan di latar belakang.',
            'processed_sheets' => 0,
            'processed_rows' => 0,
            'last_heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
        $this->historyService->syncFromDomainBatch($batchId);

        try {
            $preview = json_decode(Storage::disk('local')->get($batch->preview_path), true, 512, JSON_THROW_ON_ERROR);
            $options = json_decode($batch->overrides_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            $permissions = json_decode($batch->permissions_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            $results = json_decode($batch->results_json ?: '{}', true) ?: [];
            $sheets = $preview['sheets'] ?? [];

            $completedRows = array_sum(array_map(fn ($entry) => (int) ($entry['rows'] ?? 0), array_values($results)));
            foreach ($sheets as $sheetIndex => $sheet) {
                $sheetKey = (string) ($sheet['sheet_key'] ?? '');
                if ($sheetKey === '' || isset($results[$sheetKey])) {
                    continue;
                }

                $overrides = $options['sheet_overrides'][$sheetKey] ?? [];
                $rowOverrides = $options['row_overrides'] ?? [];
                $missingDate = $options['sheet_missing_dates'][$sheetKey] ?? null;
                $result = null;
                try {
                    $result = DB::transaction(function () use ($batch, $batchId, $sheet, $sheetKey, $overrides, $rowOverrides, $missingDate, $permissions, $completedRows, $results) {
                        $businessId = (int) $batch->business_id;
                        $userId = (int) $batch->created_by;
                        $metadata = $sheet['metadata'] ?? [];

                        $supplierId = $overrides['supplier_id'] ?? ($metadata['supplier_id'] ?? null);
                        if (! empty($overrides['create_supplier'])) {
                            if (empty($permissions['can_create_supplier'])) {
                                throw new \RuntimeException('Hak supplier.create tidak tersedia untuk membuat supplier baru.');
                            }
                            $supplier = $this->loadingService->createMinimalSupplier((string) ($metadata['supplier_label'] ?? ''), $businessId, $userId);
                        } else {
                            $supplier = $supplierId
                                ? Contact::where('business_id', $businessId)->whereIn('type', ['supplier', 'both'])->where('contact_status', 'active')->find($supplierId)
                                : null;
                        }
                        if (! $supplier) {
                            throw new \RuntimeException('Pilih Supplier/Bendera yang valid untuk sheet '.$sheet['title'].'.');
                        }

                        $customerId = $overrides['customer_id'] ?? ($metadata['customer_id'] ?? null);
                        $customer = $customerId
                            ? Contact::where('business_id', $businessId)->whereIn('type', ['customer', 'both'])->where('contact_status', 'active')->find($customerId)
                            : null;
                        if (! $customer && trim((string) ($metadata['customer_label'] ?? '')) !== '') {
                            $customer = $this->loadingService->findCustomer($businessId, (string) $metadata['customer_label']);
                        }
                        if (! $customer && trim((string) ($metadata['customer_label'] ?? '')) !== '') {
                            if (empty($permissions['can_create_customer'])) {
                                throw new \RuntimeException('Customer '.$metadata['customer_label'].' belum terdaftar dan izin customer.create tidak tersedia.');
                            }
                            $customer = $this->loadingService->createMinimalCustomer((string) $metadata['customer_label'], $businessId, $userId);
                        }
                        if (! $customer) {
                            throw new \RuntimeException('Pilih Customer yang valid untuk sheet '.$sheet['title'].'.');
                        }

                        $routeStatus = $metadata['route_match_status'] ?? 'not_set';
                        $routeChoiceProvided = array_key_exists('route_id', $overrides);
                        $routeChoice = $routeChoiceProvided ? $overrides['route_id'] : ($metadata['route_id'] ?? null);
                        if (in_array($routeStatus, ['conflict', 'unmatched'], true) && ! $routeChoiceProvided) {
                            $sources = implode(', ', $metadata['route_source_labels'] ?? []);
                            throw new \RuntimeException('Tentukan satu Rute Dokumen aktif atau pilih tanpa rute untuk sheet '.$sheet['title'].($sources !== '' ? ' (sumber: '.$sources.').' : '.'));
                        }
                        $route = null;
                        if ($routeChoice !== null && $routeChoice !== '' && $routeChoice !== 'none') {
                            $route = HeavyEquipmentRoute::where('business_id', $businessId)->where('is_active', true)->find((int) $routeChoice);
                            if (! $route) {
                                throw new \RuntimeException('Rute dokumen yang dipilih tidak aktif atau bukan milik business ini.');
                            }
                        }

                        if ($missingDate !== null && $missingDate !== '') {
                            $dateCheck = Validator::make(['date' => $missingDate], ['date' => 'required|date_format:Y-m-d']);
                            if ($dateCheck->fails()) {
                                throw new \RuntimeException('Tanggal pengisi untuk sheet '.$sheet['title'].' tidak valid.');
                            }
                        }

                        $preparedLines = [];
                        $preparedLineSources = [];
                        $rowErrors = [];
                        $lineResults = [];
                        $sheetRows = $sheet['rows'] ?? [];
                        foreach ($sheetRows as $rowIndex => $row) {
                            if ($rowIndex % 5 === 0 || $rowIndex + 1 === count($sheetRows)) {
                                Cache::put($this->progressKey($batchId), [
                                    'processed_sheets' => count($results),
                                    'processed_rows' => min((int) $batch->total_rows, $completedRows + $rowIndex + 1),
                                    'stage' => 'saving_rows',
                                    'heartbeat_at' => now()->timestamp,
                                    'last_message' => 'Menyimpan sheet '.$sheet['title'].' — tiket '.($rowIndex + 1).' dari '.count($sheetRows).'.',
                                ], now()->addHours(3));
                            }
                            $rowKey = (string) ($row['row_key'] ?? '');
                            $dateOverride = $rowOverrides[$rowKey]['loading_date'] ?? null;
                            $dateWasCorrected = false;
                            if ($dateOverride !== null && $dateOverride !== '') {
                                $dateCheck = Validator::make(['date' => $dateOverride], ['date' => 'required|date_format:Y-m-d']);
                                if ($dateCheck->fails()) {
                                    throw new \RuntimeException('Tanggal pada tiket '.($row['ticket_no'] ?? $row['source_row']).' tidak valid.');
                                }
                                $row['loading_date'] = Carbon::createFromFormat('!Y-m-d', $dateOverride)->toDateString();
                                $row['load_date'] = $row['load_date'] ?: $row['loading_date'];
                                $row['loading_date_invalid'] = false;
                                $row['date_missing'] = false;
                                $dateWasCorrected = true;
                            } elseif (empty($row['loading_date']) && $missingDate) {
                                $row['loading_date'] = Carbon::createFromFormat('!Y-m-d', $missingDate)->toDateString();
                                $row['load_date'] = $row['load_date'] ?: $row['loading_date'];
                                $row['date_missing'] = false;
                                $row['loading_date_invalid'] = false;
                                $dateWasCorrected = true;
                            }

                            $row['row_errors'] = array_values(array_filter($row['row_errors'] ?? [], function ($message) use ($dateWasCorrected) {
                                $message = strtolower((string) $message);
                                if (str_contains($message, 'bendera/supplier') || str_contains($message, 'customer ')) {
                                    return false;
                                }
                                if ($dateWasCorrected && str_contains($message, 'tanggal loading tidak terbaca')) {
                                    return false;
                                }
                                return true;
                            }));
                            if ($row['row_errors'] !== [] || empty($row['loading_date'])) {
                                $reason = implode(' ', $row['row_errors']) ?: 'Tanggal loading kosong.';
                                $rowErrors[] = "Sheet {$sheet['title']} baris {$row['source_row']}: {$reason}";
                                $lineResults[] = [
                                    'source_sheet' => $sheet['source_sheet'] ?? $sheet['title'],
                                    'source_row' => $row['source_row'] ?? null,
                                    'ticket_no' => $row['ticket_no'] ?? null,
                                    'status' => 'skipped',
                                    'error' => $reason,
                                ];
                                continue;
                            }

                            $row['supplier_id'] = $supplier->id;
                            $row['supplier_name_snapshot'] = Contact::supplierDisplayName($supplier, false);
                            $row['supplier_label'] = $row['supplier_name_snapshot'];
                            $row['customer_id'] = $customer->id;
                            $row['customer_label'] = $customer->name;
                            $row['goods_name'] = $metadata['goods_name'] ?? null;
                            $row['goods_type'] = $metadata['goods_type'] ?? null;
                            $row['vessel_name'] = $metadata['vessel_name'] ?? null;
                            $row['destination'] = $metadata['destination'] ?? null;
                            $preparedLines[] = $this->importService->prepareRow($row, $businessId, [], [], null, false, (int) $customer->id);
                            $preparedLineSources[] = [
                                'source_sheet' => $row['source_sheet'] ?? $sheet['title'],
                                'source_row' => $row['source_row'] ?? null,
                                'ticket_no' => $row['ticket_no'] ?? null,
                            ];
                        }

                        if ($preparedLines === []) {
                            throw new \RuntimeException(implode(' | ', array_slice($rowErrors, 0, 4)) ?: 'Tidak ada line siap pada sheet ini.');
                        }

                        $documentMetadata = array_merge($metadata, [
                            'import_batch_id' => $batchId,
                            'supplier_id' => $supplier->id,
                            'supplier_name_snapshot' => Contact::supplierDisplayName($supplier, false),
                            'customer_id' => $customer->id,
                            'customer_name_snapshot' => $customer->name,
                            'route_id' => $route?->id,
                            'route_name_snapshot' => $route?->name,
                            'source_workbook' => $sheet['source_workbook'] ?? null,
                            'source_sheet' => $sheet['source_sheet'] ?? $sheet['title'],
                            'source_file_hash' => $sheet['source_file_hash'] ?? null,
                        ]);
                        $document = $this->documentService->createImported($documentMetadata, $preparedLines, $businessId, $userId);
                        $createdLines = $document->lines->values();
                        foreach ($preparedLineSources as $lineIndex => $source) {
                            $line = $createdLines->get($lineIndex);
                            $lineResults[] = array_merge($source, [
                                'status' => 'imported',
                                'document_id' => (int) $document->id,
                                'document_no' => $document->document_no,
                                'line_id' => $line ? (int) $line->id : null,
                            ]);
                        }
                        foreach ($lineResults as &$lineResult) {
                            $lineResult['document_id'] = (int) $document->id;
                            $lineResult['document_no'] = $document->document_no;
                        }
                        unset($lineResult);
                        usort($lineResults, fn ($left, $right) => ((int) ($left['source_row'] ?? 0)) <=> ((int) ($right['source_row'] ?? 0)));

                        return [
                            'documents' => $document ? 1 : 0,
                            'document_id' => (int) $document->id,
                            'document_no' => $document->document_no,
                            'lines' => count($preparedLines),
                            'skipped' => count($rowErrors),
                            'errors' => $rowErrors,
                            'line_results' => $lineResults,
                        ];
                    });
                } catch (\Throwable $e) {
                    report($e);
                    $result = ['documents' => 0, 'lines' => 0, 'skipped' => count($sheet['rows'] ?? []), 'errors' => ['Sheet '.($sheet['title'] ?? $sheetKey).': '.$e->getMessage()]];
                    $result['line_results'] = array_map(fn ($row) => [
                        'source_sheet' => $row['source_sheet'] ?? $sheet['title'] ?? $sheetKey,
                        'source_row' => $row['source_row'] ?? null,
                        'ticket_no' => $row['ticket_no'] ?? null,
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ], $sheet['rows'] ?? []);
                }
                $result['rows'] = count($sheet['rows'] ?? []);

                DB::transaction(function () use ($batchId, $sheet, $sheetKey, $result, &$results, $sheetIndex) {
                    $lockedBatch = DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->lockForUpdate()->first();
                    $currentResults = json_decode($lockedBatch->results_json ?: '{}', true) ?: [];
                    if (isset($currentResults[$sheetKey])) {
                        $results = $currentResults;
                        return;
                    }
                    $currentResults[$sheetKey] = array_merge(['title' => $sheet['title'] ?? 'Sheet '.($sheetIndex + 1)], $result);
                    $all = array_values($currentResults);
                    $rowTotal = array_sum(array_map(fn ($entry) => (int) ($entry['rows'] ?? 0), $all));
                    $docs = array_sum(array_column($all, 'documents'));
                    $lines = array_sum(array_column($all, 'lines'));
                    $skipped = array_sum(array_column($all, 'skipped'));
                    $errors = array_merge(...array_map(fn ($entry) => $entry['errors'] ?? [], $all));
                    $results = $currentResults;
                    DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->update([
                        'results_json' => json_encode($currentResults, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        'processed_sheets' => count($currentResults),
                        'processed_rows' => min((int) $lockedBatch->total_rows, $rowTotal),
                        'imported_documents' => $docs,
                        'imported_lines' => $lines,
                        'skipped_rows' => $skipped,
                        'errors_json' => json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        'last_message' => 'Sheet '.($sheet['title'] ?? '—').' selesai: '.(int) ($result['lines'] ?? 0).' line disimpan, '.(int) ($result['skipped'] ?? 0).' dilewati.',
                        'last_heartbeat_at' => now(),
                        'updated_at' => now(),
                    ]);
                    Cache::put($this->progressKey($batchId), [
                        'processed_sheets' => count($currentResults),
                        'processed_rows' => min((int) $lockedBatch->total_rows, $rowTotal),
                        'stage' => 'saving_sheets',
                        'heartbeat_at' => now()->timestamp,
                        'last_message' => 'Sheet '.($sheet['title'] ?? '—').' selesai: '.(int) ($result['lines'] ?? 0).' line disimpan, '.(int) ($result['skipped'] ?? 0).' dilewati.',
                    ], now()->addHours(3));
                });
                $this->historyService->storePayloadForDomainBatch($batchId, 'results', $results);
                $this->historyService->syncFromDomainBatch($batchId);
                $completedRows += count($sheet['rows'] ?? []);
            }

            $final = DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->first();
            $hasIssues = (int) $final->skipped_rows > 0 || ! empty(json_decode($final->errors_json ?: '[]', true));
            $status = (int) $final->imported_documents === 0 ? 'failed' : ($hasIssues ? 'partial' : 'imported');
            DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->update([
                'status' => $status,
                'stage' => $status,
                'last_message' => $status === 'imported' ? 'Import selesai.' : ($status === 'partial' ? 'Import selesai dengan beberapa baris/sheet yang dilewati.' : 'Tidak ada dokumen yang berhasil diimpor.'),
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
            $this->historyService->syncFromDomainBatch($batchId);
            Cache::forget($this->progressKey($batchId));
        } catch (\Throwable $e) {
            report($e);
            $this->markFailed($batchId, $e->getMessage());
        }
    }

    public function markFailed(int $batchId, string $message): void
    {
        $batch = DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->first();
        if (! $batch || in_array($batch->status, ['imported', 'partial'], true)) {
            return;
        }
        $status = (int) $batch->imported_documents > 0 ? 'partial' : 'failed';
        DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->update([
            'status' => $status,
            'stage' => $status,
            'last_message' => 'Import berhenti karena terjadi kesalahan.',
            'error_message' => $message,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
        $this->historyService->syncFromDomainBatch($batchId);
        Cache::forget($this->progressKey($batchId));
    }

    private function progressKey(int $batchId): string
    {
        return 'vendor_loading_import_batch:'.$batchId.':progress';
    }
}
