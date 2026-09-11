<?php

namespace Modules\AssetManagement\Http\Controllers;

use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\AssetManagement\Entities\HeavyEquipmentDriverSettlement;
use Modules\AssetManagement\Services\HeavyEquipmentTripService;

class HeavyEquipmentSettlementController extends Controller
{
    public function __construct(private HeavyEquipmentTripService $tripService)
    {
    }

    public function index()
    {
        $this->authorizeView();
        $businessId = $this->businessId();
        $settlements = HeavyEquipmentDriverSettlement::where('business_id', $businessId)->with(['driver', 'payrollTransaction'])->latest()->get();
        $drivers = User::where('business_id', $businessId)->where('user_type', 'user')->orderBy('first_name')->get()->mapWithKeys(fn ($user) => [$user->id => $user->user_full_name]);

        return view('assetmanagement::fleet.settlements.index', compact('settlements', 'drivers'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();
        $request->validate([
            'driver_id' => 'required|integer',
            'period_start' => 'required|date',
            'period_end' => 'required|date',
            'trip_ids' => 'nullable|array',
            'trip_ids.*' => 'integer',
            'bonus_ids' => 'nullable|array',
            'bonus_ids.*' => 'integer',
            'kasbon_ids' => 'nullable|array',
            'kasbon_ids.*' => 'integer',
        ]);

        try {
            $settlement = $this->tripService->createSettlement($request->all(), $this->businessId(), auth()->id());

            return redirect()->route('asset.fleet.settlements.index')->with('status', ['success' => true, 'msg' => "Settlement {$settlement->settlement_no} dibuat."]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('asset.fleet.settlements.index')->withInput()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function preview(Request $request)
    {
        $this->authorizeManage();
        $validated = $request->validate([
            'driver_id' => 'required|integer',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);
        $businessId = $this->businessId();
        $driver = User::where('business_id', $businessId)->where('user_type', 'user')->findOrFail($validated['driver_id']);
        $candidates = $this->tripService->settlementCandidates(
            $businessId,
            $driver->id,
            Carbon::parse($validated['period_start'])->startOfDay(),
            Carbon::parse($validated['period_end'])->endOfDay()
        );

        return view('assetmanagement::fleet.settlements.preview', [
            'driver' => $driver,
            'trips' => $candidates['trips'],
            'bonusAyaks' => $candidates['bonusAyaks'],
            'kasbons' => $candidates['kasbons'],
            'periodStart' => $validated['period_start'],
            'periodEnd' => $validated['period_end'],
        ]);
    }

    public function post($id)
    {
        $this->authorizeManage();
        $settlement = HeavyEquipmentDriverSettlement::where('business_id', $this->businessId())->findOrFail($id);

        try {
            $this->tripService->postSettlement($settlement, auth()->id());

            return back()->with('status', ['success' => true, 'msg' => 'Settlement diposting ke Payroll Essentials.']);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function cancel($id)
    {
        $this->authorizeManage();
        $settlement = HeavyEquipmentDriverSettlement::where('business_id', $this->businessId())->findOrFail($id);
        if ($settlement->status !== 'draft') {
            return back()->with('status', ['success' => false, 'msg' => 'Hanya settlement draft yang dapat dibatalkan.']);
        }

        DB::transaction(function () use ($settlement) {
            $settlement->trips()->detach();
            $settlement->bonusAyaks()->detach();
            $settlement->kasbons()->detach();
            $settlement->update(['status' => 'cancelled']);
        });

        return back()->with('status', ['success' => true, 'msg' => 'Settlement dibatalkan dan sumber payroll tersedia kembali.']);
    }

    private function businessId(): int
    {
        return (int) request()->session()->get('user.business_id');
    }

    private function authorizeView(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.view') && ! auth()->user()->can('asset.fleet_settlements.manage') && ! auth()->user()->can('asset.fleet_payroll.manage') && ! auth()->user()->can('asset.fleet_trips.manage')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.fleet_settlements.manage') && ! auth()->user()->can('asset.fleet_payroll.manage') && ! auth()->user()->can('asset.fleet_trips.manage') && ! auth()->user()->can('asset.create') && ! auth()->user()->can('asset.update')) {
            abort(403, 'Unauthorized action.');
        }
    }
}
