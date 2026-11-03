<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReconciliationFindingActionService
{
    private const AMOUNT_TOLERANCE = 0.01;
    private const RATE_TOLERANCE = 0.01;

    public function previewOperationalTaxCorrection(int $businessId, int $findingId): array
    {
        $finding = $this->finding($businessId, $findingId);
        $proposal = $this->buildTaxProposal($finding);

        return $proposal === null
            ? [
                'supported' => false,
                'reason' => $this->lastUnsupportedReason,
                'editor_url' => $this->sourceEditorUrl($businessId, $finding),
            ]
            : ['supported' => true, 'proposal' => $proposal];
    }

    private string $lastUnsupportedReason = 'Temuan ini tidak memiliki bukti yang cukup untuk koreksi pajak otomatis.';

    public function confirmOperationalTaxCorrection(int $businessId, int $findingId, int $actorId, string $fingerprint): array
    {
        return DB::transaction(function () use ($businessId, $findingId, $actorId, $fingerprint): array {
            $finding = $this->finding($businessId, $findingId, true);
            if ($finding->status === 'resolved' && ! (bool) $finding->is_current) {
                return ['status' => 'resolved', 'idempotent' => true];
            }

            $existingHistory = json_decode((string) $finding->resolution_history, true);
            foreach (is_array($existingHistory) ? $existingHistory : [] as $event) {
                if (($event['action'] ?? null) === 'operational_tax_corrected'
                    && hash_equals((string) ($event['fingerprint'] ?? ''), $fingerprint)) {
                    return ['status' => (string) $finding->status, 'idempotent' => true];
                }
            }

            $proposal = $this->buildTaxProposal($finding, true);
            if ($proposal === null) {
                $this->reject($this->lastUnsupportedReason);
            }
            if (! hash_equals((string) $proposal['fingerprint'], $fingerprint)) {
                $this->reject('Data sumber atau nilai temuan berubah setelah preview. Muat ulang preview sebelum mengonfirmasi.');
            }

            $transactionUpdate = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('id', $proposal['source']['id'])
                ->where('status', 'final');
            if (Schema::hasColumn('transactions', 'deleted_at')) {
                $transactionUpdate->whereNull('deleted_at');
            }
            $updated = $transactionUpdate->update([
                'tax_id' => $proposal['after']['tax_id'],
                'tax_amount' => $proposal['after']['tax_amount'],
                'total_before_tax' => $proposal['after']['total_before_tax'],
                'final_total' => $proposal['after']['final_total'],
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                $this->reject('Transaksi sumber berubah atau tidak lagi aktif. Muat ulang preview.');
            }

            $sourceAfter = DB::table('transactions')->where('business_id', $businessId)
                ->where('id', $proposal['source']['id'])->first();
            $verifiedVariance = round((float) $proposal['journal_vat'] - (float) $sourceAfter->tax_amount, 4);
            $isCleared = abs($verifiedVariance) <= self::AMOUNT_TOLERANCE
                && abs((float) $sourceAfter->final_total - ((float) $sourceAfter->total_before_tax + (float) $sourceAfter->tax_amount)) <= self::AMOUNT_TOLERANCE;
            $status = $isCleared ? 'resolved' : 'open';

            $history = $this->appendHistory($finding->resolution_history, [
                'action' => 'operational_tax_corrected',
                'actor_id' => $actorId,
                'at' => now()->toDateTimeString(),
                'source_transaction_id' => (int) $proposal['source']['id'],
                'fingerprint' => $fingerprint,
                'before' => $proposal['before'],
                'after' => $proposal['after'],
                'journal_vat' => (float) $proposal['journal_vat'],
                'verified_variance' => $verifiedVariance,
            ]);

            DB::table('accounting_legacy_reconciliation_findings')
                ->where('business_id', $businessId)
                ->where('id', $findingId)
                ->update([
                    'expected_amount' => round((float) $sourceAfter->tax_amount, 4),
                    'actual_amount' => round((float) $proposal['journal_vat'], 4),
                    'variance' => $verifiedVariance,
                    'status' => $status,
                    'is_current' => $isCleared ? false : (bool) $finding->is_current,
                    'resolution_history' => $history,
                    'updated_at' => now(),
                ]);

            return [
                'status' => $status,
                'idempotent' => false,
                'verified_variance' => $verifiedVariance,
                'message' => $isCleared
                    ? 'Pajak transaksi diperbarui dan selisih VAT terverifikasi sudah hilang.'
                    : 'Pajak diperbarui, tetapi selisih masih ada dan tetap terbuka.',
            ];
        });
    }

    public function accept(int $businessId, int $findingId, int $actorId, string $reason): array
    {
        return DB::transaction(function () use ($businessId, $findingId, $actorId, $reason): array {
            $finding = $this->finding($businessId, $findingId, true);
            if ($finding->status === 'accepted' && (bool) $finding->is_current) {
                return ['status' => 'accepted', 'idempotent' => true];
            }
            if ($finding->status !== 'open' || ! (bool) $finding->is_current) {
                $this->reject('Hanya temuan terbuka yang dapat diterima.');
            }

            $history = $this->appendHistory($finding->resolution_history, [
                'action' => 'accepted',
                'actor_id' => $actorId,
                'at' => now()->toDateTimeString(),
                'reason' => trim($reason),
                'audit_key' => (string) $finding->last_audit_key,
            ]);
            DB::table('accounting_legacy_reconciliation_findings')
                ->where('business_id', $businessId)
                ->where('id', $findingId)
                ->update(['status' => 'accepted', 'resolution_history' => $history, 'updated_at' => now()]);

            return ['status' => 'accepted', 'idempotent' => false];
        });
    }

    private function buildTaxProposal(object $finding, bool $lockSource = false): ?array
    {
        $evidence = json_decode((string) $finding->evidence, true);
        if (! is_array($evidence)) {
            return $this->unsupported('Bukti temuan tidak dapat dibaca.');
        }
        if ($finding->status !== 'open' || ! (bool) $finding->is_current) {
            return $this->unsupported('Koreksi hanya tersedia untuk temuan yang masih terbuka.');
        }
        if ($finding->type !== 'transaction' || $finding->component !== 'vat_in'
            || (string) $finding->source_type !== 'Expense') {
            return $this->unsupported('Koreksi otomatis saat ini hanya mendukung komponen VAT In pada Expense.');
        }
        if (($evidence['match_status'] ?? null) !== 'matched'
            || (int) ($evidence['operational_source_count'] ?? 0) !== 1
            || empty($evidence['has_operational_source'])
            || ! empty($evidence['source_identity_conflict'])
            || ! is_numeric($evidence['operational_base'] ?? null)
            || ! is_numeric($evidence['operational_vat_in'] ?? null)
            || ! is_numeric($evidence['journal_base'] ?? null)
            || ! is_numeric($evidence['journal_vat_in'] ?? null)
            || ! is_numeric($evidence['journal_total'] ?? null)
            || ! is_numeric($finding->expected_amount)
            || ! is_numeric($finding->actual_amount)) {
            return $this->unsupported('Audit tidak membuktikan satu sumber operasional dan komponen pajak yang lengkap.');
        }

        $sourceNumber = trim((string) $finding->source_number);
        $sourceDate = (string) $finding->source_date;
        if ($sourceNumber === '' || $sourceDate === '') {
            return $this->unsupported('Nomor dan tanggal sumber tidak lengkap untuk pencocokan transaksi.');
        }

        $sourceQuery = DB::table('transactions')
            ->where('business_id', (int) $finding->business_id)
            ->where('type', 'expense')
            ->where('status', 'final')
            ->whereDate('transaction_date', $sourceDate)
            ->where(function ($query) use ($sourceNumber): void {
                $query->where('ref_no', $sourceNumber)->orWhere('invoice_no', $sourceNumber);
            });
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $sourceQuery->whereNull('deleted_at');
        }
        if ($lockSource) {
            $sourceQuery->lockForUpdate();
        }
        $sources = $sourceQuery->get([
            'id', 'business_id', 'type', 'status', 'ref_no', 'invoice_no', 'transaction_date',
            'total_before_tax', 'tax_id', 'tax_amount', 'final_total', 'updated_at',
        ]);
        if ($sources->count() !== 1) {
            return $this->unsupported($sources->isEmpty()
                ? 'Transaksi aktif dengan nomor dan tanggal yang sama tidak ditemukan.'
                : 'Pencocokan sumber menghasilkan lebih dari satu transaksi; koreksi otomatis memerlukan sumber yang unik.');
        }
        $source = $sources->first();

        $base = round((float) $evidence['operational_base'], 4);
        $expectedVat = round((float) $evidence['operational_vat_in'], 4);
        $journalBase = round((float) $evidence['journal_base'], 4);
        $journalVat = round((float) $evidence['journal_vat_in'], 4);
        if ($base <= 0 || $journalVat <= 0
            || abs($journalBase - $base) > self::AMOUNT_TOLERANCE
            || abs((float) $finding->expected_amount - $expectedVat) > self::AMOUNT_TOLERANCE
            || abs((float) $finding->actual_amount - $journalVat) > self::AMOUNT_TOLERANCE
            || abs((float) ($evidence['journal_vat_out'] ?? 0)) > self::AMOUNT_TOLERANCE
            || abs((float) ($evidence['journal_other_tax'] ?? 0)) > self::AMOUNT_TOLERANCE) {
            return $this->unsupported('Nilai Journal tidak menunjukkan satu komponen VAT In positif yang dapat dikoreksi sendiri.');
        }

        $currentVat = round((float) $source->tax_amount, 4);
        $currentBase = round((float) $source->total_before_tax, 4);
        $currentTotal = round((float) $source->final_total, 4);
        if (abs($currentBase - $base) > self::AMOUNT_TOLERANCE
            || abs($currentVat - $expectedVat) > self::AMOUNT_TOLERANCE
            || abs($currentTotal - ($currentBase + $currentVat)) > self::AMOUNT_TOLERANCE) {
            return $this->unsupported('Nilai dasar, VAT, atau total transaksi berubah dari snapshot audit; sumber tidak diubah.');
        }
        if (abs(round($base + $journalVat, 4) - round((float) $evidence['journal_total'], 4)) > self::AMOUNT_TOLERANCE) {
            return $this->unsupported('Total Journal memuat komponen selain basis dan VAT yang belum dapat dibuktikan untuk koreksi ini.');
        }

        $rate = round($journalVat / $base * 100, 4);
        $taxRatesQuery = DB::table('tax_rates')->where('business_id', (int) $finding->business_id);
        if (Schema::hasColumn('tax_rates', 'deleted_at')) {
            $taxRatesQuery->whereNull('deleted_at');
        }
        if (Schema::hasColumn('tax_rates', 'is_tax_group')) {
            $taxRatesQuery->where('is_tax_group', false);
        }
        $taxRates = $taxRatesQuery->get(['id', 'name', 'amount'])->filter(function ($taxRate) use ($rate, $base, $journalVat): bool {
            return abs(round((float) $taxRate->amount, 4) - $rate) <= self::RATE_TOLERANCE
                && abs(round($base * (float) $taxRate->amount / 100, 2) - $journalVat) <= self::AMOUNT_TOLERANCE;
        })->values();
        if ($taxRates->count() !== 1) {
            return $this->unsupported($taxRates->isEmpty()
                ? 'Tarif hasil hitung VAT Journal tidak cocok dengan TaxRate aktif pada business ini.'
                : 'Tarif hasil hitung cocok dengan beberapa TaxRate; pilihannya perlu ditinjau manual.');
        }
        $taxRate = $taxRates->first();

        $before = [
            'tax_id' => $source->tax_id === null ? null : (int) $source->tax_id,
            'tax_amount' => $currentVat,
            'total_before_tax' => $currentBase,
            'final_total' => $currentTotal,
        ];
        $after = [
            'tax_id' => (int) $taxRate->id,
            'tax_rate_name' => (string) $taxRate->name,
            'tax_rate' => (float) $taxRate->amount,
            'tax_amount' => $journalVat,
            'total_before_tax' => $currentBase,
            'final_total' => round($currentBase + $journalVat, 4),
        ];
        $fingerprint = hash('sha256', json_encode([
            'finding_key' => (string) $finding->finding_key,
            'audit_key' => (string) $finding->last_audit_key,
            'source_id' => (int) $source->id,
            'source_updated_at' => (string) $source->updated_at,
            'before' => $before,
            'after' => $after,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return [
            'source' => [
                'id' => (int) $source->id,
                'type' => 'Expense',
                'number' => $sourceNumber,
                'date' => $sourceDate,
            ],
            'before' => $before,
            'after' => $after,
            'journal_vat' => $journalVat,
            'journal_total' => (float) $evidence['journal_total'],
            'fingerprint' => $fingerprint,
        ];
    }

    private function sourceEditorUrl(int $businessId, object $finding): ?string
    {
        $type = strtolower(trim((string) $finding->source_type));
        $routeNames = [
            'expense' => ['expenses.edit', 'expenses.index'],
            'sales invoice' => ['sells.edit', 'sells.index'],
            'purchase invoice' => ['purchases.edit', 'purchases.index'],
        ];
        $routes = $routeNames[$type] ?? null;
        if ($routes === null || trim((string) $finding->source_number) === '' || empty($finding->source_date)) {
            return null;
        }

        $typeValue = [
            'expense' => 'expense', 'sales invoice' => 'sell', 'purchase invoice' => 'purchase',
        ][$type];
        $routeParameter = [
            'expense' => 'expense', 'sales invoice' => 'sell', 'purchase invoice' => 'purchase',
        ][$type];
        $query = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', $typeValue)
            ->whereDate('transaction_date', (string) $finding->source_date)
            ->where(function ($builder) use ($finding): void {
                $builder->where('ref_no', trim((string) $finding->source_number))
                    ->orWhere('invoice_no', trim((string) $finding->source_number));
            });
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $sources = $query->get(['id']);
        if ($sources->count() === 1 && \Illuminate\Support\Facades\Route::has($routes[0])) {
            return route($routes[0], [$routeParameter => (int) $sources->first()->id]);
        }
        if (\Illuminate\Support\Facades\Route::has($routes[1])) {
            return route($routes[1], ['search' => trim((string) $finding->source_number)]);
        }

        return null;
    }

    private function finding(int $businessId, int $findingId, bool $lock = false): object
    {
        $query = DB::table('accounting_legacy_reconciliation_findings')
            ->where('business_id', $businessId)
            ->where('id', $findingId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $finding = $query->first();
        if (! $finding) {
            abort(404);
        }

        return $finding;
    }

    private function unsupported(string $reason): ?array
    {
        $this->lastUnsupportedReason = $reason;

        return null;
    }

    private function reject(string $message): void
    {
        throw ValidationException::withMessages(['finding' => $message]);
    }

    private function appendHistory(?string $history, array $event): string
    {
        $events = json_decode((string) $history, true);
        if (! is_array($events)) {
            $events = [];
        }
        $events[] = $event;

        return json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
