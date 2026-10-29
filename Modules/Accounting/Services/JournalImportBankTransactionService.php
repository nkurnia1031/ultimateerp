<?php

namespace Modules\Accounting\Services;

use App\AccountTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalImportBankTransactionService
{
    private const SOURCE_SUBTYPES = [
        'Bank Deposit' => 'deposit',
        'Bank Transfer' => 'fund_transfer',
        'Bank Withdrawal' => 'withdrawal',
        'Reversal' => 'reversal',
    ];

    private $draftService;

    public function __construct(JournalImportDraftService $draftService)
    {
        $this->draftService = $draftService;
    }

    public function readinessIssue(object $row, array $entry, int $businessId, array $options = []): ?string
    {
        try {
            $this->prepare($row, $entry, $businessId, false);

            return null;
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }
    }

    public function draftTotal(object $row, array $entry, object $import, array $options = []): ?float
    {
        try {
            return $this->prepare($row, $entry, (int) $import->business_id, false)['amount'];
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function process(object $row, array $entry, object $import, array $options = []): AccountTransaction
    {
        $prepared = $this->prepare($row, $entry, (int) $import->business_id, true);
        $action = (string) $row->source_action;
        $main = $prepared['source'];

        if ($action === 'link') {
            $this->saveMutationSnapshot($row, ['action' => 'linked', 'record_ids' => [$main->id]]);

            return $main;
        }

        if ($action === 'create') {
            $existingCreated = $this->createdRecordsForRow((int) $row->id, (int) $import->business_id);
            if ($existingCreated->isNotEmpty()) {
                $main = $this->findCreatedMain($existingCreated, $prepared);
                $this->assertCreatedSetIsComplete($existingCreated, $prepared);
                $this->saveMutationSnapshot($row, [
                    'action' => 'created',
                    'primary_id' => (int) $main->id,
                    'created_ids' => $existingCreated->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'created_expected' => $this->createdExpectedValues($existingCreated),
                    'mutated' => [],
                ]);

                return $main;
            }

            [$main, $created] = $this->createRecords($row, $entry, $import, $prepared);
            $this->saveMutationSnapshot($row, [
                'action' => 'created',
                'primary_id' => (int) $main->id,
                'created_ids' => array_map(fn ($record) => (int) $record->id, $created),
                'created_expected' => $this->createdExpectedValues(collect($created)),
                'mutated' => [],
            ]);

            return $main->fresh();
        }

        [$main, $created, $mutated] = $this->updateSource($row, $entry, $import, $prepared);
        $this->saveMutationSnapshot($row, [
            'action' => 'updated',
            'primary_id' => (int) $main->id,
            'created_ids' => array_map(fn ($record) => (int) $record->id, $created),
            'created_expected' => $this->createdExpectedValues(collect($created)),
            'mutated' => $mutated,
        ]);

        return $main->fresh();
    }

    public function revertImport(int $importId, int $businessId): int
    {
        $rows = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->whereIn('source_type', array_keys(self::SOURCE_SUBTYPES))
            ->whereNotNull('source_mutation_snapshot')
            ->lockForUpdate()
            ->get();

        $reverted = 0;
        foreach ($rows as $row) {
            $snapshot = json_decode((string) $row->source_mutation_snapshot, true);
            if (! is_array($snapshot)) {
                throw new \RuntimeException('Snapshot transaksi bank tidak valid; revert dibatalkan agar data tidak tertimpa.');
            }
            if (($snapshot['action'] ?? null) === 'linked') {
                continue;
            }

            $mutated = $snapshot['mutated'] ?? [];
            foreach ($mutated as $recordId => $change) {
                $record = $this->findBusinessRecord((int) $recordId, $businessId, true);
                if (! $record) {
                    throw new \RuntimeException('Transaksi bank yang dilengkapi sudah tidak tersedia; revert dibatalkan.');
                }
                if (! $this->matchesValues($record, $change['expected'] ?? [])) {
                    throw new \RuntimeException('Transaksi bank yang dilengkapi berubah setelah import; revert dibatalkan agar perubahan terbaru tidak tertimpa.');
                }
            }

            foreach ($mutated as $recordId => $change) {
                $record = $this->findBusinessRecord((int) $recordId, $businessId, true);
                foreach (($change['before'] ?? []) as $field => $value) {
                    $record->{$field} = $value;
                }
                $record->save();
            }

            $createdIds = array_values(array_unique(array_map('intval', $snapshot['created_ids'] ?? [])));
            if ($createdIds !== []) {
                AccountTransaction::whereIn('id', $createdIds)
                    ->whereHas('account', fn ($query) => $query->where('business_id', $businessId))
                    ->get()
                    ->each(function (AccountTransaction $record) use ($snapshot) {
                        $expected = $snapshot['created_expected'][(string) $record->id] ?? null;
                        if (is_array($expected) && ! $this->matchesValues($record, $expected)) {
                            throw new \RuntimeException('Transaksi bank buatan import berubah setelah dibuat; revert dibatalkan agar perubahan terbaru tidak tertimpa.');
                        }
                        $record->delete();
                    });
            }
            $reverted++;
        }

        return $reverted;
    }

    private function prepare(object $row, array $entry, int $businessId, bool $lock): array
    {
        $sourceType = (string) ($entry['source_type'] ?? $row->source_type ?? '');
        if (! isset(self::SOURCE_SUBTYPES[$sourceType])) {
            throw new \RuntimeException('Jenis transaksi bank tidak didukung.');
        }
        $action = (string) ($row->source_action ?? '');
        if (! in_array($action, ['create', 'update', 'link'], true)) {
            throw new \RuntimeException('Pilih Buat baru, Lengkapi sumber, atau Tautkan sumber untuk memproses transaksi bank.');
        }

        $draftData = json_decode((string) ($row->draft_data ?? '{}'), true) ?: [];
        try {
            $draftData = $this->draftService->validateDraftData(
                $draftData,
                $businessId,
                $sourceType,
                empty($row->journal_import_id) ? null : (int) $row->journal_import_id
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $first = reset($errors);
            throw new \RuntimeException(is_array($first) ? (string) reset($first) : 'Detail transaksi bank tidak valid.');
        }

        $source = null;
        if ($action !== 'create') {
            if (empty($row->source_record_id)
                || ! $this->draftService->isCurrentSourceRecordAllowed($sourceType, AccountTransaction::class, (int) $row->source_record_id, $businessId)) {
                throw new \RuntimeException('Pilih sumber bank yang masih tersedia pada business aktif.');
            }
            $source = $this->findBusinessRecord((int) $row->source_record_id, $businessId, $lock);
            if (! $source || $source->sub_type !== self::SOURCE_SUBTYPES[$sourceType]) {
                throw new \RuntimeException('Sumber bank pilihan tidak cocok dengan jenis transaksi.');
            }
        }

        $journalTotal = $this->journalTotal($entry);
        if ($journalTotal === null || $journalTotal <= 0) {
            throw new \RuntimeException('Total debit Journal harus lebih besar dari nol untuk transaksi bank.');
        }

        $accountId = empty($draftData['payment_account_id']) ? null : (int) $draftData['payment_account_id'];
        $transferAccountId = empty($draftData['transfer_account_id']) ? null : (int) $draftData['transfer_account_id'];
        $reversalTargetId = empty($draftData['reversal_target_id']) ? null : (int) $draftData['reversal_target_id'];

        if ($action === 'create' && $accountId === null && $sourceType !== 'Reversal') {
            throw new \RuntimeException('Pilih akun bank/kas yang sudah ada pada business aktif.');
        }
        if ($accountId !== null) {
            $this->assertActiveAccount($accountId, $businessId);
        }
        if ($transferAccountId !== null) {
            $this->assertActiveAccount($transferAccountId, $businessId);
            if ($accountId !== null && $transferAccountId === $accountId) {
                throw new \RuntimeException('Akun asal dan tujuan harus berbeda.');
            }
        }

        $reversalTarget = null;
        if ($sourceType === 'Reversal') {
            $effectiveTargetId = $reversalTargetId ?? ($source ? (int) ($source->transfer_transaction_id ?? 0) : null);
            if (! $effectiveTargetId) {
                throw new \RuntimeException('Pilih transaksi bank yang akan dibalik.');
            }
            $reversalTarget = $source && $source->sub_type === 'reversal'
                && (int) ($source->transfer_transaction_id ?? 0) === $effectiveTargetId
                    ? $this->findBusinessRecord($effectiveTargetId, $businessId, $lock)
                    : $this->findReversibleTarget($effectiveTargetId, $businessId, $lock, (int) ($row->id ?? 0));
            if (! $reversalTarget) {
                throw new \RuntimeException('Target reversal tidak tersedia pada business aktif atau sudah pernah dibalik.');
            }
            if ($source && (int) ($source->transfer_transaction_id ?? 0) !== (int) $reversalTarget->id) {
                throw new \RuntimeException('Target reversal berbeda dari target yang tersimpan pada sumber; tinjau sumber sebelum melanjutkan.');
            }
        }

        if ($source && $accountId !== null && (int) $source->account_id !== $accountId) {
            throw new \RuntimeException('Akun bank pada sumber berbeda dari pilihan draft; tinjau sumber sebelum melanjutkan.');
        }

        if ($sourceType === 'Reversal' && $accountId !== null && (int) $reversalTarget->account_id !== $accountId) {
            throw new \RuntimeException('Reversal harus memakai akun dari transaksi yang dipilih sebagai target.');
        }

        $sourceNumber = trim((string) ($entry['source_number'] ?? $row->source_number ?? ''));
        if ($source && $sourceNumber !== '' && trim((string) $source->reff_no) !== ''
            && strcasecmp(trim((string) $source->reff_no), $sourceNumber) !== 0) {
            throw new \RuntimeException('Nomor referensi pada sumber berbeda dari Journal; tinjau sumber sebelum melanjutkan.');
        }
        if ($source) {
            $journalDate = $this->dateOnly($entry['date'] ?? null);
            $sourceDate = $this->dateOnly((string) $source->operation_date);
            if ($journalDate !== null && $sourceDate !== null && $journalDate !== $sourceDate) {
                throw new \RuntimeException('Tanggal sumber berbeda dari Journal; pilih sumber lain atau Buat baru setelah meninjau data.');
            }
        }

        if ($sourceType === 'Bank Transfer' && $source) {
            $pair = $this->counterpart($source, $businessId, $lock);
            if ($pair && $transferAccountId !== null && (int) $pair->account_id !== $transferAccountId) {
                throw new \RuntimeException('Akun tujuan transfer berbeda dari sumber; tinjau sumber sebelum melanjutkan.');
            }
            if ($pair && $transferAccountId === null) {
                $transferAccountId = (int) $pair->account_id;
            }
        }

        if ($sourceType === 'Bank Deposit' && $source && $transferAccountId !== null) {
            $pair = $this->counterpart($source, $businessId, $lock);
            if ($pair && (int) $pair->account_id !== $transferAccountId) {
                throw new \RuntimeException('Akun asal deposit berbeda dari sumber; tinjau sumber sebelum melanjutkan.');
            }
            if (! $pair && $action === 'link') {
                throw new \RuntimeException('Sumber deposit belum memiliki pasangan akun yang dipilih; gunakan Lengkapi sumber atau Buat baru.');
            }
        }
        if ($sourceType === 'Bank Transfer' && $transferAccountId === null) {
            throw new \RuntimeException('Pilih akun tujuan transfer yang sudah ada pada business aktif.');
        }
        if ($sourceType === 'Bank Transfer' && $source && $action === 'link'
            && $this->counterpart($source, $businessId, $lock) === null) {
            throw new \RuntimeException('Sumber transfer belum memiliki akun tujuan; gunakan Lengkapi sumber atau Buat baru.');
        }

        $amount = $reversalTarget ? round((float) $reversalTarget->amount, 4)
            : ($source ? round((float) $source->amount, 4) : $journalTotal);
        if ($amount <= 0) {
            throw new \RuntimeException('Nominal transaksi bank harus lebih besar dari nol.');
        }

        return compact('sourceType', 'action', 'draftData', 'source', 'journalTotal', 'accountId', 'transferAccountId', 'reversalTargetId', 'reversalTarget', 'amount');
    }

    private function createRecords(object $row, array $entry, object $import, array $prepared): array
    {
        $sourceType = $prepared['sourceType'];
        $amount = $prepared['amount'];
        $date = $this->operationDate($entry['date'] ?? null);
        $mainType = $this->mainLedgerType($sourceType, $prepared['reversalTarget']);
        $note = $this->note($entry, $sourceType);
        $main = $this->newRecord($row, $import, $prepared['accountId'] ?? (int) $prepared['reversalTarget']->account_id, $mainType, self::SOURCE_SUBTYPES[$sourceType], $amount, $date, $note, $entry, $prepared);
        $created = [$main];

        $counterpartAccountId = $sourceType === 'Bank Transfer'
            ? $prepared['transferAccountId']
            : ($sourceType === 'Bank Deposit' ? $prepared['transferAccountId'] : null);
        if ($counterpartAccountId !== null) {
            $counterpartType = $mainType === 'debit' ? 'credit' : 'debit';
            $counterpart = $this->newRecord($row, $import, $counterpartAccountId, $counterpartType, self::SOURCE_SUBTYPES[$sourceType], $amount, $date, $note, $entry, $prepared);
            $main->transfer_transaction_id = $counterpart->id;
            $counterpart->transfer_transaction_id = $main->id;
            $main->save();
            $counterpart->save();
            $created[] = $counterpart;
        } elseif ($sourceType === 'Reversal') {
            $main->transfer_transaction_id = (int) $prepared['reversalTarget']->id;
            $main->save();
        }

        return [$main, $created];
    }

    private function updateSource(object $row, array $entry, object $import, array $prepared): array
    {
        $main = $prepared['source'];
        $created = [];
        $mutated = [];
        $changes = [];
        foreach (['reff_no' => trim((string) ($entry['source_number'] ?? $row->source_number ?? '')), 'note' => $this->note($entry, $prepared['sourceType'])] as $field => $value) {
            if ($value !== '' && ($main->{$field} === null || trim((string) $main->{$field}) === '')) {
                $changes[$field] = $value;
            }
        }
        if ($changes !== []) {
            $this->mergeMutation($mutated, (int) $main->id, $this->applyMutation($main, $changes));
            $main = $main->fresh();
        }

        $selectedCounterpartId = $prepared['transferAccountId'];
        $pair = $this->counterpart($main, (int) $import->business_id, true);
        $needsPair = in_array($prepared['sourceType'], ['Bank Transfer', 'Bank Deposit'], true)
            && $selectedCounterpartId !== null && $pair === null;
        if ($needsPair) {
            $counterpartType = $main->type === 'debit' ? 'credit' : 'debit';
            $counterpart = $this->newRecord(
                $row,
                $import,
                $selectedCounterpartId,
                $counterpartType,
                self::SOURCE_SUBTYPES[$prepared['sourceType']],
                (float) $main->amount,
                $this->operationDate($entry['date'] ?? null),
                $this->note($entry, $prepared['sourceType']),
                $entry,
                $prepared
            );
            $this->mergeMutation($mutated, (int) $main->id, $this->applyMutation($main, ['transfer_transaction_id' => (int) $counterpart->id]));
            $counterpart->transfer_transaction_id = (int) $main->id;
            $counterpart->save();
            $created[] = $counterpart;
        }

        return [$main, $created, $mutated];
    }

    private function newRecord(object $row, object $import, int $accountId, string $type, string $subType, float $amount, string $date, string $note, array $entry, array $prepared): AccountTransaction
    {
        return AccountTransaction::create([
            'account_id' => $accountId,
            'type' => $type,
            'sub_type' => $subType,
            'amount' => $amount,
            'reff_no' => trim((string) ($entry['source_number'] ?? $row->source_number ?? '')) ?: null,
            'operation_date' => $date,
            'created_by' => (int) ($import->created_by ?? 0),
            'transfer_transaction_id' => null,
            'note' => $note !== '' ? $note : null,
            'journal_import_id' => (int) $import->id,
            'journal_import_row_id' => (int) $row->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function applyMutation(AccountTransaction $record, array $changes): array
    {
        $before = [];
        foreach ($changes as $field => $value) {
            $before[$field] = $record->{$field};
            $record->{$field} = $value;
        }
        $record->save();

        $expected = [];
        foreach (array_keys($changes) as $field) {
            $expected[$field] = $this->normalizeValue($field, $record->fresh()->{$field});
            $before[$field] = $this->normalizeValue($field, $before[$field]);
        }

        return compact('before', 'expected');
    }

    private function createdExpectedValues($created): array
    {
        $expected = [];
        foreach ($created as $record) {
            $expected[(string) $record->id] = $this->recordValues($record->fresh());
        }

        return $expected;
    }

    private function mergeMutation(array &$mutated, int $recordId, array $change): void
    {
        if (! isset($mutated[$recordId])) {
            $mutated[$recordId] = $change;
            return;
        }
        $mutated[$recordId]['before'] = array_merge($mutated[$recordId]['before'], $change['before']);
        $mutated[$recordId]['expected'] = array_merge($mutated[$recordId]['expected'], $change['expected']);
    }

    private function saveMutationSnapshot(object $row, array $snapshot): void
    {
        DB::table('accounting_journal_import_rows')
            ->where('id', $row->id)
            ->where('business_id', $row->business_id)
            ->update([
                'source_mutation_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_record_type' => AccountTransaction::class,
                'source_record_id' => $snapshot['primary_id'] ?? $snapshot['record_ids'][0] ?? $row->source_record_id,
                'updated_at' => now(),
            ]);
    }

    private function createdRecordsForRow(int $rowId, int $businessId)
    {
        return AccountTransaction::query()
            ->where('journal_import_row_id', $rowId)
            ->whereHas('account', fn ($query) => $query->where('business_id', $businessId))
            ->lockForUpdate()
            ->get();
    }

    private function findCreatedMain($records, array $prepared): AccountTransaction
    {
        $sourceType = $prepared['sourceType'];
        $type = $this->mainLedgerType($sourceType, $prepared['reversalTarget']);
        $main = $records->first(fn ($record) => $record->sub_type === self::SOURCE_SUBTYPES[$sourceType] && $record->type === $type);
        if (! $main) {
            throw new \RuntimeException('Ditemukan transaksi import parsial dengan arah ledger berbeda; periksa data sebelum mencoba ulang.');
        }

        return $main;
    }

    private function assertCreatedSetIsComplete($records, array $prepared): void
    {
        $needsPair = in_array($prepared['sourceType'], ['Bank Transfer', 'Bank Deposit'], true)
            && $prepared['transferAccountId'] !== null;
        if ($records->count() !== ($needsPair ? 2 : 1)) {
            throw new \RuntimeException('Ditemukan transaksi import parsial; periksa data sebelum mencoba ulang.');
        }
        if ($needsPair) {
            $main = $this->findCreatedMain($records, $prepared);
            $pair = $records->first(fn ($record) => (int) $record->id !== (int) $main->id);
            if ((int) ($main->transfer_transaction_id ?? 0) !== (int) $pair->id
                || (int) ($pair->transfer_transaction_id ?? 0) !== (int) $main->id) {
                throw new \RuntimeException('Pasangan transaksi import tidak lengkap; periksa data sebelum mencoba ulang.');
            }
        }
        if ($prepared['sourceType'] === 'Reversal') {
            $main = $this->findCreatedMain($records, $prepared);
            if ((int) ($main->transfer_transaction_id ?? 0) !== (int) $prepared['reversalTarget']->id) {
                throw new \RuntimeException('Target reversal dari transaksi import tidak konsisten; periksa data sebelum mencoba ulang.');
            }
        }
    }

    private function findReversibleTarget(int $recordId, int $businessId, bool $lock, int $currentRowId = 0): ?AccountTransaction
    {
        $target = $this->findBusinessRecord($recordId, $businessId, $lock);
        if (! $target || ! in_array($target->sub_type, ['deposit', 'fund_transfer', 'withdrawal'], true)) {
            return null;
        }

        $alreadyReversed = DB::table('account_transactions as reversal')
            ->join('accounts', 'accounts.id', '=', 'reversal.account_id')
            ->where('accounts.business_id', $businessId)
            ->whereNull('reversal.deleted_at')
            ->where('reversal.sub_type', 'reversal')
            ->where('reversal.transfer_transaction_id', $recordId)
            ->when($currentRowId > 0, fn ($query) => $query->where(function ($query) use ($currentRowId) {
                $query->whereNull('reversal.journal_import_row_id')
                    ->orWhere('reversal.journal_import_row_id', '<>', $currentRowId);
            }))
            ->exists();

        return $alreadyReversed ? null : $target;
    }

    private function findBusinessRecord(int $recordId, int $businessId, bool $lock = false): ?AccountTransaction
    {
        $query = AccountTransaction::query()
            ->whereHas('account', fn ($query) => $query->where('business_id', $businessId));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($recordId);
    }

    private function counterpart(AccountTransaction $record, int $businessId, bool $lock): ?AccountTransaction
    {
        if (empty($record->transfer_transaction_id)) {
            return null;
        }

        $counterpart = $this->findBusinessRecord((int) $record->transfer_transaction_id, $businessId, $lock);
        if (! $counterpart || $counterpart->sub_type !== $record->sub_type
            || (float) $counterpart->amount !== (float) $record->amount
            || $counterpart->type === $record->type) {
            throw new \RuntimeException('Pasangan transaksi bank tidak konsisten; tinjau sumber sebelum melanjutkan.');
        }

        return $counterpart;
    }

    private function assertActiveAccount(int $accountId, int $businessId): void
    {
        $account = DB::table('accounts')
            ->where('id', $accountId)
            ->where('business_id', $businessId)
            ->where('is_closed', 0)
            ->whereNull('deleted_at')
            ->exists();
        if (! $account) {
            throw new \RuntimeException('Akun bank/kas tidak aktif pada business ini.');
        }
    }

    private function mainLedgerType(string $sourceType, ?AccountTransaction $reversalTarget): string
    {
        if ($sourceType === 'Bank Deposit') {
            return 'credit';
        }
        if ($sourceType === 'Bank Withdrawal') {
            return 'debit';
        }
        if ($sourceType === 'Reversal') {
            return $reversalTarget && $reversalTarget->type === 'credit' ? 'debit' : 'credit';
        }

        return 'debit';
    }

    private function journalTotal(array $entry): ?float
    {
        if (isset($entry['debit_total']) && is_numeric($entry['debit_total'])) {
            return round((float) $entry['debit_total'], 4);
        }
        if (empty($entry['lines']) || ! is_array($entry['lines'])) {
            return null;
        }

        $total = collect($entry['lines'])
            ->filter(fn ($line) => strtolower((string) ($line['type'] ?? '')) === 'debit')
            ->sum(fn ($line) => (float) ($line['debit'] ?? $line['amount'] ?? 0));

        return is_finite((float) $total) ? round((float) $total, 4) : null;
    }

    private function operationDate($date): string
    {
        try {
            return \Carbon\Carbon::parse((string) $date)->toDateTimeString();
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Tanggal transaksi bank wajib tersedia dan harus valid.');
        }
    }

    private function dateOnly($date): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($date)->toDateString();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function note(array $entry, string $sourceType): string
    {
        $description = trim((string) ($entry['description'] ?? ''));
        return $sourceType === 'Reversal' && $description === '' ? 'Reversal transaksi bank' : $description;
    }

    private function recordValues(AccountTransaction $record): array
    {
        $fields = ['account_id', 'type', 'sub_type', 'amount', 'reff_no', 'operation_date', 'created_by', 'transfer_transaction_id', 'note', 'journal_import_id', 'journal_import_row_id'];
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $this->normalizeValue($field, $record->{$field});
        }

        return $values;
    }

    private function normalizeValue(string $field, $value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($field === 'amount' && $value !== null) {
            return number_format((float) $value, 4, '.', '');
        }

        return $value;
    }

    private function matchesValues(AccountTransaction $record, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            if ($this->normalizeValue((string) $field, $record->{$field}) !== $value) {
                return false;
            }
        }

        return true;
    }
}
