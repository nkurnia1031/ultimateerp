<?php

namespace Modules\Accounting\Services;

use App\PurchaseLine;
use App\StockAdjustmentLine;
use App\Transaction;
use App\Unit;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;

class JournalImportStockService
{
    private JournalImportDraftService $draftService;

    private ProductUtil $productUtil;

    private TransactionUtil $transactionUtil;

    public function __construct(
        JournalImportDraftService $draftService,
        ProductUtil $productUtil,
        TransactionUtil $transactionUtil
    ) {
        $this->draftService = $draftService;
        $this->productUtil = $productUtil;
        $this->transactionUtil = $transactionUtil;
    }

    public function readinessIssue(object $row, array $entry, int $businessId, array $options = []): ?string
    {
        try {
            $this->prepare($row, $entry, $businessId, false);

            return null;
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }
    }

    public function draftTotal(object $row, array $entry, object $import, array $options = []): ?float
    {
        try {
            $prepared = $this->prepare($row, $entry, (int) $import->business_id, false);
            if (! empty($prepared['transaction'])) {
                return $this->transactionTotal($prepared['transaction']);
            }

            return round(array_sum(array_map(fn ($line) => $line['base_quantity'] * $line['base_price'], $prepared['details'])), 4);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function process(object $row, array $entry, object $import, array $options = []): Transaction
    {
        $businessId = (int) $import->business_id;
        $snapshot = $this->savedSnapshot($row);
        if ($snapshot && ! empty($snapshot['primary_id'])) {
            $alreadyCreated = Transaction::where('business_id', $businessId)->find((int) $snapshot['primary_id']);
            if ($alreadyCreated) {
                return $alreadyCreated;
            }
        }

        $prepared = $this->prepare($row, $entry, $businessId, true);
        if (($prepared['action'] ?? '') === 'link') {
            $this->saveMutationSnapshot($row, [
                'action' => 'linked',
                'primary_id' => (int) $prepared['transaction']->id,
                'record_ids' => [(int) $prepared['transaction']->id],
            ]);

            return $prepared['transaction'];
        }

        if (($prepared['action'] ?? '') === 'update' && ! empty($prepared['transaction'])) {
            $transaction = $prepared['transaction'];
            if (empty($prepared['details'])) {
                $this->saveMutationSnapshot($row, [
                    'action' => 'linked',
                    'primary_id' => (int) $transaction->id,
                    'record_ids' => [(int) $transaction->id],
                ]);

                return $transaction;
            }

            $before = $this->headerValues($transaction);
            $this->applyDetails($transaction, $prepared, $import, $row);
            $this->saveMutationSnapshot($row, [
                'action' => 'updated',
                'primary_id' => (int) $transaction->id,
                'record_ids' => [(int) $transaction->id],
                'before' => $before,
                'expected' => $this->headerValues($transaction->fresh()),
                'expected_lines' => $this->lineStates($transaction->fresh()),
            ]);

            return $transaction->fresh();
        }

        $transaction = $this->createMovement($row, $entry, $import, $prepared);
        $this->saveMutationSnapshot($row, [
            'action' => 'created',
            'primary_id' => (int) $transaction->id,
            'record_ids' => [(int) $transaction->id],
            'expected' => $this->headerValues($transaction),
            'expected_lines' => $this->lineStates($transaction),
        ]);

        return $transaction;
    }

    public function revertImport(int $importId, int $businessId): int
    {
        $rows = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->whereIn('source_type', ['Stock Adjustment', 'Stock In/Out', 'Opening Balance'])
            ->whereNotNull('source_mutation_snapshot')
            ->lockForUpdate()
            ->get();

        $reverted = 0;
        foreach ($rows as $row) {
            $snapshot = json_decode((string) $row->source_mutation_snapshot, true);
            if (! is_array($snapshot)) {
                throw new \RuntimeException('Snapshot sumber stok tidak valid; revert dibatalkan agar saldo tidak tertimpa.');
            }
            if (! in_array($snapshot['action'] ?? null, ['created', 'updated'], true)) {
                continue;
            }

            $transaction = Transaction::where('business_id', $businessId)
                ->lockForUpdate()
                ->find($snapshot['primary_id'] ?? null);
            if (! $transaction) {
                continue;
            }
            if (! $this->matchesHeader($transaction, $snapshot['expected'] ?? [])
                || ! $this->sameLineStates($this->lineStates($transaction), $snapshot['expected_lines'] ?? [])) {
                throw new \RuntimeException('Record stok yang dibuat/dilengkapi dari import telah berubah; revert dibatalkan agar perubahan terbaru aman.');
            }

            $this->removeMovementLines($transaction);
            if (($snapshot['action'] ?? null) === 'created') {
                $transaction->delete();
            } else {
                $transaction->fill($snapshot['before'] ?? [])->save();
            }
            $reverted++;
        }

        return $reverted;
    }

    private function prepare(object $row, array $entry, int $businessId, bool $lock): array
    {
        $sourceType = (string) ($row->source_type ?? '');
        $action = (string) ($row->source_action ?? 'review');
        if (! in_array($sourceType, ['Stock Adjustment', 'Stock In/Out', 'Opening Balance'], true)) {
            throw new \RuntimeException('Handler stok hanya mendukung Stock Adjustment, Stock In/Out, dan Opening Balance.');
        }
        if ($action === 'review') {
            throw new \RuntimeException('Pilih kandidat sumber yang tersedia atau pilih Buat baru secara eksplisit.');
        }

        $recordId = empty($row->source_record_id) ? null : (int) $row->source_record_id;
        $recordType = $row->source_record_type ?? null;
        if (! $this->draftService->isValidSourceDecision($entry, $action, $recordType, $recordId)) {
            throw new \RuntimeException('Pilih sumber stok yang tersedia atau pilih Buat baru secara eksplisit.');
        }

        $transaction = null;
        if (in_array($action, ['link', 'update'], true)) {
            if ($recordType !== Transaction::class || $recordId === null) {
                throw new \RuntimeException('Pilih transaksi stok yang akan ditautkan atau dilengkapi.');
            }
            $query = Transaction::where('business_id', $businessId)->whereIn('type', $this->allowedTransactionTypes($sourceType));
            if ($lock) {
                $query->lockForUpdate();
            }
            $transaction = $query->find($recordId);
            if (! $transaction) {
                throw new \RuntimeException('Transaksi stok pilihan tidak ditemukan pada business aktif.');
            }
            if ($action === 'link') {
                $this->assertLinkReady($transaction, $businessId);
                return ['action' => 'link', 'transaction' => $transaction, 'details' => []];
            }
            if ($transaction->type === 'opening_balance' || in_array($transaction->type, ['sell_transfer', 'purchase_transfer'], true)) {
                $this->assertLinkReady($transaction, $businessId);

                return ['action' => 'link', 'transaction' => $transaction, 'details' => []];
            }
            if (! in_array($transaction->type, ['stock_adjustment', 'opening_stock'], true)) {
                throw new \RuntimeException('Transfer stok yang sudah ada hanya dapat ditautkan. Pilih Tautkan sumber atau Buat baru.');
            }
            if ($transaction->type === 'opening_stock' && ! in_array((string) $transaction->status, ['', 'received'], true)) {
                throw new \RuntimeException('Status opening stock tidak dapat dilengkapi tanpa ditinjau terlebih dahulu.');
            }
        } elseif ($action !== 'create') {
            throw new \RuntimeException('Aksi draft stok belum siap diproses.');
        }

        $draft = json_decode((string) ($row->draft_data ?? ''), true) ?: [];
        if ($sourceType === 'Stock In/Out' && $transaction && in_array($action, ['link', 'update'], true)
            && ! empty($draft['movement_direction'])) {
            $storedDirection = in_array($transaction->type, ['stock_adjustment', 'sell_transfer'], true) ? 'out' : 'in';
            if ($storedDirection !== $draft['movement_direction']) {
                throw new \RuntimeException('Arah stok berbeda dengan record yang sudah dipilih. Tinjau konflik sebelum melanjutkan.');
            }
        }
        $direction = $sourceType === 'Stock Adjustment' ? 'out'
            : ($sourceType === 'Opening Balance' ? 'in'
                : ($transaction && in_array($action, ['link', 'update'], true)
                    ? ($transaction->type === 'stock_adjustment' ? 'out' : 'in')
                    : (string) ($draft['movement_direction'] ?? '')));
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new \RuntimeException('Pilih arah pergerakan stok Masuk atau Keluar.');
        }

        $locationId = ! empty($draft['location_id'])
            ? (int) $draft['location_id']
            : ((int) ($transaction->location_id ?? 0) ?: (int) ($this->draftService->defaultActiveLocationId($businessId) ?? 0));
        if ($locationId < 1 || ! DB::table('business_locations')->where('id', $locationId)->where('business_id', $businessId)->where('is_active', 1)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Pilih lokasi/gudang aktif dari business ini.');
        }
        if ($transaction && ! empty($transaction->location_id) && ! empty($draft['location_id'])
            && (int) $transaction->location_id !== (int) $draft['location_id']) {
            throw new \RuntimeException('Lokasi draft berbeda dengan record stok yang sudah terisi. Tinjau konflik sebelum melanjutkan.');
        }
        $entryDate = $this->transactionDate($entry['date'] ?? null);
        if ($action === 'update' && $transaction && ! empty($transaction->transaction_date)
            && substr((string) $transaction->transaction_date, 0, 10) !== substr($entryDate, 0, 10)) {
            throw new \RuntimeException('Tanggal berbeda dengan record stok yang sudah terisi. Tinjau konflik sebelum melanjutkan.');
        }

        $rawDetails = $draft['details'] ?? [];
        if (! is_array($rawDetails) || count($rawDetails) === 0) {
            if ($action === 'update' && $transaction && $this->hasLines($transaction)) {
                return ['action' => 'update', 'transaction' => $transaction, 'details' => [], 'direction' => $direction, 'location_id' => $locationId];
            }
            throw new \RuntimeException('Tambahkan minimal satu detail produk dengan jumlah, satuan, dan harga.');
        }

        $details = $this->prepareDetails($rawDetails, $businessId);
        if ($action === 'update' && $transaction && ! $this->hasLines($transaction)) {
            $detailTotal = round(array_sum(array_map(fn ($line) => $line['base_quantity'] * $line['base_price'], $details)), 4);
            if ($transaction->final_total !== null && (float) $transaction->final_total != 0.0
                && abs((float) $transaction->final_total - $detailTotal) > 0.01) {
                throw new \RuntimeException('Total detail berbeda dengan total record stok yang sudah terisi. Tinjau konflik sebelum melanjutkan.');
            }
            if ($transaction->type === 'opening_stock' && $transaction->total_before_tax !== null
                && (float) $transaction->total_before_tax != 0.0
                && abs((float) $transaction->total_before_tax - $detailTotal) > 0.01) {
                throw new \RuntimeException('Total sebelum pajak berbeda dengan record opening stock yang sudah terisi. Tinjau konflik sebelum melanjutkan.');
            }
        }
        if ($action === 'update' && $transaction && $this->hasLines($transaction)) {
            if (! $this->sameProductDetails($transaction, $details)) {
                throw new \RuntimeException('Detail berbeda dengan record stok yang sudah terisi. Tinjau konflik atau pilih Buat baru; data lama tidak ditimpa.');
            }
            return ['action' => 'update', 'transaction' => $transaction, 'details' => [], 'direction' => $direction, 'location_id' => $locationId];
        }

        return [
            'action' => $action,
            'transaction' => $transaction,
            'details' => $details,
            'direction' => $direction,
            'location_id' => $locationId,
        ];
    }

    private function prepareDetails(array $rawDetails, int $businessId): array
    {
        $details = [];
        foreach ($rawDetails as $index => $detail) {
            if (! is_array($detail) || empty($detail['variation_id']) || empty($detail['unit_id'])
                || ! isset($detail['quantity']) || ! is_numeric($detail['quantity']) || (float) $detail['quantity'] <= 0
                || ! isset($detail['unit_price']) || ! is_numeric($detail['unit_price']) || (float) $detail['unit_price'] < 0) {
                throw new \RuntimeException('Detail #'.($index + 1).': pilih produk dan satuan, lalu isi jumlah positif serta harga.');
            }

            $variation = DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('v.id', (int) $detail['variation_id'])
                ->where('p.business_id', $businessId)
                ->where('p.is_inactive', 0)
                ->where('p.enable_stock', 1)
                ->when(DB::connection()->getSchemaBuilder()->hasColumn('products', 'deleted_at'), fn ($query) => $query->whereNull('p.deleted_at'))
                ->whereNull('v.deleted_at')
                ->first(['v.id as variation_id', 'v.product_id', 'p.unit_id', 'p.sub_unit_ids']);
            if (! $variation) {
                throw new \RuntimeException('Detail #'.($index + 1).': produk/variasi tidak aktif atau bukan milik business ini.');
            }

            $unit = Unit::where('business_id', $businessId)->whereNull('deleted_at')->find((int) $detail['unit_id']);
            if (! $unit) {
                throw new \RuntimeException('Detail #'.($index + 1).': satuan tidak ditemukan pada business aktif.');
            }
            $multiplier = 1.0;
            if ((int) $unit->id !== (int) $variation->unit_id) {
                $subUnitIds = json_decode((string) $variation->sub_unit_ids, true) ?: [];
                if (! in_array((int) $unit->id, array_map('intval', $subUnitIds), true)
                    || (int) $unit->base_unit_id !== (int) $variation->unit_id
                    || ! is_numeric($unit->base_unit_multiplier) || (float) $unit->base_unit_multiplier <= 0) {
                    throw new \RuntimeException('Detail #'.($index + 1).': satuan harus satuan dasar produk atau sub-satuan yang terhubung.');
                }
                $multiplier = (float) $unit->base_unit_multiplier;
            }

            $details[] = [
                'product_id' => (int) $variation->product_id,
                'variation_id' => (int) $variation->variation_id,
                'unit_id' => (int) $unit->id,
                'quantity' => (float) $detail['quantity'],
                'unit_price' => (float) $detail['unit_price'],
                'base_quantity' => round((float) $detail['quantity'] * $multiplier, 4),
                'base_price' => round((float) $detail['unit_price'] / $multiplier, 4),
                'description' => trim((string) ($detail['description'] ?? '')),
            ];
        }

        return $details;
    }

    private function createMovement(object $row, array $entry, object $import, array $prepared): Transaction
    {
        $transaction = $prepared['transaction'] ?? null;
        if (! $transaction) {
            $type = $prepared['direction'] === 'in' ? 'opening_stock' : 'stock_adjustment';
            $total = round(array_sum(array_map(fn ($line) => $line['base_quantity'] * $line['base_price'], $prepared['details'])), 4);
            $transaction = Transaction::create([
                'type' => $type,
                'business_id' => (int) $import->business_id,
                'created_by' => (int) $import->created_by,
                'transaction_date' => $this->transactionDate($entry['date'] ?? null),
                'location_id' => (int) $prepared['location_id'],
                'ref_no' => trim((string) ($entry['source_number'] ?? '')) ?: null,
                'invoice_no' => null,
                'additional_notes' => trim((string) ($entry['description'] ?? '')),
                'status' => $type === 'opening_stock' ? 'received' : null,
                'payment_status' => $type === 'opening_stock' ? 'paid' : null,
                'total_before_tax' => $type === 'opening_stock' ? $total : null,
                'final_total' => $total,
                'opening_stock_product_id' => $type === 'opening_stock' ? $prepared['details'][0]['product_id'] : null,
                'adjustment_type' => $type === 'stock_adjustment' ? 'normal' : null,
                'total_amount_recovered' => 0,
            ]);
        }

        $this->applyDetails($transaction, $prepared, $import, $row);

        return $transaction->fresh();
    }

    private function applyDetails(Transaction $transaction, array $prepared, object $import, object $row): void
    {
        $entry = $this->rowEntry($row);
        $lineTotal = round(array_sum(array_map(fn ($line) => $line['base_quantity'] * $line['base_price'], $prepared['details'])), 4);
        if (in_array($transaction->type, ['stock_adjustment', 'opening_stock'], true)
            && $transaction->final_total !== null
            && (float) $transaction->final_total != 0.0
            && abs((float) $transaction->final_total - $lineTotal) > 0.01) {
            throw new \RuntimeException('Total detail berbeda dengan total record stok yang sudah terisi. Tinjau konflik sebelum melanjutkan.');
        }
        if ($transaction->type === 'opening_stock' && $transaction->total_before_tax !== null
            && (float) $transaction->total_before_tax != 0.0
            && abs((float) $transaction->total_before_tax - $lineTotal) > 0.01) {
            throw new \RuntimeException('Total sebelum pajak berbeda dengan record opening stock yang sudah terisi. Tinjau konflik sebelum melanjutkan.');
        }
        if (empty($transaction->location_id)) {
            $transaction->location_id = (int) $prepared['location_id'];
        }
        if (empty($transaction->transaction_date)) {
            $transaction->transaction_date = $this->transactionDate($entry['date'] ?? null);
        }
        if (empty($transaction->created_by)) {
            $transaction->created_by = (int) $import->created_by;
        }
        if (empty($transaction->ref_no) && ! empty($entry['source_number'])) {
            $transaction->ref_no = trim((string) $entry['source_number']);
        }
        if (empty($transaction->additional_notes) && ! empty($entry['description'])) {
            $transaction->additional_notes = trim((string) $entry['description']);
        }
        if ($transaction->type === 'opening_stock') {
            $transaction->status = $transaction->status ?: 'received';
            $transaction->payment_status = $transaction->payment_status ?: 'paid';
        }
        $transaction->save();

        if ($transaction->type === 'stock_adjustment' || $prepared['direction'] === 'out') {
            $lines = [];
            foreach ($prepared['details'] as $detail) {
                $this->productUtil->decreaseProductQuantity(
                    $detail['product_id'],
                    $detail['variation_id'],
                    (int) $prepared['location_id'],
                    $detail['base_quantity']
                );
                $lines[] = [
                    'product_id' => $detail['product_id'],
                    'variation_id' => $detail['variation_id'],
                    'quantity' => $detail['base_quantity'],
                    'unit_price' => $detail['base_price'],
                ];
            }
            $createdLines = $transaction->stock_adjustment_lines()->createMany($lines);
            $business = DB::table('business')->where('id', $transaction->business_id)->first();
            $this->transactionUtil->mapPurchaseSell(
                ['id' => $transaction->business_id, 'accounting_method' => $business->accounting_method ?? 'fifo', 'location_id' => $transaction->location_id],
                $createdLines,
                'stock_adjustment'
            );
        } else {
            $lines = [];
            foreach ($prepared['details'] as $detail) {
                $line = new PurchaseLine([
                    'product_id' => $detail['product_id'],
                    'variation_id' => $detail['variation_id'],
                    'quantity' => $detail['base_quantity'],
                    'pp_without_discount' => $detail['base_price'],
                    'purchase_price' => $detail['base_price'],
                    'purchase_price_inc_tax' => $detail['base_price'],
                    'item_tax' => 0,
                    'tax_id' => null,
                    'discount_percent' => 0,
                    'sub_unit_id' => $detail['unit_id'] === (int) DB::table('products')->where('id', $detail['product_id'])->value('unit_id') ? null : $detail['unit_id'],
                ]);
                $this->productUtil->updateProductQuantity(
                    (int) $prepared['location_id'],
                    $detail['product_id'],
                    $detail['variation_id'],
                    $detail['base_quantity'],
                    0,
                    null,
                    false
                );
                $lines[] = $line;
            }
            $transaction->purchase_lines()->saveMany($lines);
            if (empty($transaction->final_total)) {
                $transaction->final_total = $lineTotal;
            }
            if (empty($transaction->total_before_tax)) {
                $transaction->total_before_tax = $lineTotal;
            }
            $transaction->opening_stock_product_id = $prepared['details'][0]['product_id'];
            $transaction->save();
            $this->productUtil->adjustStockOverSelling($transaction);
        }
        if ($transaction->type === 'stock_adjustment' && empty($transaction->final_total)) {
            $transaction->final_total = $lineTotal;
            $transaction->save();
        }
    }

    private function removeMovementLines(Transaction $transaction): void
    {
        if ($transaction->type === 'stock_adjustment') {
            $lines = $transaction->stock_adjustment_lines;
            $ids = [];
            foreach ($lines as $line) {
                $this->productUtil->updateProductQuantity($transaction->location_id, $line->product_id, $line->variation_id, $line->quantity);
                $ids[] = (int) $line->id;
            }
            if ($ids !== []) {
                $this->transactionUtil->mapPurchaseQuantityForDeleteStockAdjustment($ids);
            }
            StockAdjustmentLine::where('transaction_id', $transaction->id)->delete();
            return;
        }

        foreach ($transaction->purchase_lines as $line) {
            if ((float) ($line->quantity_used ?? 0) > 0) {
                throw new \RuntimeException('Stok opening balance telah dipakai transaksi lain; revert dibatalkan.');
            }
            $this->productUtil->decreaseProductQuantity($line->product_id, $line->variation_id, $transaction->location_id, $line->quantity);
            $line->delete();
        }
    }

    private function sameProductDetails(Transaction $transaction, array $details): bool
    {
        $existing = $transaction->type === 'stock_adjustment'
            ? $transaction->stock_adjustment_lines->map(fn ($line) => ['variation_id' => (int) $line->variation_id, 'quantity' => (float) $line->quantity, 'price' => (float) $line->unit_price])
            : $transaction->purchase_lines->map(fn ($line) => ['variation_id' => (int) $line->variation_id, 'quantity' => (float) $line->quantity, 'price' => (float) $line->purchase_price_inc_tax]);
        $expected = collect($details)->map(function ($line) use ($transaction) {
            return [
                'variation_id' => $line['variation_id'],
                'quantity' => $line['base_quantity'],
                'price' => $line['base_price'],
            ];
        });

        return $existing->sortBy('variation_id')->values()->toArray() === $expected->sortBy('variation_id')->values()->toArray();
    }

    private function hasLines(Transaction $transaction): bool
    {
        return $transaction->type === 'stock_adjustment'
            ? $transaction->stock_adjustment_lines()->exists()
            : $transaction->purchase_lines()->exists();
    }

    private function assertLinkReady(Transaction $transaction, int $businessId): void
    {
        if (empty($transaction->transaction_date)) {
            throw new \RuntimeException('Record stok pilihan belum memiliki tanggal; pilih Lengkapi sumber.');
        }
        if ($transaction->type === 'opening_balance') {
            if ($transaction->final_total === null) {
                throw new \RuntimeException('Opening balance pilihan belum memiliki total; lengkapi sumber sebelum ditautkan.');
            }

            return;
        }
        if (in_array($transaction->type, ['sell_transfer', 'purchase_transfer'], true)) {
            $counterpart = $transaction->type === 'sell_transfer'
                ? Transaction::where('business_id', $businessId)->where('type', 'purchase_transfer')->where('transfer_parent_id', $transaction->id)->first()
                : Transaction::where('business_id', $businessId)->where('type', 'sell_transfer')->find($transaction->transfer_parent_id);
            $hasSourceLines = $transaction->type === 'sell_transfer'
                ? $transaction->sell_lines()->exists()
                : $transaction->purchase_lines()->exists();
            $hasCounterpartLines = $counterpart && ($counterpart->type === 'sell_transfer'
                ? $counterpart->sell_lines()->exists()
                : $counterpart->purchase_lines()->exists());
            if (! $counterpart || ! $hasSourceLines || ! $hasCounterpartLines || $transaction->final_total === null) {
                throw new \RuntimeException('Transfer stok pilihan belum memiliki pasangan dan baris yang lengkap; lengkapi transfer atau buat baru.');
            }
            foreach ([$transaction, $counterpart] as $transfer) {
                if (empty($transfer->location_id)
                    || ! DB::table('business_locations')->where('id', $transfer->location_id)->where('business_id', $businessId)->whereNull('deleted_at')->exists()) {
                    throw new \RuntimeException('Lokasi transfer stok pilihan tidak termasuk business aktif.');
                }
                $table = $transfer->type === 'sell_transfer' ? 'transaction_sell_lines' : 'purchase_lines';
                if (! $this->transactionLinesBelongToBusiness((int) $transfer->id, $table, $businessId)) {
                    throw new \RuntimeException('Detail transfer stok pilihan memiliki produk di luar business aktif atau tidak lengkap.');
                }
            }

            return;
        }

        if (empty($transaction->location_id)
            || ! DB::table('business_locations')->where('id', $transaction->location_id)->where('business_id', $businessId)->whereNull('deleted_at')->exists()
            || ! $this->hasLines($transaction)) {
            throw new \RuntimeException('Record stok pilihan belum memiliki lokasi dan detail produk; pilih Lengkapi sumber.');
        }
        if ($transaction->type === 'stock_adjustment') {
            $lines = $transaction->stock_adjustment_lines;
            foreach ($lines as $line) {
                $productExists = DB::table('products')->where('id', $line->product_id)->where('business_id', $businessId)->where('enable_stock', 1)->exists();
                if (! $productExists || (float) $line->quantity <= 0 || ! is_numeric($line->unit_price)) {
                    throw new \RuntimeException('Record stock adjustment memiliki detail produk, jumlah, atau harga yang belum valid.');
                }
            }
        } elseif ($transaction->type === 'opening_stock') {
            if ($transaction->final_total === null || $transaction->status !== 'received') {
                throw new \RuntimeException('Record opening stock belum berstatus diterima atau belum memiliki total; pilih Lengkapi sumber.');
            }
            foreach ($transaction->purchase_lines as $line) {
                $productExists = DB::table('products')->where('id', $line->product_id)->where('business_id', $businessId)->where('enable_stock', 1)->exists();
                if (! $productExists || (float) $line->quantity <= 0 || ! is_numeric($line->purchase_price_inc_tax)) {
                    throw new \RuntimeException('Record opening stock memiliki detail produk, jumlah, atau harga yang belum valid.');
                }
            }
        }
    }

    private function transactionLinesBelongToBusiness(int $transactionId, string $lineTable, int $businessId): bool
    {
        $lineCount = DB::table($lineTable)->where('transaction_id', $transactionId)->count();
        if ($lineCount === 0) {
            return false;
        }
        $validCount = DB::table($lineTable.' as lines')
            ->join('products as products', 'products.id', '=', 'lines.product_id')
            ->join('variations as variations', function ($join) {
                $join->on('variations.id', '=', 'lines.variation_id')
                    ->on('variations.product_id', '=', 'products.id');
            })
            ->where('lines.transaction_id', $transactionId)
            ->where('products.business_id', $businessId)
            ->where('products.enable_stock', 1)
            ->when(DB::connection()->getSchemaBuilder()->hasColumn('products', 'deleted_at'), fn ($query) => $query->whereNull('products.deleted_at'))
            ->whereNull('variations.deleted_at')
            ->count();

        return $lineCount === $validCount;
    }

    private function allowedTransactionTypes(string $sourceType): array
    {
        return [
            'Stock Adjustment' => ['stock_adjustment'],
            'Stock In/Out' => ['opening_stock', 'sell_transfer', 'purchase_transfer', 'stock_adjustment'],
            'Opening Balance' => ['opening_stock', 'opening_balance'],
        ][$sourceType] ?? [];
    }

    private function transactionTotal(Transaction $transaction): ?float
    {
        if ($transaction->type === 'opening_balance' || in_array($transaction->type, ['sell_transfer', 'purchase_transfer'], true)) {
            return is_numeric($transaction->final_total) ? (float) $transaction->final_total : null;
        }
        if ($transaction->type === 'stock_adjustment') {
            return (float) $transaction->stock_adjustment_lines->sum(fn ($line) => (float) $line->quantity * (float) $line->unit_price);
        }

        return (float) $transaction->purchase_lines->sum(fn ($line) => (float) $line->quantity * (float) $line->purchase_price_inc_tax);
    }

    private function transactionDate($date): string
    {
        try {
            return \Carbon\Carbon::parse((string) $date)->toDateTimeString();
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Tanggal transaksi stok wajib tersedia dan valid.');
        }
    }

    private function saveMutationSnapshot(object $row, array $snapshot): void
    {
        $businessId = $row->business_id ?? DB::table('accounting_journal_import_rows')->where('id', $row->id)->value('business_id');
        DB::table('accounting_journal_import_rows')
            ->where('id', $row->id)
            ->where('business_id', $businessId)
            ->update([
                'source_mutation_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_record_type' => Transaction::class,
                'source_record_id' => $snapshot['primary_id'] ?? null,
                'updated_at' => now(),
            ]);
    }

    private function savedSnapshot(object $row): ?array
    {
        $encoded = $row->source_mutation_snapshot ?? DB::table('accounting_journal_import_rows')->where('id', $row->id)->value('source_mutation_snapshot');
        $snapshot = json_decode((string) $encoded, true);

        return is_array($snapshot) ? $snapshot : null;
    }

    private function rowEntry(object $row): array
    {
        return json_decode((string) ($row->entry_data ?? ''), true) ?: [];
    }

    private function headerValues(Transaction $transaction): array
    {
        $fields = ['type', 'business_id', 'created_by', 'transaction_date', 'location_id', 'ref_no', 'additional_notes', 'status', 'payment_status', 'total_before_tax', 'final_total', 'opening_stock_product_id', 'adjustment_type', 'total_amount_recovered'];
        $values = [];
        foreach ($fields as $field) {
            $value = $transaction->{$field};
            $values[$field] = is_numeric($value) && in_array($field, ['total_before_tax', 'final_total', 'total_amount_recovered'], true)
                ? number_format((float) $value, 4, '.', '')
                : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value);
        }

        return $values;
    }

    private function matchesHeader(Transaction $transaction, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            $actual = $this->headerValues($transaction)[$key] ?? null;
            if ($actual !== $value) {
                return false;
            }
        }

        return true;
    }

    private function lineStates(Transaction $transaction): array
    {
        if ($transaction->type === 'stock_adjustment') {
            return $transaction->stock_adjustment_lines()->orderBy('id')->get()->map(fn ($line) => [
                'id' => (int) $line->id,
                'product_id' => (int) $line->product_id,
                'variation_id' => (int) $line->variation_id,
                'quantity' => number_format((float) $line->quantity, 4, '.', ''),
                'price' => number_format((float) $line->unit_price, 4, '.', ''),
            ])->all();
        }

        return $transaction->purchase_lines()->orderBy('id')->get()->map(fn ($line) => [
            'id' => (int) $line->id,
            'product_id' => (int) $line->product_id,
            'variation_id' => (int) $line->variation_id,
            'quantity' => number_format((float) $line->quantity, 4, '.', ''),
            'price' => number_format((float) $line->purchase_price_inc_tax, 4, '.', ''),
        ])->all();
    }

    private function sameLineStates(array $actual, array $expected): bool
    {
        return $actual === $expected;
    }
}
