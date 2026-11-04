<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\AccountTransaction;
use App\Transaction;
use App\TransactionPayment;

class LegacyJournalImportSourceLinkAuditReader
{
    public function mergeIntoEvidence(array $evidence, int $businessId, ?int $journalImportId): array
    {
        if ($businessId < 1 || $journalImportId === null || $journalImportId < 1
            || ! Schema::hasTable('accounting_journal_import_source_links')
            || ! Schema::hasTable('accounting_journal_import_rows')) {
            return $evidence;
        }

        $rows = DB::table('accounting_journal_import_source_links as source_link')
            ->join('accounting_journal_import_rows as journal_row', function ($join) {
                $join->on('journal_row.id', '=', 'source_link.journal_import_row_id')
                    ->on('journal_row.business_id', '=', 'source_link.business_id')
                    ->on('journal_row.journal_import_id', '=', 'source_link.journal_import_id');
            })
            ->where('source_link.business_id', $businessId)
            ->where('source_link.journal_import_id', $journalImportId)
            ->where('journal_row.processing_status', 'completed')
            ->orderBy('source_link.id')
            ->get([
                'source_link.source_record_type',
                'source_link.source_record_id',
                'source_link.source_reference',
                'source_link.source_date',
                'source_link.base_amount',
                'source_link.tax_amount',
                'source_link.total_amount',
                'source_link.link_method',
                'journal_row.source_type',
                'journal_row.source_number',
                'journal_row.entry_index',
                'journal_row.entry_data',
            ]);
        $activeRecords = $this->activeSourceRecords($businessId, $rows);

        $linkedDocuments = [];
        foreach ($rows as $row) {
            $entry = json_decode((string) $row->entry_data, true);
            if (! is_array($entry)) {
                continue;
            }

            $sourceType = trim((string) ($entry['source_type'] ?? $row->source_type ?? ''));
            $sourceNumber = trim((string) ($entry['source_number'] ?? $row->source_number ?? ''));
            $date = $this->date($entry['date'] ?? $row->source_date ?? null);
            $identity = $this->identity($sourceType, $sourceNumber, $date);
            if ($identity === null) {
                continue;
            }

            $recordType = trim((string) $row->source_record_type);
            $recordId = (int) $row->source_record_id;
            if ($recordType === '' || $recordId < 1) {
                continue;
            }

            if (! isset($linkedDocuments[$identity])) {
                $linkedDocuments[$identity] = [
                    'source_type' => $sourceType,
                    'source_number' => $sourceNumber,
                    'date' => $date,
                    'status' => 'matched',
                    'source_count' => 1,
                    'source_linked' => true,
                    'linked_source_type' => $sourceType,
                    'linked_source_records' => [],
                    'provenance' => [],
                    'base' => null,
                    'tax' => null,
                    'total' => null,
                    'lines' => [],
                    'expense_lines' => [],
                ];
            }

            $recordIdentity = $recordType.'#'.$recordId;
            if (isset($linkedDocuments[$identity]['linked_source_records'][$recordIdentity])) {
                continue;
            }

            $linkedDocuments[$identity]['linked_source_records'][$recordIdentity] = [
                'record_type' => $recordType,
                'record_id' => $recordId,
                'record_active' => isset($activeRecords[$recordType][$recordId]),
                'link_method' => (string) ($row->link_method ?? 'single'),
                'source_reference' => (string) ($row->source_reference ?? ''),
                'source_date' => $this->date($row->source_date ?? null),
                'base_amount' => is_numeric($row->base_amount) ? (float) $row->base_amount : null,
                'tax_amount' => is_numeric($row->tax_amount) ? (float) $row->tax_amount : null,
                'total_amount' => is_numeric($row->total_amount) ? (float) $row->total_amount : null,
            ];
            $linkedDocuments[$identity]['provenance'][] = [
                'path' => 'Journal Import #'.$journalImportId.' / '.$recordType.' #'.$recordId,
                'row' => is_numeric($row->entry_index) ? (int) $row->entry_index + 1 : null,
                'source_key' => $recordIdentity,
            ];
            if (! isset($activeRecords[$recordType][$recordId])) {
                $linkedDocuments[$identity]['status'] = 'conflict';
                $linkedDocuments[$identity]['review_reason'] = 'Tautan Journal Import menunjuk ke sumber operasional yang sudah tidak aktif atau tidak ditemukan pada business ini.';
            }
        }

        $identitiesBySource = [];
        foreach ($linkedDocuments as $identity => $document) {
            foreach (array_keys($document['linked_source_records']) as $recordIdentity) {
                $identitiesBySource[$recordIdentity][$identity] = true;
            }
        }
        foreach ($linkedDocuments as $identity => &$document) {
            foreach (array_keys($document['linked_source_records']) as $recordIdentity) {
                if (count($identitiesBySource[$recordIdentity] ?? []) > 1) {
                    $document['status'] = 'conflict';
                    $document['review_reason'] = 'Sumber operasional yang sama tertaut ke beberapa kelompok Journal Import.';
                    break;
                }
            }
        }
        unset($document);

        $documents = (array) ($evidence['documents'] ?? []);
        foreach ($linkedDocuments as $identity => $linkedDocument) {
            $members = array_values($linkedDocument['linked_source_records']);
            $linkedDocument['linked_source_records'] = $members;
            $linkedDocument['linked_source_count'] = count($members);
            foreach ([
                'base' => 'base_amount',
                'tax' => 'tax_amount',
                'total' => 'total_amount',
            ] as $target => $source) {
                $values = array_column($members, $source);
                if ($values !== [] && ! in_array(null, $values, true)) {
                    $linkedDocument[$target] = round(array_sum($values), 4);
                }
            }

            if (! isset($documents[$identity])) {
                $documents[$identity] = $linkedDocument;
                continue;
            }

            $document = $documents[$identity];
            $conflicts = [];
            foreach (['base', 'tax', 'total'] as $component) {
                $workbookAmount = $document[$component] ?? null;
                $linkedAmount = $linkedDocument[$component] ?? null;
                if (is_numeric($workbookAmount) && is_numeric($linkedAmount)
                    && abs((float) $workbookAmount - (float) $linkedAmount) > 0.01) {
                    $conflicts[] = $component;
                } elseif (! is_numeric($workbookAmount) && is_numeric($linkedAmount)) {
                    $document[$component] = $linkedAmount;
                }
            }
            $document['source_linked'] = true;
            $document['linked_source_type'] = $linkedDocument['linked_source_type'];
            $document['linked_source_records'] = $linkedDocument['linked_source_records'];
            $document['linked_source_count'] = $linkedDocument['linked_source_count'];
            $document['provenance'] = array_values(array_merge(
                (array) ($document['provenance'] ?? []),
                $linkedDocument['provenance']
            ));
            if (($linkedDocument['status'] ?? '') === 'conflict') {
                $conflicts[] = (string) ($linkedDocument['review_reason'] ?? 'sumber tertaut');
            }
            if ($conflicts !== []) {
                $document['status'] = 'conflict';
                $document['review_reason'] = 'Workbook atau relasi sumber menunjukkan konflik: '.implode(', ', $conflicts).'.';
            }
            $documents[$identity] = $document;
        }

        $evidence['documents'] = $documents;
        $evidence['persisted_source_link_count'] = array_sum(array_map(
            fn (array $document): int => (int) ($document['linked_source_count'] ?? 0),
            $linkedDocuments
        ));

        return $evidence;
    }

    private function identity(string $sourceType, string $sourceNumber, ?string $date): ?string
    {
        $normalizedNumber = $this->normalizeIdentityText($sourceNumber);
        if ($sourceType === '' || $normalizedNumber === '' || $date === null) {
            return null;
        }

        return implode('|', [$sourceType, $normalizedNumber, $date]);
    }

    private function normalizeIdentityText(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }

    private function date($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $date = substr(trim($value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    private function activeSourceRecords(int $businessId, iterable $links): array
    {
        $ids = [
            Transaction::class => [],
            TransactionPayment::class => [],
            AccountTransaction::class => [],
        ];
        foreach ($links as $link) {
            $type = (string) ($link->source_record_type ?? '');
            $id = (int) ($link->source_record_id ?? 0);
            if ($id > 0 && isset($ids[$type])) {
                $ids[$type][$id] = true;
            }
        }

        $active = [];
        if ($ids[Transaction::class] !== [] && Schema::hasTable('transactions')
            && Schema::hasColumn('transactions', 'business_id')) {
            $query = DB::table('transactions')->where('business_id', $businessId)->whereIn('id', array_keys($ids[Transaction::class]));
            if (Schema::hasColumn('transactions', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            if (Schema::hasColumn('transactions', 'status')) {
                $query->whereNotIn('status', ['draft', 'cancelled', 'canceled', 'voided']);
            }
            foreach ($query->pluck('id') as $id) {
                $active[Transaction::class][(int) $id] = true;
            }
        }

        if ($ids[TransactionPayment::class] !== [] && Schema::hasTable('transaction_payments')
            && Schema::hasTable('transactions')) {
            $query = DB::table('transaction_payments as payment')
                ->join('transactions as tx', 'tx.id', '=', 'payment.transaction_id')
                ->where('tx.business_id', $businessId)
                ->whereIn('payment.id', array_keys($ids[TransactionPayment::class]));
            if (Schema::hasColumn('transaction_payments', 'deleted_at')) {
                $query->whereNull('payment.deleted_at');
            }
            if (Schema::hasColumn('transactions', 'deleted_at')) {
                $query->whereNull('tx.deleted_at');
            }
            if (Schema::hasColumn('transactions', 'status')) {
                $query->whereNotIn('tx.status', ['draft', 'cancelled', 'canceled', 'voided']);
            }
            foreach ($query->pluck('payment.id') as $id) {
                $active[TransactionPayment::class][(int) $id] = true;
            }
        }

        if ($ids[AccountTransaction::class] !== [] && Schema::hasTable('account_transactions')
            && Schema::hasTable('accounts') && Schema::hasColumn('accounts', 'business_id')) {
            $query = DB::table('account_transactions as movement')
                ->join('accounts as account', 'account.id', '=', 'movement.account_id')
                ->where('account.business_id', $businessId)
                ->whereIn('movement.id', array_keys($ids[AccountTransaction::class]));
            if (Schema::hasColumn('account_transactions', 'deleted_at')) {
                $query->whereNull('movement.deleted_at');
            }
            foreach ($query->pluck('movement.id') as $id) {
                $active[AccountTransaction::class][(int) $id] = true;
            }
        }

        return $active;
    }
}
