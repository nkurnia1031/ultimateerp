<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;

class JournalImportIdentityService
{
    /**
     * Compare a Journal Entry with the draft row that produced an existing GL mapping.
     * Older mappings without a retained draft are treated as already posted because
     * there is no trustworthy source content available for a comparison.
     */
    public function compareExistingMapping(object $mapping, array $candidate, int $businessId): string
    {
        $sourceKey = (string) ($mapping->import_source_key ?? $candidate['source_key'] ?? '');
        $existingQuery = DB::table('accounting_journal_import_rows')
            ->where('business_id', $businessId)
            ->where('journal_import_id', $mapping->journal_import_id ?? 0);

        if (! empty($mapping->id)) {
            $existingQuery->where('journal_mapping_id', $mapping->id);
        }

        if ($sourceKey !== '') {
            $existingQuery->where('source_key', $sourceKey);
        }

        $existingRow = $existingQuery->first();
        if (empty($existingRow) && ! empty($mapping->journal_import_id) && $sourceKey !== '') {
            $existingRow = DB::table('accounting_journal_import_rows')
                ->where('business_id', $businessId)
                ->where('journal_import_id', $mapping->journal_import_id)
                ->where('source_key', $sourceKey)
                ->whereNotNull('journal_mapping_id')
                ->first();
        }

        if (empty($existingRow)) {
            return 'unverified';
        }

        $existingEntry = json_decode((string) $existingRow->entry_data, true);
        if (! is_array($existingEntry)) {
            return 'unverified';
        }

        return hash_equals($this->fingerprint($existingEntry), $this->fingerprint($candidate))
            ? 'identical'
            : 'conflict';
    }

    public function completeIdenticalPendingRows(
        int $importId,
        int $businessId,
        string $sourceKey,
        array $postedEntry,
        int $mappingId
    ): void {
        $postedFingerprint = $this->fingerprint($postedEntry);
        $pendingRows = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->where('source_key', $sourceKey)
            ->where('processing_status', 'pending')
            ->get();

        foreach ($pendingRows as $row) {
            $entry = json_decode((string) $row->entry_data, true);
            if (! is_array($entry) || ! hash_equals($postedFingerprint, $this->fingerprint($entry))) {
                continue;
            }

            DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                'processing_status' => 'completed',
                'processing_message' => 'Grup Journal Entry identik sudah diposting dari baris yang sama; tidak dibuat ulang.',
                'processing_error' => null,
                'processing_started_at' => null,
                'journal_mapping_id' => $mappingId,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Mark repeated source identities inside one workbook before any row is queued.
     * Exact repeated groups keep the first copy eligible; conflicting groups all stay
     * pending so no version can win merely because it appeared first in the workbook.
     */
    public function markRepeatedSourceIdentities(array &$entries): void
    {
        $groups = [];
        foreach ($entries as $index => $entry) {
            $sourceKey = trim((string) ($entry['source_key'] ?? ''));
            if ($sourceKey !== '') {
                $groups[$sourceKey][] = $index;
            }
        }

        foreach ($groups as $sourceKey => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $fingerprints = [];
            foreach ($indexes as $index) {
                $fingerprints[$this->fingerprint($entries[$index])] = true;
            }

            if (count($fingerprints) > 1) {
                foreach ($indexes as $index) {
                    $entries[$index]['source_identity_conflict'] = true;
                    $entries[$index]['source_identity_conflict_message'] =
                        'Nomor dan tanggal Journal Entry berulang dengan isi berbeda; tinjau semua salinan sebelum memproses.';
                    $entries[$index]['duplicate_matches'] = array_values(array_unique(array_merge(
                        (array) ($entries[$index]['duplicate_matches'] ?? []),
                        ['Konflik isi untuk kunci sumber '.$sourceKey.'.']
                    )));
                }

                continue;
            }

            foreach (array_slice($indexes, 1) as $index) {
                $entries[$index]['is_duplicate'] = true;
                $entries[$index]['duplicate_matches'] = array_values(array_unique(array_merge(
                    (array) ($entries[$index]['duplicate_matches'] ?? []),
                    ['Grup Journal Entry identik muncul lebih dari sekali dalam workbook.']
                )));
            }
        }
    }

    public function fingerprint(array $entry): string
    {
        $lines = [];
        foreach ((array) ($entry['lines'] ?? []) as $line) {
            $lines[] = [
                'account_no' => $this->normalizedText($line['account_no'] ?? ''),
                'account_name' => $this->normalizedText($line['account_name'] ?? ''),
                'description' => $this->normalizedText($line['description'] ?? ''),
                'debit' => number_format((float) ($line['debit'] ?? 0), 4, '.', ''),
                'credit' => number_format((float) ($line['credit'] ?? 0), 4, '.', ''),
            ];
        }
        usort($lines, function ($left, $right) {
            return strcmp(json_encode($left), json_encode($right));
        });

        $identity = [
            'source_type' => $this->normalizedText($entry['source_type'] ?? ''),
            'source_number' => $this->normalizedText($entry['source_number'] ?? ''),
            'date' => substr(trim((string) ($entry['date'] ?? '')), 0, 10),
            'lines' => $lines,
        ];

        return hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizedText($value): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
    }
}
