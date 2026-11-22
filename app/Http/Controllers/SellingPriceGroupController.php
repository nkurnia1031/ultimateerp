<?php

namespace App\Http\Controllers;

use App\SellingPriceGroup;
use App\Jobs\ProcessSellingPriceGroupImportBatch;
use App\Services\SellingPriceGroupImportBatchService;
use App\Services\UserImportBatchService;
use App\UserImportBatch;
use App\Utils\Util;
use App\Variation;
use App\VariationGroupPrice;
use DB;
use Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Yajra\DataTables\Facades\DataTables;

class SellingPriceGroupController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $commonUtil;

    /**
     * Constructor
     *
     * @param  ProductUtils  $product
     * @return void
     */
    public function __construct(Util $commonUtil)
    {
        $this->commonUtil = $commonUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (! auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $price_groups = SellingPriceGroup::where('business_id', $business_id)
                        ->select(['name', 'description', 'id', 'is_active']);

            return Datatables::of($price_groups)
                ->addColumn(
                    'action',
                    '<button data-href="{{action(\'App\Http\Controllers\SellingPriceGroupController@edit\', [$id])}}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary btn-modal" data-container=".view_modal"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</button>
                        &nbsp;
                        <button data-href="{{action(\'App\Http\Controllers\SellingPriceGroupController@destroy\', [$id])}}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error delete_spg_button"><i class="glyphicon glyphicon-trash"></i> @lang("messages.delete")</button>
                        &nbsp;
                        <button data-href="{{action(\'App\Http\Controllers\SellingPriceGroupController@activateDeactivate\', [$id])}}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs  @if($is_active) tw-dw-btn-error @else tw-dw-btn-success @endif activate_deactivate_spg"><i class="fas fa-power-off"></i> @if($is_active) @lang("messages.deactivate") @else @lang("messages.activate") @endif</button>'
                )
                ->removeColumn('is_active')
                ->removeColumn('id')
                ->rawColumns([2])
                ->make(false);
        }

        return view('selling_price_group.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (! auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        return view('selling_price_group.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $input = $request->only(['name', 'description']);
            $business_id = $request->session()->get('user.business_id');
            $input['business_id'] = $business_id;

            $spg = SellingPriceGroup::create($input);

            //Create a new permission related to the created selling price group
            Permission::create(['name' => 'selling_price_group.'.$spg->id]);

            $output = ['success' => true,
                'data' => $spg,
                'msg' => __('lang_v1.added_success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return $output;
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\SellingPriceGroup  $sellingPriceGroup
     * @return \Illuminate\Http\Response
     */
    public function show(SellingPriceGroup $sellingPriceGroup)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\SellingPriceGroup  $sellingPriceGroup
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (! auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $spg = SellingPriceGroup::where('business_id', $business_id)->find($id);

            return view('selling_price_group.edit')
                ->with(compact('spg'));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\SellingPriceGroup  $sellingPriceGroup
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (! auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $input = $request->only(['name', 'description']);
                $business_id = $request->session()->get('user.business_id');

                $spg = SellingPriceGroup::where('business_id', $business_id)->findOrFail($id);
                $spg->name = $input['name'];
                $spg->description = $input['description'];
                $spg->save();

                $output = ['success' => true,
                    'msg' => __('lang_v1.updated_success'),
                ];
            } catch (\Exception $e) {
                \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

                $output = ['success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\SellingPriceGroup  $sellingPriceGroup
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (! auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->user()->business_id;

                $spg = SellingPriceGroup::where('business_id', $business_id)->findOrFail($id);
                $spg->delete();

                $output = ['success' => true,
                    'msg' => __('lang_v1.deleted_success'),
                ];
            } catch (\Exception $e) {
                \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

                $output = ['success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }

    /**
     * Show interface to download product price excel file.
     *
     * @return \Illuminate\Http\Response
     */
    public function updateProductPrice(){
        if (! auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        return view('selling_price_group.update_product_price');
    }

    /**
     * Exports selling price group prices for all the products in xls format
     *
     * @return \Illuminate\Http\Response
     */
    public function export()
    {
        $business_id = request()->user()->business_id;
        $price_groups = SellingPriceGroup::where('business_id', $business_id)->active()->get();

        $variations = Variation::join('products as p', 'variations.product_id', '=', 'p.id')
                            ->join('product_variations as pv', 'variations.product_variation_id', '=', 'pv.id')
                            ->where('p.business_id', $business_id)
                            ->whereIn('p.type', ['single', 'variable'])
                            ->select('sub_sku', 'p.name as product_name', 'variations.name as variation_name', 'p.type', 'variations.id', 'pv.name as product_variation_name', 'sell_price_inc_tax')
                            ->with(['group_prices'])
                            ->get();
        $export_data = [];
        foreach ($variations as $variation) {
            $temp = [];
            $temp['product'] = $variation->type == 'single' ? $variation->product_name : $variation->product_name.' - '.$variation->product_variation_name.' - '.$variation->variation_name;
            $temp['sku'] = $variation->sub_sku;
            $temp['Selling Price Including Tax'] = $variation->sell_price_inc_tax;

            foreach ($price_groups as $price_group) {
                $price_group_id = $price_group->id;
                $variation_pg = $variation->group_prices->filter(function ($item) use ($price_group_id) {
                    return $item->price_group_id == $price_group_id;
                });

                $temp[$price_group->name] = $variation_pg->isNotEmpty() ? $variation_pg->first()->price_inc_tax : '';
            }
            $export_data[] = $temp;
        }

        if (ob_get_contents()) {
            ob_end_clean();
        }
        ob_start();

        return collect($export_data)->downloadExcel(
            'product_prices.xlsx',
            null,
            true
        );
    }

    /**
     * Imports the uploaded file to database.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function import(Request $request)
    {
        abort_unless($request->user()->can('product.update'), 403, 'Unauthorized action.');
        $notAllowed = $this->commonUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return $notAllowed;
        }
        $request->validate(['product_group_prices' => 'required|file|mimes:xlsx,xls|max:51200']);

        $file = $request->file('product_group_prices');
        $stagingPath = $file->storeAs(
            'user-import-staging/selling-price-group',
            (string) Str::uuid().'.'.(strtolower($file->getClientOriginalExtension()) ?: 'xlsx'),
            'local'
        );
        abort_unless($stagingPath, 422, 'Workbook harga tidak dapat disimpan ke storage privat.');

        $batch = UserImportBatch::create([
            'business_id' => (int) $request->user()->business_id,
            'created_by' => (int) $request->user()->id,
            'importer_key' => SellingPriceGroupImportBatchService::IMPORTER_KEY,
            'source_key' => (string) Str::uuid(),
            'file_name' => $file->getClientOriginalName(),
            'staging_path' => $stagingPath,
            'status' => 'queued_preview',
            'stage' => 'queued',
            'last_message' => 'Workbook harga masuk antrean import.',
            'heartbeat_at' => now(),
        ]);
        ProcessSellingPriceGroupImportBatch::dispatch((int) $batch->id, (int) $batch->business_id);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'batch_id' => $batch->id,
                'status' => 'queued_preview',
                'message' => 'Workbook masuk antrean. Status dapat dilihat pada riwayat import.',
            ], 202);
        }

        return redirect('update-product-price')->with('status', [
            'success' => 1,
            'msg' => 'Workbook masuk antrean. Status dapat dilihat pada riwayat import.',
        ]);
    }

    public function importHistory(Request $request, SellingPriceGroupImportBatchService $history)
    {
        abort_unless($request->user()->can('product.update'), 403, 'Unauthorized action.');

        return response()->json($history->history($request, (int) $request->user()->business_id));
    }

    public function importHistoryDetail(Request $request, int $batch, SellingPriceGroupImportBatchService $history)
    {
        abort_unless($request->user()->can('product.update'), 403, 'Unauthorized action.');
        $detail = $history->detail($batch, (int) $request->user()->business_id);

        return response()->json($detail);
    }

    public function importHistorySource(Request $request, int $batch, UserImportBatchService $batches)
    {
        abort_unless($request->user()->can('product.update'), 403, 'Unauthorized action.');
        $record = $batches->findForBusiness($batch, (int) $request->user()->business_id, SellingPriceGroupImportBatchService::IMPORTER_KEY);
        abort_unless($record->hasSourceSnapshot(), 404);

        return $batches->workbookDownload($record);
    }

    public function importHistoryRetry(Request $request, int $batch)
    {
        abort_unless($request->user()->can('product.update'), 403, 'Unauthorized action.');
        $record = UserImportBatch::where('business_id', $request->user()->business_id)
            ->where('importer_key', SellingPriceGroupImportBatchService::IMPORTER_KEY)
            ->findOrFail($batch);
        abort_unless($record->status === 'failed' && $record->hasSourceSnapshot(), 422, 'Batch ini tidak dapat dicoba ulang.');
        $record->forceFill(['status' => 'queued_preview', 'stage' => 'queued', 'failed_at' => null, 'completed_at' => null, 'error_message' => null, 'heartbeat_at' => now()])->save();
        ProcessSellingPriceGroupImportBatch::dispatch((int) $record->id, (int) $record->business_id);

        return response()->json(['success' => true, 'status' => 'queued_preview'], 202);
    }

    public function importHistoryRevert(Request $request, int $batch, SellingPriceGroupImportBatchService $history, UserImportBatchService $batches)
    {
        abort_unless($request->user()->can('product.update'), 403, 'Unauthorized action.');
        $record = $batches->findForBusiness($batch, (int) $request->user()->business_id, SellingPriceGroupImportBatchService::IMPORTER_KEY);
        $history->revert($record);

        return response()->json(['success' => true, 'status' => 'reverted']);
    }

    /**
     * Activate/deactivate selling price group.
     */
    public function activateDeactivate($id)
    {
        if (! auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $spg = SellingPriceGroup::where('business_id', $business_id)->find($id);
            $spg->is_active = $spg->is_active == 1 ? 0 : 1;
            $spg->save();

            $output = ['success' => true,
                'msg' => __('lang_v1.updated_success'),
            ];

            return $output;
        }
    }
}
