<?php

namespace App\Services;

use App\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Services\JournalImportAccountComponentService;
use Modules\Accounting\Services\JournalImportTaxResolver;
use RuntimeException;

class ExpenseImportJournalMatcher
{
    private $componentService;

    private $taxResolver;

    public function __construct(
        JournalImportAccountComponentService $componentService,
        JournalImportTaxResolver $taxResolver
    ) {
        $this->componentService = $componentService;
        $this->taxResolver = $taxResolver;
    }

    /**
     * Find completed or in-progress Journal Expense rows by exact reference.
     * Incomplete Journal rows are returned as unavailable candidates so an
     * Expense import cannot silently race or duplicate an unfinished posting.
     */
    public function matchReferences(int $businessId, array $references, array $overrides, array $taxableBases): array
    {
        $normalizedReferences = [];
        foreach ($references as $reference) {
            $normalized = $this->normalizeReference((string) $reference);
            if ($normalized !== '') {
                $normalizedReferences[$normalized] = true;
            }
        }

        if ($normalizedReferences === []) {
            return [];
        }

        foreach ([
            'accounting_journal_imports',
            'accounting_journal_import_rows',
            'accounting_acc_trans_mappings',
            'accounting_accounts_transactions',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Tabel Journal GL belum tersedia. Jalankan migration Accounting sebelum import Expense.');
            }
        }

        $rowsByReference = [];
        foreach (array_chunk(array_keys($normalizedReferences), 300) as $referenceChunk) {
            $journalRows = DB::table('accounting_journal_import_rows as journal_row')
                ->join('accounting_journal_imports as journal_import', 'journal_import.id', '=', 'journal_row.journal_import_id')
                ->leftJoin('accounting_acc_trans_mappings as mapping', function ($join) {
                    $join->on('mapping.id', '=', 'journal_row.journal_mapping_id')
                        ->on('mapping.business_id', '=', 'journal_row.business_id');
                })
                ->where('journal_row.business_id', $businessId)
                ->where('journal_import.business_id', $businessId)
                ->where('journal_row.source_type', 'Expense')
                ->whereIn('journal_import.status', ['analyzed', 'partially_imported', 'failed', 'cancelled', 'imported'])
                ->whereIn(DB::raw('LOWER(TRIM(journal_row.source_number))'), $referenceChunk)
                ->select([
                    'journal_row.id as journal_row_id',
                    'journal_row.journal_import_id',
                    'journal_row.source_number',
                    'journal_row.source_record_type as row_source_record_type',
                    'journal_row.source_record_id as row_source_record_id',
                    'journal_row.entry_data',
                    'journal_row.processing_status',
                    'journal_row.journal_mapping_id',
                    'mapping.business_id as mapping_business_id',
                    'mapping.type as mapping_type',
                    'mapping.source_record_type as mapping_source_record_type',
                    'mapping.source_record_id as mapping_source_record_id',
                    'mapping.source_link_status',
                    'journal_import.status as journal_import_status',
                ])
                ->orderBy('journal_row.id')
                ->get();

            $sourceLinksByRow = [];
            if (Schema::hasTable('accounting_journal_import_source_links') && $journalRows->isNotEmpty()) {
                DB::table('accounting_journal_import_source_links')
                    ->where('business_id', $businessId)
                    ->whereIn('journal_import_row_id', $journalRows->pluck('journal_row_id')->all())
                    ->get(['journal_import_row_id', 'source_record_type', 'source_record_id'])
                    ->each(function ($link) use (&$sourceLinksByRow) {
                        $sourceLinksByRow[(int) $link->journal_import_row_id][] = [
                            'type' => (string) $link->source_record_type,
                            'id' => (int) $link->source_record_id,
                        ];
                    });
            }

            $journalRows->each(function ($row) use (&$rowsByReference, $businessId, $taxableBases, $sourceLinksByRow) {
                    $entry = json_decode((string) $row->entry_data, true);
                    $entry = is_array($entry) ? $entry : [];
                    $reference = trim((string) ($entry['source_number'] ?? $row->source_number ?? ''));
                    $normalized = $this->normalizeReference($reference);
                    if ($normalized === '') {
                        return;
                    }

                    $mappingId = empty($row->journal_mapping_id) ? null : (int) $row->journal_mapping_id;
                    $candidateKey = $mappingId === null ? 'row:'.(int) $row->journal_row_id : 'mapping:'.$mappingId;
                    $candidate = $this->candidate(
                        $row,
                        $entry,
                        $businessId,
                        (float) ($taxableBases[$normalized] ?? 0),
                        $sourceLinksByRow[(int) $row->journal_row_id] ?? []
                    );
                    if (! isset($rowsByReference[$normalized][$candidateKey])
                        || ($rowsByReference[$normalized][$candidateKey]['ready'] === false && $candidate['ready'] === true)) {
                        $rowsByReference[$normalized][$candidateKey] = $candidate;
                    }
                });
        }

        $matches = [];
        foreach (array_keys($normalizedReferences) as $reference) {
            $candidates = array_values($rowsByReference[$reference] ?? []);
            usort($candidates, function (array $left, array $right): int {
                return strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''))
                    ?: ((int) ($left['journal_row_id'] ?? 0) <=> (int) ($right['journal_row_id'] ?? 0));
            });

            $override = $overrides['journal_candidate:'.$reference] ?? null;
            if ($override !== null && $override !== '') {
                $selected = null;
                foreach ($candidates as $candidate) {
                    if ((int) ($candidate['mapping_id'] ?? 0) === (int) $override) {
                        $selected = $candidate;
                        break;
                    }
                }
                $matches[$reference] = $selected === null
                    ? ['status' => 'selection_invalid', 'selected' => null, 'candidates' => $candidates]
                    : ['status' => 'matched', 'selected' => $selected, 'candidates' => $candidates, 'selected_by_user' => true];
            } elseif (count($candidates) === 1) {
                $matches[$reference] = ['status' => 'matched', 'selected' => $candidates[0], 'candidates' => $candidates, 'selected_by_user' => false];
            } elseif (count($candidates) > 1) {
                $matches[$reference] = ['status' => 'ambiguous', 'selected' => null, 'candidates' => $candidates];
            } else {
                $matches[$reference] = ['status' => 'none', 'selected' => null, 'candidates' => []];
            }
        }

        return $matches;
    }

    private function candidate(object $row, array $entry, int $businessId, float $taxableBase, array $sourceLinks = []): array
    {
        $mappingId = empty($row->journal_mapping_id) ? null : (int) $row->journal_mapping_id;
        $ready = $row->processing_status === 'completed'
            && $mappingId !== null
            && (int) ($row->mapping_business_id ?? 0) === $businessId
            && (string) ($row->mapping_type ?? '') === 'journal_entry'
            && (string) ($row->journal_import_status ?? '') !== 'reverted';
        $issue = $ready ? null : 'Posting Journal untuk referensi ini belum selesai atau tidak tersedia di General Ledger.';

        $mappingSourceType = trim((string) ($row->mapping_source_record_type ?? ''));
        $mappingSourceId = empty($row->mapping_source_record_id) ? null : (int) $row->mapping_source_record_id;
        $rowSourceType = trim((string) ($row->row_source_record_type ?? ''));
        $rowSourceId = empty($row->row_source_record_id) ? null : (int) $row->row_source_record_id;
        if ($mappingSourceType !== '' && $rowSourceType !== ''
            && ($mappingSourceType !== $rowSourceType || $mappingSourceId !== $rowSourceId)) {
            $issue = 'Relasi sumber pada baris Journal dan mapping GL berbeda; perlu ditinjau.';
            $ready = false;
        }

        $linkedRecords = [];
        foreach ([
            [$mappingSourceType, $mappingSourceId],
            [$rowSourceType, $rowSourceId],
        ] as [$recordType, $recordId]) {
            if ($recordType !== '' && $recordId !== null) {
                $linkedRecords[$recordType.':'.$recordId] = ['type' => $recordType, 'id' => $recordId];
            }
        }
        foreach ($sourceLinks as $link) {
            if (! empty($link['type']) && ! empty($link['id'])) {
                $linkedRecords[(string) $link['type'].':'.(int) $link['id']] = [
                    'type' => (string) $link['type'],
                    'id' => (int) $link['id'],
                ];
            }
        }
        $sourceLinkGroup = is_array($entry['source_link_group'] ?? null) ? $entry['source_link_group'] : [];
        $sourceLinkMembers = $sourceLinkGroup['members'] ?? [];
        foreach (is_array($sourceLinkMembers) ? $sourceLinkMembers : [] as $member) {
            if (is_array($member) && ! empty($member['record_type']) && ! empty($member['id'])) {
                $linkedRecords[(string) $member['record_type'].':'.(int) $member['id']] = [
                    'type' => (string) $member['record_type'],
                    'id' => (int) $member['id'],
                ];
            }
        }
        if (count($linkedRecords) > 1) {
            $issue = 'Journal memiliki lebih dari satu relasi sumber operasional; perlu ditinjau agar Expense tidak dibuat ganda.';
            $ready = false;
        }
        $linkedRecord = $linkedRecords === [] ? null : reset($linkedRecords);
        $sourceType = $linkedRecord['type'] ?? '';
        $sourceId = $linkedRecord['id'] ?? null;
        $sourceTransactionId = null;
        if ($sourceId !== null) {
            if ($sourceType !== Transaction::class) {
                $issue = 'Journal sudah tertaut ke jenis sumber lain; Expense tidak akan menimpa relasi itu.';
                $ready = false;
            } else {
                $sourceTransaction = Transaction::where('business_id', $businessId)
                    ->where('type', 'expense')
                    ->find($sourceId);
                if (! $sourceTransaction) {
                    $issue = 'Expense yang tertaut ke Journal tidak ditemukan pada business aktif.';
                    $ready = false;
                } else {
                    $journalReference = trim((string) ($entry['source_number'] ?? $row->source_number ?? ''));
                    if ($this->normalizeReference((string) $sourceTransaction->ref_no) !== $this->normalizeReference($journalReference)) {
                        $issue = 'Referensi Expense yang sudah tertaut berbeda dengan Journal; perlu ditinjau.';
                        $ready = false;
                    } else {
                        $sourceTransactionId = (int) $sourceTransaction->id;
                    }
                }
            }
        }

        if ($ready && ! DB::table('accounting_accounts_transactions')->where('acc_trans_mapping_id', $mappingId)->exists()) {
            $issue = 'Posting Journal tidak memiliki baris General Ledger.';
            $ready = false;
        }

        $tax = $this->journalTax($entry, $businessId, $taxableBase);
        if ($issue === null && $tax['issue'] !== null) {
            $issue = $tax['issue'];
            $ready = false;
        }

        return [
            'mapping_id' => $mappingId,
            'journal_row_id' => (int) $row->journal_row_id,
            'journal_import_id' => (int) $row->journal_import_id,
            'reference' => trim((string) ($entry['source_number'] ?? $row->source_number ?? '')),
            'date' => substr(trim((string) ($entry['date'] ?? '')), 0, 10),
            'description' => trim((string) ($entry['description'] ?? '')),
            'journal_total' => is_numeric($entry['debit_total'] ?? null) ? (float) $entry['debit_total'] : null,
            'tax_amount' => $tax['amount'],
            'tax_id' => $tax['tax_id'],
            'tax_name' => $tax['tax_name'],
            'tax_label' => $tax['tax_label'],
            'tax_issue' => $tax['issue'],
            'source_transaction_id' => $sourceTransactionId,
            'ready' => $ready,
            'issue' => $issue,
        ];
    }

    private function journalTax(array $entry, int $businessId, float $taxableBase): array
    {
        $components = $this->componentService->groupJournalLines($entry, $businessId);
        if ($components === []) {
            return $this->taxIssue('Baris GL Journal tidak memiliki komponen akun yang dapat dibaca.');
        }

        $debitTotal = round((float) collect($components)->sum('debit'), 4);
        $creditTotal = round((float) collect($components)->sum('credit'), 4);
        if (abs($debitTotal - $creditTotal) > 0.01) {
            return $this->taxIssue('Journal Expense tidak seimbang; sumber ini tidak dapat dipakai otomatis.');
        }

        $taxComponents = array_values(array_filter($components, fn (array $component) => ! empty($component['is_tax'])));
        if ($taxComponents === []) {
            return [
                'amount' => 0.0,
                'tax_id' => null,
                'tax_name' => null,
                'tax_label' => 'Journal: tanpa pajak',
                'issue' => null,
            ];
        }

        $taxAmount = 0.0;
        $labels = [];
        foreach ($taxComponents as $component) {
            $label = trim((string) ($component['account_name'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($component['account_no'] ?? 'akun pajak'));
            }
            $labels[] = $label;
            if (! empty($component['tax_direction_conflict'])
                || ($component['tax_direction'] ?? null) !== 'input'
                || (float) ($component['credit'] ?? 0) > 0
                || (float) ($component['debit'] ?? 0) <= 0) {
                return $this->taxIssue('Akun pajak Journal tidak dapat dipastikan sebagai VAT In di sisi debit: '.$label.'.');
            }
            $taxAmount += (float) $component['debit'];
        }
        $taxAmount = round($taxAmount, 4);
        if ($taxAmount <= 0) {
            return $this->taxIssue('Komponen pajak Journal tidak memiliki nominal debit positif.');
        }

        $taxId = null;
        $taxName = null;
        if ($taxableBase > 0) {
            try {
                $resolvedTax = $this->taxResolver->resolveForEntry($entry, $businessId, $taxableBase);
                if (($resolvedTax['direction'] ?? null) === 'input') {
                    $taxId = (int) $resolvedTax['id'];
                    $taxName = DB::table('tax_rates')->where('business_id', $businessId)->where('id', $taxId)->value('name');
                }
            } catch (\Throwable $exception) {
                // Keep the exact Journal amount. A TaxRate is only metadata and
                // is not created when the business has no unique matching rate.
            }
        }

        return [
            'amount' => $taxAmount,
            'tax_id' => $taxId,
            'tax_name' => $taxName,
            'tax_label' => 'Journal: '.implode(', ', array_unique($labels)).' · '.number_format($taxAmount, 2, '.', ','),
            'issue' => null,
        ];
    }

    private function taxIssue(string $message): array
    {
        return [
            'amount' => null,
            'tax_id' => null,
            'tax_name' => null,
            'tax_label' => null,
            'issue' => $message,
        ];
    }

    private function normalizeReference(string $reference): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $reference)));
    }
}
