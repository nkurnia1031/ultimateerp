<?php

namespace Modules\Accounting\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:1900|max:2200',
            'type' => 'nullable|in:transaction,control,account_balance,unbalanced_journal',
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
