<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Jobs\ProcessGeneralLedgerBackfillRetryJob;
use Modules\Accounting\Services\GeneralLedgerBackfillService;

class GeneralLedgerBackfillReviewService
{
    private const REVIEW_TABLE = 'accounting_general_ledger_backfill_reviews';

    private $backfillService;

    public function __construct(GeneralLedgerBackfillService $backfillService)
    {
        $this->backfillService = $backfillService;
    }

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
            ->whereIn('status', ['awaiting_confirmation', 'queued', 'running'])
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

    public function createRetryPreview(int $businessId, int $userId, array $reviewIds): array
    {
        $reviewIds = array_values(array_unique(array_map('intval', $reviewIds)));
        if ($reviewIds === [] || count($reviewIds) > 100 || min($reviewIds) < 1) {
            throw ValidationException::withMessages(['ids' => 'Pilih 1 sampai 100 sumber pada halaman aktif untuk membuat pratinjau.']);
        }

        $previews = $this->backfillService->previewReviewItems($businessId, $reviewIds);
        $eligibleCount = count(array_filter($previews, fn ($item) => ! empty($item['eligible'])));
        $previewData = $this->summarizeRetryPreview($previews);
        $planHash = $this->retryPreviewHash($businessId, $previews);
        $now = now();

        $batchId = DB::transaction(function () use ($businessId, $userId, $previews, $eligibleCount, $previewData, $planHash, $now) {
            $batchId = DB::table('accounting_general_ledger_backfill_retry_batches')->insertGetId([
                'business_id' => $businessId,
                'created_by' => $userId,
                'status' => $eligibleCount > 0 ? 'awaiting_confirmation' : 'empty',
                'selected_items' => count($previews),
                'total_items' => $eligibleCount,
                'planned_debit' => $previewData['debit_total'],
                'planned_credit' => $previewData['credit_total'],
                'plan_hash' => $planHash,
                'preview_data' => json_encode($previewData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'previewed_at' => $now,
                'last_message' => $eligibleCount > 0
                    ? 'Pratinjau siap. Tinjau sumber, CoA, debit, kredit, VAT, dan label sebelum konfirmasi.'
                    : 'Tidak ada sumber terpilih yang dapat diposting dengan mapping saat ini.',
                'finished_at' => $eligibleCount > 0 ? null : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('accounting_general_ledger_backfill_retry_items')->insert(array_map(fn ($item) => [
                    'batch_id' => $batchId,
                    'review_id' => (int) $item['review_id'],
                    'status' => ! empty($item['eligible']) ? 'previewed' : 'excluded',
                    'plan_hash' => $item['plan_hash'] ?? null,
                    'planned_debit' => $item['debit_total'] ?? 0,
                    'planned_credit' => $item['credit_total'] ?? 0,
                    'preview_message' => $item['reason'] ?? null,
                    'preview_data' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $previews));

            return $batchId;
        });

        return [
            'batch_id' => (int) $batchId,
            'selected_items' => count($previews),
            'total_items' => $eligibleCount,
            'planned_debit' => $previewData['debit_total'],
            'planned_credit' => $previewData['credit_total'],
            'plan_hash' => $planHash,
            'preview_data' => $previewData,
            'status' => $eligibleCount > 0 ? 'awaiting_confirmation' : 'empty',
        ];
    }

    public function confirmRetryPreview(int $businessId, int $userId, int $batchId, string $planHash): array
    {
        $pendingPreview = DB::table('accounting_general_ledger_backfill_retry_batches')
            ->where('business_id', $businessId)
            ->where('id', $batchId)
            ->first(['id', 'status', 'plan_hash']);
        if (! $pendingPreview) {
            return ['found' => false];
        }
        if (! hash_equals((string) $pendingPreview->plan_hash, $planHash)) {
            throw ValidationException::withMessages(['plan_hash' => 'Pratinjau berubah atau kedaluwarsa. Buat pratinjau baru.']);
        }
        if ((string) $pendingPreview->status === 'awaiting_confirmation') {
            $reviewIds = DB::table('accounting_general_ledger_backfill_retry_items')
                ->where('batch_id', $batchId)
                ->orderBy('id')
                ->pluck('review_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $currentPreviews = $this->backfillService->previewReviewItems($businessId, $reviewIds);
            if (! hash_equals($planHash, $this->retryPreviewHash($businessId, $currentPreviews))) {
                throw ValidationException::withMessages(['plan_hash' => 'Mapping, sumber, atau label berubah sejak pratinjau. Tinjau pratinjau baru sebelum memproses.']);
            }
        }

        $dispatch = false;
        $result = DB::transaction(function () use ($businessId, $userId, $batchId, $planHash, &$dispatch) {
            $batch = DB::table('accounting_general_ledger_backfill_retry_batches')
                ->where('business_id', $businessId)
                ->where('id', $batchId)
                ->lockForUpdate()
                ->first();
            if (! $batch) {
                return null;
            }
            if (! hash_equals((string) $batch->plan_hash, $planHash)) {
                throw ValidationException::withMessages(['plan_hash' => 'Pratinjau berubah atau kedaluwarsa. Buat pratinjau baru.']);
            }
            if ((string) $batch->status !== 'awaiting_confirmation') {
                return $this->retryBatchSummary($batch, true);
            }

            $items = DB::table('accounting_general_ledger_backfill_retry_items')
                ->where('batch_id', $batchId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $previewData = json_decode((string) $batch->preview_data, true);
            $previewData = is_array($previewData) ? $previewData : ['items' => [], 'account_totals' => []];
            $previewItemsById = collect($previewData['items'] ?? [])->keyBy('review_id');
            $queuedCount = 0;
            $debit = 0.0;
            $credit = 0.0;
            $now = now();

            foreach ($items as $item) {
                if ((string) $item->status !== 'previewed') {
                    continue;
                }
                $review = DB::table(self::REVIEW_TABLE)
                    ->where('business_id', $businessId)
                    ->where('id', $item->review_id)
                    ->lockForUpdate()
                    ->first(['id', 'status']);
                if (! $review || (string) $review->status !== 'pending') {
                    $message = 'Sumber tidak lagi menunggu; dikeluarkan dari batch.';
                    DB::table('accounting_general_ledger_backfill_retry_items')->where('id', $item->id)->update([
                        'status' => 'excluded',
                        'preview_message' => $message,
                        'updated_at' => $now,
                    ]);
                    if ($previewItemsById->has((int) $item->review_id)) {
                        $previewItem = $previewItemsById->get((int) $item->review_id);
                        $previewItem['eligible'] = false;
                        $previewItem['reason'] = $message;
                        $previewItemsById->put((int) $item->review_id, $previewItem);
                    }
                    continue;
                }

                DB::table('accounting_general_ledger_backfill_retry_items')->where('id', $item->id)->update([
                    'status' => 'queued',
                    'updated_at' => $now,
                ]);
                DB::table(self::REVIEW_TABLE)->where('business_id', $businessId)->where('id', $item->review_id)->update([
                    'status' => 'retrying',
                    'retry_batch_id' => $batchId,
                    'updated_at' => $now,
                ]);
                $queuedCount++;
                $debit += (float) $item->planned_debit;
                $credit += (float) $item->planned_credit;
            }

            $previewData['items'] = $previewItemsById->values()->all();
            $previewData['eligible_count'] = $queuedCount;
            $previewData['excluded_count'] = max(0, (int) ($batch->selected_items ?? 0) - $queuedCount);
            $previewData['debit_total'] = round($debit, 4);
            $previewData['credit_total'] = round($credit, 4);
            $previewData['account_totals'] = $this->retryAccountTotals($previewData['items']);
            $status = $queuedCount > 0 ? 'queued' : 'empty';
            DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $batchId)->update([
                'status' => $status,
                'total_items' => $queuedCount,
                'planned_debit' => round($debit, 4),
                'planned_credit' => round($credit, 4),
                'preview_data' => json_encode($previewData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'confirmed_by' => $userId,
                'confirmed_at' => $now,
                'last_message' => $queuedCount > 0
                    ? 'Pratinjau dikonfirmasi. Worker memproses maksimal 25 sumber per chunk.'
                    : 'Tidak ada sumber terpilih yang masih menunggu untuk diproses.',
                'started_at' => $queuedCount > 0 ? null : $batch->started_at,
                'finished_at' => $queuedCount > 0 ? null : $now,
                'updated_at' => $now,
            ]);
            $dispatch = $queuedCount > 0;
            $updatedBatch = DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $batchId)->first();

            return $this->retryBatchSummary($updatedBatch, false);
        });

        if ($result === null) {
            return ['found' => false];
        }
        if ($dispatch) {
            ProcessGeneralLedgerBackfillRetryJob::dispatch($batchId)->onQueue('imports');
        }

        return $result;
    }

    private function summarizeRetryPreview(array $items): array
    {
        $debit = 0.0;
        $credit = 0.0;
        foreach ($items as $item) {
            if (! empty($item['eligible'])) {
                $debit += (float) $item['debit_total'];
                $credit += (float) $item['credit_total'];
            }
        }
        $data = [
            'selected_count' => count($items),
            'eligible_count' => count(array_filter($items, fn ($item) => ! empty($item['eligible']))),
            'excluded_count' => count(array_filter($items, fn ($item) => empty($item['eligible']))),
            'debit_total' => round($debit, 4),
            'credit_total' => round($credit, 4),
            'account_totals' => $this->retryAccountTotals($items),
            'items' => array_values($items),
        ];

        return $data;
    }

    private function retryPreviewHash(int $businessId, array $previews): string
    {
        $planItems = collect($previews)->sortBy('review_id')->map(fn ($item) => [
            'review_id' => (int) $item['review_id'],
            'eligible' => (bool) $item['eligible'],
            'plan_hash' => $item['plan_hash'] ?? null,
            'reason' => $item['reason'] ?? null,
        ])->values()->all();

        return hash('sha256', json_encode([
            'business_id' => $businessId,
            'items' => $planItems,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function retryAccountTotals(array $items): array
    {
        $totals = [];
        foreach ($items as $item) {
            if (empty($item['eligible'])) {
                continue;
            }
            foreach ($item['lines'] ?? [] as $line) {
                $key = (int) $line['accounting_account_id'].':'.(string) $line['type'];
                if (! isset($totals[$key])) {
                    $totals[$key] = [
                        'accounting_account_id' => (int) $line['accounting_account_id'],
                        'account_code' => (string) $line['account_code'],
                        'account_name' => (string) $line['account_name'],
                        'type' => (string) $line['type'],
                        'amount' => 0.0,
                    ];
                }
                $totals[$key]['amount'] = round($totals[$key]['amount'] + (float) $line['amount'], 4);
            }
        }
        ksort($totals);

        return array_values($totals);
    }

    private function retryBatchSummary(object $batch, bool $includePreview): array
    {
        $result = [
            'found' => true,
            'id' => (int) $batch->id,
            'batch_id' => (int) $batch->id,
            'status' => (string) $batch->status,
            'selected_items' => (int) ($batch->selected_items ?? 0),
            'total_items' => (int) $batch->total_items,
            'processed_items' => (int) $batch->processed_items,
            'resolved_items' => (int) $batch->resolved_items,
            'failed_items' => (int) $batch->failed_items,
            'planned_debit' => (float) ($batch->planned_debit ?? 0),
            'planned_credit' => (float) ($batch->planned_credit ?? 0),
            'plan_hash' => (string) ($batch->plan_hash ?? ''),
            'last_message' => (string) ($batch->last_message ?? ''),
            'already_confirmed' => $includePreview,
        ];
        if ($includePreview && (string) $batch->status === 'awaiting_confirmation') {
            $preview = json_decode((string) $batch->preview_data, true);
            $result['preview_data'] = is_array($preview) ? $preview : [];
        }

        return $result;
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
            'selected_items' => (int) ($batch->selected_items ?? 0),
            'total_items' => (int) $batch->total_items,
            'processed_items' => (int) $batch->processed_items,
            'resolved_items' => (int) $batch->resolved_items,
            'failed_items' => (int) $batch->failed_items,
            'last_message' => (string) ($batch->last_message ?? ''),
            'planned_debit' => (float) ($batch->planned_debit ?? 0),
            'planned_credit' => (float) ($batch->planned_credit ?? 0),
            'plan_hash' => (string) ($batch->plan_hash ?? ''),
            'preview_data' => (string) $batch->status === 'awaiting_confirmation'
                ? (json_decode((string) $batch->preview_data, true) ?: [])
                : null,
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
