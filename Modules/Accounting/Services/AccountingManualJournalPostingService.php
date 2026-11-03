<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Shared, business-scoped writer for balanced manual Accounting journals. */
class AccountingManualJournalPostingService
{
    public function prepareLines(int $businessId, array $lines, bool $lockAccounts = false): array
    {
        if (count($lines) < 2 || count($lines) > 100) {
            throw ValidationException::withMessages(['lines' => 'Jurnal harus berisi 2 sampai 100 baris.']);
        }

        $prepared = [];
        $accountIds = [];
        foreach ($lines as $index => $line) {
            $accountId = (int) ($line['accounting_account_id'] ?? 0);
            $debit = $this->amountMinor($line['debit'] ?? 0, 'lines.'.$index.'.debit');
            $credit = $this->amountMinor($line['credit'] ?? 0, 'lines.'.$index.'.credit');
            if ($accountId < 1 || (($debit > 0) === ($credit > 0))) {
                throw ValidationException::withMessages(['lines.'.$index => 'Setiap baris harus memilih satu CoA dan mengisi debit atau kredit saja.']);
            }

            $accountIds[] = $accountId;
            $prepared[] = [
                'accounting_account_id' => $accountId,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
            ];
        }

        $accountIds = array_values(array_unique($accountIds));
        $accounts = DB::table('accounting_accounts')
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->whereIn('id', $accountIds)
            ->when($lockAccounts, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'name'])
            ->keyBy('id');
        if ($accounts->count() !== count($accountIds)) {
            throw ValidationException::withMessages(['lines' => 'Semua akun harus CoA aktif milik business saat ini.']);
        }

        foreach ($prepared as &$line) {
            $line['account_name'] = (string) $accounts[$line['accounting_account_id']]->name;
            $line['debit'] = number_format($line['debit_minor'] / 10000, 4, '.', '');
            $line['credit'] = number_format($line['credit_minor'] / 10000, 4, '.', '');
        }
        unset($line);

        return $prepared;
    }

    /** Caller must keep the source lock and surrounding business action in a transaction. */
    public function create(
        int $businessId,
        int $userId,
        string $sourceRecordType,
        int $sourceRecordId,
        string $reference,
        string $operationDate,
        string $reason,
        array $lines,
        string $mapType,
        string $note,
        bool $allowDuplicateSourceLink = false
    ): array {
        if (! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_type')
            || ! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_id')) {
            throw ValidationException::withMessages(['source' => 'Source link Accounting belum tersedia; jalankan migrasi terlebih dahulu.']);
        }

        if (! $allowDuplicateSourceLink) {
            $existing = DB::table('accounting_acc_trans_mappings')
                ->where('business_id', $businessId)
                ->where('source_record_type', $sourceRecordType)
                ->where('source_record_id', $sourceRecordId)
                ->lockForUpdate()
                ->first(['id', 'ref_no']);
            if ($existing) {
                return [
                    'created' => false,
                    'journal_id' => (int) $existing->id,
                    'ref_no' => (string) $existing->ref_no,
                    'total' => null,
                    'lines' => [],
                ];
            }
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan jurnal manual wajib diisi.']);
        }

        $preparedLines = $this->prepareLines($businessId, $lines, true);
        $debitTotal = array_sum(array_column($preparedLines, 'debit_minor'));
        $creditTotal = array_sum(array_column($preparedLines, 'credit_minor'));
        if ($debitTotal <= 0 || $creditTotal <= 0 || $debitTotal !== $creditTotal) {
            throw ValidationException::withMessages(['lines' => 'Total debit dan kredit harus sama dan lebih dari nol.']);
        }

        $now = now();
        $mappingId = DB::table('accounting_acc_trans_mappings')->insertGetId([
            'business_id' => $businessId,
            'ref_no' => $reference,
            'type' => 'journal_entry',
            'created_by' => $userId,
            'operation_date' => $operationDate,
            'note' => $note,
            'source_record_type' => $sourceRecordType,
            'source_record_id' => $sourceRecordId,
            'source_link_status' => 'matched',
            'source_link_note' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($preparedLines as $line) {
            $amountMinor = $line['debit_minor'] > 0 ? $line['debit_minor'] : $line['credit_minor'];
            DB::table('accounting_accounts_transactions')->insert([
                'accounting_account_id' => $line['accounting_account_id'],
                'acc_trans_mapping_id' => $mappingId,
                'transaction_id' => null,
                'transaction_payment_id' => null,
                'amount' => number_format($amountMinor / 10000, 4, '.', ''),
                'type' => $line['debit_minor'] > 0 ? 'debit' : 'credit',
                'sub_type' => 'journal_entry',
                'map_type' => $mapType,
                'created_by' => $userId,
                'operation_date' => $operationDate,
                'note' => mb_substr($note, 0, 1000),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'created' => true,
            'journal_id' => $mappingId,
            'ref_no' => $reference,
            'total' => number_format($debitTotal / 10000, 4, '.', ''),
            'lines' => $preparedLines,
        ];
    }

    private function amountMinor($value, string $key): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 999999999999) {
            throw ValidationException::withMessages([$key => 'Nominal harus angka positif dengan maksimal 4 angka desimal.']);
        }

        $rounded = round((float) $value, 4);
        if (abs($rounded - (float) $value) > 0.0000001) {
            throw ValidationException::withMessages([$key => 'Nominal tidak boleh melebihi 4 angka desimal.']);
        }

        return (int) round($rounded * 10000);
    }
}
