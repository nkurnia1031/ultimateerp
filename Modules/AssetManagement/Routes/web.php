<?php

Route::middleware('web', 'authh', 'auth', 'SetSessionData', 'language', 'timezone', 'AdminSidebarMenu')->prefix('asset')->group(function () {
    Route::get('install', [Modules\AssetManagement\Http\Controllers\InstallController::class, 'index']);
    Route::post('install', [Modules\AssetManagement\Http\Controllers\InstallController::class, 'install']);
    Route::get('install/uninstall', [Modules\AssetManagement\Http\Controllers\InstallController::class, 'uninstall']);
    Route::get('install/update', [Modules\AssetManagement\Http\Controllers\InstallController::class, 'update']);

    Route::get('assets/{asset}/tag-ledger', [Modules\AssetManagement\Http\Controllers\AssetController::class, 'tagLedger']);
    Route::resource('assets', Modules\AssetManagement\Http\Controllers\AssetController::class);
    Route::get('list', [Modules\AssetManagement\Http\Controllers\AssetController::class, 'getAssets']);

    Route::resource('depreciation', Modules\AssetManagement\Http\Controllers\AssetDepreciationController::class);
    Route::resource('allocation', Modules\AssetManagement\Http\Controllers\AssetAllocationController::class);
    Route::resource('revocation', Modules\AssetManagement\Http\Controllers\RevokeAllocatedAssetController::class);
    Route::resource('settings', Modules\AssetManagement\Http\Controllers\AssetSettingsController::class)
        ->names('asset.settings');
    Route::get('dashboard', [Modules\AssetManagement\Http\Controllers\AssetController::class, 'dashboard']);

    Route::prefix('fleet')->name('asset.fleet.')->group(function () {
        Route::get('rates', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'index'])->name('rates.index');
        Route::get('rates/create', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'create'])->name('rates.create');
        Route::post('rates', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'store'])->name('rates.store');
        Route::get('rates/{id}/edit', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'edit'])->name('rates.edit');
        Route::put('rates/{id}', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'update'])->name('rates.update');
        Route::delete('rates/{id}', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'destroy'])->name('rates.destroy');
        Route::post('rates/upload', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'upload'])->name('rates.upload');
        Route::post('rates/{id}/confirm', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'confirm'])->name('rates.confirm');
        Route::post('rates/{id}/cancel', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'cancel'])->name('rates.cancel');
        Route::post('rates/units/{unitId}/map', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'mapUnit'])->name('rates.units.map');
        Route::get('rates/export', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentRateController::class, 'export'])->name('rates.export');

        Route::get('trips', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'index'])->name('trips.index');
        Route::get('trips/create', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'create'])->name('trips.create');
        Route::post('trips', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'store'])->name('trips.store');
        Route::get('trips/{id}/edit', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'edit'])->name('trips.edit');
        Route::put('trips/{id}', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'update'])->name('trips.update');
        Route::get('trips/import', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'importForm'])->name('trips.import.form');
        Route::post('trips/import/preview', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'importPreview'])->name('trips.import.preview');
        Route::post('trips/import/confirm', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'importConfirm'])->name('trips.import.confirm');
        Route::get('trips/project-invoice/choose', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'projectInvoiceChooser'])->name('trips.project-invoice.choose');
        Route::get('trips/project-invoice', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'projectInvoiceForm'])->name('trips.project-invoice.form');
        Route::post('trips/project-invoice', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'projectInvoice'])->name('trips.project-invoice');
        Route::get('trips/sell/choose', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'sellChooser'])->name('trips.sell.choose');
        Route::get('trips/sell', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'sellForm'])->name('trips.sell.form');
        Route::post('trips/sell', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'sell'])->name('trips.sell');
        Route::get('trips/assets/{assetId}/ledger', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentReportController::class, 'assetLedger'])->name('trips.asset-ledger');
        Route::get('trips/{id}', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'show'])->name('trips.show');
        Route::post('trips/{id}/status', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentTripController::class, 'status'])->name('trips.status');

        Route::get('settlements', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentSettlementController::class, 'index'])->name('settlements.index');
        Route::post('settlements/preview', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentSettlementController::class, 'preview'])->name('settlements.preview');
        Route::post('settlements', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentSettlementController::class, 'store'])->name('settlements.store');
        Route::post('settlements/{id}/cancel', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentSettlementController::class, 'cancel'])->name('settlements.cancel');
        Route::post('settlements/{id}/post', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentSettlementController::class, 'post'])->name('settlements.post');

        Route::get('reports', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [Modules\AssetManagement\Http\Controllers\HeavyEquipmentReportController::class, 'export'])->name('reports.export');
    });

    Route::resource('asset-maintenance', 'Modules\AssetManagement\Http\Controllers\AssetMaitenanceController');
});
