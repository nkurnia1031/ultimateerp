<?php

namespace Modules\Accounting\Services;

use App\AccountTransaction;
use App\Transaction;
use App\TransactionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GeneralLedgerBackfillService
{
    private $accountComponentService;

    private $mainAccountCache = [];

    private $accountingAccountsCache = [];

    private $locationMapsCache = [];

    private $vatAccountMappingsCache = [];

    private $expenseCategoriesCache = [];

    private $expensePaymentGroupsCache = [];

    private $reviewQueueBuffer = [];

    private bool $legacyRecheckMode = false;

    private ?int $legacyRecheckJournalImportId = null;

    private int $legacyRecheckPriorReviewCount = 0;

    private $legacyRecheckProgressCallback = null;

    private array $legacyJournalPairCache = [];

    private array $legacyRecheckTotals = [];

    private const REVIEW_QUEUE_TABLE = 'accounting_general_ledger_backfill_reviews';

    public function __construct(JournalImportAccountComponentService $accountComponentService)
    {
        $this->accountComponentService = $accountComponentService;
    }

    public function run(
        int $businessId,
        bool $apply = false,
        bool $legacyRecheck = false,
        ?int $journalImportId = null,
        ?callable $progressCallback = null,
        ?GeneralLedgerBackfillCheckpoint $resumeCheckpoint = null
    ): array
    {
        $this->reviewQueueBuffer = [];
        $this->legacyRecheckMode = $legacyRecheck && $apply;
        $this->legacyRecheckJournalImportId = $journalImportId;
        $this->legacyRecheckPriorReviewCount = 0;
        if ($legacyRecheck && $apply && $resumeCheckpoint !== null) {
            $this->legacyRecheckPriorReviewCount = $resumeCheckpoint->count('actionable_review_sources');
        }
        $this->legacyRecheckProgressCallback = $progressCallback;
        $this->legacyJournalPairCache = [];
        $this->legacyRecheckTotals = [];

        $business = DB::table('business')->where('id', $businessId)->first(['id', 'name']);
        if (! $business) {
            return ['error' => 'Business ID tidak ditemukan.'];
        }

        if (! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_type')
            || ! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_id')) {
            return ['error' => 'Migrasi source link Journal belum tersedia. Jalankan migration aplikasi terlebih dahulu.'];
        }

        if ($apply && ! Schema::hasTable(self::REVIEW_QUEUE_TABLE)) {
            return ['error' => 'Migrasi antrean review backfill belum tersedia. Jalankan migration aplikasi terlebih dahulu.'];
        }

        if ($legacyRecheck && ! $apply) {
            return ['error' => 'Recheck legacy hanya boleh berjalan dalam mode apply setelah preview/konfirmasi coordinator.'];
        }

        $this->loadBusinessContext($businessId);
        if ($this->legacyRecheckMode) {
            $this->legacyRecheckTotals = $this->legacyRecheckPhaseTotals($businessId);
        }

        $summary = [
            'business_name' => (string) $business->name,
            'candidate_groups' => 0,
            'planned_groups' => 0,
            'applied_groups' => 0,
            'already_linked' => 0,
            'planned_rows' => 0,
            'account_links' => [],
            'current_map_details' => [],
            'current_map_groups' => 0,
            'auto_map_details' => [],
            'auto_mapped_groups' => 0,
            'ambiguous_vat_accounts' => 0,
            'missing_vat_accounts' => 0,
            'skipped' => [],
            'review_source_keys' => [],
            'debit_total' => 0.0,
            'credit_total' => 0.0,
            'date_start' => null,
            'date_end' => null,
            'journal_linked_sources' => 0,
            'removed_duplicate_app_postings' => $resumeCheckpoint?->removedDuplicateAppPostings() ?? 0,
            'replaced_operational_postings' => 0,
            'operational_evidence_groups' => 0,
            'journal_pattern_groups' => 0,
        ];
        if ($this->legacyRecheckMode && $resumeCheckpoint !== null) {
            foreach ([
                'candidate_groups', 'planned_groups', 'applied_groups', 'already_linked',
                'journal_linked_sources', 'removed_duplicate_app_postings', 'replaced_operational_postings',
                'operational_evidence_groups', 'actionable_review_sources',
            ] as $key) {
                if ($resumeCheckpoint->hasCount($key)) {
                    $summary[$key] = $resumeCheckpoint->count($key);
                }
            }
        }
        $summary['account_mapping_audit'] = $this->auditMainAccountMappings($businessId);

        $phases = [
            'gl_duplicates' => null,
            'gl_transactions' => fn (?int $afterId) => $this->transactionCandidates($businessId, null, $afterId),
            'gl_payments' => fn (?int $afterId) => $this->paymentCandidates($businessId, null, $afterId),
            'gl_transfers' => fn (?int $afterId) => $this->bankTransferCandidates($businessId, null, $afterId),
        ];
        $resumePhase = $resumeCheckpoint?->phase() ?? ($this->legacyRecheckMode ? 'gl_duplicates' : 'gl_transactions');
        $phaseNames = array_keys($phases);
        $resumePhaseIndex = array_search($resumePhase, $phaseNames, true);
        if ($resumePhaseIndex === false) {
            $resumePhaseIndex = $resumePhase === 'recheck_complete' ? count($phaseNames) : 0;
        }
        if ($this->legacyRecheckMode && $resumePhaseIndex <= 0) {
            $duplicateCheckpoint = $resumePhase === 'gl_duplicates' ? $resumeCheckpoint : null;
            $duplicateResult = $this->removeProvenJournalDuplicates(
                $businessId,
                $journalImportId,
                $duplicateCheckpoint?->cursor(),
                $duplicateCheckpoint?->processed() ?? 0,
                $duplicateCheckpoint?->count('removed_duplicate_app_postings') ?? 0,
                function (int $processed, int $total, ?int $cursor, int $removed, bool $completed) use ($summary) {
                    $phaseSummary = $summary;
                    $phaseSummary['removed_duplicate_app_postings'] = $removed;
                    $this->reportLegacyRecheckProgress($phaseSummary, 'gl_duplicates', $cursor, $processed, $total, $completed);
                }
            );
            $summary['removed_duplicate_app_postings'] = $duplicateResult['removed'];
        }
        foreach ($phaseNames as $phaseIndex => $phase) {
            if ($phase === 'gl_duplicates') {
                continue;
            }
            if ($phaseIndex < $resumePhaseIndex) {
                continue;
            }
            $afterId = $phaseIndex === $resumePhaseIndex && $resumeCheckpoint?->cursor() !== null
                ? $resumeCheckpoint->cursor()
                : null;
            $phaseProcessed = $phaseIndex === $resumePhaseIndex
                ? ($resumeCheckpoint?->processed() ?? 0)
                : 0;
            $lastCursor = $afterId;
            foreach ($phases[$phase]($afterId) as $candidate) {
                $this->processCandidate($candidate, $businessId, $apply, $summary);
                $lastCursor = $this->candidateSourceId($candidate) ?? $lastCursor;
                $phaseProcessed++;
                $this->reportLegacyRecheckProgress(
                    $summary,
                    $phase,
                    $lastCursor,
                    $phaseProcessed,
                    $this->legacyRecheckTotals[$phase] ?? null,
                    false
                );
            }
            $this->reportLegacyRecheckProgress(
                $summary,
                $phase,
                $lastCursor,
                $phaseProcessed,
                $this->legacyRecheckTotals[$phase] ?? null,
                true
            );
        }

        foreach ($this->unpairedMainAccountMovements($businessId) as $reason => $count) {
            if ($count > 0) {
                $summary['skipped'][$reason] = ($summary['skipped'][$reason] ?? 0) + $count;
            }
        }

        $this->reviewUnpairedMainAccountMovements($businessId, $apply, $summary);
        $this->reviewInvalidTransfers($businessId, $apply, $summary);
        if ($apply) {
            $this->flushReviewQueue();
        }

        $summary['account_links'] = array_values($summary['account_links']);
        $summary['current_map_details'] = array_values($summary['current_map_details']);
        $summary['auto_map_details'] = array_values($summary['auto_map_details']);
        $summary['actionable_review_sources'] = ($this->legacyRecheckMode ? $this->legacyRecheckPriorReviewCount : 0)
            + count($summary['review_source_keys']);
        unset($summary['review_source_keys']);
        $summary['date_range'] = $summary['date_start'] === null
            ? '—'
            : $summary['date_start'].' — '.$summary['date_end'];
        unset($summary['date_start'], $summary['date_end']);

        $this->legacyRecheckMode = false;
        $this->legacyRecheckJournalImportId = null;
        $this->legacyRecheckPriorReviewCount = 0;
        $this->legacyRecheckProgressCallback = null;
        $this->legacyJournalPairCache = [];
        $this->legacyRecheckTotals = [];

        return $summary;
    }

    /** Build a read-only posting preview for persisted review sources. */
    public function previewReviewItems(int $businessId, array $reviewIds): array
    {
        $reviewIds = array_values(array_unique(array_map('intval', $reviewIds)));
        if ($reviewIds === []) {
            return [];
        }
        if (! DB::table('business')->where('id', $businessId)->exists()) {
            return array_map(fn ($id) => $this->unavailablePreview($id, 'Business sumber tidak ditemukan.'), $reviewIds);
        }

        $this->loadBusinessContext($businessId);
        $this->reviewQueueBuffer = [];
        $reviews = DB::table(self::REVIEW_QUEUE_TABLE)
            ->where('business_id', $businessId)
            ->whereIn('id', $reviewIds)
            ->get()
            ->keyBy('id');

        $previews = [];
        foreach ($reviewIds as $reviewId) {
            $review = $reviews->get($reviewId);
            if (! $review) {
                $previews[] = $this->unavailablePreview($reviewId, 'Sumber tidak ditemukan atau bukan milik business aktif.');
                continue;
            }
            if ((string) $review->status !== 'pending') {
                $previews[] = $this->unavailablePreview($reviewId, 'Sumber tidak lagi berstatus menunggu.');
                continue;
            }

            $candidate = $this->candidateForReview($businessId, $review);
            if ($candidate === null) {
                $previews[] = $this->unavailablePreview(
                    $reviewId,
                    $this->reviewSourceAlreadyLinked($this->reviewSourceReference($businessId, $review))
                        ? 'Sumber sudah tertaut ke jurnal.'
                        : 'Sumber operasional tidak lagi tersedia untuk diposting.',
                    $review
                );
                continue;
            }

            $previews[] = $this->previewCandidate($businessId, $review, $candidate);
        }

        return $previews;
    }

    /** Retry one source only when its current posting plan matches the confirmed preview. */
    public function retryReviewItem(int $businessId, int $reviewId, string $expectedPlanHash, int $appliedByUserId): array
    {
        $review = DB::table(self::REVIEW_QUEUE_TABLE)
            ->where('business_id', $businessId)
            ->where('id', $reviewId)
            ->first();
        if (! $review) {
            return ['resolved' => false, 'reason' => 'Item antrean tidak lagi menunggu.'];
        }
        if ((string) $review->status === 'resolved') {
            return ['resolved' => true, 'reason' => 'Sumber sudah ditautkan ke jurnal.'];
        }
        if (! in_array((string) $review->status, ['pending', 'retrying'], true)) {
            return ['resolved' => false, 'reason' => 'Status item antrean tidak dapat dicoba ulang.'];
        }

        if (! DB::table('business')->where('id', $businessId)->exists()) {
            return ['resolved' => false, 'reason' => 'Business sumber tidak ditemukan.'];
        }

        $this->reviewQueueBuffer = [];
        $this->loadBusinessContext($businessId);
        $candidate = $this->candidateForReview($businessId, $review);
        $source = $this->reviewSourceReference($businessId, $review);
        if ($candidate === null) {
            if ($this->reviewSourceAlreadyLinked($source)) {
                $this->resolveExistingReviewSource($source, $appliedByUserId);

                return ['resolved' => true, 'reason' => 'Sumber sudah tertaut ke jurnal.'];
            }

            return ['resolved' => false, 'reason' => 'Sumber operasional tidak lagi tersedia untuk diposting.'];
        }

        $preview = $this->previewCandidate($businessId, $review, $candidate);
        if (! $preview['eligible']) {
            return ['resolved' => false, 'reason' => (string) $preview['reason']];
        }
        if (! hash_equals((string) $expectedPlanHash, (string) $preview['plan_hash'])) {
            return ['resolved' => false, 'reason' => 'Mapping atau transaksi berubah sejak pratinjau. Buat pratinjau baru sebelum memproses.'];
        }

        if ($appliedByUserId < 1) {
            return ['resolved' => false, 'reason' => 'Identitas pengguna yang mengonfirmasi proses tidak tersedia.'];
        }
        $candidate['created_by'] = $appliedByUserId;
        $candidate['applied_by'] = $appliedByUserId;
        $summary = $this->emptyCandidateSummary();
        $this->processCandidate($candidate, $businessId, true, $summary);
        $this->flushReviewQueue();

        if ($this->sourceAlreadyLinked($candidate) || $this->hasLegacyLedgerRows($candidate)) {
            $this->resolveExistingReviewSource($candidate, $appliedByUserId);
        }

        $updated = DB::table(self::REVIEW_QUEUE_TABLE)
            ->where('business_id', $businessId)
            ->where('id', $reviewId)
            ->first(['status', 'skip_reason']);

        return [
            'resolved' => $updated && (string) $updated->status === 'resolved',
            'reason' => (string) ($updated->skip_reason ?? $review->skip_reason),
        ];
    }

    private function candidateForReview(int $businessId, object $review): ?array
    {
        $candidates = match ((string) $review->source_record_type) {
            Transaction::class => $this->transactionCandidates($businessId, (int) $review->source_record_id),
            TransactionPayment::class => $this->paymentCandidates($businessId, (int) $review->source_record_id),
            AccountTransaction::class => $this->bankTransferCandidates($businessId, (int) $review->source_record_id),
            default => [],
        };

        foreach ($candidates as $candidate) {
            return $candidate;
        }

        return null;
    }

    private function previewCandidate(int $businessId, object $review, array $candidate): array
    {
        if (! empty($candidate['uses_auto_map']) || ! empty($candidate['account_links'])) {
            $preview = $this->unavailablePreview(
                (int) $review->id,
                'Pemetaan CoA otomatis perlu dikonfirmasi dengan mapping eksplisit sebelum retry.',
                $review
            );
            $mapInfo = $candidate['map_info'] ?? [];
            foreach (['payment_account', 'deposit_to'] as $role) {
                $idKey = $role.'_id';
                $nameKey = $role.'_name';
                if (! empty($mapInfo[$idKey]) && ! empty($mapInfo[$nameKey])) {
                    $preview['suggested_accounts'][] = [
                        'role' => $role === 'payment_account' ? 'Akun pasangan' : 'Akun transaksi',
                        'accounting_account_id' => (int) $mapInfo[$idKey],
                        'account_name' => (string) $mapInfo[$nameKey],
                        'method' => (string) ($mapInfo[$role.'_method'] ?? $mapInfo['mapping_source'] ?? ''),
                    ];
                }
            }

            return $preview;
        }

        $summary = $this->emptyCandidateSummary();
        $this->processCandidate($candidate, $businessId, false, $summary);
        if ((int) $summary['planned_groups'] !== 1) {
            $reason = array_key_first($summary['skipped'])
                ?? ((int) $summary['already_linked'] > 0 ? 'Sumber sudah tertaut ke jurnal.' : 'Sumber belum dapat diposting dengan mapping saat ini.');

            return $this->unavailablePreview((int) $review->id, (string) $reason, $review);
        }

        $accountById = [];
        foreach ($this->accountingAccountsCache[$businessId] ?? [] as $account) {
            $accountById[(int) $account->id] = $account;
        }
        $lines = [];
        $hashLines = [];
        foreach ($candidate['lines'] as $line) {
            $accountId = (int) $line['accounting_account_id'];
            $account = $accountById[$accountId] ?? null;
            if (! $account) {
                return $this->unavailablePreview((int) $review->id, 'Akun CoA tidak lagi aktif di business.', $review);
            }
            $amount = round((float) $line['amount'], 4);
            $type = (string) $line['type'];
            $lines[] = [
                'accounting_account_id' => $accountId,
                'account_code' => (string) ($account->gl_code ?? ''),
                'account_name' => (string) $account->name,
                'type' => $type,
                'amount' => $amount,
            ];
            $hashLines[] = [
                'accounting_account_id' => $accountId,
                'type' => $type,
                'amount' => number_format($amount, 4, '.', ''),
            ];
        }
        $classification = $this->reviewClassification($businessId, $candidate);
        $planHash = hash('sha256', json_encode([
            'business_id' => $businessId,
            'source_record_type' => (string) $candidate['source_record_type'],
            'source_record_id' => (int) $candidate['source_record_id'],
            'operation_date' => (string) $candidate['operation_date'],
            'sub_type' => (string) $candidate['sub_type'],
            'mapping_key' => (string) ($candidate['map_info']['mapping_key'] ?? ''),
            'posting_label' => $classification['label'],
            'posting_detail' => $classification['detail'],
            'ref_no' => (string) $candidate['ref_no'],
            'mapping_note' => (string) $candidate['mapping_note'],
            'lines' => $hashLines,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $context = json_decode((string) ($review->source_context ?? '[]'), true);
        $context = is_array($context) ? $context : [];

        return [
            'review_id' => (int) $review->id,
            'eligible' => true,
            'source_type' => class_basename((string) $review->source_record_type),
            'source_record_type' => (string) $review->source_record_type,
            'source_record_id' => (int) $review->source_record_id,
            'source_reference' => (string) ($review->source_reference ?? ''),
            'operation_date' => $review->operation_date,
            'source_summary' => (string) $review->source_summary,
            'source_amount' => isset($candidate['source_amount'])
                ? (float) $candidate['source_amount']
                : (isset($context['final_total']) ? (float) $context['final_total'] : (isset($context['amount']) ? (float) $context['amount'] : null)),
            'source_tax_amount' => isset($candidate['source_tax_amount'])
                ? (float) $candidate['source_tax_amount']
                : (isset($context['tax_amount']) ? (float) $context['tax_amount'] : null),
            'skip_reason' => (string) $review->skip_reason,
            'posting_label' => $classification['label'],
            'posting_detail' => $classification['detail'],
            'mapping_note' => (string) $candidate['mapping_note'],
            'plan_hash' => $planHash,
            'debit_total' => (float) $summary['debit_total'],
            'credit_total' => (float) $summary['credit_total'],
            'lines' => $lines,
            'suggested_accounts' => [],
            'reason' => null,
        ];
    }

    private function unavailablePreview(int $reviewId, string $reason, ?object $review = null): array
    {
        $context = $review ? json_decode((string) ($review->source_context ?? '[]'), true) : [];
        $context = is_array($context) ? $context : [];

        return [
            'review_id' => $reviewId,
            'eligible' => false,
            'source_type' => $review ? class_basename((string) $review->source_record_type) : '',
            'source_record_type' => $review ? (string) $review->source_record_type : '',
            'source_record_id' => $review ? (int) $review->source_record_id : null,
            'source_reference' => $review ? (string) ($review->source_reference ?? '') : '',
            'operation_date' => $review ? $review->operation_date : null,
            'source_summary' => $review ? (string) $review->source_summary : '',
            'source_amount' => isset($context['final_total']) ? (float) $context['final_total'] : (isset($context['amount']) ? (float) $context['amount'] : null),
            'source_tax_amount' => isset($context['tax_amount']) ? (float) $context['tax_amount'] : null,
            'skip_reason' => $review ? (string) $review->skip_reason : '',
            'posting_label' => '',
            'posting_detail' => '',
            'mapping_note' => '',
            'plan_hash' => null,
            'debit_total' => 0.0,
            'credit_total' => 0.0,
            'lines' => [],
            'suggested_accounts' => [],
            'reason' => $reason,
        ];
    }

    private function reviewClassification(int $businessId, array $candidate): array
    {
        $mappingKey = (string) ($candidate['map_info']['mapping_key'] ?? '');
        $subType = strtolower((string) ($candidate['sub_type'] ?? ''));
        $kind = strtolower((string) ($candidate['map_info']['mapping_key'] ?? $subType));

        if (str_starts_with($kind, 'expense_')) {
            $label = 'Operasional';
            $categoryId = (int) substr($kind, strlen('expense_'));
            $category = $this->expenseCategoriesCache[$businessId][$categoryId] ?? null;

            return ['label' => $label, 'detail' => $category ? (string) $category->name : ''];
        }
        if (in_array($kind, ['sale', 'sell_payment', 'sell'], true)) {
            return ['label' => 'Pendapatan Usaha', 'detail' => ''];
        }
        if (in_array($kind, ['purchases', 'purchase_payment', 'purchase'], true)) {
            return ['label' => 'Pembelian', 'detail' => ''];
        }
        if ($subType === 'fund_transfer' || $subType === 'transfer') {
            return ['label' => 'Transfer Antar Rekening', 'detail' => ''];
        }
        if ($subType === 'opening_balance') {
            return ['label' => 'Saldo Awal', 'detail' => ''];
        }

        return ['label' => $mappingKey !== '' ? $mappingKey : ($subType !== '' ? ucfirst($subType) : 'Jurnal'), 'detail' => ''];
    }

    private function reviewSourceReference(int $businessId, object $review): array
    {
        $context = json_decode((string) ($review->source_context ?? '[]'), true);

        return [
            'business_id' => $businessId,
            'source_record_type' => (string) $review->source_record_type,
            'source_record_id' => (int) $review->source_record_id,
            'source_context' => is_array($context) ? $context : [],
        ];
    }

    private function emptyCandidateSummary(): array
    {
        return [
            'candidate_groups' => 0,
            'planned_groups' => 0,
            'applied_groups' => 0,
            'already_linked' => 0,
            'planned_rows' => 0,
            'account_links' => [],
            'current_map_details' => [],
            'current_map_groups' => 0,
            'auto_map_details' => [],
            'auto_mapped_groups' => 0,
            'operational_evidence_groups' => 0,
            'ambiguous_vat_accounts' => 0,
            'missing_vat_accounts' => 0,
            'skipped' => [],
            'review_source_keys' => [],
            'debit_total' => 0.0,
            'credit_total' => 0.0,
            'date_start' => null,
            'date_end' => null,
        ];
    }

    private function loadBusinessContext(int $businessId): void
    {
        $this->accountingAccountsCache[$businessId] = DB::table('accounting_accounts')
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->all();

        $this->locationMapsCache[$businessId] = DB::table('business_locations')
            ->where('business_id', $businessId)
            ->get(['id', 'accounting_default_map'])
            ->mapWithKeys(function ($location) {
                $mapping = json_decode((string) $location->accounting_default_map, true);

                return [(int) $location->id => is_array($mapping) ? $mapping : []];
            })
            ->all();
        $this->expenseCategoriesCache[$businessId] = Schema::hasTable('expense_categories')
            ? DB::table('expense_categories')
                ->where('business_id', $businessId)
                ->when(Schema::hasColumn('expense_categories', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->get(['id', 'name'])
                ->keyBy('id')
                ->all()
            : [];
        $this->expensePaymentGroupsCache[$businessId] = $this->loadExpensePaymentGroups($businessId);
        $this->vatAccountMappingsCache[$businessId] = Schema::hasTable('accounting_general_ledger_vat_accounts')
            ? DB::table('accounting_general_ledger_vat_accounts')
                ->where('business_id', $businessId)
                ->pluck('accounting_account_id', 'direction')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];
    }

    private function auditMainAccountMappings(int $businessId): array
    {
        $query = DB::table('accounts')->where('business_id', $businessId)->orderBy('id');
        if (Schema::hasColumn('accounts', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $audit = [
            'account_id_matches' => 0,
            'exact_name_matches' => 0,
            'ambiguous_account_id' => 0,
            'ambiguous_exact_name' => 0,
            'conflicting_crosswalk' => 0,
            'nearest_matches' => 0,
            'unmatched' => 0,
            'matches' => [],
            'nearest_suggestions' => [],
        ];

        foreach ($query->get(['id', 'name']) as $mainAccount) {
            $accounts = $this->accountingAccountsCache[$businessId] ?? [];
            $direct = array_values(array_filter(
                $accounts,
                fn ($account) => (int) ($account->account_id ?? 0) === (int) $mainAccount->id
            ));

            if (count($direct) > 1) {
                $audit['ambiguous_account_id']++;
                continue;
            }
            if (count($direct) === 1) {
                $audit['account_id_matches']++;
                $audit['matches'][] = $this->accountMappingMatch($mainAccount, $direct[0], 'account_id');
                continue;
            }

            $nameMatches = array_values(array_filter(
                $accounts,
                fn ($account) => (string) $account->name === (string) $mainAccount->name
            ));
            if (count($nameMatches) > 1) {
                $audit['ambiguous_exact_name']++;
                continue;
            }
            if ($nameMatches === []) {
                $audit['unmatched']++;
                continue;
            }
            if (! empty($nameMatches[0]->account_id) && (int) $nameMatches[0]->account_id !== (int) $mainAccount->id) {
                $audit['conflicting_crosswalk']++;
                continue;
            }

            $audit['exact_name_matches']++;
            $audit['matches'][] = $this->accountMappingMatch($mainAccount, $nameMatches[0], 'exact_name');
        }

        foreach ($query->get(['id', 'name']) as $mainAccount) {
            $resolved = $this->mainAccountMapping($businessId, (int) $mainAccount->id);
            if (empty($resolved['automatic_nearest'])) {
                continue;
            }
            $audit['nearest_matches']++;
            $audit['nearest_suggestions'][] = $this->accountMappingMatch(
                $mainAccount,
                $resolved['account'],
                $resolved['method'] ?? 'nearest_name'
            ) + ['score' => $resolved['score']];
        }

        $audit['unmatched'] = max(0, $audit['unmatched'] - $audit['nearest_matches']);

        return $audit;
    }

    private function accountMappingMatch(object $mainAccount, object $accountingAccount, string $method): array
    {
        return [
            'account_id' => (int) $mainAccount->id,
            'account_name' => (string) $mainAccount->name,
            'accounting_account_id' => (int) $accountingAccount->id,
            'accounting_name' => (string) $accountingAccount->name,
            'method' => $method,
        ];
    }

    private function reportLegacyRecheckProgress(
        array $summary,
        string $phase,
        ?int $cursor,
        int $phaseProcessed,
        ?int $total,
        bool $completed
    ): void
    {
        if (! $this->legacyRecheckMode) {
            return;
        }

        if (! $this->legacyRecheckProgressCallback) {
            return;
        }
        if ($phaseProcessed % 100 === 0 || $completed) {
            $summary['actionable_review_sources'] = $this->legacyRecheckPriorReviewCount
                + count($summary['review_source_keys'] ?? []);
            $summary['checkpoint'] = [
                'phase' => $phase,
                'cursor' => $cursor,
                'processed' => $phaseProcessed,
                'total' => $total,
                'completed' => $completed,
            ];
            ($this->legacyRecheckProgressCallback)($phase, $phaseProcessed, $summary);
        }
    }

    private function legacyRecheckPhaseTotals(int $businessId): array
    {
        $transactions = DB::table('transactions')
            ->where('business_id', $businessId)
            ->whereIn('type', ['sell', 'purchase', 'expense']);
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $transactions->whereNull('deleted_at');
        }

        $payments = DB::table('transaction_payments as payment')
            ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
            ->where('transaction.business_id', $businessId)
            ->where('payment.business_id', $businessId)
            ->whereIn('transaction.type', ['sell', 'purchase']);
        if (Schema::hasColumn('transaction_payments', 'deleted_at')) {
            $payments->whereNull('payment.deleted_at');
        }
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $payments->whereNull('transaction.deleted_at');
        }
        if (Schema::hasColumn('transaction_payments', 'is_advance')) {
            $payments->where('payment.is_advance', 0);
        }
        if (Schema::hasColumn('transaction_payments', 'is_return')) {
            $payments->where('payment.is_return', 0);
        }

        $transfers = DB::table('account_transactions as source')
            ->join('accounts as source_account', 'source_account.id', '=', 'source.account_id')
            ->join('account_transactions as counterpart', 'counterpart.id', '=', 'source.transfer_transaction_id')
            ->join('accounts as counterpart_account', 'counterpart_account.id', '=', 'counterpart.account_id')
            ->where('source_account.business_id', $businessId)
            ->where('counterpart_account.business_id', $businessId)
            ->whereNull('source_account.deleted_at')
            ->whereNull('counterpart_account.deleted_at')
            ->whereColumn('source.id', '<', 'counterpart.id')
            ->whereColumn('counterpart.transfer_transaction_id', 'source.id')
            ->whereIn('source.sub_type', ['fund_transfer', 'deposit'])
            ->whereColumn('source.sub_type', 'counterpart.sub_type');
        if (Schema::hasColumn('account_transactions', 'deleted_at')) {
            $transfers->whereNull('source.deleted_at')->whereNull('counterpart.deleted_at');
        }

        return [
            'gl_transactions' => $transactions->count(),
            'gl_payments' => $payments->count(),
            'gl_transfers' => $transfers->count(),
        ];
    }

    private function candidateSourceId(array $candidate): ?int
    {
        $sourceId = $candidate['source_record_id']
            ?? ($candidate['review_source']['source_record_id'] ?? null);

        return is_numeric($sourceId) && (int) $sourceId > 0 ? (int) $sourceId : null;
    }

    private function operationalProductCategoryEvidenceQuery(int $businessId)
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('categories')
            || ! Schema::hasColumn('products', 'category_id')
            || ! Schema::hasColumn('products', 'business_id')
            || ! Schema::hasColumn('categories', 'business_id')) {
            return null;
        }

        $sources = [];
        foreach (['transaction_sell_lines' => 'sell', 'purchase_lines' => 'purchase'] as $lineTable => $transactionType) {
            if (! Schema::hasTable($lineTable)
                || ! Schema::hasColumn($lineTable, 'transaction_id')
                || ! Schema::hasColumn($lineTable, 'product_id')) {
                continue;
            }

            $source = DB::table($lineTable.' as source_line')
                ->join('transactions as source_transaction', 'source_transaction.id', '=', 'source_line.transaction_id')
                ->leftJoin('products as product', function ($join) use ($businessId) {
                    $join->on('product.id', '=', 'source_line.product_id')
                        ->where('product.business_id', '=', $businessId);
                    if (Schema::hasColumn('products', 'deleted_at')) {
                        $join->whereNull('product.deleted_at');
                    }
                })
                ->leftJoin('categories as category', function ($join) use ($businessId) {
                    $join->on('category.id', '=', 'product.category_id')
                        ->where('category.business_id', '=', $businessId);
                    if (Schema::hasColumn('categories', 'deleted_at')) {
                        $join->whereNull('category.deleted_at');
                    }
                })
                ->where('source_transaction.business_id', $businessId)
                ->where('source_transaction.type', $transactionType)
                ->whereIn('source_transaction.status', $transactionType === 'sell' ? ['final'] : ['received'])
                ->select('source_line.transaction_id')
                ->selectRaw('category.id as category_id, category.name as category_name');

            if (Schema::hasColumn('transactions', 'deleted_at')) {
                $source->whereNull('source_transaction.deleted_at');
            }
            if (Schema::hasColumn($lineTable, 'deleted_at')) {
                $source->whereNull('source_line.deleted_at');
            }

            $sources[] = $source;
        }

        if ($sources === []) {
            return null;
        }

        $lineEvidence = array_shift($sources);
        foreach ($sources as $source) {
            $lineEvidence->unionAll($source);
        }

        return DB::query()
            ->fromSub($lineEvidence, 'operational_product_lines')
            ->select('transaction_id')
            ->selectRaw('COUNT(*) as operational_product_line_count')
            ->selectRaw('COUNT(category_id) as operational_categorized_line_count')
            ->selectRaw('COUNT(DISTINCT category_id) as operational_category_count')
            ->selectRaw('MIN(category_name) as operational_category_name')
            ->groupBy('transaction_id');
    }

    private function uniqueOperationalEvidencePair(int $businessId, object $transaction): array
    {
        $type = (string) ($transaction->type ?? '');
        if ($type === 'expense') {
            $evidence = $this->inferExpensePair($businessId, $transaction, true);
            if (empty($evidence['reason'])) {
                $evidence['operational_evidence'] = true;
            }

            return $evidence;
        }

        if (! in_array($type, ['sell', 'purchase'], true)) {
            return ['reason' => 'bukti_operasional_tidak_tersedia'];
        }

        $lineCount = (int) ($transaction->operational_product_line_count ?? 0);
        $categorizedLineCount = (int) ($transaction->operational_categorized_line_count ?? 0);
        $categoryCount = (int) ($transaction->operational_category_count ?? 0);
        $categoryName = trim((string) ($transaction->operational_category_name ?? ''));
        if ($lineCount < 1 || $categorizedLineCount !== $lineCount || $categoryCount !== 1 || $categoryName === '') {
            return ['reason' => 'kategori_produk_operasional_tidak_unik'];
        }

        $categoryAccount = $this->exactOperationalAccountByName(
            $businessId,
            $categoryName,
            $type === 'sell' ? ['income'] : ['asset', 'expense', 'expenses']
        );
        if (! empty($categoryAccount['reason'])) {
            return $categoryAccount;
        }

        $role = $type === 'sell' ? 'receivable' : 'payable';
        $counterpart = $this->inferOperationalRoleAccount($businessId, $role, false);
        if (! empty($counterpart['reason'])) {
            return $counterpart;
        }

        $category = $categoryAccount['account'];
        $pair = $type === 'sell'
            ? ['payment_account' => $category, 'deposit_to' => $counterpart['account']]
            : ['payment_account' => $counterpart['account'], 'deposit_to' => $category];
        $roleAccountInfo = [
            'score' => $counterpart['score'] ?? 1.0,
            'method' => $counterpart['method'] ?? 'exact_name',
        ];
        $categoryInfo = [
            'score' => $categoryAccount['score'] ?? 1.0,
            'method' => $categoryAccount['method'] ?? 'exact_name',
        ];

        return [
            'pair' => $pair,
            'payment_account_score' => $type === 'sell' ? $categoryInfo['score'] : $roleAccountInfo['score'],
            'payment_account_method' => $type === 'sell' ? $categoryInfo['method'] : $roleAccountInfo['method'],
            'deposit_to_score' => $type === 'sell' ? $roleAccountInfo['score'] : $categoryInfo['score'],
            'deposit_to_method' => $type === 'sell' ? $roleAccountInfo['method'] : $categoryInfo['method'],
            'account_links' => ! empty($counterpart['crosswalk']) ? [$counterpart['crosswalk']] : [],
            'operational_evidence' => true,
        ];
    }

    private function exactOperationalAccountByName(int $businessId, string $sourceName, array $allowedTypes): array
    {
        $normalizedName = $this->normalizeExactAccountName($sourceName);
        $allowedTypes = array_map('strtolower', $allowedTypes);
        if (in_array('expense', $allowedTypes, true) || in_array('expenses', $allowedTypes, true)) {
            $allowedTypes[] = 'expense';
            $allowedTypes[] = 'expenses';
        }
        $allowedTypes = array_values(array_unique($allowedTypes));
        $matches = array_values(array_filter(
            $this->accountingAccountsCache[$businessId] ?? [],
            fn ($account) => $this->normalizeExactAccountName((string) $account->name) === $normalizedName
                && in_array(strtolower((string) ($account->account_primary_type ?? '')), $allowedTypes, true)
        ));

        if (count($matches) !== 1) {
            return ['reason' => count($matches) > 1 ? 'akun_coa_bukti_operasional_ambigu' : 'akun_coa_bukti_operasional_tidak_ditemukan'];
        }

        return [
            'account' => $matches[0],
            'score' => 1.0,
            'source_name' => $sourceName,
            'method' => 'exact_name',
        ];
    }

    private function journalSourceLinkState(int $businessId, string $sourceType, int $sourceId): string
    {
        $links = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $businessId)
            ->where('source_record_type', $sourceType)
            ->where('source_record_id', $sourceId)
            ->where('type', 'journal_entry')
            ->get(['source_link_status']);
        if ($links->isEmpty()) {
            return 'none';
        }

        return $links->count() === 1 && (string) $links->first()->source_link_status === 'matched'
            ? 'matched'
            : 'ambiguous';
    }

    private function removeProvenJournalDuplicates(
        int $businessId,
        ?int $journalImportId,
        ?int $afterMappingId = null,
        int $processedBefore = 0,
        int $removedBefore = 0,
        ?callable $progressCallback = null
    ): array {
        if ($journalImportId === null || ! Schema::hasTable('accounting_journal_import_rows')) {
            if ($progressCallback) {
                $progressCallback(0, 0, null, $removedBefore, true);
            }

            return ['removed' => $removedBefore, 'processed' => $processedBefore, 'total' => 0, 'cursor' => null];
        }

        $allSources = $this->provenJournalDuplicateSourceQuery($businessId, $journalImportId);
        $total = (int) DB::query()->fromSub($allSources, 'legacy_journal_sources')->count();
        $sourceRows = $this->provenJournalDuplicateSourceQuery($businessId, $journalImportId, $afterMappingId)->cursor();
        $processed = max(0, $processedBefore);
        $phaseProcessed = 0;
        $removed = max(0, $removedBefore);
        $cursor = $afterMappingId;

        foreach ($sourceRows as $sourceRow) {
            $cursor = (int) $sourceRow->journal_mapping_id;
            $removed += $this->removeProvenJournalDuplicateSource($businessId, $sourceRow);
            $processed++;
            $phaseProcessed++;
            if ($progressCallback) {
                $progressCallback($processed, $total, $cursor, $removed, false);
            }
        }

        if ($progressCallback) {
            $progressCallback($processed, $total, $cursor, $removed, true);
        }

        return ['removed' => $removed, 'processed' => $processed, 'total' => $total, 'cursor' => $cursor];
    }

    private function provenJournalDuplicateSourceQuery(int $businessId, int $journalImportId, ?int $afterMappingId = null)
    {
        $query = DB::table('accounting_journal_import_rows')
            ->select('source_record_type', 'source_record_id')
            ->selectRaw('MIN(journal_mapping_id) as journal_mapping_id')
            ->where('business_id', $businessId)
            ->where('journal_import_id', $journalImportId)
            ->whereIn('source_action', ['link', 'create', 'update'])
            ->whereNotNull('source_record_type')
            ->whereNotNull('source_record_id')
            ->whereNotNull('journal_mapping_id')
            ->groupBy('source_record_type', 'source_record_id')
            ->havingRaw('COUNT(DISTINCT journal_mapping_id) = 1');
        if ($afterMappingId !== null) {
            $query->havingRaw('MIN(journal_mapping_id) > ?', [$afterMappingId]);
        }

        return $query->orderBy('journal_mapping_id');
    }

    private function removeProvenJournalDuplicateSource(int $businessId, object $sourceRow): int
    {
        $source = [
            'type' => (string) $sourceRow->source_record_type,
            'id' => (int) $sourceRow->source_record_id,
        ];
        $mappingId = (int) $sourceRow->journal_mapping_id;
        $mapping = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $businessId)
            ->where('id', $mappingId)
            ->where('type', 'journal_entry')
            ->where('source_record_type', $source['type'])
            ->where('source_record_id', $source['id'])
            ->where('source_link_status', 'matched')
            ->first(['id', 'source_tax_snapshot']);
        if (! $mapping) {
            return 0;
        }

        $journalTotals = DB::table('accounting_accounts_transactions')
            ->where('acc_trans_mapping_id', $mappingId)
            ->where('sub_type', 'journal_entry')
            ->selectRaw("SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as debit_total")
            ->selectRaw("SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as credit_total")
            ->first();
        if (abs((float) ($journalTotals->debit_total ?? 0) - (float) ($journalTotals->credit_total ?? 0)) > 0.01
            || (float) ($journalTotals->debit_total ?? 0) <= 0) {
            return 0;
        }

        $amounts = [$this->linkedSourceAmount($businessId, $source['type'], $source['id'])];
        $taxSnapshot = json_decode((string) ($mapping->source_tax_snapshot ?? ''), true);
        if ($source['type'] === Transaction::class && is_array($taxSnapshot)) {
            $amounts[] = isset($taxSnapshot['transaction_before']['final_total'])
                ? round((float) $taxSnapshot['transaction_before']['final_total'], 4)
                : null;
        }
        $amounts = array_values(array_unique(array_filter($amounts, fn ($amount) => $amount !== null && $amount > 0)));
        if ($amounts === []) {
            return 0;
        }

        return $this->removeAutomaticRowsForLinkedSource($businessId, $source['type'], $source['id'], $amounts) > 0 ? 1 : 0;
    }

    private function linkedSourceAmount(int $businessId, string $sourceType, int $sourceId): ?float
    {
        if ($sourceType === Transaction::class) {
            $transaction = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('id', $sourceId)
                ->first(['final_total']);

            return $transaction ? round((float) $transaction->final_total, 4) : null;
        }

        if ($sourceType === TransactionPayment::class) {
            $payment = DB::table('transaction_payments as payment')
                ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
                ->where('transaction.business_id', $businessId)
                ->where('payment.business_id', $businessId)
                ->where('payment.id', $sourceId)
                ->first(['payment.amount']);

            return $payment ? round(abs((float) $payment->amount), 4) : null;
        }

        return null;
    }

    private function removeAutomaticRowsForLinkedSource(int $businessId, string $sourceType, int $sourceId, array $expectedAmounts): int
    {
        $rows = DB::table('accounting_accounts_transactions')
            ->whereNull('acc_trans_mapping_id')
            ->whereIn('map_type', ['payment_account', 'deposit_to']);
        if ($sourceType === Transaction::class) {
            $transaction = DB::table('transactions')->where('business_id', $businessId)->where('id', $sourceId)->first(['type']);
            if (! $transaction) {
                return 0;
            }
            $rows->where('transaction_id', $sourceId)->whereNull('transaction_payment_id')->where('sub_type', $transaction->type);
        } elseif ($sourceType === TransactionPayment::class) {
            $payment = DB::table('transaction_payments as payment')
                ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
                ->where('transaction.business_id', $businessId)
                ->where('payment.business_id', $businessId)
                ->where('payment.id', $sourceId)
                ->first(['transaction.type']);
            if (! $payment) {
                return 0;
            }
            $rows->where('transaction_payment_id', $sourceId)->where('sub_type', $payment->type.'_payment');
        } else {
            return 0;
        }

        $automaticRows = $rows->get(['id', 'amount', 'type']);
        if ($automaticRows->isNotEmpty()) {
            $debit = round((float) $automaticRows->where('type', 'debit')->sum('amount'), 4);
            $credit = round((float) $automaticRows->where('type', 'credit')->sum('amount'), 4);
            if (! $this->totalsMatchAnySourceAmount($debit, $credit, $expectedAmounts)) {
                $automaticRows = collect();
            }
        }

        $removed = 0;
        if ($automaticRows->isNotEmpty()) {
            DB::table('accounting_accounts_transactions')->whereIn('id', $automaticRows->pluck('id'))->delete();
            $removed++;
        }

        $backfillMappings = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $businessId)
            ->where('source_record_type', $sourceType)
            ->where('source_record_id', $sourceId)
            ->where('type', 'general_ledger_backfill')
            ->pluck('id');
        foreach ($backfillMappings as $mappingId) {
            $lines = DB::table('accounting_accounts_transactions')
                ->where('acc_trans_mapping_id', $mappingId)
                ->get(['id', 'amount', 'type', 'transaction_id', 'transaction_payment_id']);
            $isExactSource = $sourceType === Transaction::class
                ? $lines->every(fn ($line) => (int) $line->transaction_id === $sourceId && $line->transaction_payment_id === null)
                : $lines->every(fn ($line) => (int) $line->transaction_payment_id === $sourceId);
            if ($lines->isEmpty() || ! $isExactSource) {
                continue;
            }
            $debit = round((float) $lines->where('type', 'debit')->sum('amount'), 4);
            $credit = round((float) $lines->where('type', 'credit')->sum('amount'), 4);
            if (! $this->totalsMatchAnySourceAmount($debit, $credit, $expectedAmounts)) {
                continue;
            }
            DB::table('accounting_accounts_transactions')->whereIn('id', $lines->pluck('id'))->delete();
            DB::table('accounting_acc_trans_mappings')->where('business_id', $businessId)->where('id', $mappingId)->delete();
            $removed++;
        }

        return $removed;
    }

    private function totalsMatchAnySourceAmount(float $debit, float $credit, array $amounts): bool
    {
        foreach ($amounts as $amount) {
            if (abs($debit - (float) $amount) <= 0.01 && abs($credit - (float) $amount) <= 0.01) {
                return true;
            }
        }

        return false;
    }

    private function journalPatternPair(int $businessId, object $transaction): array
    {
        $type = (string) ($transaction->type ?? '');
        $sourceType = ['sell' => 'Sales Invoice', 'purchase' => 'Purchase Invoice', 'expense' => 'Expense'][$type] ?? '';
        if ($sourceType === '' || $this->legacyRecheckJournalImportId === null) {
            return ['reason' => 'pola_journal_tidak_ditemukan'];
        }
        $cacheKey = implode(':', [
            $businessId,
            $type,
            (int) ($transaction->location_id ?? 0),
            (int) ($transaction->expense_category_id ?? 0),
        ]);
        if (array_key_exists($cacheKey, $this->legacyJournalPairCache)) {
            return $this->legacyJournalPairCache[$cacheKey];
        }

        $samples = DB::table('accounting_journal_import_rows as import_row')
            ->join('accounting_acc_trans_mappings as mapping', 'mapping.id', '=', 'import_row.journal_mapping_id')
            ->join('transactions as sample', 'sample.id', '=', 'import_row.source_record_id')
            ->where('import_row.business_id', $businessId)
            ->where('import_row.journal_import_id', $this->legacyRecheckJournalImportId)
            ->where('import_row.source_type', $sourceType)
            ->whereIn('import_row.source_action', ['link', 'create', 'update'])
            ->where('import_row.source_record_type', Transaction::class)
            ->where('mapping.business_id', $businessId)
            ->where('mapping.type', 'journal_entry')
            ->whereColumn('mapping.source_record_type', 'import_row.source_record_type')
            ->whereColumn('mapping.source_record_id', 'import_row.source_record_id')
            ->where('mapping.source_link_status', 'matched')
            ->where('sample.business_id', $businessId)
            ->where('sample.type', $type)
            ->where('sample.location_id', $transaction->location_id)
            ->where('sample.expense_category_id', $transaction->expense_category_id)
            ->when(Schema::hasColumn('transactions', 'deleted_at'), fn ($query) => $query->whereNull('sample.deleted_at'))
            ->select('mapping.id as mapping_id', 'import_row.source_record_id')
            ->distinct()
            ->orderBy('mapping.id')
            ->get();

        $pairCounts = [];
        foreach ($samples as $sample) {
            $pair = $this->uniqueNonTaxJournalPair($businessId, (int) $sample->mapping_id);
            if ($pair === null) {
                continue;
            }
            $key = $pair['payment_account']->id.':'.$pair['deposit_to']->id;
            $pairCounts[$key]['pair'] = $pair;
            $pairCounts[$key]['count'] = ($pairCounts[$key]['count'] ?? 0) + 1;
            foreach ($pair['vat_accounts'] ?? [] as $direction => $account) {
                $accountId = (int) $account->id;
                $pairCounts[$key]['vat_counts'][$direction][$accountId] = ($pairCounts[$key]['vat_counts'][$direction][$accountId] ?? 0) + 1;
            }
        }

        if ($pairCounts === []) {
            return $this->legacyJournalPairCache[$cacheKey] = ['reason' => 'pola_journal_tidak_ditemukan'];
        }
        usort($pairCounts, fn ($left, $right) => $right['count'] <=> $left['count']);
        if (isset($pairCounts[1]) && $pairCounts[0]['count'] === $pairCounts[1]['count']) {
            return $this->legacyJournalPairCache[$cacheKey] = ['reason' => 'pola_journal_ambigu'];
        }

        $pair = $pairCounts[0]['pair'];
        $pair['vat_accounts'] = [];
        foreach ($pairCounts[0]['vat_counts'] ?? [] as $direction => $counts) {
            arsort($counts);
            $accountIds = array_keys($counts);
            if (isset($counts[$accountIds[1] ?? -1]) && $counts[$accountIds[0]] === $counts[$accountIds[1]]) {
                continue;
            }
            $account = collect($this->accountingAccountsCache[$businessId] ?? [])->firstWhere('id', (int) $accountIds[0]);
            if ($account) {
                $pair['vat_accounts'][$direction] = $account;
            }
        }

        return $this->legacyJournalPairCache[$cacheKey] = ['pair' => $pair];
    }

    private function journalPaymentCounterpart(int $businessId, object $payment, int $expectedPaymentAccountId): array
    {
        $type = (string) ($payment->transaction_type ?? '');
        $sourceType = $type === 'sell' ? 'Receive Payment' : ($type === 'purchase' ? 'Purchase Payment' : '');
        if ($sourceType === '' || $this->legacyRecheckJournalImportId === null) {
            return ['reason' => 'pola_journal_pembayaran_tidak_ditemukan'];
        }
        $cacheKey = implode(':', ['payment', $businessId, $type, (int) ($payment->location_id ?? 0), $expectedPaymentAccountId]);
        if (array_key_exists($cacheKey, $this->legacyJournalPairCache)) {
            return $this->legacyJournalPairCache[$cacheKey];
        }

        $samples = DB::table('accounting_journal_import_rows as import_row')
            ->join('accounting_acc_trans_mappings as mapping', 'mapping.id', '=', 'import_row.journal_mapping_id')
            ->join('transaction_payments as sample_payment', 'sample_payment.id', '=', 'import_row.source_record_id')
            ->join('transactions as sample', 'sample.id', '=', 'sample_payment.transaction_id')
            ->where('import_row.business_id', $businessId)
            ->where('import_row.journal_import_id', $this->legacyRecheckJournalImportId)
            ->where('import_row.source_type', $sourceType)
            ->whereIn('import_row.source_action', ['link', 'create', 'update'])
            ->where('import_row.source_record_type', TransactionPayment::class)
            ->where('mapping.business_id', $businessId)
            ->where('mapping.type', 'journal_entry')
            ->whereColumn('mapping.source_record_type', 'import_row.source_record_type')
            ->whereColumn('mapping.source_record_id', 'import_row.source_record_id')
            ->where('mapping.source_link_status', 'matched')
            ->where('sample_payment.business_id', $businessId)
            ->where('sample.business_id', $businessId)
            ->where('sample.type', $type)
            ->where('sample.location_id', $payment->location_id)
            ->select('mapping.id as mapping_id')
            ->distinct()
            ->orderBy('mapping.id')
            ->get();

        $counterparts = [];
        foreach ($samples as $sample) {
            $pair = $this->uniqueNonTaxJournalPair($businessId, (int) $sample->mapping_id);
            if ($pair === null) {
                continue;
            }
            $cashSide = $type === 'sell' ? $pair['deposit_to'] : $pair['payment_account'];
            if ((int) $cashSide->id !== $expectedPaymentAccountId) {
                continue;
            }
            $counterpart = $type === 'sell' ? $pair['payment_account'] : $pair['deposit_to'];
            $counterparts[(int) $counterpart->id] = ($counterparts[(int) $counterpart->id] ?? 0) + 1;
        }
        if ($counterparts === []) {
            return $this->legacyJournalPairCache[$cacheKey] = ['reason' => 'pola_journal_pembayaran_tidak_ditemukan'];
        }
        arsort($counterparts);
        $ids = array_keys($counterparts);
        if (isset($counterparts[$ids[1] ?? -1]) && $counterparts[$ids[0]] === $counterparts[$ids[1]]) {
            return $this->legacyJournalPairCache[$cacheKey] = ['reason' => 'pola_journal_pembayaran_ambigu'];
        }
        $account = collect($this->accountingAccountsCache[$businessId] ?? [])->firstWhere('id', $ids[0]);

        return $this->legacyJournalPairCache[$cacheKey] = ($account ? ['account' => $account] : ['reason' => 'akun_coa_tidak_aktif']);
    }

    private function uniqueNonTaxJournalPair(int $businessId, int $mappingId): ?array
    {
        $lines = DB::table('accounting_accounts_transactions as line')
            ->join('accounting_accounts as account', function ($join) use ($businessId) {
                $join->on('account.id', '=', 'line.accounting_account_id')
                    ->where('account.business_id', '=', $businessId);
            })
            ->where('line.acc_trans_mapping_id', $mappingId)
            ->where('line.sub_type', 'journal_entry')
            ->get([
                'line.accounting_account_id',
                'line.amount',
                'line.type',
                'account.gl_code as account_no',
                'account.name as account_name',
            ]);
        if ($lines->isEmpty()) {
            return null;
        }
        $debitTotal = round((float) $lines->where('type', 'debit')->sum('amount'), 4);
        $creditTotal = round((float) $lines->where('type', 'credit')->sum('amount'), 4);
        if ($debitTotal <= 0 || abs($debitTotal - $creditTotal) > 0.01) {
            return null;
        }

        $idsByKey = [];
        $entry = ['lines' => []];
        foreach ($lines as $line) {
            $normalized = [
                'account_no' => (string) $line->account_no,
                'account_name' => (string) $line->account_name,
                'type' => (string) $line->type,
                'amount' => (float) $line->amount,
            ];
            $key = $this->accountComponentService->accountKey($normalized);
            $idsByKey[$key][(int) $line->accounting_account_id] = true;
            $entry['lines'][] = $normalized;
        }
        $components = $this->accountComponentService->groupJournalLines($entry, $businessId);
        $debits = [];
        $credits = [];
        $vatAccounts = ['input' => [], 'output' => []];
        foreach ($components as $component) {
            if (! empty($component['is_tax'])) {
                if (! empty($component['tax_direction_conflict'])) {
                    return null;
                }
                $direction = (string) ($component['tax_direction'] ?? '');
                if (in_array($direction, ['input', 'output'], true)) {
                    $componentAccountIds = array_keys($idsByKey[$component['account_key']] ?? []);
                    if (count($componentAccountIds) !== 1) {
                        return null;
                    }
                    $vatAccounts[$direction][(int) $componentAccountIds[0]] = true;
                }
                continue;
            }
            $componentAccountIds = array_keys($idsByKey[$component['account_key']] ?? []);
            if (count($componentAccountIds) !== 1) {
                return null;
            }
            $accountId = (int) $componentAccountIds[0];
            if ((float) $component['debit'] > 0 && (float) $component['credit'] === 0.0) {
                $debits[$accountId] = true;
            } elseif ((float) $component['credit'] > 0 && (float) $component['debit'] === 0.0) {
                $credits[$accountId] = true;
            } elseif ((float) $component['debit'] > 0 || (float) $component['credit'] > 0) {
                return null;
            }
        }
        if (count($debits) !== 1 || count($credits) !== 1) {
            return null;
        }
        $debitId = (int) array_key_first($debits);
        $creditId = (int) array_key_first($credits);
        if ($debitId === $creditId) {
            return null;
        }
        $accounts = collect($this->accountingAccountsCache[$businessId] ?? []);
        $debit = $accounts->firstWhere('id', $debitId);
        $credit = $accounts->firstWhere('id', $creditId);
        if (! $debit || ! $credit) {
            return null;
        }

        $vatAccountsByDirection = [];
        foreach ($vatAccounts as $direction => $accountIds) {
            if (count($accountIds) !== 1) {
                continue;
            }
            $account = $accounts->firstWhere('id', (int) array_key_first($accountIds));
            if ($account) {
                $vatAccountsByDirection[$direction] = $account;
            }
        }

        return [
            'payment_account' => $credit,
            'deposit_to' => $debit,
            'vat_accounts' => $vatAccountsByDirection,
        ];
    }

    private function transactionCandidates(int $businessId, ?int $sourceId = null, ?int $afterId = null): \Generator
    {
        $query = DB::table('transactions')
            ->where('business_id', $businessId)
            ->whereIn('type', ['sell', 'purchase', 'expense'])
            ->when($sourceId !== null, fn ($query) => $query->where('id', $sourceId))
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id');

        if ($this->legacyRecheckMode) {
            $productCategoryEvidence = $this->operationalProductCategoryEvidenceQuery($businessId);
            if ($productCategoryEvidence !== null) {
                $query->leftJoinSub($productCategoryEvidence, 'operational_product_evidence', function ($join) {
                    $join->on('operational_product_evidence.transaction_id', '=', 'transactions.id');
                })->addSelect(
                    'transactions.*',
                    'operational_product_evidence.operational_product_line_count',
                    'operational_product_evidence.operational_categorized_line_count',
                    'operational_product_evidence.operational_category_count',
                    'operational_product_evidence.operational_category_name'
                );
            }
        }

        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        foreach ($query->cursor() as $transaction) {
            if (! $this->isPostedTransaction($transaction)) {
                yield $this->transactionSkipCandidate($transaction, 'transaksi_bukan_posting', $businessId);
                continue;
            }

            if ($this->legacyRecheckMode) {
                $journalLinkState = $this->journalSourceLinkState($businessId, Transaction::class, (int) $transaction->id);
                if ($journalLinkState === 'matched') {
                    yield [
                        'journal_linked_source' => true,
                        'review_source' => $this->transactionSkipCandidate($transaction, 'source_journal_authoritative', $businessId)['review_source'],
                    ];
                    continue;
                }
                if ($journalLinkState === 'ambiguous') {
                    yield $this->transactionSkipCandidate($transaction, 'journal_source_link_ambiguous', $businessId);
                    continue;
                }
            }

            $type = (string) $transaction->type;
            $locationMap = $this->locationMap($businessId, (int) ($transaction->location_id ?? 0));
            $mappingKey = $type === 'sell' ? 'sale' : ($type === 'purchase' ? 'purchases' : 'expense');
            if ($type === 'expense' && ! empty($transaction->expense_category_id)) {
                $mappingKey = 'expense_'.$transaction->expense_category_id;
            }
            $accountMap = $locationMap[$mappingKey] ?? [];
            $pair = null;
            $autoMap = null;
            $journalPattern = false;

            if ($this->legacyRecheckMode) {
                $operationalEvidence = $this->uniqueOperationalEvidencePair($businessId, $transaction);
                if (empty($operationalEvidence['reason'])) {
                    $pair = $operationalEvidence['pair'];
                    $autoMap = $operationalEvidence;
                }
            }

            if ($pair === null) {
                $pair = $this->resolveAccountingPair($businessId, $accountMap);
            }

            if (! empty($pair['reason']) && $this->legacyRecheckMode) {
                $pattern = $this->journalPatternPair($businessId, $transaction);
                if (empty($pattern['reason'])) {
                    $pair = $pattern['pair'];
                    $journalPattern = true;
                }
            } elseif (! empty($pair['reason']) && in_array($type, ['sell', 'purchase'], true)) {
                $autoMap = $this->inferTransactionPair($businessId, $type);
                if (empty($autoMap['reason'])) {
                    $pair = $autoMap['pair'];
                }
            } elseif (! empty($pair['reason']) && $type === 'expense') {
                $autoMap = $this->inferExpensePair($businessId, $transaction);
                if (empty($autoMap['reason'])) {
                    $pair = $autoMap['pair'];
                }
            }
            if (! empty($pair['reason'])) {
                $reason = $this->legacyRecheckMode && ! empty($accountMap)
                    ? 'mapping_lokasi_tidak_valid_dan_pola_journal_ambigu'
                    : (string) $pair['reason'];
                yield $this->transactionSkipCandidate($transaction, $reason, $businessId);
                continue;
            }

            $total = round((float) ($transaction->final_total ?? 0), 4);
            $tax = round((float) ($transaction->tax_amount ?? 0), 4);
            if (! is_finite($total) || ! is_finite($tax) || $total <= 0 || $tax < 0 || $tax > $total) {
                yield $this->transactionSkipCandidate($transaction, 'nominal_transaksi_tidak_valid', $businessId);
                continue;
            }

            $base = round($total - $tax, 4);
            $lines = [];
            if ($type === 'sell') {
                $lines[] = $this->line((int) $pair['deposit_to']->id, 'debit', $total);
                if ($base > 0) {
                    $lines[] = $this->line((int) $pair['payment_account']->id, 'credit', $base);
                }
                if ($tax > 0) {
                    $vat = $this->resolveLegacyVatAccount(
                        $businessId,
                        'output',
                        $this->legacyVatPatternAccount($businessId, $transaction, $pair, 'output')
                    );
                    if (! empty($vat['reason'])) {
                        yield $this->transactionSkipCandidate($transaction, (string) $vat['reason'], $businessId);
                        continue;
                    }
                    $lines[] = $this->line((int) $vat['account']->id, 'credit', $tax);
                }
            } elseif ($type === 'purchase') {
                if ($base > 0) {
                    $lines[] = $this->line((int) $pair['deposit_to']->id, 'debit', $base);
                }
                if ($tax > 0) {
                    $vat = $this->resolveLegacyVatAccount(
                        $businessId,
                        'input',
                        $this->legacyVatPatternAccount($businessId, $transaction, $pair, 'input')
                    );
                    if (! empty($vat['reason'])) {
                        yield $this->transactionSkipCandidate($transaction, (string) $vat['reason'], $businessId);
                        continue;
                    }
                    $lines[] = $this->line((int) $vat['account']->id, 'debit', $tax);
                }
                $lines[] = $this->line((int) $pair['payment_account']->id, 'credit', $total);
            } else {
                if ($base > 0) {
                    $lines[] = $this->line((int) $pair['deposit_to']->id, 'debit', $base);
                }
                if ($tax > 0) {
                    $vat = $this->resolveLegacyVatAccount(
                        $businessId,
                        'input',
                        $this->legacyVatPatternAccount($businessId, $transaction, $pair, 'input')
                    );
                    if (! empty($vat['reason'])) {
                        yield $this->transactionSkipCandidate($transaction, (string) $vat['reason'], $businessId);
                        continue;
                    }
                    $lines[] = $this->line((int) $vat['account']->id, 'debit', $tax);
                }
                if ($autoMap !== null && ! empty($autoMap['payment_lines'])) {
                    foreach ($autoMap['payment_lines'] as $paymentLine) {
                        $lines[] = $paymentLine;
                    }
                } else {
                    $lines[] = $this->line((int) $pair['payment_account']->id, 'credit', $total);
                }
            }

            $date = (string) ($transaction->transaction_date ?? '');
            $operationalEvidenceUsed = ! empty($autoMap['operational_evidence']);
            $mappingSource = $operationalEvidenceUsed
                ? 'operational_evidence'
                : ($journalPattern ? 'journal_pattern' : ($autoMap === null ? 'location' : 'automatic_nearest'));
            $mappingNote = $operationalEvidenceUsed
                ? 'Backfill dari transaksi '.$type.' #'.$transaction->id.' memakai bukti kategori dan akun operasional yang unik.'
                : ($autoMap === null
                    ? ($journalPattern
                        ? 'Recheck legacy dari transaksi '.$type.' #'.$transaction->id.' memakai pola Journal unik untuk lokasi dan kategori ini.'
                        : 'Backfill dari transaksi '.$type.' #'.$transaction->id.' dengan mapping lokasi saat ini.')
                    : 'Backfill dari transaksi '.$type.' #'.$transaction->id.' dengan pemetaan CoA terdekat otomatis.');
            $candidate = $this->group(
                $businessId,
                Transaction::class,
                (int) $transaction->id,
                $type,
                $date,
                (int) ($transaction->created_by ?? 1),
                (string) ($transaction->invoice_no ?: $transaction->ref_no ?: strtoupper($type).'-'.$transaction->id),
                $mappingNote,
                $lines,
                $autoMap === null && ! $journalPattern,
                (int) $transaction->id,
                null,
                $autoMap['account_links'] ?? [],
                $tax > 0,
                null,
                $this->mapInfo(
                    (int) ($transaction->location_id ?? 0),
                    $mappingKey,
                    $pair,
                    $mappingSource,
                    $autoMap['payment_account_score'] ?? null,
                    $autoMap['deposit_to_score'] ?? null,
                    $autoMap['payment_account_method'] ?? null,
                    $autoMap['deposit_to_method'] ?? null
                )
            );
            $candidate['uses_auto_map'] = $autoMap !== null && ! $operationalEvidenceUsed;
            $candidate['uses_operational_evidence'] = $operationalEvidenceUsed;
            $candidate['uses_journal_pattern'] = $journalPattern;
            $candidate['source_amount'] = $total;
            $candidate['source_tax_amount'] = $tax;
            yield $candidate;
        }
    }

    private function paymentCandidates(int $businessId, ?int $sourceId = null, ?int $afterId = null): \Generator
    {
        $query = DB::table('transaction_payments as payment')
            ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
            ->where('transaction.business_id', $businessId)
            ->where('payment.business_id', $businessId)
            ->whereIn('transaction.type', ['sell', 'purchase'])
            ->when($sourceId !== null, fn ($query) => $query->where('payment.id', $sourceId))
            ->when($afterId !== null, fn ($query) => $query->where('payment.id', '>', $afterId))
            ->orderBy('payment.id')
            ->select(
                'payment.id as payment_id',
                'payment.transaction_id',
                'payment.account_id as payment_account_id',
                'payment.amount as payment_amount',
                'payment.paid_on',
                'payment.created_by as payment_created_by',
                'payment.is_return',
                'payment.is_advance',
                'transaction.business_id',
                'transaction.location_id',
                'transaction.type as transaction_type',
                'transaction.status as transaction_status',
                'transaction.invoice_no',
                'transaction.ref_no'
            );

        if (Schema::hasColumn('transaction_payments', 'deleted_at')) {
            $query->whereNull('payment.deleted_at');
        }
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $query->whereNull('transaction.deleted_at');
        }
        if (Schema::hasColumn('transaction_payments', 'is_advance')) {
            $query->where('payment.is_advance', 0);
        }
        if (Schema::hasColumn('transaction_payments', 'is_return')) {
            $query->where('payment.is_return', 0);
        }

        foreach ($query->cursor() as $payment) {
            $type = (string) $payment->transaction_type;
            if (! $this->isPostedTransaction((object) [
                'type' => $type,
                'status' => $payment->transaction_status,
            ])) {
                yield $this->paymentSkipCandidate($payment, 'transaksi_bukan_posting', $businessId);
                continue;
            }
            if ($this->legacyRecheckMode) {
                $journalLinkState = $this->journalSourceLinkState($businessId, TransactionPayment::class, (int) $payment->payment_id);
                if ($journalLinkState === 'matched') {
                    yield [
                        'journal_linked_source' => true,
                        'review_source' => $this->paymentSkipCandidate($payment, 'source_journal_authoritative', $businessId)['review_source'],
                    ];
                    continue;
                }
                if ($journalLinkState === 'ambiguous') {
                    yield $this->paymentSkipCandidate($payment, 'journal_source_link_ambiguous', $businessId);
                    continue;
                }
            }
            $mainAccountId = (int) ($payment->payment_account_id ?? 0);
            if ($mainAccountId < 1) {
                yield $this->paymentSkipCandidate($payment, 'akun_pembayaran_operasional_kosong', $businessId);
                continue;
            }

            $sourceAccount = $this->mainAccountMapping($businessId, $mainAccountId, ! $this->legacyRecheckMode);
            if (! empty($sourceAccount['reason'])) {
                yield $this->paymentSkipCandidate($payment, (string) $sourceAccount['reason'], $businessId);
                continue;
            }

            $mapType = $type === 'sell' ? 'sell_payment' : 'purchase_payment';
            $locationMap = $this->locationMap($businessId, (int) ($payment->location_id ?? 0));
            $map = $locationMap[$mapType] ?? [];
            $counterpartId = (int) ($type === 'sell'
                ? ($map['payment_account'] ?? 0)
                : ($map['deposit_to'] ?? 0));
            $counterpart = $counterpartId > 0
                ? collect($this->accountingAccountsCache[$businessId] ?? [])->firstWhere('id', $counterpartId)
                : null;
            $autoMap = null;
            $journalPattern = false;
            if (! $counterpart) {
                if ($this->legacyRecheckMode) {
                    $pattern = $this->journalPaymentCounterpart($businessId, $payment);
                    if (empty($pattern['reason'])) {
                        $counterpart = $pattern['account'];
                        $journalPattern = true;
                    }
                } else {
                    $autoMap = $this->inferOperationalRoleAccount($businessId, $type === 'sell' ? 'receivable' : 'payable');
                    $counterpart = $autoMap['account'] ?? null;
                }
            }
            if (! $counterpart) {
                yield $this->paymentSkipCandidate($payment, (string) ($autoMap['reason'] ?? ($counterpartId < 1
                    ? 'mapping_akun_lawan_tidak_lengkap'
                    : 'akun_lawan_tidak_ada_di_business')), $businessId);
                continue;
            }
            if ((int) $counterpart->id === (int) $sourceAccount['account']->id) {
                yield $this->paymentSkipCandidate($payment, 'akun_pembayaran_sama_dengan_akun_lawan', $businessId);
                continue;
            }

            $cashAccount = $sourceAccount['account'];
            if ($type === 'sell') {
                $pair = ['payment_account' => $counterpart, 'deposit_to' => $cashAccount];
                $lines = [
                    $this->line((int) $cashAccount->id, 'debit', (float) $payment->payment_amount),
                    $this->line((int) $counterpart->id, 'credit', (float) $payment->payment_amount),
                ];
            } else {
                $pair = ['payment_account' => $cashAccount, 'deposit_to' => $counterpart];
                $lines = [
                    $this->line((int) $counterpart->id, 'debit', (float) $payment->payment_amount),
                    $this->line((int) $cashAccount->id, 'credit', (float) $payment->payment_amount),
                ];
            }
            $links = $sourceAccount['crosswalk'] ? [$sourceAccount['crosswalk']] : [];
            $usesAutoMap = $autoMap !== null || ! empty($sourceAccount['automatic_nearest']);
            $paymentScore = $autoMap !== null && $type === 'sell'
                ? $autoMap['score']
                : ($type === 'purchase' && ! empty($sourceAccount['automatic_nearest']) ? $sourceAccount['score'] : null);
            $depositScore = $autoMap !== null && $type === 'purchase'
                ? $autoMap['score']
                : ($type === 'sell' && ! empty($sourceAccount['automatic_nearest']) ? $sourceAccount['score'] : null);
            $mappingNote = 'Akun kas/bank dari transaction_payments.account_id; akun lawan dari mapping lokasi saat ini.';
            if ($autoMap !== null) {
                $mappingNote = 'Akun lawan '.$autoMap['source_name'].' dipetakan otomatis ke CoA terdekat.';
            } elseif ($journalPattern) {
                $mappingNote = 'Recheck legacy memakai akun lawan dari pola Journal pembayaran yang unik untuk lokasi dan jenis transaksi ini.';
            }
            if (! empty($sourceAccount['automatic_nearest'])) {
                $mappingNote .= ' Akun operasional '.$sourceAccount['source_name'].' dipetakan ke CoA terdekat dengan skor '.number_format((float) $sourceAccount['score'], 4, '.', '').'.';
            }
            $candidate = $this->group(
                $businessId,
                TransactionPayment::class,
                (int) $payment->payment_id,
                $mapType,
                (string) $payment->paid_on,
                (int) ($payment->payment_created_by ?: 1),
                (string) ($payment->ref_no ?: $payment->invoice_no ?: 'PAYMENT-'.$payment->payment_id),
                $mappingNote,
                $lines,
                ! $usesAutoMap,
                null,
                (int) $payment->payment_id,
                $links,
                false,
                null,
                $this->mapInfo(
                    (int) ($payment->location_id ?? 0),
                    $mapType,
                    $pair,
                    $journalPattern ? 'journal_pattern' : ($usesAutoMap ? 'automatic_nearest' : 'location'),
                    $paymentScore,
                    $depositScore,
                    $type === 'sell'
                        ? ($autoMap['method'] ?? null)
                        : ($sourceAccount['method'] ?? null),
                    $type === 'purchase'
                        ? ($autoMap['method'] ?? null)
                        : ($sourceAccount['method'] ?? null)
                )
            );
            $candidate['uses_auto_map'] = $usesAutoMap;
            $candidate['uses_journal_pattern'] = $journalPattern;
            $candidate['source_amount'] = (float) $payment->payment_amount;
            $candidate['source_tax_amount'] = 0.0;
            yield $candidate;
        }
    }

    private function bankTransferCandidates(int $businessId, ?int $sourceId = null, ?int $afterId = null): \Generator
    {
        $query = DB::table('account_transactions as source')
            ->join('accounts as source_account', 'source_account.id', '=', 'source.account_id')
            ->join('account_transactions as counterpart', 'counterpart.id', '=', 'source.transfer_transaction_id')
            ->join('accounts as counterpart_account', 'counterpart_account.id', '=', 'counterpart.account_id')
            ->where('source_account.business_id', $businessId)
            ->where('counterpart_account.business_id', $businessId)
            ->whereNull('source_account.deleted_at')
            ->whereNull('counterpart_account.deleted_at')
            ->whereColumn('source.id', '<', 'counterpart.id')
            ->whereColumn('counterpart.transfer_transaction_id', 'source.id')
            ->whereIn('source.sub_type', ['fund_transfer', 'deposit'])
            ->when($sourceId !== null, fn ($query) => $query->where('source.id', $sourceId))
            ->when($afterId !== null, fn ($query) => $query->where('source.id', '>', $afterId))
            ->whereColumn('source.sub_type', 'counterpart.sub_type')
            ->whereNull('source.deleted_at')
            ->whereNull('counterpart.deleted_at')
            ->orderBy('source.id')
            ->select(
                'source_account.business_id as business_id',
                'source.id as source_id',
                'source.account_id as source_account_id',
                'source.type as source_type',
                'source.sub_type as source_sub_type',
                'source.amount as source_amount',
                'source.operation_date as source_operation_date',
                'source.created_by as source_created_by',
                'source.note as source_note',
                'counterpart.id as counterpart_id',
                'counterpart.account_id as counterpart_account_id',
                'counterpart.type as counterpart_type',
                'counterpart.amount as counterpart_amount',
                'counterpart.operation_date as counterpart_operation_date'
            );

        foreach ($query->cursor() as $transfer) {
            if ((string) $transfer->source_type === (string) $transfer->counterpart_type
                || round((float) $transfer->source_amount, 4) !== round((float) $transfer->counterpart_amount, 4)
                || round((float) $transfer->source_amount, 4) <= 0) {
                yield $this->transferSkipCandidate($transfer, 'transfer_tidak_seimbang', $businessId);
                continue;
            }

            $sourceAccount = $this->mainAccountMapping($businessId, (int) $transfer->source_account_id, ! $this->legacyRecheckMode);
            $counterpartAccount = $this->mainAccountMapping($businessId, (int) $transfer->counterpart_account_id, ! $this->legacyRecheckMode);
            if (! empty($sourceAccount['reason']) || ! empty($counterpartAccount['reason'])) {
                yield $this->transferSkipCandidate(
                    $transfer,
                    (string) ($sourceAccount['reason'] ?? $counterpartAccount['reason']),
                    $businessId
                );
                continue;
            }

            $sourceType = $transfer->source_type === 'credit' ? 'debit' : 'credit';
            $counterpartType = $transfer->counterpart_type === 'credit' ? 'debit' : 'credit';
            $links = array_values(array_filter([
                $sourceAccount['crosswalk'],
                $counterpartAccount['crosswalk'],
            ]));
            $candidate = $this->group(
                $businessId,
                AccountTransaction::class,
                (int) $transfer->source_id,
                (string) $transfer->source_sub_type,
                (string) $transfer->source_operation_date,
                (int) ($transfer->source_created_by ?: 1),
                'BANK-'.$transfer->source_id.'-'.$transfer->counterpart_id,
                'Backfill transfer rekening #'.$transfer->source_id.' dan #'.$transfer->counterpart_id.'.',
                [
                    $this->line((int) $sourceAccount['account']->id, $sourceType, (float) $transfer->source_amount),
                    $this->line((int) $counterpartAccount['account']->id, $counterpartType, (float) $transfer->counterpart_amount),
                ],
                false,
                null,
                null,
                $links,
                false,
                (int) $transfer->source_id
            );
            $candidate['source_amount'] = (float) $transfer->source_amount;
            $candidate['source_tax_amount'] = 0.0;
            yield $candidate;
        }
    }

    private function unpairedMainAccountMovements(int $businessId): array
    {
        $query = $this->unpairedMainAccountMovementQuery($businessId);

        $openingBalances = (clone $query)->where('source.sub_type', 'opening_balance')->count();
        $unpaired = (clone $query)->where(function ($query) {
            $query->whereNull('source.sub_type')->orWhereNotIn('source.sub_type', ['opening_balance']);
        })->count();

        return [
            'saldo_awal_rekening_tidak_disalin' => $openingBalances,
            'pergerakan_rekening_tanpa_akun_lawan' => $unpaired,
            'pasangan_transfer_tidak_valid' => $this->invalidTransferCount($businessId),
        ];
    }

    private function unpairedMainAccountMovementQuery(int $businessId)
    {
        $query = DB::table('account_transactions as source')
            ->join('accounts', 'accounts.id', '=', 'source.account_id')
            ->where('accounts.business_id', $businessId)
            ->whereNull('accounts.deleted_at')
            ->whereNull('source.deleted_at')
            ->whereNull('source.transaction_payment_id')
            ->whereNull('source.transfer_transaction_id');

        $representedTransactionIds = DB::table('transactions as represented')
            ->where('represented.business_id', $businessId)
            ->whereIn('represented.type', ['sell', 'purchase', 'expense']);
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $representedTransactionIds->whereNull('represented.deleted_at');
        }
        $query->where(function ($query) use ($representedTransactionIds) {
            $query->whereNull('source.transaction_id')
                ->orWhereNotIn('source.transaction_id', (clone $representedTransactionIds)->select('represented.id'));
        });

        return $query->select(
            'source.id as source_id',
            'source.account_id',
            'source.type as movement_type',
            'source.sub_type',
            'source.amount',
            'source.operation_date',
            'source.transaction_id',
            'source.note'
        );
    }

    private function reviewUnpairedMainAccountMovements(int $businessId, bool $apply, array &$summary): void
    {
        foreach ($this->unpairedMainAccountMovementQuery($businessId)->orderBy('source.id')->cursor() as $movement) {
            $reason = (string) $movement->sub_type === 'opening_balance'
                ? 'saldo_awal_rekening_tidak_disalin'
                : 'pergerakan_rekening_tanpa_akun_lawan';
            $label = $reason === 'saldo_awal_rekening_tidak_disalin' ? 'Saldo awal rekening' : 'Mutasi rekening tanpa akun lawan';
            $summaryText = trim((string) ($movement->note ?? ''));
            if ($summaryText === '') {
                $summaryText = $label.' #'.$movement->source_id;
            }

            $this->registerReviewSource([
                'business_id' => $businessId,
                'source_record_type' => AccountTransaction::class,
                'source_record_id' => (int) $movement->source_id,
                'operation_date' => (string) $movement->operation_date,
                'source_reference' => 'ACCOUNT-TRANSACTION-'.$movement->source_id,
                'source_summary' => mb_substr($summaryText, 0, 1000),
                'source_context' => [
                    'account_id' => (int) $movement->account_id,
                    'movement_type' => (string) $movement->movement_type,
                    'sub_type' => $movement->sub_type,
                    'amount' => (float) $movement->amount,
                    'transaction_id' => $movement->transaction_id === null ? null : (int) $movement->transaction_id,
                ],
                'skip_reason' => $reason,
            ], $apply, $summary);
        }
    }

    private function reviewInvalidTransfers(int $businessId, bool $apply, array &$summary): void
    {
        $query = DB::table('account_transactions as source')
            ->join('accounts as source_account', 'source_account.id', '=', 'source.account_id')
            ->where('source_account.business_id', $businessId)
            ->whereNull('source_account.deleted_at')
            ->whereNotNull('source.transfer_transaction_id')
            ->whereIn('source.sub_type', ['fund_transfer', 'deposit'])
            ->whereNull('source.deleted_at')
            ->whereNotExists(function ($query) use ($businessId) {
                $query->select(DB::raw(1))
                    ->from('account_transactions as counterpart')
                    ->join('accounts as counterpart_account', 'counterpart_account.id', '=', 'counterpart.account_id')
                    ->where('counterpart_account.business_id', $businessId)
                    ->whereNull('counterpart_account.deleted_at')
                    ->whereColumn('counterpart.id', 'source.transfer_transaction_id')
                    ->whereColumn('counterpart.transfer_transaction_id', 'source.id')
                    ->whereColumn('counterpart.sub_type', 'source.sub_type')
                    ->whereColumn('counterpart.amount', 'source.amount')
                    ->whereColumn('counterpart.type', '<>', 'source.type')
                    ->whereNull('counterpart.deleted_at');
            })
            ->orderBy('source.id')
            ->select(
                'source_account.business_id as business_id',
                'source.id as source_id',
                'source.account_id',
                'source.type as movement_type',
                'source.sub_type',
                'source.amount',
                'source.operation_date',
                'source.transfer_transaction_id',
                'source.note'
            );

        foreach ($query->cursor() as $transfer) {
            $summaryText = trim((string) ($transfer->note ?? ''));
            if ($summaryText === '') {
                $summaryText = 'Transfer rekening #'.$transfer->source_id.' dengan pasangan #'.$transfer->transfer_transaction_id;
            }

            $this->registerReviewSource([
                'business_id' => $businessId,
                'source_record_type' => AccountTransaction::class,
                'source_record_id' => (int) $transfer->source_id,
                'operation_date' => (string) $transfer->operation_date,
                'source_reference' => 'ACCOUNT-TRANSFER-'.$transfer->source_id,
                'source_summary' => mb_substr($summaryText, 0, 1000),
                'source_context' => [
                    'account_id' => (int) $transfer->account_id,
                    'movement_type' => (string) $transfer->movement_type,
                    'sub_type' => (string) $transfer->sub_type,
                    'amount' => (float) $transfer->amount,
                    'counterpart_id' => (int) $transfer->transfer_transaction_id,
                ],
                'skip_reason' => 'pasangan_transfer_tidak_valid',
            ], $apply, $summary);
        }
    }

    private function invalidTransferCount(int $businessId): int
    {
        return DB::table('account_transactions as source')
            ->join('accounts as source_account', 'source_account.id', '=', 'source.account_id')
            ->whereNull('source_account.deleted_at')
            ->where('source_account.business_id', $businessId)
            ->whereIn('source.sub_type', ['fund_transfer', 'deposit'])
            ->whereNotNull('source.transfer_transaction_id')
            ->whereNull('source.deleted_at')
            ->whereNotExists(function ($query) use ($businessId) {
                $query->select(DB::raw(1))
                    ->from('account_transactions as counterpart')
                    ->join('accounts as counterpart_account', 'counterpart_account.id', '=', 'counterpart.account_id')
                    ->whereNull('counterpart_account.deleted_at')
                    ->where('counterpart_account.business_id', $businessId)
                    ->whereColumn('counterpart.id', 'source.transfer_transaction_id')
                    ->whereColumn('counterpart.transfer_transaction_id', 'source.id')
                    ->whereColumn('counterpart.sub_type', 'source.sub_type')
                    ->whereColumn('counterpart.amount', 'source.amount')
                    ->whereColumn('counterpart.type', '<>', 'source.type')
                    ->whereNull('counterpart.deleted_at');
            })
            ->count();
    }

    private function transactionSkipCandidate(object $transaction, string $reason, int $businessId): array
    {
        $type = (string) ($transaction->type ?? 'transaction');
        $reference = trim((string) ($transaction->invoice_no ?? '')) ?: trim((string) ($transaction->ref_no ?? ''));
        if ($reference === '') {
            $reference = strtoupper($type).'-'.(int) $transaction->id;
        }

        return [
            'skip_reason' => $reason,
            'review_source' => [
                'business_id' => $businessId,
                'source_record_type' => Transaction::class,
                'source_record_id' => (int) $transaction->id,
                'operation_date' => (string) ($transaction->transaction_date ?? ''),
                'source_reference' => $reference,
                'source_summary' => 'Transaksi '.$type.' #'.(int) $transaction->id.' ('.$reference.')',
                'source_context' => [
                    'transaction_type' => $type,
                    'location_id' => empty($transaction->location_id) ? null : (int) $transaction->location_id,
                    'expense_category_id' => empty($transaction->expense_category_id) ? null : (int) $transaction->expense_category_id,
                    'final_total' => isset($transaction->final_total) ? (float) $transaction->final_total : null,
                    'tax_amount' => isset($transaction->tax_amount) ? (float) $transaction->tax_amount : null,
                ],
            ],
        ];
    }

    private function paymentSkipCandidate(object $payment, string $reason, int $businessId): array
    {
        $reference = trim((string) ($payment->ref_no ?? '')) ?: trim((string) ($payment->invoice_no ?? ''));
        if ($reference === '') {
            $reference = 'PAYMENT-'.(int) $payment->payment_id;
        }

        return [
            'skip_reason' => $reason,
            'review_source' => [
                'business_id' => $businessId,
                'source_record_type' => TransactionPayment::class,
                'source_record_id' => (int) $payment->payment_id,
                'operation_date' => (string) ($payment->paid_on ?? ''),
                'source_reference' => $reference,
                'source_summary' => 'Pembayaran transaksi '.(string) $payment->transaction_type.' #'.(int) $payment->transaction_id.' ('.$reference.')',
                'source_context' => [
                    'transaction_id' => (int) $payment->transaction_id,
                    'transaction_type' => (string) $payment->transaction_type,
                    'location_id' => empty($payment->location_id) ? null : (int) $payment->location_id,
                    'payment_account_id' => empty($payment->payment_account_id) ? null : (int) $payment->payment_account_id,
                    'amount' => isset($payment->payment_amount) ? (float) $payment->payment_amount : null,
                ],
            ],
        ];
    }

    private function transferSkipCandidate(object $transfer, string $reason, int $businessId): array
    {
        return [
            'skip_reason' => $reason,
            'review_source' => [
                'business_id' => $businessId,
                'source_record_type' => AccountTransaction::class,
                'source_record_id' => (int) $transfer->source_id,
                'operation_date' => (string) ($transfer->source_operation_date ?? ''),
                'source_reference' => 'BANK-'.$transfer->source_id.'-'.$transfer->counterpart_id,
                'source_summary' => 'Transfer rekening #'.(int) $transfer->source_id.' dan #'.(int) $transfer->counterpart_id,
                'source_context' => [
                    'account_id' => (int) $transfer->source_account_id,
                    'counterpart_account_id' => (int) $transfer->counterpart_account_id,
                    'counterpart_id' => (int) $transfer->counterpart_id,
                    'movement_type' => (string) $transfer->source_sub_type,
                    'source_amount' => (float) $transfer->source_amount,
                    'counterpart_amount' => (float) $transfer->counterpart_amount,
                ],
            ],
        ];
    }

    private function reviewSourceFromGroup(array $candidate, string $reason): array
    {
        $sourceType = (string) ($candidate['source_record_type'] ?? '');
        $sourceId = (int) ($candidate['source_record_id'] ?? 0);
        $reference = trim((string) ($candidate['ref_no'] ?? ''));
        if ($reference === '') {
            $reference = strtoupper((string) ($candidate['sub_type'] ?? 'SOURCE')).'-'.$sourceId;
        }

        $mapInfo = is_array($candidate['map_info'] ?? null) ? $candidate['map_info'] : [];
        $mappingKey = (string) ($mapInfo['mapping_key'] ?? '');
        $transactionType = $sourceType === Transaction::class
            ? (string) ($candidate['sub_type'] ?? '')
            : (string) ($candidate['sub_type'] ?? '');
        if ($sourceType === TransactionPayment::class) {
            $transactionType = str_ends_with($transactionType, '_payment')
                ? substr($transactionType, 0, -8)
                : $transactionType;
        }
        $expenseCategoryId = preg_match('/^expense_(\d+)$/', $mappingKey, $matches) ? (int) $matches[1] : null;

        return [
            'business_id' => (int) ($candidate['business_id'] ?? 0),
            'source_record_type' => $sourceType,
            'source_record_id' => $sourceId,
            'operation_date' => (string) ($candidate['operation_date'] ?? ''),
            'source_reference' => $reference,
            'source_summary' => 'Sumber '.class_basename($sourceType).' #'.$sourceId.' ('.$reference.')',
            'source_context' => [
                'sub_type' => $candidate['sub_type'] ?? null,
                'transaction_id' => $candidate['transaction_id'] ?? null,
                'transaction_payment_id' => $candidate['transaction_payment_id'] ?? null,
                'source_account_transaction_id' => $candidate['source_account_transaction_id'] ?? null,
                'mapping_note' => $candidate['mapping_note'] ?? null,
                'transaction_type' => $transactionType !== '' ? $transactionType : null,
                'mapping_key' => $mappingKey !== '' ? $mappingKey : null,
                'location_id' => empty($mapInfo['location_id']) ? null : (int) $mapInfo['location_id'],
                'expense_category_id' => $expenseCategoryId,
                'account_id' => $candidate['source_main_account_id'] ?? null,
                'final_total' => $candidate['source_amount'] ?? null,
                'tax_amount' => $candidate['source_tax_amount'] ?? null,
            ],
            'skip_reason' => $reason,
        ];
    }

    private function registerReviewSource(?array $source, bool $apply, array &$summary): void
    {
        if ($source === null || ! $this->isActionableReviewReason((string) ($source['skip_reason'] ?? ''))) {
            return;
        }

        $businessId = (int) ($source['business_id'] ?? 0);
        $sourceType = (string) ($source['source_record_type'] ?? '');
        $sourceId = (int) ($source['source_record_id'] ?? 0);
        if ($businessId < 1 || $sourceType === '' || $sourceId < 1) {
            return;
        }

        if ($this->reviewSourceAlreadyLinked($source)) {
            if ($apply) {
                $this->resolveExistingReviewSource($source);
            }
            return;
        }

        $key = implode(':', [$businessId, $sourceType, $sourceId]);
        $summary['review_source_keys'][$key] = true;
        if (! $apply) {
            return;
        }

        $this->reviewQueueBuffer[$key] = $source;
        if (count($this->reviewQueueBuffer) >= 250) {
            $this->flushReviewQueue();
        }
    }

    private function reviewSourceAlreadyLinked(array $source): bool
    {
        if ($this->legacyRecheckMode && (string) ($source['skip_reason'] ?? '') === 'journal_source_link_ambiguous') {
            return false;
        }

        $mappingQuery = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', (int) $source['business_id'])
            ->where('source_record_type', (string) $source['source_record_type'])
            ->where('source_record_id', (int) $source['source_record_id']);
        if ($this->legacyRecheckMode) {
            $mappingQuery->where('type', '<>', 'general_ledger_backfill');
        }
        if ($mappingQuery->exists()) {
            return true;
        }

        if ($this->legacyRecheckMode) {
            return false;
        }

        $context = $source['source_context'] ?? [];
        $legacyRows = DB::table('accounting_accounts_transactions');
        if ((string) $source['source_record_type'] === Transaction::class) {
            return $legacyRows->where('transaction_id', (int) $source['source_record_id'])
                ->whereNull('transaction_payment_id')
                ->where('sub_type', (string) ($context['transaction_type'] ?? ''))
                ->exists();
        }

        if ((string) $source['source_record_type'] === TransactionPayment::class) {
            $paymentType = (string) ($context['transaction_type'] ?? '');

            return $legacyRows->where('transaction_payment_id', (int) $source['source_record_id'])
                ->where('sub_type', $paymentType.'_payment')
                ->exists();
        }

        return false;
    }

    private function isActionableReviewReason(string $reason): bool
    {
        return $reason !== '' && ! in_array($reason, [
            'transaksi_bukan_posting',
            'akun_debit_kredit_sama',
            'akun_pembayaran_sama_dengan_akun_lawan',
        ], true);
    }

    private function flushReviewQueue(): void
    {
        if ($this->reviewQueueBuffer === []) {
            return;
        }

        $items = $this->reviewQueueBuffer;
        $this->reviewQueueBuffer = [];
        DB::transaction(function () use ($items) {
            foreach ($items as $source) {
                $now = now();
                $identity = [
                    'business_id' => (int) $source['business_id'],
                    'source_record_type' => (string) $source['source_record_type'],
                    'source_record_id' => (int) $source['source_record_id'],
                ];
                $initialEvent = [[
                    'event' => 'queued',
                    'at' => $now->toIso8601String(),
                    'reason' => (string) $source['skip_reason'],
                    'by' => 'accounting:backfill-general-ledger --apply',
                ]];
                $inserted = DB::table(self::REVIEW_QUEUE_TABLE)->insertOrIgnore($identity + [
                    'operation_date' => $this->nullableDate($source['operation_date'] ?? null),
                    'source_reference' => mb_substr((string) ($source['source_reference'] ?? ''), 0, 191),
                    'source_summary' => mb_substr((string) ($source['source_summary'] ?? ''), 0, 1000),
                    'source_context' => json_encode($source['source_context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'location_id' => $this->reviewLocationId($source),
                    'mapping_key' => $this->reviewMappingKey($source),
                    'transaction_kind' => $this->reviewTransactionKind($source),
                    'main_account_id' => $this->reviewMainAccountId($source),
                    'counterpart_main_account_id' => $this->reviewCounterpartMainAccountId($source),
                    'skip_reason' => (string) $source['skip_reason'],
                    'status' => 'pending',
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'audit_log' => json_encode($initialEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($inserted > 0) {
                    continue;
                }

                $existing = DB::table(self::REVIEW_QUEUE_TABLE)
                    ->where($identity)
                    ->lockForUpdate()
                    ->first();
                if (! $existing) {
                    continue;
                }

                $auditLog = $this->decodeAuditLog($existing->audit_log ?? null);
                $changes = [];
                $nextReason = (string) $source['skip_reason'];
                if ((string) $existing->skip_reason !== $nextReason) {
                    $changes['reason'] = ['from' => (string) $existing->skip_reason, 'to' => $nextReason];
                }
                $context = $source['source_context'] ?? [];
                $oldContext = json_decode((string) ($existing->source_context ?? '[]'), true);
                if (! is_array($oldContext)) {
                    $oldContext = [];
                }
                if ($oldContext != $context) {
                    $changes['source_details_changed'] = true;
                }
                $reference = mb_substr((string) ($source['source_reference'] ?? ''), 0, 191);
                if ((string) ($existing->source_reference ?? '') !== $reference) {
                    $changes['source_reference_changed'] = true;
                }
                $operationDate = $this->nullableDate($source['operation_date'] ?? null);
                if ((string) ($existing->operation_date ?? '') !== (string) ($operationDate ?? '')) {
                    $changes['operation_date_changed'] = true;
                }
                $sourceSummary = mb_substr((string) ($source['source_summary'] ?? ''), 0, 1000);
                if ((string) $existing->source_summary !== $sourceSummary) {
                    $changes['summary_changed'] = true;
                }
                $reopened = (string) $existing->status !== 'pending';
                if ($reopened) {
                    $changes['status'] = ['from' => (string) $existing->status, 'to' => 'pending'];
                }
                if ($changes !== []) {
                    $auditLog[] = [
                        'event' => $reopened ? 'reopened' : 'updated',
                        'at' => $now->toIso8601String(),
                        'changes' => $changes,
                        'by' => 'accounting:backfill-general-ledger --apply',
                    ];
                }

                DB::table(self::REVIEW_QUEUE_TABLE)->where($identity)->update([
                    'operation_date' => $operationDate,
                    'source_reference' => $reference,
                    'source_summary' => $sourceSummary,
                    'source_context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'location_id' => $this->reviewLocationId($source),
                    'mapping_key' => $this->reviewMappingKey($source),
                    'transaction_kind' => $this->reviewTransactionKind($source),
                    'main_account_id' => $this->reviewMainAccountId($source),
                    'counterpart_main_account_id' => $this->reviewCounterpartMainAccountId($source),
                    'skip_reason' => $nextReason,
                    'status' => 'pending',
                    'last_seen_at' => $now,
                    'resolved_at' => null,
                    'audit_log' => json_encode($auditLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);
            }
        });
    }

    private function resolveExistingReviewSource(array $candidate, ?int $actorId = null, string $reason = 'source_already_linked'): void
    {
        if (! Schema::hasTable(self::REVIEW_QUEUE_TABLE)) {
            return;
        }

        $identity = [
            'business_id' => (int) $candidate['business_id'],
            'source_record_type' => (string) $candidate['source_record_type'],
            'source_record_id' => (int) $candidate['source_record_id'],
        ];
        DB::transaction(function () use ($identity, $actorId, $reason) {
            $review = DB::table(self::REVIEW_QUEUE_TABLE)
                ->where($identity)
                ->lockForUpdate()
                ->first();
            if (! $review || (string) $review->status === 'resolved') {
                return;
            }

            $auditLog = $this->decodeAuditLog($review->audit_log ?? null);
            $auditLog[] = [
                'event' => 'resolved',
                'at' => now()->toIso8601String(),
                'reason' => $reason,
                'by' => $actorId ?? 'accounting:backfill-general-ledger --apply',
            ];
            DB::table(self::REVIEW_QUEUE_TABLE)->where($identity)->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'audit_log' => json_encode($auditLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        });
    }

    private function decodeAuditLog($value): array
    {
        $decoded = json_decode((string) ($value ?? '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableDate($value): ?string
    {
        $date = trim((string) ($value ?? ''));

        return $date === '' ? null : $date;
    }

    private function reviewLocationId(array $source): ?int
    {
        $locationId = (int) ($source['source_context']['location_id'] ?? 0);

        return $locationId > 0 ? $locationId : null;
    }

    private function reviewMappingKey(array $source): ?string
    {
        $context = $source['source_context'] ?? [];
        if (! empty($context['mapping_key'])) {
            return (string) $context['mapping_key'];
        }
        $type = (string) ($context['transaction_type'] ?? '');
        if ((string) ($source['source_record_type'] ?? '') === Transaction::class) {
            if ($type === 'sell') {
                return 'sale';
            }
            if ($type === 'purchase') {
                return 'purchases';
            }
            if ($type === 'expense') {
                return empty($context['expense_category_id']) ? 'expense' : 'expense_'.(int) $context['expense_category_id'];
            }
        }
        if ((string) ($source['source_record_type'] ?? '') === TransactionPayment::class) {
            return in_array($type, ['sell', 'purchase'], true) ? $type.'_payment' : null;
        }

        return null;
    }

    private function reviewTransactionKind(array $source): ?string
    {
        $type = (string) ($source['source_context']['transaction_type'] ?? '');

        return $type !== '' ? $type : null;
    }

    private function reviewMainAccountId(array $source): ?int
    {
        $context = $source['source_context'] ?? [];
        $accountId = (int) ($context['payment_account_id'] ?? $context['account_id'] ?? 0);

        return $accountId > 0 ? $accountId : null;
    }

    private function reviewCounterpartMainAccountId(array $source): ?int
    {
        $accountId = (int) ($source['source_context']['counterpart_account_id'] ?? 0);

        return $accountId > 0 ? $accountId : null;
    }

    private function processCandidate(array $candidate, int $businessId, bool $apply, array &$summary): void
    {
        $summary['candidate_groups']++;
        if (! empty($candidate['journal_linked_source'])) {
            $summary['journal_linked_sources'] = ($summary['journal_linked_sources'] ?? 0) + 1;
            $summary['already_linked']++;
            if ($apply && is_array($candidate['review_source'] ?? null)) {
                $this->resolveExistingReviewSource($candidate['review_source'], null, 'source_journal_authoritative');
            }
            return;
        }
        if (! empty($candidate['skip_reason'])) {
            $reason = (string) $candidate['skip_reason'];
            $this->skip($summary, $reason);
            $reviewSource = $candidate['review_source'] ?? null;
            if (is_array($reviewSource)) {
                $reviewSource['skip_reason'] = $reason;
            }
            $this->registerReviewSource($reviewSource, $apply, $summary);
            return;
        }

        $debitAccounts = [];
        $creditAccounts = [];
        foreach ($candidate['lines'] as $line) {
            if ((float) $line['amount'] <= 0) {
                continue;
            }

            $accountId = (int) $line['accounting_account_id'];
            if ($line['type'] === 'debit') {
                $debitAccounts[$accountId] = true;
            } elseif ($line['type'] === 'credit') {
                $creditAccounts[$accountId] = true;
            }
        }
        if (array_intersect_key($debitAccounts, $creditAccounts) !== []) {
            $this->skip($summary, 'akun_debit_kredit_sama');
            $this->registerReviewSource($this->reviewSourceFromGroup($candidate, 'akun_debit_kredit_sama'), $apply, $summary);
            return;
        }

        $debit = round(array_sum(array_map(fn ($line) => $line['type'] === 'debit' ? $line['amount'] : 0, $candidate['lines'])), 4);
        $credit = round(array_sum(array_map(fn ($line) => $line['type'] === 'credit' ? $line['amount'] : 0, $candidate['lines'])), 4);
        if ($candidate['lines'] === [] || round($debit - $credit, 4) !== 0.0) {
            $this->skip($summary, 'group_jurnal_tidak_seimbang');
            $this->registerReviewSource($this->reviewSourceFromGroup($candidate, 'group_jurnal_tidak_seimbang'), $apply, $summary);
            return;
        }

        if ($this->sourceAlreadyLinked($candidate)) {
            $summary['already_linked']++;
            if ($apply) {
                $this->resolveExistingReviewSource($candidate, isset($candidate['applied_by']) ? (int) $candidate['applied_by'] : null);
            }
            return;
        }
        if ($this->legacyRecheckMode && $this->hasNonReplaceableLegacyLedgerRows($candidate)) {
            $this->skip($summary, 'gl_manual_atau_tidak_dikenali_dipertahankan');
            $this->registerReviewSource($this->reviewSourceFromGroup($candidate, 'gl_manual_atau_tidak_dikenali_dipertahankan'), $apply, $summary);
            return;
        }
        if ($this->legacyRecheckMode && $this->generatedPostingMatchesCandidate($candidate)) {
            $summary['already_linked']++;
            if ($apply) {
                $this->resolveExistingReviewSource($candidate, null, 'source_posting_unchanged');
            }
            return;
        }
        if (! $this->legacyRecheckMode && $this->hasLegacyLedgerRows($candidate)) {
            $summary['already_linked']++;
            if ($apply) {
                $this->resolveExistingReviewSource($candidate, isset($candidate['applied_by']) ? (int) $candidate['applied_by'] : null);
            }
            return;
        }

        $summary['planned_groups']++;
        $summary['planned_rows'] += count($candidate['lines']);
        $date = substr((string) $candidate['operation_date'], 0, 10);
        $summary['date_start'] = $summary['date_start'] === null ? $date : min($summary['date_start'], $date);
        $summary['date_end'] = $summary['date_end'] === null ? $date : max($summary['date_end'], $date);
        $summary['debit_total'] = round($summary['debit_total'] + $debit, 4);
        $summary['credit_total'] = round($summary['credit_total'] + $credit, 4);
        if (! empty($candidate['uses_current_map'])) {
            $summary['current_map_groups']++;
            if (! empty($candidate['map_info'])) {
                $key = implode(':', [
                    $candidate['map_info']['location_id'],
                    $candidate['map_info']['mapping_key'],
                    $candidate['map_info']['payment_account_id'],
                    $candidate['map_info']['deposit_to_id'],
                ]);
                if (! isset($summary['current_map_details'][$key])) {
                    $summary['current_map_details'][$key] = $candidate['map_info'] + ['groups' => 0];
                }
                $summary['current_map_details'][$key]['groups']++;
            }
        }
        if (! empty($candidate['uses_auto_map'])) {
            $summary['auto_mapped_groups']++;
            if (! empty($candidate['map_info'])) {
                $info = $candidate['map_info'];
                $key = implode(':', [
                    $info['location_id'],
                    $info['mapping_key'],
                    $info['payment_account_id'],
                    $info['deposit_to_id'],
                ]);
                if (! isset($summary['auto_map_details'][$key])) {
                    $summary['auto_map_details'][$key] = $info + ['groups' => 0];
                }
                $summary['auto_map_details'][$key]['groups']++;
            }
        }
        if (! empty($candidate['uses_journal_pattern'])) {
            $summary['journal_pattern_groups'] = ($summary['journal_pattern_groups'] ?? 0) + 1;
        }
        if (! empty($candidate['uses_operational_evidence'])) {
            $summary['operational_evidence_groups'] = ($summary['operational_evidence_groups'] ?? 0) + 1;
        }
        if (! empty($candidate['requires_vat_account'])) {
            $summary['vat_split_groups'] = ($summary['vat_split_groups'] ?? 0) + 1;
        }
        foreach ($candidate['account_links'] as $link) {
            $key = $link['accounting_account_id'].':'.$link['account_id'];
            $summary['account_links'][$key] = $link;
        }

        if (! $apply) {
            return;
        }

        $applied = $this->applyGroup($candidate, $businessId);
        if ($applied) {
            $summary['applied_groups']++;
            if ($this->legacyRecheckMode) {
                $summary['replaced_operational_postings'] = ($summary['replaced_operational_postings'] ?? 0) + 1;
                $this->resolveExistingReviewSource($candidate, null, 'source_posting_rechecked');
            }
        } else {
            $summary['already_linked']++;
        }
    }

    private function applyGroup(array $candidate, int $businessId): bool
    {
        return DB::transaction(function () use ($candidate, $businessId) {
            if (! $this->lockSourceRecord($candidate)) {
                return false;
            }
            if ($this->sourceAlreadyLinked($candidate)) {
                return false;
            }
            if ($this->legacyRecheckMode) {
                if ($this->hasNonReplaceableLegacyLedgerRows($candidate)) {
                    return false;
                }
                $this->deleteReplaceableGeneratedRows($candidate);
            } elseif ($this->hasLegacyLedgerRows($candidate)) {
                return false;
            }

            foreach ($candidate['account_links'] as $link) {
                $account = DB::table('accounting_accounts')
                    ->where('business_id', $businessId)
                    ->where('id', $link['accounting_account_id'])
                    ->lockForUpdate()
                    ->first(['id', 'account_id']);
                if (! $account || ($account->account_id !== null && (int) $account->account_id !== (int) $link['account_id'])) {
                    throw new \RuntimeException('Crosswalk akun berubah saat apply; jalankan dry-run ulang.');
                }
                if ($account->account_id === null) {
                    DB::table('accounting_accounts')
                        ->where('business_id', $businessId)
                        ->where('id', $link['accounting_account_id'])
                        ->whereNull('account_id')
                        ->update(['account_id' => $link['account_id'], 'updated_at' => now()]);
                }
            }

            $now = now();
            $mappingId = DB::table('accounting_acc_trans_mappings')->insertGetId([
                'business_id' => $businessId,
                'ref_no' => mb_substr((string) $candidate['ref_no'], 0, 100),
                'type' => 'general_ledger_backfill',
                'created_by' => max(1, (int) $candidate['created_by']),
                'operation_date' => $candidate['operation_date'],
                'note' => $candidate['mapping_note'],
                'source_record_type' => $candidate['source_record_type'],
                'source_record_id' => $candidate['source_record_id'],
                'source_link_status' => 'matched',
                'source_link_note' => $candidate['mapping_note'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($candidate['lines'] as $line) {
                DB::table('accounting_accounts_transactions')->insert([
                    'accounting_account_id' => $line['accounting_account_id'],
                    'acc_trans_mapping_id' => $mappingId,
                    'transaction_id' => $candidate['transaction_id'],
                    'transaction_payment_id' => $candidate['transaction_payment_id'],
                    'amount' => $line['amount'],
                    'type' => $line['type'],
                    'sub_type' => $candidate['sub_type'],
                    'map_type' => 'general_ledger_backfill',
                    'created_by' => max(1, (int) $candidate['created_by']),
                    'operation_date' => $candidate['operation_date'],
                    'note' => $candidate['mapping_note'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return true;
        });
    }

    private function lockSourceRecord(array $candidate): bool
    {
        $tables = [
            Transaction::class => 'transactions',
            TransactionPayment::class => 'transaction_payments',
            AccountTransaction::class => 'account_transactions',
        ];
        $table = $tables[$candidate['source_record_type']] ?? null;
        if ($table === null) {
            return false;
        }

        return DB::table($table)
            ->where('id', $candidate['source_record_id'])
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    private function sourceAlreadyLinked(array $candidate): bool
    {
        $query = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $candidate['business_id'])
            ->where('source_record_type', $candidate['source_record_type'])
            ->where('source_record_id', $candidate['source_record_id']);
        if ($this->legacyRecheckMode) {
            $query->where('type', '<>', 'general_ledger_backfill');
        }

        return $query->exists();
    }

    private function hasLegacyLedgerRows(array $candidate): bool
    {
        $query = DB::table('accounting_accounts_transactions');
        if ($candidate['transaction_id'] !== null) {
            $query->where('transaction_id', $candidate['transaction_id'])
                ->whereNull('transaction_payment_id');
        } elseif ($candidate['transaction_payment_id'] !== null) {
            $query->where('transaction_payment_id', $candidate['transaction_payment_id']);
        } else {
            return false;
        }

        return $query->where('sub_type', $candidate['sub_type'])->exists();
    }

    private function hasNonReplaceableLegacyLedgerRows(array $candidate): bool
    {
        $query = DB::table('accounting_accounts_transactions as line')
            ->leftJoin('accounting_acc_trans_mappings as mapping', 'mapping.id', '=', 'line.acc_trans_mapping_id');
        if ($candidate['transaction_id'] !== null) {
            $query->where('line.transaction_id', $candidate['transaction_id'])
                ->whereNull('line.transaction_payment_id');
        } elseif ($candidate['transaction_payment_id'] !== null) {
            $query->where('line.transaction_payment_id', $candidate['transaction_payment_id']);
        } else {
            return $this->hasLegacyLedgerRows($candidate);
        }

        $rows = $query->where('line.sub_type', $candidate['sub_type'])
            ->get([
                'line.acc_trans_mapping_id',
                'line.map_type',
                'mapping.type as mapping_type',
                'mapping.business_id as mapping_business_id',
                'mapping.source_record_type as mapping_source_type',
                'mapping.source_record_id as mapping_source_id',
            ]);

        foreach ($rows as $row) {
            if ($row->acc_trans_mapping_id === null
                && in_array((string) $row->map_type, ['payment_account', 'deposit_to'], true)) {
                continue;
            }
            if ((string) ($row->mapping_type ?? '') === 'general_ledger_backfill'
                && (int) ($row->mapping_business_id ?? 0) === (int) $candidate['business_id']
                && (string) ($row->mapping_source_type ?? '') === (string) $candidate['source_record_type']
                && (int) ($row->mapping_source_id ?? 0) === (int) $candidate['source_record_id']) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function deleteReplaceableGeneratedRows(array $candidate): void
    {
        $mappingIds = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $candidate['business_id'])
            ->where('source_record_type', $candidate['source_record_type'])
            ->where('source_record_id', $candidate['source_record_id'])
            ->where('type', 'general_ledger_backfill')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($mappingIds !== []) {
            DB::table('accounting_accounts_transactions')->whereIn('acc_trans_mapping_id', $mappingIds)->delete();
            DB::table('accounting_acc_trans_mappings')
                ->where('business_id', $candidate['business_id'])
                ->whereIn('id', $mappingIds)
                ->delete();
        }

        $query = DB::table('accounting_accounts_transactions')
            ->whereNull('acc_trans_mapping_id')
            ->where('sub_type', $candidate['sub_type'])
            ->whereIn('map_type', ['payment_account', 'deposit_to']);
        if ($candidate['transaction_id'] !== null) {
            $query->where('transaction_id', $candidate['transaction_id'])
                ->whereNull('transaction_payment_id');
        } elseif ($candidate['transaction_payment_id'] !== null) {
            $query->where('transaction_payment_id', $candidate['transaction_payment_id']);
        }
        $query->delete();
    }

    private function generatedPostingMatchesCandidate(array $candidate): bool
    {
        $mappings = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', (int) $candidate['business_id'])
            ->where('source_record_type', (string) $candidate['source_record_type'])
            ->where('source_record_id', (int) $candidate['source_record_id'])
            ->where('type', 'general_ledger_backfill')
            ->get(['id', 'ref_no', 'operation_date']);
        if ($mappings->count() !== 1) {
            return false;
        }

        $mapping = $mappings->first();
        if ((string) $mapping->ref_no !== mb_substr((string) $candidate['ref_no'], 0, 100)
            || substr((string) $mapping->operation_date, 0, 19) !== substr((string) $candidate['operation_date'], 0, 19)) {
            return false;
        }

        if ($candidate['transaction_id'] !== null) {
            $hasUnmappedAutomaticRows = DB::table('accounting_accounts_transactions')
                ->where('transaction_id', (int) $candidate['transaction_id'])
                ->whereNull('transaction_payment_id')
                ->where('sub_type', (string) $candidate['sub_type'])
                ->whereNull('acc_trans_mapping_id')
                ->whereIn('map_type', ['payment_account', 'deposit_to'])
                ->exists();
            if ($hasUnmappedAutomaticRows) {
                return false;
            }
        } elseif ($candidate['transaction_payment_id'] !== null) {
            $hasUnmappedAutomaticRows = DB::table('accounting_accounts_transactions')
                ->where('transaction_payment_id', (int) $candidate['transaction_payment_id'])
                ->where('sub_type', (string) $candidate['sub_type'])
                ->whereNull('acc_trans_mapping_id')
                ->whereIn('map_type', ['payment_account', 'deposit_to'])
                ->exists();
            if ($hasUnmappedAutomaticRows) {
                return false;
            }
        }

        $actual = DB::table('accounting_accounts_transactions')
            ->where('acc_trans_mapping_id', (int) $mapping->id)
            ->get(['accounting_account_id', 'amount', 'type', 'sub_type', 'map_type', 'transaction_id', 'transaction_payment_id']);
        if ($actual->count() !== count($candidate['lines'])) {
            return false;
        }

        $actualLines = [];
        foreach ($actual as $line) {
            if ((string) $line->sub_type !== (string) $candidate['sub_type']
                || (string) $line->map_type !== 'general_ledger_backfill'
                || ($candidate['transaction_id'] === null
                    ? $line->transaction_id !== null
                    : (int) $line->transaction_id !== (int) $candidate['transaction_id'])
                || ($candidate['transaction_payment_id'] === null
                    ? $line->transaction_payment_id !== null
                    : (int) $line->transaction_payment_id !== (int) $candidate['transaction_payment_id'])) {
                return false;
            }
            $actualLines[] = sprintf('%d|%s|%.4f', (int) $line->accounting_account_id, (string) $line->type, (float) $line->amount);
        }

        $expectedLines = array_map(
            fn (array $line) => sprintf('%d|%s|%.4f', (int) $line['accounting_account_id'], (string) $line['type'], (float) $line['amount']),
            $candidate['lines']
        );
        sort($actualLines);
        sort($expectedLines);

        return $actualLines === $expectedLines;
    }

    private function group(
        int $businessId,
        string $sourceType,
        int $sourceId,
        string $subType,
        string $date,
        int $createdBy,
        string $reference,
        string $note,
        array $lines,
        bool $usesCurrentMap,
        ?int $transactionId,
        ?int $paymentId,
        array $accountLinks,
        bool $requiresVatAccount,
        ?int $sourceAccountTransactionId = null,
        ?array $mapInfo = null
    ): array {
        return [
            'business_id' => $businessId,
            'source_record_type' => $sourceType,
            'source_record_id' => $sourceId,
            'source_account_transaction_id' => $sourceAccountTransactionId,
            'sub_type' => $subType,
            'operation_date' => $date,
            'created_by' => $createdBy,
            'ref_no' => $reference,
            'mapping_note' => $note,
            'lines' => $lines,
            'uses_current_map' => $usesCurrentMap,
            'transaction_id' => $transactionId,
            'transaction_payment_id' => $paymentId,
            'account_links' => $accountLinks,
            'requires_vat_account' => $requiresVatAccount,
            'map_info' => $mapInfo,
        ];
    }

    private function mapInfo(
        int $locationId,
        string $mappingKey,
        array $pair,
        string $mappingSource = 'location',
        ?float $paymentAccountScore = null,
        ?float $depositToScore = null,
        ?string $paymentAccountMethod = null,
        ?string $depositToMethod = null
    ): array {
        return [
            'location_id' => $locationId,
            'mapping_key' => $mappingKey,
            'mapping_source' => $mappingSource,
            'payment_account_id' => (int) $pair['payment_account']->id,
            'payment_account_name' => (string) $pair['payment_account']->name,
            'payment_account_score' => $paymentAccountScore,
            'payment_account_method' => $paymentAccountMethod,
            'deposit_to_id' => (int) $pair['deposit_to']->id,
            'deposit_to_name' => (string) $pair['deposit_to']->name,
            'deposit_to_score' => $depositToScore,
            'deposit_to_method' => $depositToMethod,
        ];
    }

    private function line(int $accountId, string $type, float $amount): array
    {
        return [
            'accounting_account_id' => $accountId,
            'type' => $type,
            'amount' => round($amount, 4),
        ];
    }

    private function resolveAccountingPair(int $businessId, array $map): array
    {
        $paymentId = (int) ($map['payment_account'] ?? 0);
        $depositId = (int) ($map['deposit_to'] ?? 0);
        if ($paymentId < 1 || $depositId < 1) {
            return ['reason' => 'mapping_lokasi_tidak_lengkap'];
        }
        if ($paymentId === $depositId) {
            return ['reason' => 'akun_lawan_sama'];
        }

        $accounts = collect($this->accountingAccountsCache[$businessId] ?? []);
        $payment = $accounts->firstWhere('id', $paymentId);
        $deposit = $accounts->firstWhere('id', $depositId);
        if (! $payment || ! $deposit) {
            return ['reason' => 'akun_mapping_tidak_ada_di_business'];
        }

        return ['payment_account' => $payment, 'deposit_to' => $deposit];
    }

    private function inferTransactionPair(int $businessId, string $type): array
    {
        if ($type === 'sell') {
            $revenue = $this->nearestAccountingAccount($businessId, 'Sales', ['income']);
            $receivable = $this->inferOperationalRoleAccount($businessId, 'receivable');
            if (! empty($revenue['reason']) || ! empty($receivable['reason'])) {
                return ['reason' => $revenue['reason'] ?? $receivable['reason']];
            }

            return [
                'pair' => ['payment_account' => $revenue['account'], 'deposit_to' => $receivable['account']],
                'payment_account_score' => $revenue['score'],
                'deposit_to_score' => $receivable['score'],
                'payment_account_method' => $revenue['method'] ?? 'nearest_name',
                'deposit_to_method' => $receivable['method'] ?? 'nearest_name',
                'account_links' => $receivable['crosswalk'] ? [$receivable['crosswalk']] : [],
            ];
        }

        if ($type === 'purchase') {
            $purchase = $this->nearestAccountingAccount($businessId, 'Purchases', ['expenses']);
            $payable = $this->inferOperationalRoleAccount($businessId, 'payable');
            if (! empty($purchase['reason']) || ! empty($payable['reason'])) {
                return ['reason' => $purchase['reason'] ?? $payable['reason']];
            }

            return [
                'pair' => ['payment_account' => $payable['account'], 'deposit_to' => $purchase['account']],
                'payment_account_score' => $payable['score'],
                'deposit_to_score' => $purchase['score'],
                'payment_account_method' => $payable['method'] ?? 'nearest_name',
                'deposit_to_method' => $purchase['method'] ?? 'nearest_name',
                'account_links' => $payable['crosswalk'] ? [$payable['crosswalk']] : [],
            ];
        }

        return ['reason' => 'jenis_transaksi_tidak_mendukung_pemetaan_otomatis'];
    }

    private function loadExpensePaymentGroups(int $businessId): array
    {
        if (! Schema::hasTable('transaction_payments')) {
            return [];
        }

        $query = DB::table('transaction_payments as payment')
            ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
            ->where('transaction.business_id', $businessId)
            ->where('payment.business_id', $businessId)
            ->where('transaction.type', 'expense')
            ->where('transaction.status', 'final')
            ->groupBy('payment.transaction_id', 'payment.account_id')
            ->selectRaw('payment.transaction_id, payment.account_id, SUM(payment.amount) as amount');

        if (Schema::hasColumn('transaction_payments', 'deleted_at')) {
            $query->whereNull('payment.deleted_at');
        }
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $query->whereNull('transaction.deleted_at');
        }
        if (Schema::hasColumn('transaction_payments', 'is_return')) {
            $query->where('payment.is_return', 0);
        }
        if (Schema::hasColumn('transaction_payments', 'is_advance')) {
            $query->where('payment.is_advance', 0);
        }

        return $query->get()->groupBy('transaction_id')->all();
    }

    private function inferExpensePair(int $businessId, object $transaction, bool $requireUniqueEvidence = false): array
    {
        $category = $this->expenseCategoriesCache[$businessId][(int) ($transaction->expense_category_id ?? 0)] ?? null;
        if (! $category) {
            return ['reason' => 'kategori_biaya_tidak_ditemukan'];
        }

        $expenseAccount = $requireUniqueEvidence
            ? $this->exactOperationalAccountByName(
                $businessId,
                (string) $category->name,
                $this->accountTypesForExpenseCategory((string) $category->name)
            )
            : $this->nearestAccountingAccount(
                $businessId,
                (string) $category->name,
                $this->accountTypesForExpenseCategory((string) $category->name)
            );
        if (! empty($expenseAccount['reason'])) {
            return $expenseAccount;
        }

        $total = round((float) ($transaction->final_total ?? 0), 4);
        $payments = collect($this->expensePaymentGroupsCache[$businessId][(int) $transaction->id] ?? []);
        $paidTotal = round((float) $payments->sum(fn ($payment) => (float) ($payment->amount ?? 0)), 4);
        if ($paidTotal > $total + 0.0001) {
            return ['reason' => 'pembayaran_melebihi_total_transaksi'];
        }

        $paymentLines = [];
        $accountLinks = [];
        $paymentAccounts = [];
        foreach ($payments as $payment) {
            $amount = round((float) ($payment->amount ?? 0), 4);
            if ($amount == 0.0) {
                continue;
            }
            $operationalAccountId = (int) ($payment->account_id ?? 0);
            if ($operationalAccountId < 1) {
                return ['reason' => 'akun_pembayaran_operasional_kosong'];
            }

            $resolved = $this->mainAccountMapping($businessId, $operationalAccountId, ! $requireUniqueEvidence);
            if (! empty($resolved['reason'])) {
                return $resolved;
            }

            $paymentAccounts[] = [
                'account' => $resolved['account'],
                'score' => $resolved['score'] ?? 1.0,
                'source_name' => $resolved['source_name'] ?? '',
                'method' => $resolved['method'] ?? (! empty($resolved['automatic_nearest']) ? 'nearest_name' : 'account_id'),
            ];
            if (! empty($resolved['crosswalk'])) {
                $accountLinks[] = $resolved['crosswalk'];
            }
            $paymentLines[] = $this->line(
                (int) $resolved['account']->id,
                $amount > 0 ? 'credit' : 'debit',
                abs($amount)
            );
        }

        if ($paidTotal < $total - 0.0001) {
            $payable = $this->inferOperationalRoleAccount($businessId, 'payable', ! $requireUniqueEvidence);
            if (! empty($payable['reason'])) {
                return $payable;
            }
            $paymentLines[] = $this->line((int) $payable['account']->id, 'credit', $total - $paidTotal);
            $paymentAccounts[] = [
                'account' => $payable['account'],
                'score' => $payable['score'],
                'source_name' => $payable['source_name'],
                'method' => $payable['method'] ?? 'nearest_name',
            ];
        }

        if ($paymentAccounts === []) {
            return ['reason' => 'akun_pembayaran_operasional_kosong'];
        }

        $primaryPayment = $paymentAccounts[0];
        return [
            'pair' => [
                'payment_account' => $primaryPayment['account'],
                'deposit_to' => $expenseAccount['account'],
            ],
            'payment_account_score' => $primaryPayment['score'],
            'deposit_to_score' => $expenseAccount['score'],
            'payment_account_method' => $primaryPayment['method'],
            'deposit_to_method' => $expenseAccount['method'] ?? 'nearest_name',
            'payment_lines' => $paymentLines,
            'account_links' => array_values(array_unique($accountLinks, SORT_REGULAR)),
            'payment_source_names' => array_values(array_unique(array_filter(array_column($paymentAccounts, 'source_name')))),
            'operational_evidence' => $requireUniqueEvidence,
        ];
    }

    private function inferOperationalRoleAccount(int $businessId, string $role, bool $allowNearest = true): array
    {
        $roles = [
            'receivable' => [
                'operational_names' => ['Account Receivable', 'Accounts Receivable', 'Piutang Usaha'],
                'coa_name' => 'Accounts Receivable',
                'coa_names' => ['Accounts Receivable (A/R)', 'Accounts Receivable', 'Account Receivable', 'Piutang Usaha'],
                'account_types' => ['asset'],
            ],
            'payable' => [
                'operational_names' => ['Trade Payable', 'Account Payable', 'Accounts Payable', 'Utang Usaha', 'Hutang Usaha'],
                'coa_name' => 'Trade Payable',
                'coa_names' => ['Trade Payable', 'Account Payable', 'Accounts Payable', 'Utang Usaha', 'Hutang Usaha'],
                'account_types' => ['liability'],
            ],
        ];
        if (! isset($roles[$role])) {
            return ['reason' => 'peran_akun_operasional_tidak_dikenal'];
        }

        $roleConfig = $roles[$role];
        $aliases = array_map([$this, 'normalizeExactAccountName'], $roleConfig['operational_names']);
        $query = DB::table('accounts')->where('business_id', $businessId);
        if (Schema::hasColumn('accounts', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $normalizeRoleName = $allowNearest ? 'normalizeAccountName' : 'normalizeExactAccountName';
        $operationalAccounts = $query->get(['id', 'name'])
            ->filter(fn ($account) => in_array($this->{$normalizeRoleName}((string) $account->name), $aliases, true))
            ->values();

        if (! $allowNearest && $operationalAccounts->count() > 1) {
            return ['reason' => 'akun_peran_operasional_ambigu'];
        }

        if ($operationalAccounts->count() === 1) {
            $mapped = $this->mainAccountMapping($businessId, (int) $operationalAccounts[0]->id, $allowNearest);
            if (! empty($mapped['reason'])) {
                return $mapped;
            }

            return [
                'account' => $mapped['account'],
                'score' => $mapped['score'] ?? 1.0,
                'source_name' => (string) $operationalAccounts[0]->name,
                'method' => $mapped['method'] ?? ($mapped['crosswalk'] === null ? 'account_id' : 'exact_name'),
                'automatic_nearest' => ! empty($mapped['automatic_nearest']),
                'crosswalk' => $mapped['crosswalk'] ?? null,
            ];
        }

        if (! $allowNearest) {
            $matches = [];
            foreach ($roleConfig['coa_names'] as $coaName) {
                $resolved = $this->exactOperationalAccountByName($businessId, $coaName, $roleConfig['account_types']);
                if (empty($resolved['reason'])) {
                    $matches[(int) $resolved['account']->id] = $resolved;
                }
            }

            if (count($matches) !== 1) {
                return ['reason' => count($matches) > 1 ? 'akun_coa_peran_operasional_ambigu' : 'akun_coa_peran_operasional_tidak_ditemukan'];
            }

            $resolved = array_values($matches)[0];

            return [
                'account' => $resolved['account'],
                'score' => 1.0,
                'source_name' => $resolved['source_name'],
                'method' => 'exact_name',
                'crosswalk' => null,
            ];
        }

        $nearest = $this->nearestAccountingAccount($businessId, $roleConfig['coa_name'], $roleConfig['account_types']);
        if (! empty($nearest['reason'])) {
            return $nearest;
        }

        return $nearest + ['source_name' => $roleConfig['coa_name']];
    }

    private function nearestAccountingAccount(int $businessId, string $sourceName, array $allowedTypes): array
    {
        $candidates = array_values(array_filter(
            $this->accountingAccountsCache[$businessId] ?? [],
            fn ($account) => in_array(strtolower((string) ($account->account_primary_type ?? '')), $allowedTypes, true)
        ));
        if ($candidates === []) {
            return ['reason' => 'akun_coa_untuk_tipe_tidak_ditemukan'];
        }

        $hintName = $this->semanticCoaHint($sourceName);
        if ($hintName !== null) {
            $hintedAccount = collect($candidates)->first(
                fn ($account) => $this->normalizeAccountName((string) $account->name) === $this->normalizeAccountName($hintName)
            );
            if ($hintedAccount) {
                return [
                    'account' => $hintedAccount,
                    'score' => 1.0,
                    'source_name' => $sourceName,
                    'method' => 'semantic_hint',
                ];
            }
        }

        $ranked = [];
        foreach ($candidates as $account) {
            $ranked[] = [
                'account' => $account,
                'score' => $this->accountNameSimilarity($sourceName, (string) $account->name),
            ];
        }
        usort($ranked, function ($left, $right) {
            $scoreOrder = $right['score'] <=> $left['score'];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            return (int) $left['account']->id <=> (int) $right['account']->id;
        });

        return [
            'account' => $ranked[0]['account'],
            'score' => round((float) $ranked[0]['score'], 4),
            'source_name' => $sourceName,
            'method' => 'nearest_name',
        ];
    }

    private function semanticCoaHint(string $sourceName): ?string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', trim($sourceName)) ?: '');
        $key = trim(preg_replace('/\s+/', ' ', $key) ?: '');
        $hints = [
            'cash' => 'Cash and cash equivalents',
            'giro' => 'Cash and cash equivalents',
            'petty cash pool' => 'Cash and cash equivalents',
            'mandiri 9641' => 'MANDIRI - BMT',
            'mandiri pt' => 'MANDIRI - BMT',
            'employee receivables' => 'Accounts Receivable (A/R)',
            'employee receivables expenses' => 'Accounts Receivable (A/R)',
            'kasbon' => 'Accounts Receivable (A/R)',
            'accumulated depreciation vehicles' => 'Accumulated depreciation on property, plant and equipment',
            'accumulated depreciation machinery equipment' => 'Accumulated depreciation on property, plant and equipment',
            'purchase discounts' => 'Discounts given - COS',
            'repair and maintenance hpp' => 'Repair and maintenance',
            'donations' => 'Other general and administrative expenses',
            'transportation' => 'Travel expenses - general and admin expenses',
            'uang jalan' => 'Travel expenses - general and admin expenses',
            'travelling general' => 'Travel expenses - general and admin expenses',
            'fuel toll and parking general' => 'Travel expenses - general and admin expenses',
            'travelling sales' => 'Travel expenses - selling expense',
            'fuel toll and parking sales' => 'Travel expenses - selling expense',
            'employee benefit expense' => 'Wage expenses',
            'other benefit and allowances' => 'Wage expenses',
            'salaries' => 'Wage expenses',
            'overtime' => 'Wage expenses',
            'thr and bonus' => 'Wage expenses',
            'incentive' => 'Wage expenses',
            'uniform' => 'Wage expenses',
            'meals' => 'Meals and entertainment',
            'entertainment' => 'Meals and entertainment',
            'food' => 'Meals and entertainment',
            'meals and transport' => 'Meals and entertainment',
            'medical' => 'Insurance - General',
            'insurance' => 'Insurance - General',
            'bank administration fee' => 'Bank charges',
            'bank admin' => 'Bank charges',
            'document handling expense' => 'Office expenses',
            'taxes and licenses' => 'Other general and administrative expenses',
            'tunjangan pph' => 'Income tax expense',
            'bank loan' => 'Long-term debt',
            'depreciation vehicle' => 'Amortisation expense',
            'depreciation machinery equipment' => 'Amortisation expense',
            'maintenance general' => 'Repair and maintenance',
            'training developments' => 'Dues and Subscriptions',
            'cost of production' => 'Overhead - COS',
            'inventory adjustments' => 'Change in inventory - COS',
            'other miscellaneous expense' => 'Other general and administrative expenses',
            'securities and cleaning' => 'Other general and administrative expenses',
            'general affair general' => 'Other general and administrative expenses',
            'security general' => 'Other general and administrative expenses',
            'cleaning general' => 'Other general and administrative expenses',
            'souvenir' => 'Other Types of Expenses-Advertising Expenses',
            'training and developments' => 'Dues and Subscriptions',
            'shipping freight delivery' => 'Freight and delivery - COS',
            'shippingan' => 'Freight and delivery - COS',
            'utilities expense' => 'Utilities',
            'electricity' => 'Utilities',
            'water' => 'Utilities',
            'communication general' => 'Utilities',
            'communication sales' => 'Utilities',
        ];

        return $hints[$key] ?? null;
    }

    private function accountNameSimilarity(string $sourceName, string $candidateName): float
    {
        $source = $this->normalizeAccountName($sourceName);
        $candidate = $this->normalizeAccountName($candidateName);
        if ($source === '' || $candidate === '') {
            return 0.0;
        }
        if ($source === $candidate) {
            return 1.0;
        }

        $sourceTokens = array_values(array_unique(explode(' ', $source)));
        $candidateTokens = array_values(array_unique(explode(' ', $candidate)));
        $intersection = count(array_intersect($sourceTokens, $candidateTokens));
        $union = count(array_unique(array_merge($sourceTokens, $candidateTokens)));
        $tokenScore = $union > 0 ? $intersection / $union : 0.0;
        $maxLength = max(strlen($source), strlen($candidate));
        $stringScore = $maxLength > 0 ? 1 - (levenshtein($source, $candidate) / $maxLength) : 0.0;

        return (0.7 * $tokenScore) + (0.3 * $stringScore);
    }

    private function normalizeAccountName(string $name): string
    {
        $name = strtolower(str_replace('&', ' and ', trim($name)));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?: '';
        $tokens = array_filter(explode(' ', trim($name)), fn ($token) => $token !== '');
        $aliases = [
            'accounts' => 'account',
            'receivables' => 'receivable',
            'payables' => 'payable',
            'purchases' => 'purchase',
            'sales' => 'sale',
            'expenses' => 'expense',
            'travelling' => 'travel',
            'transportation' => 'travel',
            'transport' => 'travel',
            'fuel' => 'travel',
            'toll' => 'travel',
            'parking' => 'travel',
            'jalan' => 'travel',
            'freight' => 'shipping',
            'delivery' => 'shipping',
            'shippingan' => 'shipping',
            'repair' => 'maintenance',
            'meals' => 'meal',
            'food' => 'meal',
            'entertainment' => 'meal',
            'electricity' => 'utilities',
            'water' => 'utilities',
            'communication' => 'utilities',
            'salaries' => 'wage',
            'salary' => 'wage',
            'overtime' => 'wage',
            'bonus' => 'wage',
            'thr' => 'wage',
            'incentive' => 'wage',
            'allowances' => 'wage',
            'benefit' => 'wage',
            'commission' => 'commission',
            'fees' => 'commission',
            'fee' => 'commission',
            'administration' => 'charge',
            'admin' => 'charge',
            'charges' => 'charge',
            'charge' => 'charge',
            'amortisation' => 'depreciation',
            'amortization' => 'depreciation',
            'license' => 'tax',
            'licenses' => 'tax',
            'taxes' => 'tax',
            'pph' => 'tax',
            'loan' => 'debt',
            'kasbon' => 'receivable',
            'miscellaneous' => 'other',
            'misc' => 'other',
            'donations' => 'other',
            'donation' => 'other',
            'securities' => 'other',
            'production' => 'cost',
            'hpp' => 'cost',
            'cos' => 'cost',
            'discounts' => 'discount',
            'souvenir' => 'advertising',
        ];

        return implode(' ', array_map(fn ($token) => $aliases[$token] ?? $token, $tokens));
    }

    private function normalizeExactAccountName(string $name): string
    {
        $name = mb_strtolower(str_replace('&', ' and ', trim($name)));
        $name = preg_replace('/[^\pL\pN]+/u', ' ', $name) ?: '';

        return trim(preg_replace('/\s+/u', ' ', $name) ?: '');
    }

    private function accountTypesForOperationalAccount(string $name): array
    {
        $normalized = $this->normalizeAccountName($name);
        if (preg_match('/\b(receivable|depreciation|cash|bank|giro|mandiri|petty)\b/', $normalized)) {
            return ['asset'];
        }
        if (preg_match('/\b(payable|debt|utang|hutang)\b/', $normalized)) {
            return ['liability'];
        }
        if (preg_match('/\b(expense|wage|discount|commission|charge)\b/', $normalized)) {
            return ['expenses'];
        }

        return ['asset', 'liability', 'expenses', 'income', 'equity'];
    }

    private function accountTypesForExpenseCategory(string $name): array
    {
        $normalized = $this->normalizeAccountName($name);
        if (preg_match('/\b(receivable)\b/', $normalized)) {
            return ['asset'];
        }
        if (preg_match('/\b(debt|loan)\b/', $normalized)) {
            return ['liability', 'expenses'];
        }

        return ['expenses'];
    }

    private function legacyVatPatternAccount(int $businessId, object $transaction, array $pair, string $direction): ?object
    {
        if (isset($pair['vat_accounts'][$direction])) {
            return $pair['vat_accounts'][$direction];
        }
        if (! $this->legacyRecheckMode) {
            return null;
        }

        $pattern = $this->journalPatternPair($businessId, $transaction);

        return $pattern['pair']['vat_accounts'][$direction] ?? null;
    }

    private function resolveLegacyVatAccount(int $businessId, string $direction, ?object $journalPatternAccount): array
    {
        $mappedAccountId = (int) ($this->vatAccountMappingsCache[$businessId][$direction] ?? 0);
        if ($mappedAccountId > 0 || $journalPatternAccount === null) {
            return $this->resolveVatAccount($businessId, $direction);
        }

        $account = collect($this->accountingAccountsCache[$businessId] ?? [])->firstWhere('id', (int) $journalPatternAccount->id);

        return $account ? ['account' => $account] : ['reason' => 'akun_vat_tidak_ditemukan'];
    }

    private function resolveVatAccount(int $businessId, string $direction): array
    {
        $mappedAccountId = (int) ($this->vatAccountMappingsCache[$businessId][$direction] ?? 0);
        if ($mappedAccountId > 0) {
            $mappedAccount = collect($this->accountingAccountsCache[$businessId] ?? [])->firstWhere('id', $mappedAccountId);

            return $mappedAccount ? ['account' => $mappedAccount] : ['reason' => 'akun_vat_tidak_ditemukan'];
        }

        $side = $direction === 'input' ? 'debit' : 'credit';
        $candidates = [];
        foreach ($this->accountingAccountsCache[$businessId] ?? [] as $account) {
            $namedDirection = $this->explicitVatDirection(
                (string) ($account->gl_code ?? ''),
                (string) $account->name
            );
            if ($namedDirection !== $direction) {
                continue;
            }

            $components = $this->accountComponentService->groupJournalLines([
                'lines' => [[
                    'account_no' => (string) ($account->gl_code ?? ''),
                    'account_name' => (string) $account->name,
                    'type' => $side,
                    'amount' => 1,
                ]],
            ], $businessId);
            $component = $components[0] ?? [];
            if (! empty($component['is_tax'])
                && ($component['tax_direction'] ?? null) === $direction
                && empty($component['tax_direction_conflict'])) {
                $candidates[] = $account;
            }
        }

        if (count($candidates) > 1) {
            return ['reason' => 'akun_vat_ambigu'];
        }
        if ($candidates === []) {
            return ['reason' => 'akun_vat_tidak_ditemukan'];
        }

        return ['account' => $candidates[0]];
    }

    private function explicitVatDirection(string $accountNo, string $accountName): ?string
    {
        $label = mb_strtolower(trim($accountNo.' '.$accountName));
        $taxTerm = '(?:vat|ppn|gst|pajak|tax)';
        if (preg_match('/\b'.$taxTerm.'\b.*\b(?:in|input|masukan)\b|\b(?:in|input|masukan)\b.*\b'.$taxTerm.'\b/u', $label) === 1) {
            return 'input';
        }
        if (preg_match('/\b'.$taxTerm.'\b.*\b(?:out|output|keluaran)\b|\b(?:out|output|keluaran)\b.*\b'.$taxTerm.'\b/u', $label) === 1) {
            return 'output';
        }

        return null;
    }

    private function mainAccountMapping(int $businessId, int $accountId, bool $allowNearest = true): array
    {
        $cacheKey = $businessId.':'.$accountId.':'.($allowNearest ? 'nearest' : 'strict');
        if (array_key_exists($cacheKey, $this->mainAccountCache)) {
            return $this->mainAccountCache[$cacheKey];
        }

        $mainAccount = DB::table('accounts')
            ->where('business_id', $businessId)
            ->where('id', $accountId)
            ->when(Schema::hasColumn('accounts', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->first(['id', 'name']);
        if (! $mainAccount) {
            return $this->mainAccountCache[$cacheKey] = ['reason' => 'akun_utama_tidak_ada_di_business'];
        }

        $accounts = $this->accountingAccountsCache[$businessId] ?? [];
        $direct = array_values(array_filter($accounts, fn ($account) => (int) ($account->account_id ?? 0) === $accountId));
        if (count($direct) > 1) {
            return $this->mainAccountCache[$cacheKey] = ['reason' => 'crosswalk_account_id_ambigu'];
        }
        if (count($direct) === 1) {
            return $this->mainAccountCache[$cacheKey] = [
                'account' => $direct[0],
                'crosswalk' => null,
                'method' => 'account_id',
            ];
        }

        $mainAccountName = (string) $mainAccount->name;
        $matches = array_values(array_filter($accounts, fn ($account) => (string) $account->name === $mainAccountName));
        if (count($matches) > 1) {
            return $this->mainAccountCache[$cacheKey] = ['reason' => 'nama_akun_utama_ambigu'];
        }
        if ($matches === []) {
            if (! $allowNearest) {
                return $this->mainAccountCache[$cacheKey] = ['reason' => 'akun_utama_belum_dimapping'];
            }
            $nearest = $this->nearestAccountingAccount(
                $businessId,
                $mainAccountName,
                $this->accountTypesForOperationalAccount($mainAccountName)
            );
            if (! empty($nearest['reason'])) {
                return $this->mainAccountCache[$cacheKey] = ['reason' => 'akun_utama_belum_dimapping'];
            }

            return $this->mainAccountCache[$cacheKey] = [
                'account' => $nearest['account'],
                'crosswalk' => null,
                'automatic_nearest' => true,
                'score' => $nearest['score'],
                'source_name' => $mainAccountName,
                'method' => $nearest['method'],
            ];
        }
        if (! empty($matches[0]->account_id) && (int) $matches[0]->account_id !== $accountId) {
            return $this->mainAccountCache[$cacheKey] = ['reason' => 'crosswalk_akun_bertentangan'];
        }

        $account = $matches[0];
        return $this->mainAccountCache[$cacheKey] = [
            'account' => $account,
            'crosswalk' => [
                'accounting_account_id' => (int) $account->id,
                'account_id' => $accountId,
            ],
            'method' => 'exact_name',
        ];
    }

    private function locationMap(int $businessId, int $locationId): array
    {
        return $this->locationMapsCache[$businessId][$locationId] ?? [];
    }

    private function isPostedTransaction(object $transaction): bool
    {
        $type = (string) ($transaction->type ?? '');
        $status = strtolower(trim((string) ($transaction->status ?? '')));

        if ($type === 'sell' || $type === 'expense') {
            return $status === 'final';
        }

        return $type === 'purchase' && $status === 'received';
    }

    private function skip(array &$summary, string $reason): void
    {
        $summary['skipped'][$reason] = ($summary['skipped'][$reason] ?? 0) + 1;
        if ($reason === 'akun_vat_ambigu') {
            $summary['ambiguous_vat_accounts']++;
        } elseif ($reason === 'akun_vat_tidak_ditemukan') {
            $summary['missing_vat_accounts']++;
        }
    }
}
