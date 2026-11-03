<?php

namespace Modules\Accounting\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Accounting\Services\ReconciliationFindingActionService;
use Modules\Accounting\Services\LegacyReconciliationFindingSnapshotService;

class ReconciliationFindingController extends Controller
{
    public function index(Request $request, ModuleUtil $moduleUtil, LegacyReconciliationFindingSnapshotService $findings)
    {
        $businessId = $this->authorizeReports($request, $moduleUtil);
        $filters = $this->filters($request);
        $rows = $findings->paginate($businessId, $filters, (int) $request->input('page', 1));
        $summary = $findings->summary($businessId, $filters);
        $years = $findings->years($businessId);

        return view('accounting::reconciliation.findings', compact('rows', 'summary', 'years', 'filters', 'findings'));
    }

    public function data(Request $request, ModuleUtil $moduleUtil, LegacyReconciliationFindingSnapshotService $findings)
    {
        $businessId = $this->authorizeReports($request, $moduleUtil);
        $filters = $this->filters($request);
        $rows = $findings->paginate($businessId, $filters, (int) $request->input('page', 1));
        $summary = $findings->summary($businessId, $filters);

        return response()->json([
            'rows_html' => view('accounting::reconciliation.partials.rows', compact('rows', 'findings'))->render(),
            'summary_html' => view('accounting::reconciliation.partials.summary', compact('summary'))->render(),
            'pagination_html' => view('accounting::reconciliation.partials.pagination', compact('rows'))->render(),
            'filtered_count' => $summary['filtered_count'],
            'open_count' => $summary['open_count'],
            'last_audited_at' => $summary['last_audited_at'],
        ]);
    }

    public function correctionPreview(Request $request, ModuleUtil $moduleUtil, ReconciliationFindingActionService $actions, int $findingId)
    {
        $businessId = $this->authorizeMutation($request, $moduleUtil);
        $result = $actions->previewOperationalTaxCorrection($businessId, $findingId);
        $result['currency_symbol'] = (string) ($request->session()->get('currency')['symbol'] ?? '');
        $result['can_confirm'] = $request->user()->can('superadmin') || $request->user()->can('expense.edit');
        $result['journal_url'] = \Illuminate\Support\Facades\Route::has('journal-entry.create')
            ? route('journal-entry.create')
            : null;

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
            'type' => 'nullable|in:transaction,control,account_balance,unbalanced_journal,cash_bank',
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
}
