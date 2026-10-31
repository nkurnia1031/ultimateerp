<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;

class JournalImportSourceLinkService
{
    public function sync(int $rowId, int $importId, int $businessId, string $journalSourceType, array $entry, ?string $fallbackRecordType, ?int $fallbackRecordId): int
    {
        $group = $entry['source_link_group'] ?? null;
        $members = is_array($group['members'] ?? null) ? $group['members'] : [];
        if ($members === [] && $fallbackRecordType !== null && $fallbackRecordId !== null) {
            $members[] = [
                'record_type' => $fallbackRecordType,
                'id' => $fallbackRecordId,
                'reference' => $entry['source_number'] ?? null,
                'date' => $entry['date'] ?? null,
                'base_amount' => null,
                'tax_amount' => null,
                'amount' => null,
            ];
        }

        $now = now();
        $links = [];
        foreach ($members as $member) {
            $recordType = (string) ($member['record_type'] ?? '');
            $recordId = (int) ($member['id'] ?? 0);
            if ($recordType === '' || $recordId < 1) {
                continue;
            }
            $links[] = [
                'business_id' => $businessId,
                'journal_import_id' => $importId,
                'journal_import_row_id' => $rowId,
                'journal_source_type' => $journalSourceType,
                'source_record_type' => $recordType,
                'source_record_id' => $recordId,
                'source_reference' => $member['reference'] ?? $entry['source_number'] ?? null,
                'source_date' => ! empty($member['date']) ? substr((string) $member['date'], 0, 10) : null,
                'base_amount' => is_numeric($member['base_amount'] ?? null) ? (float) $member['base_amount'] : null,
                'tax_amount' => is_numeric($member['tax_amount'] ?? null) ? (float) $member['tax_amount'] : null,
                'total_amount' => is_numeric($member['amount'] ?? null) ? (float) $member['amount'] : null,
                'link_method' => ! empty($group['method']) ? (string) $group['method'] : 'single',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($links !== []) {
            DB::table('accounting_journal_import_source_links')->upsert(
                $links,
                ['journal_import_row_id', 'source_record_type', 'source_record_id'],
                [
                    'business_id', 'journal_import_id', 'journal_source_type', 'source_reference', 'source_date',
                    'base_amount', 'tax_amount', 'total_amount', 'link_method', 'updated_at',
                ]
            );
        }

        return count($links);
    }

    public function removeForImport(int $importId, int $businessId): int
    {
        return DB::table('accounting_journal_import_source_links')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->delete();
    }
}
