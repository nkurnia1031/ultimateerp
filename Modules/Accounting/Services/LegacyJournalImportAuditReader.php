<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyJournalImportAuditReader
{
    public function readEntries(int $businessId, ?int $importId): array
    {
        if ($businessId < 1 || $importId === null || $importId < 1
            || ! Schema::hasTable('accounting_journal_import_rows')) {
            return [];
        }

        $entries = [];
        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->select('id', 'entry_data')
            ->chunkById(500, function ($rows) use (&$entries) {
                foreach ($rows as $row) {
                    $entry = json_decode((string) ($row->entry_data ?? ''), true);
                    if (is_array($entry)) {
                        $entries[] = $entry;
                    }
                }
            }, 'id');

        return $entries;
    }

    public function read(int $businessId, ?int $importId, array $unmappedBySourceKey = []): array
    {
        if ($businessId < 1 || $importId === null || $importId < 1
            || ! Schema::hasTable('accounting_journal_import_rows')) {
            return [];
        }

        $auditRows = [];
        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$auditRows, $unmappedBySourceKey) {
                foreach ($rows as $row) {
                    $entry = json_decode((string) ($row->entry_data ?? ''), true);
                    $entry = is_array($entry) ? $entry : [];
                    $sourceKey = trim((string) ($row->source_key ?? $entry['source_key'] ?? ''));
                    $date = substr(trim((string) ($entry['date'] ?? '')), 0, 10);
                    $missingAccounts = $unmappedBySourceKey[$sourceKey]
                        ?? (array) ($entry['missing_account_lines'] ?? []);
                    $auditRows[] = [
                        'id' => (int) $row->id,
                        'year' => preg_match('/^(\d{4})-\d{2}-\d{2}$/', $date, $match) === 1 ? (int) $match[1] : 0,
                        'date' => $date,
                        'source_type' => (string) ($row->source_type ?? $entry['source_type'] ?? 'Unknown'),
                        'source_number' => (string) ($row->source_number ?? $entry['source_number'] ?? ''),
                        'source_key' => $sourceKey,
                        'processing_status' => (string) ($row->processing_status ?? 'unknown'),
                        'processing_message' => $row->processing_message ?? null,
                        'processing_error' => $row->processing_error ?? null,
                        'source_action' => (string) ($row->source_action ?? ''),
                        'journal_mapping_id' => $row->journal_mapping_id ?? null,
                        'reconciliation_status' => (string) ($row->reconciliation_status ?? ''),
                        'journal_total' => $row->journal_total ?? null,
                        'business_total' => $row->business_total ?? null,
                        'difference_amount' => $row->difference_amount ?? null,
                        'missing_account_lines' => $missingAccounts,
                    ];
                }
            }, 'id');

        return $auditRows;
    }
}
