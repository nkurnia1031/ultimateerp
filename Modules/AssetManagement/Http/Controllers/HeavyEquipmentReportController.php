<?php

namespace Modules\AssetManagement\Http\Controllers;

use App\BonusAyak;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\HeavyEquipmentTrip;
use Modules\Project\Entities\Project;

class HeavyEquipmentReportController extends Controller
{
    public function index(Request $request)
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.view') && ! auth()->user()->can('asset.fleet_trips.manage')) {
            abort(403, 'Unauthorized action.');
        }
        $businessId = (int) $request->session()->get('user.business_id');
        $trips = HeavyEquipmentTrip::where('business_id', $businessId)
            ->whereNotIn('status', ['cancelled'])
            ->with(['route', 'asset', 'driver', 'project'])
            ->when($request->input('asset_id'), fn ($q, $value) => $q->where('asset_id', $value))
            ->when($request->input('driver_id'), fn ($q, $value) => $q->where('driver_id', $value))
            ->when($request->input('project_id'), fn ($q, $value) => $q->where('project_id', $value))
            ->when($request->input('start_date'), fn ($q, $value) => $q->whereDate('trip_date', '>=', $value))
            ->when($request->input('end_date'), fn ($q, $value) => $q->whereDate('trip_date', '<=', $value))
            ->get();
        $bonusAyaks = BonusAyak::where('business_id', $businessId)
            ->when($request->input('asset_id'), fn ($q, $value) => $q->where('asset_id', $value))
            ->when($request->input('driver_id'), fn ($q, $value) => $q->where('driver_id', $value))
            ->when($request->input('project_id'), fn ($q, $value) => $q->where('project_id', $value))
            ->when($request->input('start_date'), fn ($q, $value) => $q->whereDate('tanggal', '>=', $value))
            ->when($request->input('end_date'), fn ($q, $value) => $q->whereDate('tanggal', '<=', $value))
            ->get();

        $summary = [
            'trip_count' => $trips->count(),
            'tons' => $trips->sum('actual_tons'),
            'revenue' => $trips->sum('revenue_total'),
            'driver_pay' => $trips->sum('driver_pay_total'),
            'bonus_ayak' => $bonusAyaks->sum('bonus'),
            'contribution' => $trips->sum('gross_contribution') - $bonusAyaks->sum('bonus'),
        ];
        $byAsset = $trips->groupBy('asset_id')->map(function ($items) use ($bonusAyaks) {
            $bonus = $bonusAyaks->where('asset_id', $items->first()->asset_id)->sum('bonus');
            return ['label' => optional($items->first()->asset)->name, 'trips' => $items->count(), 'tons' => $items->sum('actual_tons'), 'revenue' => $items->sum('revenue_total'), 'driver_pay' => $items->sum('driver_pay_total'), 'bonus_ayak' => $bonus, 'contribution' => $items->sum('gross_contribution') - $bonus];
        })->sortBy('label');
        $byDriver = $trips->groupBy('driver_id')->map(function ($items) use ($bonusAyaks) {
            $bonus = $bonusAyaks->where('driver_id', $items->first()->driver_id)->sum('bonus');
            return ['label' => optional($items->first()->driver)->user_full_name, 'trips' => $items->count(), 'tons' => $items->sum('actual_tons'), 'driver_pay' => $items->sum('driver_pay_total'), 'bonus_ayak' => $bonus, 'contribution' => $items->sum('gross_contribution') - $bonus];
        })->sortBy('label');
        $assets = Asset::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id');
        $drivers = User::where('business_id', $businessId)->where('user_type', 'user')->orderBy('first_name')->get()->mapWithKeys(fn ($user) => [$user->id => $user->user_full_name]);
        $projects = Project::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id');

        return view('assetmanagement::fleet.reports.index', compact('summary', 'byAsset', 'byDriver', 'assets', 'drivers', 'projects'));
    }

    public function export(Request $request)
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.view') && ! auth()->user()->can('asset.fleet_trips.manage')) {
            abort(403, 'Unauthorized action.');
        }
        $businessId = (int) $request->session()->get('user.business_id');
        $trips = HeavyEquipmentTrip::where('business_id', $businessId)
            ->whereNotIn('status', ['cancelled'])
            ->with(['route', 'asset', 'driver', 'project', 'bonusAyaks'])
            ->when($request->input('asset_id'), fn ($q, $value) => $q->where('asset_id', $value))
            ->when($request->input('driver_id'), fn ($q, $value) => $q->where('driver_id', $value))
            ->when($request->input('project_id'), fn ($q, $value) => $q->where('project_id', $value))
            ->when($request->input('start_date'), fn ($q, $value) => $q->whereDate('trip_date', '>=', $value))
            ->when($request->input('end_date'), fn ($q, $value) => $q->whereDate('trip_date', '<=', $value))
            ->orderBy('trip_date')->orderBy('id')->get();

        return response()->streamDownload(function () use ($trips) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['No SPB', 'No Tiket', 'Tanggal', 'Rute', 'Unit', 'Sopir', 'Project', 'Ton Netto', 'Ton Tagihan', 'Pendapatan', 'Upah Sopir', 'Bonus Ayak terkait SPB', 'Kontribusi setelah Bonus', 'Status'], ';');
            foreach ($trips as $trip) {
                fputcsv($handle, [
                    $trip->trip_no,
                    $trip->ticket_no,
                    optional($trip->trip_date)->format('Y-m-d'),
                    optional($trip->route)->name,
                    optional($trip->asset)->name,
                    optional($trip->driver)->user_full_name,
                    optional($trip->project)->name,
                    $trip->actual_tons,
                    $trip->billable_tons ?: $trip->actual_tons,
                    $trip->revenue_total,
                    $trip->driver_pay_total,
                    $trip->bonusAyaks->sum('bonus'),
                    $trip->gross_contribution - $trip->bonusAyaks->sum('bonus'),
                    $trip->status,
                ], ';');
            }
            fclose($handle);
        }, 'rekap-spb-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function assetLedger($assetId)
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.view') && ! auth()->user()->can('asset.fleet_trips.manage')) {
            abort(403, 'Unauthorized action.');
        }

        $businessId = (int) request()->session()->get('user.business_id');
        $asset = Asset::where('business_id', $businessId)->findOrFail($assetId);
        $baseQuery = HeavyEquipmentTrip::where('business_id', $businessId)->where('asset_id', $asset->id);
        $summary = [
            'trip_count' => (clone $baseQuery)->whereNotIn('status', ['cancelled'])->count(),
            'tons' => (clone $baseQuery)->whereNotIn('status', ['cancelled'])->sum('actual_tons'),
            'revenue' => (clone $baseQuery)->whereNotIn('status', ['cancelled'])->sum('revenue_total'),
            'driver_pay' => (clone $baseQuery)->whereNotIn('status', ['cancelled'])->sum('driver_pay_total'),
            'bonus_ayak' => BonusAyak::where('business_id', $businessId)->where('asset_id', $asset->id)->sum('bonus'),
            'contribution' => (clone $baseQuery)->whereNotIn('status', ['cancelled'])->sum('gross_contribution') - BonusAyak::where('business_id', $businessId)->where('asset_id', $asset->id)->sum('bonus'),
        ];
        $trips = $baseQuery->with(['route', 'driver', 'project', 'billing', 'settlements', 'bonusAyaks'])
            ->latest('trip_date')
            ->latest('id')
            ->get();
        $manualBonusAyaks = BonusAyak::where('business_id', $businessId)
            ->where('asset_id', $asset->id)
            ->whereDoesntHave('trips')
            ->with(['driver', 'project'])
            ->latest('tanggal')
            ->latest('id')
            ->get();

        return view('assetmanagement::fleet.reports.asset_ledger', compact('asset', 'summary', 'trips', 'manualBonusAyaks'));
    }
}
