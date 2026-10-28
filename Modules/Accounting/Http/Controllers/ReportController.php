<?php

namespace Modules\Accounting\Http\Controllers;

use App\BusinessLocation;
use App\Utils\BusinessUtil;
use App\Utils\ModuleUtil;
use DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Exports\GeneralLedgerExport;
use Modules\Accounting\Services\GeneralLedgerReportService;
use Modules\Accounting\Services\GeneralLedgerAuditService;
use Modules\Accounting\Utils\AccountingUtil;

class ReportController extends Controller
{
    protected $accountingUtil;

    protected $businessUtil;

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct(AccountingUtil $accountingUtil, BusinessUtil $businessUtil,
    ModuleUtil $moduleUtil)
    {
        $this->accountingUtil = $accountingUtil;
        $this->businessUtil = $businessUtil;
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        return view('accounting::report.index');
    }

    public function generalLedger(Request $request, GeneralLedgerReportService $generalLedgerReportService)
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $this->authorizeReports($businessId);
        $filters = $this->generalLedgerFilters($request, $businessId, $generalLedgerReportService);
        $report = $generalLedgerReportService->paginate(
            $businessId,
            $filters['start_date'],
            $filters['end_date'],
            $filters['account_id'],
            (int) ($request->input('page', 1))
        );
        $accounts = $generalLedgerReportService->mappedCashBankAccounts($businessId);
        $canManageAccounts = auth()->user()->can('accounting.manage_accounts');
        $mappingAccounts = $canManageAccounts
            ? $generalLedgerReportService->accountMappingReview($businessId, $filters['start_date'], $filters['end_date'])
            : collect();
        $reportRows = $report->getCollection();
        $businessName = session('business.name') ?: get_business_name();
        $currencyPrecision = max(0, min(4, (int) session('business.currency_precision', 2)));
        $currency = session('currency', []);
        $decimalSeparator = $currency['decimal_separator'] ?? '.';
        $thousandSeparator = $currency['thousand_separator'] ?? ',';

        return view('accounting::report.general_ledger', compact(
            'accounts', 'mappingAccounts', 'canManageAccounts', 'report', 'reportRows', 'businessName', 'currencyPrecision',
            'decimalSeparator', 'thousandSeparator'
        ) + $filters);
    }

    public function saveGeneralLedgerAccountMappings(Request $request, GeneralLedgerReportService $generalLedgerReportService)
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $this->authorizeManageAccounts($businessId);
        $validated = $request->validate([
            'mappings' => 'required|array',
            'mappings.*' => 'nullable|in:cash,bank,excluded',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $saved = $generalLedgerReportService->saveCashBankAccountMappings(
            $businessId,
            (int) $request->user()->id,
            $validated['mappings']
        );

        $filters = array_filter([
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return redirect()->route('accounting.generalLedger', $filters)
            ->with('status', $saved > 0 ? 'Pemetaan akun kas/bank berhasil disimpan.' : 'Tidak ada perubahan pemetaan akun.');
    }

    public function exportGeneralLedger(Request $request, GeneralLedgerReportService $generalLedgerReportService)
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $this->authorizeReports($businessId);
        $filters = $this->generalLedgerFilters($request, $businessId, $generalLedgerReportService);
        $reportRows = $generalLedgerReportService->all(
            $businessId,
            $filters['start_date'],
            $filters['end_date'],
            $filters['account_id']
        );
        $businessName = session('business.name') ?: get_business_name();
        $currencyPrecision = max(0, min(4, (int) session('business.currency_precision', 2)));
        $currency = session('currency', []);
        $decimalSeparator = $currency['decimal_separator'] ?? '.';
        $thousandSeparator = $currency['thousand_separator'] ?? ',';
        $view = view('accounting::report.general_ledger_export', compact(
            'reportRows', 'businessName', 'currencyPrecision', 'decimalSeparator', 'thousandSeparator'
        ) + $filters);
        $filename = 'buku-besar-'.$filters['start_date'].'-'.$filters['end_date'].'.xlsx';

        return Excel::download(new GeneralLedgerExport($view), $filename);
    }

    public function generalLedgerAudit(Request $request, GeneralLedgerAuditService $auditService)
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $this->authorizeReports($businessId);
        $filters = $this->generalLedgerAuditFilters($request, $businessId, $auditService);
        $report = $auditService->report($businessId, $filters['start_date'], $filters['end_date'], $filters, (int) ($filters['page'] ?? 1));
        $currency = session('currency', []);

        return view('accounting::report.general_ledger_audit', $report + $filters + [
            'businessName' => session('business.name') ?: get_business_name(),
            'currencyPrecision' => max(0, min(4, (int) session('business.currency_precision', 2))),
            'decimalSeparator' => $currency['decimal_separator'] ?? '.',
            'thousandSeparator' => $currency['thousand_separator'] ?? ',',
        ]);
    }

    public function exportGeneralLedgerAudit(Request $request, GeneralLedgerAuditService $auditService)
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $this->authorizeReports($businessId);
        $filters = $this->generalLedgerAuditFilters($request, $businessId, $auditService);
        $rows = $auditService->exportRows($businessId, $filters['start_date'], $filters['end_date'], $filters);
        $filename = 'audit-buku-besar-'.$filters['start_date'].'-'.$filters['end_date'].'.csv';

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Tanggal', 'Tipe sumber', 'Nomor / referensi', 'Keterangan', 'Kategori sumber', 'Detail jurnal',
                'Supplier / Customer', 'Akun Kas/Bank', 'Nominal operasional', 'Nominal Accounting', 'Selisih',
                'VAT sumber', 'VAT jurnal', 'Status', 'Alasan', 'Source type', 'Source ID',
            ]);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['date'], $this->safeCsvText($row['source_type']), $this->safeCsvText($row['source_number']),
                    $this->safeCsvText($row['description']), $this->safeCsvText($row['category']),
                    $this->safeCsvText(trim($row['detail_category'].' '.$row['journal_detail'])),
                    $this->safeCsvText($row['contact']), $this->safeCsvText($row['payment_account']),
                    $row['operational_amount'], $row['accounting_amount'], $row['difference'], $row['source_vat'],
                    $row['journal_vat'], $this->safeCsvText($row['status']), $this->safeCsvText($row['reason']),
                    $this->safeCsvText($row['source_record_type']), $row['source_record_id'],
                ]);
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeCsvText($value): string
    {
        $text = (string) $value;
        if (preg_match('/^[\x00-\x20]*[=+@-]/u', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }

    private function generalLedgerAuditFilters(Request $request, int $businessId, GeneralLedgerAuditService $auditService): array
    {
        $validated = $request->validate([
            'start_date' => 'nullable|required_with:end_date|date_format:Y-m-d',
            'end_date' => 'nullable|required_with:start_date|date_format:Y-m-d|after_or_equal:start_date',
            'account_id' => 'nullable|integer|min:1',
            'source_type' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:50',
            'reason' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
        ]);

        if (empty($validated['start_date']) && empty($validated['end_date'])) {
            $financialYear = $this->businessUtil->getCurrentFinancialYear($businessId);
            $startDate = $financialYear['start'];
            $endDate = $financialYear['end'];
        } else {
            $startDate = $validated['start_date'];
            $endDate = $validated['end_date'];
        }

        $accountId = empty($validated['account_id']) ? null : (int) $validated['account_id'];
        if ($accountId !== null && ! $auditService->paymentAccounts($businessId)->contains(function ($account) use ($accountId) {
            return (int) $account->id === $accountId;
        })) {
            abort(404);
        }

        $validated['start_date'] = $startDate;
        $validated['end_date'] = $endDate;
        $validated['account_id'] = $accountId;

        return $validated;
    }

    private function authorizeReports(int $businessId): void
    {
        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($businessId, 'accounting_module')) ||
            ! auth()->user()->can('accounting.view_reports')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function authorizeManageAccounts(int $businessId): void
    {
        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($businessId, 'accounting_module')) ||
            ! auth()->user()->can('accounting.manage_accounts')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function generalLedgerFilters(Request $request, int $businessId, GeneralLedgerReportService $generalLedgerReportService): array
    {
        $validated = $request->validate([
            'start_date' => 'nullable|required_with:end_date|date_format:Y-m-d',
            'end_date' => 'nullable|required_with:start_date|date_format:Y-m-d|after_or_equal:start_date',
            'account_id' => 'nullable|integer|min:1',
        ]);

        if (empty($validated['start_date']) && empty($validated['end_date'])) {
            $financialYear = $this->businessUtil->getCurrentFinancialYear($businessId);
            $startDate = $financialYear['start'];
            $endDate = $financialYear['end'];
        } else {
            $startDate = $validated['start_date'];
            $endDate = $validated['end_date'];
        }

        $accountId = empty($validated['account_id']) ? null : (int) $validated['account_id'];
        $mappedAccount = $accountId === null
            ? true
            : $generalLedgerReportService->mappedCashBankAccounts($businessId)->contains(function ($account) use ($accountId) {
                return (int) $account->id === $accountId;
            });
        if (! $mappedAccount) {
            abort(404);
        }

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'account_id' => $accountId,
        ];
    }

    /**
     * Trial Balance
     *
     * @return Response
     */
    public function trialBalance()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        if (! empty(request()->start_date) && ! empty(request()->end_date)) {
            $start_date = request()->start_date;
            $end_date = request()->end_date;
        } else {
            $fy = $this->businessUtil->getCurrentFinancialYear($business_id);
            $start_date = $fy['start'];
            $end_date = $fy['end'];
        }

        $accounts = AccountingAccount::join('accounting_accounts_transactions as AAT',
                                'AAT.accounting_account_id', '=', 'accounting_accounts.id')
                            ->where('business_id', $business_id)
                            ->whereDate('AAT.operation_date', '>=', $start_date)
                            ->whereDate('AAT.operation_date', '<=', $end_date)
                            ->select(
                                DB::raw("SUM(IF(AAT.type = 'credit', AAT.amount, 0)) as credit_balance"),
                                DB::raw("SUM(IF(AAT.type = 'debit', AAT.amount, 0)) as debit_balance"),
                                'accounting_accounts.name'
                            )
                            ->groupBy('accounting_accounts.name')
                            ->get();

        return view('accounting::report.trial_balance')
            ->with(compact('accounts', 'start_date', 'end_date'));
    }

    /**
     * Trial Balance
     *
     * @return Response
     */
    public function balanceSheet()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        if (! empty(request()->start_date) && ! empty(request()->end_date)) {
            $start_date = request()->start_date;
            $end_date = request()->end_date;
        } else {
            $fy = $this->businessUtil->getCurrentFinancialYear($business_id);
            $start_date = $fy['start'];
            $end_date = $fy['end'];
        }

        $balance_formula = $this->accountingUtil->balanceFormula();

        $assets = AccountingAccount::join('accounting_accounts_transactions as AAT',
                                'AAT.accounting_account_id', '=', 'accounting_accounts.id')
                    ->join('accounting_account_types as AATP',
                                'AATP.id', '=', 'accounting_accounts.account_sub_type_id')
                    ->whereDate('AAT.operation_date', '>=', $start_date)
                    ->whereDate('AAT.operation_date', '<=', $end_date)
                    ->select(DB::raw($balance_formula), 'accounting_accounts.name', 'AATP.name as sub_type')
                    ->where('accounting_accounts.business_id', $business_id)
                    ->whereIn('accounting_accounts.account_primary_type', ['asset'])
                    ->groupBy('accounting_accounts.name')
                    ->get();

        $liabilities = AccountingAccount::join('accounting_accounts_transactions as AAT',
                                'AAT.accounting_account_id', '=', 'accounting_accounts.id')
                    ->join('accounting_account_types as AATP',
                                'AATP.id', '=', 'accounting_accounts.account_sub_type_id')
                    ->whereDate('AAT.operation_date', '>=', $start_date)
                    ->whereDate('AAT.operation_date', '<=', $end_date)
                    ->select(DB::raw($balance_formula), 'accounting_accounts.name', 'AATP.name as sub_type')
                    ->where('accounting_accounts.business_id', $business_id)
                    ->whereIn('accounting_accounts.account_primary_type', ['liability'])
                    ->groupBy('accounting_accounts.name')
                    ->get();

        $equities = AccountingAccount::join('accounting_accounts_transactions as AAT',
                                'AAT.accounting_account_id', '=', 'accounting_accounts.id')
                    ->join('accounting_account_types as AATP',
                                'AATP.id', '=', 'accounting_accounts.account_sub_type_id')
                    ->whereDate('AAT.operation_date', '>=', $start_date)
                    ->whereDate('AAT.operation_date', '<=', $end_date)
                    ->select(DB::raw($balance_formula), 'accounting_accounts.name', 'AATP.name as sub_type')
                    ->where('accounting_accounts.business_id', $business_id)
                    ->whereIn('accounting_accounts.account_primary_type', ['equity'])
                    ->groupBy('accounting_accounts.name')
                    ->get();

        return view('accounting::report.balance_sheet')
            ->with(compact('assets', 'liabilities', 'equities', 'start_date', 'end_date'));
    }

    public function accountReceivableAgeingReport()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        $location_id = request()->input('location_id', null);

        $report_details = $this->accountingUtil->getAgeingReport($business_id, 'sell', 'contact', $location_id);

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.account_receivable_ageing_report')
        ->with(compact('report_details', 'business_locations'));
    }

    public function accountPayableAgeingReport()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        $location_id = request()->input('location_id', null);
        $report_details = $this->accountingUtil->getAgeingReport($business_id, 'purchase', 'contact',
        $location_id);
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.account_payable_ageing_report')
        ->with(compact('report_details', 'business_locations'));
    }

    public function accountReceivableAgeingDetails()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        $location_id = request()->input('location_id', null);

        $report_details = $this->accountingUtil->getAgeingReport($business_id, 'sell', 'due_date',
        $location_id);

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.account_receivable_ageing_details')
        ->with(compact('business_locations', 'report_details'));
    }

    public function accountPayableAgeingDetails()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_reports'))) {
            abort(403, 'Unauthorized action.');
        }

        $location_id = request()->input('location_id', null);

        $report_details = $this->accountingUtil->getAgeingReport($business_id, 'purchase', 'due_date',
        $location_id);

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('accounting::report.account_payable_ageing_details')
        ->with(compact('business_locations', 'report_details'));
    }
}
