<?php

namespace Modules\Accounting\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Services\ReconciliationFindingActionService;
use Modules\Accounting\Services\LegacyReconciliationFindingSnapshotService;
use Modules\Accounting\Services\ReconciliationFindingJournalService;
use Modules\Accounting\Services\LegacySourceReconciliationFindingService;

class ReconciliationFindingController extends Controller
{
    public function index(Request $request, ModuleUtil $moduleUtil, LegacyReconciliationFindingSnapshotService $findings, ReconciliationFindingJournalService $journalActions, LegacySourceReconciliationFindingService $sourceFindings)
    {
        $businessId = $this->authorizeReports($request, $moduleUtil);
        $filters = $this->filters($request);
        $rows = $findings->paginate($businessId, $filters, (int) $request->input('page', 1));
        $summary = $findings->summary($businessId, $filters);
        $years = $findings->years($businessId);
        $roundingAccountId = $journalActions->roundingAccountId($businessId);
        $accountOptions = AccountingAccount::forDropdown($businessId)->map(function ($name, $id): array {
            return ['id' => (int) $id, 'name' => (string) $name];
        })->values()->all();
        $currencyPrecision = $journalActions->currencyPrecision($businessId);
        $canPostJournals = $request->user()->can('superadmin')
            || $request->user()->can('accounting.add_journal');
        $canManageRounding = $request->user()->can('superadmin') || $request->user()->can('accounting.manage_accounts');
        $sourceFilters = $this->sourceFilters($request);
        $sourceRows = $sourceFindings->paginate($businessId, $sourceFilters, (int) $request->input('source_page', 1));
        $sourceSummary = $sourceFindings->summary($businessId, $sourceFilters);
        $sourceYears = $sourceFindings->years($businessId);
        $sourceReasons = $sourceFindings->reasons($businessId);
        $sourceTypes = $sourceFindings->types($businessId);
        $canManageSources = $request->user()->can('superadmin') || $request->user()->can('accounting.manage_accounts');
        $linkedJournals = $journalActions->linkedJournals($businessId, collect($rows->items())->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return view('accounting::reconciliation.findings', compact(
            'rows', 'summary', 'years', 'filters', 'findings', 'roundingAccountId', 'accountOptions',
            'currencyPrecision', 'canPostJournals', 'canManageRounding', 'sourceRows', 'sourceSummary',
            'sourceFilters', 'sourceYears', 'sourceReasons', 'sourceTypes', 'sourceFindings', 'canManageSources'
        ));
    }

    public function data(Request $request, ModuleUtil $moduleUtil, LegacyReconciliationFindingSnapshotService $findings, ReconciliationFindingJournalService $journalActions)
    {
        $businessId = $this->authorizeReports($request, $moduleUtil);
        $filters = $this->filters($request);
        $rows = $findings->paginate($businessId, $filters, (int) $request->input('page', 1));
        $summary = $findings->summary($businessId, $filters);
        $findingIds = collect($rows->items())->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $linkedJournals = $journalActions->linkedJournals($businessId, $findingIds);
        $roundingAccountId = $journalActions->roundingAccountId($businessId);
        $currencyPrecision = $journalActions->currencyPrecision($businessId);
        $canPostJournals = $request->user()->can('superadmin')
            || $request->user()->can('accounting.add_journal');

        return response()->json([
            'rows_html' => view('accounting::reconciliation.partials.rows', compact(
                'rows', 'findings', 'linkedJournals', 'roundingAccountId', 'currencyPrecision', 'canPostJournals'
            ))->render(),
            'summary_html' => view('accounting::reconciliation.partials.summary', compact('summary'))->render(),
            'pagination_html' => view('accounting::reconciliation.partials.pagination', compact('rows'))->render(),
            'filtered_count' => $summary['filtered_count'],
            'open_count' => $summary['open_count'],
            'last_audited_at' => $summary['last_audited_at'],
        ]);
    }

    public function sourceData(Request $request, ModuleUtil $moduleUtil, LegacySourceReconciliationFindingService $sourceFindings)
    {
        $businessId = $this->authorizeReports($request, $moduleUtil);
        $filters = $this->sourceFilters($request);
        $rows = $sourceFindings->paginate($businessId, $filters, (int) $request->input('source_page', 1));
        $summary = $sourceFindings->summary($businessId, $filters);

        return response()->json([
            'rows_html' => view('accounting::reconciliation.partials.source_rows', [
                'rows' => $rows,
                'canManageSources' => $request->user()->can('superadmin') || $request->user()->can('accounting.manage_accounts'),
            ])->render(),
            'pagination_html' => view('accounting::reconciliation.partials.source_pagination', compact('rows'))->render(),
            'open_count' => $summary['open_count'],
            'filtered_count' => $summary['filtered_count'],
            'last_seen_at' => $summary['last_seen_at'],
        ]);
    }

    public function linkSource(Request $request, ModuleUtil $moduleUtil, LegacySourceReconciliationFindingService $sourceFindings, int $findingId)
    {
        $businessId = $this->authorizeMutation($request, $moduleUtil);
        $validated = $request->validate([
            'transaction_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $result = $sourceFindings->linkExisting(
            $businessId,
            $findingId,
            (int) $validated['transaction_id'],
            (int) $request->user()->id,
            trim($validated['reason'])
        );

        return response()->json($result);
    }

    public function retrySource(Request $request, ModuleUtil $moduleUtil, LegacySourceReconciliationFindingService $sourceFindings, int $findingId)
    {
        $businessId = $this->authorizeMutation($request, $moduleUtil);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $result = $sourceFindings->retry(
            $businessId,
            $findingId,
            (int) $request->user()->id,
            trim($validated['reason'])
        );

        return response()->json($result);
    }

    public function correctionPreview(Request $request, ModuleUtil $moduleUtil, ReconciliationFindingActionService $actions, int $findingId)
    {
        $businessId = $this->authorizeMutation($request, $moduleUtil);
        $result = $actions->previewOperationalTaxCorrection($businessId, $findingId);
        $result['currency_symbol'] = (string) ($request->session()->get('currency')['symbol'] ?? '');
        $result['can_confirm'] = $request->user()->can('superadmin') || $request->user()->can('expense.edit');
        $sourceType = strtolower(trim((string) ($result['source_review']['source_type'] ?? '')));
        $canEditOperational = match ($sourceType) {
            'expense' => $request->user()->can('expense.edit'),
            'purchase', 'purchase invoice' => $request->user()->can('purchase.update'),
            'sell', 'sales invoice' => $request->user()->can('direct_sell.update') || $request->user()->can('so.update'),
            default => false,
        };
        $result['source_review']['can_edit_operational'] = $canEditOperational;
        if (! $canEditOperational && isset($result['source_review']['operational'])) {
            $result['source_review']['operational']['edit_url'] = null;
        }
        $canEditJournal = $request->user()->can('accounting.edit_journal');
        $result['source_review']['can_edit_journal'] = $canEditJournal;
        if (! $canEditJournal) {
            foreach ($result['source_review']['journals'] ?? [] as &$journal) {
                $journal['edit_url'] = null;
            }
            unset($journal);
        }

        return response()->json($result);
    }

    public function correctionConfirm(Request $request, ModuleUtil $moduleUtil, ReconciliationFindingActionService $actions, int $findingId)
    {
        $businessId = $this->authorizeMutation($request, $moduleUtil, 'expense.edit');
        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $result = $actions->confirmOperationalTaxCorrection(
            $businessId,
            $findingId,
            (int) $request->user()->id,
            $validated['fingerprint']
        );

        return response()->json($result);
    }

    public function accept(Request $request, ModuleUtil $moduleUtil, ReconciliationFindingActionService $actions, int $findingId)
    {
        $businessId = $this->authorizeMutation($request, $moduleUtil);
        $request->merge(['reason' => trim((string) $request->input('reason', ''))]);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ]);
        $result = $actions->accept($businessId, $findingId, (int) $request->user()->id, $validated['reason']);

        return response()->json($result);
    }

    public function saveRoundingAccount(Request $request, ModuleUtil $moduleUtil, ReconciliationFindingJournalService $actions)
    {
        $businessId = $this->authorizeMutation($request, $moduleUtil);
        $validated = $request->validate([
            'accounting_account_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $accountId = empty($validated['accounting_account_id']) ? null : (int) $validated['accounting_account_id'];
        $savedId = $actions->saveRoundingAccount($businessId, $accountId, (int) $request->user()->id);
        $name = $savedId === null ? null : AccountingAccount::where('business_id', $businessId)->where('id', $savedId)->value('name');

        return response()->json([
            'success' => true,
            'accounting_account_id' => $savedId,
            'account_name' => $name,
            'message' => $savedId === null ? 'Mapping akun pembulatan dihapus.' : 'Akun pembulatan business disimpan.',
        ]);
    }

    public function adjustmentPreview(Request $request, ModuleUtil $moduleUtil, ReconciliationFindingJournalService $actions, int $findingId)
    {
        $businessId = $this->authorizeJournalMutation($request, $moduleUtil);
        $validated = $this->validateAdjustmentRequest($request);
        $result = $actions->preview(
            $businessId,
            $findingId,
            (int) $request->user()->id,
            $validated['action'],
            $validated['reason'],
            $validated['lines']
        );
        $result['currency_symbol'] = (string) ($request->session()->get('currency')['symbol'] ?? '');

        return response()->json($result);
    }

    public function adjustmentConfirm(Request $request, ModuleUtil $moduleUtil, ReconciliationFindingJournalService $actions, int $findingId)
    {
        $businessId = $this->authorizeJournalMutation($request, $moduleUtil);
        $validated = $this->validateAdjustmentRequest($request);
        $validated['fingerprint'] = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ])['fingerprint'];
        $request->validate(['confirmation_ack' => ['required', 'accepted']]);
        $result = $actions->confirm(
            $businessId,
            $findingId,
            (int) $request->user()->id,
            $validated['action'],
            $validated['reason'],
            $validated['lines'],
            $validated['fingerprint']
        );

        return response()->json($result);
    }

    private function validateAdjustmentRequest(Request $request): array
    {
        $request->merge(['reason' => trim((string) $request->input('reason', ''))]);

        return $request->validate([
            'action' => ['required', 'in:adjustment,rounding'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.accounting_account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function authorizeJournalMutation(Request $request, ModuleUtil $moduleUtil): int
    {
        $businessId = $this->authorizeReports($request, $moduleUtil);
        $user = $request->user();
        if (! $user->can('superadmin') && ! $user->can('accounting.add_journal')) {
            abort(403, 'Unauthorized action.');
        }

        return $businessId;
    }

    private function authorizeReports(Request $request, ModuleUtil $moduleUtil): int
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $user = $request->user();
        if ($businessId < 1 || ! $user || ! ($user->can('superadmin')
            || $moduleUtil->hasThePermissionInSubscription($businessId, 'accounting_module'))
            || ! $user->can('accounting.view_reports')) {
            abort(403, 'Unauthorized action.');
        }

        return $businessId;
    }

    private function authorizeMutation(Request $request, ModuleUtil $moduleUtil, ?string $sourcePermission = null): int
    {
        $businessId = $this->authorizeReports($request, $moduleUtil);
        $user = $request->user();
        $isSuperadmin = $user && $user->can('superadmin');
        if (! $isSuperadmin && ! $user->can('accounting.manage_accounts')) {
            abort(403, 'Unauthorized action.');
        }
        if ($sourcePermission !== null && ! $isSuperadmin && ! $user->can($sourcePermission)) {
            abort(403, 'Unauthorized action.');
        }

        return $businessId;
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:1900|max:2200',
            'type' => 'nullable|in:transaction,control,account_balance,unbalanced_journal,cash_bank,loan_classification',
            'status' => 'nullable|in:open,accepted,resolved,all',
            'search' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
        ]);

        return [
            'year' => $validated['year'] ?? '',
            'type' => $validated['type'] ?? '',
            'status' => $validated['status'] ?? 'open',
            'search' => trim((string) ($validated['search'] ?? '')),
        ];
    }

    private function sourceFilters(Request $request): array
    {
        $validated = $request->validate([
            'source_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
            'source_type' => ['nullable', 'in:Sales Invoice,Purchase Invoice,Expense'],
            'source_status' => ['nullable', 'in:open,resolved,all'],
            'source_reason' => ['nullable', 'string', 'max:100'],
            'source_search' => ['nullable', 'string', 'max:120'],
            'source_page' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'year' => $validated['source_year'] ?? '',
            'source_type' => $validated['source_type'] ?? '',
            'status' => $validated['source_status'] ?? 'open',
            'reason' => $validated['source_reason'] ?? '',
            'search' => trim((string) ($validated['source_search'] ?? '')),
        ];
    }
}
