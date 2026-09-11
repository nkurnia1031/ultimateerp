<section class="no-print">
    <nav class="navbar navbar-default bg-white m-4">
        <div class="container-fluid">
            <!-- Brand and toggle get grouped for better mobile display -->
            <div class="navbar-header">
                <button type="button" class="navbar-toggle collapsed" data-toggle="collapse"
                    data-target="#bs-example-navbar-collapse-1" aria-expanded="false">
                    <span class="sr-only">Toggle navigation</span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a class="navbar-brand"
                    href="{{ action([\Modules\AssetManagement\Http\Controllers\AssetController::class, 'dashboard']) }}">
                    <i class="fas fa fa-boxes"></i>
                    {{ __('assetmanagement::lang.asset_management') }}
                </a>
            </div>

            <!-- Collect the nav links, forms, and other content for toggling -->
            <div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
                <ul class="nav navbar-nav">
                    @if (auth()->user()->can('asset.view') || auth()->user()->can('asset.fleet_rates.manage') || auth()->user()->can('asset.fleet_trips.manage') || auth()->user()->can('asset.fleet_trips.create') || auth()->user()->can('asset.fleet_billing.manage') || auth()->user()->can('asset.fleet_settlements.manage') || auth()->user()->can('asset.fleet_payroll.manage'))
                        @can('asset.view')
                        <li @if (request()->segment(2) == 'assets') class="active" @endif>
                            <a
                                href="{{ action([\Modules\AssetManagement\Http\Controllers\AssetController::class, 'index']) }}">
                                Asset / Alat Berat
                            </a>
                        </li>
                        <li @if (request()->segment(2) == 'depreciation') class="active" @endif>
                            <a
                                href="{{ action([\Modules\AssetManagement\Http\Controllers\AssetDepreciationController::class, 'index']) }}">
                                @lang('Asset Depreciation')
                            </a>
                        </li>
                        <li @if (request()->segment(2) == 'consumptions') class="active" @endif>
                            <a href="{{ action([\App\Http\Controllers\StockAdjustmentController::class, 'index']) }}">
                                @lang('Asset Consumptions')
                            </a>
                        </li>
                        <li @if (request()->segment(2) == 'allocation') class="active" @endif>
                            <a
                                href="{{ action([\Modules\AssetManagement\Http\Controllers\AssetAllocationController::class, 'index']) }}">
                                @lang('assetmanagement::lang.asset_allocated')
                            </a>
                        </li>
                        <li @if (request()->segment(2) == 'revocation') class="active" @endif>
                            <a
                                href="{{ action([\Modules\AssetManagement\Http\Controllers\RevokeAllocatedAssetController::class, 'index']) }}">
                                @lang('assetmanagement::lang.revoked_asset')
                            </a>
                        </li>
                        @endcan
                        @if (auth()->user()->can('asset.view') || auth()->user()->can('asset.fleet_rates.manage'))
                        <li @if (request()->segment(2) == 'fleet' && request()->segment(3) == 'rates') class="active" @endif>
                            <a href="{{ route('asset.fleet.rates.index') }}">
                                <i class="fas fa-road"></i> Tarif Angkutan
                            </a>
                        </li>
                        @endif
                        @if (auth()->user()->can('asset.view') || auth()->user()->can('asset.fleet_trips.manage') || auth()->user()->can('asset.fleet_trips.create') || auth()->user()->can('asset.fleet_billing.manage'))
                        <li @if (request()->segment(2) == 'fleet' && request()->segment(3) == 'trips') class="active" @endif>
                            <a href="{{ route('asset.fleet.trips.index') }}">
                                <i class="fas fa-truck-moving"></i> SPB Angkutan
                            </a>
                        </li>
                        <li @if (request()->segment(2) == 'fleet' && request()->segment(3) == 'trips' && request()->segment(4) == 'import') class="active" @endif>
                            <a href="{{ route('asset.fleet.trips.import.form') }}">
                                <i class="fas fa-file-import"></i> Import SPB Angkutan
                            </a>
                        </li>
                        @endif
                        @if (auth()->user()->can('asset.view') || auth()->user()->can('asset.fleet_settlements.manage') || auth()->user()->can('asset.fleet_payroll.manage'))
                        <li @if (request()->segment(2) == 'fleet' && request()->segment(3) == 'settlements') class="active" @endif>
                            <a href="{{ route('asset.fleet.settlements.index') }}">
                                <i class="fas fa-money-check-alt"></i> Upah Sopir
                            </a>
                        </li>
                        @endif
                        @if (auth()->user()->can('asset.view') || auth()->user()->can('asset.fleet_trips.manage'))
                        <li @if (request()->segment(2) == 'fleet' && request()->segment(3) == 'reports') class="active" @endif>
                            <a href="{{ route('asset.fleet.reports.index') }}">
                                <i class="fas fa-chart-line"></i> Rekap Alat &amp; Sopir
                            </a>
                        </li>
                        @endif
                    @endif
                    @if (auth()->user()->can('asset.view_all_maintenance') || auth()->user()->can('asset.view_own_maintenance'))
                        <li @if (request()->segment(2) == 'maintenance') class="active" @endif>
                            <a
                                href="{{ action([\Modules\AssetManagement\Http\Controllers\AssetMaitenanceController::class, 'index']) }}">
                                @lang('assetmanagement::lang.asset_maintenance')
                            </a>
                        </li>
                    @endif
                    @can('only_admin')
                        <li @if (request()->get('type') == 'asset') class="active" @endif>
                            <a
                                href="{{ action([\App\Http\Controllers\TaxonomyController::class, 'index']) . '?type=asset' }}">
                                @lang('assetmanagement::lang.asset_categories')
                            </a>
                        </li>
                        <li @if (request()->segment(2) == 'settings') class="active" @endif>
                            <a
                                href="{{ action([\Modules\AssetManagement\Http\Controllers\AssetSettingsController::class, 'index']) }}">
                                @lang('role.settings')
                            </a>
                        </li>
                    @endcan
                </ul>

            </div><!-- /.navbar-collapse -->
        </div><!-- /.container-fluid -->
    </nav>
</section>
