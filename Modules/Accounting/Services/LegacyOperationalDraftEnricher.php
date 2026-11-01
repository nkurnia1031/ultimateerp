<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use App\Transaction;

class LegacyOperationalDraftEnricher
{
    public function prepareStandaloneDrafts(array $documents, int $businessId, JournalImportDraftService $draftService): array
    {
        $productIndex = $this->productIndex($businessId);
        $contactIndex = $this->contactIndex($businessId);
        $categoryIndex = $this->categoryIndex($businessId);
        $locationId = $draftService->defaultActiveLocationId($businessId);
        $prepared = [];

        foreach ($documents as $identity => $document) {
            $result = ['ready' => false, 'draft_data' => [], 'expense_lines' => [], 'reason' => null];
            if (! is_array($document) || ($document['status'] ?? null) !== 'matched') {
                $result['reason'] = is_array($document)
                    ? (string) ($document['review_reason'] ?? 'Sumber operasional berkonflik atau tidak dapat digabungkan dengan aman.')
                    : 'Dokumen sumber operasional tidak valid.';
                $prepared[$identity] = $result;
                continue;
            }
            if ($locationId === null) {
                $result['reason'] = 'Business tidak memiliki lokasi aktif untuk transaksi baru.';
                $prepared[$identity] = $result;
                continue;
            }
            if (! is_numeric($document['total'] ?? null) || (float) $document['total'] <= 0) {
                $result['reason'] = 'Total sumber operasional tidak tersedia atau bukan nilai positif.';
                $prepared[$identity] = $result;
                continue;
            }

            $sourceType = (string) ($document['source_type'] ?? '');
            $draft = ['location_id' => $locationId];
            $contactId = $this->contactId($document, ['source_type' => $sourceType], $contactIndex);
            if ($contactId !== null) {
                $draft['contact_id'] = $contactId;
            }

            if (in_array($sourceType, ['Sales Invoice', 'Purchase Invoice'], true)) {
                $details = $this->invoiceDetails((array) ($document['lines'] ?? []), $productIndex, $reason);
                if ($details === []) {
                    $result['reason'] = $reason ?? 'Detail produk belum lengkap atau tidak dapat dipetakan secara unik.';
                    $prepared[$identity] = $result;
                    continue;
                }
                $lineBase = array_sum(array_map(fn (array $line) => (float) $line['quantity'] * (float) $line['unit_price'], $details));
                if (is_numeric($document['base'] ?? null) && abs($lineBase - (float) $document['base']) > 0.01) {
                    $result['reason'] = 'Jumlah detail produk berbeda dari basis sumber operasional.';
                    $prepared[$identity] = $result;
                    continue;
                }
                $draft['details'] = $details;
            } elseif ($sourceType === 'Expense') {
                $expenseLines = (array) ($document['expense_lines'] ?? []);
                if ($expenseLines === [] && trim((string) ($document['category_name'] ?? '')) !== '' && is_numeric($document['base'] ?? null)) {
                    $expenseLines[] = [
                        'category_name' => $document['category_name'],
                        'description' => $document['description'] ?? '',
                        'amount' => (float) $document['base'],
                    ];
                }
                if ($expenseLines === []) {
                    $result['reason'] = 'Detail kategori dan nominal Expense tidak tersedia.';
                    $prepared[$identity] = $result;
                    continue;
                }

                $resolvedLines = [];
                $invalidLine = null;
                foreach ($expenseLines as $line) {
                    $categoryName = $this->normalizeText($line['category_name'] ?? '');
                    $categoryIds = array_values($categoryIndex[$categoryName] ?? []);
                    if ($categoryName === '' || count($categoryIds) !== 1 || ! is_numeric($line['amount'] ?? null) || (float) $line['amount'] <= 0) {
                        $invalidLine = $categoryName !== '' ? $categoryName : 'kategori tanpa nama';
                        break;
                    }
                    $resolvedLines[] = [
                        'category_id' => (int) $categoryIds[0],
                        'description' => trim((string) ($line['description'] ?? '')) ?: null,
                        'amount' => round((float) $line['amount'], 4),
                    ];
                }
                if ($invalidLine !== null) {
                    $result['reason'] = 'Kategori Expense tidak ditemukan secara unik atau nominal detail tidak valid: '.$invalidLine.'.';
                    $prepared[$identity] = $result;
                    continue;
                }
                $lineBase = array_sum(array_column($resolvedLines, 'amount'));
                if (is_numeric($document['base'] ?? null) && abs($lineBase - (float) $document['base']) > 0.01) {
                    $result['reason'] = 'Jumlah detail kategori Expense berbeda dari basis sumber operasional.';
                    $prepared[$identity] = $result;
                    continue;
                }
                $draft['expense_category_id'] = (int) $resolvedLines[0]['category_id'];
                $result['expense_lines'] = $resolvedLines;
            } else {
                $result['reason'] = 'Jenis transaksi operasional ini belum didukung untuk pembuatan otomatis.';
                $prepared[$identity] = $result;
                continue;
            }

            $result['ready'] = true;
            $result['draft_data'] = $draft;
            $prepared[$identity] = $result;
        }

        return $prepared;
    }

    public function enrich(array $entries, array $evidence, int $businessId, JournalImportDraftService $draftService): array
    {
        $documents = (array) ($evidence['documents'] ?? []);
        if ($documents === []) {
            return $entries;
        }
        $productIndex = $this->productIndex($businessId);
        $contactIndex = $this->contactIndex($businessId);
        $categoryIndex = $this->categoryIndex($businessId);
        $transactionState = $this->transactionStateIndex($entries, $businessId);
        $locationId = $draftService->defaultActiveLocationId($businessId);

        foreach ($entries as $index => $entry) {
            $key = $this->documentKey($entry);
            if ($key === null || ! isset($documents[$key])) {
                $entries[$index]['legacy_evidence_status'] = 'missing';
                continue;
            }

            $document = $documents[$key];
            $entries[$index]['legacy_evidence_identity'] = $key;
            $entries[$index]['legacy_sources'] = $this->mergeSourceProvenance(
                (array) ($entry['legacy_sources'] ?? []),
                (array) ($document['provenance'] ?? []),
                $document,
                $key
            );
            $entries[$index]['legacy_evidence_status'] = (string) ($document['status'] ?? 'matched');
            $entries[$index]['legacy_evidence_source_count'] = (int) ($document['source_count'] ?? 1);

            if (($document['status'] ?? null) === 'conflict') {
                $entries[$index]['legacy_evidence_reason'] = (string) ($document['review_reason'] ?? 'File operational memiliki konflik.');
                $entries[$index]['source_identity_conflict'] = true;
                $entries[$index]['source_identity_conflict_message'] = $entries[$index]['legacy_evidence_reason'];
                continue;
            }

            if (! $this->amountMatches($entry, $document)) {
                $entries[$index]['legacy_evidence_status'] = 'amount_mismatch';
                $entries[$index]['legacy_evidence_reason'] = 'Total sumber operational berbeda dari total Journal; detail tidak diterapkan otomatis.';
                $entries[$index]['source_identity_conflict'] = true;
                $entries[$index]['source_identity_conflict_message'] = $entries[$index]['legacy_evidence_reason'];
                continue;
            }

            $draft = [];
            if ($locationId !== null) {
                $draft['location_id'] = $locationId;
            }
            $contactId = $this->contactId($document, $entry, $contactIndex);
            if ($contactId !== null) {
                $draft['contact_id'] = $contactId;
            }

            $sourceType = (string) ($entry['source_type'] ?? '');
            if (in_array($sourceType, ['Sales Invoice', 'Purchase Invoice'], true)) {
                $details = $this->invoiceDetails((array) ($document['lines'] ?? []), $productIndex, $reason);
                if ($details === []) {
                    $entries[$index]['legacy_evidence_status'] = 'incomplete';
                    $entries[$index]['legacy_evidence_reason'] = $reason ?? 'Detail produk belum lengkap atau tidak dapat dipetakan secara unik.';
                    continue;
                }
                $draft['details'] = $details;
                $this->preferEnrichmentAction($entries[$index], $transactionState, $details);
            } elseif ($sourceType === 'Expense') {
                $this->applyExpenseCategoryMappings($draft, $entry, $document, $categoryIndex, $businessId);
            }

            if ($draft !== []) {
                $entries[$index]['legacy_draft_data'] = $draft;
            }
        }

        return $entries;
    }

    public function persistDrafts(int $importId, int $businessId, array $entries): int
    {
        $updated = 0;
        foreach (array_chunk(array_values($entries), 250, true) as $chunk) {
            foreach ($chunk as $entryIndex => $entry) {
                $draft = $entry['legacy_draft_data'] ?? null;
                if (! is_array($draft) || $draft === []) {
                    continue;
                }
                DB::table('accounting_journal_import_rows')
                    ->where('journal_import_id', $importId)
                    ->where('business_id', $businessId)
                    ->where('entry_index', $entryIndex)
                    ->update([
                        'draft_data' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                $updated++;
            }
        }

        return $updated;
    }

    private function productIndex(int $businessId): array
    {
        $rows = DB::table('variations as variation')
            ->join('products as product', 'product.id', '=', 'variation.product_id')
            ->where('product.business_id', $businessId)
            ->where('product.is_inactive', 0)
            ->whereNull('product.deleted_at')
            ->whereNull('variation.deleted_at')
            ->get([
                'variation.id as variation_id',
                'variation.name as variation_name',
                'variation.sub_sku',
                'product.id as product_id',
                'product.name as product_name',
                'product.sku as product_sku',
            ]);
        $byCode = [];
        $byName = [];
        foreach ($rows as $row) {
            $variation = [
                'variation_id' => (int) $row->variation_id,
                'product_id' => (int) $row->product_id,
            ];
            foreach ([$row->sub_sku, $row->product_sku] as $code) {
                $key = $this->normalizeText($code);
                if ($key !== '') {
                    $byCode[$key][$row->variation_id] = $variation;
                }
            }
            foreach ([$row->product_name, $row->variation_name, trim($row->product_name.' '.$row->variation_name)] as $name) {
                $key = $this->normalizeText($name);
                if ($key !== '') {
                    $byName[$key][$row->variation_id] = $variation;
                }
            }
        }

        return ['by_code' => $byCode, 'by_name' => $byName];
    }

    private function contactIndex(int $businessId): array
    {
        $rows = DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'supplier_business_name', 'type']);
        $index = [];
        foreach ($rows as $row) {
            foreach ([$row->name, $row->supplier_business_name] as $name) {
                $key = $this->normalizeText($name);
                if ($key !== '') {
                    $index[$key][$row->id] = [
                        'id' => (int) $row->id,
                        'type' => (string) $row->type,
                    ];
                }
            }
        }

        return $index;
    }

    private function categoryIndex(int $businessId): array
    {
        $rows = DB::table('expense_categories')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->get(['id', 'name']);
        $index = [];
        foreach ($rows as $row) {
            $key = $this->normalizeText($row->name);
            if ($key !== '') {
                $index[$key][$row->id] = (int) $row->id;
            }
        }

        return $index;
    }

    private function transactionStateIndex(array $entries, int $businessId): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if (($entry['source_record_type'] ?? null) === Transaction::class && ! empty($entry['source_record_id'])) {
                $ids[(int) $entry['source_record_id']] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $transactions = DB::table('transactions')
            ->where('business_id', $businessId)
            ->whereIn('id', array_keys($ids))
            ->get(['id', 'type'])
            ->keyBy('id');
        $sellIds = $transactions->where('type', 'sell')->keys()->all();
        $purchaseIds = $transactions->where('type', 'purchase')->keys()->all();
        $hasLines = [];
        if ($sellIds !== []) {
            foreach (DB::table('transaction_sell_lines')->whereIn('transaction_id', $sellIds)->distinct()->pluck('transaction_id') as $id) {
                $hasLines[(int) $id] = true;
            }
        }
        if ($purchaseIds !== []) {
            foreach (DB::table('purchase_lines')->whereIn('transaction_id', $purchaseIds)->distinct()->pluck('transaction_id') as $id) {
                $hasLines[(int) $id] = true;
            }
        }

        $state = [];
        foreach ($transactions as $transaction) {
            $state[(int) $transaction->id] = ['has_lines' => ! empty($hasLines[(int) $transaction->id])];
        }

        return $state;
    }

    private function invoiceDetails(array $lines, array $productIndex, ?string &$reason): array
    {
        $reason = null;
        if ($lines === [] || count($lines) > 100) {
            $reason = $lines === [] ? 'File operational tidak menyertakan detail produk.' : 'Jumlah detail produk melebihi batas 100 baris per invoice.';

            return [];
        }

        $details = [];
        foreach ($lines as $line) {
            if ((float) ($line['discount_percent'] ?? 0) > 0) {
                $reason = 'Baris memiliki diskon; import otomatis tidak mengubah diskon menjadi harga produk.';

                return [];
            }
            $quantity = $line['quantity'] ?? null;
            $unitPrice = $line['unit_price'] ?? null;
            if (! is_numeric($quantity) || (float) $quantity <= 0 || ! is_numeric($unitPrice) || (float) $unitPrice < 0) {
                $reason = 'Kuantitas atau harga baris produk tidak valid.';

                return [];
            }

            $matches = $this->productMatches($line, $productIndex);
            if (count($matches) !== 1) {
                $reason = count($matches) === 0
                    ? 'Produk tidak ditemukan dengan SKU atau nama persis: '.(string) ($line['product_name'] ?? $line['product_code'] ?? '').'.'
                    : 'Produk atau variasi ambigu untuk: '.(string) ($line['product_name'] ?? $line['product_code'] ?? '').'.';

                return [];
            }

            $details[] = [
                'variation_id' => (int) $matches[0]['variation_id'],
                'quantity' => round((float) $quantity, 4),
                'unit_price' => round((float) $unitPrice, 4),
                'description' => trim((string) ($line['description'] ?? '')),
            ];
        }

        return $details;
    }

    private function productMatches(array $line, array $index): array
    {
        $code = $this->normalizeText($line['product_code'] ?? '');
        if ($code !== '') {
            $matches = array_values($index['by_code'][$code] ?? []);
            if ($matches !== []) {
                return $matches;
            }
        }

        $name = $this->normalizeText($line['product_name'] ?? '');

        return array_values($index['by_name'][$name] ?? []);
    }

    private function preferEnrichmentAction(array &$entry, array $transactionState, array $details): void
    {
        if (($entry['source_link_status'] ?? null) !== 'matched'
            || ($entry['source_record_type'] ?? null) !== Transaction::class
            || empty($entry['source_record_id'])) {
            return;
        }

        $transactionId = (int) $entry['source_record_id'];
        if (empty($transactionState[$transactionId]['has_lines'])) {
            $entry['source_link_default_action'] = 'update';
        }
    }

    private function applyExpenseCategoryMappings(array &$draft, array $entry, array $document, array $categoryIndex, int $businessId): void
    {
        $expenseLines = (array) ($document['expense_lines'] ?? []);
        $categoryNames = array_values(array_unique(array_filter(array_map(
            fn (array $line) => $this->normalizeText($line['category_name'] ?? ''),
            $expenseLines
        ))));
        if ($categoryNames === [] && trim((string) ($document['category_name'] ?? '')) !== '') {
            $categoryNames[] = $this->normalizeText($document['category_name']);
        }

        $categoryIds = [];
        foreach ($categoryNames as $name) {
            $ids = array_values($categoryIndex[$name] ?? []);
            if (count($ids) === 1) {
                $categoryIds[$name] = $ids[0];
            }
        }
        if ($categoryIds === []) {
            return;
        }

        $components = (new JournalImportAccountComponentService())->groupJournalLines($entry, $businessId);
        $expenseComponents = array_values(array_filter($components, fn (array $component) => ! $component['is_tax'] && $component['debit'] > 0));
        if (count($categoryIds) === 1) {
            $categoryId = (int) reset($categoryIds);
            $draft['expense_category_id'] = $categoryId;
            foreach ($expenseComponents as $component) {
                $draft['expense_category_mappings'][$component['account_key']] = $categoryId;
            }

            return;
        }

        $availableLines = $expenseLines;
        foreach ($expenseComponents as $component) {
            $matches = [];
            foreach ($availableLines as $lineIndex => $line) {
                $categoryName = $this->normalizeText($line['category_name'] ?? '');
                if (! isset($categoryIds[$categoryName]) || ! is_numeric($line['amount'] ?? null)) {
                    continue;
                }
                if (abs((float) $line['amount'] - (float) $component['debit']) <= 0.01) {
                    $matches[$lineIndex] = $categoryIds[$categoryName];
                }
            }
            if (count($matches) === 1) {
                $lineIndex = array_key_first($matches);
                $draft['expense_category_mappings'][$component['account_key']] = $matches[$lineIndex];
                unset($availableLines[$lineIndex]);
            }
        }

        if (($draft['expense_category_mappings'] ?? []) !== []) {
            $draft['expense_category_id'] = (int) reset($draft['expense_category_mappings']);
        }
    }

    private function contactId(array $document, array $entry, array $contacts): ?int
    {
        $name = $this->normalizeText($document['contact_name'] ?? '');
        if ($name === '') {
            return null;
        }
        $matches = array_values($contacts[$name] ?? []);
        $requiredType = ($entry['source_type'] ?? null) === 'Sales Invoice' ? ['customer', 'both'] : ['supplier', 'both'];
        $matches = array_values(array_filter($matches, fn (array $contact) => in_array($contact['type'], $requiredType, true)));

        return count($matches) === 1 ? (int) $matches[0]['id'] : null;
    }

    private function amountMatches(array $entry, array $document): bool
    {
        if (! is_numeric($document['total'] ?? null)) {
            return true;
        }
        $journalTotal = max((float) ($entry['debit_total'] ?? 0), (float) ($entry['credit_total'] ?? 0));

        return abs($journalTotal - (float) $document['total']) <= 0.01;
    }

    private function documentKey(array $entry): ?string
    {
        $sourceType = trim((string) ($entry['source_type'] ?? ''));
        $sourceNumber = $this->normalizeText($entry['source_number'] ?? '');
        $date = substr(trim((string) ($entry['date'] ?? '')), 0, 10);
        if ($sourceType === '' || $sourceNumber === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return implode('|', [$sourceType, $sourceNumber, $date]);
    }

    private function mergeSourceProvenance(array $existing, array $sources, array $document, string $identity): array
    {
        $fingerprint = hash('sha256', json_encode([
            $identity,
            (string) ($document['total'] ?? ''),
            (array) ($document['lines'] ?? []),
            (array) ($document['expense_lines'] ?? []),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            $existing[] = [
                'path' => (string) ($source['path'] ?? ''),
                'sha256' => (string) ($source['sha256'] ?? ''),
                'row' => (int) ($source['row'] ?? 0),
                'source_key' => $identity,
                'source_identity' => (string) ($source['source_identity'] ?? ''),
                'fingerprint' => $fingerprint,
            ];
        }

        $unique = [];
        $seen = [];
        foreach ($existing as $source) {
            if (! is_array($source)) {
                continue;
            }
            $key = implode('|', [(string) ($source['path'] ?? ''), (string) ($source['row'] ?? ''), (string) ($source['fingerprint'] ?? '')]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $source;
        }

        return $unique;
    }

    private function normalizeText($value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $value) ?: (string) $value;

        return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }
}
