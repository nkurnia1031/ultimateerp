<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalImportGroupedReviewService
{
    private const ROW_CHUNK_SIZE = 1000;

    public function __construct(private JournalImportDraftService $draftService)
    {
    }

    public function summarize(object $batch, int $businessId, string $sourceType): array
    {
        $groups = [];
        $defaultLocationId = $this->draftService->defaultActiveLocationId($businessId);
        $defaultLocation = $defaultLocationId === null ? null : DB::table('business_locations')
            ->where('business_id', $businessId)
            ->where('id', $defaultLocationId)
            ->value('name');

        $this->pendingRows($batch, $businessId, $sourceType)->chunkById(self::ROW_CHUNK_SIZE, function ($rows) use (&$groups, $businessId, $defaultLocationId, $defaultLocation) {
            foreach ($rows as $row) {
                $context = $this->classify($row, $businessId);
                $key = $this->groupKey($context);
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'group_key' => $key,
                        'group_context' => $this->encodeContext($context),
                        'source_type' => $context['source_type'],
                        'source_action' => $context['source_action'],
                        'blocking_reason' => $context['reason'],
                        'missing_field' => $context['missing_field'],
                        'missing_field_label' => $this->fieldLabel($context['missing_field']),
                        'next_action' => $this->nextAction($context),
                        'suggested_direction' => $context['suggested_direction'],
                        'row_count' => 0,
                        'can_set_default_location' => $defaultLocationId !== null
                            && $context['missing_field'] === 'location_id'
                            && $context['source_action'] === 'create',
                        'default_location' => $defaultLocationId === null ? null : [
                            'id' => $defaultLocationId,
                            'name' => (string) ($defaultLocation ?: 'Lokasi aktif default'),
                        ],
                    ];
                }

                $groups[$key]['row_count']++;
                $draftData = json_decode((string) $row->draft_data, true) ?: [];
                if ($context['missing_field'] === 'location_id'
                    && $context['source_action'] === 'create'
                    && ! empty($draftData['location_id'])
                    && (int) ($draftData['location_id'] ?? 0) !== (int) $defaultLocationId) {
                    $groups[$key]['can_set_default_location'] = false;
                }
            }
        }, 'id');

        $groups = array_values($groups);
        foreach ($groups as &$group) {
            $group['bulk_actions'] = [];
            if (! empty($group['can_set_default_location'])) {
                $group['bulk_actions'][] = [
                    'field' => 'location_id',
                    'value' => (string) $group['default_location']['id'],
                    'label' => 'Isi lokasi: '.$group['default_location']['name'],
                ];
            }
            if ($group['source_type'] === 'Stock In/Out'
                && $group['source_action'] === 'create'
                && in_array($group['suggested_direction'], ['in', 'out'], true)) {
                $group['bulk_actions'][] = [
                    'field' => 'movement_direction',
                    'value' => $group['suggested_direction'],
                    'label' => 'Terapkan arah dari akun persediaan: '.($group['suggested_direction'] === 'in' ? 'Masuk' : 'Keluar'),
                ];
            }
            unset($group['can_set_default_location'], $group['default_location']);
        }
        unset($group);

        usort($groups, fn (array $left, array $right): int =>
            [$left['source_type'], $left['missing_field'], $left['blocking_reason']] <=> [$right['source_type'], $right['missing_field'], $right['blocking_reason']]
        );

        return [
            'groups' => $groups,
            'total_groups' => count($groups),
            'total_rows' => array_sum(array_column($groups, 'row_count')),
        ];
    }

    public function decodeContext(string $token, ?string $expectedGroupKey = null): array
    {
        $padding = (4 - (strlen($token) % 4)) % 4;
        $decoded = base64_decode(strtr($token.str_repeat('=', $padding), '-_', '+/'), true);
        $context = $decoded === false ? null : json_decode($decoded, true);
        if (! is_array($context)
            || ! in_array($context['source_type'] ?? null, app(JournalImportDraftService::class)->sourceTypes(), true)
            || ! in_array($context['source_action'] ?? null, ['create', 'update', 'link', 'review', 'journal'], true)
            || ! in_array($context['reason_source'] ?? null, ['message', 'error'], true)
            || ! is_string($context['reason'] ?? null)
            || mb_strlen((string) $context['reason']) > 2000
            || ! is_string($context['missing_field'] ?? null)
            || ! in_array($context['missing_field'], ['location_id', 'movement_direction', 'payment_account_id', 'payment_method', 'contact_id', 'invoice_reference', 'expense_category_id', 'reversal_target_id', 'details', 'source_decision', 'journal_lines', 'handler', 'other'], true)
            || ! in_array($context['suggested_direction'] ?? null, [null, 'in', 'out'], true)) {
            throw ValidationException::withMessages(['group_context' => 'Konteks kelompok tidak valid. Muat ulang ringkasan kelompok.']);
        }

        $context = $this->canonicalContext($context);
        if ($expectedGroupKey !== null && ! hash_equals($this->groupKey($context), $expectedGroupKey)) {
            throw ValidationException::withMessages(['group_key' => 'Kelompok berubah sejak ditampilkan. Muat ulang ringkasan kelompok.']);
        }

        return $context;
    }

    public function matchingRowIds(object $batch, int $businessId, array $context): array
    {
        $ids = [];
        $this->pendingRows($batch, $businessId, $context['source_type'])
            ->where('source_action', $context['source_action'])
            ->chunkById(self::ROW_CHUNK_SIZE, function ($rows) use (&$ids, $businessId, $context) {
                foreach ($rows as $row) {
                    if ($this->groupKey($this->classify($row, $businessId)) === $this->groupKey($context)) {
                        $ids[] = (int) $row->id;
                    }
                }
            }, 'id');

        return $ids;
    }

    public function applyGroupValue(object $batch, int $businessId, array $context, string $field, string $value, array $rowIds): int
    {
        if ($rowIds === [] || count($rowIds) > 50000) {
            throw ValidationException::withMessages(['group_key' => 'Kelompok kosong atau terlalu besar untuk diproses. Persempit dengan jenis/baris masalah.']);
        }

        $locationId = null;
        if ($field === 'location_id') {
            if ($context['missing_field'] !== 'location_id' || $context['source_action'] !== 'create'
                || ! ctype_digit($value) || (int) $value < 1) {
                throw ValidationException::withMessages(['field' => 'Lokasi hanya dapat diterapkan ke kelompok Buat baru yang menunggu lokasi.']);
            }
            $locationId = DB::table('business_locations')
                ->where('business_id', $businessId)
                ->where('id', (int) $value)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->value('id');
            if ($locationId === null) {
                throw ValidationException::withMessages(['value' => 'Pilih lokasi aktif dari business ini.']);
            }
        } elseif ($field === 'movement_direction') {
            if ($context['source_type'] !== 'Stock In/Out'
                || ! in_array($context['suggested_direction'], ['in', 'out'], true)
                || $value !== $context['suggested_direction']) {
                throw ValidationException::withMessages(['field' => 'Arah hanya dapat diterapkan jika baris Journal secara konsisten membuktikan arah masuk/keluar.']);
            }
        } else {
            throw ValidationException::withMessages(['field' => 'Field ini memerlukan pemeriksaan per baris atau pemetaan khusus.']);
        }

        foreach (array_chunk($rowIds, 250) as $rowIdChunk) {
            $rows = DB::table('accounting_journal_import_rows')
                ->where('journal_import_id', $batch->id)
                ->where('business_id', $businessId)
                ->whereIn('id', $rowIdChunk)
                ->where('source_type', $context['source_type'])
                ->where('source_action', $context['source_action'])
                ->whereIn('processing_status', ['pending', 'failed'])
                ->orderBy('id')
                ->get([
                    'id', 'source_type', 'source_action', 'source_record_type', 'source_record_id',
                    'entry_data', 'draft_data', 'processing_status', 'processing_message', 'processing_error',
                ]);
            if ($rows->count() !== count($rowIdChunk)) {
                throw ValidationException::withMessages(['group_key' => 'Sebagian baris sudah berubah atau sedang diproses. Muat ulang kelompok.']);
            }
            foreach ($rows as $row) {
                if ($this->groupKey($this->classify($row, $businessId)) !== $this->groupKey($context)) {
                    throw ValidationException::withMessages(['group_key' => 'Isi kelompok berubah sejak preview. Muat ulang kelompok.']);
                }
                $draft = json_decode((string) $row->draft_data, true) ?: [];
                $currentValue = $draft[$field] ?? null;
                if (! empty($currentValue) && (string) $currentValue !== $value) {
                    throw ValidationException::withMessages(['group_key' => 'Sebagian baris memiliki nilai yang berbeda; kelompok tidak dapat ditimpa massal.']);
                }
            }
        }

        $updated = 0;
        foreach (array_chunk($rowIds, 250) as $rowIdChunk) {
            $updated += DB::transaction(function () use ($batch, $businessId, $context, $field, $value, $locationId, $rowIdChunk) {
                $rows = DB::table('accounting_journal_import_rows')
                    ->where('journal_import_id', $batch->id)
                    ->where('business_id', $businessId)
                    ->whereIn('id', $rowIdChunk)
                    ->where('source_type', $context['source_type'])
                    ->where('source_action', $context['source_action'])
                    ->whereIn('processing_status', ['pending', 'failed'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($rows->count() !== count($rowIdChunk)) {
                    throw ValidationException::withMessages(['group_key' => 'Sebagian baris sudah berubah atau sedang diproses. Muat ulang kelompok.']);
                }

                foreach ($rows as $row) {
                    $draft = json_decode((string) $row->draft_data, true) ?: [];
                    $currentValue = $draft[$field] ?? null;
                    if ($this->groupKey($this->classify($row, $businessId)) !== $this->groupKey($context)) {
                        throw ValidationException::withMessages(['group_key' => 'Isi kelompok berubah saat pemrosesan. Muat ulang kelompok.']);
                    }
                    if ($field === 'location_id') {
                        if (! empty($currentValue) && (int) $currentValue !== (int) $locationId) {
                            throw ValidationException::withMessages(['group_key' => 'Sebagian baris memiliki lokasi berbeda; kelompok tidak dapat ditimpa massal.']);
                        }
                        $draft[$field] = (int) $locationId;
                    } else {
                        if (! empty($currentValue) && (string) $currentValue !== $value) {
                            throw ValidationException::withMessages(['group_key' => 'Sebagian baris memiliki arah stok berbeda; kelompok tidak dapat ditimpa massal.']);
                        }
                        $draft[$field] = $value;
                    }

                    DB::table('accounting_journal_import_rows')
                        ->where('id', $row->id)
                        ->where('journal_import_id', $batch->id)
                        ->where('business_id', $businessId)
                        ->whereIn('processing_status', ['pending', 'failed'])
                        ->update([
                            'draft_data' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'is_reviewed' => 1,
                            'updated_at' => now(),
                        ]);
                }

                return $rows->count();
            });
        }

        return $updated;
    }

    private function pendingRows(object $batch, int $businessId, ?string $sourceType = null)
    {
        $query = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $businessId)
            ->whereIn('processing_status', ['pending', 'failed']);
        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        return $query->orderBy('id')->select([
            'id', 'source_type', 'source_action', 'source_record_type', 'source_record_id',
            'entry_data', 'draft_data', 'processing_status', 'processing_message', 'processing_error',
        ]);
    }

    private function classify(object $row, int $businessId): array
    {
        $reasonSource = trim((string) ($row->processing_error ?? '')) !== '' ? 'error' : 'message';
        $reasonColumn = $reasonSource === 'error' ? 'processing_error' : 'processing_message';
        $reason = trim((string) ($row->{$reasonColumn} ?? ''));
        if ($reason === '') {
            $reason = 'Baris masih menunggu kelengkapan atau handler transaksi.';
        }
        $lower = mb_strtolower($reason);
        $missingField = $this->missingField($lower, (string) $row->source_type, (string) $row->source_action);
        $entry = json_decode((string) $row->entry_data, true) ?: [];
        $draft = json_decode((string) ($row->draft_data ?? ''), true) ?: [];
        $direction = null;
        if ($row->source_type === 'Stock In/Out' && empty($draft['movement_direction'])) {
            $direction = $this->inferStockDirection($entry);
        }

        return $this->canonicalContext([
            'source_type' => (string) $row->source_type,
            'source_action' => (string) $row->source_action,
            'reason_source' => $reasonSource,
            'reason' => $reason,
            'missing_field' => $missingField,
            'suggested_direction' => $direction,
        ]);
    }

    private function missingField(string $reason, string $sourceType, string $sourceAction): string
    {
        if (preg_match('/lokasi|gudang/u', $reason)) return 'location_id';
        if (preg_match('/arah.{0,30}stok|pergerakan stok/u', $reason)) return 'movement_direction';
        if (preg_match('/akun pembayaran|akun bank|payment account/u', $reason)) return 'payment_account_id';
        if (preg_match('/metode pembayaran|payment method/u', $reason)) return 'payment_method';
        if (preg_match('/customer|supplier|kontak/u', $reason)) return 'contact_id';
        if (preg_match('/referensi invoice|invoice.*(pilih|lengkapi|wajib)|tagihan terkait/u', $reason)) return 'invoice_reference';
        if (preg_match('/kategori biaya|kategori akun/u', $reason)) return 'expense_category_id';
        if (preg_match('/reversal|target transaksi/u', $reason)) return 'reversal_target_id';
        if (preg_match('/produk|item|qty|kuantitas|satuan|unit|harga|detail transaksi|detail stok/u', $reason)) return 'details';
        if (preg_match('/handler transaksi/u', $reason)) return 'handler';
        if (preg_match('/akun gl|baris akun|debit dan kredit|kunci sumber|posting journal/u', $reason)) return 'journal_lines';
        if ($sourceType === 'Journal Entry') return 'journal_lines';
        if ($sourceType !== 'Journal Entry' && $sourceAction === 'review') return 'source_decision';

        return 'other';
    }

    private function inferStockDirection(array $entry): ?string
    {
        $inventory = [];
        foreach (($entry['lines'] ?? []) as $line) {
            if (! is_array($line)) continue;
            $label = mb_strtolower(trim((string) ($line['account_no'] ?? '').' '.(string) ($line['account_name'] ?? '')));
            if (! preg_match('/(?:^|[^a-z0-9])(inventory|inventories|persediaan|stock)(?:[^a-z0-9]|$)/u', $label)) continue;
            $side = strtolower(trim((string) ($line['type'] ?? '')));
            if (in_array($side, ['debit', 'credit'], true)) {
                $amount = (float) ($line[$side] ?? $line['amount'] ?? 0);
                $debit = $side === 'debit' ? $amount : 0.0;
                $credit = $side === 'credit' ? $amount : 0.0;
            } else {
                $debit = (float) ($line['debit'] ?? 0);
                $credit = (float) ($line['credit'] ?? 0);
            }
            $key = $label;
            if (! isset($inventory[$key])) $inventory[$key] = ['debit' => 0.0, 'credit' => 0.0];
            if (is_finite($debit) && $debit > 0) $inventory[$key]['debit'] += $debit;
            if (is_finite($credit) && $credit > 0) $inventory[$key]['credit'] += $credit;
        }

        if ($inventory === []) return null;
        $directions = [];
        foreach ($inventory as $amounts) {
            if ($amounts['debit'] > 0 && $amounts['credit'] <= 0) $directions['in'] = true;
            elseif ($amounts['credit'] > 0 && $amounts['debit'] <= 0) $directions['out'] = true;
            else return null;
        }

        return count($directions) === 1 ? array_key_first($directions) : null;
    }

    private function fieldLabel(string $field): string
    {
        return [
            'location_id' => 'Lokasi/gudang',
            'movement_direction' => 'Arah stok',
            'payment_account_id' => 'Akun pembayaran',
            'payment_method' => 'Metode pembayaran',
            'contact_id' => 'Customer/supplier',
            'invoice_reference' => 'Invoice terkait',
            'expense_category_id' => 'Kategori biaya',
            'reversal_target_id' => 'Transaksi reversal',
            'details' => 'Detail produk, qty, satuan, dan harga',
            'source_decision' => 'Keputusan sumber',
            'journal_lines' => 'Baris akun Journal',
            'handler' => 'Handler transaksi',
        ][$field] ?? 'Informasi transaksi';
    }

    private function nextAction(array $context): string
    {
        if ($context['missing_field'] === 'details') return 'Lengkapi produk, kuantitas, satuan, dan harga per baris; data ini tidak disalin massal.';
        if ($context['missing_field'] === 'source_decision') return 'Pilih kandidat sumber pada tiap baris atau pilih Buat baru secara eksplisit.';
        if ($context['missing_field'] === 'movement_direction' && $context['suggested_direction'] === null) return 'Periksa baris akun persediaan; arah tidak dapat disimpulkan dengan aman.';
        if ($context['missing_field'] === 'payment_account_id') return 'Pilih akun pembayaran business ini; gunakan Mapping berulang massal bila konteks akun sama.';
        if ($context['missing_field'] === 'contact_id') return 'Pilih customer/supplier yang benar per transaksi.';
        if ($context['missing_field'] === 'journal_lines') return 'Periksa nomor/nama akun dan nilai debit-kredit di workbook.';
        if ($context['missing_field'] === 'handler') return 'Jenis sumber ini belum didukung handler operasional; baris tetap pending.';
        if ($context['missing_field'] === 'location_id') return 'Terapkan lokasi aktif default ke grup atau pilih lokasi per baris.';

        return 'Tinjau alasan blokir dan lengkapi field yang ditunjukkan sebelum mencoba ulang.';
    }

    private function canonicalContext(array $context): array
    {
        return [
            'source_type' => (string) ($context['source_type'] ?? ''),
            'source_action' => (string) ($context['source_action'] ?? ''),
            'reason_source' => (string) ($context['reason_source'] ?? ''),
            'reason' => trim((string) ($context['reason'] ?? '')),
            'missing_field' => (string) ($context['missing_field'] ?? 'other'),
            'suggested_direction' => $context['suggested_direction'] ?? null,
        ];
    }

    private function groupKey(array $context): string
    {
        return hash('sha256', json_encode($this->canonicalContext($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function encodeContext(array $context): string
    {
        return rtrim(strtr(base64_encode(json_encode($this->canonicalContext($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }
}
