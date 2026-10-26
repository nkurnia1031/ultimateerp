<?php

namespace Modules\Accounting\Services;

use App\Transaction;
use App\TransactionPayment;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalImportPaymentService
{
    private $draftService;

    private $transactionUtil;

    public function __construct(JournalImportDraftService $draftService, TransactionUtil $transactionUtil)
    {
        $this->draftService = $draftService;
        $this->transactionUtil = $transactionUtil;
    }

    public function readinessIssue(object $row, array $entry, int $businessId, array $options = []): ?string
    {
        try {
            $dependencyIssue = $this->dependencyIssue($row, $entry, $businessId);
            if ($dependencyIssue !== null) {
                return $dependencyIssue;
            }
            $this->prepare($row, $entry, $businessId, $options, false);

            return null;
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }
    }

    public function dependencyIssue(object $row, array $entry, int $businessId): ?string
    {
        $sourceType = (string) ($row->source_type ?? '');
        if (! $this->isPaymentType($sourceType)) {
            return null;
        }

        $action = (string) ($row->source_action ?? '');
        $payment = null;
        if (in_array($action, ['update', 'link'], true) && ! empty($row->source_record_id)) {
            $payment = TransactionPayment::where('business_id', $businessId)->find((int) $row->source_record_id);
            if (! $payment) {
                return 'Pembayaran tertunda: sumber pembayaran sudah tidak tersedia pada business aktif.';
            }
            $invoice = $this->invoiceForPayment($payment, $sourceType, $businessId);
            if (! $invoice) {
                return 'Pembayaran tertunda: invoice induk tidak tersedia pada business aktif.';
            }

            return $this->payableInvoiceIssue($invoice);
        }

        $draftData = json_decode((string) ($row->draft_data ?? '{}'), true) ?: [];
        $invoiceReference = trim((string) ($draftData['invoice_reference'] ?? ''));
        if ($invoiceReference === '') {
            return null;
        }

        [$referenceType, $referenceId] = array_pad(explode(':', $invoiceReference, 2), 2, null);
        if ($referenceType === 'transaction' && ctype_digit((string) $referenceId)) {
            $invoice = $this->findInvoice((int) $referenceId, $sourceType, $businessId);
            if (! $invoice) {
                return 'Pembayaran tertunda: invoice pilihan tidak tersedia pada business aktif.';
            }

            return $this->payableInvoiceIssue($invoice);
        }

        if ($referenceType !== 'draft' || ! ctype_digit((string) $referenceId)) {
            return null;
        }

        $invoiceRow = DB::table('accounting_journal_import_rows')
            ->where('id', (int) $referenceId)
            ->where('journal_import_id', $row->journal_import_id ?? null)
            ->where('business_id', $businessId)
            ->where('source_type', $this->invoiceSourceType($sourceType))
            ->whereIn('source_action', ['create', 'update', 'link'])
            ->first(['id', 'processing_status', 'source_record_id']);
        if (! $invoiceRow) {
            return 'Pembayaran tertunda: baris invoice pada batch yang sama tidak tersedia.';
        }
        if ($invoiceRow->processing_status === 'queued') {
            return null;
        }
        if ($invoiceRow->processing_status !== 'completed' || empty($invoiceRow->source_record_id)) {
            return 'Pembayaran tertunda: invoice batch belum berhasil diproses.';
        }

        $invoice = $this->findInvoice((int) $invoiceRow->source_record_id, $sourceType, $businessId);
        if (! $invoice) {
            return 'Pembayaran tertunda: transaksi sumber invoice belum tersedia.';
        }

        return $this->payableInvoiceIssue($invoice);
    }

    public function draftTotal(object $row, array $entry, object $import, array $options = []): ?float
    {
        try {
            $prepared = $this->prepare($row, $entry, (int) $import->business_id, $options, false);
            if (in_array($prepared['action'], ['link', 'update'], true) && $prepared['payment'] !== null) {
                return (float) $prepared['payment']->amount;
            }

            return (float) $prepared['amount'];
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function process(object $row, array $entry, object $import, array $options = []): TransactionPayment
    {
        $prepared = $this->prepare($row, $entry, (int) $import->business_id, $options, true);
        if ($prepared['action'] === 'link') {
            $this->saveMutationSnapshot($row, [
                'action' => 'linked',
                'payment_id' => $prepared['payment']->id,
            ]);

            return $prepared['payment'];
        }

        if ($prepared['action'] === 'create') {
            $payment = $this->createPayment($entry, $import, $prepared);
            $this->saveMutationSnapshot($row, [
                'action' => 'created',
                'payment_id' => $payment->id,
                'invoice_id' => $prepared['invoice']->id,
                'payment_expected' => $this->paymentValues($payment),
                'invoice_before' => $prepared['invoiceBefore'],
                'invoice_expected' => $this->invoiceValues($prepared['invoice']->fresh()),
            ]);

            return $payment;
        }

        $snapshot = $this->updatePayment($prepared, $entry, $import);
        $snapshot['action'] = 'updated';
        $snapshot['payment_id'] = $prepared['payment']->id;
        $snapshot['invoice_id'] = $prepared['invoice']->id;
        $this->saveMutationSnapshot($row, $snapshot);

        return $prepared['payment']->fresh();
    }

    public function revertImport(int $importId, int $businessId): int
    {
        $rows = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->whereIn('source_type', ['Receive Payment', 'Purchase Payment'])
            ->whereNotNull('source_mutation_snapshot')
            ->lockForUpdate()
            ->get();

        $reverted = 0;
        foreach ($rows as $row) {
            $snapshot = json_decode((string) $row->source_mutation_snapshot, true);
            if (! is_array($snapshot)) {
                throw new \RuntimeException('Snapshot pembayaran tidak valid; revert dibatalkan agar data tidak tertimpa.');
            }
            if (($snapshot['action'] ?? null) === 'linked') {
                continue;
            }

            $payment = TransactionPayment::where('business_id', $businessId)
                ->lockForUpdate()
                ->find($snapshot['payment_id'] ?? null);
            if (! $payment) {
                continue;
            }
            if (! $this->samePaymentValues($this->paymentValues($payment), $snapshot['payment_expected'] ?? [])) {
                throw new \RuntimeException('Pembayaran sumber berubah setelah diproses Journal Import; tinjau perubahan sebelum revert batch.');
            }

            $invoice = Transaction::where('business_id', $businessId)
                ->lockForUpdate()
                ->find($snapshot['invoice_id'] ?? null);
            if (! $invoice || ! $this->sameInvoiceValues($this->invoiceValues($invoice), $snapshot['invoice_expected'] ?? [])) {
                throw new \RuntimeException('Invoice pembayaran berubah setelah diproses Journal Import; tinjau perubahan sebelum revert batch.');
            }

            if (($snapshot['action'] ?? null) === 'created') {
                DB::table('transaction_payments')->where('id', $payment->id)->where('business_id', $businessId)->delete();
            } elseif (($snapshot['action'] ?? null) === 'updated') {
                foreach ($snapshot['payment_before'] ?? [] as $field => $value) {
                    $payment->{$field} = $value;
                }
                $payment->save();
            } else {
                continue;
            }

            $invoice->payment_status = $snapshot['invoice_before']['payment_status'] ?? 'due';
            $invoice->save();
            $reverted++;
        }

        return $reverted;
    }

    private function prepare(object $row, array $entry, int $businessId, array $options, bool $lock): array
    {
        $sourceType = (string) ($row->source_type ?? '');
        if (! $this->isPaymentType($sourceType)) {
            throw new \RuntimeException('Handler pembayaran hanya mendukung Receive Payment dan Purchase Payment.');
        }

        $action = (string) ($row->source_action ?? 'review');
        if (! in_array($action, ['create', 'update', 'link'], true)) {
            throw new \RuntimeException('Pilih Buat baru, Lengkapi sumber, atau Tautkan pembayaran secara eksplisit.');
        }
        $recordType = $row->source_record_type ?? null;
        $recordId = empty($row->source_record_id) ? null : (int) $row->source_record_id;
        if (! $this->draftService->isValidSourceDecision($entry, $action, $recordType, $recordId)) {
            throw new \RuntimeException('Pilihan sumber pembayaran tidak valid; pilih kandidat yang tersedia atau Buat baru secara eksplisit.');
        }

        $payment = null;
        if (in_array($action, ['link', 'update'], true)) {
            if ($recordType !== TransactionPayment::class || $recordId === null) {
                throw new \RuntimeException('Pilih pembayaran yang akan ditautkan atau dilengkapi.');
            }
            $query = TransactionPayment::where('business_id', $businessId);
            if ($lock) {
                $query->lockForUpdate();
            }
            $payment = $query->find($recordId);
            if (! $payment) {
                throw new \RuntimeException('Pembayaran pilihan tidak ditemukan pada business aktif.');
            }
        } elseif ($recordType !== null || $recordId !== null) {
            throw new \RuntimeException('Buat pembayaran baru tidak boleh membawa sumber pembayaran yang sudah dipilih.');
        }

        $invoice = $payment ? $this->invoiceForPayment($payment, $sourceType, $businessId, $lock) : null;
        if ($payment && ! $invoice) {
            throw new \RuntimeException('Invoice induk pembayaran tidak ditemukan pada business aktif atau jenisnya tidak cocok.');
        }

        if ($action === 'link') {
            $this->assertLinkReady($payment, $invoice, $businessId);

            return compact('action', 'payment', 'invoice') + [
                'amount' => (float) $payment->amount,
                'invoice_before' => $this->invoiceValues($invoice),
                'draft_data' => [],
            ];
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
            throw new \RuntimeException(is_array($first) ? (string) reset($first) : 'Detail pembayaran tidak valid.');
        }

        $resolvedInvoice = $payment
            ? ['transaction' => $invoice, 'draft_row' => null]
            : $this->resolveInvoiceReference($draftData['invoice_reference'] ?? null, $row, $sourceType, $businessId, $lock);
        if (! $payment && $resolvedInvoice === null) {
            throw new \RuntimeException('Pilih invoice final yang akan menerima pembayaran.');
        }
        if ($payment && ! empty($draftData['invoice_reference'])) {
            $selectedInvoice = $this->resolveInvoiceReference($draftData['invoice_reference'], $row, $sourceType, $businessId, $lock);
            if ($selectedInvoice === null || $selectedInvoice['transaction'] === null
                || (int) $selectedInvoice['transaction']->id !== (int) $invoice->id) {
                throw new \RuntimeException('Invoice pembayaran berbeda dari invoice yang sudah terhubung; tinjau sumber sebelum melanjutkan.');
            }
        }

        $invoice = $resolvedInvoice['transaction'] ?? null;
        $invoiceDraftRow = $resolvedInvoice['draft_row'] ?? null;
        if ($invoice !== null) {
            $this->assertInvoiceType($invoice, $sourceType);
            $dependencyIssue = $this->payableInvoiceIssue($invoice);
            if ($dependencyIssue !== null) {
                throw new \RuntimeException($dependencyIssue);
            }
        } elseif ($invoiceDraftRow === null || $invoiceDraftRow->processing_status !== 'queued') {
            throw new \RuntimeException('Pembayaran tertunda: invoice batch belum berhasil diproses.');
        }

        $date = $this->normalizedDateOrNull($entry['date'] ?? null);
        if ($date === null) {
            throw new \RuntimeException('Tanggal pembayaran wajib tersedia dan harus valid.');
        }
        $journalTotal = $this->journalTotal($entry);
        if ($journalTotal === null || $journalTotal <= 0) {
            throw new \RuntimeException('Total debit Journal harus lebih besar dari nol untuk membuat pembayaran.');
        }

        $sourceNumber = trim((string) ($entry['source_number'] ?? $row->source_number ?? ''));
        $draftMethod = trim((string) ($draftData['payment_method'] ?? ''));
        $draftAccountId = empty($draftData['payment_account_id']) ? null : (int) $draftData['payment_account_id'];
        $effectiveMethod = $payment && trim((string) $payment->method) !== ''
            ? trim((string) $payment->method)
            : $draftMethod;
        $effectiveAccountId = $payment && ! empty($payment->account_id)
            ? (int) $payment->account_id
            : $draftAccountId;

        if ($effectiveMethod === '') {
            throw new \RuntimeException('Pilih metode pembayaran yang tersedia sebelum melanjutkan.');
        }
        if (! in_array($effectiveMethod, $this->supportedMethods(), true)) {
            throw new \RuntimeException('Metode pembayaran tidak tersedia atau tidak didukung.');
        }
        $locationPaymentMethods = app(\App\Utils\Util::class)->payment_types(
            $invoice ? $invoice->location_id : null,
            false,
            $businessId
        );
        if (! array_key_exists($effectiveMethod, $locationPaymentMethods)) {
            throw new \RuntimeException('Metode pembayaran tidak aktif pada lokasi invoice yang dipilih.');
        }
        if ($draftMethod !== '' && $payment && trim((string) $payment->method) !== '' && $draftMethod !== trim((string) $payment->method)) {
            throw new \RuntimeException('Metode pembayaran sumber berbeda dari pilihan draft; tinjau sumber sebelum melanjutkan.');
        }
        if ($effectiveAccountId === null) {
            throw new \RuntimeException('Pilih akun pembayaran yang sudah ada pada business aktif.');
        }
        $account = DB::table('accounts')
            ->where('id', $effectiveAccountId)
            ->where('business_id', $businessId)
            ->where('is_closed', 0)
            ->whereNull('deleted_at')
            ->first(['id']);
        if (! $account) {
            throw new \RuntimeException('Akun pembayaran tidak aktif pada business ini.');
        }
        if ($draftAccountId !== null && $payment && ! empty($payment->account_id) && $draftAccountId !== (int) $payment->account_id) {
            throw new \RuntimeException('Akun pembayaran sumber berbeda dari pilihan draft; tinjau sumber sebelum melanjutkan.');
        }

        if ($invoice !== null && empty($invoice->contact_id)) {
            throw new \RuntimeException('Invoice belum memiliki kontak; lengkapi sumber invoice sebelum membuat pembayaran.');
        }
        if ($payment && ! empty($payment->payment_for) && (int) $payment->payment_for !== (int) $invoice->contact_id) {
            throw new \RuntimeException('Kontak pembayaran berbeda dari kontak invoice; tinjau sumber sebelum melanjutkan.');
        }
        if (! empty($draftData['contact_id']) && $invoice !== null && (int) $draftData['contact_id'] !== (int) $invoice->contact_id) {
            throw new \RuntimeException('Kontak pembayaran harus sama dengan kontak invoice yang dipilih.');
        }

        if ($payment) {
            $this->assertNoConflictingValues($payment, $invoice, $sourceNumber, $date, $draftMethod, $draftAccountId);
            if ((int) ($payment->is_return ?? 0) === 1 || (int) ($payment->is_advance ?? 0) === 1 || $payment->method === 'advance') {
                throw new \RuntimeException('Pembayaran retur atau uang muka tidak dapat dilengkapi sebagai payment invoice Journal Import.');
            }
        }

        $amount = $payment && is_numeric($payment->amount) && (float) $payment->amount > 0
            ? round((float) $payment->amount, 4)
            : $journalTotal;
        $reference = $payment && trim((string) $payment->payment_ref_no) !== ''
            ? trim((string) $payment->payment_ref_no)
            : $sourceNumber;
        $invoiceBefore = $invoice === null ? null : $this->invoiceValues($invoice);

        if ($invoice !== null && $action === 'create') {
            $this->assertAmountWithinOutstanding($invoice, $amount, null);
        } elseif ($invoice !== null && $payment && (! is_numeric($payment->amount) || (float) $payment->amount <= 0)) {
            $this->assertAmountWithinOutstanding($invoice, $amount, (int) $payment->id);
        }

        return compact(
            'action', 'payment', 'invoice', 'invoiceDraftRow', 'draftData', 'date', 'sourceNumber',
            'draftMethod', 'draftAccountId', 'effectiveMethod', 'effectiveAccountId', 'journalTotal',
            'amount', 'reference', 'invoiceBefore'
        );
    }

    private function createPayment(array $entry, object $import, array $prepared): TransactionPayment
    {
        $reference = $prepared['reference'];
        if ($reference === '') {
            $prefix = $prepared['invoice']->type === 'purchase' ? 'purchase_payment' : 'sell_payment';
            $count = $this->transactionUtil->setAndGetReferenceCount($prefix, (int) $import->business_id);
            $reference = $this->transactionUtil->generateReferenceNumber($prefix, $count, (int) $import->business_id);
        }

        $payment = TransactionPayment::create([
            'transaction_id' => (int) $prepared['invoice']->id,
            'business_id' => (int) $import->business_id,
            'is_return' => 0,
            'amount' => $prepared['amount'],
            'method' => $prepared['effectiveMethod'],
            'payment_type' => null,
            'paid_on' => $prepared['date'],
            'created_by' => (int) $import->created_by,
            'paid_through_link' => 0,
            'is_advance' => 0,
            'payment_for' => (int) $prepared['invoice']->contact_id,
            'note' => trim((string) ($entry['description'] ?? '')) ?: null,
            'payment_ref_no' => $reference,
            'account_id' => (int) $prepared['effectiveAccountId'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Avoid TransactionPaymentAdded: it posts account and accounting GL rows.
        $this->transactionUtil->updatePaymentStatus($prepared['invoice']->id, $prepared['invoice']->final_total);

        return $payment->fresh();
    }

    private function updatePayment(array $prepared, array $entry, object $import): array
    {
        $payment = $prepared['payment'];
        $before = [];
        $expected = [];
        $values = [
            'transaction_id' => $prepared['invoice']->id,
            'business_id' => (int) $import->business_id,
            'payment_for' => (int) $prepared['invoice']->contact_id,
            'amount' => $prepared['amount'],
            'method' => $prepared['effectiveMethod'],
            'account_id' => $prepared['effectiveAccountId'],
            'paid_on' => $prepared['date'],
            'payment_ref_no' => $prepared['reference'] !== '' ? $prepared['reference'] : null,
            'created_by' => (int) $import->created_by,
            'note' => trim((string) ($entry['description'] ?? '')) ?: null,
        ];

        foreach ($values as $field => $value) {
            $existing = $payment->{$field};
            $isMissing = $existing === null || $existing === '' || ($field === 'amount' && (float) $existing <= 0);
            if (! $isMissing || $value === null || $value === '') {
                continue;
            }
            $before[$field] = $existing;
            $payment->{$field} = $value;
            $expected[$field] = $value;
        }
        $payment->save();
        $this->transactionUtil->updatePaymentStatus($prepared['invoice']->id, $prepared['invoice']->final_total);

        return [
            'payment_before' => $before,
            'payment_expected' => $this->paymentValues($payment->fresh()),
            'invoice_before' => $prepared['invoiceBefore'],
            'invoice_expected' => $this->invoiceValues($prepared['invoice']->fresh()),
        ];
    }

    private function assertLinkReady(TransactionPayment $payment, Transaction $invoice, int $businessId): void
    {
        $dependencyIssue = $this->payableInvoiceIssue($invoice);
        if ($dependencyIssue !== null) {
            throw new \RuntimeException($dependencyIssue);
        }
        if (! is_numeric($payment->amount) || (float) $payment->amount <= 0
            || trim((string) $payment->method) === ''
            || empty($payment->account_id)
            || empty($payment->paid_on)
            || empty($payment->payment_ref_no)) {
            throw new \RuntimeException('Pembayaran sumber belum memiliki nominal, metode, akun, tanggal, atau referensi lengkap; pilih Lengkapi sumber.');
        }
        if ((int) ($payment->is_return ?? 0) === 1 || (int) ($payment->is_advance ?? 0) === 1 || $payment->method === 'advance') {
            throw new \RuntimeException('Pembayaran retur atau uang muka tidak dapat ditautkan sebagai payment invoice Journal Import.');
        }
        $accountExists = DB::table('accounts')
            ->where('id', $payment->account_id)
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->exists();
        if (! $accountExists) {
            throw new \RuntimeException('Akun pada pembayaran sumber tidak tersedia pada business aktif.');
        }
        if ((int) $payment->payment_for !== (int) $invoice->contact_id) {
            throw new \RuntimeException('Kontak pembayaran berbeda dari kontak invoice; tinjau sumber sebelum melanjutkan.');
        }
    }

    private function assertNoConflictingValues(
        TransactionPayment $payment,
        Transaction $invoice,
        string $sourceNumber,
        string $date,
        string $draftMethod,
        ?int $draftAccountId
    ): void {
        if ((int) $payment->transaction_id !== (int) $invoice->id) {
            throw new \RuntimeException('Pembayaran terhubung ke invoice lain; tinjau sumber sebelum melanjutkan.');
        }
        if ($sourceNumber !== '' && trim((string) $payment->payment_ref_no) !== ''
            && strcasecmp(trim((string) $payment->payment_ref_no), $sourceNumber) !== 0) {
            throw new \RuntimeException('Nomor pembayaran berbeda dari Journal; tinjau sumber sebelum melanjutkan.');
        }
        if ($date !== '' && ! empty($payment->paid_on) && $this->normalizedDateOrNull((string) $payment->paid_on) !== $date) {
            throw new \RuntimeException('Tanggal pembayaran berbeda dari Journal; tinjau sumber sebelum melanjutkan.');
        }
        if ($draftMethod !== '' && trim((string) $payment->method) !== '' && $draftMethod !== trim((string) $payment->method)) {
            throw new \RuntimeException('Metode pembayaran berbeda dari sumber; tinjau sumber sebelum melanjutkan.');
        }
        if ($draftAccountId !== null && ! empty($payment->account_id) && $draftAccountId !== (int) $payment->account_id) {
            throw new \RuntimeException('Akun pembayaran berbeda dari sumber; tinjau sumber sebelum melanjutkan.');
        }
        if (! empty($payment->payment_for) && ! empty($invoice->contact_id)
            && (int) $payment->payment_for !== (int) $invoice->contact_id) {
            throw new \RuntimeException('Kontak pembayaran berbeda dari kontak invoice; tinjau sumber sebelum melanjutkan.');
        }
    }

    private function resolveInvoiceReference(?string $reference, object $row, string $sourceType, int $businessId, bool $lock): ?array
    {
        if (! is_string($reference) || ! preg_match('/^(transaction|draft):([1-9][0-9]*)$/', $reference, $matches)) {
            return null;
        }

        if ($matches[1] === 'transaction') {
            $invoice = $this->findInvoice((int) $matches[2], $sourceType, $businessId, $lock);

            return $invoice ? ['transaction' => $invoice, 'draft_row' => null] : null;
        }

        $query = DB::table('accounting_journal_import_rows')
            ->where('id', (int) $matches[2])
            ->where('journal_import_id', $row->journal_import_id ?? null)
            ->where('business_id', $businessId)
            ->where('source_type', $this->invoiceSourceType($sourceType))
            ->whereIn('source_action', ['create', 'update', 'link']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $invoiceRow = $query->first();
        if (! $invoiceRow) {
            return null;
        }
        if ($invoiceRow->processing_status === 'completed' && ! empty($invoiceRow->source_record_id)) {
            $invoice = $this->findInvoice((int) $invoiceRow->source_record_id, $sourceType, $businessId, $lock);

            return $invoice ? ['transaction' => $invoice, 'draft_row' => $invoiceRow] : null;
        }

        return ['transaction' => null, 'draft_row' => $invoiceRow];
    }

    private function findInvoice(int $invoiceId, string $sourceType, int $businessId, bool $lock = false): ?Transaction
    {
        $query = Transaction::where('business_id', $businessId)
            ->where('type', $sourceType === 'Receive Payment' ? 'sell' : 'purchase');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($invoiceId);
    }

    private function invoiceForPayment(TransactionPayment $payment, string $sourceType, int $businessId, bool $lock = false): ?Transaction
    {
        if (empty($payment->transaction_id)) {
            return null;
        }

        return $this->findInvoice((int) $payment->transaction_id, $sourceType, $businessId, $lock);
    }

    private function assertInvoiceType(Transaction $invoice, string $sourceType): void
    {
        $expectedType = $sourceType === 'Receive Payment' ? 'sell' : 'purchase';
        if ($invoice->type !== $expectedType) {
            throw new \RuntimeException('Jenis invoice tidak cocok dengan jenis pembayaran.');
        }
    }

    private function payableInvoiceIssue(Transaction $invoice): ?string
    {
        if ($invoice->status !== 'final') {
            return 'Pembayaran tertunda: invoice belum berstatus final. Finalisasi invoice lalu antrekan ulang pembayaran.';
        }
        if (! is_numeric($invoice->final_total) || (float) $invoice->final_total <= 0 || empty($invoice->contact_id)) {
            return 'Pembayaran tertunda: invoice belum memiliki total atau kontak yang valid.';
        }

        return null;
    }

    private function assertAmountWithinOutstanding(Transaction $invoice, float $amount, ?int $excludePaymentId): void
    {
        $query = DB::table('transaction_payments')
            ->where('transaction_id', $invoice->id)
            ->where('business_id', $invoice->business_id)
            ->where('is_return', 0);
        if ($excludePaymentId !== null) {
            $query->where('id', '<>', $excludePaymentId);
        }
        $paid = (float) $query->sum('amount');
        $outstanding = max(0, (float) $invoice->final_total - $paid);
        if ($amount - $outstanding > 0.01) {
            throw new \RuntimeException('Nominal pembayaran melebihi sisa tagihan invoice.');
        }
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

    private function normalizedDateOrNull($date): ?string
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

    private function isPaymentType(string $sourceType): bool
    {
        return in_array($sourceType, ['Receive Payment', 'Purchase Payment'], true);
    }

    private function invoiceSourceType(string $sourceType): ?string
    {
        return $sourceType === 'Receive Payment' ? 'Sales Invoice' : ($sourceType === 'Purchase Payment' ? 'Purchase Invoice' : null);
    }

    private function supportedMethods(): array
    {
        return ['cash', 'card', 'cheque', 'bank_transfer', 'other', 'custom_pay_1', 'custom_pay_2', 'custom_pay_3', 'custom_pay_4', 'custom_pay_5', 'custom_pay_6', 'custom_pay_7'];
    }

    private function paymentValues(TransactionPayment $payment): array
    {
        return [
            'transaction_id' => (int) $payment->transaction_id,
            'business_id' => (int) $payment->business_id,
            'payment_for' => $payment->payment_for === null ? null : (int) $payment->payment_for,
            'amount' => number_format((float) $payment->amount, 4, '.', ''),
            'method' => $payment->method,
            'account_id' => $payment->account_id === null ? null : (int) $payment->account_id,
            'paid_on' => $this->normalizedDateOrNull((string) $payment->paid_on),
            'payment_ref_no' => $payment->payment_ref_no,
            'is_return' => (int) $payment->is_return,
            'is_advance' => (int) $payment->is_advance,
            'note' => $payment->note,
        ];
    }

    private function samePaymentValues(array $actual, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            if (! array_key_exists($field, $actual) || $actual[$field] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function invoiceValues(Transaction $invoice): array
    {
        return [
            'id' => (int) $invoice->id,
            'business_id' => (int) $invoice->business_id,
            'type' => (string) $invoice->type,
            'status' => (string) $invoice->status,
            'contact_id' => $invoice->contact_id === null ? null : (int) $invoice->contact_id,
            'final_total' => $invoice->final_total === null ? null : number_format((float) $invoice->final_total, 4, '.', ''),
            'payment_status' => (string) $invoice->payment_status,
        ];
    }

    private function sameInvoiceValues(array $actual, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            if (! array_key_exists($field, $actual) || $actual[$field] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function saveMutationSnapshot(object $row, array $snapshot): void
    {
        DB::table('accounting_journal_import_rows')
            ->where('id', $row->id)
            ->where('business_id', $row->business_id)
            ->update([
                'source_mutation_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_record_type' => TransactionPayment::class,
                'source_record_id' => $snapshot['payment_id'],
                'updated_at' => now(),
            ]);
    }
}
