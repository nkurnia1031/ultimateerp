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
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

class ExpenseImportService
{
    private const REQUIRED_HEADERS = [
        'LOCATION', 'CATEGORY', 'SUB-CATEGORY', 'DESCRIPTION', 'REFERENCE NO',
        'TAGS', 'EXPENSE DATE', 'EXPENSE FOR', 'EXPENSE FOR CONTACT ID',
        'ATTACH DOCUMENT', 'APPLICABLE TAX', 'EXPENSE NOTE', 'TOTAL AMOUNT',
        'PAID AMOUNT', 'PAID ON DATE', 'PAYMENT METHOD', 'PAYMENT ACCOUNT',
        'PAYMENT ACCOUND', 'PAYMENT NOTE',
    ];

    public function __construct(private TransactionUtil $transactionUtil)
    {
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
        $rows = $this->validateGroupTaxes($rows);
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
                    'skip_reason' => $row['is_importable'] ? null : ($row['errors'][0] ?? null),
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
        $counts = DB::table('expense_import_preview_rows')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) ($counts['valid'] ?? 0) + (int) ($counts['error'] ?? 0),
            'valid' => (int) ($counts['valid'] ?? 0),
            'errors' => (int) ($counts['error'] ?? 0),
            'importable' => (int) DB::table('expense_import_preview_rows')
                ->where('batch_id', $batchId)->where('business_id', $businessId)->where('is_importable', true)->count(),
            'groups' => (int) DB::table('expense_import_preview_rows')
                ->where('batch_id', $batchId)->where('business_id', $businessId)->where('is_importable', true)
                ->distinct('group_key')->count('group_key'),
            'skipped' => (int) ($counts['skipped'] ?? 0),
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
        $taxes = TaxRate::where('business_id', $businessId)->get();
        $taxesByName = $taxes->keyBy(fn ($tax) => $this->normalize($tax->name));
        $taxesById = $taxes->keyBy('id');
        $paymentTypes = $this->transactionUtil->payment_types(null, false, $businessId);

        return compact('locations', 'categories', 'categoryByName', 'contacts', 'users', 'taxesByName', 'taxesById', 'paymentTypes');
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
        $taxOverrideId = $originalTaxName !== ''
            ? ($overrides['tax_name:'.$this->normalize($originalTaxName)] ?? null)
            : null;
        $tax = is_numeric($taxOverrideId)
            ? $context['taxesById']->get((int) $taxOverrideId)
            : null;
        $matchedTax = $originalTaxName !== ''
            ? $context['taxesByName']->get($this->normalize($originalTaxName))
            : null;
        $taxStatus = $originalTaxName === '' ? 'none' : 'matched';
        if ($tax) {
            $taxStatus = 'mapped';
        } else {
            $tax = $matchedTax;
        }
        if (! $tax && $originalTaxName !== '') {
            $taxStatus = 'unknown';
            $errors[] = "Tax '{$originalTaxName}' tidak dikenal. Pilih TaxRate yang tersedia dari preview.";
        }

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
            'applicable_tax' => $tax?->name ?? $originalTaxName,
            'original_applicable_tax' => $originalTaxName,
            'tax_id' => $tax?->id,
            'tax_name' => $tax?->name,
            'tax_status' => $taxStatus,
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
            'group_key' => $normalized['reference_no'] !== '' ? 'ref:'.$normalized['reference_no'] : 'row:'.$sourceRow,
            'raw_payload' => $row,
            'normalized_payload' => $normalized,
            'errors' => array_values(array_unique($errors)),
            'status' => $errors === [] ? 'valid' : 'error',
            'is_importable' => $errors === [],
        ];
    }

    private function importGroup($rows, int $businessId, int $userId, string $batchId, int $importBatchNumber): void
    {
        $first = $rows->first();
        $payload = json_decode((string) $first->normalized_payload, true) ?: [];
        $categoryId = $this->validCategoryId($payload['category_id'] ?? null, $businessId)
            ?: $this->resolveCategory($payload['category_name'] ?? '', $businessId, null);

        $taxName = trim((string) ($payload['applicable_tax'] ?? ''));
        $originalTaxName = trim((string) ($payload['original_applicable_tax'] ?? $taxName));
        $tax = ! empty($payload['tax_id'])
            ? TaxRate::where('business_id', $businessId)->find((int) $payload['tax_id'])
            : null;
        if (! $tax && $taxName !== '') {
            $tax = $this->resolveTax($taxName, $businessId);
        }
        if ($originalTaxName !== '' && ! $tax) {
            throw new RuntimeException("Tax '{$originalTaxName}' tidak lagi tersedia. Buka ulang preview dan petakan ke TaxRate yang aktif.");
        }
        $taxPercentage = 0.0;
        if ($tax) {
            $tax->loadMissing('sub_taxes');
            $taxPercentage = $tax->is_tax_group
                ? (float) $tax->sub_taxes->where('business_id', $businessId)->sum('amount')
                : (float) $tax->amount;
            if ($tax->is_tax_group && $taxPercentage <= 0) {
                throw new RuntimeException("Grup pajak '{$tax->name}' tidak memiliki komponen aktif yang valid.");
            }
        }

        $lineRows = [];
        $total = 0;
        $totalBeforeTax = 0;
        $paid = 0;
        foreach ($rows as $row) {
            $item = json_decode((string) $row->normalized_payload, true) ?: [];
            $lineCategoryId = $this->validCategoryId($item['category_id'] ?? null, $businessId)
                ?: $this->resolveCategory($item['category_name'] ?? '', $businessId, null);
            $lineSubCategoryId = $this->validCategoryId($item['sub_category_id'] ?? null, $businessId, $lineCategoryId)
                ?: $this->resolveCategory($item['sub_category_name'] ?? '', $businessId, $lineCategoryId);
            $inclusiveAmount = (float) ($item['total_amount'] ?? 0);
            $lineBaseAmount = $tax
                ? $this->transactionUtil->calc_percentage_base($inclusiveAmount, $taxPercentage)
                : $inclusiveAmount;
            $total += $inclusiveAmount;
            $totalBeforeTax += $lineBaseAmount;
            $paid += (float) ($item['paid_amount'] ?? 0);
            $lineRows[] = [
                'category_id' => $lineCategoryId,
                'sub_category_id' => $lineSubCategoryId,
                'description' => $item['description'] ?? null,
                'amount' => $lineBaseAmount,
            ];
        }

        $taxAmount = $total - $totalBeforeTax;
        $contactId = $this->resolveContact($payload['expense_for_contact_id'] ?? '', $businessId, $userId);
        $expenseFor = $this->resolveUser($payload['expense_for'] ?? '', $businessId, $userId);
        $reference = trim((string) ($payload['reference_no'] ?? ''));
        if ($reference === '') {
            $reference = $this->transactionUtil->generateReferenceNumber('expense', $this->transactionUtil->setAndGetReferenceCount('expense', $businessId), $businessId);
        }
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
        foreach ($lineRows as &$line) {
            $line['transaction_id'] = $transaction->id;
        }
        unset($line);
        ExpenseLine::insert($lineRows);

        $firstDate = $payload['paid_on_date'] ?: $payload['expense_date'];
        $paymentMethod = $payload['payment_method'] ?: 'other';
        if ($paid > 0) {
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
            $this->transactionUtil->createOrUpdatePaymentLines($transaction, [$paymentData], $businessId, $userId, false);
            $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);
        }

        $tags = collect(explode(';', (string) ($payload['tags'] ?? '')))->map(fn ($tag) => trim($tag))->filter();
        if ($tags->isNotEmpty()) {
            $tagIds = $tags->map(fn ($tag) => Tag::firstOrCreate(['business_id' => $businessId, 'name' => $tag])->id)->unique()->values()->all();
            $transaction->tags()->sync($tagIds);
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
        return ['total' => count($rows), 'valid' => $valid, 'errors' => count($rows) - $valid, 'importable' => $valid, 'groups' => $groups, 'skipped' => 0];
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
