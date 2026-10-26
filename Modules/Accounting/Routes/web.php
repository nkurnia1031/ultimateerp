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

Route::middleware('web', 'SetSessionData', 'auth', 'language', 'timezone', 'AdminSidebarMenu')->prefix('accounting')->group(function () {
    Route::get('dashboard', [\Modules\Accounting\Http\Controllers\AccountingController::class, 'dashboard']);

    Route::get('accounts-dropdown', [\Modules\Accounting\Http\Controllers\AccountingController::class, 'AccountsDropdown'])->name('accounts-dropdown');

    Route::get('get-account-sub-types', [\Modules\Accounting\Http\Controllers\CoaController::class, 'getAccountSubTypes']);
    Route::get('get-account-details-types', [\Modules\Accounting\Http\Controllers\CoaController::class, 'getAccountDetailsType']);
    Route::resource('chart-of-accounts', \Modules\Accounting\Http\Controllers\CoaController::class);
    Route::get('ledger/{id}', [\Modules\Accounting\Http\Controllers\CoaController::class, 'ledger'])->name('accounting.ledger');
    Route::get('activate-deactivate/{id}', [\Modules\Accounting\Http\Controllers\CoaController::class, 'activateDeactivate']);
    Route::get('create-default-accounts', [\Modules\Accounting\Http\Controllers\CoaController::class, 'createDefaultAccounts'])->name('accounting.create-default-accounts');

    Route::get('journal-entry/import', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'import'])->name('journal-entry.import');
    Route::post('journal-entry/import/analyze', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'analyzeImport']);
    Route::get('journal-entry/import/{id}/review', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'reviewImport']);
    Route::get('journal-entry/import/{id}/status', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'importStatus']);
    Route::get('journal-entry/import/{id}/draft-rows', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'draftRows']);
    Route::patch('journal-entry/import/{id}/draft-rows/approve-differences', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'approveDraftDiscrepancies']);
    Route::patch('journal-entry/import/{id}/draft-rows/{row_id}', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'updateDraftRow']);
    Route::patch('journal-entry/import/{id}/draft-rows/{row_id}/approve-difference', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'approveDraftDiscrepancy']);
    Route::get('journal-entry/import/{id}/draft-options', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'draftOptions']);
    Route::post('journal-entry/import/start', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'startImport']);
    Route::post('journal-entry/import/process', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'processImport']);
    Route::post('journal-entry/import', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'storeImport']);
    Route::post('journal-entry/import/{id}/revert', [\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'revertImport']);
    Route::resource('journal-entry', \Modules\Accounting\Http\Controllers\JournalEntryController::class);

    Route::get('settings', [\Modules\Accounting\Http\Controllers\SettingsController::class, 'index']);
    Route::get('reset-data', [\Modules\Accounting\Http\Controllers\SettingsController::class, 'resetData']);

    Route::resource('account-type', \Modules\Accounting\Http\Controllers\AccountTypeController::class);

    Route::resource('transfer', \Modules\Accounting\Http\Controllers\TransferController::class)->except(['show']);

    Route::resource('budget', \Modules\Accounting\Http\Controllers\BudgetController::class)->except(['show', 'edit', 'update', 'destroy']);

    Route::get('reports', [\Modules\Accounting\Http\Controllers\ReportController::class, 'index']);
    Route::get('reports/general-ledger/export', [\Modules\Accounting\Http\Controllers\ReportController::class, 'exportGeneralLedger'])->name('accounting.generalLedger.export');
    Route::get('reports/general-ledger', [\Modules\Accounting\Http\Controllers\ReportController::class, 'generalLedger'])->name('accounting.generalLedger');
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
    Route::post('transactions/save-map', [\Modules\Accounting\Http\Controllers\TransactionController::class, 'saveMap']);
    Route::post('save-settings', [\Modules\Accounting\Http\Controllers\SettingsController::class, 'saveSettings']);

    Route::get('install', [\Modules\Accounting\Http\Controllers\InstallController::class, 'index']);
    Route::post('install', [\Modules\Accounting\Http\Controllers\InstallController::class, 'install']);
    Route::get('install/uninstall', [\Modules\Accounting\Http\Controllers\InstallController::class, 'uninstall']);
    Route::get('install/update', [\Modules\Accounting\Http\Controllers\InstallController::class, 'update']);
});
