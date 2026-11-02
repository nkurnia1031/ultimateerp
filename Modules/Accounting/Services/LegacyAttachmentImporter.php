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
        $mediaIndex = $this->mediaIndex($businessId, $records);

        foreach ($records as $record) {
            $outcome = $this->importRecord(
                $record,
                $source,
                $runId,
                $businessId,
                $documentsDirectory,
                $allowRemoteDownloads,
                $collisionInfo['counts'],
                $crosswalkIndex,
                $transactionIndex,
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
        array $crosswalkIndex,
        array $transactionIndex,
        array &$mediaIndex
    ): array {
        $attachmentId = $this->scalarId($record['id'] ?? null);
        if ($attachmentId === '') {
            $attachmentId = 'invalid-'.substr(hash('sha256', json_encode($record)), 0, 40);
        }
        $entry = is_array($record['entry'] ?? null) ? $record['entry'] : [];
        $legacyTransactionId = $this->scalarId($record['transaction_id'] ?? ($entry['id'] ?? null));
        $entryNumber = trim((string) ($entry['number'] ?? ''));
        [$sourceType, $sourceNumber] = $this->parseEntryNumber($entryNumber);
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
            'media_id' => null,
            'match_method' => null,
            'file_action' => null,
            'status' => 'review',
            'reason' => null,
            'updated_at' => now(),
        ];

        if ($fileInfo['valid'] !== true) {
            return $this->storeOutcome($base, 'rejected', null, null, 'rejected', (string) $fileInfo['reason']);
        }
        if (! isset(self::OPERATIONAL_TYPES[$sourceType])) {
            return $this->storeOutcome($base, 'review', null, null, null, 'Tipe transaksi lampiran tidak didukung untuk Media transaksi operasional.');
        }

        $match = $this->resolveTransaction($legacyTransactionId, $sourceType, $sourceNumber, $crosswalkIndex, $transactionIndex);
        if ($match['transaction'] === null) {
            return $this->storeOutcome(
                $base,
                'review',
                null,
                $match['method'],
                null,
                $match['reason']
            );
        }

        /** @var Transaction $transaction */
        $transaction = $match['transaction'];
        $base['target_transaction_id'] = (int) $transaction->id;
        $base['match_method'] = $match['method'];

        $existingMedia = $mediaIndex[$attachmentId] ?? null;
        if ($existingMedia && ((string) $existingMedia->model_type !== Transaction::class
            || (int) $existingMedia->model_id !== (int) $transaction->id)) {
            return $this->storeOutcome($base, 'review', null, $match['method'], null, 'ID lampiran sudah tertaut ke transaksi lain; tautan tidak diubah.');
        }

        if ($existingMedia) {
            $existingName = basename((string) $existingMedia->file_name);
            if ($existingMedia->storage_location !== 'documents' || ! $this->isSafeFileName($existingName)) {
                return $this->storeOutcome($base, 'review', (int) $existingMedia->id, $match['method'], null, 'Media lampiran yang sudah ada memakai lokasi atau nama file yang tidak sesuai.');
            }
            $destinationName = $existingName;
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
        $media->model_type = Transaction::class;
        $media->model_id = (int) $transaction->id;
        $media->uploaded_by = null;

        try {
            $media->save();
            $mediaIndex[$attachmentId] = $media;
        } catch (\Throwable $exception) {
            $concurrentMedia = Media::query()
                ->where('business_id', $businessId)
                ->where('legacy_source_attachment_id', $attachmentId)
                ->first();
            if (! $concurrentMedia || (int) $concurrentMedia->model_id !== (int) $transaction->id
                || (string) $concurrentMedia->model_type !== Transaction::class) {
                return $this->storeOutcome($base, 'failed', null, $match['method'], $fileAction['action'], 'Media tidak dapat disimpan: '.$exception->getMessage());
            }
            $media = $concurrentMedia;
            $mediaIndex[$attachmentId] = $media;
        }

        return $this->storeOutcome($base, 'linked', (int) $media->id, $match['method'], $fileAction['action'], null);
    }

    private function resolveTransaction(
        string $legacyTransactionId,
        string $sourceType,
        ?string $sourceNumber,
        array $crosswalkIndex,
        array $transactionIndex
    ): array {
        $targetIds = [];
        if ($legacyTransactionId !== '') {
            $targetIds = array_keys($crosswalkIndex['legacy'][$sourceType][$legacyTransactionId] ?? []);
            if (count($targetIds) > 1) {
                return ['transaction' => null, 'method' => 'legacy_record_id', 'reason' => 'ID transaksi legacy memiliki lebih dari satu target crosswalk.'];
            }
            if (count($targetIds) === 1) {
                $transaction = $this->validTransactionFromIndex((int) $targetIds[0], $sourceType, $transactionIndex);
                if ($transaction) {
                    return ['transaction' => $transaction, 'method' => 'legacy_record_id', 'reason' => null];
                }
            }
        }

        if ($sourceNumber !== null && $sourceNumber !== '') {
            $numberKey = $this->normalizeNumber($sourceNumber);
            $targetIds = array_keys($crosswalkIndex['number'][$sourceType][$numberKey] ?? []);
            if (count($targetIds) > 1) {
                return ['transaction' => null, 'method' => 'source_number', 'reason' => 'Nomor sumber memiliki beberapa transaksi tujuan; tautan lampiran perlu ditinjau.'];
            }
            if (count($targetIds) === 1) {
                $transaction = $this->validTransactionFromIndex((int) $targetIds[0], $sourceType, $transactionIndex);
                if ($transaction) {
                    return ['transaction' => $transaction, 'method' => 'source_number', 'reason' => null];
                }
            }
        }

        return ['transaction' => null, 'method' => null, 'reason' => 'Transaksi tujuan belum ditemukan pada crosswalk legacy tersimpan.'];
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
        if (preg_match('/^(Expense|Purchase Invoice|Sales Invoice)\s*#\s*(.+)$/i', $entryNumber, $matches) !== 1) {
            return [null, null];
        }

        $sourceType = [
            'expense' => 'Expense',
            'purchase invoice' => 'Purchase Invoice',
            'sales invoice' => 'Sales Invoice',
        ][strtolower(trim($matches[1]))] ?? null;
        $sourceNumber = trim($matches[2]);

        return [$sourceType, $sourceNumber !== '' ? $sourceNumber : null];
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

    private function storeOutcome(array $base, string $status, ?int $mediaId, ?string $matchMethod, ?string $fileAction, ?string $reason): array
    {
        $base['status'] = $status;
        $base['media_id'] = $mediaId;
        $base['match_method'] = $matchMethod;
        $base['file_action'] = $fileAction;
        $base['reason'] = $reason;
        $base['created_at'] = now();

        DB::table('accounting_legacy_import_attachments')->upsert(
            [$base],
            ['legacy_import_run_id', 'legacy_source_attachment_id'],
            [
                'legacy_import_source_id', 'business_id', 'legacy_transaction_id', 'source_type', 'source_number',
                'source_url', 'original_file_name', 'destination_file_name', 'target_transaction_id', 'media_id',
                'match_method', 'file_action', 'status', 'reason', 'updated_at',
            ]
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
                'original_file_name', 'destination_file_name', 'transaction_id', 'media_id', 'status', 'match_method', 'reason',
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
