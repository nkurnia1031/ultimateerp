<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Keep legacy report links working while sending users to reports backed by
// the canonical accounting ledger. Reports that no longer have a canonical
// equivalent return to the Accounting reports index instead of querying the
// retired journal tables.
Route::middleware('SetSessionData', 'auth', 'language', 'timezone', 'AdminSidebarMenu')
    ->prefix('report/accounting')
    ->group(function () {
        Route::get('/', fn () => redirect('/accounting/reports'));

        Route::get('{legacyReport}', function ($legacyReport) {
            $routeName = [
                'ledger' => 'accounting.generalLedger',
                'trial_balance' => 'accounting.trialBalance',
                'balance_sheet' => 'accounting.balanceSheet',
                'accounts_receivable_ageing_summary' => 'accounting.account_receivable_ageing_report',
                'accounts_receivable_ageing_detail' => 'accounting.account_receivable_ageing_details',
                'accounts_payable_ageing_summary' => 'accounting.account_payable_ageing_report',
                'accounts_payable_ageing_detail' => 'accounting.account_payable_ageing_details',
            ][$legacyReport] ?? null;

            return $routeName
                ? redirect()->route($routeName, request()->query())
                : redirect('/accounting/reports'.(request()->getQueryString() ? '?'.request()->getQueryString() : ''));
        })->where('legacyReport', 'ledger|trial_balance|balance_sheet|cash_flow|profit_and_loss|journal|budget_overview|accounts_receivable_ageing_summary|accounts_receivable_ageing_detail|accounts_payable_ageing_summary|accounts_payable_ageing_detail');
    });

Route::middleware('web', 'SetSessionData', 'auth', 'language', 'timezone', 'AdminSidebarMenu')->prefix('accounting')->group(function () {
    Route::get('dashboard', [\Modules\Accounting\Http\Controllers\AccountingController::class, 'dashboard']);

    Route::get('accounts-dropdown', [\Modules\Accounting\Http\Controllers\AccountingController::class, 'AccountsDropdown'])->name('accounts-dropdown');

    Route::get('get-account-sub-types', [\Modules\Accounting\Http\Controllers\CoaController::class, 'getAccountSubTypes']);
    Route::get('get-account-details-types', [\Modules\Accounting\Http\Controllers\CoaController::class, 'getAccountDetailsType']);
    Route::get('chart-of-accounts/export', [\Modules\Accounting\Http\Controllers\CoaController::class, 'export'])
        ->name('accounting.chartOfAccounts.export');
    Route::resource('chart-of-accounts', \Modules\Accounting\Http\Controllers\CoaController::class)->except(['show']);
    Route::get('chart-of-accounts/{id}', fn ($id) => redirect()->route('accounting.ledger', array_merge(request()->query(), ['id' => $id])))->whereNumber('id')
        ->name('chart-of-accounts.show');
    Route::get('ledger/{id}', [\Modules\Accounting\Http\Controllers\CoaController::class, 'ledger'])->name('accounting.ledger');
    Route::get('activate-deactivate/{id}', [\Modules\Accounting\Http\Controllers\CoaController::class, 'activateDeactivate']);
    Route::get('create-default-accounts', [\Modules\Accounting\Http\Controllers\CoaController::class, 'createDefaultAccounts'])->name('accounting.create-default-accounts');

    Route::get('journal-entry/import', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'import'])->name('journal-entry.import');
    Route::post('journal-entry/import/analyze', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'analyzeImport']);
    Route::get('journal-entry/import/history', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'importHistoryData']);
    Route::get('journal-entry/import/history/{batchId}', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'importHistoryDetail'])->whereNumber('batchId');
    Route::get('journal-entry/import/history/{batchId}/download', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'downloadImportSource'])->whereNumber('batchId');
    Route::get('journal-entry/import/{id}/review', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'reviewImport']);
    Route::get('journal-entry/import/{id}/status', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'importStatus']);
    Route::post('journal-entry/import/{id}/terminate', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'terminateImport']);
    Route::get('journal-entry/import/{id}/review-groups', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'reviewGroups']);
    Route::post('journal-entry/import/{id}/review-groups/resolve', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'resolveReviewGroup']);
    Route::get('journal-entry/import/{id}/draft-rows', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'draftRows']);
    Route::get('journal-entry/import/{id}/source-groups/preview', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'previewSourceGroups']);
    Route::post('journal-entry/import/{id}/source-groups/confirm', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'confirmSourceGroups']);
    Route::get('journal-entry/import/{id}/cohort-mappings/preview', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'previewCohortMappings']);
    Route::post('journal-entry/import/{id}/cohort-mappings/apply', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'applyCohortMappings']);
    Route::post('journal-entry/import/{id}/draft-rows/{row_id}/source-group', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'selectSourceGroup']);
    Route::patch('journal-entry/import/{id}/draft-rows/approve-differences', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'approveDraftDiscrepancies']);
    Route::patch('journal-entry/import/{id}/draft-rows/{row_id}', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'updateDraftRow']);
    Route::patch('journal-entry/import/{id}/draft-rows/{row_id}/approve-difference', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'approveDraftDiscrepancy']);
    Route::get('journal-entry/import/{id}/draft-options', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'draftOptions']);
    Route::post('journal-entry/import/start', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'startImport']);
    Route::post('journal-entry/import/process', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'processImport']);
    Route::post('journal-entry/import', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'storeImport']);
    Route::post('journal-entry/import/{id}/revert', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'revertImport']);

    Route::resource('journal-entry', \Modules\Accounting\Http\Controllers\JournalEntryController::class)->except(['show']);
    Route::get('journal-entry/{id}', fn () => redirect()->route('journal-entry.index', request()->query()))
        ->whereNumber('id')
        ->name('journal-entry.show');

    Route::get('journal_entry/import/{legacyPath?}', function ($legacyPath = null) {
        $target = url('accounting/journal-entry/import'.($legacyPath ? '/'.$legacyPath : ''));
        $query = request()->getQueryString();

        return redirect()->to($target.($query ? '?'.$query : ''));
    })->where('legacyPath', '.*')->name('journal-entry.legacyImportRedirect');
    Route::get('journal_entry/get_journal_entries', fn () => redirect()->route('journal-entry.index', request()->query()))
        ->name('journal-entry.legacyRowsRedirect');
    Route::get('journal_entry/{id}/show', fn () => redirect()->route('journal-entry.index', request()->query()))
        ->whereNumber('id')->name('journal-entry.legacyShowRedirect');
    Route::get('journal_entry/{id}/reverse', fn () => abort(410))
        ->whereNumber('id')->name('journal-entry.legacyReverse');
    Route::get('journal_entry/{id}/destroy', fn () => abort(410))
        ->whereNumber('id')->name('journal-entry.legacyDestroy');
    Route::get('journal_entry/{legacyPath?}', function ($legacyPath = null) {
        $target = url('accounting/journal-entry'.($legacyPath ? '/'.$legacyPath : ''));
        $query = request()->getQueryString();

        return redirect()->to($target.($query ? '?'.$query : ''));
    })
        ->where('legacyPath', '.*')
        ->name('journal-entry.legacyUnderscoreRedirect');
    Route::match(['post', 'put', 'patch', 'delete'], 'journal_entry/{legacyPath?}', fn () => abort(410))
        ->where('legacyPath', '.*')
        ->name('journal-entry.legacyUnderscoreMutation');

    Route::get('chart_of_account/create', fn () => redirect()->action([\Modules\Accounting\Http\Controllers\CoaController::class, 'create']))
        ->name('chart-of-accounts.legacyCreateRedirect');
    Route::get('chart_of_account/{id}/show', fn () => redirect()->action([\Modules\Accounting\Http\Controllers\CoaController::class, 'index']))->whereNumber('id')
        ->name('chart-of-accounts.legacyShowRedirect');
    Route::get('chart_of_account/{id}/edit', fn () => redirect()->action([\Modules\Accounting\Http\Controllers\CoaController::class, 'index']))->whereNumber('id')
        ->name('chart-of-accounts.legacyEditRedirect');
    Route::get('chart_of_account/{id}/destroy', fn () => abort(410))->whereNumber('id')
        ->name('chart-of-accounts.legacyDestroy');
    Route::get('chart_of_account/export', fn () => abort(410))->name('chart-of-accounts.legacyExport');
    Route::get('chart_of_account/get_chart_of_accounts', fn () => abort(410))->name('chart-of-accounts.legacyRows');
    Route::get('chart_of_account', fn () => redirect()->action([\Modules\Accounting\Http\Controllers\CoaController::class, 'index']))
        ->name('chart-of-accounts.legacyRedirect');
    Route::match(['post', 'put', 'patch', 'delete'], 'chart_of_account/{legacyPath?}', fn () => abort(410))
        ->where('legacyPath', '.*')
        ->name('chart-of-accounts.legacyMutation');

    Route::get('dashboard/get_totals', fn () => abort(410))->name('dashboard.legacyTotals');

    Route::get('reconcile/{legacyPath?}', fn () => redirect()->route('accounting.reconciliation.findings'))
        ->where('legacyPath', '.*')
        ->name('reconciliation.legacyRedirect');
    Route::match(['post', 'put', 'patch', 'delete'], 'reconcile/{legacyPath?}', fn () => abort(410))
        ->where('legacyPath', '.*')
        ->name('reconciliation.legacyMutation');

    Route::get('transfers/{legacyPath?}', function ($legacyPath = null) {
        if ($legacyPath === 'create') {
            return redirect()->action([\Modules\Accounting\Http\Controllers\TransferController::class, 'create']);
        }

        return redirect()->action([\Modules\Accounting\Http\Controllers\TransferController::class, 'index']);
    })->where('legacyPath', '.*')->name('transfer.legacyRedirect');
    Route::match(['post', 'put', 'patch', 'delete'], 'transfers/{legacyPath?}', fn () => abort(410))
        ->where('legacyPath', '.*')
        ->name('transfer.legacyMutation');

    Route::get('transactions/sales', fn () => redirect()->action([\Modules\Accounting\Http\Controllers\TransactionController::class, 'index']))
        ->name('transactions.legacySalesRedirect');
    Route::get('transactions/expenses', fn () => redirect()->action([\Modules\Accounting\Http\Controllers\TransactionController::class, 'index']))
        ->name('transactions.legacyExpensesRedirect');
    Route::get('transactions/purchases', fn () => redirect()->action([\Modules\Accounting\Http\Controllers\TransactionController::class, 'index']))
        ->name('transactions.legacyPurchasesRedirect');
    Route::post('transactions/map_to_chart_of_account', fn () => abort(410))
        ->name('transactions.legacyMappingMutation');

    Route::get('settings', [\Modules\Accounting\Http\Controllers\SettingsController::class, 'index']);
    Route::get('reset-data', [\Modules\Accounting\Http\Controllers\SettingsController::class, 'resetData']);

    Route::resource('account-type', \Modules\Accounting\Http\Controllers\AccountTypeController::class);

    Route::resource('transfer', \Modules\Accounting\Http\Controllers\TransferController::class)->except(['show']);

    Route::resource('budget', \Modules\Accounting\Http\Controllers\BudgetController::class)->except(['show', 'edit', 'update', 'destroy']);

    Route::get('reports', [\Modules\Accounting\Http\Controllers\ReportController::class, 'index']);
    Route::get('reports/reconciliation-findings/data', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'data'])->name('accounting.reconciliation.findings.data');
    Route::get('reports/reconciliation-findings/source-data', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'sourceData'])->name('accounting.reconciliation.findings.sourceData');
    Route::get('reports/reconciliation-findings', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'index'])->name('accounting.reconciliation.findings');
    Route::post('reports/reconciliation-findings/{findingId}/source/link', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'linkSource'])->whereNumber('findingId')->name('accounting.reconciliation.findings.sourceLink');
    Route::post('reports/reconciliation-findings/{findingId}/source/retry', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'retrySource'])->whereNumber('findingId')->name('accounting.reconciliation.findings.sourceRetry');
    Route::post('reports/reconciliation-findings/{findingId}/operational-tax/preview', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'correctionPreview'])->whereNumber('findingId')->name('accounting.reconciliation.findings.correctionPreview');
    Route::post('reports/reconciliation-findings/{findingId}/operational-tax/confirm', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'correctionConfirm'])->whereNumber('findingId')->name('accounting.reconciliation.findings.correctionConfirm');
    Route::post('reports/reconciliation-findings/{findingId}/accept', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'accept'])->whereNumber('findingId')->name('accounting.reconciliation.findings.accept');
    Route::post('reports/reconciliation-findings/rounding-account', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'saveRoundingAccount'])->name('accounting.reconciliation.findings.roundingAccount');
    Route::post('reports/reconciliation-findings/{findingId}/adjustment/preview', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'adjustmentPreview'])->whereNumber('findingId')->name('accounting.reconciliation.findings.adjustmentPreview');
    Route::post('reports/reconciliation-findings/{findingId}/adjustment/confirm', [\Modules\Accounting\Http\Controllers\ReconciliationFindingController::class, 'adjustmentConfirm'])->whereNumber('findingId')->name('accounting.reconciliation.findings.adjustmentConfirm');
    Route::get('reports/general-ledger/export', [\Modules\Accounting\Http\Controllers\ReportController::class, 'exportGeneralLedger'])->name('accounting.generalLedger.export');
    Route::get('reports/general-ledger/audit/export', [\Modules\Accounting\Http\Controllers\ReportController::class, 'exportGeneralLedgerAudit'])->name('accounting.generalLedger.audit.export');
    Route::get('reports/general-ledger/audit', [\Modules\Accounting\Http\Controllers\ReportController::class, 'generalLedgerAudit'])->name('accounting.generalLedger.audit');
    Route::post('reports/general-ledger/account-mappings', [\Modules\Accounting\Http\Controllers\ReportController::class, 'saveGeneralLedgerAccountMappings'])->name('accounting.generalLedger.accountMappings.save');
    Route::get('reports/general-ledger', [\Modules\Accounting\Http\Controllers\ReportController::class, 'generalLedger'])->name('accounting.generalLedger');
    Route::get('reports/general-ledger/backfill-review', [\Modules\Accounting\Http\Controllers\GeneralLedgerBackfillReviewController::class, 'index'])->name('accounting.generalLedger.backfillReview');
    Route::get('reports/general-ledger/backfill-review/rows', [\Modules\Accounting\Http\Controllers\GeneralLedgerBackfillReviewController::class, 'rows'])->name('accounting.generalLedger.backfillReview.rows');
    Route::get('reports/general-ledger/backfill-review/options', [\Modules\Accounting\Http\Controllers\GeneralLedgerBackfillReviewController::class, 'options'])->name('accounting.generalLedger.backfillReview.options');
    Route::post('reports/general-ledger/backfill-review/mappings/location', [\Modules\Accounting\Http\Controllers\GeneralLedgerBackfillReviewController::class, 'saveLocationMapping'])->name('accounting.generalLedger.backfillReview.locationMapping');
    Route::post('reports/general-ledger/backfill-review/mappings/main-account', [\Modules\Accounting\Http\Controllers\GeneralLedgerBackfillReviewController::class, 'saveMainAccountMapping'])->name('accounting.generalLedger.backfillReview.mainAccountMapping');
    Route::post('reports/general-ledger/backfill-review/mappings/vat', [\Modules\Accounting\Http\Controllers\GeneralLedgerBackfillReviewController::class, 'saveVatMapping'])->name('accounting.generalLedger.backfillReview.vatMapping');
    Route::post('reports/general-ledger/backfill-review/retry-batches', [\Modules\Accounting\Http\Controllers\GeneralLedgerBackfillReviewController::class, 'retry'])->name('accounting.generalLedger.backfillReview.retry');
    Route::post('reports/general-ledger/backfill-review/retry-batches/{batchId}/confirm', [\Modules\Accounting\Http\Controllers\GeneralLedgerBackfillReviewController::class, 'confirmRetry'])->whereNumber('batchId')->name('accounting.generalLedger.backfillReview.confirmRetry');
    Route::get('reports/general-ledger/backfill-review/retry-batches/{batchId}', [\Modules\Accounting\Http\Controllers\GeneralLedgerBackfillReviewController::class, 'batchStatus'])->whereNumber('batchId')->name('accounting.generalLedger.backfillReview.retryBatches');
    Route::post('reports/general-ledger/backfill-review/{reviewId}/manual-journal', [\Modules\Accounting\Http\Controllers\GeneralLedgerBackfillReviewController::class, 'manualJournal'])->whereNumber('reviewId')->name('accounting.generalLedger.backfillReview.manualJournal');
    Route::get('reports/trial-balance', [\Modules\Accounting\Http\Controllers\ReportController::class, 'trialBalance'])->name('accounting.trialBalance');
    Route::get('reports/balance-sheet', [\Modules\Accounting\Http\Controllers\ReportController::class, 'balanceSheet'])->name('accounting.balanceSheet');
    Route::get('reports/account-receivable-ageing-report',
    [\Modules\Accounting\Http\Controllers\ReportController::class, 'accountReceivableAgeingReport'])->name('accounting.account_receivable_ageing_report');
    Route::get('reports/account-receivable-ageing-details',
    [\Modules\Accounting\Http\Controllers\ReportController::class, 'accountReceivableAgeingDetails'])->name('accounting.account_receivable_ageing_details');

    Route::get('reports/account-payable-ageing-report',
    [\Modules\Accounting\Http\Controllers\ReportController::class, 'accountPayableAgeingReport'])->name('accounting.account_payable_ageing_report');
    Route::get('reports/account-payable-ageing-details',
    [\Modules\Accounting\Http\Controllers\ReportController::class, 'accountPayableAgeingDetails'])->name('accounting.account_payable_ageing_details');

    Route::get('transactions', [\Modules\Accounting\Http\Controllers\TransactionController::class, 'index']);
    Route::get('transactions/map', [\Modules\Accounting\Http\Controllers\TransactionController::class, 'map']);
    Route::post('transactions/save-map', [\Modules\Accounting\Http\Controllers\TransactionController::class, 'saveMap'])->name('accounting.transactions.saveMap');
    Route::post('save-settings', [\Modules\Accounting\Http\Controllers\SettingsController::class, 'saveSettings']);

    Route::get('install', [\Modules\Accounting\Http\Controllers\InstallController::class, 'index']);
    Route::post('install', [\Modules\Accounting\Http\Controllers\InstallController::class, 'install']);
    Route::get('install/uninstall', [\Modules\Accounting\Http\Controllers\InstallController::class, 'uninstall']);
    Route::get('install/update', [\Modules\Accounting\Http\Controllers\InstallController::class, 'update']);
});
