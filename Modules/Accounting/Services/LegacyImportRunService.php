<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountingAccTransMapping;

class LegacyImportRunService
{
    private array $sourceIdCache = [];

    public function findRun(int $runId, int $businessId): ?object
    {
        return DB::table('accounting_legacy_import_runs')
            ->where('id', $runId)
            ->where('business_id', $businessId)
            ->first();
    }

    public function findByManifest(int $businessId, int $year, string $manifestHash): ?object
    {
        $query = DB::table('accounting_legacy_import_runs')
            ->where('business_id', $businessId)
            ->where('manifest_sha256', $manifestHash);

        if ($year === 0) {
            $query->whereNull('year_filter');
        } else {
            $query->where('year_filter', $year);
        }

        return $query->orderByDesc('id')->first();
    }

    public function createRun(
        int $businessId,
        int $userId,
        ?int $year,
        string $directory,
        string $manifestHash,
        array $files
    ): int {
        return DB::transaction(function () use ($businessId, $userId, $year, $directory, $manifestHash, $files) {
            $now = now();
            $runId = DB::table('accounting_legacy_import_runs')->insertGetId([
                'business_id' => $businessId,
                'created_by' => $userId,
                'year_filter' => $year,
                'source_directory' => $directory,
                'manifest_sha256' => $manifestHash,
                'status' => 'preparing',
                'last_message' => 'Manifest sumber legacy tersimpan; analisis Journal dimulai.',
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (array_chunk($files, 250) as $chunk) {
                $rows = [];
                foreach ($chunk as $file) {
                    $isDuplicate = ! empty($file['duplicate_of']);
                    $classification = (string) ($file['classification'] ?? 'unclassified');
                    $status = $isDuplicate
                        ? 'duplicate_file'
                        : (in_array($classification, ['coa_control', 'general_ledger_control', 'book_reference', 'pdf_reference'], true)
                            ? 'reference'
                            : ($classification === 'unclassified' ? 'unclassified' : 'inventoried'));

                    $rows[] = [
                        'legacy_import_run_id' => $runId,
                        'business_id' => $businessId,
                        'source_path' => (string) $file['path'],
                        'sha256' => (string) $file['sha256'],
                        'classification' => $classification,
                        'period_start' => $file['period_start'] ?? null,
                        'period_end' => $file['period_end'] ?? null,
                        'status' => $status,
                        'summary' => json_encode([
                            'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                            'duplicate_of' => $file['duplicate_of'] ?? null,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('accounting_legacy_import_sources')->insert($rows);
                }
            }

            return (int) $runId;
        });
    }

    public function createJournalBatch(int $runId, int $businessId, int $userId, string $fileName, array $summary): int
    {
        return DB::transaction(function () use ($runId, $businessId, $userId, $fileName, $summary) {
            $now = now();
            $importId = DB::table('accounting_journal_imports')->insertGetId([
                'business_id' => $businessId,
                'file_name' => $fileName,
                'source_date_range' => $summary['source_date_range'] ?? null,
                'total_entries' => (int) ($summary['total_entries'] ?? 0),
                'imported_entries' => 0,
                'skipped_entries' => (int) ($summary['duplicate_count'] ?? 0),
                'unbalanced_entries' => (int) ($summary['unbalanced_count'] ?? 0),
                'duplicate_entries' => (int) ($summary['duplicate_count'] ?? 0),
                'missing_accounts' => (int) ($summary['missing_account_count'] ?? 0),
                'unsupported_entries' => (int) ($summary['unsupported_count'] ?? 0),
                'processed_entries' => 0,
                'last_message' => 'Batch dibuat oleh coordinator import-legacy.',
                'status' => 'analyzed',
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'import_options' => json_encode(['legacy_import_run_id' => $runId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('accounting_legacy_import_runs')
                ->where('id', $runId)
                ->where('business_id', $businessId)
                ->update([
                    'journal_import_id' => $importId,
                    'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => 'preparing',
                    'last_message' => 'Analisis selesai; batch Journal Import dibuat.',
                    'updated_at' => $now,
                ]);

            DB::table('accounting_legacy_import_sources')
                ->where('legacy_import_run_id', $runId)
                ->where('business_id', $businessId)
                ->where('classification', 'journal_posting_source')
                ->where('status', 'inventoried')
                ->update([
                    'journal_import_id' => $importId,
                    'status' => 'analyzed',
                    'updated_at' => $now,
                ]);

            return (int) $importId;
        });
    }

    public function saveJournalBatch(int $runId, int $businessId, int $importId, array $summary): void
    {
        DB::table('accounting_legacy_import_runs')
            ->where('id', $runId)
            ->where('business_id', $businessId)
            ->update([
                'journal_import_id' => $importId,
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'prepared',
                'last_message' => 'Journal Import batch tersimpan; proses memakai handler yang sudah ada.',
                'updated_at' => now(),
            ]);

        DB::table('accounting_legacy_import_sources')
            ->where('legacy_import_run_id', $runId)
            ->where('business_id', $businessId)
            ->where('classification', 'journal_posting_source')
            ->where('status', 'inventoried')
            ->update([
                'journal_import_id' => $importId,
                'status' => 'analyzed',
                'updated_at' => now(),
            ]);
    }

    public function persistOperationalEvidence(int $runId, int $businessId, array $evidence, array $matchedIdentities = []): int
    {
        $sourceIds = $this->sourceIds($runId, $businessId);
        $isList = $matchedIdentities === [] || array_keys($matchedIdentities) === range(0, count($matchedIdentities) - 1);
        $matchedIdentities = $isList
            ? array_fill_keys($matchedIdentities, ['status' => 'matched'])
            : $matchedIdentities;
        $now = now();
        $crosswalks = [];
        foreach ((array) ($evidence['documents'] ?? []) as $key => $document) {
            if (! is_array($document)) {
                continue;
            }
            foreach ((array) ($document['provenance'] ?? []) as $source) {
                $sourceId = $sourceIds[$source['path'] ?? ''] ?? null;
                if ($sourceId === null || empty($source['source_identity'])) {
                    continue;
                }
                $identity = (string) $source['source_identity'];
                $match = $matchedIdentities[$key] ?? null;
                $isMatched = is_array($match);
                $entryStatus = (string) ($match['status'] ?? 'matched');
                $status = ($document['status'] ?? null) === 'conflict' || $entryStatus === 'conflict'
                    ? 'conflict'
                    : ($isMatched && $entryStatus === 'matched' ? 'matched' : 'review');
                $reason = $document['review_reason'] ?? ($match['reason'] ?? null);
                if ($reason === null && ! $isMatched) {
                    $reason = 'Tidak ada Journal group dengan jenis, nomor, dan tanggal yang sama; baris ditahan untuk review.';
                }
                $crosswalk = [
                    'legacy_import_run_id' => $runId,
                    'legacy_import_source_id' => (int) $sourceId,
                    'business_id' => $businessId,
                    'source_identity' => $identity,
                    'legacy_record_id' => $document['legacy_record_id'] ?? null,
                    'source_type' => (string) ($document['source_type'] ?? 'Unknown'),
                    'source_number' => $document['source_number'] ?? null,
                    'source_date' => $this->validDate($document['date'] ?? null),
                    'journal_import_row_id' => null,
                    'target_record_type' => null,
                    'target_record_id' => null,
                    'status' => $status,
                    'match_method' => $isMatched ? 'document_number_date' : 'operational_only',
                    'reason' => $reason,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $crosswalkKey = $sourceId.'|'.$identity;
                // A grouped operational document can have one provenance record per
                // workbook row. Persist one crosswalk per document/file identity.
                $crosswalks[$crosswalkKey] = $crosswalk;
            }
        }

        foreach ((array) ($evidence['file_summaries'] ?? []) as $fileSummary) {
            $sourceId = $sourceIds[$fileSummary['path'] ?? ''] ?? null;
            if ($sourceId === null) {
                continue;
            }
            $status = ! empty($fileSummary['error']) ? 'read_error' : 'analyzed';
            DB::table('accounting_legacy_import_sources')
                ->where('id', $sourceId)
                ->where('business_id', $businessId)
                ->update([
                    'status' => $status,
                    'records_read' => (int) ($fileSummary['documents'] ?? 0),
                    'records_for_review' => ! empty($fileSummary['error']) ? 1 : 0,
                    'summary' => json_encode($fileSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'error_message' => $fileSummary['error'] ?? null,
                    'updated_at' => $now,
                ]);
        }

        $crosswalks = array_values($crosswalks);
        foreach (array_chunk($crosswalks, 250) as $chunk) {
            DB::table('accounting_legacy_import_crosswalks')->upsert(
                $chunk,
                ['legacy_import_run_id', 'legacy_import_source_id', 'source_identity'],
                [
                    'business_id', 'legacy_record_id', 'source_type', 'source_number', 'source_date', 'journal_import_row_id',
                    'status', 'match_method', 'reason', 'updated_at',
                ]
            );
        }

        return count($crosswalks);
    }

    public function updateRun(int $runId, int $businessId, string $status, string $message, array $summary = [], bool $finished = false): void
    {
        $values = [
            'status' => $status,
            'last_message' => $message,
            'updated_at' => now(),
        ];
        if ($summary !== []) {
            $values['summary'] = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($finished) {
            $values['finished_at'] = now();
        }

        DB::table('accounting_legacy_import_runs')
            ->where('id', $runId)
            ->where('business_id', $businessId)
            ->update($values);
    }

    public function linkOperationalRecord(
        int $runId,
        int $businessId,
        array $document,
        ?string $targetType,
        ?int $targetId,
        string $status,
        string $matchMethod,
        ?string $reason
    ): int {
        $sourceIds = $this->sourceIds($runId, $businessId);
        $rows = [];
        $now = now();
        foreach ((array) ($document['provenance'] ?? []) as $source) {
            $sourceId = $sourceIds[$source['path'] ?? ''] ?? null;
            $identity = (string) ($source['source_identity'] ?? '');
            if ($sourceId === null || preg_match('/^[a-f0-9]{64}$/', $identity) !== 1) {
                continue;
            }
            $rows[$sourceId.'|'.$identity] = [
                'legacy_import_run_id' => $runId,
                'legacy_import_source_id' => (int) $sourceId,
                'business_id' => $businessId,
                'source_identity' => $identity,
                'legacy_record_id' => $source['legacy_record_id'] ?? ($document['legacy_record_id'] ?? null),
                'source_type' => (string) ($document['source_type'] ?? 'Unknown'),
                'source_number' => $document['source_number'] ?? null,
                'source_date' => $this->validDate($document['date'] ?? null),
                'journal_import_row_id' => null,
                'target_record_type' => $targetType,
                'target_record_id' => $targetId,
                'status' => $status,
                'match_method' => $matchMethod,
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $rows = array_values($rows);
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('accounting_legacy_import_crosswalks')->upsert(
                $chunk,
                ['legacy_import_run_id', 'legacy_import_source_id', 'source_identity'],
                [
                    'business_id', 'legacy_record_id', 'source_type', 'source_number', 'source_date', 'journal_import_row_id',
                    'target_record_type', 'target_record_id', 'status', 'match_method', 'reason', 'updated_at',
                ]
            );
        }

        return count($rows);
    }

    public function syncJournalCrosswalks(int $runId, int $businessId, int $importId): int
    {
        $sourceIds = $this->sourceIds($runId, $businessId);
        $created = 0;

        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->chunkById(250, function ($rows) use ($runId, $businessId, $sourceIds, &$created) {
                $now = now();
                $crosswalks = [];
                foreach ($rows as $row) {
                    $entry = json_decode((string) $row->entry_data, true);
                    if (! is_array($entry)) {
                        continue;
                    }

                    foreach ((array) ($entry['legacy_sources'] ?? []) as $source) {
                        if (! is_array($source) || empty($source['path']) || empty($source['fingerprint'])) {
                            continue;
                        }
                        $sourceId = $sourceIds[$source['path']] ?? null;
                        if ($sourceId === null) {
                            continue;
                        }

                        $sourceType = (string) ($entry['source_type'] ?? $row->source_type ?? 'Unknown');
                        $sourceKey = (string) ($entry['source_key'] ?? $row->source_key ?? '');
                        $identity = trim((string) ($source['source_identity'] ?? ''));
                        if ($identity === '') {
                            $identity = hash('sha256', implode('|', [$sourceType, $sourceKey, (string) $source['fingerprint']]));
                        }
                        $processingStatus = (string) ($row->processing_status ?? 'pending');
                        $targetType = $row->source_record_type;
                        $targetId = $row->source_record_id;
                        $method = 'journal_source_key';
                        if ($sourceType === 'Journal Entry' && ! empty($row->journal_mapping_id)) {
                            $targetType = AccountingAccTransMapping::class;
                            $targetId = (int) $row->journal_mapping_id;
                            $method = 'journal_posting';
                        }

                        $crosswalk = [
                            'legacy_import_run_id' => $runId,
                            'legacy_import_source_id' => (int) $sourceId,
                            'business_id' => $businessId,
                            'source_identity' => $identity,
                            'legacy_record_id' => $source['legacy_record_id'] ?? ($entry['legacy_record_id'] ?? null),
                            'source_type' => $sourceType,
                            'source_number' => $entry['source_number'] ?? null,
                            'source_date' => $this->validDate($entry['date'] ?? null),
                            'journal_import_row_id' => (int) $row->id,
                            'target_record_type' => $targetType,
                            'target_record_id' => $targetId,
                            'status' => $this->crosswalkStatus($processingStatus, $targetType, $entry),
                            'match_method' => $method,
                            'reason' => $row->processing_error ?? $row->processing_message ?? null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $crosswalks[$sourceId.'|'.$identity] = $crosswalk;
                    }
                }

                if ($crosswalks !== []) {
                    $crosswalks = array_values($crosswalks);
                    DB::table('accounting_legacy_import_crosswalks')->upsert(
                        $crosswalks,
                        ['legacy_import_run_id', 'legacy_import_source_id', 'source_identity'],
                        [
                            'business_id', 'legacy_record_id', 'source_type', 'source_number', 'source_date', 'journal_import_row_id',
                            'target_record_type', 'target_record_id', 'status', 'match_method', 'reason', 'updated_at',
                        ]
                    );
                    $created += count($crosswalks);
                }
            }, 'id');

        $this->refreshSourceCounts($runId, $businessId);

        return $created;
    }

    public function syncLegacyRecordIds(int $runId, int $businessId, array $evidence): int
    {
        $sourceIds = $this->sourceIds($runId, $businessId);
        $updated = 0;
        foreach ((array) ($evidence['documents'] ?? []) as $document) {
            if (! is_array($document)) {
                continue;
            }
            $legacyRecordId = trim((string) ($document['legacy_record_id'] ?? ''));
            if ($legacyRecordId === '') {
                continue;
            }
            foreach ((array) ($document['provenance'] ?? []) as $source) {
                if (! is_array($source)) {
                    continue;
                }
                $sourceId = $sourceIds[$source['path'] ?? ''] ?? null;
                $identity = trim((string) ($source['source_identity'] ?? ''));
                if ($sourceId === null || preg_match('/^[a-f0-9]{64}$/', $identity) !== 1) {
                    continue;
                }
                $updated += DB::table('accounting_legacy_import_crosswalks')
                    ->where('legacy_import_run_id', $runId)
                    ->where('legacy_import_source_id', $sourceId)
                    ->where('business_id', $businessId)
                    ->where('source_identity', $identity)
                    ->whereNull('legacy_record_id')
                    ->update([
                        'legacy_record_id' => $source['legacy_record_id'] ?? $legacyRecordId,
                        'updated_at' => now(),
                    ]);
            }
        }

        return $updated;
    }

    public function refreshSourceCounts(int $runId, int $businessId): void
    {
        $sources = DB::table('accounting_legacy_import_sources')
            ->where('legacy_import_run_id', $runId)
            ->where('business_id', $businessId)
            ->whereIn('classification', [
                'journal_posting_source',
                'sales_operational_source',
                'purchase_operational_source',
                'expense_operational_source',
                'expense_detail_source',
                'operational_detail_json',
            ])
            ->whereIn('status', ['inventoried', 'analyzed', 'review', 'linked'])
            ->whereNull('error_message')
            ->get(['id']);
        foreach ($sources as $source) {
            $counts = DB::table('accounting_legacy_import_crosswalks')
                ->where('legacy_import_run_id', $runId)
                ->where('legacy_import_source_id', $source->id)
                ->selectRaw("SUM(CASE WHEN status IN ('posted', 'linked', 'matched') THEN 1 ELSE 0 END) as linked_count")
                ->selectRaw("SUM(CASE WHEN status IN ('review', 'conflict', 'failed') THEN 1 ELSE 0 END) as review_count")
                ->first();
            $linked = (int) ($counts->linked_count ?? 0);
            $review = (int) ($counts->review_count ?? 0);
            DB::table('accounting_legacy_import_sources')
                ->where('id', $source->id)
                ->update([
                    'records_linked' => $linked,
                    'records_for_review' => $review,
                    'status' => $review > 0 ? 'review' : ($linked > 0 ? 'linked' : 'analyzed'),
                    'updated_at' => now(),
                ]);
        }
    }

    private function crosswalkStatus(string $processingStatus, ?string $targetType, array $entry): string
    {
        if (! empty($entry['source_identity_conflict'])) {
            return 'conflict';
        }
        if ($processingStatus === 'completed') {
            return $targetType === null ? 'posted' : 'linked';
        }
        if ($processingStatus === 'failed') {
            return 'failed';
        }

        return 'review';
    }

    private function validDate($value): ?string
    {
        $value = substr(trim((string) $value), 0, 10);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $value;
    }

    private function sourceIds(int $runId, int $businessId): array
    {
        $cacheKey = $runId.'|'.$businessId;
        if (! array_key_exists($cacheKey, $this->sourceIdCache)) {
            $this->sourceIdCache[$cacheKey] = DB::table('accounting_legacy_import_sources')
                ->where('legacy_import_run_id', $runId)
                ->where('business_id', $businessId)
                ->pluck('id', 'source_path')
                ->all();
        }

        return $this->sourceIdCache[$cacheKey];
    }
}
