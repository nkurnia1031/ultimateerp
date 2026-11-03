<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReconciliationFindingJournalService
{
    private const FINDING_TABLE = 'accounting_legacy_reconciliation_findings';
    private const SOURCE_TYPE = 'reconciliation_finding';
    private const VARIANCE_TOLERANCE = 0.01;

    private $journalPosting;

    public function __construct(AccountingManualJournalPostingService $journalPosting)
    {
        $this->journalPosting = $journalPosting;
    }

    public function roundingAccountId(int $businessId): ?int
    {
        $settings = $this->businessSettings($businessId);
        $accountId = (int) ($settings['reconciliation_rounding_account_id'] ?? 0);
        if ($accountId < 1) {
            return null;
        }

        return DB::table('accounting_accounts')
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->where('id', $accountId)
            ->exists() ? $accountId : null;
    }

    public function saveRoundingAccount(int $businessId, ?int $accountId, int $actorId): ?int
    {
        return DB::transaction(function () use ($businessId, $accountId, $actorId): ?int {
            $business = DB::table('business')->where('id', $businessId)->lockForUpdate()->first(['id', 'accounting_settings']);
            if (! $business) {
                throw ValidationException::withMessages(['accounting_account_id' => 'Business aktif tidak ditemukan.']);
            }
            if ($accountId !== null && ! DB::table('accounting_accounts')
                ->where('business_id', $businessId)->where('id', $accountId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['accounting_account_id' => 'Pilih CoA aktif milik business ini.']);
            }

            $settings = json_decode((string) $business->accounting_settings, true);
            $settings = is_array($settings) ? $settings : [];
            $settings['reconciliation_rounding_account_id'] = $accountId;
            $settings['reconciliation_rounding_account_updated_by'] = $actorId;
            $settings['reconciliation_rounding_account_updated_at'] = now()->toDateTimeString();
            DB::table('business')->where('id', $businessId)->update([
                'accounting_settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return $accountId;
        });
    }

    public function preview(int $businessId, int $findingId, int $actorId, string $actionType, string $reason, array $lines): array
    {
        $finding = $this->finding($businessId, $findingId);
        $proposal = $this->buildProposal($businessId, $finding, $actorId, $actionType, $reason, $lines);

        return ['supported' => true, 'proposal' => $proposal];
    }

    public function confirm(
        int $businessId,
        int $findingId,
        int $actorId,
        string $actionType,
        string $reason,
        array $lines,
        string $fingerprint
    ): array {
        return DB::transaction(function () use ($businessId, $findingId, $actorId, $actionType, $reason, $lines, $fingerprint): array {
            $finding = $this->finding($businessId, $findingId, true);
            $historyEvents = json_decode((string) $finding->resolution_history, true);
            foreach (array_reverse(is_array($historyEvents) ? $historyEvents : []) as $event) {
                if (! is_array($event)
                    || ! in_array($event['action'] ?? '', ['adjustment_journal_posted', 'rounding_journal_posted'], true)
                    || ! hash_equals((string) ($event['fingerprint'] ?? ''), $fingerprint)) {
                    continue;
                }

                $existing = DB::table('accounting_acc_trans_mappings')
                    ->where('business_id', $businessId)
                    ->where('source_record_type', self::SOURCE_TYPE)
                    ->where('source_record_id', $findingId)
                    ->where('id', (int) ($event['journal_id'] ?? 0))
                    ->lockForUpdate()
                    ->first(['id', 'ref_no']);
                if (! $existing) {
                    $this->reject('Jurnal dari konfirmasi sebelumnya tidak ditemukan. Hubungi administrator sebelum mencoba lagi.');
                }

                return [
                    'status' => (string) $finding->status,
                    'idempotent' => true,
                    'journal_id' => (int) $existing->id,
                    'ref_no' => (string) $existing->ref_no,
                    'variance' => (float) $finding->variance,
                ];
            }
            if (! DB::table('business')->where('id', $businessId)->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['business' => 'Business aktif tidak ditemukan.']);
            }

            $proposal = $this->buildProposal($businessId, $finding, $actorId, $actionType, $reason, $lines);
            if (! hash_equals((string) $proposal['fingerprint'], $fingerprint)) {
                $this->reject('Temuan, mapping, atau baris jurnal berubah setelah preview. Muat ulang preview sebelum mengonfirmasi.');
            }

            $operationDate = $finding->source_date
                ? substr((string) $finding->source_date, 0, 10).' 00:00:00'
                : now()->toDateTimeString();
            $note = 'Jurnal penyesuaian temuan #'.$findingId.' ('.$finding->source_number.'). Alasan: '.trim($reason);
            $sequence = (int) DB::table('accounting_acc_trans_mappings')
                ->where('business_id', $businessId)
                ->where('source_record_type', self::SOURCE_TYPE)
                ->where('source_record_id', $findingId)
                ->count() + 1;
            $reference = 'RFA-'.$businessId.'-'.$findingId.'-'.$sequence;
            $posted = $this->journalPosting->create(
                $businessId,
                $actorId,
                self::SOURCE_TYPE,
                $findingId,
                $reference,
                $operationDate,
                $reason,
                $lines,
                $actionType === 'rounding' ? 'reconciliation_finding_rounding' : 'reconciliation_finding_adjustment',
                $note,
                true
            );

            if (! $posted['created']) {
                return [
                    'status' => (string) $finding->status,
                    'idempotent' => true,
                    'journal_id' => (int) $posted['journal_id'],
                    'ref_no' => (string) $posted['ref_no'],
                    'variance' => (float) $finding->variance,
                ];
            }

            $recheck = $this->recheckFromPostedJournal($businessId, (int) $posted['journal_id'], $finding);
            $history = $this->appendHistory($finding->resolution_history, [
                'action' => $actionType === 'rounding' ? 'rounding_journal_posted' : 'adjustment_journal_posted',
                'actor_id' => $actorId,
                'at' => now()->toDateTimeString(),
                'reason' => trim($reason),
                'journal_id' => (int) $posted['journal_id'],
                'journal_ref' => (string) $posted['ref_no'],
                'fingerprint' => $fingerprint,
                'before' => $recheck['before'],
                'after' => $recheck['after'],
                'variance_direction' => $recheck['before']['variance'] < 0 ? -1 : 1,
                'debit_total' => $recheck['debit_total'],
                'credit_total' => $recheck['credit_total'],
                'lines' => $posted['lines'],
                'recheck' => 'linked_journal_amount_applied_to_actual_side',
                'status' => $recheck['status'],
            ]);

            DB::table(self::FINDING_TABLE)->where('business_id', $businessId)->where('id', $findingId)->update([
                'expected_amount' => $recheck['after']['expected_amount'],
                'actual_amount' => $recheck['after']['actual_amount'],
                'variance' => $recheck['after']['variance'],
                'status' => $recheck['status'],
                'is_current' => $recheck['status'] !== 'resolved',
                'resolution_history' => $history,
                'updated_at' => now(),
            ]);

            return [
                'status' => $recheck['status'],
                'idempotent' => false,
                'journal_id' => (int) $posted['journal_id'],
                'ref_no' => (string) $posted['ref_no'],
                'variance' => $recheck['after']['variance'],
                'message' => $recheck['status'] === 'resolved'
                    ? 'Jurnal tertaut berhasil diposting; pemeriksaan ulang atas nilai yang tercakup tidak menyisakan selisih.'
                    : 'Jurnal tertaut berhasil diposting; sebagian selisih masih terbuka untuk ditinjau.',
            ];
        });
    }

    public function linkedJournals(int $businessId, array $findingIds): array
    {
        if ($findingIds === []) {
            return [];
        }

        return DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $businessId)
            ->where('source_record_type', self::SOURCE_TYPE)
            ->whereIn('source_record_id', $findingIds)
            ->orderBy('id')
            ->get(['id', 'ref_no', 'source_record_id'])
            ->groupBy('source_record_id')
            ->all();
    }

    private function buildProposal(int $businessId, object $finding, int $actorId, string $actionType, string $reason, array $lines): array
    {
        if ($finding->status !== 'open' || ! (bool) $finding->is_current) {
            $this->reject('Jurnal penyesuaian hanya tersedia untuk temuan terbuka yang masih berlaku.');
        }
        if (! in_array($actionType, ['adjustment', 'rounding'], true)) {
            $this->reject('Jenis jurnal penyesuaian tidak valid.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan jurnal wajib diisi.']);
        }
        if (! is_numeric($finding->expected_amount) || ! is_numeric($finding->actual_amount)
            || ! is_numeric($finding->variance) || abs((float) $finding->variance) <= self::VARIANCE_TOLERANCE) {
            $this->reject('Temuan tidak memiliki nilai pembanding yang dapat dijurnal.');
        }

        $precision = $this->currencyPrecision($businessId);
        $smallestUnit = 1 / (10 ** $precision);
        $absoluteVariance = round(abs((float) $finding->variance), 4);
        $roundingAccountId = $this->roundingAccountId($businessId);
        if ($actionType === 'rounding' && $absoluteVariance >= $smallestUnit) {
            $this->reject('Pembulatan hanya untuk selisih yang lebih kecil dari satuan terkecil mata uang business.');
        }
        if ($actionType === 'rounding' && $roundingAccountId === null) {
            $this->reject('Petakan akun pembulatan CoA untuk business ini sebelum membuat jurnal pembulatan.');
        }
        if ($actionType === 'adjustment' && $absoluteVariance < $smallestUnit) {
            $this->reject('Selisih di bawah presisi mata uang harus memakai jurnal pembulatan dengan akun yang sudah dipetakan.');
        }

        $preparedLines = $this->journalPosting->prepareLines($businessId, $lines);
        $debitMinor = array_sum(array_column($preparedLines, 'debit_minor'));
        $creditMinor = array_sum(array_column($preparedLines, 'credit_minor'));
        if ($debitMinor <= 0 || $creditMinor <= 0 || $debitMinor !== $creditMinor) {
            throw ValidationException::withMessages(['lines' => 'Total debit dan kredit harus sama dan lebih dari nol.']);
        }
        $coveredAmount = round($debitMinor / 10000, 4);
        if ($coveredAmount > $absoluteVariance + 0.00005) {
            throw ValidationException::withMessages(['lines' => 'Nilai jurnal tidak boleh melebihi selisih temuan.']);
        }
        if ($actionType === 'rounding') {
            $roundingLines = array_values(array_filter($preparedLines, fn (array $line): bool => (int) $line['accounting_account_id'] === $roundingAccountId));
            if (count($roundingLines) !== 1 || $coveredAmount !== $absoluteVariance
                || ($roundingLines[0]['debit_minor'] + $roundingLines[0]['credit_minor']) !== $debitMinor) {
                throw ValidationException::withMessages(['lines' => 'Jurnal pembulatan harus memakai akun pembulatan yang dipetakan tepat satu kali untuk seluruh nilai selisih.']);
            }
        }

        $before = [
            'expected_amount' => round((float) $finding->expected_amount, 4),
            'actual_amount' => round((float) $finding->actual_amount, 4),
            'variance' => round((float) $finding->variance, 4),
        ];
        $direction = $before['variance'] < 0 ? -1 : 1;
        $after = [
            'expected_amount' => $before['expected_amount'],
            'actual_amount' => round($before['actual_amount'] - ($direction * $coveredAmount), 4),
            'variance' => round($before['variance'] - ($direction * $coveredAmount), 4),
        ];
        $status = abs($after['variance']) <= self::VARIANCE_TOLERANCE ? 'resolved' : 'open';
        $evidence = json_decode((string) $finding->evidence, true);
        $evidence = is_array($evidence) ? $evidence : [];
        $fingerprint = hash('sha256', json_encode([
            'business_id' => $businessId,
            'finding_id' => (int) $finding->id,
            'finding_key' => (string) $finding->finding_key,
            'last_audit_key' => (string) $finding->last_audit_key,
            'updated_at' => (string) $finding->updated_at,
            'action' => $actionType,
            'reason' => $reason,
            'rounding_account_id' => $actionType === 'rounding' ? $roundingAccountId : null,
            'precision' => $precision,
            'lines' => array_map(fn (array $line): array => [
                'account_id' => $line['accounting_account_id'],
                'debit_minor' => $line['debit_minor'],
                'credit_minor' => $line['credit_minor'],
            ], $preparedLines),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return [
            'fingerprint' => $fingerprint,
            'action' => $actionType,
            'reason' => $reason,
            'precision' => $precision,
            'smallest_unit' => $smallestUnit,
            'rounding_account_id' => $roundingAccountId,
            'source' => [
                'type' => (string) $finding->source_type,
                'number' => (string) $finding->source_number,
                'date' => (string) $finding->source_date,
                'description' => (string) $finding->description,
                'account_no' => (string) $finding->account_no,
                'account_name' => (string) $finding->account_name,
            ],
            'component' => (string) $finding->component,
            'vat_components' => [
                'journal_vat_in' => $evidence['journal_vat_in'] ?? null,
                'operational_vat_in' => $evidence['operational_vat_in'] ?? null,
                'journal_vat_out' => $evidence['journal_vat_out'] ?? null,
                'operational_vat_out' => $evidence['operational_vat_out'] ?? null,
            ],
            'before' => $before,
            'after' => $after,
            'covered_amount' => $coveredAmount,
            'debit_total' => number_format($debitMinor / 10000, 4, '.', ''),
            'credit_total' => number_format($creditMinor / 10000, 4, '.', ''),
            'status_after_recheck' => $status,
            'lines' => $preparedLines,
        ];
    }

    private function recheckFromPostedJournal(int $businessId, int $mappingId, object $finding): array
    {
        $lines = DB::table('accounting_accounts_transactions')
            ->join('accounting_acc_trans_mappings as mapping', 'mapping.id', '=', 'accounting_accounts_transactions.acc_trans_mapping_id')
            ->where('acc_trans_mapping_id', $mappingId)
            ->where('mapping.business_id', $businessId)
            ->where('sub_type', 'journal_entry')
            ->get(['accounting_accounts_transactions.amount', 'accounting_accounts_transactions.type']);
        $debit = round((float) $lines->where('type', 'debit')->sum('amount'), 4);
        $credit = round((float) $lines->where('type', 'credit')->sum('amount'), 4);
        if ($lines->count() < 2 || $debit <= 0 || $debit !== $credit) {
            $this->reject('Pemeriksaan ulang gagal: jurnal tersimpan tidak seimbang.');
        }

        $before = [
            'expected_amount' => round((float) $finding->expected_amount, 4),
            'actual_amount' => round((float) $finding->actual_amount, 4),
            'variance' => round((float) $finding->variance, 4),
        ];
        $direction = $before['variance'] < 0 ? -1 : 1;
        $actualAfter = round($before['actual_amount'] - ($direction * $debit), 4);
        $varianceAfter = round($before['variance'] - ($direction * $debit), 4);

        return [
            'before' => $before,
            'after' => [
                'expected_amount' => $before['expected_amount'],
                'actual_amount' => $actualAfter,
                'variance' => $varianceAfter,
            ],
            'debit_total' => $debit,
            'credit_total' => $credit,
            'status' => abs($varianceAfter) <= self::VARIANCE_TOLERANCE ? 'resolved' : 'open',
        ];
    }

    public function currencyPrecision(int $businessId): int
    {
        if (Schema::hasColumn('business', 'currency_precision')) {
            $storedPrecision = DB::table('business')->where('id', $businessId)->value('currency_precision');
            if (! is_numeric($storedPrecision)) {
                return 2;
            }
            $precision = (int) $storedPrecision;

            return max(0, min(4, $precision));
        }

        return 2;
    }

    private function businessSettings(int $businessId): array
    {
        if (! Schema::hasColumn('business', 'accounting_settings')) {
            return [];
        }
        $settings = DB::table('business')->where('id', $businessId)->value('accounting_settings');
        $settings = json_decode((string) $settings, true);

        return is_array($settings) ? $settings : [];
    }

    private function finding(int $businessId, int $findingId, bool $lock = false): object
    {
        $query = DB::table(self::FINDING_TABLE)->where('business_id', $businessId)->where('id', $findingId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $finding = $query->first();
        if (! $finding) {
            abort(404);
        }

        return $finding;
    }

    private function appendHistory(?string $history, array $event): string
    {
        $events = json_decode((string) $history, true);
        $events = is_array($events) ? $events : [];
        $events[] = $event;

        return json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function reject(string $message): void
    {
        throw ValidationException::withMessages(['finding' => $message]);
    }
}
