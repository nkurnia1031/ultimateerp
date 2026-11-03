<?php

namespace Modules\Accounting\Http\Controllers;

use App\Utils\ModuleUtil;
use App\Utils\Util;
use Illuminate\Routing\Controller;
use Menu;

class DataController extends Controller
{
    /**
     * Superadmin package permissions
     *
     * @return array
     */
    public function superadmin_package()
    {
        return [
            [
                'name' => 'accounting_module',
                'label' => __('accounting::lang.accounting_module'),
                'default' => false,
            ],
        ];
    }

    /**
     * Adds cms menus
     *
     * @return null
     */
    public function modifyAdminMenu()
    {
        $business_id = session()->get('user.business_id');
        $module_util = new ModuleUtil();

        $is_accounting_enabled = (bool) $module_util->hasThePermissionInSubscription($business_id, 'accounting_module');

        $commonUtil = new Util();
        $is_admin = $commonUtil->is_admin(auth()->user(), $business_id);

        if (auth()->user()->can('accounting.access_accounting_module') && $is_accounting_enabled) {
            Menu::modify(
                'admin-sidebar-menu',
                function ($menu) {
                    $menu->url(action([\Modules\Accounting\Http\Controllers\AccountingController::class, 'dashboard']), __('accounting::lang.accounting'), ['icon' => 'fas fa-money-check fa', 'style' => config('app.env') == 'demo' ? 'background-color: #D483D9;' : '', 'active' => request()->segment(1) == 'accounting'])->order(50);
                }
            );
        }

        $user = auth()->user();
        $canViewReconciliationFindings = $user->can('accounting.view_reports')
            && ($is_accounting_enabled || $user->can('superadmin'));

        if ($canViewReconciliationFindings) {
            $openFindingCount = app(\Modules\Accounting\Services\LegacyReconciliationFindingSnapshotService::class)
                ->openCount((int) $business_id);
            $badge = $openFindingCount > 0
                ? '<span class="reconciliation-findings-sidebar-badge" aria-hidden="true">'.number_format($openFindingCount).'</span>'
                : '';
            $linkTitle = '<span class="reconciliation-findings-sidebar-label">Temuan Rekonsiliasi</span>'.$badge;
            $linkAriaLabel = $openFindingCount > 0
                ? 'Temuan Rekonsiliasi, '.number_format($openFindingCount).' temuan terbuka'
                : 'Temuan Rekonsiliasi';

            Menu::modify(
                'admin-sidebar-menu',
                function ($menu) use ($linkTitle, $linkAriaLabel, $openFindingCount) {
                    $menu->url(
                        route('accounting.reconciliation.findings'),
                        $linkTitle,
                        [
                            'icon' => 'fas fa-exclamation-circle',
                            'active' => request()->routeIs('accounting.reconciliation.findings*'),
                            'class' => 'reconciliation-findings-sidebar-link'.($openFindingCount > 0 ? ' has-open-findings' : ''),
                            'aria-label' => $linkAriaLabel,
                        ]
                    )->order(51);
                }
            );
        }
    }

    /**
     * Defines user permissions for the module.
     *
     * @return array
     */
    public function user_permissions()
    {
        return [
            [
                'value' => 'accounting.access_accounting_module',
                'label' => __('accounting::lang.access_accounting_module'),
                'default' => false,
            ],
            [
                'value' => 'accounting.manage_accounts',
                'label' => __('accounting::lang.manage_accounts'),
                'default' => false,
            ],
            [
                'value' => 'accounting.view_journal',
                'label' => __('accounting::lang.view_journal'),
                'default' => false,
            ],
            [
                'value' => 'accounting.add_journal',
                'label' => __('accounting::lang.add_journal'),
                'default' => false,
            ],
            [
                'value' => 'accounting.edit_journal',
                'label' => __('accounting::lang.edit_journal'),
                'default' => false,
            ],
            [
                'value' => 'accounting.delete_journal',
                'label' => __('accounting::lang.delete_journal'),
                'default' => false,
            ],
            [
                'value' => 'accounting.map_transactions',
                'label' => __('accounting::lang.map_transactions'),
                'default' => false,
            ],
            [
                'value' => 'accounting.view_transfer',
                'label' => __('accounting::lang.view_transfer'),
                'default' => false,
            ],
            [
                'value' => 'accounting.add_transfer',
                'label' => __('accounting::lang.add_transfer'),
                'default' => false,
            ],
            [
                'value' => 'accounting.edit_transfer',
                'label' => __('accounting::lang.edit_transfer'),
                'default' => false,
            ],
            [
                'value' => 'accounting.delete_transfer',
                'label' => __('accounting::lang.delete_transfer'),
                'default' => false,
            ],
            [
                'value' => 'accounting.manage_budget',
                'label' => __('accounting::lang.manage_budget'),
                'default' => false,
            ],
            [
                'value' => 'accounting.view_reports',
                'label' => __('accounting::lang.view_reports'),
                'default' => false,
            ],
        ];
    }
}
