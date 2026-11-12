<?php

namespace App\Services;

use App\Account;
use App\AccountTransaction;
use App\BusinessLocation;
use App\Contact;
use App\ExpenseCategory;
use App\ExpenseLine;
use App\TaxRate;
use App\Tag;
use App\Transaction;
use App\User;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

class ExpenseImportService
{
    private $journalMatcher;

    private $journalLinkService;

    private const REQUIRED_HEADERS = [
        'LOCATION', 'CATEGORY', 'SUB-CATEGORY', 'DESCRIPTION', 'REFERENCE NO',
        'TAGS', 'EXPENSE DATE', 'EXPENSE FOR', 'EXPENSE FOR CONTACT ID',
        'ATTACH DOCUMENT', 'APPLICABLE TAX', 'EXPENSE NOTE', 'TOTAL AMOUNT',
        'PAID AMOUNT', 'PAID ON DATE', 'PAYMENT METHOD', 'PAYMENT ACCOUNT',
        'PAYMENT ACCOUND', 'PAYMENT NOTE',
    ];

    public function __construct(
        private TransactionUtil $transactionUtil,
        ?ExpenseImportJournalMatcher $journalMatcher = null,
        ?ExpenseImportJournalLinkService $journalLinkService = null
    ) {
        $this->journalMatcher = $journalMatcher ?: app(ExpenseImportJournalMatcher::class);
        $this->journalLinkService = $journalLinkService ?: app(ExpenseImportJournalLinkService::class);
    }

    public function storeUploadedFile(UploadedFile $file, int $businessId, ?int $userId): object
    {
        $token = (string) \Illuminate\Support\Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $path = $file->storeAs("expense-imports/{$businessId}", "{$token}.{$extension}", 'local');

        DB::table('expense_import_batches')->insert([
            'id' => $token,
            'business_id' => $businessId,
            'created_by' => $userId,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_hash' => hash_file('sha256', Storage::disk('local')->path($path)),
            'status' => 'preview',
            'operation' => 'preview',
            'process_state' => 'queued',
            'stage' => 'uploading',
            'progress_message' => 'File diterima, menunggu proses preview.',
            'heartbeat_at' => now(),
            'summary' => json_encode([], JSON_UNESCAPED_UNICODE),
            'row_overrides' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('expense_import_batches')->where('id', $token)->first();
    }

    public function preview(string $batchId, int $businessId, string $path, array $overrides = [], ?callable $progress = null): array
    {
        $progress && $progress('reading', 'Membaca workbook Excel.', 10, 0, 0);
        $rows = $this->parseWorkbook($path, $businessId, $overrides, $progress);
        $rows = $this->applyJournalMatches($rows, $businessId, $overrides);
        $rows = $this->validateGroupTaxes($rows);
        $rows = $this->applyExistingExpenseMatches($rows, $businessId, $overrides);
        $progress && $progress('matching', 'Memvalidasi kategori, lokasi, pembayaran, dan tanggal.', 70, count($rows), count($rows));

        DB::transaction(function () use ($batchId, $businessId, $rows, $progress) {
            DB::table('expense_import_preview_rows')
                ->where('batch_id', $batchId)
                ->where('business_id', $businessId)
                ->delete();

            $payloads = [];
            foreach ($rows as $row) {
                $payloads[] = [
                    'batch_id' => $batchId,
                    'business_id' => $businessId,
                    'source_row' => $row['source_row'],
                    'group_key' => $row['group_key'],
                    'status' => $row['status'],
                    'errors' => json_encode($row['errors'], JSON_UNESCAPED_UNICODE),
                    'raw_payload' => json_encode($row['raw_payload'], JSON_UNESCAPED_UNICODE),
                    'normalized_payload' => json_encode($row['normalized_payload'], JSON_UNESCAPED_UNICODE),
                    'is_importable' => $row['is_importable'],
                    'skip_reason' => $row['status'] === 'skipped'
                        ? 'Dilewati dari preview.'
                        : ($row['is_importable'] ? null : ($row['errors'][0] ?? null)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            $written = 0;
            $totalRows = count($payloads);
            foreach (array_chunk($payloads, 500) as $chunk) {
                if ($chunk !== []) {
                    DB::table('expense_import_preview_rows')->insert($chunk);
                    $written += count($chunk);
                    $progress && $progress(
                        'saving',
                        "Menyimpan preview {$written} dari {$totalRows} baris.",
                        70 + (int) floor(($written / max(1, $totalRows)) * 25),
                        $written,
                        $totalRows
                    );
                }
            }

            DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update([
                'progress_total' => count($rows),
                'progress_current' => count($rows),
                'summary' => json_encode($this->summaryFromRows($rows), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        });

        return $this->summary($batchId, $businessId);
    }

    public function summary(string $batchId, int $businessId): array
    {
        $storedSummary = json_decode((string) DB::table('expense_import_batches')
            ->where('id', $batchId)->where('business_id', $businessId)->value('summary'), true) ?: [];
        $counts = DB::table('expense_import_preview_rows')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) ($counts['valid'] ?? 0) + (int) ($counts['error'] ?? 0) + (int) ($counts['skipped'] ?? 0),
            'valid' => (int) ($counts['valid'] ?? 0),
            'errors' => (int) ($counts['error'] ?? 0),
            'importable' => (int) DB::table('expense_import_preview_rows')
                ->where('batch_id', $batchId)->where('business_id', $businessId)->where('is_importable', true)->count(),
            'groups' => (int) DB::table('expense_import_preview_rows')
                ->where('batch_id', $batchId)->where('business_id', $businessId)->where('is_importable', true)
                ->distinct('group_key')->count('group_key'),
            'skipped' => (int) ($counts['skipped'] ?? 0),
            'journal_matched_groups' => (int) ($storedSummary['journal_matched_groups'] ?? 0),
            'journal_review_groups' => (int) ($storedSummary['journal_review_groups'] ?? 0),
            'existing_expense_updated_groups' => (int) ($storedSummary['existing_expense_updated_groups'] ?? 0),
            'existing_expense_review_groups' => (int) ($storedSummary['existing_expense_review_groups'] ?? 0),
        ];
    }

    public function previewRows(string $batchId, int $businessId, int $start, int $length, string $search = '', ?string $status = null, string $orderBy = 'source_row', string $orderDir = 'asc'): array
    {
        $allowedOrderColumns = ['id', 'source_row', 'group_key', 'status'];
        $orderBy = in_array($orderBy, $allowedOrderColumns, true) ? $orderBy : 'source_row';
        $orderDir = strtolower($orderDir) === 'desc' ? 'desc' : 'asc';
        $query = DB::table('expense_import_preview_rows')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId);
        $total = (clone $query)->count();

        if ($status && in_array($status, ['valid', 'error', 'skipped'], true)) {
            $query->where('status', $status);
        }
        if (trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('source_row', 'like', $term)
                    ->orWhere('group_key', 'like', $term)
                    ->orWhere('status', 'like', $term)
                    ->orWhere('errors', 'like', $term)
                    ->orWhere('raw_payload', 'like', $term);
            });
        }
        $filtered = (clone $query)->count();
        $rows = $query->orderBy($orderBy, $orderDir)->orderBy('id')->skip(max(0, $start))->take(max(1, min(100, $length)))->get();

        return [
            'total' => $total,
            'filtered' => $filtered,
            'data' => $rows->map(function ($row) {
                $raw = json_decode((string) $row->raw_payload, true) ?: [];
                $normalized = json_decode((string) $row->normalized_payload, true) ?: [];
                $errors = json_decode((string) $row->errors, true) ?: [];
                return [
                    'id' => (int) $row->id,
                    'source_row' => (int) $row->source_row,
                    'reference_no' => $raw['REFERENCE NO'] ?? $raw['REFERENCE_NO'] ?? ($normalized['reference_no'] ?? ''),
                    'expense_date' => $normalized['expense_date'] ?? null,
                    'paid_on_date' => $normalized['paid_on_date'] ?? null,
                    'raw_expense_date' => $raw['EXPENSE DATE'] ?? '',
                    'raw_paid_on_date' => $raw['PAID ON DATE'] ?? '',
                    'raw_applicable_tax' => $raw['APPLICABLE TAX'] ?? '',
                    'status' => $row->status,
                    'errors' => $errors,
                    'is_importable' => (bool) $row->is_importable,
                    'tax_id' => $normalized['tax_id'] ?? null,
                    'tax_name' => $normalized['tax_name'] ?? null,
                    'tax_status' => $normalized['tax_status'] ?? 'none',
                    'journal_match' => $normalized['journal_match'] ?? ['status' => 'none', 'candidates' => []],
                    'existing_expense_match' => $normalized['existing_expense_match'] ?? ['status' => 'none', 'candidates' => []],
                    'tax_mapping_required' => collect($errors)->contains(fn ($error) => str_contains((string) $error, 'satu TaxRate atau grup pajak')),
                    'payload' => $normalized,
                ];
            })->values()->all(),
        ];
    }

    public function updateDate(string $batchId, int $rowId, int $businessId, string $field, string $date): array
    {
        if (! in_array($field, ['expense_date', 'paid_on_date'], true)) {
            throw new RuntimeException('Kolom tanggal tidak dapat diedit.');
        }
        try {
            $normalized = Carbon::createFromFormat('Y-m-d', $date);
            if (! $normalized || $normalized->format('Y-m-d') !== $date) {
                throw new RuntimeException();
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException('Tanggal harus menggunakan format YYYY-MM-DD.');
        }

        $batch = DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->first();
        if (! $batch || $batch->status !== 'preview' || (($batch->process_state ?? 'completed') !== 'completed')) {
            throw new RuntimeException('Batch preview tidak ditemukan atau sudah diproses.');
        }
        $row = DB::table('expense_import_preview_rows')->where('id', $rowId)->where('batch_id', $batchId)->where('business_id', $businessId)->first();
        if (! $row) {
            throw new RuntimeException('Baris preview tidak ditemukan.');
        }
        $rowErrors = json_decode((string) $row->errors, true) ?: [];
        if (! collect($rowErrors)->contains(fn ($error) => stripos((string) $error, 'tanggal') !== false)) {
            throw new RuntimeException('Baris ini tidak memiliki error tanggal yang dapat diedit.');
        }

        $overrides = array_merge(
            json_decode((string) $batch->date_overrides, true) ?: [],
            json_decode((string) ($batch->row_overrides ?? '[]'), true) ?: []
        );
        $overrides[$row->source_row.':'.$field] = $date;
        $path = $batch->file_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('File sumber preview sudah tidak tersedia.');
        }

        $summary = $this->preview($batchId, $businessId, Storage::disk('local')->path($path), $overrides);
        DB::table('expense_import_batches')->where('id', $batchId)->update([
            'row_overrides' => json_encode($overrides, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return $summary;
    }

    public function updateTax(string $batchId, int $rowId, int $businessId, int $taxRateId): array
    {
        $batch = DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->first();
        if (! $batch || $batch->status !== 'preview' || (($batch->process_state ?? 'completed') !== 'completed')) {
            throw new RuntimeException('Batch preview tidak ditemukan atau sudah diproses.');
        }

        $row = DB::table('expense_import_preview_rows')
            ->where('id', $rowId)->where('batch_id', $batchId)->where('business_id', $businessId)->first();
        if (! $row) {
            throw new RuntimeException('Baris preview tidak ditemukan.');
        }

        $normalized = json_decode((string) $row->normalized_payload, true) ?: [];
        $rawTaxName = trim((string) ($normalized['original_applicable_tax'] ?? $normalized['applicable_tax'] ?? ''));
        if (! in_array(($normalized['tax_status'] ?? null), ['unknown', 'mapped', 'matched'], true) || $rawTaxName === '') {
            throw new RuntimeException('Baris ini tidak memiliki TaxRate yang perlu dipetakan.');
        }
        $rowErrors = json_decode((string) $row->errors, true) ?: [];
        if (($normalized['tax_status'] ?? null) === 'matched'
            && ! collect($rowErrors)->contains(fn ($error) => str_contains((string) $error, 'satu TaxRate atau grup pajak'))) {
            throw new RuntimeException('TaxRate yang sudah cocok tidak memerlukan pemetaan ulang.');
        }

        $taxRate = TaxRate::where('business_id', $businessId)->find($taxRateId);
        if (! $taxRate) {
            throw new RuntimeException('TaxRate harus berasal dari business ini dan masih aktif.');
        }

        $overrides = array_merge(
            json_decode((string) $batch->date_overrides, true) ?: [],
            json_decode((string) ($batch->row_overrides ?? '[]'), true) ?: []
        );
        $overrides['tax_name:'.$this->normalize($rawTaxName)] = (int) $taxRate->id;
        $path = $batch->file_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('File sumber preview sudah tidak tersedia.');
        }

        $summary = $this->preview($batchId, $businessId, Storage::disk('local')->path($path), $overrides);
        DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update([
            'row_overrides' => json_encode($overrides, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return $summary;
    }

    public function updateJournalMatch(string $batchId, int $rowId, int $businessId, int $journalMappingId): array
    {
        $batch = DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->first();
        if (! $batch || $batch->status !== 'preview' || (($batch->process_state ?? 'completed') !== 'completed')) {
            throw new RuntimeException('Batch preview tidak ditemukan atau sudah diproses.');
        }

        $row = DB::table('expense_import_preview_rows')
            ->where('id', $rowId)->where('batch_id', $batchId)->where('business_id', $businessId)->first();
        if (! $row) {
            throw new RuntimeException('Baris preview tidak ditemukan.');
        }

        $normalized = json_decode((string) $row->normalized_payload, true) ?: [];
        $reference = trim((string) ($normalized['reference_no'] ?? ''));
        if ($reference === '') {
            throw new RuntimeException('Baris tanpa REFERENCE NO tidak dapat ditautkan ke Journal.');
        }
        $match = $normalized['journal_match'] ?? [];
        $candidateExists = collect($match['candidates'] ?? [])->contains(function ($candidate) use ($journalMappingId) {
            return (int) ($candidate['mapping_id'] ?? 0) === $journalMappingId
                && ! empty($candidate['mapping_id']);
        });
        if (! $candidateExists) {
            throw new RuntimeException('Journal yang dipilih bukan kandidat untuk referensi ini. Muat ulang preview dan pilih kandidat yang tersedia.');
        }

        $overrides = array_merge(
            json_decode((string) ($batch->date_overrides ?? '[]'), true) ?: [],
            json_decode((string) ($batch->row_overrides ?? '[]'), true) ?: []
        );
        $overrides['journal_candidate:'.$this->normalize($reference)] = $journalMappingId;
        $path = $batch->file_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('File sumber preview sudah tidak tersedia.');
        }

        $summary = $this->preview($batchId, $businessId, Storage::disk('local')->path($path), $overrides);
        DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update([
            'row_overrides' => json_encode($overrides, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return $summary;
    }

    public function updateExistingExpenseAction(string $batchId, int $rowId, int $businessId, string $action): array
    {
        $batch = DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->first();
        if (! $batch || $batch->status !== 'preview' || (($batch->process_state ?? 'completed') !== 'completed')) {
            throw new RuntimeException('Batch preview tidak ditemukan atau sudah diproses.');
        }

        $row = DB::table('expense_import_preview_rows')
            ->where('id', $rowId)->where('batch_id', $batchId)->where('business_id', $businessId)->first();
        if (! $row) {
            throw new RuntimeException('Baris preview tidak ditemukan.');
        }

        $normalized = json_decode((string) $row->normalized_payload, true) ?: [];
        $reference = trim((string) ($normalized['reference_no'] ?? ''));
        $match = $normalized['existing_expense_match'] ?? [];
        if ($reference === '' || empty($match['review_required'])) {
            throw new RuntimeException('Baris ini tidak memerlukan pilihan untuk Expense dengan referensi yang sudah ada.');
        }

        if (! in_array($action, ['skip', 'new'], true)) {
            if (! preg_match('/^merge:([1-9][0-9]*)$/D', $action, $matches)) {
                throw new RuntimeException('Pilih Lewati, Gabungkan, atau Buat transaksi baru.');
            }
            $transactionId = (int) $matches[1];
            $candidateExists = collect($match['candidates'] ?? [])->contains(function ($candidate) use ($transactionId) {
                return (int) ($candidate['id'] ?? 0) === $transactionId;
            });
            if (! $candidateExists) {
                throw new RuntimeException('Expense yang dipilih bukan kandidat untuk referensi ini. Muat ulang preview.');
            }
        }

        $overrides = array_merge(
            json_decode((string) ($batch->date_overrides ?? '[]'), true) ?: [],
            json_decode((string) ($batch->row_overrides ?? '[]'), true) ?: []
        );
        $overrides['expense_existing_action:'.$this->normalize($reference)] = $action;
        $path = $batch->file_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('File sumber preview sudah tidak tersedia.');
        }

        $summary = $this->preview($batchId, $businessId, Storage::disk('local')->path($path), $overrides);
        DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update([
            'row_overrides' => json_encode($overrides, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return $summary;
    }

    public function import(string $batchId, int $businessId, int $userId, ?callable $progress = null): array
    {
        $batch = DB::table('expense_import_batches')
            ->where('id', $batchId)->where('business_id', $businessId)->lockForUpdate()->first();
        // The queue job marks the batch as `processing` before entering this
        // service. Accept that state here while keeping direct/duplicate
        // imports blocked unless the batch is still a preview.
        if (! $batch || $batch->status !== 'preview' || ($batch->operation ?? 'preview') !== 'import' || ! in_array(($batch->process_state ?? 'completed'), ['completed', 'processing'], true)) {
            throw new RuntimeException('Preview belum selesai atau batch sudah diproses.');
        }

        $rows = DB::table('expense_import_preview_rows')
            ->where('batch_id', $batchId)->where('business_id', $businessId)->where('is_importable', true)
            ->orderBy('source_row')->get();
        if ($rows->isEmpty()) {
            throw new RuntimeException('Tidak ada baris valid untuk diimpor. Perbaiki error terlebih dahulu.');
        }

        $inserted = 0;
        DB::transaction(function () use ($rows, $businessId, $userId, $batchId, &$inserted, $progress) {
            $groups = $rows->groupBy('group_key');
            // Each reference group becomes one transaction, but progress is
            // reported in source rows so it matches the preview total.
            $totalRows = $rows->count();
            $processedRows = 0;
            $importBatchNumber = ((int) Transaction::where('business_id', $businessId)->max('import_batch')) + 1;
            foreach ($groups as $groupRows) {
                $progress && $progress(
                    'saving',
                    "Memproses baris ".($processedRows + 1)." dari {$totalRows}.",
                    20 + (int) floor(($processedRows / max(1, $totalRows)) * 70),
                    $processedRows,
                    $totalRows
                );
                $this->importGroup($groupRows, $businessId, $userId, $batchId, $importBatchNumber);
                $inserted++;
                $processedRows += $groupRows->count();
                $progress && $progress(
                    'saving',
                    "Menyimpan baris {$processedRows} dari {$totalRows} ({$inserted} transaksi).",
                    20 + (int) floor(($processedRows / max(1, $totalRows)) * 70),
                    $processedRows,
                    $totalRows
                );
            }
            DB::table('expense_import_preview_rows')->where('batch_id', $batchId)->where('business_id', $businessId)->where('is_importable', true)->update([
                'status' => 'imported', 'updated_at' => now(),
            ]);
            DB::table('expense_import_batches')->where('id', $batchId)->update([
                'status' => 'imported', 'operation' => 'import', 'confirmed_at' => now(),
                'summary' => json_encode(['transactions' => $inserted, 'rows' => $rows->count()], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        });

        return ['transactions' => $inserted, 'rows' => $rows->count()];
    }

    public function cancel(string $batchId, int $businessId): bool
    {
        return DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)
            ->whereIn('process_state', ['queued', 'processing'])->update([
                'process_state' => 'cancelled', 'status' => 'cancelled', 'stage' => 'cancelled',
                'progress_message' => 'Proses dibatalkan.', 'cancel_requested_at' => now(), 'updated_at' => now(),
            ]) > 0;
    }

    private function parseWorkbook(string $path, int $businessId, array $overrides, ?callable $progress): array
    {
        $sheets = Excel::toArray([], $path);
        $sheet = $sheets[0] ?? [];
        if (count($sheet) < 1) {
            throw new RuntimeException('Workbook tidak memiliki baris header.');
        }
        $header = array_map(fn ($value) => $this->headerKey($value), $sheet[0]);
        $header = array_values(array_filter($header, fn ($value) => $value !== ''));
        if (! in_array('TOTAL AMOUNT', $header, true) || ! in_array('PAID AMOUNT', $header, true)) {
            throw new RuntimeException('Header template Expense tidak sesuai. Gunakan template resmi.');
        }

        $context = $this->validationContext($businessId);
        $rows = [];
        $total = max(1, count($sheet) - 1);
        foreach (array_slice($sheet, 1) as $index => $values) {
            $sourceRow = $index + 2;
            $row = [];
            foreach ($header as $column => $name) {
                $row[$name] = $values[$column] ?? null;
            }
            if ($this->rowIsEmpty($row)) {
                continue;
            }
            $rows[] = $this->validateRow($row, $sourceRow, $context, $overrides);
            if ($progress && ($sourceRow % 250 === 0 || $sourceRow === $total + 1)) {
                $progress('reading', "Membaca baris {$sourceRow} dari {$total}.", min(65, 10 + (int) floor(($sourceRow / $total) * 55)), count($rows), $total);
            }
        }

        return $rows;
    }

    private function validationContext(int $businessId): array
    {
        $locations = BusinessLocation::where('business_id', $businessId)->get()->keyBy(fn ($location) => $this->normalize($location->name));
        $categories = ExpenseCategory::where('business_id', $businessId)->get();
        $categoryByName = $categories->groupBy(fn ($category) => $this->normalize($category->name));
        $contacts = Contact::where('business_id', $businessId)->get();
        $users = User::where('business_id', $businessId)->get();
        $taxes = TaxRate::where('business_id', $businessId)->with('sub_taxes')->get();
        $taxesByName = $taxes->keyBy(fn ($tax) => $this->normalize($tax->name));
        $taxesById = $taxes->keyBy('id');
        $paymentTypes = $this->transactionUtil->payment_types(null, false, $businessId);

        return compact('businessId', 'locations', 'categories', 'categoryByName', 'contacts', 'users', 'taxesByName', 'taxesById', 'paymentTypes');
    }

    private function validateRow(array $row, int $sourceRow, array $context, array $overrides): array
    {
        $errors = [];
        $expenseDate = $this->parseDate($row['EXPENSE DATE'] ?? null, $overrides[$sourceRow.':expense_date'] ?? null);
        $paidOnDate = $this->parseDate($row['PAID ON DATE'] ?? null, $overrides[$sourceRow.':paid_on_date'] ?? null);
        if ($expenseDate['error']) {
            $errors[] = 'Tanggal pengeluaran: '.$expenseDate['error'];
        }
        if ($paidOnDate['error']) {
            $errors[] = 'Tanggal pembayaran: '.$paidOnDate['error'];
        }

        $locationName = $this->stringValue($row['LOCATION'] ?? null);
        $location = $locationName !== '' ? ($context['locations'][$this->normalize($locationName)] ?? null) : $context['locations']->first();
        if (! $location) {
            $errors[] = $locationName !== '' ? "Lokasi bisnis '{$locationName}' tidak ditemukan." : 'Lokasi bisnis wajib diisi atau bisnis belum memiliki lokasi.';
        }

        $total = $this->parseAmount($row['TOTAL AMOUNT'] ?? null);
        $paid = $this->parseAmount($row['PAID AMOUNT'] ?? null);
        if ($total === null || $total < 0) {
            $errors[] = 'Total Amount wajib berupa angka nol atau lebih.';
        }
        if ($paid === null || $paid < 0) {
            $errors[] = 'Paid Amount wajib berupa angka nol atau lebih.';
        }

        $categoryName = $this->stringValue($row['CATEGORY'] ?? null);
        $categoryMatches = $categoryName !== ''
            ? $context['categoryByName']->get($this->normalize($categoryName), collect())
            : collect();
        $category = $categoryMatches->first();
        $subCategoryName = $this->stringValue($row['SUB-CATEGORY'] ?? null);
        if ($subCategoryName !== '' && $categoryName === '') {
            $errors[] = 'Subkategori tidak dapat diisi tanpa kategori induk.';
        }
        $subCategory = null;
        if ($subCategoryName !== '' && $category) {
            $sub = $context['categoryByName']->get($this->normalize($subCategoryName), collect());
            $subCategory = $sub->first(fn ($item) => (int) $item->parent_id === (int) $category->id);
            if (! $subCategory) {
                // Subcategories are intentionally created under the selected
                // parent during import, so this is not a blocking error.
            }
        }

        $paymentMethod = $this->stringValue($row['PAYMENT METHOD'] ?? null);
        $paymentKey = $this->paymentKey($paymentMethod, $context['paymentTypes']);
        if ($paymentMethod !== '' && $paymentKey === null) {
            $errors[] = "Metode pembayaran '{$paymentMethod}' tidak tersedia.";
        }

        $originalTaxName = $this->stringValue($row['APPLICABLE TAX'] ?? null);
        $hasNoTax = $originalTaxName === '' || $this->isZeroTaxValue($originalTaxName);
        $taxOverrideId = ! $hasNoTax
            ? ($overrides['tax_name:'.$this->normalize($originalTaxName)] ?? null)
            : null;
        $tax = is_numeric($taxOverrideId)
            ? $context['taxesById']->get((int) $taxOverrideId)
            : null;
        $matchedTax = $originalTaxName !== ''
            ? $context['taxesByName']->get($this->normalize($originalTaxName))
            : null;
        $taxStatus = $hasNoTax ? 'none' : 'matched';
        if ($tax) {
            $taxStatus = 'mapped';
        } else {
            $tax = $matchedTax;
        }
        if (! $tax && ! $hasNoTax) {
            $taxStatus = 'unknown';
            $errors[] = "Tax '{$originalTaxName}' tidak dikenal. Pilih TaxRate yang tersedia dari preview.";
        }
        $excelTaxRate = $this->taxPercentage($tax, (int) $context['businessId']);

        $normalized = [
            'location_id' => $location?->id,
            'location_name' => $locationName,
            'category_id' => $category?->id,
            'category_name' => $categoryName,
            'sub_category_id' => $subCategory?->id,
            'sub_category_name' => $subCategoryName,
            'description' => $this->stringValue($row['DESCRIPTION'] ?? null),
            'reference_no' => $this->stringValue($row['REFERENCE NO'] ?? null),
            'tags' => $this->stringValue($row['TAGS'] ?? null),
            'expense_date' => $expenseDate['value'],
            'expense_for' => $this->stringValue($row['EXPENSE FOR'] ?? null),
            'expense_for_contact_id' => $this->stringValue($row['EXPENSE FOR CONTACT ID'] ?? null),
            'attach_document' => $this->stringValue($row['ATTACH DOCUMENT'] ?? null),
            'applicable_tax' => $tax?->name ?? ($hasNoTax ? '' : $originalTaxName),
            'original_applicable_tax' => $hasNoTax ? '' : $originalTaxName,
            'tax_id' => $tax?->id,
            'tax_name' => $tax?->name,
            'tax_status' => $taxStatus,
            'excel_tax_id' => $tax?->id,
            'excel_tax_name' => $tax?->name,
            'excel_tax_rate' => $excelTaxRate,
            'expense_note' => $this->stringValue($row['EXPENSE NOTE'] ?? null),
            'total_amount' => $total,
            'paid_amount' => $paid,
            'paid_on_date' => $paidOnDate['value'],
            'payment_method' => $paymentKey ?: ($paymentMethod !== '' ? null : 'other'),
            'payment_method_label' => $paymentMethod,
            'payment_account' => $this->stringValue($row['PAYMENT ACCOUNT'] ?? ($row['PAYMENT ACCOUND'] ?? null)),
            'payment_note' => $this->stringValue($row['PAYMENT NOTE'] ?? null),
        ];

        return [
            'source_row' => $sourceRow,
            'group_key' => $normalized['reference_no'] !== '' ? 'ref:'.$this->normalize($normalized['reference_no']) : 'row:'.$sourceRow,
            'raw_payload' => $row,
            'normalized_payload' => $normalized,
            'errors' => array_values(array_unique($errors)),
            'status' => $errors === [] ? 'valid' : 'error',
            'is_importable' => $errors === [],
        ];
    }

    private function applyJournalMatches(array $rows, int $businessId, array $overrides): array
    {
        $references = [];
        $taxableBases = [];
        foreach ($rows as $row) {
            $payload = $row['normalized_payload'];
            $reference = trim((string) ($payload['reference_no'] ?? ''));
            $normalizedReference = $this->normalize($reference);
            if ($normalizedReference === '') {
                continue;
            }
            $references[$normalizedReference] = $reference;
            $taxableBases[$normalizedReference] = ($taxableBases[$normalizedReference] ?? 0)
                + $this->excelLineBaseAmount($payload);
        }

        if ($references === []) {
            return $rows;
        }

        $matches = $this->journalMatcher->matchReferences(
            $businessId,
            array_values($references),
            $overrides,
            $taxableBases
        );

        foreach ($rows as &$row) {
            $payload = $row['normalized_payload'];
            $normalizedReference = $this->normalize((string) ($payload['reference_no'] ?? ''));
            if ($normalizedReference === '') {
                $payload['journal_match'] = ['status' => 'none', 'selected' => null, 'candidates' => []];
                $row['normalized_payload'] = $payload;
                continue;
            }

            $match = $matches[$normalizedReference] ?? ['status' => 'none', 'selected' => null, 'candidates' => []];
            $payload['journal_match'] = [
                'status' => $match['status'],
                'selected_by_user' => (bool) ($match['selected_by_user'] ?? false),
                'selected' => $match['selected'] ?? null,
                'candidates' => $match['candidates'] ?? [],
            ];

            if ($match['status'] === 'ambiguous') {
                $row['errors'][] = 'Ada beberapa posting Journal Expense dengan REFERENCE NO ini. Pilih satu kandidat sebelum import agar GL tidak tertaut ganda.';
            } elseif ($match['status'] === 'selection_invalid') {
                $row['errors'][] = 'Pilihan Journal sudah tidak tersedia. Pilih ulang kandidat yang masih aktif.';
            } elseif ($match['status'] === 'unavailable') {
                $issues = collect($match['candidates'] ?? [])->pluck('issue')->filter()->unique()->values();
                $row['errors'][] = $issues->isNotEmpty()
                    ? 'Journal dengan referensi ini belum siap dipakai: '.$issues->implode(' ')
                    : 'Journal dengan referensi ini belum memiliki satu posting GL yang siap dipakai.';
            } elseif ($match['status'] === 'matched') {
                $candidate = $match['selected'];
                $payload['journal_mapping_id'] = $candidate['mapping_id'] ?? null;
                $payload['journal_row_id'] = $candidate['journal_row_id'] ?? null;
                $payload['journal_import_id'] = $candidate['journal_import_id'] ?? null;
                $payload['journal_source_transaction_id'] = $candidate['source_transaction_id'] ?? null;
                $payload['journal_tax_amount'] = $candidate['tax_amount'];
                $payload['journal_tax_id'] = $candidate['tax_id'] ?? null;
                $payload['journal_tax_name'] = $candidate['tax_name'] ?? null;

                if (empty($candidate['ready'])) {
                    $row['errors'][] = (string) ($candidate['issue'] ?? 'Posting Journal belum siap untuk ditautkan.');
                } elseif (! is_numeric($candidate['tax_amount'] ?? null)) {
                    $row['errors'][] = (string) ($candidate['tax_issue'] ?? 'Pajak Journal tidak dapat ditentukan.');
                } else {
                    $row['errors'] = array_values(array_filter($row['errors'], function ($error) {
                        return ! str_starts_with((string) $error, 'Tax ')
                            && ! str_contains((string) $error, 'satu TaxRate atau grup pajak');
                    }));
                    $payload['tax_id'] = $candidate['tax_id'] ?? null;
                    $payload['tax_name'] = $candidate['tax_name'] ?? null;
                    $payload['applicable_tax'] = $candidate['tax_name'] ?? '';
                    $payload['original_applicable_tax'] = '';
                    $payload['tax_status'] = (float) $candidate['tax_amount'] > 0 ? 'journal_matched' : 'journal_no_tax';
                }
            }

            $row['errors'] = array_values(array_unique($row['errors']));
            $row['status'] = $row['errors'] === [] ? 'valid' : 'error';
            $row['is_importable'] = $row['errors'] === [];
            $row['normalized_payload'] = $payload;
        }
        unset($row);

        return $rows;
    }

    private function applyExistingExpenseMatches(array $rows, int $businessId, array $overrides): array
    {
        $references = [];
        $groupTotals = [];
        $groupDates = [];
        foreach ($rows as $index => $row) {
            $payload = $row['normalized_payload'];
            $reference = trim((string) ($payload['reference_no'] ?? ''));
            $normalizedReference = $this->normalize($reference);
            if ($normalizedReference === '') {
                continue;
            }

            $references[$normalizedReference] = $reference;
            $groupTotals[$normalizedReference]['excel'] = ($groupTotals[$normalizedReference]['excel'] ?? 0)
                + (float) ($payload['total_amount'] ?? 0);
            $groupTotals[$normalizedReference]['base'] = ($groupTotals[$normalizedReference]['base'] ?? 0)
                + $this->excelLineBaseAmount($payload);
            $expenseDate = substr((string) ($payload['expense_date'] ?? ''), 0, 10);
            if ($expenseDate !== '') {
                $groupDates[$normalizedReference][$expenseDate] = $expenseDate;
            }
            $groupTotals[$normalizedReference]['indexes'][] = $index;
        }

        if ($references === []) {
            return $rows;
        }

        $lookupReferences = array_values(array_unique(array_map(fn ($reference) => mb_strtolower(trim($reference)), $references)));
        $existingByReference = [];
        foreach (array_chunk($lookupReferences, 300) as $referenceChunk) {
            Transaction::where('business_id', $businessId)
                ->where('type', 'expense')
                ->whereNotNull('ref_no')
                ->whereIn(DB::raw('LOWER(TRIM(ref_no))'), $referenceChunk)
                ->get(['id', 'ref_no', 'transaction_date', 'final_total'])
                ->each(function ($transaction) use (&$existingByReference) {
                    $normalizedReference = $this->normalize((string) $transaction->ref_no);
                    $existingByReference[$normalizedReference][] = $transaction;
                });
        }

        foreach ($references as $normalizedReference => $reference) {
            $indexes = $groupTotals[$normalizedReference]['indexes'] ?? [];
            if ($indexes === []) {
                continue;
            }

            $candidates = collect($existingByReference[$normalizedReference] ?? [])->unique('id')->values();
            $firstPayload = $rows[$indexes[0]]['normalized_payload'];
            $journalMatch = $firstPayload['journal_match'] ?? [];
            $linkedTransactionId = ($journalMatch['status'] ?? null) === 'matched'
                ? (int) ($firstPayload['journal_source_transaction_id'] ?? 0)
                : 0;
            if ($linkedTransactionId > 0) {
                $linked = $candidates->first(fn ($candidate) => (int) $candidate->id === $linkedTransactionId);
                if ($linked) {
                    $candidates = collect([$linked]);
                }
            }

            if ($candidates->isEmpty()) {
                foreach ($indexes as $index) {
                    $payload = $rows[$index]['normalized_payload'];
                    $payload['existing_expense_match'] = [
                        'status' => 'none',
                        'review_required' => false,
                        'action' => null,
                        'selected_transaction_id' => null,
                        'candidates' => [],
                    ];
                    $rows[$index]['normalized_payload'] = $payload;
                }
                if (! empty($overrides['expense_existing_action:'.$normalizedReference])) {
                    foreach ($indexes as $index) {
                        $rows[$index]['errors'][] = 'Expense yang dipilih sebelumnya tidak lagi tersedia untuk referensi ini. Preview perlu diperiksa ulang.';
                        $rows[$index]['errors'] = array_values(array_unique($rows[$index]['errors']));
                        $rows[$index]['status'] = 'error';
                        $rows[$index]['is_importable'] = false;
                    }
                }
                continue;
            }

            $incomingTotal = (float) ($groupTotals[$normalizedReference]['excel'] ?? 0);
            if (($journalMatch['status'] ?? null) === 'matched'
                && (float) ($journalMatch['selected']['tax_amount'] ?? 0) > 0) {
                $incomingTotal = (float) ($groupTotals[$normalizedReference]['base'] ?? 0)
                    + (float) $journalMatch['selected']['tax_amount'];
            }
            $incomingDates = array_values($groupDates[$normalizedReference] ?? []);
            sort($incomingDates);
            $incomingDate = $incomingDates[0] ?? null;
            $candidateDetails = $candidates->map(function ($candidate) use ($incomingDate, $incomingTotal) {
                $existingDate = substr((string) ($candidate->transaction_date ?? ''), 0, 10);
                $dateDifference = null;
                if ($incomingDate && $existingDate !== '') {
                    try {
                        $dateDifference = Carbon::parse($incomingDate)->startOfDay()->diffInDays(Carbon::parse($existingDate)->startOfDay());
                    } catch (\Throwable $exception) {
                        $dateDifference = null;
                    }
                }
                $existingTotal = (float) ($candidate->final_total ?? 0);
                $amountDifference = abs($incomingTotal - $existingTotal);
                $relativeDifference = $amountDifference / max(abs($existingTotal), 1);

                return [
                    'id' => (int) $candidate->id,
                    'reference' => (string) $candidate->ref_no,
                    'date' => $existingDate,
                    'total' => $existingTotal,
                    'incoming_date' => $incomingDate,
                    'incoming_total' => $incomingTotal,
                    'date_difference_days' => $dateDifference,
                    'amount_difference' => $amountDifference,
                    'amount_difference_percent' => $relativeDifference * 100,
                    'large_difference' => ($dateDifference !== null && $dateDifference > 30)
                        || ($amountDifference >= 100000 && $relativeDifference > 0.10),
                ];
            })->values()->all();

            $reviewRequired = count($candidateDetails) > 1
                || collect($candidateDetails)->contains(fn ($candidate) => ! empty($candidate['large_difference']))
                || count($incomingDates) > 1;
            $actionKey = 'expense_existing_action:'.$normalizedReference;
            $requestedAction = trim((string) ($overrides[$actionKey] ?? ''));
            $action = '';
            $selectedTransactionId = null;
            $actionValid = false;

            if (in_array($requestedAction, ['skip', 'new'], true)) {
                $action = $requestedAction;
                $actionValid = true;
            } elseif (preg_match('/^merge:([1-9][0-9]*)$/D', $requestedAction, $actionMatches)) {
                $selectedId = (int) $actionMatches[1];
                if (collect($candidateDetails)->contains(fn ($candidate) => (int) $candidate['id'] === $selectedId)) {
                    $action = $requestedAction;
                    $selectedTransactionId = $selectedId;
                    $actionValid = true;
                }
            } elseif ($requestedAction === '' && count($candidateDetails) === 1 && ! $reviewRequired) {
                $selectedTransactionId = (int) $candidateDetails[0]['id'];
                $action = 'merge:'.$selectedTransactionId;
                $actionValid = true;
            }

            $matchStatus = count($candidateDetails) > 1 ? 'ambiguous' : 'matched';
            $match = [
                'status' => $matchStatus,
                'review_required' => $reviewRequired,
                'action' => $actionValid ? $action : null,
                'selected_transaction_id' => $selectedTransactionId,
                'candidates' => $candidateDetails,
            ];

            foreach ($indexes as $index) {
                $rows[$index]['normalized_payload']['existing_expense_match'] = $match;
                if ($requestedAction !== '' && ! $actionValid) {
                    $rows[$index]['errors'][] = 'Pilihan transaksi Expense sudah tidak cocok dengan kandidat yang tersedia. Pilih ulang aksi.';
                } elseif ($reviewRequired && ! $actionValid) {
                    $rows[$index]['errors'][] = 'REFERENCE NO sudah memiliki Expense dengan tanggal atau total yang berbeda jauh. Pilih Lewati, Gabungkan ke transaksi lama, atau Buat transaksi baru.';
                }

                if ($actionValid && $action === 'skip') {
                    $rows[$index]['errors'] = [];
                    $rows[$index]['status'] = 'skipped';
                    $rows[$index]['is_importable'] = false;
                    continue;
                }

                $rows[$index]['errors'] = array_values(array_unique($rows[$index]['errors']));
                $rows[$index]['status'] = $rows[$index]['errors'] === [] ? 'valid' : 'error';
                $rows[$index]['is_importable'] = $rows[$index]['errors'] === [];
            }
        }

        return $rows;
    }

    private function excelLineBaseAmount(array $payload): float
    {
        $amount = (float) ($payload['total_amount'] ?? 0);
        $rate = (float) ($payload['excel_tax_rate'] ?? 0);
        if ($rate <= 0) {
            return $amount;
        }

        return (float) $this->transactionUtil->calc_percentage_base($amount, $rate);
    }

    private function taxPercentage($tax, int $businessId): float
    {
        if (! $tax) {
            return 0.0;
        }
        if (! empty($tax->is_tax_group)) {
            $subTaxes = $tax->sub_taxes ?? collect();
            return (float) collect($subTaxes)->where('business_id', $businessId)->sum('amount');
        }

        return (float) ($tax->amount ?? 0);
    }

    private function isZeroTaxValue(string $value): bool
    {
        $normalized = trim(str_replace([' ', '%'], '', $value));
        if ($normalized === '') {
            return false;
        }
        $parsed = $this->parseAmount($normalized);

        return $parsed !== null && abs($parsed) < 0.00001;
    }

    private function importGroup($rows, int $businessId, int $userId, string $batchId, int $importBatchNumber): void
    {
        $first = $rows->first();
        $payload = json_decode((string) $first->normalized_payload, true) ?: [];
        $journalMatch = $payload['journal_match'] ?? [];
        $hasJournalMatch = ($journalMatch['status'] ?? null) === 'matched';
        $reference = trim((string) ($payload['reference_no'] ?? ''));
        if ($reference !== '') {
            $this->lockExpenseReference($businessId, $reference);
        }
        $categoryId = $this->validCategoryId($payload['category_id'] ?? null, $businessId)
            ?: $this->resolveCategory($payload['category_name'] ?? '', $businessId, null);
        $lockedJournal = $hasJournalMatch
            ? $this->journalLinkService->lockSelectedJournal($journalMatch, $businessId, $reference)
            : null;

        $taxName = trim((string) ($payload['applicable_tax'] ?? ''));
        $originalTaxName = trim((string) ($payload['original_applicable_tax'] ?? $taxName));
        $taxId = $hasJournalMatch ? ($payload['journal_tax_id'] ?? null) : ($payload['tax_id'] ?? null);
        $tax = ! empty($taxId)
            ? TaxRate::where('business_id', $businessId)->find((int) $taxId)
            : null;
        if (! $hasJournalMatch && ! $tax && $taxName !== '') {
            $tax = $this->resolveTax($taxName, $businessId);
        }
        if (! $hasJournalMatch && $originalTaxName !== '' && ! $tax) {
            throw new RuntimeException("Tax '{$originalTaxName}' tidak lagi tersedia. Buka ulang preview dan petakan ke TaxRate yang aktif.");
        }
        $taxPercentage = $hasJournalMatch
            ? (float) ($payload['excel_tax_rate'] ?? 0)
            : $this->taxPercentage($tax, $businessId);
        if (! $hasJournalMatch && $tax && ! empty($tax->is_tax_group) && $taxPercentage <= 0) {
            throw new RuntimeException("Grup pajak '{$tax->name}' tidak memiliki komponen aktif yang valid.");
        }
        $journalTaxAmount = $hasJournalMatch ? (float) ($payload['journal_tax_amount'] ?? 0) : null;
        if ($hasJournalMatch && (! is_finite($journalTaxAmount) || $journalTaxAmount < 0)) {
            throw new RuntimeException('Nominal pajak Journal tidak valid; Expense tidak diubah.');
        }

        $lineRows = [];
        $excelTotal = 0.0;
        $totalBeforeTax = 0.0;
        $paid = 0;
        foreach ($rows as $row) {
            $item = json_decode((string) $row->normalized_payload, true) ?: [];
            $lineCategoryId = $this->validCategoryId($item['category_id'] ?? null, $businessId)
                ?: $this->resolveCategory($item['category_name'] ?? '', $businessId, null);
            $lineSubCategoryId = $this->validCategoryId($item['sub_category_id'] ?? null, $businessId, $lineCategoryId)
                ?: $this->resolveCategory($item['sub_category_name'] ?? '', $businessId, $lineCategoryId);
            $inclusiveAmount = (float) ($item['total_amount'] ?? 0);
            $lineRate = $hasJournalMatch
                ? ($journalTaxAmount > 0 ? (float) ($item['excel_tax_rate'] ?? 0) : 0.0)
                : $taxPercentage;
            $lineBaseAmount = $lineRate > 0
                ? (float) $this->transactionUtil->calc_percentage_base($inclusiveAmount, $lineRate)
                : $inclusiveAmount;
            $excelTotal += $inclusiveAmount;
            $totalBeforeTax += $lineBaseAmount;
            $paid += (float) ($item['paid_amount'] ?? 0);
            $lineRows[] = [
                'category_id' => $lineCategoryId,
                'sub_category_id' => $lineSubCategoryId,
                'description' => $item['description'] ?? null,
                'amount' => $lineBaseAmount,
            ];
        }

        $totalBeforeTax = round($totalBeforeTax, 4);
        $taxAmount = $hasJournalMatch ? round($journalTaxAmount, 4) : round($excelTotal - $totalBeforeTax, 4);
        $total = $hasJournalMatch ? round($totalBeforeTax + $taxAmount, 4) : round($excelTotal, 4);
        $contactId = $this->resolveContact($payload['expense_for_contact_id'] ?? '', $businessId, $userId);
        $expenseFor = $this->resolveUser($payload['expense_for'] ?? '', $businessId, $userId);
        if ($reference === '') {
            $reference = $this->transactionUtil->generateReferenceNumber('expense', $this->transactionUtil->setAndGetReferenceCount('expense', $businessId), $businessId);
        }

        $existingMatch = $payload['existing_expense_match'] ?? [];
        $existingAction = (string) ($existingMatch['action'] ?? '');
        $transaction = $lockedJournal['transaction'] ?? null;
        $preserveExistingJournalLink = false;
        if (! empty($existingMatch['review_required']) && $existingAction === '') {
            throw new RuntimeException('REFERENCE NO memerlukan pilihan di preview. Muat ulang preview sebelum mengimpor.');
        }
        if (str_starts_with($existingAction, 'merge:')) {
            $targetId = (int) substr($existingAction, strlen('merge:'));
            if ($targetId < 1 || ($transaction && (int) $transaction->id !== $targetId)) {
                throw new RuntimeException('Expense yang dipilih tidak sama dengan transaksi yang tertaut ke Journal. Muat ulang preview.');
            }
            if (! $transaction) {
                $transaction = Transaction::where('business_id', $businessId)
                    ->where('type', 'expense')
                    ->lockForUpdate()
                    ->find($targetId);
            }
            if (! $transaction || $this->normalize((string) $transaction->ref_no) !== $this->normalize($reference)) {
                throw new RuntimeException('Expense yang dipilih tidak lagi tersedia untuk REFERENCE NO ini. Muat ulang preview.');
            }
        } elseif ($existingAction === 'new') {
            $preserveExistingJournalLink = $lockedJournal !== null && $transaction !== null;
            $transaction = null;
        } elseif ($existingAction === 'skip') {
            throw new RuntimeException('Kelompok REFERENCE NO yang dipilih untuk dilewati tidak boleh masuk proses import.');
        }

        if (! $transaction && $existingAction === '' && $reference !== '') {
            $liveCandidates = Transaction::where('business_id', $businessId)
                ->where('type', 'expense')
                ->whereNotNull('ref_no')
                ->whereRaw('LOWER(TRIM(ref_no)) = ?', [$this->normalize($reference)])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($liveCandidates->count() > 1) {
                throw new RuntimeException('Expense dengan REFERENCE NO ini berubah setelah preview dan memiliki beberapa kandidat. Muat ulang preview untuk memilih transaksi.');
            }

            if ($liveCandidates->count() === 1) {
                $incomingDates = collect($rows)
                    ->map(function ($row) {
                        $item = json_decode((string) $row->normalized_payload, true) ?: [];

                        return substr((string) ($item['expense_date'] ?? ''), 0, 10);
                    })
                    ->filter()
                    ->unique()
                    ->values();
                $existing = $liveCandidates->first();
                $dateDifference = null;
                $existingDate = substr((string) ($existing->transaction_date ?? ''), 0, 10);
                if ($incomingDates->count() === 1 && $existingDate !== '') {
                    try {
                        $dateDifference = Carbon::parse($incomingDates->first())->startOfDay()->diffInDays(Carbon::parse($existingDate)->startOfDay());
                    } catch (\Throwable $exception) {
                        $dateDifference = null;
                    }
                }
                $amountDifference = abs($total - (float) ($existing->final_total ?? 0));
                $relativeDifference = $amountDifference / max(abs((float) ($existing->final_total ?? 0)), 1);
                $largeDifference = $incomingDates->count() > 1
                    || ($dateDifference !== null && $dateDifference > 30)
                    || ($amountDifference >= 100000 && $relativeDifference > 0.10);

                if ($largeDifference) {
                    throw new RuntimeException('Expense dengan REFERENCE NO ini berubah setelah preview dan memiliki selisih besar. Muat ulang preview untuk memilih Lewati, Gabungkan, atau Buat transaksi baru.');
                }

                $transaction = $existing;
            }
        }

        $isExistingTransaction = $transaction !== null;
        if ($transaction) {
            if ($lockedJournal !== null) {
                $this->journalLinkService->removeProvenDuplicateExpenseMap($transaction);
            }
            $transaction->fill([
                'location_id' => $payload['location_id'] ?? null,
                'expense_category_id' => $categoryId,
                'expense_sub_category_id' => $lineRows[0]['sub_category_id'] ?? null,
                'expense_for' => $expenseFor,
                'contact_id' => $contactId,
                'ref_no' => $reference,
                'transaction_date' => $payload['expense_date'] ?: now()->toDateTimeString(),
                'additional_notes' => $payload['expense_note'] ?? null,
                'document' => $payload['attach_document'] ?? null,
                'tax_id' => $tax?->id,
                'tax_amount' => $taxAmount,
                'total_before_tax' => $totalBeforeTax,
                'final_total' => $total,
                'status' => 'final',
                'import_batch' => $importBatchNumber,
                'import_time' => now(),
                'import_file' => DB::table('expense_import_batches')->where('id', $batchId)->value('file_name'),
            ]);
            $transaction->save();
            ExpenseLine::where('transaction_id', $transaction->id)->delete();
        } else {
            $transaction = Transaction::create([
                'business_id' => $businessId,
                'location_id' => $payload['location_id'] ?? null,
                'created_by' => $userId,
                'expense_category_id' => $categoryId,
                'expense_sub_category_id' => $lineRows[0]['sub_category_id'] ?? null,
                'expense_for' => $expenseFor,
                'contact_id' => $contactId,
                'ref_no' => $reference,
                'transaction_date' => $payload['expense_date'] ?: now()->toDateTimeString(),
                'additional_notes' => $payload['expense_note'] ?? null,
                'document' => $payload['attach_document'] ?? null,
                'tax_id' => $tax?->id,
                'tax_amount' => $taxAmount,
                'total_before_tax' => $totalBeforeTax,
                'final_total' => $total,
                'status' => 'final',
                'payment_status' => 'due',
                'type' => 'expense',
                'import_batch' => $importBatchNumber,
                'import_time' => now(),
                'import_file' => DB::table('expense_import_batches')->where('id', $batchId)->value('file_name'),
            ]);
            if ($lockedJournal !== null && $preserveExistingJournalLink) {
                $this->journalLinkService->removeProvenDuplicateExpenseMap($transaction);
            }
        }
        foreach ($lineRows as &$line) {
            $line['transaction_id'] = $transaction->id;
        }
        unset($line);
        ExpenseLine::insert($lineRows);

        $firstDate = $payload['paid_on_date'] ?: $payload['expense_date'];
        $paymentMethod = $payload['payment_method'] ?: 'other';
        if ($paid > 0 || $isExistingTransaction) {
            $accountId = $this->resolveAccountId($payload['payment_account'] ?? '', $businessId);
            $paymentData = [
                'amount' => $paid,
                'paid_on' => $firstDate,
                'method' => $paymentMethod,
                'account_id' => $accountId,
                'note' => $payload['payment_note'] ?? null,
            ];
            for ($i = 1; $i < 8; $i++) {
                if ($paymentMethod === 'custom_pay_'.$i) {
                    $paymentData["transaction_no_{$i}"] = null;
                    break;
                }
            }
            $this->transactionUtil->createOrUpdatePaymentLines($transaction, $paid > 0 ? [$paymentData] : [], $businessId, $userId, false);
            $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);
        }

        $tags = collect(explode(';', (string) ($payload['tags'] ?? '')))->map(fn ($tag) => trim($tag))->filter();
        $tagIds = $tags->map(fn ($tag) => Tag::firstOrCreate(['business_id' => $businessId, 'name' => $tag])->id)->unique()->values()->all();
        if ($tagIds !== [] || $isExistingTransaction) {
            $transaction->tags()->sync($tagIds);
        }

        if ($lockedJournal !== null && ! $preserveExistingJournalLink) {
            $this->journalLinkService->link($lockedJournal, $transaction, $businessId);
        }
    }

    private function lockExpenseReference(int $businessId, string $reference): void
    {
        if (! Schema::hasTable('expense_import_reference_locks')) {
            throw new RuntimeException('Tabel pengunci referensi Expense belum tersedia. Jalankan migration sebelum memproses import.');
        }

        $normalizedReference = $this->normalize($reference);
        if ($normalizedReference === '') {
            return;
        }

        $now = now();
        $referenceHash = hash('sha256', $normalizedReference);
        DB::table('expense_import_reference_locks')->insertOrIgnore([
            'business_id' => $businessId,
            'reference_hash' => $referenceHash,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $lockedReference = DB::table('expense_import_reference_locks')
            ->where('business_id', $businessId)
            ->where('reference_hash', $referenceHash)
            ->lockForUpdate()
            ->first();

        if (! $lockedReference) {
            throw new RuntimeException('Referensi Expense gagal dikunci. Ulangi proses import.');
        }
    }

    private function resolveCategory(string $name, int $businessId, ?int $parentId): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $query = ExpenseCategory::where('business_id', $businessId)->where('name', $name);
        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }
        $category = $query->first();
        if (! $category) {
            $category = ExpenseCategory::create(['business_id' => $businessId, 'name' => $name, 'parent_id' => $parentId]);
        }
        return (int) $category->id;
    }

    private function validCategoryId($id, int $businessId, ?int $parentId = null): ?int
    {
        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }
        $query = ExpenseCategory::where('business_id', $businessId)->whereKey((int) $id);
        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }

        return $query->exists() ? (int) $id : null;
    }

    private function resolveContact(string $value, int $businessId, int $userId): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $contact = Contact::where('business_id', $businessId)
            ->where(function ($query) use ($value) {
                $query->where('contact_id', $value)->orWhere('name', $value)->orWhere('supplier_business_name', $value);
            })->first();
        if ($contact) {
            return (int) $contact->id;
        }
        return (int) Contact::create([
            'business_id' => $businessId, 'type' => 'both', 'name' => $value,
            'supplier_business_name' => $value, 'email' => ' ', 'mobile' => ' ', 'created_by' => $userId,
        ])->id;
    }

    private function resolveUser(string $value, int $businessId, int $userId): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $user = User::where('business_id', $businessId)->where(function ($query) use ($value) {
            $query->where('email', $value)->orWhere('username', $value)->orWhere('first_name', $value)->orWhere('last_name', $value)
                ->orWhereRaw("TRIM(CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) = ?", [$value]);
        })->first();
        if ($user) {
            return (int) $user->id;
        }
        return (int) User::create([
            'business_id' => $businessId, 'created_by' => $userId, 'surname' => ' ', 'first_name' => $value,
            'last_name' => '', 'username' => $value.'_'.substr(md5(uniqid('', true)), 0, 6),
            'email' => $value.'_'.substr(md5(uniqid('', true)), 0, 6).'@example.com',
            'password' => bcrypt(uniqid('', true)),
        ])->id;
    }

    private function resolveTax(string $value, int $businessId): ?TaxRate
    {
        return ($value = trim($value)) === '' ? null : TaxRate::where('business_id', $businessId)->where('name', $value)->first();
    }

    private function resolveAccountId(string $value, int $businessId): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $normalized = strtolower(str_replace([' ', '-'], '', $value));
        $account = Account::where('business_id', $businessId)->whereRaw("LOWER(REPLACE(REPLACE(name, ' ', ''), '-', '')) = ?", [$normalized])->first();
        return $account ? (int) $account->id : null;
    }

    private function parseDate($value, ?string $override = null): array
    {
        $raw = $override !== null ? $override : $value;
        if ($raw === null || trim((string) $raw) === '') {
            return ['value' => null, 'error' => null];
        }
        if ($raw instanceof \DateTimeInterface) {
            return ['value' => $raw->format('Y-m-d H:i:s'), 'error' => null];
        }
        if (is_numeric($raw) && (float) $raw >= 1 && (float) $raw <= 100000) {
            try {
                return ['value' => ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d H:i:s'), 'error' => null];
            } catch (\Throwable $exception) {
                return ['value' => null, 'error' => 'nilai serial Excel tidak valid ('.trim((string) $raw).').'];
            }
        }
        $raw = trim((string) $raw);
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y', 'd.m.Y', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $raw);
                if ($date && $date->format($format) === $raw) {
                    if (strpos($format, 'H') === false) {
                        $date->startOfDay();
                    }
                    return ['value' => $date->format('Y-m-d H:i:s'), 'error' => null];
                }
            } catch (\Throwable $exception) {
                // Try the next supported format.
            }
        }
        return ['value' => null, 'error' => 'format tanggal tidak dikenali ('.mb_substr($raw, 0, 80).'). Gunakan YYYY-MM-DD atau tanggal Excel.'];
    }

    private function parseAmount($value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        $value = preg_replace('/[^0-9,.-]/', '', $value);
        if (substr_count($value, ',') > 0 && substr_count($value, '.') > 0) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (substr_count($value, ',') > 0) {
            $value = strlen(substr(strrchr($value, ','), 1)) <= 2 ? str_replace(',', '.', $value) : str_replace(',', '', $value);
        } elseif (substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }
        return is_numeric($value) ? (float) $value : null;
    }

    private function paymentKey(string $value, array $types): ?string
    {
        if ($value === '') {
            return null;
        }
        foreach ($types as $key => $label) {
            if (strcasecmp(trim((string) $key), $value) === 0 || strcasecmp(trim((string) $label), $value) === 0) {
                return (string) $key;
            }
        }
        return null;
    }

    private function summaryFromRows(array $rows): array
    {
        $valid = count(array_filter($rows, fn ($row) => $row['is_importable']));
        $groups = collect($rows)->filter(fn ($row) => $row['is_importable'])->pluck('group_key')->filter()->unique()->count();
        $groupRows = collect($rows)->groupBy('group_key')->map(fn ($group) => $group->first());
        $matchedGroups = $groupRows->filter(function ($row) {
            return (($row['normalized_payload']['journal_match']['status'] ?? null) === 'matched');
        })->count();
        $reviewGroups = $groupRows->filter(function ($row) {
            return in_array(($row['normalized_payload']['journal_match']['status'] ?? null), ['ambiguous', 'selection_invalid', 'unavailable'], true);
        })->count();
        $existingUpdatedGroups = $groupRows->filter(function ($row) {
            return str_starts_with((string) ($row['normalized_payload']['existing_expense_match']['action'] ?? ''), 'merge:');
        })->count();
        $existingReviewGroups = $groupRows->filter(function ($row) {
            $match = $row['normalized_payload']['existing_expense_match'] ?? [];

            return ! empty($match['review_required']) && empty($match['action']);
        })->count();
        $skipped = count(array_filter($rows, fn ($row) => ($row['status'] ?? null) === 'skipped'));
        $errors = count(array_filter($rows, fn ($row) => ($row['status'] ?? null) === 'error'));

        return [
            'total' => count($rows), 'valid' => $valid, 'errors' => $errors,
            'importable' => $valid, 'groups' => $groups, 'skipped' => $skipped,
            'journal_matched_groups' => $matchedGroups, 'journal_review_groups' => $reviewGroups,
            'existing_expense_updated_groups' => $existingUpdatedGroups,
            'existing_expense_review_groups' => $existingReviewGroups,
        ];
    }

    private function validateGroupTaxes(array $rows): array
    {
        $groupIndexes = [];
        foreach ($rows as $index => $row) {
            $groupIndexes[$row['group_key']][] = $index;
        }

        foreach ($groupIndexes as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $journalMappingIds = [];
            foreach ($indexes as $index) {
                $journalMatch = $rows[$index]['normalized_payload']['journal_match'] ?? [];
                if (($journalMatch['status'] ?? null) !== 'matched') {
                    $journalMappingIds = [];
                    break;
                }
                $journalMappingIds[] = (int) ($journalMatch['selected']['mapping_id'] ?? 0);
            }
            if ($journalMappingIds !== [] && count(array_unique($journalMappingIds)) === 1 && $journalMappingIds[0] > 0) {
                continue;
            }

            $knownTaxIds = [];
            $hasUnknownTax = false;
            foreach ($indexes as $index) {
                $payload = $rows[$index]['normalized_payload'];
                if (! empty($payload['tax_id'])) {
                    $knownTaxIds[(int) $payload['tax_id']] = $payload;
                } elseif (($payload['tax_status'] ?? null) === 'unknown') {
                    $hasUnknownTax = true;
                }
            }

            if (count($knownTaxIds) === 1 && ! $hasUnknownTax) {
                $taxId = array_key_first($knownTaxIds);
                $taxPayload = reset($knownTaxIds);
                foreach ($indexes as $index) {
                    if (($rows[$index]['normalized_payload']['tax_status'] ?? null) === 'none') {
                        $rows[$index]['normalized_payload']['tax_id'] = $taxId;
                        $rows[$index]['normalized_payload']['tax_name'] = $taxPayload['tax_name'];
                        $rows[$index]['normalized_payload']['applicable_tax'] = $taxPayload['applicable_tax'];
                        $rows[$index]['normalized_payload']['tax_status'] = 'inherited';
                    }
                }
            }

            $taxKeys = [];
            foreach ($indexes as $index) {
                $payload = $rows[$index]['normalized_payload'];
                if (! empty($payload['tax_id'])) {
                    $taxKeys[] = 'tax:'.(int) $payload['tax_id'];
                } elseif (trim((string) ($payload['original_applicable_tax'] ?? '')) !== '') {
                    $taxKeys[] = 'unknown:'.$this->normalize($payload['original_applicable_tax']);
                } else {
                    $taxKeys[] = 'none';
                }
            }

            if (count(array_unique($taxKeys)) < 2) {
                continue;
            }

            foreach ($indexes as $index) {
                $rows[$index]['errors'][] = 'Satu transaksi dengan REFERENCE NO yang sama harus menggunakan satu TaxRate atau grup pajak pada semua baris.';
                $rows[$index]['errors'] = array_values(array_unique($rows[$index]['errors']));
                $rows[$index]['status'] = 'error';
                $rows[$index]['is_importable'] = false;
            }
        }

        return $rows;
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function headerKey($value): string
    {
        $value = strtoupper(trim((string) $value));
        return preg_replace('/\s+/', ' ', preg_replace('/^\xEF\xBB\xBF/', '', $value));
    }

    private function stringValue($value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function normalize($value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));
    }
}
