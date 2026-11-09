<?php

namespace Modules\Accounting\Services;

use App\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LegacySourceReconciliationFindingService
{
    private const TABLE = 'accounting_legacy_source_findings';

    private const TYPE_MAP = [
        'Sales Invoice' => 'sell',
        'Purchase Invoice' => 'purchase',
        'Expense' => 'expense',
    ];

    public function recordReview(
        int $runId,
        int $businessId,
        array $document,
        string $reasonCode,
        string $reason,
        ?int $targetId = null
    ): void {
        $sourceIdentity = $this->sourceIdentity($document);
        $sourceType = trim((string) ($document['source_type'] ?? ''));
        if ($runId < 1 || $businessId < 1 || $sourceIdentity === null || ! isset(self::TYPE_MAP[$sourceType])) {
            return;
        }

        $now = now();
        $evidence = $this->evidence($document);
        $evidenceJson = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $identity = [
            'business_id' => $businessId,
            'legacy_import_run_id' => $runId,
            'source_identity' => $sourceIdentity,
        ];

        DB::transaction(function () use ($identity, $document, $sourceType, $reasonCode, $reason, $targetId, $evidenceJson, $now): void {
            $finding = DB::table(self::TABLE)->where($identity)->lockForUpdate()->first();
            if (! $finding) {
                DB::table(self::TABLE)->insert($identity + [
                    'source_type' => $sourceType,
                    'source_number' => $this->nullableText($document['source_number'] ?? null, 191),
                    'source_date' => $this->validDate($document['date'] ?? null),
                    'source_amount' => $this->sourceAmount($document),
                    'reason_code' => $reasonCode,
                    'reason' => mb_substr(trim($reason), 0, 4000),
                    'evidence' => $evidenceJson,
                    'status' => 'open',
                    'target_record_type' => $targetId ? Transaction::class : null,
                    'target_record_id' => $targetId,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'resolution_history' => json_encode([[
                        'action' => 'queued',
                        'actor_id' => null,
                        'at' => $now->toIso8601String(),
                        'reason' => trim($reason),
                    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return;
            }

            $changes = [];
            if ((string) $finding->reason_code !== $reasonCode || (string) $finding->reason !== trim($reason)) {
                $changes['reason_changed'] = [
                    'from' => (string) $finding->reason_code,
                    'to' => $reasonCode,
                    'message' => mb_substr(trim($reason), 0, 4000),
                ];
            }
            if ((string) ($finding->evidence ?? '') !== $evidenceJson) {
                $changes['evidence_changed'] = true;
            }

            $history = $this->decodeHistory($finding->resolution_history ?? null);
            $status = (string) $finding->status;
            $nextStatus = $status === 'resolved' && $changes === [] ? 'resolved' : 'open';
            if ($status === 'resolved' && $nextStatus === 'open') {
                $history[] = [
                    'action' => 'reopened',
                    'actor_id' => null,
                    'at' => $now->toIso8601String(),
                    'reason' => 'Sumber kembali memerlukan peninjauan setelah recheck legacy.',
                    'changes' => $changes,
                ];
            } elseif ($changes !== []) {
                $history[] = [
                    'action' => 'updated_from_recheck',
                    'actor_id' => null,
                    'at' => $now->toIso8601String(),
                    'reason' => trim($reason),
                    'changes' => $changes,
                ];
            }

            DB::table(self::TABLE)->where($identity)->update([
                'source_type' => $sourceType,
                'source_number' => $this->nullableText($document['source_number'] ?? null, 191),
                'source_date' => $this->validDate($document['date'] ?? null),
                'source_amount' => $this->sourceAmount($document),
                'reason_code' => $reasonCode,
                'reason' => mb_substr(trim($reason), 0, 4000),
                'evidence' => $evidenceJson,
                'status' => $nextStatus,
                'target_record_type' => $nextStatus === 'open' ? ($targetId ? Transaction::class : null) : $finding->target_record_type,
                'target_record_id' => $nextStatus === 'open' ? $targetId : $finding->target_record_id,
                'resolved_by' => $nextStatus === 'resolved' ? $finding->resolved_by : null,
                'resolved_at' => $nextStatus === 'resolved' ? $finding->resolved_at : null,
                'resolution_reason' => $nextStatus === 'resolved' ? $finding->resolution_reason : null,
                'last_seen_at' => $now,
                'resolution_history' => json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ]);
        });
    }

    public function summary(int $businessId, array $filters = []): array
    {
        $query = $this->filteredQuery($businessId, $filters);

        return [
            'open_count' => (int) DB::table(self::TABLE)->where('business_id', $businessId)->where('status', 'open')->count(),
            'filtered_count' => (int) (clone $query)->count(),
            'last_seen_at' => DB::table(self::TABLE)->where('business_id', $businessId)->max('last_seen_at'),
        ];
    }

    public function paginate(int $businessId, array $filters = [], ?int $page = null): LengthAwarePaginator
    {
        $page = max(1, (int) ($page ?? 1));
        $rows = $this->filteredQuery($businessId, $filters)
            ->orderByDesc('source_date')
            ->orderBy('source_type')
            ->orderBy('source_number')
            ->orderBy('id')
            ->paginate(25, ['*'], 'source_page', $page)
            ->withQueryString();

        $rows->setCollection($rows->getCollection()->map(fn ($row) => $this->present($businessId, $row)));

        return $rows;
    }

    public function years(int $businessId): array
    {
        $yearExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y', source_date)"
            : 'YEAR(source_date)';

        return DB::table(self::TABLE)->where('business_id', $businessId)->whereNotNull('source_date')
            ->selectRaw($yearExpression.' as source_year')->distinct()->orderByDesc('source_year')->pluck('source_year')->map(fn ($year) => (int) $year)->all();
    }

    public function reasons(int $businessId): array
    {
        return DB::table(self::TABLE)->where('business_id', $businessId)->distinct()->orderBy('reason_code')->pluck('reason_code')->all();
    }

    public function types(int $businessId): array
    {
        return DB::table(self::TABLE)->where('business_id', $businessId)->distinct()->orderBy('source_type')->pluck('source_type')->all();
    }

    public function resolveFromRecheck(int $runId, int $businessId, array $document, ?int $targetId = null): void
    {
        $sourceIdentity = $this->sourceIdentity($document);
        if ($sourceIdentity === null || $runId < 1 || $businessId < 1) {
            return;
        }
        $finding = DB::table(self::TABLE)
            ->where('business_id', $businessId)
            ->where('legacy_import_run_id', $runId)
            ->where('source_identity', $sourceIdentity)
            ->lockForUpdate()
            ->first();
        if (! $finding || (string) $finding->status !== 'open') {
            return;
        }

        $now = now();
        $history = $this->decodeHistory($finding->resolution_history ?? null);
        $history[] = [
            'action' => 'resolved_by_legacy_recheck',
            'actor_id' => null,
            'at' => $now->toIso8601String(),
            'reason' => 'Recheck legacy memastikan sumber sudah tertaut dengan status berhasil.',
            'target_record_type' => $targetId ? Transaction::class : null,
            'target_record_id' => $targetId,
        ];
        DB::table(self::TABLE)->where('id', (int) $finding->id)->update([
            'status' => 'resolved',
            'target_record_type' => $targetId ? Transaction::class : $finding->target_record_type,
            'target_record_id' => $targetId ?: $finding->target_record_id,
            'resolved_at' => $now,
            'resolution_reason' => 'Recheck legacy memastikan sumber sudah tertaut dengan status berhasil.',
            'resolution_history' => json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_seen_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function linkExisting(int $businessId, int $findingId, int $transactionId, int $actorId, string $reason): array
    {
        return DB::transaction(function () use ($businessId, $findingId, $transactionId, $actorId, $reason): array {
            $finding = $this->finding($businessId, $findingId, true);
            $transaction = $this->transactionForFinding($businessId, $finding, $transactionId, true);

            return $this->linkLocked($finding, $transaction, $actorId, $reason);
        });
    }

    public function retry(int $businessId, int $findingId, int $actorId, string $reason): array
    {
        return DB::transaction(function () use ($businessId, $findingId, $actorId, $reason): array {
            $finding = $this->finding($businessId, $findingId, true);
            if ((string) $finding->status !== 'open') {
                if ((string) $finding->status === 'resolved') {
                    return ['success' => true, 'idempotent' => true, 'message' => 'Sumber sudah diselesaikan sebelumnya.'];
                }
                $this->reject('Hanya temuan terbuka yang dapat dicoba ulang.');
            }

            $type = self::TYPE_MAP[(string) $finding->source_type] ?? null;
            $reference = trim((string) ($finding->source_number ?? ''));
            $date = $this->validDate($finding->source_date ?? null);
            if ($type === null || $reference === '' || $date === null) {
                $this->reject('Nomor, tanggal, atau jenis sumber belum cukup untuk mencoba ulang otomatis. Tautkan transaksi secara manual atau lengkapi bukti sumber.');
            }

            $matches = $this->transactionQuery($businessId, $type)
                ->whereDate('transaction_date', $date)
                ->where(function ($query) use ($reference): void {
                    $query->where('ref_no', $reference)->orWhere('invoice_no', $reference);
                })
                ->orderBy('id')
                ->limit(3)
                ->get();
            if ($matches->count() !== 1) {
                $this->reject($matches->isEmpty()
                    ? 'Transaksi yang cocok belum tersedia. Lengkapi sumber melalui form transaksi, lalu coba ulang.'
                    : 'Ditemukan beberapa transaksi dengan nomor dan tanggal yang sama. Pilih transaksi yang benar untuk ditautkan secara manual.');
            }

            $transaction = $matches->first();
            if ($finding->source_amount !== null && abs((float) $transaction->final_total - (float) $finding->source_amount) > 0.01) {
                $this->reject('Transaksi ditemukan, tetapi totalnya berbeda dari sumber. Tinjau kandidat dan tautkan secara manual jika memang benar.');
            }

            return $this->linkLocked($finding, $transaction, $actorId, $reason);
        });
    }

    private function linkLocked(object $finding, object $transaction, int $actorId, string $reason): array
    {
        $businessId = (int) $finding->business_id;
        $runId = (int) $finding->legacy_import_run_id;
        $sourceIdentity = (string) $finding->source_identity;
        if ((string) $finding->status === 'resolved') {
            if ((int) $finding->target_record_id === (int) $transaction->id) {
                return ['success' => true, 'idempotent' => true, 'message' => 'Temuan sudah tertaut ke transaksi ini.'];
            }
            $this->reject('Temuan sudah diselesaikan dengan transaksi lain.');
        }

        $crosswalks = DB::table('accounting_legacy_import_crosswalks')
            ->where('business_id', $businessId)
            ->where('legacy_import_run_id', $runId)
            ->where('source_identity', $sourceIdentity)
            ->lockForUpdate()
            ->get();
        if ($crosswalks->isEmpty()) {
            $this->reject('Relasi sumber legacy tidak ditemukan. Jalankan ulang pemeriksaan legacy sebelum menautkan.');
        }
        foreach ($crosswalks as $crosswalk) {
            if ($crosswalk->target_record_id !== null) {
                if ($crosswalk->target_record_type !== Transaction::class) {
                    $this->reject('Sumber legacy sudah tertaut ke catatan Journal atau jenis target lain; relasi tidak diubah.');
                }
                if ((int) $crosswalk->target_record_id !== (int) $transaction->id) {
                    $this->reject('Sumber legacy sudah tertaut ke transaksi lain pada salah satu file sumber.');
                }
            }
            if ($crosswalk->journal_import_row_id !== null && Schema::hasTable('accounting_journal_import_rows')) {
                $journalRow = DB::table('accounting_journal_import_rows')
                    ->where('business_id', $businessId)
                    ->where('id', (int) $crosswalk->journal_import_row_id)
                    ->first(['source_record_type', 'source_record_id']);
                if ($journalRow && $journalRow->source_record_id !== null
                    && ((string) $journalRow->source_record_type !== Transaction::class
                        || (int) $journalRow->source_record_id !== (int) $transaction->id)) {
                    $this->reject('Baris Journal sumber sudah tertaut ke transaksi lain; relasi tidak diubah.');
                }
            }
        }

        $duplicateTarget = DB::table('accounting_legacy_import_crosswalks')
            ->where('business_id', $businessId)
            ->where('target_record_type', Transaction::class)
            ->where('target_record_id', (int) $transaction->id)
            ->where('source_identity', '<>', $sourceIdentity)
            ->whereNotIn('status', ['failed'])
            ->exists();
        if ($duplicateTarget) {
            $this->reject('Transaksi ini sudah tertaut ke dokumen legacy lain.');
        }

        $now = now();
        DB::table('accounting_legacy_import_crosswalks')
            ->where('business_id', $businessId)
            ->where('legacy_import_run_id', $runId)
            ->where('source_identity', $sourceIdentity)
            ->update([
                'target_record_type' => Transaction::class,
                'target_record_id' => (int) $transaction->id,
                'status' => 'linked',
                'match_method' => 'reconciliation_admin',
                'reason' => mb_substr(trim($reason), 0, 4000),
                'updated_at' => $now,
            ]);

        $history = $this->decodeHistory($finding->resolution_history ?? null);
        $history[] = [
            'action' => 'linked_existing_transaction',
            'actor_id' => $actorId,
            'at' => $now->toIso8601String(),
            'reason' => trim($reason),
            'target_record_type' => Transaction::class,
            'target_record_id' => (int) $transaction->id,
        ];
        DB::table(self::TABLE)->where('id', (int) $finding->id)->where('business_id', $businessId)->update([
            'status' => 'resolved',
            'target_record_type' => Transaction::class,
            'target_record_id' => (int) $transaction->id,
            'resolved_by' => $actorId,
            'resolved_at' => $now,
            'resolution_reason' => mb_substr(trim($reason), 0, 4000),
            'resolution_history' => json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ]);
        app(LegacyImportRunService::class)->refreshSourceCounts($runId, $businessId);

        return ['success' => true, 'idempotent' => false, 'message' => 'Sumber legacy berhasil ditautkan; Journal dan GL tidak diubah.'];
    }

    private function present(int $businessId, object $row): array
    {
        $evidence = json_decode((string) ($row->evidence ?? '{}'), true);
        $history = json_decode((string) ($row->resolution_history ?? '[]'), true);
        $evidence = is_array($evidence) ? $evidence : [];
        $history = is_array($history) ? $history : [];

        return [
            'id' => (int) $row->id,
            'run_id' => (int) $row->legacy_import_run_id,
            'source_identity' => (string) $row->source_identity,
            'source_type' => (string) $row->source_type,
            'source_number' => (string) ($row->source_number ?? ''),
            'source_date' => $row->source_date,
            'source_amount' => $row->source_amount === null ? null : (float) $row->source_amount,
            'reason_code' => (string) $row->reason_code,
            'reason' => (string) $row->reason,
            'evidence' => $evidence,
            'status' => (string) $row->status,
            'target_record_id' => $row->target_record_id === null ? null : (int) $row->target_record_id,
            'resolution_reason' => (string) ($row->resolution_reason ?? ''),
            'history' => $history,
            'candidates' => $this->candidateTransactions($businessId, $row),
            'create_url' => $this->createUrl((string) $row->source_type),
        ];
    }

    private function candidateTransactions(int $businessId, object $finding): array
    {
        $type = self::TYPE_MAP[(string) $finding->source_type] ?? null;
        if ($type === null) {
            return [];
        }
        $reference = trim((string) ($finding->source_number ?? ''));
        $date = $this->validDate($finding->source_date ?? null);
        $matches = collect();
        if ($reference !== '') {
            $query = $this->transactionQuery($businessId, $type)
                ->where(function ($builder) use ($reference): void {
                    $builder->where('ref_no', $reference)->orWhere('invoice_no', $reference);
                });
            if ($date !== null) {
                $query->whereDate('transaction_date', $date);
            }
            $matches = $query->limit(10)->get();
        }
        $matchMethod = 'nomor_tanggal';

        if ($matches->isEmpty() && $date !== null && $finding->source_amount !== null) {
            $amount = (float) $finding->source_amount;
            $radius = max(1, abs($amount) * 0.25);
            $matches = $this->transactionQuery($businessId, $type)
                ->whereDate('transaction_date', $date)
                ->whereBetween('final_total', [$amount - $radius, $amount + $radius])
                ->limit(100)
                ->get();
            $matchMethod = 'tanggal_nominal_terdekat';
        }
        if ($matches->isEmpty() && $date === null && $finding->source_amount !== null) {
            $amount = (float) $finding->source_amount;
            $radius = max(1, abs($amount) * 0.10);
            $matches = $this->transactionQuery($businessId, $type)
                ->whereBetween('final_total', [$amount - $radius, $amount + $radius])
                ->limit(100)
                ->get();
            $matchMethod = 'nominal_terdekat';
        }

        $target = $finding->source_amount === null ? null : (float) $finding->source_amount;
        return $matches->map(function ($transaction) use ($target, $matchMethod): array {
            $diff = $transaction->final_total === null || $target === null
                ? null
                : round(abs((float) $transaction->final_total - $target), 4);

            return [
                'id' => (int) $transaction->id,
                'reference' => trim((string) ($transaction->ref_no ?: $transaction->invoice_no ?: '')),
                'date' => $transaction->transaction_date,
                'status' => (string) $transaction->status,
                'total' => $transaction->final_total === null ? null : (float) $transaction->final_total,
                'base' => $transaction->total_before_tax === null ? null : (float) $transaction->total_before_tax,
                'tax' => $transaction->tax_amount === null ? null : (float) $transaction->tax_amount,
                'amount_difference' => $diff,
                'match_method' => $matchMethod,
            ];
        })->sortBy(fn (array $candidate) => $candidate['amount_difference'] ?? PHP_FLOAT_MAX)->take(10)->values()->all();
    }

    private function createUrl(string $sourceType): ?string
    {
        $routeName = [
            'Sales Invoice' => 'sells.create',
            'Purchase Invoice' => 'purchases.create',
            'Expense' => 'expenses.create',
        ][$sourceType] ?? null;

        return $routeName !== null && Route::has($routeName) ? route($routeName) : null;
    }

    private function transactionForFinding(int $businessId, object $finding, int $transactionId, bool $lock): object
    {
        $type = self::TYPE_MAP[(string) $finding->source_type] ?? null;
        if ($type === null) {
            $this->reject('Jenis sumber transaksi tidak didukung.');
        }
        $query = $this->transactionQuery($businessId, $type)->where('id', $transactionId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $transaction = $query->first();
        if (! $transaction) {
            $this->reject('Transaksi target tidak ditemukan pada business aktif, tidak aktif, atau jenisnya berbeda.');
        }

        return $transaction;
    }

    private function transactionQuery(int $businessId, string $type): Builder
    {
        $columns = ['id', 'business_id', 'type', 'status', 'transaction_date', 'ref_no', 'invoice_no', 'final_total', 'total_before_tax', 'tax_amount'];
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $columns[] = 'deleted_at';
        }
        $query = DB::table('transactions')->where('business_id', $businessId)->where('type', $type)->where('status', 'final');
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->select($columns);
    }

    private function finding(int $businessId, int $findingId, bool $lock = false): object
    {
        $query = DB::table(self::TABLE)->where('business_id', $businessId)->where('id', $findingId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $finding = $query->first();
        if (! $finding) {
            abort(404);
        }
        if ((string) $finding->status !== 'open' && (string) $finding->status !== 'resolved') {
            $this->reject('Status temuan tidak mendukung aksi ini.');
        }

        return $finding;
    }

    private function filteredQuery(int $businessId, array $filters): Builder
    {
        $query = DB::table(self::TABLE)->where('business_id', $businessId);
        $query->when(! empty($filters['year']), fn ($builder) => $builder->whereYear('source_date', (int) $filters['year']));
        $query->when(! empty($filters['source_type']), fn ($builder) => $builder->where('source_type', (string) $filters['source_type']));
        $query->when(! empty($filters['reason']), fn ($builder) => $builder->where('reason_code', (string) $filters['reason']));
        if (($filters['status'] ?? 'open') !== 'all') {
            $query->where('status', (string) ($filters['status'] ?? 'open'));
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($builder) use ($like, $search): void {
                $builder->where('source_number', 'like', $like)
                    ->orWhere('source_type', 'like', $like)
                    ->orWhere('reason', 'like', $like)
                    ->orWhere('reason_code', 'like', $like);
                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search);
                }
            });
        }

        return $query;
    }

    private function evidence(array $document): array
    {
        $evidence = [];
        foreach (['contact_name', 'description', 'category_name', 'base', 'tax', 'total', 'status', 'source_count', 'review_reason'] as $field) {
            if (array_key_exists($field, $document)) {
                $evidence[$field] = is_scalar($document[$field]) || $document[$field] === null
                    ? $document[$field]
                    : null;
            }
        }
        $evidence['lines'] = array_slice(array_map(function ($line): array {
            $line = is_array($line) ? $line : [];

            return [
                'product_name' => mb_substr(trim((string) ($line['product_name'] ?? '')), 0, 255),
                'product_code' => mb_substr(trim((string) ($line['product_code'] ?? '')), 0, 100),
                'quantity' => is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : null,
                'unit_price' => is_numeric($line['unit_price'] ?? null) ? (float) $line['unit_price'] : null,
                'line_base' => is_numeric($line['line_base'] ?? null) ? (float) $line['line_base'] : null,
                'tax' => is_numeric($line['tax'] ?? null) ? (float) $line['tax'] : null,
            ];
        }, (array) ($document['lines'] ?? [])), 0, 100);
        $evidence['expense_lines'] = array_slice(array_map(function ($line): array {
            $line = is_array($line) ? $line : [];

            return [
                'category_name' => mb_substr(trim((string) ($line['category_name'] ?? '')), 0, 255),
                'description' => mb_substr(trim((string) ($line['description'] ?? '')), 0, 500),
                'amount' => is_numeric($line['amount'] ?? null) ? (float) $line['amount'] : null,
            ];
        }, (array) ($document['expense_lines'] ?? [])), 0, 100);
        $evidence['sources'] = array_slice(array_map(function ($source): array {
            $source = is_array($source) ? $source : [];

            return [
                'file' => basename(str_replace('\\', '/', (string) ($source['path'] ?? ''))),
                'row' => is_numeric($source['row'] ?? null) ? (int) $source['row'] : null,
            ];
        }, (array) ($document['provenance'] ?? [])), 0, 20);

        return $evidence;
    }

    private function sourceIdentity(array $document): ?string
    {
        foreach ((array) ($document['provenance'] ?? []) as $source) {
            $identity = trim((string) (is_array($source) ? ($source['source_identity'] ?? '') : ''));
            if (preg_match('/^[a-f0-9]{64}$/', $identity) === 1) {
                return $identity;
            }
        }

        return null;
    }

    private function sourceAmount(array $document): ?float
    {
        if (is_numeric($document['total'] ?? null)) {
            return round((float) $document['total'], 4);
        }
        if (is_numeric($document['base'] ?? null) && is_numeric($document['tax'] ?? null)) {
            return round((float) $document['base'] + (float) $document['tax'], 4);
        }
        if (is_numeric($document['base'] ?? null)) {
            return round((float) $document['base'], 4);
        }
        $lineTotal = array_sum(array_map(fn ($line) => is_array($line) && is_numeric($line['line_base'] ?? null) ? (float) $line['line_base'] : 0.0, (array) ($document['lines'] ?? [])));
        if ($lineTotal > 0) {
            return round($lineTotal, 4);
        }

        return null;
    }

    private function nullableText($value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private function validDate($value): ?string
    {
        $value = substr(trim((string) ($value ?? '')), 0, 10);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $value;
    }

    private function decodeHistory($history): array
    {
        $decoded = json_decode((string) ($history ?? '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function reject(string $message): void
    {
        throw ValidationException::withMessages(['source_finding' => $message]);
    }
}
