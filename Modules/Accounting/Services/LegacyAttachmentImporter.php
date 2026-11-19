<?php

namespace Modules\Accounting\Services;

use App\Media;
use App\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LegacyAttachmentImporter
{
    private const OPERATIONAL_TYPES = [
        'Expense' => ['expense', 'expense_refund'],
        'Purchase Invoice' => ['purchase'],
        'Sales Invoice' => ['sell'],
        'Purchase Order' => ['purchase_order'],
    ];

    private const PAYMENT_SOURCE_TYPES = [
        'Purchase Payment' => 'purchase',
        'Receive Payment' => 'sell',
    ];

    private const ACCOUNT_TRANSACTION_SOURCE_TYPES = [
        'Bank Deposit' => ['credit', 'deposit'],
        'Bank Transfer' => ['debit', 'fund_transfer'],
        'Bank Withdrawal' => ['debit', 'withdrawal'],
    ];

    public function import(
        int $runId,
        int $businessId,
        string $sourceRoot,
        ?string $documentsDirectory = null,
        ?bool $allowRemoteDownloads = null
    ): array {
        $summary = $this->emptySummary();
        $runExists = DB::table('accounting_legacy_import_runs')
            ->where('id', $runId)
            ->where('business_id', $businessId)
            ->exists();
        if (! $runExists) {
            throw new RuntimeException('Legacy import run tidak ditemukan untuk business yang diminta.');
        }

        $source = DB::table('accounting_legacy_import_sources')
            ->where('legacy_import_run_id', $runId)
            ->where('business_id', $businessId)
            ->where('classification', 'attachment_metadata')
            ->orderBy('id')
            ->first();
        if (! $source) {
            return $summary;
        }

        $root = realpath($sourceRoot);
        $sourcePath = $root === false ? false : $this->safeSourcePath($root, (string) $source->source_path);
        if ($sourcePath === false || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            return $this->finishSource($source, $runId, $businessId, $summary, 'File metadata lampiran tidak ada atau tidak dapat dibaca.');
        }

        $records = json_decode((string) file_get_contents($sourcePath), true);
        if (! is_array($records)) {
            return $this->finishSource($source, $runId, $businessId, $summary, 'JSON metadata lampiran tidak valid.');
        }

        $records = array_values(array_filter($records, 'is_array'));
        $summary['total'] = count($records);
        $collisionInfo = $this->collisionInfo($records);
        $summary['collision_groups'] = count($collisionInfo['groups']);
        $summary['collision_attachments'] = $collisionInfo['attachments'];
        $documentsDirectory = $documentsDirectory ?: public_path('uploads/documents');
        $allowRemoteDownloads = $allowRemoteDownloads ?? app()->environment('production');
        $crosswalkIndex = $this->crosswalkIndex($runId, $businessId);
        $transactionIndex = $this->transactionIndex($businessId, $crosswalkIndex);
        $attachmentReferenceIndex = $this->attachmentReferenceIndex($businessId, $records);
        $mediaIndex = $this->mediaIndex($businessId, $records);
        $timestampedDocumentFiles = $this->timestampedDocumentFileIndex($documentsDirectory);

        foreach ($records as $record) {
            $outcome = $this->importRecord(
                $record,
                $source,
                $runId,
                $businessId,
                $documentsDirectory,
                $allowRemoteDownloads,
                $collisionInfo['counts'],
                $timestampedDocumentFiles,
                $crosswalkIndex,
                $transactionIndex,
                $attachmentReferenceIndex,
                $mediaIndex
            );
            if (isset($summary[$outcome['status']])) {
                $summary[$outcome['status']]++;
            }
        }

        $summary['mapping_report_path'] = $this->writeMappingReport($runId);

        return $this->finishSource($source, $runId, $businessId, $summary);
    }

    private function importRecord(
        array $record,
        object $source,
        int $runId,
        int $businessId,
        string $documentsDirectory,
        bool $allowRemoteDownloads,
        array $collisionCounts,
        array $timestampedDocumentFiles,
        array $crosswalkIndex,
        array $transactionIndex,
        array $attachmentReferenceIndex,
        array &$mediaIndex
    ): array {
        $attachmentId = $this->scalarId($record['id'] ?? null);
        if ($attachmentId === '') {
            $attachmentId = 'invalid-'.substr(hash('sha256', json_encode($record)), 0, 40);
        }
        $entry = is_array($record['entry'] ?? null) ? $record['entry'] : [];
        $legacyTransactionId = $this->scalarId($record['transaction_id'] ?? ($entry['id'] ?? null));
        [$sourceType, $sourceNumber] = $this->sourceIdentity($record);
        $file = is_array($record['file'] ?? null) ? $record['file'] : [];
        $url = trim((string) ($file['url'] ?? ''));
        $fileInfo = $this->validateSourceUrl($url, $attachmentId);
        $originalName = $fileInfo['original_name'] ?? null;
        $destinationName = $originalName === null ? null : $this->destinationName(
            $originalName,
            $attachmentId,
            (int) ($collisionCounts[mb_strtolower($originalName, 'UTF-8')] ?? 0) > 1
        );

        $base = [
            'legacy_import_run_id' => $runId,
            'legacy_import_source_id' => (int) $source->id,
            'business_id' => $businessId,
            'legacy_source_attachment_id' => $attachmentId,
            'legacy_transaction_id' => $legacyTransactionId !== '' ? $legacyTransactionId : null,
            'source_type' => $sourceType,
            'source_number' => $sourceNumber,
            'source_url' => $url !== '' ? $url : null,
            'original_file_name' => $originalName,
            'destination_file_name' => $destinationName,
            'target_transaction_id' => null,
            'target_record_type' => null,
            'target_record_id' => null,
            'media_id' => null,
            'media_model_type' => null,
            'media_model_id' => null,
            'match_method' => null,
            'file_action' => null,
            'status' => 'review',
            'reason' => null,
            'updated_at' => now(),
        ];

        if ($fileInfo['valid'] !== true) {
            return $this->storeOutcome($base, 'rejected', null, null, 'rejected', (string) $fileInfo['reason']);
        }
        $match = $this->resolveTarget(
            $legacyTransactionId,
            $sourceType,
            $sourceNumber,
            $originalName,
            $crosswalkIndex,
            $transactionIndex,
            $attachmentReferenceIndex
        );
        if ($match['target'] === null) {
            return $this->storeOutcome(
                $base,
                'review',
                null,
                $match['method'],
                null,
                $match['reason']
            );
        }

        $target = $match['target'];
        $base['target_transaction_id'] = $target['target_transaction_id'];
        $base['target_record_type'] = $target['target_record_type'];
        $base['target_record_id'] = $target['target_record_id'];
        $base['media_model_type'] = $target['media_model_type'];
        $base['media_model_id'] = $target['media_model_id'];
        $base['match_method'] = $match['method'];

        $existingMedia = $mediaIndex[$attachmentId] ?? null;
        if ($existingMedia && ((string) $existingMedia->model_type !== $target['media_model_type']
            || (int) $existingMedia->model_id !== (int) $target['media_model_id'])) {
            return $this->storeOutcome($base, 'review', null, $match['method'], null, 'ID lampiran sudah tertaut ke transaksi lain; tautan tidak diubah.');
        }

        if ($existingMedia) {
            $existingName = basename((string) $existingMedia->file_name);
            if ($existingMedia->storage_location !== 'documents' || ! $this->isSafeFileName($existingName)) {
                return $this->storeOutcome($base, 'review', (int) $existingMedia->id, $match['method'], null, 'Media lampiran yang sudah ada memakai lokasi atau nama file yang tidak sesuai.');
            }
            $destinationName = $existingName;
        } else {
            $referencedName = $this->existingDocumentReferenceName($target, $documentsDirectory);
            if ($referencedName !== null) {
                $destinationName = $referencedName;
            } elseif ((int) ($collisionCounts[mb_strtolower((string) $originalName, 'UTF-8')] ?? 0) <= 1) {
                $matchingNames = $timestampedDocumentFiles[$this->normalizeFileName((string) $originalName)] ?? [];
                if (count($matchingNames) === 1) {
                    $destinationName = $matchingNames[0];
                }
            }
        }
        if (! $this->isSafeFileName((string) $destinationName)) {
            return $this->storeOutcome($base, 'rejected', null, $match['method'], null, 'Nama tujuan lampiran tidak aman.');
        }

        $fileAction = $this->ensureDestinationFile(
            (string) $url,
            $fileInfo,
            (string) $destinationName,
            $documentsDirectory,
            $allowRemoteDownloads
        );
        if ($fileAction['status'] !== 'ready') {
            if ($existingMedia) {
                // Do not leave a Media link to a document that is absent in this environment.
                $existingMedia->delete();
                unset($mediaIndex[$attachmentId]);
            }
            return $this->storeOutcome(
                $base,
                $fileAction['status'],
                null,
                $match['method'],
                $fileAction['action'],
                $fileAction['reason']
            );
        }

        $media = $existingMedia ?: new Media();
        $media->business_id = $businessId;
        $media->file_name = $destinationName;
        $media->original_name = $originalName;
        $media->storage_location = 'documents';
        $media->legacy_source_attachment_id = $attachmentId;
        $media->model_media_type = 'legacy_document';
        $media->model_type = $target['media_model_type'];
        $media->model_id = (int) $target['media_model_id'];
        $media->uploaded_by = null;

        try {
            $media->save();
            $mediaIndex[$attachmentId] = $media;
        } catch (\Throwable $exception) {
            $concurrentMedia = Media::query()
                ->where('business_id', $businessId)
                ->where('legacy_source_attachment_id', $attachmentId)
                ->first();
            if (! $concurrentMedia || (int) $concurrentMedia->model_id !== (int) $target['media_model_id']
                || (string) $concurrentMedia->model_type !== $target['media_model_type']) {
                return $this->storeOutcome($base, 'failed', null, $match['method'], $fileAction['action'], 'Media tidak dapat disimpan: '.$exception->getMessage());
            }
            $media = $concurrentMedia;
            $mediaIndex[$attachmentId] = $media;
        }

        return $this->storeOutcome($base, 'linked', (int) $media->id, $match['method'], $fileAction['action'], null);
    }

    private function resolveTarget(
        string $legacyTransactionId,
        ?string $sourceType,
        ?string $sourceNumber,
        ?string $originalName,
        array $crosswalkIndex,
        array $transactionIndex,
        array $attachmentReferenceIndex
    ): array {
        $fileKey = $originalName !== null ? $this->normalizeFileName($originalName) : null;
        $fileCandidates = $fileKey !== null
            ? $this->uniqueAttachmentReferences($attachmentReferenceIndex['file_name']['*'][$fileKey] ?? [])
            : [];

        if ($legacyTransactionId !== '') {
            $targetIds = [];
            if ($sourceType !== null) {
                $targetIds = array_keys($crosswalkIndex['legacy'][$sourceType][$legacyTransactionId] ?? []);
            } else {
                foreach ((array) ($crosswalkIndex['legacy'] ?? []) as $bySourceType) {
                    foreach (array_keys($bySourceType[$legacyTransactionId] ?? []) as $targetId) {
                        $targetIds[(int) $targetId] = true;
                    }
                }
                $targetIds = array_keys($targetIds);
            }

            $crosswalkCandidates = [];
            foreach ($targetIds as $targetId) {
                $transaction = $transactionIndex[(int) $targetId] ?? null;
                if (! $transaction || ($sourceType === null
                    ? ! $this->isSupportedTransactionType((string) $transaction->type)
                    : $this->validTransactionFromIndex((int) $targetId, $sourceType, $transactionIndex) === null)) {
                    continue;
                }
                $crosswalkCandidates[] = $this->transactionReference($transaction);
            }
            $crosswalkCandidates = $this->uniqueAttachmentReferences($crosswalkCandidates);
            if (count($crosswalkCandidates) > 1) {
                $matchingCandidates = array_values(array_filter(
                    $crosswalkCandidates,
                    fn (array $candidate) => $this->matchesFileCandidate($candidate, $fileCandidates)
                ));
                if (count($matchingCandidates) === 1) {
                    return ['target' => $matchingCandidates[0], 'method' => 'legacy_record_id_file_name', 'reason' => null];
                }

                return ['target' => null, 'method' => 'legacy_record_id', 'reason' => 'ID transaksi legacy memiliki lebih dari satu target crosswalk.'];
            }
            if (count($crosswalkCandidates) === 1) {
                $target = $this->refineTransactionReferenceWithFile($crosswalkCandidates[0], $fileCandidates);

                return ['target' => $target, 'method' => 'legacy_record_id', 'reason' => null];
            }
        }

        if ($sourceType !== null && $sourceNumber !== null && $sourceNumber !== '') {
            $numberKey = $this->normalizeNumber($sourceNumber);
            $targetIds = array_keys($crosswalkIndex['number'][$sourceType][$numberKey] ?? []);
            if (count($targetIds) > 1) {
                $crosswalkCandidates = [];
                foreach ($targetIds as $targetId) {
                    $transaction = $this->validTransactionFromIndex((int) $targetId, $sourceType, $transactionIndex);
                    if ($transaction) {
                        $crosswalkCandidates[] = $this->transactionReference($transaction);
                    }
                }
                $crosswalkCandidates = $this->uniqueAttachmentReferences($crosswalkCandidates);
                if (count($crosswalkCandidates) === 1) {
                    $target = $this->refineTransactionReferenceWithFile($crosswalkCandidates[0], $fileCandidates);

                    return ['target' => $target, 'method' => 'source_number', 'reason' => null];
                }
                $matchingCandidates = array_values(array_filter(
                    $crosswalkCandidates,
                    fn (array $candidate) => $this->matchesFileCandidate($candidate, $fileCandidates)
                ));
                if (count($matchingCandidates) === 1) {
                    return ['target' => $matchingCandidates[0], 'method' => 'source_number_file_name', 'reason' => null];
                }

                return ['target' => null, 'method' => 'source_number', 'reason' => 'Nomor sumber memiliki beberapa transaksi tujuan; tautan lampiran perlu ditinjau.'];
            }
            if (count($targetIds) === 1) {
                $transaction = $this->validTransactionFromIndex((int) $targetIds[0], $sourceType, $transactionIndex);
                if ($transaction) {
                    $target = $this->transactionReference($transaction);
                    $target = $this->refineTransactionReferenceWithFile($target, $fileCandidates);

                    return ['target' => $target, 'method' => 'source_number', 'reason' => null];
                }
            }

            $numberCandidates = $this->uniqueAttachmentReferences(
                $attachmentReferenceIndex['number'][$sourceType][$numberKey] ?? []
            );
            if (count($numberCandidates) > 1) {
                $matchingCandidates = array_values(array_filter(
                    $numberCandidates,
                    fn (array $candidate) => $this->matchesFileCandidate($candidate, $fileCandidates)
                ));
                if (count($matchingCandidates) === 1) {
                    return ['target' => $matchingCandidates[0], 'method' => 'source_number_file_name', 'reason' => null];
                }

                return ['target' => null, 'method' => 'source_number', 'reason' => 'Nomor sumber memiliki beberapa record operasional; noref dan nama file belum menunjuk ke satu record yang sama.'];
            }
            if (count($numberCandidates) === 1) {
                $target = $this->refineTransactionReferenceWithFile($numberCandidates[0], $fileCandidates);

                return ['target' => $target, 'method' => 'source_number', 'reason' => null];
            }
        }

        if (count($fileCandidates) === 1) {
            return ['target' => $fileCandidates[0], 'method' => 'file_name', 'reason' => null];
        }
        if (count($fileCandidates) > 1) {
            return ['target' => null, 'method' => 'file_name', 'reason' => 'Nama file yang sama ditemukan pada beberapa record operasional; tautan lampiran perlu ditinjau.'];
        }

        return ['target' => null, 'method' => null, 'reason' => 'Record operasional belum ditemukan lewat crosswalk, nomor referensi, atau nama file.'];
    }

    private function transactionReference(Transaction $transaction): array
    {
        return [
            'media_model_type' => Transaction::class,
            'media_model_id' => (int) $transaction->id,
            'target_transaction_id' => (int) $transaction->id,
            'target_record_type' => Transaction::class,
            'target_record_id' => (int) $transaction->id,
            'parent_ref_no' => (string) ($transaction->ref_no ?? ''),
            'parent_transaction_type' => (string) $transaction->type,
            'document_references' => [],
        ];
    }

    private function existingDocumentReferenceName(array $target, string $documentsDirectory): ?string
    {
        $names = array_values(array_unique(array_filter(array_map(
            fn (array $reference) => basename(str_replace('\\', '/', (string) ($reference['value'] ?? ''))),
            (array) ($target['document_references'] ?? [])
        ))));
        if (count($names) !== 1 || ! $this->isSafeFileName($names[0]) || is_link($documentsDirectory)) {
            return null;
        }

        $directory = realpath($documentsDirectory);
        if ($directory === false) {
            return null;
        }
        $path = $directory.DIRECTORY_SEPARATOR.$names[0];

        return is_file($path) && ! is_link($path) ? $names[0] : null;
    }

    private function timestampedDocumentFileIndex(string $documentsDirectory): array
    {
        if (! is_dir($documentsDirectory) || is_link($documentsDirectory)) {
            return [];
        }

        $index = [];
        foreach (scandir($documentsDirectory) ?: [] as $fileName) {
            if (! $this->isSafeFileName((string) $fileName)
                || preg_match('/^\d{9,13}_(?:[A-Za-z0-9]{20}_)?(.+)$/u', (string) $fileName, $matches) !== 1
                || ! is_file($documentsDirectory.DIRECTORY_SEPARATOR.$fileName)
                || is_link($documentsDirectory.DIRECTORY_SEPARATOR.$fileName)) {
                continue;
            }
            $index[$this->normalizeFileName($matches[1])][] = (string) $fileName;
        }

        return $index;
    }

    private function refineTransactionReferenceWithFile(array $target, array $fileCandidates): array
    {
        $related = array_values(array_filter($fileCandidates, function (array $candidate) use ($target): bool {
            return (string) $candidate['media_model_type'] === (string) $target['media_model_type']
                && (int) $candidate['media_model_id'] === (int) $target['media_model_id'];
        }));

        if (count($related) === 1) {
            return $related[0];
        }
        if (count($related) > 1) {
            $documentReferences = [];
            foreach ($related as $candidate) {
                $documentReferences = array_merge($documentReferences, (array) ($candidate['document_references'] ?? []));
            }
            $documentNames = array_values(array_unique(array_filter(array_map(
                fn (array $reference) => basename(str_replace('\\', '/', (string) ($reference['value'] ?? ''))),
                $documentReferences
            ))));
            if (count($documentNames) === 1) {
                $target['document_references'] = $documentReferences;
            }
        }

        return $target;
    }

    private function matchesFileCandidate(array $numberCandidate, array $fileCandidates): bool
    {
        foreach ($fileCandidates as $fileCandidate) {
            if ((string) $numberCandidate['target_record_type'] === (string) $fileCandidate['target_record_type']
                && (int) $numberCandidate['target_record_id'] === (int) $fileCandidate['target_record_id']) {
                return true;
            }

            if (($fileCandidate['target_record_type'] ?? null) === 'expense_lines'
                && in_array((string) ($numberCandidate['parent_transaction_type'] ?? ''), ['expense', 'expense_refund'], true)
                && (int) ($fileCandidate['target_transaction_id'] ?? 0) === (int) ($numberCandidate['target_transaction_id'] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    private function uniqueAttachmentReferences(array $references): array
    {
        $unique = [];
        foreach ($references as $reference) {
            $identity = implode('|', [
                (string) ($reference['target_record_type'] ?? ''),
                (string) ($reference['target_record_id'] ?? ''),
                (string) ($reference['media_model_type'] ?? ''),
                (string) ($reference['media_model_id'] ?? ''),
            ]);
            if (! isset($unique[$identity])) {
                $unique[$identity] = $reference;
                continue;
            }

            $current = $unique[$identity];
            $current['document_references'] = array_merge(
                (array) ($current['document_references'] ?? []),
                (array) ($reference['document_references'] ?? [])
            );
            $unique[$identity] = $current;
        }

        return array_values($unique);
    }

    private function isSupportedTransactionType(string $transactionType): bool
    {
        foreach (self::OPERATIONAL_TYPES as $types) {
            if (in_array($transactionType, $types, true)) {
                return true;
            }
        }

        return false;
    }

    private function crosswalkIndex(int $runId, int $businessId): array
    {
        $index = ['legacy' => [], 'number' => []];
        $crosswalks = DB::table('accounting_legacy_import_crosswalks')
            ->where('legacy_import_run_id', $runId)
            ->where('business_id', $businessId)
            ->where('target_record_type', Transaction::class)
            ->whereNotNull('target_record_id')
            ->whereIn('status', ['matched', 'linked', 'posted', 'review'])
            ->get(['source_type', 'legacy_record_id', 'source_number', 'target_record_id']);

        foreach ($crosswalks as $crosswalk) {
            $type = trim((string) $crosswalk->source_type);
            if (! isset(self::OPERATIONAL_TYPES[$type])) {
                continue;
            }
            $targetId = (int) $crosswalk->target_record_id;
            $legacyId = trim((string) $crosswalk->legacy_record_id);
            $sourceNumber = trim((string) $crosswalk->source_number);
            if ($legacyId !== '') {
                $index['legacy'][$type][$legacyId][$targetId] = true;
            }
            if ($sourceNumber !== '') {
                $index['number'][$type][$this->normalizeNumber($sourceNumber)][$targetId] = true;
            }
        }

        return $index;
    }

    private function transactionIndex(int $businessId, array $crosswalkIndex): array
    {
        $targetIds = [];
        foreach (['legacy', 'number'] as $mappingType) {
            foreach ((array) ($crosswalkIndex[$mappingType] ?? []) as $bySourceType) {
                foreach ($bySourceType as $byKey) {
                    foreach (array_keys($byKey) as $targetId) {
                        $targetIds[(int) $targetId] = true;
                    }
                }
            }
        }

        $transactions = [];
        foreach (array_chunk(array_keys($targetIds), 500) as $chunk) {
            $rows = Transaction::query()
                ->where('business_id', $businessId)
                ->whereIn('type', array_merge(...array_values(self::OPERATIONAL_TYPES)))
                ->when(Schema::hasColumn('transactions', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->whereIn('id', $chunk)
                ->get();
            foreach ($rows as $transaction) {
                $transactions[(int) $transaction->id] = $transaction;
            }
        }

        return $transactions;
    }

    private function attachmentReferenceIndex(int $businessId, array $records): array
    {
        $index = ['number' => [], 'file_name' => ['*' => []]];
        $numbersByType = [];
        $fileNames = [];

        foreach ($records as $record) {
            [$sourceType, $sourceNumber] = $this->sourceIdentity($record);
            if ($sourceType !== null && $sourceNumber !== null) {
                $numbersByType[$sourceType][$this->normalizeNumber($sourceNumber)] = $sourceNumber;
            }

            $file = is_array($record['file'] ?? null) ? $record['file'] : [];
            $url = trim((string) ($file['url'] ?? ''));
            $fileInfo = $this->validateSourceUrl($url, $this->scalarId($record['id'] ?? null));
            $fileName = $fileInfo['original_name'] ?? null;
            if (is_string($fileName) && $fileName !== '') {
                $fileNames[$this->normalizeFileName($fileName)] = true;
            }
        }

        if (Schema::hasTable('transactions')
            && Schema::hasColumn('transactions', 'business_id')
            && Schema::hasColumn('transactions', 'type')
            && Schema::hasColumn('transactions', 'ref_no')) {
            foreach (self::OPERATIONAL_TYPES as $sourceType => $transactionTypes) {
                $numbers = array_values($numbersByType[$sourceType] ?? []);
                foreach (array_chunk($numbers, 400) as $chunk) {
                    $rows = DB::table('transactions')
                        ->where('business_id', $businessId)
                        ->whereIn('type', $transactionTypes)
                        ->whereIn('ref_no', $chunk)
                        ->whereNotNull('ref_no')
                        ->when(Schema::hasColumn('transactions', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                        ->get(['id', 'type', 'ref_no']);

                    foreach ($rows as $row) {
                        $this->addAttachmentReference($index, 'number', $sourceType, $this->normalizeNumber((string) $row->ref_no), [
                            'media_model_type' => Transaction::class,
                            'media_model_id' => (int) $row->id,
                            'target_transaction_id' => (int) $row->id,
                            'target_record_type' => Transaction::class,
                            'target_record_id' => (int) $row->id,
                            'parent_ref_no' => (string) $row->ref_no,
                            'parent_transaction_type' => (string) $row->type,
                            'document_references' => [],
                        ]);
                    }
                }
            }
        }

        if (Schema::hasTable('transaction_payments')
            && Schema::hasColumn('transaction_payments', 'transaction_id')
            && Schema::hasColumn('transaction_payments', 'payment_ref_no')
            && Schema::hasTable('transactions')
            && Schema::hasColumn('transactions', 'business_id')
            && Schema::hasColumn('transactions', 'type')) {
            foreach (self::PAYMENT_SOURCE_TYPES as $sourceType => $transactionType) {
                $numbers = array_values($numbersByType[$sourceType] ?? []);
                foreach (array_chunk($numbers, 400) as $chunk) {
                    $select = [
                        'payment.id as payment_id',
                        'payment.payment_ref_no',
                        'payment.transaction_id',
                        'transaction.type as parent_transaction_type',
                    ];
                    if (Schema::hasColumn('transaction_payments', 'document')) {
                        $select[] = 'payment.document';
                    }
                    if (Schema::hasColumn('transactions', 'ref_no')) {
                        $select[] = 'transaction.ref_no as parent_ref_no';
                    }

                    $rows = DB::table('transaction_payments as payment')
                        ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
                        ->where('transaction.business_id', $businessId)
                        ->where('transaction.type', $transactionType)
                        ->whereIn('payment.payment_ref_no', $chunk)
                        ->whereNotNull('payment.payment_ref_no')
                        ->when(Schema::hasColumn('transactions', 'deleted_at'), fn ($query) => $query->whereNull('transaction.deleted_at'))
                        ->get($select);

                    foreach ($rows as $row) {
                        $paymentId = (int) $row->payment_id;
                        $transactionId = (int) $row->transaction_id;
                        $documentReferences = [];
                        if (isset($row->document) && trim((string) $row->document) !== '') {
                            $documentReferences = $this->documentReferencesForFile($fileNames, 'transaction_payments', $paymentId, $transactionId, (string) $row->document);
                        }

                        $this->addAttachmentReference($index, 'number', $sourceType, $this->normalizeNumber((string) $row->payment_ref_no), [
                            'media_model_type' => \App\Transaction::class,
                            'media_model_id' => $transactionId,
                            'target_transaction_id' => $transactionId,
                            'target_record_type' => \App\TransactionPayment::class,
                            'target_record_id' => $paymentId,
                            'parent_ref_no' => (string) ($row->parent_ref_no ?? ''),
                            'parent_transaction_type' => (string) $row->parent_transaction_type,
                            'document_references' => $documentReferences,
                        ]);
                    }
                }
            }
        }

        if (Schema::hasTable('account_transactions')
            && Schema::hasTable('accounts')
            && Schema::hasColumn('account_transactions', 'account_id')
            && Schema::hasColumn('account_transactions', 'type')
            && Schema::hasColumn('account_transactions', 'sub_type')
            && Schema::hasColumn('account_transactions', 'reff_no')
            && Schema::hasColumn('accounts', 'business_id')) {
            foreach (self::ACCOUNT_TRANSACTION_SOURCE_TYPES as $sourceType => [$accountType, $subType]) {
                $numbers = array_values($numbersByType[$sourceType] ?? []);
                foreach (array_chunk($numbers, 400) as $chunk) {
                    $query = DB::table('account_transactions as account_transaction')
                        ->join('accounts as account', 'account.id', '=', 'account_transaction.account_id')
                        ->where('account.business_id', $businessId)
                        ->where('account_transaction.type', $accountType)
                        ->where('account_transaction.sub_type', $subType)
                        ->whereIn('account_transaction.reff_no', $chunk)
                        ->whereNotNull('account_transaction.reff_no')
                        ->when(Schema::hasColumn('account_transactions', 'deleted_at'), fn ($builder) => $builder->whereNull('account_transaction.deleted_at'))
                        ->when(Schema::hasColumn('accounts', 'deleted_at'), fn ($builder) => $builder->whereNull('account.deleted_at'));
                    $select = ['account_transaction.id', 'account_transaction.reff_no'];
                    if (Schema::hasColumn('account_transactions', 'transaction_id')) {
                        $select[] = 'account_transaction.transaction_id';
                    }

                    foreach ($query->get($select) as $row) {
                        $this->addAttachmentReference($index, 'number', $sourceType, $this->normalizeNumber((string) $row->reff_no), [
                            'media_model_type' => \App\AccountTransaction::class,
                            'media_model_id' => (int) $row->id,
                            'target_transaction_id' => isset($row->transaction_id) ? (int) $row->transaction_id : null,
                            'target_record_type' => \App\AccountTransaction::class,
                            'target_record_id' => (int) $row->id,
                            'parent_ref_no' => null,
                            'parent_transaction_type' => null,
                            'document_references' => [],
                        ]);
                    }
                }
            }
        }

        if ($fileNames === []) {
            return $index;
        }

        $transactionTypes = array_values(array_unique(array_merge(...array_values(self::OPERATIONAL_TYPES))));
        if (Schema::hasTable('transactions')
            && Schema::hasColumn('transactions', 'business_id')
            && Schema::hasColumn('transactions', 'type')
            && Schema::hasColumn('transactions', 'document')) {
            $transactionSelect = ['id', 'type', 'document'];
            if (Schema::hasColumn('transactions', 'ref_no')) {
                $transactionSelect[] = 'ref_no';
            }
            DB::table('transactions')
                ->where('business_id', $businessId)
                ->whereIn('type', $transactionTypes)
                ->whereNotNull('document')
                ->where('document', '<>', '')
                ->select($transactionSelect)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use (&$index, $fileNames): void {
                    foreach ($rows as $row) {
                        $reference = [
                            'media_model_type' => Transaction::class,
                            'media_model_id' => (int) $row->id,
                            'target_transaction_id' => (int) $row->id,
                            'target_record_type' => Transaction::class,
                            'target_record_id' => (int) $row->id,
                            'parent_ref_no' => (string) ($row->ref_no ?? ''),
                            'parent_transaction_type' => (string) $row->type,
                            'document_references' => $this->documentReferencesForFile($fileNames, 'transactions', (int) $row->id, (int) $row->id, (string) $row->document),
                        ];
                        $this->addStoredFileReferences($index, $fileNames, (string) $row->document, $reference);
                    }
                }, 'id');
        }

        if (Schema::hasTable('transactions')
            && Schema::hasColumn('transactions', 'business_id')
            && Schema::hasColumn('transactions', 'type')
            && Schema::hasColumn('transactions', 'ref_no')
            && Schema::hasTable('expense_lines')
            && Schema::hasColumn('expense_lines', 'transaction_id')
            && Schema::hasColumn('expense_lines', 'document')
            && Schema::hasColumn('transactions', 'ref_no')) {
            DB::table('expense_lines as expense_line')
                ->join('transactions as transaction', 'transaction.id', '=', 'expense_line.transaction_id')
                ->where('transaction.business_id', $businessId)
                ->whereIn('transaction.type', ['expense', 'expense_refund'])
                ->whereNotNull('expense_line.document')
                ->where('expense_line.document', '<>', '')
                ->select([
                    'expense_line.id as row_id',
                    'expense_line.transaction_id',
                    'expense_line.document',
                    'transaction.ref_no as parent_ref_no',
                ])
                ->orderBy('expense_line.id')
                ->chunkById(500, function ($rows) use (&$index, $fileNames): void {
                    foreach ($rows as $row) {
                        $transactionId = (int) $row->transaction_id;
                        $reference = [
                            'media_model_type' => Transaction::class,
                            'media_model_id' => $transactionId,
                            'target_transaction_id' => $transactionId,
                            'target_record_type' => 'expense_lines',
                            'target_record_id' => (int) $row->row_id,
                            'parent_ref_no' => (string) $row->parent_ref_no,
                            'parent_transaction_type' => 'expense',
                            'document_references' => $this->documentReferencesForFile($fileNames, 'expense_lines', (int) $row->row_id, $transactionId, (string) $row->document),
                        ];
                        $this->addStoredFileReferences($index, $fileNames, (string) $row->document, $reference);
                    }
                }, 'expense_line.id', 'row_id');
        }

        if (Schema::hasTable('transaction_payments')
            && Schema::hasColumn('transaction_payments', 'transaction_id')
            && Schema::hasColumn('transaction_payments', 'document')
            && Schema::hasTable('transactions')
            && Schema::hasColumn('transactions', 'business_id')
            && Schema::hasColumn('transactions', 'type')) {
            $select = [
                'payment.id as row_id',
                'payment.transaction_id',
                'payment.document',
                'transaction.type as parent_transaction_type',
            ];
            if (Schema::hasColumn('transactions', 'ref_no')) {
                $select[] = 'transaction.ref_no as parent_ref_no';
            }
            DB::table('transaction_payments as payment')
                ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
                ->where('transaction.business_id', $businessId)
                ->whereIn('transaction.type', $transactionTypes)
                ->whereNotNull('payment.document')
                ->where('payment.document', '<>', '')
                ->select($select)
                ->orderBy('payment.id')
                ->chunkById(500, function ($rows) use (&$index, $fileNames): void {
                    foreach ($rows as $row) {
                        $transactionId = (int) $row->transaction_id;
                        $reference = [
                            'media_model_type' => Transaction::class,
                            'media_model_id' => $transactionId,
                            'target_transaction_id' => $transactionId,
                            'target_record_type' => \App\TransactionPayment::class,
                            'target_record_id' => (int) $row->row_id,
                            'parent_ref_no' => (string) ($row->parent_ref_no ?? ''),
                            'parent_transaction_type' => (string) $row->parent_transaction_type,
                            'document_references' => $this->documentReferencesForFile($fileNames, 'transaction_payments', (int) $row->row_id, $transactionId, (string) $row->document),
                        ];
                        $this->addStoredFileReferences($index, $fileNames, (string) $row->document, $reference);
                    }
                }, 'payment.id', 'row_id');
        }

        $this->indexExistingTransactionMedia($index, $businessId, $fileNames, $transactionTypes);
        $this->indexExistingAccountTransactionMedia($index, $businessId, $fileNames);

        return $index;
    }

    private function documentReferencesForFile(array $fileNames, string $table, int $id, int $transactionId, string $storedName): array
    {
        foreach ($this->storedFileNameKeys($storedName) as $fileKey) {
            if (isset($fileNames[$fileKey])) {
                return [[
                    'table' => $table,
                    'id' => $id,
                    'transaction_id' => $transactionId,
                    'value' => $storedName,
                ]];
            }
        }

        return [];
    }

    private function addStoredFileReferences(array &$index, array $fileNames, string $storedName, array $reference): void
    {
        foreach ($this->storedFileNameKeys($storedName) as $fileKey) {
            if (isset($fileNames[$fileKey])) {
                $this->addAttachmentReference($index, 'file_name', null, $fileKey, $reference);
            }
        }
    }

    private function indexExistingTransactionMedia(array &$index, int $businessId, array $fileNames, array $transactionTypes): void
    {
        if (! Schema::hasTable('media') || ! Schema::hasColumn('media', 'business_id')
            || ! Schema::hasColumn('media', 'model_type') || ! Schema::hasColumn('media', 'model_id')
            || ! Schema::hasColumn('media', 'file_name') || ! Schema::hasTable('transactions')
            || ! Schema::hasColumn('transactions', 'id') || ! Schema::hasColumn('transactions', 'business_id')
            || ! Schema::hasColumn('transactions', 'type')) {
            return;
        }

        $select = ['media.id as media_row_id', 'media.model_id', 'media.file_name', 'transaction.type'];
        if (Schema::hasColumn('media', 'original_name')) {
            $select[] = 'media.original_name';
        }
        if (Schema::hasColumn('transactions', 'ref_no')) {
            $select[] = 'transaction.ref_no as parent_ref_no';
        }
        DB::table('media')
            ->join('transactions as transaction', 'transaction.id', '=', 'media.model_id')
            ->where('media.business_id', $businessId)
            ->where('media.model_type', Transaction::class)
            ->where('transaction.business_id', $businessId)
            ->whereIn('transaction.type', $transactionTypes)
            ->when(Schema::hasColumn('transactions', 'deleted_at'), fn ($query) => $query->whereNull('transaction.deleted_at'))
            ->select($select)
            ->orderBy('media.id')
            ->chunkById(500, function ($rows) use (&$index, $fileNames): void {
                foreach ($rows as $row) {
                    $transactionId = (int) $row->model_id;
                    $reference = [
                        'media_model_type' => Transaction::class,
                        'media_model_id' => $transactionId,
                        'target_transaction_id' => $transactionId,
                        'target_record_type' => Transaction::class,
                        'target_record_id' => $transactionId,
                        'parent_ref_no' => (string) ($row->parent_ref_no ?? ''),
                        'parent_transaction_type' => (string) $row->type,
                        'document_references' => [],
                    ];
                    $this->addStoredFileReferences($index, $fileNames, (string) $row->file_name, $reference);
                    if (isset($row->original_name) && trim((string) $row->original_name) !== '') {
                        $this->addStoredFileReferences($index, $fileNames, (string) $row->original_name, $reference);
                    }
                }
            }, 'media.id', 'media_row_id');
    }

    private function indexExistingAccountTransactionMedia(array &$index, int $businessId, array $fileNames): void
    {
        if (! Schema::hasTable('media') || ! Schema::hasTable('account_transactions') || ! Schema::hasTable('accounts')
            || ! Schema::hasColumn('media', 'business_id') || ! Schema::hasColumn('media', 'model_type')
            || ! Schema::hasColumn('media', 'model_id') || ! Schema::hasColumn('media', 'file_name')
            || ! Schema::hasColumn('account_transactions', 'account_id')) {
            return;
        }

        $accountModelTypes = [\App\AccountTransaction::class, \Modules\Accounting\Entities\AccountTransaction::class];
        $select = ['media.id as media_row_id', 'media.model_type', 'media.model_id', 'media.file_name'];
        if (Schema::hasColumn('media', 'original_name')) {
            $select[] = 'media.original_name';
        }
        if (Schema::hasColumn('account_transactions', 'transaction_id')) {
            $select[] = 'account_transaction.transaction_id';
        }

        DB::table('media')
            ->join('account_transactions as account_transaction', 'account_transaction.id', '=', 'media.model_id')
            ->join('accounts as account', 'account.id', '=', 'account_transaction.account_id')
            ->where('media.business_id', $businessId)
            ->whereIn('media.model_type', $accountModelTypes)
            ->where('account.business_id', $businessId)
            ->when(Schema::hasColumn('account_transactions', 'deleted_at'), fn ($query) => $query->whereNull('account_transaction.deleted_at'))
            ->when(Schema::hasColumn('accounts', 'deleted_at'), fn ($query) => $query->whereNull('account.deleted_at'))
            ->select($select)
            ->orderBy('media.id')
            ->chunkById(500, function ($rows) use (&$index, $fileNames): void {
                foreach ($rows as $row) {
                    $accountTransactionId = (int) $row->model_id;
                    $reference = [
                        'media_model_type' => (string) $row->model_type,
                        'media_model_id' => $accountTransactionId,
                        'target_transaction_id' => isset($row->transaction_id) ? (int) $row->transaction_id : null,
                        'target_record_type' => (string) $row->model_type,
                        'target_record_id' => $accountTransactionId,
                        'parent_ref_no' => null,
                        'parent_transaction_type' => null,
                        'document_references' => [],
                    ];
                    $this->addStoredFileReferences($index, $fileNames, (string) $row->file_name, $reference);
                    if (isset($row->original_name) && trim((string) $row->original_name) !== '') {
                        $this->addStoredFileReferences($index, $fileNames, (string) $row->original_name, $reference);
                    }
                }
            }, 'media.id', 'media_row_id');
    }

    private function addAttachmentReference(array &$index, string $kind, ?string $sourceType, string $key, array $reference): void
    {
        $index[$kind][$sourceType ?? '*'][$key][] = $reference;
    }

    private function validTransactionFromIndex(int $transactionId, string $sourceType, array $transactions): ?Transaction
    {
        $transaction = $transactions[$transactionId] ?? null;
        if (! $transaction || ! in_array($transaction->type, self::OPERATIONAL_TYPES[$sourceType] ?? [], true)) {
            return null;
        }

        return $transaction;
    }

    private function mediaIndex(int $businessId, array $records): array
    {
        $attachmentIds = array_values(array_unique(array_filter(array_map(
            fn (array $record) => $this->scalarId($record['id'] ?? null),
            $records
        ))));
        $media = [];
        foreach (array_chunk($attachmentIds, 500) as $chunk) {
            foreach (Media::query()
                ->where('business_id', $businessId)
                ->whereIn('legacy_source_attachment_id', $chunk)
                ->get() as $row) {
                $media[(string) $row->legacy_source_attachment_id] = $row;
            }
        }

        return $media;
    }

    private function ensureDestinationFile(
        string $url,
        array $fileInfo,
        string $destinationName,
        string $documentsDirectory,
        bool $allowRemoteDownloads
    ): array {
        $directory = $this->ensureSafeDirectory($documentsDirectory);
        if ($directory === null) {
            return ['status' => 'rejected', 'action' => null, 'reason' => 'Folder uploads/documents tidak aman atau tidak dapat dibuat.'];
        }

        $destinationPath = $directory.DIRECTORY_SEPARATOR.$destinationName;
        if (is_link($destinationPath)) {
            return ['status' => 'rejected', 'action' => null, 'reason' => 'File tujuan adalah symbolic link; tidak ditimpa.'];
        }
        if (is_file($destinationPath)) {
            return ['status' => 'ready', 'action' => 'reused', 'reason' => null];
        }
        if (! $allowRemoteDownloads) {
            return ['status' => 'review', 'action' => 'pending', 'reason' => 'File tidak tersedia lokal; lingkungan non-production tidak mengunduh lampiran.'];
        }

        $timeout = max(1, (int) config('legacy_import.attachments.timeout_seconds', 30));
        $connectTimeout = max(1, min($timeout, (int) config('legacy_import.attachments.connect_timeout_seconds', 10)));
        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->get($url);
        } catch (\Throwable $exception) {
            return ['status' => 'failed', 'action' => 'failed', 'reason' => 'Unduhan gagal: '.$exception->getMessage()];
        }

        if (! $response->successful()) {
            return ['status' => 'failed', 'action' => 'failed', 'reason' => 'Server lampiran merespons HTTP '.$response->status().'.'];
        }

        $maxBytes = max(1, (int) config('legacy_import.attachments.max_bytes', 5000000));
        $contentLength = (int) $response->header('Content-Length', 0);
        if ($contentLength > $maxBytes) {
            return ['status' => 'rejected', 'action' => 'rejected', 'reason' => 'Ukuran lampiran melebihi batas konfigurasi.'];
        }
        $contents = $response->body();
        if ($contents === '' || strlen($contents) > $maxBytes) {
            return ['status' => 'rejected', 'action' => 'rejected', 'reason' => 'Ukuran lampiran kosong atau melebihi batas konfigurasi.'];
        }

        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type', ''))[0]));
        if (! $this->contentMatchesAllowedType((string) $fileInfo['extension'], $mime, $contents)) {
            return ['status' => 'rejected', 'action' => 'rejected', 'reason' => 'Tipe konten unduhan tidak sesuai dengan tipe file yang diizinkan.'];
        }

        $temporaryPath = tempnam($directory, '.legacy-attachment-');
        if ($temporaryPath === false) {
            return ['status' => 'failed', 'action' => 'failed', 'reason' => 'File sementara tidak dapat dibuat.'];
        }

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) !== strlen($contents)) {
                return ['status' => 'failed', 'action' => 'failed', 'reason' => 'File sementara tidak dapat ditulis penuh.'];
            }

            // link() installs the completed temporary file atomically and fails if the
            // deterministic target already exists, so a retry never overwrites a file.
            if (! @link($temporaryPath, $destinationPath)) {
                if (is_file($destinationPath) && ! is_link($destinationPath)) {
                    return ['status' => 'ready', 'action' => 'reused', 'reason' => null];
                }
                return ['status' => 'failed', 'action' => 'failed', 'reason' => 'File hasil unduhan tidak dapat dipindahkan secara atomik ke folder dokumen.'];
            }

            return ['status' => 'ready', 'action' => 'downloaded', 'reason' => null];
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function validateSourceUrl(string $url, string $attachmentId): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! empty($parts['user']) || ! empty($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return ['valid' => false, 'reason' => 'URL lampiran harus menggunakan HTTPS tanpa kredensial atau port non-standar.'];
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = array_map('strtolower', (array) config('legacy_import.attachments.allowed_hosts', []));
        if ($host === '' || ! in_array($host, $allowedHosts, true)) {
            return ['valid' => false, 'reason' => 'Host URL lampiran tidak termasuk daftar yang diizinkan.'];
        }

        $segments = explode('/', trim((string) ($parts['path'] ?? ''), '/'));
        $decodedSegments = array_map('rawurldecode', $segments);
        if (count($decodedSegments) !== 5
            || $decodedSegments[0] !== 'uploads'
            || $decodedSegments[1] !== 'transaction_attachment'
            || $decodedSegments[2] !== 'file'
            || (string) $decodedSegments[3] !== (string) $attachmentId) {
            return ['valid' => false, 'reason' => 'Struktur path URL lampiran tidak cocok dengan ID sumber.'];
        }

        $fileName = $decodedSegments[4];
        if (! $this->isSafeFileName($fileName)) {
            return ['valid' => false, 'reason' => 'Nama file sumber mengandung path atau karakter yang tidak aman.'];
        }
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = array_map('strtolower', (array) config('legacy_import.attachments.allowed_extensions', []));
        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            return ['valid' => false, 'reason' => 'Ekstensi lampiran tidak termasuk daftar yang diizinkan.'];
        }

        return ['valid' => true, 'reason' => null, 'original_name' => $fileName, 'extension' => $extension];
    }

    private function contentMatchesAllowedType(string $extension, string $mime, string $contents): bool
    {
        $acceptedMimes = [
            'pdf' => ['application/pdf', 'application/octet-stream'],
            'jpg' => ['image/jpeg', 'application/octet-stream'],
            'jpeg' => ['image/jpeg', 'application/octet-stream'],
            'png' => ['image/png', 'application/octet-stream'],
            'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        ];
        if (! in_array($mime, $acceptedMimes[$extension] ?? [], true)) {
            return false;
        }

        if ($extension === 'pdf') {
            return str_starts_with(ltrim($contents), '%PDF-');
        }
        if ($extension === 'jpg' || $extension === 'jpeg') {
            return str_starts_with($contents, "\xFF\xD8\xFF");
        }
        if ($extension === 'png') {
            return str_starts_with($contents, "\x89PNG\r\n\x1A\n");
        }

        return str_starts_with($contents, "PK\x03\x04")
            || str_starts_with($contents, "PK\x05\x06")
            || str_starts_with($contents, "PK\x07\x08");
    }

    private function parseEntryNumber(string $entryNumber): array
    {
        if (preg_match('/^\s*(Expense|Purchase Invoice|Sales Invoice|Purchase Order|Purchase Payment|Receive Payment|Bank Transfer|Bank Withdrawal|Bank Deposit)\s*#\s*(.+?)\s*$/i', $entryNumber, $matches) !== 1) {
            return [null, null];
        }

        $sourceType = [
            'expense' => 'Expense',
            'purchase invoice' => 'Purchase Invoice',
            'sales invoice' => 'Sales Invoice',
            'purchase order' => 'Purchase Order',
            'purchase payment' => 'Purchase Payment',
            'receive payment' => 'Receive Payment',
            'bank transfer' => 'Bank Transfer',
            'bank withdrawal' => 'Bank Withdrawal',
            'bank deposit' => 'Bank Deposit',
        ][strtolower(trim($matches[1]))] ?? null;
        $sourceNumber = trim($matches[2]);

        return [$sourceType, $sourceNumber !== '' ? $sourceNumber : null];
    }

    private function sourceIdentity(array $record): array
    {
        $entry = is_array($record['entry'] ?? null) ? $record['entry'] : [];
        $reference = '';
        foreach ([$entry, $record] as $source) {
            foreach (['number', 'noref', 'no_ref', 'ref_no', 'reference_no', 'reference_number', 'source_number', 'reference'] as $field) {
                $value = $source[$field] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $reference = trim((string) $value);
                    break 2;
                }
            }
        }

        [$sourceType, $sourceNumber] = $this->parseEntryNumber($reference);
        if ($sourceType !== null) {
            return [$sourceType, $sourceNumber];
        }

        $typeValue = $entry['type'] ?? $record['source_type'] ?? $record['transaction_type'] ?? $record['type'] ?? null;
        $sourceType = $this->canonicalSourceType(is_scalar($typeValue) ? (string) $typeValue : '');

        return [$sourceType, $reference !== '' ? $reference : null];
    }

    private function canonicalSourceType(string $value): ?string
    {
        $key = strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
        $types = [
            'expense' => 'Expense',
            'expense_refund' => 'Expense',
            'purchase' => 'Purchase Invoice',
            'purchase invoice' => 'Purchase Invoice',
            'purchase_order' => 'Purchase Order',
            'purchase order' => 'Purchase Order',
            'purchase payment' => 'Purchase Payment',
            'receive payment' => 'Receive Payment',
            'sell' => 'Sales Invoice',
            'sales invoice' => 'Sales Invoice',
            'bank transfer' => 'Bank Transfer',
            'bank withdrawal' => 'Bank Withdrawal',
            'bank deposit' => 'Bank Deposit',
        ];

        return $types[$key] ?? null;
    }

    private function collisionInfo(array $records): array
    {
        $counts = [];
        foreach ($records as $record) {
            $id = $this->scalarId($record['id'] ?? null);
            $file = is_array($record['file'] ?? null) ? $record['file'] : [];
            $url = parse_url((string) ($file['url'] ?? ''), PHP_URL_PATH);
            $segments = explode('/', trim((string) $url, '/'));
            $name = rawurldecode((string) end($segments));
            if ($id === '' || ! $this->isSafeFileName($name)) {
                continue;
            }
            $key = mb_strtolower($name, 'UTF-8');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $groups = array_filter($counts, fn (int $count) => $count > 1);

        return [
            'counts' => $counts,
            'groups' => $groups,
            'attachments' => array_sum($groups),
        ];
    }

    private function destinationName(string $originalName, string $attachmentId, bool $isCollision): string
    {
        if (! $isCollision) {
            return $originalName;
        }

        $safeId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $attachmentId) ?: substr(hash('sha256', $attachmentId), 0, 16);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.'.$extension : '';
        $name = substr((string) pathinfo($originalName, PATHINFO_FILENAME), 0, max(1, 220 - strlen($safeId) - strlen($suffix)));

        return 'legacy-'.$safeId.'__'.$name.$suffix;
    }

    private function isSafeFileName(string $fileName): bool
    {
        return $fileName !== ''
            && $fileName !== '.'
            && $fileName !== '..'
            && strlen($fileName) <= 240
            && ! preg_match('/[\\\\\/<>:"|?*\x00-\x1F\x7F]/u', $fileName)
            && ! preg_match('/[. ]$/u', $fileName)
            && ! preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $fileName);
    }

    private function ensureSafeDirectory(string $directory): ?string
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return null;
        }
        if (is_link($directory)) {
            return null;
        }
        $resolved = realpath($directory);

        return $resolved !== false && is_writable($resolved) ? $resolved : null;
    }

    private function safeSourcePath(string $root, string $relativePath)
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $relativePath)) {
            return false;
        }

        $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($path === false || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return false;
        }

        return $path;
    }

    private function scalarId($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function normalizeNumber(string $value): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }

    private function normalizeFileName(string $value): string
    {
        $fileName = basename(str_replace('\\', '/', trim($value)));

        return mb_strtolower($fileName, 'UTF-8');
    }

    private function storedFileNameKeys(string $value): array
    {
        $fileName = basename(str_replace('\\', '/', trim($value)));
        if ($fileName === '') {
            return [];
        }

        $keys = [$this->normalizeFileName($fileName)];
        if (preg_match('/^\d{9,13}_(?:[A-Za-z0-9]{20}_)?(.+)$/u', $fileName, $matches) === 1 && $matches[1] !== '') {
            $keys[] = $this->normalizeFileName($matches[1]);
        }

        return array_values(array_unique($keys));
    }

    private function storeOutcome(array $base, string $status, ?int $mediaId, ?string $matchMethod, ?string $fileAction, ?string $reason): array
    {
        $base['status'] = $status;
        $base['media_id'] = $mediaId;
        $base['match_method'] = $matchMethod;
        $base['file_action'] = $fileAction;
        $base['reason'] = $reason;
        $base['created_at'] = now();

        static $availableColumns = null;
        $availableColumns = $availableColumns ?? array_flip(Schema::getColumnListing('accounting_legacy_import_attachments'));
        $base = array_intersect_key($base, $availableColumns);
        $updateColumns = array_values(array_intersect([
            'legacy_import_source_id', 'business_id', 'legacy_transaction_id', 'source_type', 'source_number',
            'source_url', 'original_file_name', 'destination_file_name', 'target_transaction_id', 'target_record_type',
            'target_record_id', 'media_id', 'media_model_type', 'media_model_id', 'match_method', 'file_action',
            'status', 'reason', 'updated_at',
        ], array_keys($availableColumns)));

        DB::table('accounting_legacy_import_attachments')->upsert(
            [$base],
            ['legacy_import_run_id', 'legacy_source_attachment_id'],
            $updateColumns
        );

        return ['status' => $status];
    }

    private function writeMappingReport(int $runId): ?string
    {
        $directory = (string) config('legacy_import.attachments.mapping_report_directory', storage_path('app/legacy-import-reports'));
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return null;
        }
        $destination = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'legacy-attachments-run-'.$runId.'.csv';
        $temporary = tempnam($directory, '.legacy-attachment-report-');
        if ($temporary === false) {
            return null;
        }

        try {
            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                return null;
            }
            fputcsv($handle, [
                'legacy_attachment_id', 'legacy_transaction_id', 'source_type', 'source_number', 'source_url',
                'original_file_name', 'destination_file_name', 'transaction_id', 'target_record_type', 'target_record_id',
                'media_model_type', 'media_model_id', 'media_id', 'status', 'match_method', 'reason',
            ]);
            DB::table('accounting_legacy_import_attachments')
                ->where('legacy_import_run_id', $runId)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($handle) {
                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row->legacy_source_attachment_id,
                            $row->legacy_transaction_id,
                            $row->source_type,
                            $row->source_number,
                            $row->source_url,
                            $row->original_file_name,
                            $row->destination_file_name,
                            $row->target_transaction_id,
                            $row->target_record_type ?? null,
                            $row->target_record_id ?? null,
                            $row->media_model_type ?? null,
                            $row->media_model_id ?? null,
                            $row->media_id,
                            $row->status,
                            $row->match_method,
                            $row->reason,
                        ]);
                    }
                }, 'id');
            fclose($handle);

            if (! @rename($temporary, $destination)) {
                return null;
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $destination;
    }

    private function finishSource(object $source, int $runId, int $businessId, array $summary, ?string $error = null): array
    {
        $counts = DB::table('accounting_legacy_import_attachments')
            ->where('legacy_import_run_id', $runId)
            ->where('business_id', $businessId)
            ->selectRaw("SUM(CASE WHEN status = 'linked' THEN 1 ELSE 0 END) as linked_count")
            ->selectRaw("SUM(CASE WHEN status = 'review' THEN 1 ELSE 0 END) as review_count")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count")
            ->first();

        $summary['linked'] = (int) ($counts->linked_count ?? 0);
        $summary['review'] = (int) ($counts->review_count ?? 0);
        $summary['failed'] = (int) ($counts->failed_count ?? 0);
        $summary['rejected'] = (int) ($counts->rejected_count ?? 0);
        $summary['actionable'] = $summary['review'] + $summary['failed'] + $summary['rejected'];
        DB::table('accounting_legacy_import_sources')
            ->where('id', $source->id)
            ->where('business_id', $businessId)
            ->update([
                'records_read' => (int) $summary['total'],
                'records_linked' => $summary['linked'],
                'records_for_review' => $summary['actionable'],
                'status' => $error !== null || $summary['actionable'] > 0
                    ? 'review'
                    : ($summary['linked'] > 0 ? 'linked' : 'analyzed'),
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_message' => $error,
                'updated_at' => now(),
            ]);

        return $summary;
    }

    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'linked' => 0,
            'review' => 0,
            'failed' => 0,
            'rejected' => 0,
            'actionable' => 0,
            'collision_groups' => 0,
            'collision_attachments' => 0,
            'mapping_report_path' => null,
        ];
    }
}
