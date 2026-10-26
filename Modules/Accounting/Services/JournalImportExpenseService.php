<?php

namespace Modules\Accounting\Services;

use App\Transaction;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalImportExpenseService
{
    private $draftService;

    private $taxResolver;

    private $transactionUtil;

    private $expenseTotalsService;

    public function __construct(
        JournalImportDraftService $draftService,
        JournalImportTaxResolver $taxResolver,
        TransactionUtil $transactionUtil
    ) {
        $this->draftService = $draftService;
        $this->taxResolver = $taxResolver;
        $this->transactionUtil = $transactionUtil;
        $this->expenseTotalsService = new JournalImportExpenseTotalsService($transactionUtil);
    }

    public function readinessIssue(object $row, array $entry, int $businessId, array $options = []): ?string
    {
        try {
            $this->prepare($row, $entry, $businessId, $options, false);

            return null;
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }
    }

    public function draftTotal(object $row, array $entry, object $import, array $options = []): ?float
    {
        try {
            $prepared = $this->prepare($row, $entry, (int) $import->business_id, $options, false);
            if ($prepared['action'] === 'link' && $prepared['transaction']->final_total !== null) {
                return (float) $prepared['transaction']->final_total;
            }
            if ($prepared['action'] === 'update' && $prepared['transaction']->final_total !== null) {
                return (float) $prepared['transaction']->final_total;
            }

            return $prepared['totals']['final_total'] ?? null;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function explainsTaxDifference(object $row, array $entry, int $businessId, float $journalTotal, array $options = []): bool
    {
        if (($row->source_type ?? null) !== 'Expense'
            || ! in_array((string) ($row->source_action ?? ''), ['link', 'update'], true)
            || ($row->source_record_type ?? null) !== Transaction::class
            || empty($row->source_record_id)) {
            return false;
        }

        $transaction = Transaction::where('business_id', $businessId)
            ->where('type', 'expense')
            ->find((int) $row->source_record_id);
        if (! $transaction) {
            return false;
        }

        $components = (new JournalImportAccountComponentService())->groupJournalLines($entry, $businessId, $options);
        $expenseComponents = array_values(array_filter($components, fn ($component) => ! $component['is_tax'] && $component['debit'] > 0));
        $taxComponents = array_values(array_filter($components, fn ($component) => $component['is_tax'] && $component['debit'] > 0));

        return $this->expenseTotalsService->explainsTaxDifference([
            'total_before_tax' => $transaction->total_before_tax,
            'tax_amount' => $transaction->tax_amount,
            'final_total' => $transaction->final_total,
        ], $journalTotal, $expenseComponents, $taxComponents);
    }

    public function process(object $row, array $entry, object $import, array $options = []): Transaction
    {
        $prepared = $this->prepare($row, $entry, (int) $import->business_id, $options, true);
        $action = (string) $row->source_action;

        if ($action === 'link') {
            $this->saveMutationSnapshot($row, [
                'action' => 'linked',
                'transaction_id' => $prepared['transaction']->id,
            ]);

            return $prepared['transaction'];
        }

        if ($action === 'create') {
            $transaction = $this->createExpense($row, $entry, $import, $prepared);
            $this->saveMutationSnapshot($row, [
                'action' => 'created',
                'transaction_id' => $transaction->id,
                'expected' => $this->expectedValues($transaction),
                'expected_lines' => $this->lineStates($transaction),
            ]);

            return $transaction;
        }

        $transaction = $prepared['transaction'];
        $snapshot = $this->applyUpdate($transaction, $entry, $prepared);
        $snapshot['action'] = 'updated';
        $snapshot['transaction_id'] = $transaction->id;
        $this->saveMutationSnapshot($row, $snapshot);

        return $transaction->fresh();
    }

    public function revertImport(int $importId, int $businessId): int
    {
        $rows = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->where('source_type', 'Expense')
            ->whereNotNull('source_mutation_snapshot')
            ->lockForUpdate()
            ->get();

        $reverted = 0;
        foreach ($rows as $row) {
            $snapshot = json_decode((string) $row->source_mutation_snapshot, true);
            if (! is_array($snapshot)) {
                throw new \RuntimeException('Snapshot Expense tidak valid; revert dibatalkan agar data tidak tertimpa.');
            }
            if (($snapshot['action'] ?? null) === 'linked') {
                continue;
            }

            $transaction = Transaction::where('business_id', $businessId)
                ->where('type', 'expense')
                ->lockForUpdate()
                ->find($snapshot['transaction_id'] ?? null);
            if (! $transaction) {
                continue;
            }

            if (($snapshot['action'] ?? null) === 'created') {
                $this->revertCreatedExpense($transaction, $snapshot);
                $reverted++;
            } elseif (($snapshot['action'] ?? null) === 'updated') {
                $this->revertUpdatedExpense($transaction, $snapshot);
                $reverted++;
            }
        }

        return $reverted;
    }

    private function prepare(object $row, array $entry, int $businessId, array $options, bool $lock): array
    {
        if (($row->source_type ?? null) !== 'Expense') {
            throw new \RuntimeException('Handler Expense hanya mendukung baris Expense.');
        }

        $action = (string) ($row->source_action ?? 'review');
        if ($action === 'review') {
            throw new \RuntimeException('Pilih sumber Expense atau pilih Buat baru secara eksplisit.');
        }
        $recordType = $row->source_record_type ?? null;
        $recordId = empty($row->source_record_id) ? null : (int) $row->source_record_id;
        if (! $this->draftService->isValidSourceDecision($entry, $action, $recordType, $recordId)) {
            throw new \RuntimeException('Pilihan sumber Expense tidak valid; pilih kandidat yang tersedia atau Buat baru secara eksplisit.');
        }

        $transaction = null;
        if (in_array($action, ['link', 'update'], true)) {
            if ($recordType !== Transaction::class || $recordId === null) {
                throw new \RuntimeException('Pilih Expense yang akan ditautkan atau dilengkapi.');
            }
            $query = Transaction::where('business_id', $businessId)->where('type', 'expense');
            if ($lock) {
                $query->lockForUpdate();
            }
            $transaction = $query->find($recordId);
            if (! $transaction) {
                throw new \RuntimeException('Expense pilihan tidak ditemukan pada business aktif.');
            }
        } elseif ($action !== 'create') {
            throw new \RuntimeException('Aksi draft Expense belum siap diproses.');
        }

        $existingLines = $transaction ? $this->existingLines($transaction) : [];
        if ($action === 'link') {
            $this->assertLinkReady($transaction, $existingLines);

            return compact('action', 'transaction', 'existingLines') + [
                'draft_data' => [],
                'category_id' => $transaction->expense_category_id,
                'location_id' => $transaction->location_id,
                'tax' => null,
                'totals' => ['total_before_tax' => $transaction->total_before_tax, 'tax' => $transaction->tax_amount, 'final_total' => $transaction->final_total],
                'line' => null,
                'line_changes' => [],
            ];
        }

        $draftData = json_decode((string) ($row->draft_data ?? '{}'), true) ?: [];
        try {
            $draftData = $this->draftService->validateDraftData($draftData, $businessId, 'Expense');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $first = reset($errors);
            throw new \RuntimeException(is_array($first) ? (string) reset($first) : 'Detail Expense tidak valid.');
        }

        $draftCategoryId = empty($draftData['expense_category_id']) ? null : (int) $draftData['expense_category_id'];
        $draftLocationId = empty($draftData['location_id']) ? null : (int) $draftData['location_id'];
        $effectiveCategoryId = $transaction && ! empty($transaction->expense_category_id)
            ? (int) $transaction->expense_category_id
            : $draftCategoryId;
        $effectiveLocationId = $transaction && ! empty($transaction->location_id)
            ? (int) $transaction->location_id
            : $draftLocationId;
        $entryDate = $this->normalizedDateOrNull($entry['date'] ?? null);
        $sourceNumber = trim((string) ($entry['source_number'] ?? $row->source_number ?? ''));
        $journalTotal = $this->journalTotal($entry);

        if ($action === 'create') {
            if ($effectiveLocationId === null) {
                throw new \RuntimeException('Pilih lokasi sebelum membuat Expense.');
            }
            if ($entryDate === null) {
                throw new \RuntimeException('Tanggal Expense wajib tersedia dan harus valid.');
            }
            if ($journalTotal === null || $journalTotal <= 0) {
                throw new \RuntimeException('Total debit Journal harus lebih besar dari nol untuk membuat Expense.');
            }
        } else {
            $this->assertNoConflictingValues($transaction, $draftData, $entry, $existingLines);
            if ($effectiveLocationId === null) {
                throw new \RuntimeException('Pilih lokasi untuk melengkapi Expense.');
            }
            if (empty($transaction->transaction_date) && $entryDate === null) {
                throw new \RuntimeException('Tanggal Expense belum tersedia dan tanggal Journal tidak valid.');
            }
        }

        $components = (new JournalImportAccountComponentService())->groupJournalLines($entry, $businessId, $options);
        $expenseComponents = array_values(array_filter($components, fn ($component) => ! $component['is_tax'] && $component['debit'] > 0));
        $taxComponents = array_values(array_filter($components, fn ($component) => $component['is_tax'] && $component['debit'] > 0));
        $taxableBase = $existingLines !== []
            ? array_sum(array_map(fn ($line) => (float) $line->amount, $existingLines))
            : null;
        if (($taxableBase === null || $taxableBase <= 0) && $transaction && (float) $transaction->total_before_tax > 0) {
            $taxableBase = (float) $transaction->total_before_tax;
        }
        if (($taxableBase === null || $taxableBase <= 0) && $expenseComponents !== []) {
            $taxableBase = (float) collect($expenseComponents)->sum('debit');
        }
        $tax = $this->taxResolver->resolveForEntry($entry, $businessId, $taxableBase);
        $totals = $this->totalsForSource($transaction, $journalTotal, $tax, $expenseComponents, $taxComponents);
        $lines = [];
        $lineChanges = [];
        if ($existingLines === []) {
            if ($expenseComponents === []) {
                if ($effectiveCategoryId === null) {
                    throw new \RuntimeException('Pilih kategori Expense sebelum menambahkan detail.');
                }
                $lines[] = [
                    'category_id' => $effectiveCategoryId,
                    'sub_category_id' => null,
                    'description' => trim((string) ($entry['description'] ?? '')) ?: null,
                    'amount' => $totals['total_before_tax'],
                ];
            } else {
                $categoryMappings = is_array($draftData['expense_category_mappings'] ?? null)
                    ? $draftData['expense_category_mappings']
                    : [];
                foreach ($expenseComponents as $component) {
                    $categoryId = ! empty($categoryMappings[$component['account_key']])
                        ? (int) $categoryMappings[$component['account_key']]
                        : (count($expenseComponents) === 1 ? $effectiveCategoryId : null);
                    if ($categoryId === null) {
                        $label = $component['account_name'] !== '' ? $component['account_name'] : $component['account_no'];
                        throw new \RuntimeException('Pilih kategori Expense untuk akun Journal "'.$label.'" sebelum melanjutkan.');
                    }
                    $accountLabel = trim($component['account_no'].' '.$component['account_name']);
                    $description = trim((string) ($entry['description'] ?? ''));
                    $lines[] = [
                        'category_id' => $categoryId,
                        'sub_category_id' => null,
                        'description' => trim(implode(' · ', array_filter([$description, $accountLabel]))) ?: null,
                        'amount' => $component['debit'],
                    ];
                }
            }
            if ($effectiveCategoryId === null && $lines !== []) {
                $effectiveCategoryId = (int) $lines[0]['category_id'];
            }
        } else {
            $lineTotal = array_sum(array_map(fn ($item) => (float) $item->amount, $existingLines));
            if (abs(round($lineTotal - (float) $totals['total_before_tax'], 4)) > 0.01) {
                throw new \RuntimeException('Total detail Expense berbeda dari basis sebelum pajak; tinjau Expense sebelum melanjutkan.');
            }
        }

        if ($tax !== null && abs((float) ($totals['calculated_tax'] ?? 0) - (float) $totals['tax']) > 0.01) {
            throw new \RuntimeException('Pajak Expense hasil hitung tidak sesuai dengan total inklusif; tinjau komponen pajak sebelum melanjutkan.');
        }
        if ($taxComponents !== [] && abs((float) collect($taxComponents)->sum('debit') - (float) $totals['tax']) > 0.01) {
            throw new \RuntimeException('Nilai akun pajak Journal berbeda dari nilai pajak Expense; tinjau transaksi sebelum melanjutkan.');
        }
        if ($transaction && $tax !== null) {
            $this->assertTaxDoesNotConflict($transaction, $tax, $totals);
        }

        return compact('action', 'transaction', 'draftData', 'effectiveCategoryId', 'effectiveLocationId', 'entryDate', 'sourceNumber', 'journalTotal', 'tax', 'totals', 'lines', 'existingLines', 'lineChanges') + [
            'category_id' => $effectiveCategoryId,
            'location_id' => $effectiveLocationId,
        ];
    }

    private function assertLinkReady(Transaction $transaction, array $lines): void
    {
        if (empty($transaction->location_id) || empty($transaction->transaction_date) || $transaction->final_total === null) {
            throw new \RuntimeException('Expense pilihan belum memiliki lokasi, tanggal, atau total; pilih Lengkapi sumber.');
        }
        if ($lines === []) {
            throw new \RuntimeException('Expense pilihan belum memiliki detail kategori; pilih Lengkapi sumber.');
        }
        if (empty($transaction->expense_category_id) && ! collect($lines)->contains(fn ($line) => ! empty($line->category_id))) {
            throw new \RuntimeException('Expense pilihan belum memiliki kategori; pilih Lengkapi sumber.');
        }
        foreach ($lines as $line) {
            if (! is_numeric($line->amount) || (float) $line->amount <= 0) {
                throw new \RuntimeException('Detail kategori Expense belum memiliki nominal yang valid; pilih Lengkapi sumber.');
            }
        }
        if ($transaction->total_before_tax !== null) {
            $lineTotal = array_sum(array_map(fn ($line) => (float) $line->amount, $lines));
            if (abs(round($lineTotal - (float) $transaction->total_before_tax, 4)) > 0.01) {
                throw new \RuntimeException('Total detail Expense berbeda dari basis sebelum pajak; pilih Lengkapi sumber.');
            }
        }
    }

    private function assertNoConflictingValues(Transaction $transaction, array $draftData, array $entry, array $existingLines): void
    {
        foreach (['contact_id', 'location_id', 'expense_category_id'] as $field) {
            $column = $field === 'expense_category_id' ? 'expense_category_id' : $field;
            $proposed = $field === 'expense_category_id' ? ($draftData[$field] ?? null) : ($draftData[$field] ?? null);
            if ($proposed !== null && ! empty($transaction->{$column}) && (int) $transaction->{$column} !== (int) $proposed) {
                $label = $field === 'contact_id' ? 'Kontak' : ($field === 'location_id' ? 'lokasi' : 'kategori Expense');
                throw new \RuntimeException($label.' pada sumber berbeda dari pilihan draft; tinjau sumber sebelum melanjutkan.');
            }
        }

        $sourceNumber = trim((string) ($entry['source_number'] ?? ''));
        if ($sourceNumber !== '' && ! empty($transaction->ref_no) && strcasecmp((string) $transaction->ref_no, $sourceNumber) !== 0) {
            throw new \RuntimeException('Nomor Expense berbeda dari nomor Journal; tinjau sumber sebelum melanjutkan.');
        }

        $entryDate = $this->normalizedDateOrNull($entry['date'] ?? null);
        $sourceDate = $this->normalizedDateOrNull((string) $transaction->transaction_date);
        if ($entryDate !== null && $sourceDate !== null && $entryDate !== $sourceDate) {
            throw new \RuntimeException('Tanggal Expense berbeda dari tanggal Journal; tinjau sumber sebelum melanjutkan.');
        }

        foreach ($existingLines as $line) {
            if (! is_numeric($line->amount) || (float) $line->amount < 0) {
                throw new \RuntimeException('Detail kategori Expense belum memiliki nominal yang valid; lengkapi sumber sebelum melanjutkan.');
            }
        }
    }

    private function assertTaxDoesNotConflict(Transaction $transaction, array $tax, array $totals): void
    {
        if (isset($totals['calculated_tax'])
            && abs((float) $totals['calculated_tax'] - (float) $totals['tax']) > 0.01) {
            throw new \RuntimeException('Pajak Expense hasil hitung tidak sesuai dengan total inklusif; tinjau komponen pajak sebelum melanjutkan.');
        }
        if ($transaction->tax_id !== null && (int) $transaction->tax_id !== (int) $tax['id']) {
            throw new \RuntimeException('TaxRate Expense berbeda dari hasil hitung komponen Journal; tinjau sumber sebelum melanjutkan.');
        }
        if ($transaction->tax_amount !== null && (float) $transaction->tax_amount > 0
            && abs((float) $transaction->tax_amount - (float) $totals['tax']) > 0.01) {
            throw new \RuntimeException('Pajak Expense berbeda dari nominal komponen Journal; tinjau sumber sebelum melanjutkan.');
        }
        $taxOnlyCorrection = (new JournalImportTaxCorrectionPolicy())->shouldCorrectFinalTotal(
            $transaction->tax_amount,
            $transaction->total_before_tax,
            $transaction->final_total,
            $totals
        );
        if ($transaction->final_total !== null
            && abs((float) $transaction->final_total - (float) $totals['final_total']) > 0.01
            && ! $taxOnlyCorrection) {
            throw new \RuntimeException('Total Expense berbeda dari hasil hitung pajak Journal; tinjau sumber sebelum melanjutkan.');
        }
    }

    private function totalsForSource(?Transaction $transaction, ?float $journalTotal, ?array $tax, array $expenseComponents = [], array $taxComponents = []): array
    {
        $source = $transaction === null ? null : [
            'total_before_tax' => $transaction->total_before_tax,
            'tax_amount' => $transaction->tax_amount,
            'final_total' => $transaction->final_total,
        ];

        return $this->expenseTotalsService->forSource($source, $journalTotal, $tax, $expenseComponents, $taxComponents);
    }

    private function createExpense(object $row, array $entry, object $import, array $prepared): Transaction
    {
        $sourceNumber = trim((string) ($prepared['sourceNumber'] ?? ''));
        $reference = $sourceNumber !== ''
            ? $sourceNumber
            : $this->transactionUtil->generateReferenceNumber(
                'expense',
                $this->transactionUtil->setAndGetReferenceCount('expense', (int) $import->business_id),
                (int) $import->business_id
            );
        $draftData = $prepared['draftData'];
        $totals = $prepared['totals'];

        $transaction = Transaction::create([
            'business_id' => (int) $import->business_id,
            'location_id' => (int) $prepared['location_id'],
            'created_by' => (int) $import->created_by,
            'expense_category_id' => (int) $prepared['category_id'],
            'expense_sub_category_id' => null,
            'contact_id' => $draftData['contact_id'] ?? null,
            'ref_no' => $reference,
            'transaction_date' => $prepared['entryDate'],
            'additional_notes' => trim((string) ($entry['description'] ?? '')) ?: null,
            'tax_id' => $prepared['tax']['id'] ?? null,
            'tax_amount' => $totals['tax'],
            'total_before_tax' => $totals['total_before_tax'],
            'final_total' => $totals['final_total'],
            'status' => 'final',
            'payment_status' => 'due',
            'type' => 'expense',
            'exchange_rate' => 1,
            'discount_type' => null,
            'discount_amount' => 0,
        ]);

        foreach ($prepared['lines'] as $line) {
            $this->insertLine($transaction, $line);
        }

        return $transaction;
    }

    private function applyUpdate(Transaction $transaction, array $entry, array $prepared): array
    {
        $draftData = $prepared['draftData'];
        $correctMissingTaxTotal = (new JournalImportTaxCorrectionPolicy())->shouldCorrectFinalTotal(
            $transaction->tax_amount,
            $transaction->total_before_tax,
            $transaction->final_total,
            $prepared['totals']
        );
        $before = [];
        $expected = [];
        $fields = [
            'location_id' => $prepared['location_id'],
            'expense_category_id' => $prepared['category_id'],
            'contact_id' => $draftData['contact_id'] ?? null,
            'transaction_date' => $prepared['entryDate'],
            'ref_no' => $prepared['sourceNumber'] !== '' ? $prepared['sourceNumber'] : null,
            'additional_notes' => trim((string) ($entry['description'] ?? '')) ?: null,
        ];

        foreach ($fields as $field => $value) {
            $existing = $transaction->{$field};
            if ($existing !== null && $existing !== '') {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $before[$field] = $existing;
            $transaction->{$field} = $value;
            $expected[$field] = $value;
        }

        if ($transaction->total_before_tax === null) {
            $before['total_before_tax'] = null;
            $transaction->total_before_tax = $prepared['totals']['total_before_tax'];
            $expected['total_before_tax'] = $transaction->total_before_tax;
        }
        if ($transaction->tax_id === null && ! empty($prepared['tax']['id'])) {
            $before['tax_id'] = null;
            $transaction->tax_id = $prepared['tax']['id'];
            $expected['tax_id'] = $transaction->tax_id;
        }
        if (($transaction->tax_amount === null || (float) $transaction->tax_amount == 0.0) && (float) $prepared['totals']['tax'] > 0) {
            $before['tax_amount'] = $transaction->tax_amount;
            $transaction->tax_amount = $prepared['totals']['tax'];
            $expected['tax_amount'] = $transaction->tax_amount;
        }
        if ($transaction->final_total === null || $correctMissingTaxTotal) {
            $before['final_total'] = $transaction->final_total;
            $transaction->final_total = $prepared['totals']['final_total'];
            $expected['final_total'] = $transaction->final_total;
        }

        $createdLineIds = [];
        if ($prepared['existingLines'] === [] && $prepared['lines'] !== []) {
            foreach ($prepared['lines'] as $line) {
                $this->insertLine($transaction, $line);
            }
            $createdLineIds = $this->lineIds($transaction);
        }

        $transaction->save();

        return [
            'fields_before' => $before,
            'fields_expected' => $expected,
            'created_line_ids' => $createdLineIds,
            'created_lines_expected' => $createdLineIds === [] ? [] : $this->lineStates($transaction, $createdLineIds),
        ];
    }

    private function insertLine(Transaction $transaction, array $line): void
    {
        DB::table('expense_lines')->insert([
            'transaction_id' => $transaction->id,
            'category_id' => $line['category_id'],
            'sub_category_id' => $line['sub_category_id'] ?? null,
            'description' => $line['description'] ?? null,
            'amount' => $line['amount'],
            'document' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function journalTotal(array $entry): ?float
    {
        if (isset($entry['debit_total']) && is_numeric($entry['debit_total'])) {
            return round((float) $entry['debit_total'], 4);
        }
        if (empty($entry['lines']) || ! is_array($entry['lines'])) {
            return null;
        }

        $total = collect($entry['lines'])
            ->filter(fn ($line) => strtolower((string) ($line['type'] ?? '')) === 'debit')
            ->sum(fn ($line) => (float) ($line['debit'] ?? $line['amount'] ?? 0));

        return is_finite((float) $total) ? round((float) $total, 4) : null;
    }

    private function existingLines(Transaction $transaction): array
    {
        return DB::table('expense_lines')->where('transaction_id', $transaction->id)->orderBy('id')->get()->all();
    }

    private function expectedValues(Transaction $transaction): array
    {
        return [
            'business_id' => (int) $transaction->business_id,
            'location_id' => $transaction->location_id === null ? null : (int) $transaction->location_id,
            'type' => (string) $transaction->type,
            'status' => (string) $transaction->status,
            'contact_id' => $transaction->contact_id === null ? null : (int) $transaction->contact_id,
            'expense_category_id' => $transaction->expense_category_id === null ? null : (int) $transaction->expense_category_id,
            'transaction_date' => $this->normalizedDateOrNull((string) $transaction->transaction_date),
            'ref_no' => $transaction->ref_no,
            'total_before_tax' => $transaction->total_before_tax,
            'tax_id' => $transaction->tax_id === null ? null : (int) $transaction->tax_id,
            'tax_amount' => $transaction->tax_amount,
            'final_total' => $transaction->final_total,
        ];
    }

    private function lineIds(Transaction $transaction, array $onlyIds = []): array
    {
        $query = DB::table('expense_lines')->where('transaction_id', $transaction->id);
        if ($onlyIds !== []) {
            $query->whereIn('id', $onlyIds);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function lineStates(Transaction $transaction, array $onlyIds = []): array
    {
        $query = DB::table('expense_lines')->where('transaction_id', $transaction->id);
        if ($onlyIds !== []) {
            $query->whereIn('id', $onlyIds);
        }

        return $query->orderBy('id')->get(['id', 'category_id', 'sub_category_id', 'description', 'amount'])
            ->map(fn ($line) => [
                'id' => (int) $line->id,
                'category_id' => $line->category_id === null ? null : (int) $line->category_id,
                'sub_category_id' => $line->sub_category_id === null ? null : (int) $line->sub_category_id,
                'description' => $line->description,
                'amount' => number_format((float) $line->amount, 4, '.', ''),
            ])->all();
    }

    private function sameLineStates(array $actual, array $expected): bool
    {
        $normalize = function (array $lines): array {
            $normalized = array_map(fn ($line) => [
                'id' => (int) ($line['id'] ?? 0),
                'category_id' => ($line['category_id'] ?? null) === null ? null : (int) $line['category_id'],
                'sub_category_id' => ($line['sub_category_id'] ?? null) === null ? null : (int) $line['sub_category_id'],
                'description' => $line['description'] ?? null,
                'amount' => number_format((float) ($line['amount'] ?? 0), 4, '.', ''),
            ], $lines);
            usort($normalized, fn ($left, $right) => $left['id'] <=> $right['id']);

            return $normalized;
        };

        return $normalize($actual) === $normalize($expected);
    }

    private function sameTransactionValue(string $field, $actual, $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }
        if ($field === 'transaction_date') {
            return $this->normalizedDateOrNull((string) $actual) === $this->normalizedDateOrNull((string) $expected);
        }
        if (in_array($field, ['total_before_tax', 'tax_amount', 'final_total'], true)) {
            return number_format((float) $actual, 4, '.', '') === number_format((float) $expected, 4, '.', '');
        }

        return (string) $actual === (string) $expected;
    }

    private function saveMutationSnapshot(object $row, array $snapshot): void
    {
        DB::table('accounting_journal_import_rows')
            ->where('id', $row->id)
            ->where('business_id', $row->business_id)
            ->update([
                'source_mutation_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_record_type' => Transaction::class,
                'source_record_id' => $snapshot['transaction_id'],
                'updated_at' => now(),
            ]);
    }

    private function revertCreatedExpense(Transaction $transaction, array $snapshot): void
    {
        foreach ($snapshot['expected'] ?? [] as $field => $expected) {
            if (! $this->sameTransactionValue($field, $transaction->{$field}, $expected)) {
                throw new \RuntimeException('Expense yang dibuat oleh Journal Import berubah setelah diproses; pulihkan secara manual sebelum revert batch.');
            }
        }
        if (DB::table('transaction_payments')->where('transaction_id', $transaction->id)->exists()) {
            throw new \RuntimeException('Expense Journal Import sudah memiliki pembayaran; hapus pembayaran sebelum revert batch.');
        }
        if (! $this->sameLineStates($this->lineStates($transaction), $snapshot['expected_lines'] ?? [])) {
            throw new \RuntimeException('Detail Expense yang dibuat oleh Journal Import berubah setelah diproses; pulihkan secara manual sebelum revert batch.');
        }

        DB::table('expense_lines')->where('transaction_id', $transaction->id)->delete();
        $transaction->delete();
    }

    private function revertUpdatedExpense(Transaction $transaction, array $snapshot): void
    {
        foreach ($snapshot['fields_expected'] ?? [] as $field => $expected) {
            if (! $this->sameTransactionValue($field, $transaction->{$field}, $expected)) {
                throw new \RuntimeException('Expense sumber berubah setelah dilengkapi oleh Journal Import; tinjau perubahan sebelum revert batch.');
            }
        }
        $lineIds = $snapshot['created_line_ids'] ?? [];
        if ($lineIds !== []) {
            if (! $this->sameLineStates($this->lineStates($transaction, $lineIds), $snapshot['created_lines_expected'] ?? [])) {
                throw new \RuntimeException('Detail Expense yang ditambahkan oleh Journal Import berubah; tinjau detail sebelum revert batch.');
            }
            DB::table('expense_lines')->where('transaction_id', $transaction->id)->whereIn('id', $lineIds)->delete();
        }
        foreach ($snapshot['fields_before'] ?? [] as $field => $value) {
            $transaction->{$field} = $value;
        }
        $transaction->save();
    }

    private function normalizedDateOrNull($date): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($date)->toDateString();
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
