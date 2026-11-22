<?php

namespace Modules\Crm\Services;

use App\Contact;
use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Crm\Entities\SuratKontrak;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class SuratKontrakImportBatchService
{
    public const IMPORTER_KEY = 'crm_surat_kontrak';

    public function __construct(private UserImportBatchService $batches)
    {
    }

    public function history(Request $request, int $businessId, bool $canRevert): array
    {
        $table = $this->batches->dataTable($request, $businessId, self::IMPORTER_KEY);
        $table['data'] = collect($table['data'])->map(function (array $row) use ($canRevert) {
            $row['detail_url'] = route('crm.surat-kontrak-import.detail', $row['id']);
            $row['restore_url'] = $row['has_snapshot'] ? route('crm.surat-kontrak-import.source', $row['id']) : null;
            $row['retry_url'] = route('crm.surat-kontrak-import.retry', $row['id']);
            $row['revert_url'] = route('crm.surat-kontrak-import.revert', $row['id']);
            $row['can_revert'] = $canRevert && in_array($row['status'], ['imported', 'partial'], true);
            $row['can_retry'] = in_array($row['status'], ['failed', 'partial'], true) && $row['has_snapshot'];

            return $row;
        })->values()->all();

        return $table;
    }

    public function detail(int $batchId, int $businessId): array
    {
        $batch = $this->batches->findForBusiness($batchId, $businessId, self::IMPORTER_KEY);

        return [
            'batch' => [
                'id' => (int) $batch->id,
                'file_name' => (string) $batch->file_name,
                'status' => (string) $batch->status,
                'progress_current' => (int) $batch->progress_current,
                'progress_total' => (int) $batch->progress_total,
                'progress_percent' => (int) $batch->progress_percent,
                'summary' => json_decode((string) $batch->summary_json, true) ?: [],
                'error_message' => $batch->error_message,
                'created_at' => optional($batch->created_at)->toDateTimeString(),
            ],
            'results' => $batch->results_path ? $this->batches->readPayload($batch, 'results') : [],
        ];
    }

    public function process(UserImportBatch $batch): void
    {
        $workbookPath = $this->batches->restoredWorkbookPath($batch);
        $spreadsheet = null;
        try {
            $spreadsheet = IOFactory::load($workbookPath);
            $previous = $batch->results_path ? $this->batches->readPayload($batch, 'results') : [];
            $outcomes = collect($previous['row_outcomes'] ?? [])->keyBy(fn ($row) => $this->rowKey($row));
            $createdContacts = collect($previous['created_contacts'] ?? [])->keyBy('id');
            $total = 0;
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $total += max(0, $sheet->getHighestDataRow() - 1);
            }

            $batch->forceFill([
                'status' => 'analyzing',
                'stage' => 'importing_contracts',
                'progress_current' => 0,
                'progress_total' => $total,
                'progress_percent' => $total > 0 ? 1 : 100,
                'started_at' => $batch->started_at ?: now(),
                'heartbeat_at' => now(),
                'last_message' => 'Workbook kontrak sedang diproses.',
                'error_message' => null,
            ])->save();

            $current = 0;
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheetName = $sheet->getTitle();
                $header = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, true, true, false)[0] ?? [];
                if (! count(array_filter($header, fn ($value) => trim((string) $value) !== '')) && $sheet->getHighestDataRow() <= 1) {
                    continue;
                }
                $columns = $this->columnMap($header);
                if (! isset($columns['pekerjaan'], $columns['contact_id'])) {
                    throw new \RuntimeException('Sheet '.$sheetName.' wajib memiliki kolom Pekerjaan dan Contact ID.');
                }

                for ($rowNumber = 2; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
                    $key = $sheetName.'#'.$rowNumber;
                    $prior = $outcomes->get($key);
                    if ($prior && in_array($prior['status'] ?? '', ['created', 'skipped'], true)) {
                        $current++;
                        continue;
                    }

                    $cells = $sheet->rangeToArray('A'.$rowNumber.':'.$sheet->getHighestColumn().$rowNumber, null, true, true, false)[0] ?? [];
                    if (! count(array_filter($cells, fn ($value) => $value !== null && trim((string) $value) !== ''))) {
                        $outcomes->put($key, ['sheet' => $sheetName, 'source_row' => $rowNumber, 'status' => 'skipped', 'message' => 'Baris kosong.']);
                        $current++;
                        $this->saveProgress($batch, $outcomes, $createdContacts, $current, $total);
                        continue;
                    }

                    try {
                        $values = [];
                        foreach ($columns as $field => $index) {
                            $values[$field] = $cells[$index] ?? null;
                        }
                        $contract = DB::transaction(function () use ($batch, $values, $sheetName, $rowNumber, $createdContacts) {
                            $number = trim((string) ($values['nomor'] ?? ''));
                            if ($number === '') {
                                $number = sprintf('SPK-%d-%d-%s-%05d', $batch->business_id, $batch->id, substr(sha1($sheetName), 0, 5), $rowNumber);
                            }
                            $existing = SuratKontrak::where('business_id', $batch->business_id)
                                ->where('import_batch', $batch->id)
                                ->where('nomor', $number)
                                ->first();
                            if ($existing) {
                                return $existing->fresh();
                            }

                            $jobName = trim((string) ($values['pekerjaan'] ?? ''));
                            $contactName = trim((string) ($values['contact_id'] ?? ''));
                            if ($jobName === '' || $contactName === '') {
                                throw new \InvalidArgumentException('Kolom Pekerjaan dan Contact ID wajib diisi.');
                            }

                            $contact = Contact::where('business_id', $batch->business_id)
                                ->where('supplier_business_name', $contactName)
                                ->first();
                            $createdContact = false;
                            if (! $contact) {
                                $contact = Contact::create([
                                    'supplier_business_name' => $contactName,
                                    'business_id' => $batch->business_id,
                                    'type' => 'customer',
                                    'name' => $contactName,
                                    'email' => '-',
                                    'mobile' => '-',
                                    'created_by' => $batch->created_by,
                                ]);
                                $createdContact = true;
                            }

                            $contract = SuratKontrak::create([
                                'nomor' => $number,
                                'contact_id' => $contact->id,
                                'jabatan_pihak_2' => $this->nullableString($values['jabatan_pihak_2'] ?? null),
                                'pekerjaan' => $jobName,
                                'kapal_mv' => $this->nullableString($values['kapal_mv'] ?? null),
                                'tonase' => is_numeric($values['tonase'] ?? null) ? round((float) $values['tonase'], -1) : null,
                                'lokasi_muat' => $this->nullableString($values['lokasi_muat'] ?? null),
                                'lokasi_bongkar' => $this->nullableString($values['lokasi_bongkar'] ?? null),
                                'mulai_kerja' => $this->dateValue($values['mulai_kerja'] ?? null),
                                'selesai' => $this->dateValue($values['selesai'] ?? null),
                                'jangka_waktu' => $this->nullableString($values['jangka_waktu'] ?? null),
                                'notes' => $this->nullableString($values['notes'] ?? null),
                                'business_id' => $batch->business_id,
                                'import_batch' => $batch->id,
                                'atribut_tambahan' => json_encode([
                                    'import_batch_id' => $batch->id,
                                    'source_sheet' => $sheetName,
                                    'source_row' => $rowNumber,
                                    'created_contact_id' => $createdContact ? $contact->id : null,
                                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            ]);

                            if ($createdContact) {
                                $createdContacts->put($contact->id, [
                                    'id' => (int) $contact->id,
                                    'attributes' => $this->modelAttributes($contact->fresh()),
                                    'updated_at' => (string) $contact->fresh()->updated_at,
                                ]);
                            }

                            return $contract->fresh();
                        });

                        $metadata = json_decode((string) $contract->atribut_tambahan, true) ?: [];
                        $createdContactId = (int) ($metadata['created_contact_id'] ?? 0);
                        if ($createdContactId > 0 && ! $createdContacts->has($createdContactId)) {
                            $contact = Contact::where('business_id', $batch->business_id)->find($createdContactId);
                            if ($contact) {
                                $createdContacts->put($contact->id, [
                                    'id' => (int) $contact->id,
                                    'attributes' => $this->modelAttributes($contact),
                                    'updated_at' => (string) $contact->updated_at,
                                ]);
                            }
                        }
                        $outcomes->put($key, [
                            'sheet' => $sheetName,
                            'source_row' => $rowNumber,
                            'status' => 'created',
                            'record_id' => (int) $contract->id,
                            'record_label' => (string) $contract->nomor,
                            'attributes' => $this->modelAttributes($contract),
                            'updated_at' => (string) $contract->updated_at,
                        ]);
                    } catch (\Throwable $exception) {
                        $outcomes->put($key, [
                            'sheet' => $sheetName,
                            'source_row' => $rowNumber,
                            'status' => 'failed',
                            'error' => mb_substr($exception->getMessage(), 0, 2000),
                        ]);
                    }

                    $current++;
                    $this->saveProgress($batch, $outcomes, $createdContacts, $current, $total);
                }
            }

            $values = $outcomes->values()->all();
            $summary = [
                'source_rows' => count($values),
                'imported_rows' => $outcomes->where('status', 'created')->count(),
                'skipped_rows' => $outcomes->where('status', 'skipped')->count(),
                'failed_rows' => $outcomes->where('status', 'failed')->count(),
            ];
            $status = $summary['failed_rows'] > 0
                ? ($summary['imported_rows'] > 0 ? 'partial' : 'failed')
                : 'imported';
            $this->batches->storePayload($batch, 'results', [
                'summary' => $summary,
                'row_outcomes' => $values,
                'created_contacts' => $createdContacts->values()->all(),
            ]);
            $batch->forceFill([
                'status' => $status,
                'stage' => $status,
                'progress_current' => $current,
                'progress_total' => $total,
                'progress_percent' => 100,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'search_text' => $batch->file_name.' '.$summary['imported_rows'].' '.$summary['failed_rows'],
                'last_message' => $status === 'failed' ? 'Tidak ada baris kontrak yang berhasil diimpor.' : 'Import kontrak selesai.',
                'error_message' => $summary['failed_rows'] > 0 ? 'Sebagian baris gagal. Periksa detail batch.' : null,
                'heartbeat_at' => null,
                'completed_at' => now(),
                'failed_at' => $status === 'failed' ? now() : null,
            ])->save();
        } finally {
            if ($spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
            @unlink($workbookPath);
        }
    }

    public function revert(UserImportBatch $batch): void
    {
        abort_unless(in_array($batch->status, ['imported', 'partial'], true), 422, 'Batch kontrak ini belum dapat direvert.');
        $results = $this->batches->readPayload($batch, 'results');
        DB::transaction(function () use ($batch, $results) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)
                ->where('importer_key', self::IMPORTER_KEY)->lockForUpdate()->findOrFail($batch->id);
            abort_unless(in_array($locked->status, ['imported', 'partial'], true), 422, 'Status batch kontrak sudah berubah.');

            $contracts = collect($results['row_outcomes'] ?? [])->where('status', 'created');
            foreach ($contracts as $outcome) {
                $contract = SuratKontrak::where('business_id', $batch->business_id)
                    ->lockForUpdate()->find((int) ($outcome['record_id'] ?? 0));
                abort_if(! $contract, 422, 'Kontrak hasil batch tidak ditemukan sehingga revert diblokir.');
                abort_if($this->modelAttributes($contract) !== ($outcome['attributes'] ?? [])
                    || (string) $contract->updated_at !== (string) ($outcome['updated_at'] ?? ''),
                    422, 'Kontrak '.$contract->nomor.' telah berubah setelah import.');
                if (DB::getSchemaBuilder()->hasTable('document_and_notes')
                    && DB::table('document_and_notes')->where('notable_type', SuratKontrak::class)->where('notable_id', $contract->id)->exists()) {
                    abort(422, 'Kontrak '.$contract->nomor.' memiliki dokumen atau catatan setelah import.');
                }
            }

            foreach (($results['created_contacts'] ?? []) as $snapshot) {
                $contact = Contact::where('business_id', $batch->business_id)->lockForUpdate()->find((int) ($snapshot['id'] ?? 0));
                abort_if(! $contact, 422, 'Contact yang dibuat batch tidak ditemukan.');
                abort_if($this->modelAttributes($contact) !== ($snapshot['attributes'] ?? [])
                    || (string) $contact->updated_at !== (string) ($snapshot['updated_at'] ?? ''),
                    422, 'Contact '.$contact->name.' telah berubah setelah import.');
                if (DB::table('transactions')->where('contact_id', $contact->id)->exists()
                    || SuratKontrak::where('contact_id', $contact->id)->where('import_batch', '<>', $batch->id)->exists()
                    || (DB::getSchemaBuilder()->hasTable('document_and_notes')
                        && DB::table('document_and_notes')->where('notable_type', Contact::class)->where('notable_id', $contact->id)->exists())) {
                    abort(422, 'Contact '.$contact->name.' sudah digunakan transaksi atau catatan lain.');
                }
            }

            foreach ($contracts as $outcome) {
                SuratKontrak::where('business_id', $batch->business_id)->whereKey($outcome['record_id'])->delete();
            }
            foreach (($results['created_contacts'] ?? []) as $snapshot) {
                Contact::where('business_id', $batch->business_id)->whereKey($snapshot['id'])->delete();
            }
            $locked->forceFill([
                'status' => 'reverted', 'stage' => 'reverted', 'progress_percent' => 100,
                'last_message' => 'Kontrak hasil batch berhasil dikembalikan.', 'error_message' => null,
                'heartbeat_at' => null, 'reverted_at' => now(),
            ])->save();
        });
    }

    public function fail(UserImportBatch $batch, \Throwable $exception): void
    {
        $results = $batch->results_path ? $this->batches->readPayload($batch, 'results') : [];
        $message = mb_substr($exception->getMessage(), 0, 65000);
        $results['row_outcomes'] = $results['row_outcomes'] ?? [[
            'sheet' => null,
            'source_row' => null,
            'status' => 'failed',
            'error' => $message,
        ]];
        try {
            $this->batches->storePayload($batch, 'results', $results);
        } catch (\Throwable $storageException) {
            report($storageException);
        }
        $batch->forceFill([
            'status' => 'failed',
            'stage' => 'failed',
            'last_message' => 'Import kontrak gagal.',
            'error_message' => $message,
            'heartbeat_at' => null,
            'completed_at' => now(),
            'failed_at' => now(),
        ])->save();
    }

    private function saveProgress(UserImportBatch $batch, $outcomes, $contacts, int $current, int $total): void
    {
        $rows = $outcomes->values()->all();
        $summary = [
            'source_rows' => count($rows),
            'imported_rows' => $outcomes->where('status', 'created')->count(),
            'skipped_rows' => $outcomes->where('status', 'skipped')->count(),
            'failed_rows' => $outcomes->where('status', 'failed')->count(),
        ];
        $this->batches->storePayload($batch, 'results', ['summary' => $summary, 'row_outcomes' => $rows, 'created_contacts' => $contacts->values()->all()]);
        $batch->forceFill([
            'progress_current' => $current,
            'progress_total' => $total,
            'progress_percent' => $total > 0 ? min(99, (int) floor($current / $total * 100)) : 100,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'heartbeat_at' => now(),
            'last_message' => 'Memproses baris '.$current.' dari '.$total.'.',
        ])->save();
    }

    private function columnMap(array $header): array
    {
        $aliases = [
            'nomor' => 'nomor', 'nomor kontrak' => 'nomor', 'contact id' => 'contact_id', 'contact_id' => 'contact_id',
            'jabatan pihak 2' => 'jabatan_pihak_2', 'jabatan_pihak_2' => 'jabatan_pihak_2', 'pekerjaan' => 'pekerjaan',
            'kapal / mv' => 'kapal_mv', 'kapal mv' => 'kapal_mv', 'kapal_mv' => 'kapal_mv', 'tonase' => 'tonase',
            'lokasi muat' => 'lokasi_muat', 'lokasi_muat' => 'lokasi_muat', 'lokasi bongkar' => 'lokasi_bongkar', 'lokasi_bongkar' => 'lokasi_bongkar',
            'mulai kerja' => 'mulai_kerja', 'mulai_kerja' => 'mulai_kerja', 'selesai' => 'selesai',
            'jangka waktu' => 'jangka_waktu', 'jangka_waktu' => 'jangka_waktu', 'notes' => 'notes', 'catatan' => 'notes',
        ];
        $columns = [];
        foreach ($header as $index => $name) {
            $key = mb_strtolower(trim((string) $name));
            if (isset($aliases[$key])) {
                $columns[$aliases[$key]] = $index;
            }
        }

        return $columns;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return is_numeric($value) && (float) $value > 0
                ? Date::excelToDateTimeObject((float) $value)->format('Y-m-d')
                : Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('Format tanggal tidak valid: '.$value);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function rowKey(array $row): string
    {
        return (string) ($row['sheet'] ?? '').'#'.(int) ($row['source_row'] ?? 0);
    }

    private function modelAttributes($model): array
    {
        return json_decode(json_encode($model->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
