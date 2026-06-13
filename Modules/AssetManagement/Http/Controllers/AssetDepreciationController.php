<?php

namespace Modules\AssetManagement\Http\Controllers;

use App\BusinessLocation;
use App\Category;
use App\Media;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\AssetTransaction;
use Modules\AssetManagement\Entities\AssetWarranty;
use Modules\AssetManagement\Services\AssetExpiryReminderService;
use Modules\AssetManagement\Utils\AssetUtil;
use Yajra\DataTables\Facades\DataTables;
use Carbon\Carbon;

class AssetDepreciationController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $moduleUtil;

    protected $commonUtil;

    protected $assetUtil;

    protected $assetExpiryReminderService;

    protected $purchaseTypes;

    /**
     * Constructor
     */
    public function __construct(
        ModuleUtil $moduleUtil,
        Util $commonUtil,
        AssetUtil $assetUtil,
        AssetExpiryReminderService $assetExpiryReminderService
    )
    {
        $this->moduleUtil = $moduleUtil;
        $this->commonUtil = $commonUtil;
        $this->assetUtil = $assetUtil;
        $this->assetExpiryReminderService = $assetExpiryReminderService;

        $this->purchaseTypes = [
            'owned' => __('assetmanagement::lang.owned'),
            'rented' => __('assetmanagement::lang.rented'),
            'leased' => __('assetmanagement::lang.leased'),
        ];
    }
    public function importDataLama()
    {
        $array = array(
            array("val0" => "*AssetName", "val1" => "*AssetNumber", "val2" => "*FixedAssetAccountNumber", "val3" => "Description", "val4" => "*AcquisitionDate", "val5" => "*AcquisitionCost", "val6" => "*AccountCredited(Number)", "val7" => "#Depreciable(Yes/No)", "val8" => "#Method(1:StraightLine, 2:ReducingBalance)", "val9" => "#UsefulLife", "val10" => "#RateperYear", "val11" => "#DepreciationAccount(Number)", "val12" => "#AccumulatedDepreciationAccount(Number)", "val13" => "#AccumulatedDepreciationValue", "val14" => "#AsAtDate(for accumulated depreciation)", "val15" => "Tags(use ; to separate tags)"),
            array("val0" => "B 138 BMT", "val1" => "10001", "val2" => "1-10703", "val3" => "", "val4" => "01/01/2016", "val5" => "135000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "5", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/01/2016", "val15" => ""),
            array("val0" => "B 9185 TYV", "val1" => "10002", "val2" => "1-10703", "val3" => "", "val4" => "01/08/2013", "val5" => "275000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/08/2013", "val15" => ""),
            array("val0" => "B 9243 WV", "val1" => "10003", "val2" => "1-10703", "val3" => "", "val4" => "01/05/2012", "val5" => "450000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/05/2012", "val15" => ""),
            array("val0" => "B 9845 QZ", "val1" => "10004", "val2" => "1-10703", "val3" => "", "val4" => "01/05/2012", "val5" => "450000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/05/2012", "val15" => ""),
            array("val0" => "B 9917 TYU", "val1" => "10005", "val2" => "1-10703", "val3" => "", "val4" => "01/08/2013", "val5" => "275000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/08/2013", "val15" => ""),
            array("val0" => "B 9920 TYU", "val1" => "10006", "val2" => "1-10703", "val3" => "", "val4" => "01/08/2013", "val5" => "275000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/08/2013", "val15" => ""),
            array("val0" => "B 9979 OL", "val1" => "10007", "val2" => "1-10703", "val3" => "", "val4" => "01/05/2010", "val5" => "375000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/05/2010", "val15" => ""),
            array("val0" => "BK 8766 LL", "val1" => "10008", "val2" => "1-10703", "val3" => "", "val4" => "01/09/2016", "val5" => "475000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/09/2016", "val15" => ""),
            array("val0" => "BM 2436 HU", "val1" => "10009", "val2" => "1-10703", "val3" => "", "val4" => "01/05/2020", "val5" => "17000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "5", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/05/2020", "val15" => ""),
            array("val0" => "BM 8361 DK", "val1" => "10010", "val2" => "1-10703", "val3" => "", "val4" => "01/05/2020", "val5" => "230000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "5", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/05/2020", "val15" => ""),
            array("val0" => "BM 8536 RG", "val1" => "10011", "val2" => "1-10703", "val3" => "", "val4" => "01/02/2020", "val5" => "130000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "5", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/02/2020", "val15" => ""),
            array("val0" => "BM 8736 DI", "val1" => "10012", "val2" => "1-10703", "val3" => "", "val4" => "01/08/2017", "val5" => "95000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "5", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/08/2017", "val15" => ""),
            array("val0" => "BM 888 BMT", "val1" => "10013", "val2" => "1-10703", "val3" => "", "val4" => "01/02/2021", "val5" => "545000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "5", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/02/2021", "val15" => ""),
            array("val0" => "BM 8979 RO", "val1" => "10014", "val2" => "1-10703", "val3" => "", "val4" => "01/10/2010", "val5" => "450000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/10/2010", "val15" => ""),
            array("val0" => "BM 9133 PU", "val1" => "10015", "val2" => "1-10703", "val3" => "", "val4" => "01/03/2014", "val5" => "375000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/03/2014", "val15" => ""),
            array("val0" => "BM 9174 LP", "val1" => "10016", "val2" => "1-10703", "val3" => "", "val4" => "01/03/2014", "val5" => "375000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/03/2014", "val15" => ""),
            array("val0" => "BM 9177 PU", "val1" => "10017", "val2" => "1-10703", "val3" => "", "val4" => "01/03/2014", "val5" => "375000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/03/2014", "val15" => ""),
            array("val0" => "BM 9600 RE", "val1" => "10018", "val2" => "1-10703", "val3" => "", "val4" => "01/03/2014", "val5" => "375000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/03/2014", "val15" => ""),
            array("val0" => "DP 8453 AJ", "val1" => "10019", "val2" => "1-10703", "val3" => "", "val4" => "01/08/2017", "val5" => "475000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "01/08/2017", "val15" => ""),
            array("val0" => "BM 1725 HB", "val1" => "10020", "val2" => "1-10703", "val3" => "", "val4" => "17/04/2021", "val5" => "295000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "5", "val10" => "", "val11" => "6-60502", "val12" => "1-10753", "val13" => "0", "val14" => "17/04/2021", "val15" => ""),
            array("val0" => "Loader 01", "val1" => "10021", "val2" => "1-10704", "val3" => "", "val4" => "01/06/2012", "val5" => "450000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/06/2012", "val15" => ""),
            array("val0" => "Loader 02", "val1" => "10022", "val2" => "1-10704", "val3" => "", "val4" => "01/06/2012", "val5" => "450000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/06/2012", "val15" => ""),
            array("val0" => "Loader 03", "val1" => "10023", "val2" => "1-10704", "val3" => "", "val4" => "01/08/2014", "val5" => "375000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/08/2014", "val15" => ""),
            array("val0" => "Loader 05", "val1" => "10024", "val2" => "1-10704", "val3" => "", "val4" => "01/08/2014", "val5" => "375000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/08/2014", "val15" => ""),
            array("val0" => "Ex 01", "val1" => "10025", "val2" => "1-10704", "val3" => "", "val4" => "01/08/2012", "val5" => "500000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/08/2012", "val15" => ""),
            array("val0" => "Ex 02", "val1" => "10026", "val2" => "1-10704", "val3" => "", "val4" => "01/06/2013", "val5" => "550000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/06/2013", "val15" => ""),
            array("val0" => "Ex 03", "val1" => "10027", "val2" => "1-10704", "val3" => "", "val4" => "01/05/2016", "val5" => "500000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/05/2016", "val15" => ""),
            array("val0" => "Ex 05", "val1" => "10028", "val2" => "1-10704", "val3" => "", "val4" => "01/05/2016", "val5" => "425000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/05/2016", "val15" => ""),
            array("val0" => "Ex 06", "val1" => "10029", "val2" => "1-10704", "val3" => "", "val4" => "01/05/2016", "val5" => "425000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/05/2016", "val15" => ""),
            array("val0" => "Ex 07", "val1" => "10030", "val2" => "1-10704", "val3" => "", "val4" => "01/08/2018", "val5" => "375000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/08/2018", "val15" => ""),
            array("val0" => "Ex 08", "val1" => "10031", "val2" => "1-10704", "val3" => "", "val4" => "01/08/2018", "val5" => "375000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/08/2018", "val15" => ""),
            array("val0" => "Dozer 01", "val1" => "10032", "val2" => "1-10704", "val3" => "", "val4" => "01/06/2020", "val5" => "425000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/06/2020", "val15" => ""),
            array("val0" => "Dozer 02", "val1" => "10033", "val2" => "1-10704", "val3" => "", "val4" => "01/03/2021", "val5" => "400000000", "val6" => "3-30999", "val7" => "Yes", "val8" => "1", "val9" => "10", "val10" => "", "val11" => "6-60503", "val12" => "1-10754", "val13" => "0", "val14" => "01/03/2021", "val15" => "")
        );
        unset($array[0]);

        $array = array_map(function ($item) {

            return [
                'name' => $item['val0'],
                'asset_code' => $item['val1'],
                'category_id' => $item['val1'] > 10020 ? 12 : 10,
                'location_id' => 2,
                'model' => null,
                'stnk' => null,
                'purchase_type' => 'owned',
                'purchase_date' => Carbon::createFromFormat('d/m/Y', $item['val4'])->format('Y-m-d'),
                'unit_price' => $item['val5'],
                'is_allocatable' => 1,
                'quantity' => 1,
                'description' => $item['val3'],
                'business_id' =>   auth()->user()->business_id,
                'created_by' => auth()->user()->id,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }, $array);
        DB::table('assets')->where('quantity', 1)->delete();
        DB::table('assets')->insert($array);
    }

    public function importDataExpense()
    {
        $data = DB::table('expense_lines')->leftJoin('expense_categories', 'expense_lines.category_id', '=', 'expense_categories.id')->whereIn('name', ['Depreciation - Vehicle', 'Depreciation - Machinery & Equipment'])->get();
        $assets = Asset::all();

        foreach ($assets as $asset) {
            $asset_depreciation = DB::table('asset_depreciation')->where('asset_id', $asset->id)->get();
            $asset_depreciation = $asset_depreciation->pluck('transaction_id')->toArray();
            $found = $data->whereNotIn('transaction_id', $asset_depreciation)->filter(function ($value, $key) use ($asset) {
                return str_contains($value->description, $asset->asset_code) || str_contains($value->description, $asset->name);
            })->unique('transaction_id')->map(function ($item) use ($asset) {
                return [
                    'asset_id' => $asset->id,
                    'transaction_id' => $item->transaction_id,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
            })->values()->toArray();
            DB::table('asset_depreciation')->insert($found);
        }
    }
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'assetmanagement_module')))) {
            abort(403, 'Unauthorized action.');
        }

        $purchase_types = $this->purchaseTypes;
        $this->importDataExpense();


        if ($request->ajax()) {
            // $this->importDataLama();

            $assets = DB::table('asset_depreciation')
                ->leftJoin('assets', 'asset_depreciation.asset_id', '=', 'assets.id')
                ->leftJoin('transactions', 'asset_depreciation.transaction_id', '=', 'transactions.id')
                ->leftJoin('expense_lines', 'transactions.id', '=', 'expense_lines.transaction_id')
                ->leftJoin('expense_categories', 'expense_lines.category_id', '=', 'expense_categories.id')
                ->where('assets.business_id', $business_id)
                ->select(
                    'assets.name as asset',
                    'assets.asset_code as asset_code',
                    'expense_lines.amount as depreciation',
                    'transactions.transaction_date as date',
                    'transactions.id as transaction_id',
                    'expense_categories.name as category',
                    'asset_depreciation.id as id'
                )
                ->groupBy('asset_depreciation.id');

            $permitted_locations = auth()->user()->permitted_locations();

            if ($permitted_locations != 'all') {
                $assets->whereIn('assets.location_id', $permitted_locations);
            }

            if (! empty(request()->input('location_id'))) {
                $assets->where('assets.location_id', request()->input('location_id'));
            }
            if (! empty(request()->input('location_id'))) {
                $assets->where('assets.location_id', request()->input('location_id'));
            }
            if (! empty(request()->input('asset_id'))) {
                $assets->where('assets.id', request()->input('asset_id'));
            }
            if (! empty(request()->input('purchase_type'))) {
                $assets->where('assets.purchase_type', request()->input('purchase_type'));
            }
            if (! empty(request()->input('is_allocatable'))) {
                $assets->where('assets.is_allocatable', 1);
            }

            $now = \Carbon::now();

            return Datatables::of($assets)
                ->addColumn('action', function ($row) {
                    $html = '<div class="btn-group">
                                    <button class="btn btn-info dropdown-toggle btn-xs" type="button"  data-toggle="dropdown" aria-expanded="false">
                                        ' . __('messages.action') . '
                                        <span class="caret"></span>
                                        <span class="sr-only">
                                        ' . __('messages.action') . '
                                        </span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-left" role="menu">
                                    <li><a href="#" data-href="' . action('App\Http\Controllers\ExpenseController@show', [$row->transaction_id]) . ' " class="btn-modal" data-container=".payment_modal"><i class="fas fa-eye" aria-hidden="true"></i>  ' . __('messages.view') . '</a></li>

                                    ';


                    if (auth()->user()->can('asset.delete')) {
                        $html .= '<li>
                                    <a data-href="' . action([\Modules\AssetManagement\Http\Controllers\AssetController::class, 'destroy'], [$row->id]) . '"  id="delete_asset" class="cursor-pointer">
                                        <i class="fas fa-trash"></i>
                                        ' . __('messages.delete') . '
                                    </a>
                                </li>
                                </ul>';
                    }

                    $html .= '
                            </div>';

                    return $html;
                })
                ->editColumn('date', '
                    @if(!empty($date))
                        {{@format_date($date)}}
                    @endif
                ')


                ->editColumn('depreciation', function ($row) {
                    return '<span class="display_currency total-discount" data-currency_symbol="true" data-orig-value="' . $row->depreciation . '">' . $row->depreciation . '</span>';
                })

                ->removeColumn('id')
                ->rawColumns([
                    'action',
                    'depreciation',
                    'date',

                ])
                ->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id);
        $asset_category = Category::forDropdown($business_id, 'asset');
        $assets = Asset::forDropdown($business_id);

        return view('assetmanagement::asset_depreciation.index')
            ->with(compact('assets', 'purchase_types', 'business_locations', 'asset_category'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create(Request $request)
    {
        if (! auth()->user()->can('asset.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'assetmanagement_module')))) {
            abort(403, 'Unauthorized action.');
        }

        if ($request->ajax()) {
            $asset_category = Category::forDropdown($business_id, 'asset');
            $business_locations = BusinessLocation::forDropdown($business_id);

            $purchase_types = $this->purchaseTypes;

            return view('assetmanagement::asset.create')
                ->with(compact('asset_category', 'business_locations', 'purchase_types'));
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('asset.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'assetmanagement_module')))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $input = $request->only(
                'asset_code',
                'name',
                'quantity',
                'model',
                'serial_no',
                'category_id',
                'location_id',
                'purchase_date',
                'unit_price',
                'depreciation',
                'is_allocatable',
                'description',
                'purchase_type',
                'stnk',
                'stnk_expired_at'
            );

            $input['business_id'] = $business_id;
            $input['created_by'] = request()->session()->get('user.id');
            $input['is_allocatable'] = ! empty($input['is_allocatable']) ? 1 : 0;

            DB::beginTransaction();

            if (empty($input['asset_code'])) {
                $ref_count = $this->commonUtil->setAndGetReferenceCount('asset_code', $business_id);
                $asset_settings = $this->assetUtil->getAssetSettings($business_id);

                $asset_code_prefix = $asset_settings['asset_code_prefix'] ?? null;
                $input['asset_code'] = $this->commonUtil->generateReferenceNumber('asset_code', $ref_count, null, $asset_code_prefix);
            }

            if (! empty($input['quantity'])) {
                $input['quantity'] = $this->commonUtil->num_uf($input['quantity']);
            }

            if (! empty($input['purchase_date'])) {
                $input['purchase_date'] = $this->commonUtil->uf_date($input['purchase_date']);
            }

            if (! empty($input['unit_price'])) {
                $input['unit_price'] = $this->commonUtil->num_uf($input['unit_price']);
            }

            if (! empty($input['depreciation'])) {
                $input['depreciation'] = $this->commonUtil->num_uf($input['depreciation']);
            }

            $document_name = $this->assetUtil->uploadFile($request, 'stnk', 'documents');
            if (! empty($document_name)) {
                $input['stnk'] = $document_name;
            } else {
                unset($input['stnk']);
            }
            if (! empty($input['stnk_expired_at'])) {
                $input['stnk_expired_at'] = $this->commonUtil->uf_date($input['stnk_expired_at']);
            }
            $asset = Asset::create($input);

            //upload media
            if ($request->has('image')) {
                Media::uploadMedia($business_id, $asset, $request, 'image', true);
            }
            $warranties = [];
            if (! empty($request->input('start_dates'))) {
                $months = $request->input('months');
                foreach ($request->input('start_dates') as $key => $value) {
                    if (! empty($value) && ! empty($months[$key])) {
                        $start_date = $this->commonUtil->uf_date($value);
                        $warranties[] = [
                            'start_date' => $start_date,
                            'end_date' => \Carbon::parse($start_date)->addMonths($months[$key])->format('Y-m-d'),
                            'additional_cost' => $this->commonUtil->num_uf($request->input('additional_cost')[$key]),
                            'additional_note' => $request->input('additional_note')[$key],

                        ];
                    }
                }
            }

            if (! empty($warranties)) {
                $asset->warranties()->createMany($warranties);
            }

            $this->assetExpiryReminderService->syncAsset($asset);
            DB::commit();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()
            ->action([\Modules\AssetManagement\Http\Controllers\AssetController::class, 'index'])
            ->with('status', $output);
    }

    /**
     * Show the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($id)
    {
        return view('assetmanagement::show');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit($id)
    {
        if (! auth()->user()->can('asset.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'assetmanagement_module')))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $asset = Asset::with(['warranties'])
                ->where('business_id', $business_id)
                ->findOrfail($id);

            $asset_category = Category::forDropdown($business_id, 'asset');
            $business_locations = BusinessLocation::forDropdown($business_id);

            $purchase_types = $this->purchaseTypes;

            return view('assetmanagement::asset.edit')
                ->with(compact('asset_category', 'business_locations', 'asset', 'purchase_types'));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        if (! auth()->user()->can('asset.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'assetmanagement_module')))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $input = $request->only(
                'name',
                'quantity',
                'model',
                'category_id',
                'location_id',
                'purchase_date',
                'unit_price',
                'depreciation',
                'is_allocatable',
                'description',
                'purchase_type',
                'serial_no',
                'stnk',
                'stnk_expired_at'
            );
            $input['is_allocatable'] = ! empty($input['is_allocatable']) ? 1 : 0;

            DB::beginTransaction();

            if (! empty($input['quantity'])) {
                $input['quantity'] = $this->commonUtil->num_uf($input['quantity']);
            }

            if (! empty($input['purchase_date'])) {
                $input['purchase_date'] = $this->commonUtil->uf_date($input['purchase_date']);
            }


            if (! empty($input['unit_price'])) {
                $input['unit_price'] = $this->commonUtil->num_uf($input['unit_price']);
            }

            if (! empty($input['depreciation'])) {
                $input['depreciation'] = $this->commonUtil->num_uf($input['depreciation']);
            }
            $document_name = $this->assetUtil->uploadFile($request, 'stnk', 'documents');
            if (! empty($document_name)) {
                $input['stnk'] = $document_name;
            } else {
                unset($input['stnk']);
            }
            if (! empty($input['stnk_expired_at'])) {
                $input['stnk_expired_at'] = $this->commonUtil->uf_date($input['stnk_expired_at']);
            }

            $asset = Asset::where('business_id', $business_id)
                ->findOrfail($id);

            $asset->update($input);

            //update existing warranties
            $edited_warranty_ids = [];
            if (! empty($request->input('edit_warranty'))) {
                foreach ($request->input('edit_warranty') as $key => $value) {
                    $edited_warranty_ids[] = $key;
                    $start_date = $this->commonUtil->uf_date($value['start_date']);
                    AssetWarranty::where('id', $key)
                        ->update([
                            'start_date' => $start_date,
                            'end_date' => \Carbon::parse($start_date)->addMonths($value['months'])->format('Y-m-d'),
                            'additional_cost' => $this->commonUtil->num_uf($value['additional_cost']),
                            'additional_note' => $value['additional_note'],

                        ]);
                }
            }
            AssetWarranty::where('asset_id', $asset->id)
                ->whereNotIn('id', $edited_warranty_ids)
                ->delete();

            //add new warranties
            $warranties = [];
            if (! empty($request->input('start_dates'))) {
                $months = $request->input('months');
                foreach ($request->input('start_dates') as $key => $value) {
                    if (! empty($value) && ! empty($months[$key])) {
                        $start_date = $this->commonUtil->uf_date($value);
                        $warranties[] = [
                            'start_date' => $start_date,
                            'end_date' => \Carbon::parse($start_date)->addMonths($months[$key])->format('Y-m-d'),
                            'additional_cost' => $this->commonUtil->num_uf($request->input('additional_cost')[$key]),
                            'additional_note' => $request->input('additional_note')[$key],

                        ];
                    }
                }
            }

            if (! empty($warranties)) {
                $asset->warranties()->createMany($warranties);
            }

            //upload media
            if ($request->has('image')) {
                Media::uploadMedia($business_id, $asset, $request, 'image', true);
            }

            $this->assetExpiryReminderService->syncAsset($asset);
            DB::commit();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()
            ->action([\Modules\AssetManagement\Http\Controllers\AssetController::class, 'index'])
            ->with('status', $output);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        if (! auth()->user()->can('asset.delete')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'assetmanagement_module')))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                DB::beginTransaction();

                $asset = Asset::where('business_id', $business_id)
                    ->findOrfail($id);

                $this->assetExpiryReminderService->purgeAssetReminders($asset->id);
                $asset->delete();
                $asset->media()->delete();

                DB::commit();

                $output = [
                    'success' => true,
                    'msg' => __('lang_v1.success'),
                ];
            } catch (\Exception $e) {
                DB::rollBack();

                \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }

    public function dashboard()
    {
        $business_id = request()->session()->get('user.business_id');

        $allocated_assets = AssetTransaction::where('receiver', auth()->user()->id)
            ->select(
                DB::raw('SUM(quantity) as total_quantity_allocated'),
                DB::raw('(SELECT SUM(quantity) FROM asset_transactions as AT WHERE 
                                                AT.parent_id = asset_transactions.id AND AT.transaction_type="revoke") as total_revoked_quantity')
            )->first();

        $total_assets_allocated = $allocated_assets->total_quantity_allocated - $allocated_assets->total_revoked_quantity;

        $asset_allocation_by_category = AssetTransaction::where(
            'asset_transactions.receiver',
            auth()->user()->id
        )
            ->leftJoin(
                'assets as a',
                'a.id',
                '=',
                'asset_transactions.asset_id'
            )
            ->leftJoin(
                'categories as cat',
                'a.category_id',
                '=',
                'cat.id'
            )
            ->select(
                DB::raw("SUM(COALESCE(asset_transactions.quantity, 0) - COALESCE((SELECT SUM(quantity) FROM asset_transactions as AT WHERE AT.parent_id =asset_transactions.id AND AT.transaction_type='revoke'),0)) as total_quantity_allocated"),
                'cat.name as category'
            )->groupBy('cat.id')->get();



        $is_admin = $this->commonUtil->is_admin(auth()->user());

        $total_assets = 0;
        $assets_by_category = null;
        $total_assets_allocated_for_all_users = 0;
        $expiring_assets = null;

        if ($is_admin) {
            $total_assets = Asset::where('business_id', $business_id)
                ->select(DB::raw('SUM(quantity) as total_quantity'))
                ->first()->total_quantity;

            $assets_by_category = Asset::where('assets.business_id', $business_id)
                ->leftJoin('categories as cat', 'assets.category_id', '=', 'cat.id')
                ->select(
                    DB::raw('SUM(quantity) as total_quantity'),
                    'cat.name as category'
                )
                ->groupBy('cat.id')
                ->get();

            $current_date_add30 = Carbon::now()->addDays(30)->toDateString();

            $expiring_assets = Asset::where('assets.business_id', $business_id)
                ->leftJoin('asset_warranties as aw', 'aw.asset_id', '=', 'assets.id')
                ->groupBy('assets.id')
                ->havingRaw("MAX(aw.end_date) <= '{$current_date_add30}'")
                ->select('assets.name', 'assets.asset_code', \DB::raw('MAX(aw.end_date) as max_end_date'), 'assets.id')
                ->get();

            $allocated_assets_for_all_users = AssetTransaction::where('business_id', $business_id)
                ->select(
                    DB::raw("SUM(IF(transaction_type='allocate', quantity, 0)) as total_quantity_allocated"),
                    DB::raw("SUM(IF(transaction_type='revoke', quantity, 0)) as total_revoked_quantity")
                )->first();

            $total_assets_allocated_for_all_users = $allocated_assets_for_all_users->total_quantity_allocated - $allocated_assets_for_all_users->total_revoked_quantity;
        }

        return view('assetmanagement::asset.dashboard')
            ->with(compact(
                'total_assets_allocated',
                'asset_allocation_by_category',
                'is_admin',
                'total_assets',
                'assets_by_category',
                'expiring_assets',
                'total_assets_allocated_for_all_users'
            ));
    }
}
