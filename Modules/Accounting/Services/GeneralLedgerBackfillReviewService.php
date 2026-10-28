<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Jobs\ProcessGeneralLedgerBackfillRetryJob;

class GeneralLedgerBackfillReviewService
{
    private const REVIEW_TABLE = 'accounting_general_ledger_backfill_reviews';

    public function listItems(int $businessId, array $filters): array
    {
        $query = DB::table(self::REVIEW_TABLE)->where('business_id', $businessId);
        $query->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']));
        $query->when(! empty($filters['reason']), fn ($q) => $q->where('skip_reason', $filters['reason']));
        $query->when(! empty($filters['source_type']), fn ($q) => $q->where('source_record_type', $filters['source_type']));
        $query->when(! empty($filters['location_id']), fn ($q) => $q->where('location_id', (int) $filters['location_id']));
        $query->when(! empty($filters['mapping_key']), fn ($q) => $q->where('mapping_key', $filters['mapping_key']));
        $query->when(! empty($filters['start_date']), fn ($q) => $q->whereDate('operation_date', '>=', $filters['start_date']));
        $query->when(! empty($filters['end_date']), fn ($q) => $q->whereDate('operation_date', '<=', $filters['end_date']));

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($like, $search) {
                $q->where('source_summary', 'like', $like)
                    ->orWhere('source_reference', 'like', $like)
                    ->orWhere('skip_reason', 'like', $like)
                    ->orWhere('source_record_type', 'like', $like);
                if (ctype_digit($search)) {
                    $q->orWhere('source_record_id', (int) $search);
                }
            });
        }

        $total = (clone $query)->distinct()->count(self::REVIEW_TABLE.'.id');
        $uniqueTotal = DB::table(self::REVIEW_TABLE)->where('business_id', $businessId)->distinct()->count('id');
        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $rows = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        return [
            'data' => $rows->map(function ($row) {
                $context = json_decode((string) ($row->source_context ?? '[]'), true);
                $context = is_array($context) ? $context : [];
                $amount = $context['final_total'] ?? $context['amount'] ?? null;

                return [
                    'id' => (int) $row->id,
                    'source_type' => class_basename((string) $row->source_record_type),
                    'source_record_type' => (string) $row->source_record_type,
                    'source_record_id' => (int) $row->source_record_id,
                    'source_reference' => (string) ($row->source_reference ?? ''),
                    'operation_date' => $row->operation_date,
                    'source_summary' => (string) $row->source_summary,
                    'amount' => $amount === null ? null : (float) $amount,
                    'skip_reason' => (string) $row->skip_reason,
                    'status' => (string) $row->status,
                    'location_id' => $row->location_id === null ? null : (int) $row->location_id,
                    'mapping_key' => $row->mapping_key,
                    'main_account_id' => $row->main_account_id === null ? null : (int) $row->main_account_id,
                    'retry_batch_id' => $row->retry_batch_id === null ? null : (int) $row->retry_batch_id,
                    'source_context' => $context,
                ];
            })->values()->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'unique_total' => $uniqueTotal,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function mappingOptions(int $businessId): array
    {
        $groups = DB::table(self::REVIEW_TABLE.' as review')
            ->leftJoin('business_locations as location', function ($join) use ($businessId) {
                $join->on('location.id', '=', 'review.location_id')->where('location.business_id', '=', $businessId);
            })
            ->where('review.business_id', $businessId)
            ->where('review.status', 'pending')
            ->whereNotNull('review.location_id')
            ->whereNotNull('review.mapping_key')
            ->select('review.location_id', 'review.mapping_key', 'location.name as location_name')
            ->selectRaw('COUNT(DISTINCT review.id) as pending_count')
            ->groupBy('review.location_id', 'review.mapping_key', 'location.name')
            ->orderBy('location.name')
            ->orderBy('review.mapping_key')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'location_id' => (int) $row->location_id,
                'location_name' => (string) ($row->location_name ?: 'Lokasi #'.$row->location_id),
                'mapping_key' => (string) $row->mapping_key,
                'label' => ($row->location_name ?: 'Lokasi #'.$row->location_id).' — '.$this->mappingLabel((string) $row->mapping_key),
                'pending_count' => (int) $row->pending_count,
            ])
            ->all();
        $locationIds = array_values(array_unique(array_column($groups, 'location_id')));
        $locationMaps = $locationIds === [] ? [] : DB::table('business_locations')
            ->where('business_id', $businessId)
            ->whereIn('id', $locationIds)
            ->get(['id', 'accounting_default_map'])
            ->mapWithKeys(function ($location) {
                $mapping = json_decode((string) $location->accounting_default_map, true);

                return [(int) $location->id => is_array($mapping) ? $mapping : []];
            })
            ->all();
        foreach ($groups as &$group) {
            $map = $locationMaps[$group['location_id']][$group['mapping_key']] ?? [];
            $group['current_payment_account_id'] = isset($map['payment_account']) ? (int) $map['payment_account'] : null;
            $group['current_deposit_to_id'] = isset($map['deposit_to']) ? (int) $map['deposit_to'] : null;
        }
        unset($group);

        $mainAccountRefs = DB::table(self::REVIEW_TABLE)
            ->where('business_id', $businessId)
            ->where('status', 'pending')
            ->whereNotNull('main_account_id')
            ->selectRaw('main_account_id as source_account_id, id as review_id')
            ->union(DB::table(self::REVIEW_TABLE)
                ->where('business_id', $businessId)
                ->where('status', 'pending')
                ->whereNotNull('counterpart_main_account_id')
                ->selectRaw('counterpart_main_account_id as source_account_id, id as review_id'));
        $mainAccounts = DB::query()
            ->fromSub($mainAccountRefs, 'source_accounts')
            ->join('accounts', function ($join) use ($businessId) {
                $join->on('accounts.id', '=', 'source_accounts.source_account_id')->where('accounts.business_id', '=', $businessId);
            })
            ->when(Schema::hasColumn('accounts', 'deleted_at'), fn ($query) => $query->whereNull('accounts.deleted_at'))
            ->groupBy('accounts.id', 'accounts.name')
            ->orderBy('accounts.name')
            ->select('accounts.id', 'accounts.name')
            ->selectRaw('COUNT(DISTINCT source_accounts.review_id) as pending_count')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'pending_count' => (int) $row->pending_count,
            ])
            ->all();

        $vatMappings = Schema::hasTable('accounting_general_ledger_vat_accounts')
            ? DB::table('accounting_general_ledger_vat_accounts')
                ->where('business_id', $businessId)
                ->pluck('accounting_account_id', 'direction')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $reasons = DB::table(self::REVIEW_TABLE)->where('business_id', $businessId)->distinct()->orderBy('skip_reason')->pluck('skip_reason')->all();
        $sourceTypes = DB::table(self::REVIEW_TABLE)->where('business_id', $businessId)->distinct()->orderBy('source_record_type')->pluck('source_record_type')->map(fn ($type) => [
            'value' => (string) $type,
            'label' => class_basename((string) $type),
        ])->all();
        $activeBatch = DB::table('accounting_general_ledger_backfill_retry_batches')
            ->where('business_id', $businessId)
            ->whereIn('status', ['queued', 'running'])
            ->orderByDesc('id')
            ->first(['id', 'status', 'total_items', 'processed_items', 'resolved_items', 'failed_items', 'last_message']);

        return [
            'groups' => $groups,
            'main_accounts' => $mainAccounts,
            'reasons' => $reasons,
            'source_types' => $sourceTypes,
            'active_batch' => $activeBatch ? [
                'id' => (int) $activeBatch->id,
                'status' => (string) $activeBatch->status,
                'total_items' => (int) $activeBatch->total_items,
                'processed_items' => (int) $activeBatch->processed_items,
                'resolved_items' => (int) $activeBatch->resolved_items,
                'failed_items' => (int) $activeBatch->failed_items,
                'last_message' => (string) ($activeBatch->last_message ?? ''),
            ] : null,
            'accounts' => DB::table('accounting_accounts')
                ->where('business_id', $businessId)
                ->where('status', 'active')
                ->orderBy('gl_code')
                ->orderBy('name')
                ->get(['id', 'gl_code', 'name', 'account_primary_type'])
                ->map(fn ($account) => [
                    'id' => (int) $account->id,
                    'code' => (string) ($account->gl_code ?? ''),
                    'name' => (string) $account->name,
                    'type' => (string) ($account->account_primary_type ?? ''),
                ])
                ->all(),
            'vat_mappings' => $vatMappings,
        ];
    }

    public function saveLocationMapping(int $businessId, int $userId, array $data): int
    {
        $locationId = (int) $data['location_id'];
        $mappingKey = (string) $data['mapping_key'];
        if (! preg_match('/^(sale|purchases|expense(?:_\d+)?|sell_payment|purchase_payment)$/', $mappingKey)) {
            throw ValidationException::withMessages(['mapping_key' => 'Jenis mapping tidak dikenali.']);
        }

        $paymentAccountId = empty($data['payment_account_id']) ? null : (int) $data['payment_account_id'];
        $depositToId = empty($data['deposit_to_id']) ? null : (int) $data['deposit_to_id'];
        $this->assertAccountsBelongToBusiness($businessId, array_filter([$paymentAccountId, $depositToId]));
        if (in_array($mappingKey, ['sell_payment', 'purchase_payment'], true)) {
            $hasCounterpart = $mappingKey === 'sell_payment' ? $paymentAccountId : $depositToId;
            if (! $hasCounterpart) {
                throw ValidationException::withMessages(['payment_account_id' => 'Pilih akun lawan untuk mapping pembayaran.']);
            }
            if ($paymentAccountId && $depositToId && $paymentAccountId === $depositToId) {
                throw ValidationException::withMessages(['deposit_to_id' => 'Akun lawan harus berbeda.']);
            }
        } elseif (! $paymentAccountId || ! $depositToId || $paymentAccountId === $depositToId) {
            throw ValidationException::withMessages(['deposit_to_id' => 'Pilih dua akun CoA berbeda untuk pasangan transaksi.']);
        }

        DB::transaction(function () use ($businessId, $locationId, $mappingKey, $paymentAccountId, $depositToId) {
            $location = DB::table('business_locations')
                ->where('business_id', $businessId)
                ->where('id', $locationId)
                ->lockForUpdate()
                ->first(['id', 'accounting_default_map']);
            if (! $location) {
                throw ValidationException::withMessages(['location_id' => 'Lokasi tidak termasuk business aktif.']);
            }
            $map = json_decode((string) $location->accounting_default_map, true);
            $map = is_array($map) ? $map : [];
            $current = is_array($map[$mappingKey] ?? null) ? $map[$mappingKey] : [];
            if ($mappingKey === 'sell_payment') {
                $current['payment_account'] = $paymentAccountId;
            } elseif ($mappingKey === 'purchase_payment') {
                $current['deposit_to'] = $depositToId;
            } else {
                $current['payment_account'] = $paymentAccountId;
                $current['deposit_to'] = $depositToId;
            }
            $map[$mappingKey] = $current;
            DB::table('business_locations')->where('id', $locationId)->update([
                'accounting_default_map' => json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        });

        return $this->pendingCountForLocationGroup($businessId, $locationId, $mappingKey);
    }

    public function saveVatMapping(int $businessId, int $userId, string $direction, int $accountId): int
    {
        if (! in_array($direction, ['input', 'output'], true)) {
            throw ValidationException::withMessages(['direction' => 'Pilih VAT In atau VAT Out.']);
        }
        $this->assertAccountsBelongToBusiness($businessId, [$accountId]);
        DB::transaction(function () use ($businessId, $userId, $direction, $accountId) {
            $existing = DB::table('accounting_general_ledger_vat_accounts')
                ->where('business_id', $businessId)
                ->where('direction', $direction)
                ->lockForUpdate()
                ->first(['id']);
            $values = ['accounting_account_id' => $accountId, 'updated_by' => $userId, 'updated_at' => now()];
            if ($existing) {
                DB::table('accounting_general_ledger_vat_accounts')->where('id', $existing->id)->update($values);
            } else {
                DB::table('accounting_general_ledger_vat_accounts')->insert($values + [
                    'business_id' => $businessId,
                    'direction' => $direction,
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);
            }
        });

        $kinds = $direction === 'input' ? ['purchase', 'expense'] : ['sell'];

        return DB::table(self::REVIEW_TABLE)
            ->where('business_id', $businessId)
            ->where('status', 'pending')
            ->whereIn('skip_reason', ['akun_vat_tidak_ditemukan', 'akun_vat_ambigu'])
            ->whereIn('transaction_kind', $kinds)
            ->count();
    }

    public function saveMainAccountMapping(int $businessId, int $userId, int $mainAccountId, int $accountingAccountId): int
    {
        if (! DB::table('accounts')->where('business_id', $businessId)->where('id', $mainAccountId)
            ->when(Schema::hasColumn('accounts', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->exists()) {
            throw ValidationException::withMessages(['main_account_id' => 'Akun sumber tidak termasuk business aktif.']);
        }
        $this->assertAccountsBelongToBusiness($businessId, [$accountingAccountId]);

        return DB::transaction(function () use ($businessId, $mainAccountId, $accountingAccountId) {
            $account = DB::table('accounting_accounts')
                ->where('business_id', $businessId)
                ->where('id', $accountingAccountId)
                ->lockForUpdate()
                ->first(['id', 'account_id']);
            if (! $account) {
                throw ValidationException::withMessages(['accounting_account_id' => 'CoA tidak termasuk business aktif.']);
            }
            if ($account->account_id !== null && (int) $account->account_id !== $mainAccountId) {
                throw ValidationException::withMessages(['accounting_account_id' => 'CoA tersebut sudah terhubung ke akun sumber lain.']);
            }
            $crosswalk = DB::table('accounting_accounts')
                ->where('business_id', $businessId)
                ->where('account_id', $mainAccountId)
                ->where('id', '<>', $accountingAccountId)
                ->exists();
            if ($crosswalk) {
                throw ValidationException::withMessages(['main_account_id' => 'Akun sumber ini sudah terhubung ke CoA lain.']);
            }
            DB::table('accounting_accounts')->where('id', $accountingAccountId)->update([
                'account_id' => $mainAccountId,
                'updated_at' => now(),
            ]);

            return DB::table(self::REVIEW_TABLE)
                ->where('business_id', $businessId)
                ->where(function ($query) use ($mainAccountId) {
                    $query->where('main_account_id', $mainAccountId)->orWhere('counterpart_main_account_id', $mainAccountId);
                })
                ->where('status', 'pending')
                ->count();
        });
    }

    public function pendingCountForLocationGroup(int $businessId, int $locationId, string $mappingKey): int
    {
        return DB::table(self::REVIEW_TABLE)
            ->where('business_id', $businessId)
            ->where('status', 'pending')
            ->where('location_id', $locationId)
            ->where('mapping_key', $mappingKey)
            ->count();
    }

    public function pendingCountForMainAccount(int $businessId, int $mainAccountId): int
    {
        return DB::table(self::REVIEW_TABLE)
            ->where('business_id', $businessId)
            ->where('status', 'pending')
            ->where(function ($query) use ($mainAccountId) {
                $query->where('main_account_id', $mainAccountId)->orWhere('counterpart_main_account_id', $mainAccountId);
            })
            ->count();
    }

    public function pendingCountForVatDirection(int $businessId, string $direction): int
    {
        $kinds = $direction === 'input' ? ['purchase', 'expense'] : ['sell'];

        return DB::table(self::REVIEW_TABLE)
            ->where('business_id', $businessId)
            ->where('status', 'pending')
            ->whereIn('skip_reason', ['akun_vat_tidak_ditemukan', 'akun_vat_ambigu'])
            ->whereIn('transaction_kind', $kinds)
            ->count();
    }

    public function enqueueRetry(int $businessId, int $userId, array $scope): array
    {
        $query = DB::table(self::REVIEW_TABLE)->where('business_id', $businessId)->where('status', 'pending');
        switch ($scope['type']) {
            case 'location':
                $query->where('location_id', (int) $scope['location_id'])->where('mapping_key', (string) $scope['mapping_key']);
                break;
            case 'main_account':
                $mainAccountId = (int) $scope['main_account_id'];
                $query->where(function ($q) use ($mainAccountId) {
                    $q->where('main_account_id', $mainAccountId)->orWhere('counterpart_main_account_id', $mainAccountId);
                });
                break;
            case 'vat':
                $query->whereIn('skip_reason', ['akun_vat_tidak_ditemukan', 'akun_vat_ambigu'])
                    ->whereIn('transaction_kind', $scope['direction'] === 'input' ? ['purchase', 'expense'] : ['sell']);
                break;
            default:
                throw ValidationException::withMessages(['type' => 'Cakupan retry tidak dikenali.']);
        }

        $batchId = null;
        $count = 0;
        DB::transaction(function () use ($query, $businessId, $userId, &$batchId, &$count) {
            $batchId = DB::table('accounting_general_ledger_backfill_retry_batches')->insertGetId([
                'business_id' => $businessId,
                'created_by' => $userId,
                'status' => 'queued',
                'last_message' => 'Menunggu worker untuk memproses antrean dalam chunk kecil.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $query->select('id')->orderBy('id')->chunkById(250, function ($rows) use ($batchId, &$count) {
                $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
                $eligible = DB::table(self::REVIEW_TABLE)
                    ->whereIn('id', $ids)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                if ($eligible === []) {
                    return;
                }
                $now = now();
                DB::table('accounting_general_ledger_backfill_retry_items')->insert(array_map(fn ($id) => [
                    'batch_id' => $batchId,
                    'review_id' => $id,
                    'status' => 'queued',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $eligible));
                DB::table(self::REVIEW_TABLE)->whereIn('id', $eligible)->where('status', 'pending')->update([
                    'status' => 'retrying',
                    'retry_batch_id' => $batchId,
                    'updated_at' => $now,
                ]);
                $count += count($eligible);
            });

            DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $batchId)->update([
                'total_items' => $count,
                'status' => $count > 0 ? 'queued' : 'empty',
                'last_message' => $count > 0 ? 'Worker memproses maksimal 25 sumber per chunk.' : 'Tidak ada item pending yang cocok dengan mapping ini.',
                'finished_at' => $count > 0 ? null : now(),
                'updated_at' => now(),
            ]);
        });

        if ($count > 0) {
            ProcessGeneralLedgerBackfillRetryJob::dispatch($batchId)->onQueue('imports');
        }

        return ['batch_id' => (int) $batchId, 'total_items' => $count, 'status' => $count > 0 ? 'queued' : 'empty'];
    }

    public function retryBatchStatus(int $businessId, int $batchId): ?array
    {
        $batch = DB::table('accounting_general_ledger_backfill_retry_batches')
            ->where('business_id', $businessId)
            ->where('id', $batchId)
            ->first();
        if (! $batch) {
            return null;
        }

        return [
            'id' => (int) $batch->id,
            'status' => (string) $batch->status,
            'total_items' => (int) $batch->total_items,
            'processed_items' => (int) $batch->processed_items,
            'resolved_items' => (int) $batch->resolved_items,
            'failed_items' => (int) $batch->failed_items,
            'last_message' => (string) ($batch->last_message ?? ''),
        ];
    }

    private function assertAccountsBelongToBusiness(int $businessId, array $accountIds): void
    {
        $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
        if ($accountIds === []) {
            return;
        }
        $valid = DB::table('accounting_accounts')
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->whereIn('id', $accountIds)
            ->count();
        if ($valid !== count($accountIds)) {
            throw ValidationException::withMessages(['accounting_account_id' => 'Semua CoA harus aktif dan milik business saat ini.']);
        }
    }

    private function mappingLabel(string $key): string
    {
        if ($key === 'sale') {
            return 'Penjualan';
        }
        if ($key === 'purchases') {
            return 'Pembelian';
        }
        if ($key === 'expense') {
            return 'Biaya';
        }
        if (str_starts_with($key, 'expense_')) {
            return 'Biaya kategori #'.substr($key, 8);
        }
        if ($key === 'sell_payment') {
            return 'Penerimaan pembayaran';
        }
        if ($key === 'purchase_payment') {
            return 'Pembayaran pembelian';
        }

        return $key;
    }
}
