<?php

namespace Modules\Accounting\Services;

use App\PurchaseLine;
use App\Transaction;
use App\TransactionSellLine;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalImportInvoiceService
{
    private $draftService;

    private $productUtil;

    private $transactionUtil;

    private $taxResolver;

    public function __construct(
        JournalImportDraftService $draftService,
        ProductUtil $productUtil,
        TransactionUtil $transactionUtil,
        ?JournalImportTaxResolver $taxResolver = null
    ) {
        $this->draftService = $draftService;
        $this->productUtil = $productUtil;
        $this->transactionUtil = $transactionUtil;
        $this->taxResolver = $taxResolver ?: new JournalImportTaxResolver();
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
            $transaction = $this->createInvoice($row, $entry, $import, $prepared);
            $this->saveMutationSnapshot($row, [
                'action' => 'created',
                'transaction_id' => $transaction->id,
                'expected_lines' => $this->lineStates($transaction),
                'expected' => $this->expectedValues($transaction),
            ]);

            return $transaction;
        }

        $transaction = $prepared['transaction'];
        $snapshot = $this->applyUpdate($transaction, $row, $entry, $import, $prepared);
        $snapshot['action'] = 'updated';
        $snapshot['transaction_id'] = $transaction->id;
        $this->saveMutationSnapshot($row, $snapshot);

        return $transaction->fresh();
    }

    public function draftTotal(object $row, array $entry, object $import, array $options = []): ?float
    {
        try {
            $prepared = $this->prepare($row, $entry, (int) $import->business_id, $options, false, true);
            if ($prepared['action'] === 'link') {
                $total = $prepared['transaction']->final_total;

                return is_numeric($total) ? (float) $total : null;
            }

            if ($prepared['action'] === 'update'
                && ! empty($prepared['transaction']->final_total)
                && $this->linesQuery($prepared['transaction'])->exists()) {
                return (float) $prepared['transaction']->final_total;
            }

            return $prepared['totals']['final_total'] ?? null;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function revertImport(int $importId, int $businessId): int
    {
        $rows = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->whereIn('source_type', ['Sales Invoice', 'Purchase Invoice'])
            ->whereNotNull('source_mutation_snapshot')
            ->lockForUpdate()
            ->get();

        $reverted = 0;
        foreach ($rows as $row) {
            $snapshot = json_decode((string) $row->source_mutation_snapshot, true);
            if (! is_array($snapshot)) {
                throw new \RuntimeException('Snapshot sumber faktur tidak valid; revert dibatalkan agar data tidak tertimpa.');
            }

            $transaction = Transaction::where('business_id', $businessId)
                ->lockForUpdate()
                ->find($snapshot['transaction_id'] ?? null);
            if (! $transaction) {
                continue;
            }

            if (($snapshot['action'] ?? null) === 'created') {
                $this->revertCreatedInvoice($transaction, $snapshot);
                $reverted++;
                continue;
            }

            if (($snapshot['action'] ?? null) === 'updated') {
                $this->revertUpdatedInvoice($transaction, $snapshot);
                $reverted++;
            }
        }

        return $reverted;
    }

    private function prepare(object $row, array $entry, int $businessId, array $options, bool $lock, bool $forPreview = false): array
    {
        $sourceType = (string) ($row->source_type ?? '');
        $action = (string) ($row->source_action ?? 'review');
        if (! in_array($sourceType, ['Sales Invoice', 'Purchase Invoice'], true)) {
            throw new \RuntimeException('Handler faktur hanya mendukung Sales Invoice dan Purchase Invoice.');
        }
        if ($action === 'review') {
            throw new \RuntimeException('Pilih sumber invoice yang tersedia atau pilih Buat baru secara eksplisit.');
        }

        $recordType = $row->source_record_type ?? null;
        $recordId = empty($row->source_record_id) ? null : (int) $row->source_record_id;
        if (! $this->draftService->isValidSourceDecision($entry, $action, $recordType, $recordId)) {
            throw new \RuntimeException('Pilih sumber invoice yang tersedia atau pilih Buat baru secara eksplisit.');
        }

        $transactionType = $sourceType === 'Sales Invoice' ? 'sell' : 'purchase';
        $transaction = null;
        if (in_array($action, ['link', 'update'], true)) {
            if ($recordType !== Transaction::class || $recordId === null) {
                throw new \RuntimeException('Pilih invoice yang akan ditautkan atau dilengkapi.');
            }
            $query = Transaction::where('business_id', $businessId)->where('type', $transactionType);
            if ($lock) {
                $query->lockForUpdate();
            }
            $transaction = $query->find($recordId);
            if (! $transaction) {
                throw new \RuntimeException('Invoice pilihan tidak ditemukan pada business aktif.');
            }
        } elseif ($action !== 'create') {
            throw new \RuntimeException('Aksi draft invoice belum siap diproses.');
        }

        if ($action === 'link') {
            if (empty($transaction->location_id)
                || empty($transaction->transaction_date) || $transaction->final_total === null) {
                throw new \RuntimeException('Invoice pilihan belum memiliki lokasi, tanggal, atau total lengkap; pilih Lengkapi sumber.');
            }
            if ($this->existingLines($transaction) === []) {
                throw new \RuntimeException('Invoice pilihan belum memiliki detail produk; pilih Lengkapi sumber.');
            }

            return compact('action', 'transaction', 'transactionType') + ['totals' => null, 'lines' => []];
        }

        $draftData = json_decode((string) ($row->draft_data ?? '{}'), true) ?: [];
        try {
            $draftData = $this->draftService->validateDraftData($draftData, $businessId, $sourceType);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $first = reset($errors);
            throw new \RuntimeException(is_array($first) ? (string) reset($first) : 'Detail invoice tidak valid.');
        }

        $details = $this->normalizedDetails($draftData['details'] ?? [], $businessId);
        $existingLines = $transaction ? $this->existingLines($transaction) : [];
        $effectiveLocationId = ! empty($transaction->location_id)
            ? (int) $transaction->location_id
            : (! empty($draftData['location_id'])
                ? (int) $draftData['location_id']
                : $this->draftService->defaultActiveLocationId($businessId));

        if ($action === 'create') {
            if ($effectiveLocationId === null) {
                throw new \RuntimeException('Pilih lokasi sebelum membuat invoice.');
            }
            if ($details === []) {
                throw new \RuntimeException('Tambahkan minimal satu detail produk dengan jumlah dan harga.');
            }
        } else {
            $this->assertNoConflictingHeader($transaction, $draftData, $entry, $details, $existingLines);
            if ($existingLines === [] && $details === []) {
                throw new \RuntimeException('Invoice belum memiliki item; tambahkan minimal satu detail produk.');
            }
            if ($existingLines !== [] && $details !== [] && ! $this->sameLines($existingLines, $details, $transactionType)) {
                throw new \RuntimeException('Detail produk berbeda dari invoice yang sudah terisi; tinjau invoice sebelum melanjutkan.');
            }
            if (empty($transaction->location_id) && $effectiveLocationId === null) {
                throw new \RuntimeException('Pilih lokasi untuk melengkapi invoice.');
            }
            if (empty($transaction->transaction_date) && $this->normalizedDateOrNull($entry['date'] ?? null) === null) {
                throw new \RuntimeException('Tanggal invoice belum tersedia dan tanggal Journal tidak valid.');
            }
        }

        $effectiveDetails = $existingLines !== [] ? $existingLines : $details;
        $taxableBase = round(array_sum(array_map(
            fn ($detail) => (float) $detail['quantity'] * (float) $detail['unit_price'],
            $effectiveDetails
        )), 4);
        if ($taxableBase <= 0 && $transaction && (float) $transaction->total_before_tax > 0) {
            $taxableBase = (float) $transaction->total_before_tax;
        }
        $tax = $this->taxResolver->resolveForEntry($entry, $businessId, $taxableBase > 0 ? $taxableBase : null);
        $totals = $this->calculateTotals($effectiveDetails, $tax);
        if ($action === 'update' && $tax !== null) {
            $this->assertTaxDoesNotConflict($transaction, $existingLines, $tax, $totals);
        }
        if ($forPreview && $details === [] && $existingLines === []) {
            return compact('action', 'transaction', 'transactionType', 'totals', 'effectiveLocationId') + ['lines' => []];
        }

        return compact('action', 'transaction', 'transactionType', 'totals') + [
            'draft_data' => $draftData,
            'location_id' => $effectiveLocationId,
            'details' => $details,
            'existing_lines' => $existingLines,
            'tax' => $tax,
        ];
    }

    private function createInvoice(object $row, array $entry, object $import, array $prepared): Transaction
    {
        $sourceType = (string) $row->source_type;
        $transactionType = $prepared['transactionType'];
        $draftData = $prepared['draft_data'];
        $tax = $prepared['tax'];
        $date = $this->normalizedDate($entry['date'] ?? null);
        $sourceNumber = trim((string) ($entry['source_number'] ?? $row->source_number ?? ''));
        $invoiceNo = $transactionType === 'sell'
            ? $this->transactionUtil->getInvoiceNumber((int) $import->business_id, 'draft', (int) $prepared['location_id'], null, 'sell')
            : null;
        $refNo = $sourceNumber !== '' ? $sourceNumber : null;

        $transaction = Transaction::create([
            'business_id' => (int) $import->business_id,
            'location_id' => (int) $prepared['location_id'],
            'type' => $transactionType,
            'status' => 'draft',
            'contact_id' => $draftData['contact_id'] ?? null,
            'invoice_no' => $invoiceNo,
            'ref_no' => $refNo,
            'transaction_date' => $date,
            'total_before_tax' => $prepared['totals']['total_before_tax'],
            'tax_id' => $tax['id'] ?? null,
            'tax_amount' => $prepared['totals']['tax'],
            'discount_type' => null,
            'discount_amount' => 0,
            'final_total' => $prepared['totals']['final_total'],
            'payment_status' => 'due',
            'additional_notes' => trim((string) ($entry['description'] ?? '')) ?: null,
            'created_by' => (int) $import->created_by,
            'exchange_rate' => 1,
            'is_direct_sale' => $transactionType === 'sell' ? 1 : 0,
        ]);

        $this->persistLines($transaction, $prepared['details'], $prepared['tax']);

        return $transaction;
    }

    private function applyUpdate(Transaction $transaction, object $row, array $entry, object $import, array $prepared): array
    {
        $draftData = $prepared['draft_data'];
        $tax = $prepared['tax'];
        $correctMissingTaxTotal = (new JournalImportTaxCorrectionPolicy())->shouldCorrectFinalTotal(
            $transaction->tax_amount,
            $transaction->total_before_tax,
            $transaction->final_total,
            $prepared['totals']
        );
        $before = [];
        $expected = [];
        $invoiceNo = null;
        if ($transaction->type === 'sell' && empty($transaction->invoice_no)) {
            $locationId = (int) ($transaction->location_id ?: ($draftData['location_id'] ?? 0));
            $invoiceNo = $this->transactionUtil->getInvoiceNumber(
                (int) $transaction->business_id,
                (string) ($transaction->status ?: 'draft'),
                $locationId,
                null,
                'sell'
            );
        }
        $fields = [
            'contact_id' => $draftData['contact_id'] ?? null,
            'location_id' => $prepared['location_id'] ?? null,
            'transaction_date' => $this->normalizedDateOrNull($entry['date'] ?? null),
            'invoice_no' => $invoiceNo,
            'ref_no' => $entry['source_number'] ?? null,
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
        if ($transaction->tax_id === null && ! empty($tax['id'])) {
            $before['tax_id'] = null;
            $transaction->tax_id = $tax['id'];
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
        if ($prepared['existing_lines'] === [] && $prepared['details'] !== []) {
            $this->persistLines($transaction, $prepared['details'], $tax);
            $createdLineIds = $this->lineIds($transaction);
        }
        $lineChanges = [];
        if ($tax !== null && $prepared['existing_lines'] !== []) {
            $lineChanges = $this->applyMissingLineTax($transaction, $tax, $prepared['totals']);
        }

        $transaction->save();

        return [
            'fields_before' => $before,
            'fields_expected' => $expected,
            'created_line_ids' => $createdLineIds,
            'created_lines_expected' => $createdLineIds === [] ? [] : $this->lineStates($transaction, $createdLineIds),
            'line_changes' => $lineChanges,
        ];
    }

    private function assertNoConflictingHeader(Transaction $transaction, array $draftData, array $entry, array $details, array $existingLines): void
    {
        foreach (['contact_id', 'location_id'] as $field) {
            $proposed = $draftData[$field] ?? null;
            if ($proposed !== null && ! empty($transaction->{$field}) && (int) $transaction->{$field} !== (int) $proposed) {
                throw new \RuntimeException('Invoice sudah memiliki '.($field === 'contact_id' ? 'customer/supplier' : 'lokasi').' yang berbeda; tinjau sumber sebelum melanjutkan.');
            }
        }

        $sourceNumber = trim((string) ($entry['source_number'] ?? ''));
        $numberField = $transaction->type === 'sell' ? 'invoice_no' : 'ref_no';
        $numberMatches = (string) ($transaction->{$numberField} ?? '') === $sourceNumber
            || (string) ($transaction->ref_no ?? '') === $sourceNumber
            || (string) ($transaction->invoice_no ?? '') === $sourceNumber;
        if ($sourceNumber !== '' && ! $numberMatches && (! empty($transaction->{$numberField}) || ! empty($transaction->ref_no) || ! empty($transaction->invoice_no))) {
            throw new \RuntimeException('Nomor sumber berbeda dari nomor invoice yang dipilih; tinjau invoice sebelum melanjutkan.');
        }

        $entryDate = $this->normalizedDateOrNull($entry['date'] ?? null);
        if ($entryDate !== null && ! empty($transaction->transaction_date)
            && substr((string) $transaction->transaction_date, 0, 10) !== substr($entryDate, 0, 10)) {
            throw new \RuntimeException('Tanggal invoice berbeda dari tanggal Journal; tinjau sumber sebelum melanjutkan.');
        }

        if ($existingLines !== [] && $details !== [] && ! $this->sameLines($existingLines, $details, $transaction->type)) {
            throw new \RuntimeException('Detail produk berbeda dari invoice yang sudah terisi; tinjau invoice sebelum melanjutkan.');
        }
    }

    private function normalizedDetails(array $details, int $businessId): array
    {
        $normalized = [];
        foreach ($details as $index => $detail) {
            if (empty($detail['variation_id']) || ! is_numeric($detail['quantity'] ?? null) || ! is_numeric($detail['unit_price'] ?? null)) {
                continue;
            }
            $variation = DB::table('variations as variation')
                ->join('products as product', 'product.id', '=', 'variation.product_id')
                ->where('variation.id', (int) $detail['variation_id'])
                ->where('product.business_id', $businessId)
                ->where('product.is_inactive', 0)
                ->whereNull('variation.deleted_at')
                ->select('variation.id as variation_id', 'product.id as product_id')
                ->first();
            if (! $variation) {
                throw new \RuntimeException('Produk pada baris '.($index + 1).' tidak tersedia pada business aktif.');
            }

            $normalized[] = [
                'product_id' => (int) $variation->product_id,
                'variation_id' => (int) $variation->variation_id,
                'quantity' => round((float) $detail['quantity'], 4),
                'unit_price' => round((float) $detail['unit_price'], 4),
                'description' => trim((string) ($detail['description'] ?? '')),
            ];
        }

        if (count($normalized) !== count($details)) {
            throw new \RuntimeException('Setiap detail invoice wajib memilih produk, jumlah, dan harga.');
        }

        return $normalized;
    }

    private function existingLines(Transaction $transaction): array
    {
        $lines = $transaction->type === 'sell'
            ? TransactionSellLine::where('transaction_id', $transaction->id)
                ->whereNull('parent_sell_line_id')
                ->get(['product_id', 'variation_id', 'quantity', 'unit_price'])
            : PurchaseLine::where('transaction_id', $transaction->id)
                ->get(['product_id', 'variation_id', 'quantity', 'purchase_price']);

        return $lines->map(function ($line) use ($transaction) {
            $price = $transaction->type === 'sell' ? $line->unit_price : $line->purchase_price;
            if ($price === null || (float) $line->quantity <= 0) {
                throw new \RuntimeException('Invoice pilihan memiliki item yang belum lengkap; tinjau detail invoice sebelum melanjutkan.');
            }

            return [
                'product_id' => (int) $line->product_id,
                'variation_id' => (int) $line->variation_id,
                'quantity' => round((float) $line->quantity, 4),
                'unit_price' => round((float) $price, 4),
            ];
        })->all();
    }

    private function sameLines(array $existing, array $draft, string $transactionType): bool
    {
        $normalize = function (array $lines) {
            $rows = array_map(fn ($line) => [
                (int) $line['variation_id'],
                round((float) $line['quantity'], 4),
                round((float) $line['unit_price'], 4),
            ], $lines);
            sort($rows);

            return $rows;
        };

        return $normalize($existing) === $normalize($draft);
    }

    private function calculateTotals(array $details, ?array $tax): array
    {
        $base = 0.0;
        $taxAmount = 0.0;
        foreach ($details as $detail) {
            $quantity = (float) $detail['quantity'];
            $unitPrice = (float) $detail['unit_price'];
            $base += $quantity * $unitPrice;
            if ($tax !== null) {
                $taxAmount += $quantity * round($unitPrice * $tax['rate'] / 100, 4);
            }
        }
        $taxAmount = round($taxAmount, 4);

        return [
            'total_before_tax' => round($base, 4),
            'tax' => $taxAmount,
            'final_total' => round($base + $taxAmount, 4),
        ];
    }

    private function assertTaxDoesNotConflict(Transaction $transaction, array $existingLines, array $tax, array $totals): void
    {
        if ($transaction->tax_id !== null && (int) $transaction->tax_id !== (int) $tax['id']) {
            throw new \RuntimeException('TaxRate header invoice berbeda dari hasil hitung komponen Journal; tinjau invoice sebelum melanjutkan.');
        }
        if ($transaction->tax_amount !== null && (float) $transaction->tax_amount > 0
            && abs((float) $transaction->tax_amount - (float) $totals['tax']) > 0.01) {
            throw new \RuntimeException('Nilai pajak header invoice berbeda dari nominal komponen Journal; tinjau invoice sebelum melanjutkan.');
        }
        $table = $transaction->type === 'sell' ? 'transaction_sell_lines' : 'purchase_lines';
        $priceColumn = $transaction->type === 'sell' ? 'unit_price' : 'purchase_price';
        $incColumn = $transaction->type === 'sell' ? 'unit_price_inc_tax' : 'purchase_price_inc_tax';
        foreach (DB::table($table)->where('transaction_id', $transaction->id)->get() as $line) {
            if ($line->tax_id !== null && (int) $line->tax_id !== (int) $tax['id']) {
                throw new \RuntimeException('TaxRate pada item invoice berbeda dari hasil hitung komponen Journal; tinjau invoice sebelum melanjutkan.');
            }
            if ($line->tax_id !== null) {
                $expectedTax = round((float) $line->{$priceColumn} * $tax['rate'] / 100, 4);
                if (abs((float) $line->item_tax - $expectedTax) > 0.01
                    || abs((float) $line->{$incColumn} - ((float) $line->{$priceColumn} + $expectedTax)) > 0.01) {
                    throw new \RuntimeException('Nilai pajak item invoice berbeda dari nominal komponen Journal; tinjau invoice sebelum melanjutkan.');
                }
            }
        }
    }

    private function persistLines(Transaction $transaction, array $details, ?array $tax): void
    {
        if ($transaction->type === 'sell') {
            $products = [];
            foreach ($details as $detail) {
                $itemTax = $tax ? round($detail['unit_price'] * $tax['rate'] / 100, 4) : 0.0;
                $products[] = [
                    'product_id' => $detail['product_id'],
                    'variation_id' => $detail['variation_id'],
                    'quantity' => $detail['quantity'],
                    'unit_price' => $detail['unit_price'],
                    'unit_price_inc_tax' => round($detail['unit_price'] + $itemTax, 4),
                    'item_tax' => $itemTax,
                    'tax_id' => $tax['id'] ?? null,
                    'line_discount_type' => null,
                    'line_discount_amount' => 0,
                ];
            }
            $this->transactionUtil->createOrUpdateSellLines($transaction, $products, $transaction->location_id, false, null, [], false);

            return;
        }

        $currencyDetails = $this->transactionUtil->purchaseCurrencyDetails((int) $transaction->business_id);
        $purchases = [];
        foreach ($details as $detail) {
            $itemTax = $tax ? round($detail['unit_price'] * $tax['rate'] / 100, 4) : 0.0;
            $purchases[] = [
                'product_id' => $detail['product_id'],
                'variation_id' => $detail['variation_id'],
                'quantity' => $detail['quantity'],
                'pp_without_discount' => $detail['unit_price'],
                'discount_percent' => 0,
                'purchase_price' => $detail['unit_price'],
                'purchase_price_inc_tax' => round($detail['unit_price'] + $itemTax, 4),
                'item_tax' => $itemTax,
                'purchase_line_tax_id' => $tax['id'] ?? null,
            ];
        }
        $this->productUtil->createOrUpdatePurchaseLines($transaction, $purchases, $currencyDetails, false);
    }

    private function applyMissingLineTax(Transaction $transaction, array $tax, array $totals): array
    {
        $rate = $tax['rate'];
        $table = $transaction->type === 'sell' ? 'transaction_sell_lines' : 'purchase_lines';
        $priceColumn = $transaction->type === 'sell' ? 'unit_price' : 'purchase_price';
        $incColumn = $transaction->type === 'sell' ? 'unit_price_inc_tax' : 'purchase_price_inc_tax';
        $lines = DB::table($table)->where('transaction_id', $transaction->id)->get();
        $changes = [];
        foreach ($lines as $line) {
            $unitTax = round((float) $line->{$priceColumn} * $rate / 100, 4);
            if ($line->tax_id !== null && (int) $line->tax_id !== (int) $tax['id']) {
                throw new \RuntimeException('TaxRate pada item invoice berbeda dari hasil hitung komponen Journal; tinjau invoice sebelum melanjutkan.');
            }
            if ($line->tax_id === null) {
                $before = [
                    'tax_id' => $line->tax_id,
                    'item_tax' => $line->item_tax,
                    $incColumn => $line->{$incColumn},
                ];
                $expected = [
                    'tax_id' => (int) $tax['id'],
                    'item_tax' => $unitTax,
                    $incColumn => round((float) $line->{$priceColumn} + $unitTax, 4),
                ];
                DB::table($table)->where('id', $line->id)->where('transaction_id', $transaction->id)->update([
                    'tax_id' => $expected['tax_id'],
                    'item_tax' => $expected['item_tax'],
                    $incColumn => $expected[$incColumn],
                    'updated_at' => now(),
                ]);
                $changes[] = [
                    'id' => (int) $line->id,
                    'table' => $table,
                    'before' => $before,
                    'expected' => $expected,
                ];
            }
        }

        if ($transaction->tax_id === null) {
            $transaction->tax_id = $tax['id'];
        } elseif ((int) $transaction->tax_id !== (int) $tax['id']) {
            throw new \RuntimeException('TaxRate header invoice berbeda dari hasil hitung komponen Journal; tinjau invoice sebelum melanjutkan.');
        }
        if ($transaction->tax_amount === null || (float) $transaction->tax_amount == 0.0) {
            $transaction->tax_amount = $totals['tax'];
        }
        if ($transaction->final_total === null) {
            $transaction->final_total = $totals['final_total'];
        }

        return $changes;
    }

    private function normalizedDate($date): string
    {
        $normalized = $this->normalizedDateOrNull($date);
        if ($normalized === null) {
            throw new \RuntimeException('Tanggal invoice wajib tersedia dan harus valid.');
        }

        return $normalized;
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

    private function linesQuery(Transaction $transaction)
    {
        return $transaction->type === 'sell'
            ? TransactionSellLine::where('transaction_id', $transaction->id)
            : PurchaseLine::where('transaction_id', $transaction->id);
    }

    private function lineIds(Transaction $transaction): array
    {
        return $this->linesQuery($transaction)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function expectedValues(Transaction $transaction): array
    {
        return [
            'business_id' => (int) $transaction->business_id,
            'type' => (string) $transaction->type,
            'status' => (string) $transaction->status,
            'contact_id' => (int) $transaction->contact_id,
            'location_id' => (int) $transaction->location_id,
            'transaction_date' => (string) $transaction->transaction_date,
            'invoice_no' => $transaction->invoice_no,
            'ref_no' => $transaction->ref_no,
            'total_before_tax' => $transaction->total_before_tax,
            'tax_id' => $transaction->tax_id,
            'tax_amount' => $transaction->tax_amount,
            'final_total' => $transaction->final_total,
        ];
    }

    private function lineStates(Transaction $transaction, array $onlyIds = []): array
    {
        $lines = $this->linesQuery($transaction);
        if ($onlyIds !== []) {
            $lines->whereIn('id', $onlyIds);
        }
        $rows = $lines->get();
        $isSell = $transaction->type === 'sell';

        return $rows->map(fn ($line) => [
            'id' => (int) $line->id,
            'product_id' => (int) $line->product_id,
            'variation_id' => (int) $line->variation_id,
            'quantity' => round((float) $line->quantity, 4),
            'unit_price' => round((float) ($isSell ? $line->unit_price : $line->purchase_price), 4),
            'tax_id' => $line->tax_id === null ? null : (int) $line->tax_id,
            'item_tax' => (float) $line->item_tax,
            'unit_price_inc_tax' => (float) ($isSell ? $line->unit_price_inc_tax : $line->purchase_price_inc_tax),
        ])->all();
    }

    private function sameLineStates(array $actual, array $expected): bool
    {
        $normalize = function (array $lines): array {
            $normalized = array_map(function (array $line): array {
                return [
                    'id' => (int) ($line['id'] ?? 0),
                    'product_id' => (int) ($line['product_id'] ?? 0),
                    'variation_id' => (int) ($line['variation_id'] ?? 0),
                    'quantity' => number_format((float) ($line['quantity'] ?? 0), 4, '.', ''),
                    'unit_price' => number_format((float) ($line['unit_price'] ?? 0), 4, '.', ''),
                    'tax_id' => ($line['tax_id'] ?? null) === null ? null : (int) $line['tax_id'],
                    'item_tax' => number_format((float) ($line['item_tax'] ?? 0), 4, '.', ''),
                    'unit_price_inc_tax' => number_format((float) ($line['unit_price_inc_tax'] ?? 0), 4, '.', ''),
                ];
            }, $lines);
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

    private function revertCreatedInvoice(Transaction $transaction, array $snapshot): void
    {
        $expected = $snapshot['expected'] ?? [];
        foreach ($expected as $field => $value) {
            if (! $this->sameTransactionValue($field, $transaction->{$field}, $value)) {
                throw new \RuntimeException('Invoice yang dibuat oleh Journal Import berubah setelah diproses; pulihkan secara manual sebelum revert batch.');
            }
        }
        if (DB::table('transaction_payments')->where('transaction_id', $transaction->id)->exists()) {
            throw new \RuntimeException('Invoice Journal Import sudah memiliki pembayaran; hapus pembayaran sebelum revert batch.');
        }
        if (! $this->sameLineStates($this->lineStates($transaction), $snapshot['expected_lines'] ?? [])) {
            throw new \RuntimeException('Item invoice yang dibuat oleh Journal Import berubah setelah diproses; pulihkan secara manual sebelum revert batch.');
        }
        $this->linesQuery($transaction)->delete();
        $transaction->delete();
    }

    private function revertUpdatedInvoice(Transaction $transaction, array $snapshot): void
    {
        foreach ($snapshot['fields_expected'] ?? [] as $field => $expected) {
            if (! $this->sameTransactionValue($field, $transaction->{$field}, $expected)) {
                throw new \RuntimeException('Invoice sumber berubah setelah dilengkapi oleh Journal Import; tinjau perubahan sebelum revert batch.');
            }
        }
        $lineIds = $snapshot['created_line_ids'] ?? [];
        if ($lineIds !== []) {
            if (! $this->sameLineStates($this->lineStates($transaction, $lineIds), $snapshot['created_lines_expected'] ?? [])) {
                throw new \RuntimeException('Item invoice yang ditambahkan oleh Journal Import berubah; tinjau item sebelum revert batch.');
            }
            $this->linesQuery($transaction)->whereIn('id', $lineIds)->delete();
        }
        foreach ($snapshot['line_changes'] ?? [] as $change) {
            $line = DB::table($change['table'])
                ->where('id', $change['id'])
                ->where('transaction_id', $transaction->id)
                ->first(array_keys($change['expected']));
            if (! $line) {
                throw new \RuntimeException('Item invoice yang dilengkapi oleh Journal Import berubah; tinjau item sebelum revert batch.');
            }
            foreach ($change['expected'] as $field => $expected) {
                if ((string) $line->{$field} !== (string) $expected) {
                    throw new \RuntimeException('Pajak item invoice berubah setelah Journal Import; tinjau item sebelum revert batch.');
                }
            }
            DB::table($change['table'])->where('id', $change['id'])->where('transaction_id', $transaction->id)
                ->update(array_merge($change['before'], ['updated_at' => now()]));
        }
        foreach ($snapshot['fields_before'] ?? [] as $field => $value) {
            $transaction->{$field} = $value;
        }
        $transaction->save();
    }
}
