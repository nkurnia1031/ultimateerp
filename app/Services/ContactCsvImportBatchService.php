<?php

namespace App\Services;

use App\Contact;
use App\Transaction;
use App\TransactionPayment;
use App\UserImportBatch;
use App\Utils\TransactionUtil;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ContactCsvImportBatchService
{
    public const IMPORTER_KEY = 'contacts_csv';

    public function __construct(
        private UserImportBatchService $batches,
        private Util $commonUtil,
        private TransactionUtil $transactionUtil,
        private ContactImportDependencyChecker $contactDependencies
    ) {
    }

    public function history(Request $request, int $businessId, bool $canRevert): array
    {
        $table = $this->batches->dataTable($request, $businessId, self::IMPORTER_KEY);
        $table['data'] = collect($table['data'])->map(function (array $row) use ($canRevert) {
            $row['detail_url'] = route('contacts.import.detail', $row['id']);
            $row['restore_url'] = $row['has_snapshot'] ? route('contacts.import.source', $row['id']) : null;
            $row['retry_url'] = route('contacts.import.retry', $row['id']);
            $row['revert_url'] = route('contacts.import.revert', $row['id']);
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
                'summary' => json_decode((string) $batch->summary_json, true) ?: [],
                'error_message' => $batch->error_message,
                'progress_current' => (int) $batch->progress_current,
                'progress_total' => (int) $batch->progress_total,
                'progress_percent' => (int) $batch->progress_percent,
            ],
            'results' => $batch->results_path ? $this->batches->readPayload($batch, 'results') : [],
        ];
    }

    public function process(UserImportBatch $batch): void
    {
        $path = $this->batches->restoredWorkbookPath($batch);
        $book = null;
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getSheet(0);
            $sheetName = $sheet->getTitle();
            $lastRow = $sheet->getHighestDataRow();
            $lastColumn = $sheet->getHighestDataColumn();
            $previous = $batch->results_path ? $this->batches->readPayload($batch, 'results') : [];
            $outcomes = collect($previous['row_outcomes'] ?? [])->keyBy(fn ($row) => (int) ($row['source_row'] ?? 0));
            $createdContacts = collect($previous['created_contacts'] ?? [])->keyBy('id');
            $createdTransactions = collect($previous['created_transactions'] ?? [])->keyBy('id');
            $batch->forceFill([
                'status' => 'analyzing', 'stage' => 'processing_rows', 'progress_current' => 0,
                'progress_total' => max(0, $lastRow - 1), 'progress_percent' => 1,
                'started_at' => $batch->started_at ?: now(), 'heartbeat_at' => now(),
                'last_message' => 'Data kontak sedang diproses.', 'error_message' => null,
            ])->save();

            $current = 0;
            for ($sourceRow = 2; $sourceRow <= $lastRow; $sourceRow++) {
                $prior = $outcomes->get($sourceRow);
                if ($prior && in_array($prior['status'] ?? '', ['created', 'matched', 'skipped'], true)) {
                    $current++;
                    continue;
                }
                $cells = $sheet->rangeToArray('A'.$sourceRow.':'.$lastColumn.$sourceRow, null, true, true, false)[0] ?? [];
                if (! count(array_filter($cells, fn ($value) => $value !== null && trim((string) $value) !== ''))) {
                    $outcomes->put($sourceRow, ['sheet' => $sheetName, 'source_row' => $sourceRow, 'status' => 'skipped', 'message' => 'Baris kosong.']);
                    $current++;
                    $this->saveProgress($batch, $outcomes, $createdContacts, $createdTransactions, $current, max(0, $lastRow - 1));
                    continue;
                }

                try {
                    if (count($cells) !== 27) {
                        throw new \InvalidArgumentException('Baris harus memiliki tepat 27 kolom.');
                    }
                    $formatted = $this->formatRow($cells, $sourceRow);
                    $contactKey = trim((string) ($formatted['contact_id'] ?? ''));
                    $existing = $contactKey !== ''
                        ? Contact::where('business_id', $batch->business_id)->where('contact_id', $contactKey)->first()
                        : null;
                    if ($existing) {
                        $expectedName = trim((string) ($formatted['name'] ?? ''));
                        if ($existing->name === $expectedName) {
                            $outcomes->put($sourceRow, [
                                'sheet' => $sheetName, 'source_row' => $sourceRow, 'status' => 'matched', 'record_id' => (int) $existing->id,
                                'record_label' => (string) $existing->contact_id, 'message' => 'Contact ID sudah terhubung dengan nama yang sama; tidak ada perubahan.',
                            ]);
                        } else {
                            throw new \InvalidArgumentException('Contact ID sudah dipakai oleh kontak lain pada bisnis ini.');
                        }
                    } else {
                        $result = DB::transaction(function () use ($batch, $formatted, $sourceRow, $createdContacts, $createdTransactions) {
                            $data = $formatted;
                            $openingBalance = (float) ($data['opening_balance'] ?? 0);
                            unset($data['opening_balance']);
                            if (empty($data['contact_id'])) {
                                $counter = $this->commonUtil->setAndGetReferenceCount('contacts', $batch->business_id);
                                $data['contact_id'] = $this->commonUtil->generateReferenceNumber('contacts', $counter, $batch->business_id);
                            }
                            $data['business_id'] = $batch->business_id;
                            $data['created_by'] = $batch->created_by;
                            $contact = Contact::create($data);
                            $openingTransaction = null;
                            if ($openingBalance != 0.0) {
                                $this->transactionUtil->createOpeningBalanceTransaction($batch->business_id, $contact->id, $openingBalance, $batch->created_by, false);
                                $openingTransaction = Transaction::where('business_id', $batch->business_id)
                                    ->where('contact_id', $contact->id)->where('type', 'opening_balance')->latest('id')->firstOrFail();
                                $createdTransactions->put($openingTransaction->id, [
                                    'id' => (int) $openingTransaction->id,
                                    'attributes' => $this->modelAttributes($openingTransaction),
                                    'updated_at' => (string) $openingTransaction->updated_at,
                                    'contact_id' => (int) $contact->id,
                                ]);
                            }
                            $this->transactionUtil->activityLog($contact, 'imported', null, [], true, $batch->business_id);
                            $contact = $contact->fresh();
                            $createdContacts->put($contact->id, [
                                'id' => (int) $contact->id,
                                'attributes' => $this->modelAttributes($contact),
                                'updated_at' => (string) $contact->updated_at,
                            ]);

                            return ['contact' => $contact, 'opening_transaction' => $openingTransaction];
                        });
                        $contact = $result['contact'];
                        $outcomes->put($sourceRow, [
                            'sheet' => $sheetName, 'source_row' => $sourceRow, 'status' => 'created', 'record_id' => (int) $contact->id,
                            'record_label' => (string) $contact->contact_id,
                            'attributes' => $this->modelAttributes($contact), 'updated_at' => (string) $contact->updated_at,
                            'opening_transaction_id' => $result['opening_transaction'] ? (int) $result['opening_transaction']->id : null,
                        ]);
                    }
                } catch (\Throwable $exception) {
                    $outcomes->put($sourceRow, ['sheet' => $sheetName, 'source_row' => $sourceRow, 'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
                }
                $current++;
                $this->saveProgress($batch, $outcomes, $createdContacts, $createdTransactions, $current, max(0, $lastRow - 1));
            }

            $result = $this->resultPayload($outcomes, $createdContacts, $createdTransactions);
            $status = $result['summary']['failed_rows'] > 0
                ? ($result['summary']['created_rows'] + $result['summary']['matched_rows'] > 0 ? 'partial' : 'failed')
                : 'imported';
            $this->batches->storePayload($batch, 'results', $result);
            $batch->forceFill([
                'status' => $status, 'stage' => $status, 'progress_current' => $current,
                'progress_total' => max(0, $lastRow - 1), 'progress_percent' => 100,
                'summary_json' => json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'search_text' => $batch->file_name.' '.$result['summary']['created_rows'],
                'last_message' => $status === 'failed' ? 'Tidak ada kontak yang berhasil diimpor.' : 'Import kontak selesai.',
                'error_message' => $result['summary']['failed_rows'] > 0 ? 'Sebagian baris kontak gagal. Periksa detail batch.' : null,
                'heartbeat_at' => null, 'completed_at' => now(), 'failed_at' => $status === 'failed' ? now() : null,
            ])->save();
        } finally {
            if ($book) {
                $book->disconnectWorksheets();
            }
            @unlink($path);
        }
    }

    public function revert(UserImportBatch $batch): void
    {
        abort_unless(in_array($batch->status, ['imported', 'partial'], true), 422, 'Batch kontak ini belum dapat direvert.');
        $result = $this->batches->readPayload($batch, 'results');
        DB::transaction(function () use ($batch, $result) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)->where('importer_key', self::IMPORTER_KEY)->lockForUpdate()->findOrFail($batch->id);
            abort_unless(in_array($locked->status, ['imported', 'partial'], true), 422, 'Status batch kontak sudah berubah.');
            $contacts = collect($result['created_contacts'] ?? [])->keyBy('id');
            $transactions = collect($result['created_transactions'] ?? [])->keyBy('id');
            foreach ($contacts as $snapshot) {
                $contact = Contact::where('business_id', $batch->business_id)->lockForUpdate()->find((int) $snapshot['id']);
                abort_if(! $contact, 422, 'Contact hasil batch tidak ditemukan.');
                abort_if($this->modelAttributes($contact) !== ($snapshot['attributes'] ?? [])
                    || (string) $contact->updated_at !== (string) ($snapshot['updated_at'] ?? ''),
                    422, 'Contact '.$contact->contact_id.' telah berubah setelah import.');
                if ($this->contactDependencies->hasReferences(
                    [(int) $contact->id],
                    $transactions->keys()->map(fn ($id) => (int) $id)->all()
                )) {
                    abort(422, 'Contact '.$contact->contact_id.' sudah dipakai transaksi atau catatan lain.');
                }
            }
            foreach ($transactions as $snapshot) {
                $transaction = Transaction::where('business_id', $batch->business_id)->lockForUpdate()->find((int) $snapshot['id']);
                abort_if(! $transaction || $this->modelAttributes($transaction) !== ($snapshot['attributes'] ?? [])
                    || (string) $transaction->updated_at !== (string) ($snapshot['updated_at'] ?? ''),
                    422, 'Saldo awal hasil batch telah berubah setelah import.');
                if (TransactionPayment::where('transaction_id', $transaction->id)->exists()) {
                    abort(422, 'Saldo awal hasil batch sudah memiliki pembayaran.');
                }
            }
            foreach ($transactions as $snapshot) {
                Transaction::whereKey($snapshot['id'])->delete();
            }
            foreach ($contacts as $snapshot) {
                Contact::where('business_id', $batch->business_id)->whereKey($snapshot['id'])->delete();
            }
            $locked->forceFill(['status' => 'reverted', 'stage' => 'reverted', 'progress_percent' => 100,
                'last_message' => 'Contact hasil batch berhasil dikembalikan.', 'error_message' => null,
                'heartbeat_at' => null, 'reverted_at' => now()])->save();
        });
    }

    public function fail(UserImportBatch $batch, \Throwable $exception): void
    {
        $message = mb_substr($exception->getMessage(), 0, 65000);
        try {
            $this->batches->storePayload($batch, 'results', ['summary' => ['failed_rows' => 1], 'row_outcomes' => [[
                'source_row' => null, 'status' => 'failed', 'error' => $message,
            ]]]);
        } catch (\Throwable $storageException) {
            report($storageException);
        }
        $batch->forceFill(['status' => 'failed', 'stage' => 'failed', 'last_message' => 'Import kontak gagal.',
            'error_message' => $message, 'heartbeat_at' => null, 'completed_at' => now(), 'failed_at' => now()])->save();
    }

    private function formatRow(array $row, int $sourceRow): array
    {
        $types = [1 => 'customer', 2 => 'supplier', 3 => 'both'];
        $rawType = strtolower(trim((string) ($row[0] ?? '')));
        $type = isset($types[(int) $rawType]) && (string) (int) $rawType === $rawType ? $types[(int) $rawType] : $rawType;
        if (! in_array($type, ['customer', 'supplier', 'both'], true)) {
            throw new \InvalidArgumentException('Tipe kontak tidak valid pada baris '.$sourceRow.'.');
        }
        if (trim((string) ($row[2] ?? '')) === '') {
            throw new \InvalidArgumentException('Nama depan wajib diisi pada baris '.$sourceRow.'.');
        }
        if (in_array($type, ['supplier', 'both'], true)) {
            if (trim((string) ($row[9] ?? '')) === '') {
                throw new \InvalidArgumentException('Syarat pembayaran wajib diisi pada baris '.$sourceRow.'.');
            }
            if (! in_array(strtolower(trim((string) ($row[10] ?? ''))), ['days', 'months'], true)) {
                throw new \InvalidArgumentException('Periode syarat pembayaran tidak valid pada baris '.$sourceRow.'.');
            }
        }
        if (trim((string) ($row[13] ?? '')) === '') {
            throw new \InvalidArgumentException('Nomor ponsel wajib diisi pada baris '.$sourceRow.'.');
        }
        if (trim((string) ($row[12] ?? '')) !== '' && ! filter_var(trim((string) $row[12]), FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Alamat email tidak valid pada baris '.$sourceRow.'.');
        }
        $nameParts = array_map(fn ($index) => trim((string) ($row[$index] ?? '')), [1, 2, 3, 4]);
        $contact = [
            'type' => $type, 'prefix' => $row[1] ?? null, 'first_name' => $row[2] ?? null,
            'middle_name' => $row[3] ?? null, 'last_name' => $row[4] ?? null,
            'name' => implode(' ', $nameParts), 'supplier_business_name' => trim((string) ($row[5] ?? '')) ?: null,
            'contact_id' => trim((string) ($row[6] ?? '')) ?: null, 'tax_number' => trim((string) ($row[7] ?? '')) ?: null,
            'pay_term_number' => in_array($type, ['supplier', 'both'], true) ? trim((string) $row[9]) : null,
            'pay_term_type' => in_array($type, ['supplier', 'both'], true) ? strtolower(trim((string) $row[10])) : null,
            'credit_limit' => trim((string) ($row[11] ?? '')) !== '' && in_array($type, ['customer', 'both'], true) ? trim((string) $row[11]) : null,
            'email' => trim((string) ($row[12] ?? '')) ?: null, 'mobile' => trim((string) $row[13]),
            'alternate_number' => $row[14] ?? null, 'landline' => $row[15] ?? null,
            'city' => $row[16] ?? null, 'state' => $row[17] ?? null, 'country' => $row[18] ?? null,
            'address_line_1' => $row[19] ?? null, 'address_line_2' => $row[20] ?? null,
            'zip_code' => $row[21] ?? null, 'dob' => $row[22] ?? null,
            'custom_field1' => $row[23] ?? null, 'custom_field2' => $row[24] ?? null,
            'custom_field3' => $row[25] ?? null, 'custom_field4' => $row[26] ?? null,
            'opening_balance' => trim((string) ($row[8] ?? '')) !== '' ? (float) $row[8] : 0,
        ];

        return $contact;
    }

    private function saveProgress(UserImportBatch $batch, $outcomes, $contacts, $transactions, int $current, int $total): void
    {
        $result = $this->resultPayload($outcomes, $contacts, $transactions);
        $this->batches->storePayload($batch, 'results', $result);
        $batch->forceFill(['progress_current' => $current, 'progress_total' => $total,
            'progress_percent' => $total > 0 ? min(99, (int) floor($current / $total * 100)) : 100,
            'summary_json' => json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'heartbeat_at' => now(), 'last_message' => 'Memproses baris '.$current.' dari '.$total.'.'])->save();
    }

    private function resultPayload($outcomes, $contacts, $transactions): array
    {
        $rows = $outcomes->values()->all();

        return [
            'summary' => [
                'source_rows' => count($rows), 'created_rows' => $outcomes->where('status', 'created')->count(),
                'matched_rows' => $outcomes->where('status', 'matched')->count(),
                'skipped_rows' => $outcomes->where('status', 'skipped')->count(),
                'failed_rows' => $outcomes->where('status', 'failed')->count(),
            ],
            'row_outcomes' => $rows,
            'created_contacts' => $contacts->values()->all(),
            'created_transactions' => $transactions->values()->all(),
        ];
    }

    private function modelAttributes($model): array
    {
        return json_decode(json_encode($model->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
