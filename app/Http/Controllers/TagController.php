<?php

namespace App\Http\Controllers;

use App\SellingPriceGroup;
use App\Utils\ModuleUtil;
use App\BusinessLocation;
use App\Tag;
use App\Transaction;

use DB;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;

class TagController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $moduleUtil;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(ModuleUtil $moduleUtil)
    {
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (! auth()->user()->can('tags.view')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $tags = Tag::where('business_id', $business_id)
                ->select(['name', 'id', 'business_id']);

            return DataTables::of($tags)
                ->addColumn('action', function ($row) {
                    if (! $row->is_default || $row->name == 'Cashier#' . $row->business_id) {
                        $action = '';
                        if (auth()->user()->can('tags.update')) {
                            $action .= '<a href="' . action([\App\Http\Controllers\TagController::class, 'edit'], [$row->id]) . '" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary"><i class="glyphicon glyphicon-edit"></i> ' . __('messages.edit') . '</a>';
                        }
                        if (auth()->user()->can('tags.delete')) {
                            $action .= '&nbsp
                                <button data-href="' . action([\App\Http\Controllers\TagController::class, 'destroy'], [$row->id]) . '" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error delete_role_button"><i class="glyphicon glyphicon-trash"></i> ' . __('messages.delete') . '</button>';
                        }

                        if (auth()->user()->can('tags.view')) {
                            $action .= '&nbsp
                                <a href="' . action([\App\Http\Controllers\TagController::class, 'show'], [$row->id]) . '?view=ledger" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-info"><i class="fas fa-scroll"></i> ' . __('lang_v1.ledger') . '</a>';
                            $action .= '&nbsp
                                <a href="' . action([\Modules\Project\Http\Controllers\ProjectController::class, 'ledger'], ['tag_id' => $row->id]) . '" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-info"><i class="fas fa-tasks"></i> Project Ledger</a>';
                        }
                        if (auth()->user()->can('tags.update') && auth()->user()->can('tags.delete')) {
                            $action .= '&nbsp
                                <a href="' . action([\App\Http\Controllers\TagController::class, 'similar'], [$row->id]) . '" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-warning"><i class="fas fa-code-branch"></i> Merge Similar</a>';
                        }

                        return $action;
                    } else {
                        return '';
                    }
                })
                ->editColumn('name', function ($row) use ($business_id) {
                    $role_name = str_replace('#' . $business_id, '', $row->name);
                    if (in_array($role_name, ['Admin', 'Cashier'])) {
                        $role_name = __('lang_v1.' . $role_name);
                    }

                    return $role_name;
                })
                ->removeColumn('id')
                ->removeColumn('is_default')
                ->removeColumn('business_id')
                ->rawColumns([1])
                ->make(false);
        }

        return view('tags.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (! auth()->user()->can('tags.create')) {
            abort(403, 'Unauthorized action.');
        }


        return view('tags.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('tags.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $role_name = $request->input('name');
            $business_id = $request->session()->get('user.business_id');

            $count = Tag::where('name', $role_name)
                ->where('business_id', $business_id)
                ->count();
            if ($count == 0) {

                $role = Tag::create([
                    'name' => $role_name,
                    'business_id' => $business_id,
                ]);

                $output = [
                    'success' => 1,
                    'msg' => __('Tambah Tag Berhasil'),
                ];
            } else {
                $output = [
                    'success' => 0,
                    'msg' => __('Tag Sudah Ada'),
                ];
            }
        } catch (\Exception $e) {
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect('tags')->with('status', $output);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (! auth()->user()->can('tags.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $tag = Tag::where('business_id', $business_id)->findOrFail($id);
        $tags = Tag::where('business_id', $business_id)->pluck('name', 'id');
        $business_locations = BusinessLocation::forDropdown($business_id, true);
        $view_type = request()->get('view', 'ledger');

        return view('tags.show')->with(compact('tag', 'tags', 'business_locations', 'view_type'));
    }

    /**
     * Shows financial ledger for a selected tag.
     *
     * @return \Illuminate\Http\Response
     */
    public function getLedger(Request $request)
    {
        if (! auth()->user()->can('tags.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $tag_id = $request->input('tag_id');

        $tag = Tag::where('business_id', $business_id)->findOrFail($tag_id);

        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        $location_id = $request->input('location_id');

        $ledger_details = $this->getTagLedgerDetails($business_id, $tag->id, $start_date, $end_date, $location_id);

        $location = null;
        if (! empty($location_id)) {
            $location = BusinessLocation::where('business_id', $business_id)->find($location_id);
        }

        return view('tags.ledger')->with(compact('ledger_details', 'tag', 'location'));
    }

    /**
     * Returns tag based ledger rows and totals.
     *
     * @param  int  $business_id
     * @param  int  $tag_id
     * @param  string|null  $start_date
     * @param  string|null  $end_date
     * @param  int|null  $location_id
     * @return array
     */
    private function getTagLedgerDetails($business_id, $tag_id, $start_date = null, $end_date = null, $location_id = null)
    {
        $sell_tag_match_query = DB::table('transaction_sell_lines as tsl')
            ->join('transaction_sell_line_tags as tslt', 'tslt.transaction_sell_line_id', '=', 'tsl.id')
            ->where('tslt.tag_id', $tag_id)
            ->select(
                'tsl.transaction_id',
                DB::raw('SUM((tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * tsl.unit_price_inc_tax) as matched_sell_total')
            )
            ->groupBy('tsl.transaction_id');

        $query = Transaction::leftJoin('contacts AS c', 'transactions.contact_id', '=', 'c.id')
            ->leftJoin('business_locations AS bl', 'transactions.location_id', '=', 'bl.id')
            ->leftJoin('users AS u', 'transactions.created_by', '=', 'u.id')
            ->leftJoinSub($sell_tag_match_query, 'sell_tag_match', function ($join) {
                $join->on('sell_tag_match.transaction_id', '=', 'transactions.id');
            })
            ->where('transactions.business_id', $business_id)
            ->where(function ($query) use ($tag_id) {
                $query->where(function ($sell_query) {
                    $sell_query->where('transactions.type', 'sell')
                        ->whereNotNull('sell_tag_match.transaction_id');
                })->orWhere(function ($non_sell_query) use ($tag_id) {
                    $non_sell_query->where('transactions.type', '!=', 'sell')
                        ->whereExists(function ($q) use ($tag_id) {
                            $q->select(DB::raw(1))
                                ->from('transaction_tags AS tt')
                                ->whereRaw('tt.transaction_id = transactions.id')
                                ->where('tt.tag_id', $tag_id);
                        });
                });
            })
            ->whereIn('transactions.type', ['sell', 'sell_return', 'purchase', 'purchase_return', 'expense', 'expense_refund'])
            ->where(function ($q) {
                $q->where('transactions.type', '!=', 'sell')
                    ->orWhere('transactions.status', 'final');
            });

        if (! empty($start_date) && ! empty($end_date)) {
            $query->whereDate('transactions.transaction_date', '>=', $start_date)
                ->whereDate('transactions.transaction_date', '<=', $end_date);
        }

        if (! empty($location_id)) {
            $query->where('transactions.location_id', $location_id);
        }

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        $ledger = $query
            ->select(
                'transactions.id',
                'transactions.transaction_date',
                'transactions.type',
                'transactions.invoice_no',
                'transactions.ref_no',
                'transactions.final_total',
                'transactions.payment_status',
                'transactions.additional_notes',
                'transactions.staff_note',
                DB::raw('COALESCE(sell_tag_match.matched_sell_total, transactions.final_total) as matched_total'),
                'bl.name as location_name',
                'c.name as contact_name',
                'c.supplier_business_name',
                DB::raw("CONCAT(COALESCE(u.surname, ''), ' ', COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as added_by"),
                DB::raw("(SELECT GROUP_CONCAT(el.description SEPARATOR '\n') FROM expense_lines el WHERE el.transaction_id = transactions.id) AS expense_descriptions")
            )
            ->orderBy('transactions.transaction_date', 'asc')
            ->orderBy('transactions.id', 'asc')
            ->get()
            ->map(function ($row) {
                $amount_in = 0;
                $amount_out = 0;
                $matched_total = $row->type === 'sell' ? (float) $row->matched_total : (float) $row->final_total;

                if (in_array($row->type, ['sell', 'purchase_return', 'expense_refund'])) {
                    $amount_in = $matched_total;
                }

                if (in_array($row->type, ['sell_return', 'purchase', 'expense'])) {
                    $amount_out = $matched_total;
                }

                $row->amount_in = $amount_in;
                $row->amount_out = $amount_out;
                $row->net_amount = $amount_in - $amount_out;
                $row->ledger_total = $matched_total;
                $row->reference_no = ! empty($row->invoice_no) ? $row->invoice_no : $row->ref_no;
                $row->description = ! empty($row->expense_descriptions) ? $row->expense_descriptions : (! empty($row->additional_notes) ? $row->additional_notes : $row->staff_note);
                $row->related_entity = ! empty($row->supplier_business_name) ? $row->supplier_business_name : $row->contact_name;

                return $row;
            });

        $running_balance = 0;
        $ledger = $ledger->map(function ($row) use (&$running_balance) {
            $running_balance += $row->net_amount;
            $row->running_balance = $running_balance;

            return $row;
        });

        $total_sales = $ledger->where('type', 'sell')->sum('ledger_total') - $ledger->where('type', 'sell_return')->sum('ledger_total');
        $total_purchases = $ledger->where('type', 'purchase')->sum('ledger_total') - $ledger->where('type', 'purchase_return')->sum('ledger_total');
        $total_expenses = $ledger->where('type', 'expense')->sum('ledger_total') - $ledger->where('type', 'expense_refund')->sum('ledger_total');

        return [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'total_sales' => $total_sales,
            'total_purchases' => $total_purchases,
            'total_expenses' => $total_expenses,
            'total_in' => $ledger->sum('amount_in'),
            'total_out' => $ledger->sum('amount_out'),
            'net_result' => $ledger->sum('net_amount'),
            'total_transactions' => $ledger->count(),
            'ledger' => $ledger,
        ];
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (! auth()->user()->can('tags.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $role = Tag::where('business_id', $business_id)
            ->find($id);
        if (! $role) {
            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
            return redirect('tags')->with('status', $output);
        }

        return view('tags.edit')
            ->with(compact('role'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (! auth()->user()->can('tags.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $role_name = $request->input('name');

            $business_id = $request->session()->get('user.business_id');

            $count = Tag::where('name', $role_name)
                ->where('id', '!=', $id)
                ->where('business_id', $business_id)
                ->count();
            if ($count == 0) {
                $role = Tag::findOrFail($id);
                $role->name = $role_name;
                $role->save();

                $output = [
                    'success' => 1,
                    'msg' => __('Tag Berhasil Diupdate'),
                ];
            } else {
                $output = [
                    'success' => 0,
                    'msg' => __('Tag Sudah Ada'),
                ];
            }
        } catch (\Exception $e) {
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect('tags')->with('status', $output);
    }

    /**
     * Show similar tags that can be merged into selected tag.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function similar($id)
    {
        if (! auth()->user()->can('tags.update') || ! auth()->user()->can('tags.delete')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $tag = Tag::where('business_id', $business_id)->findOrFail($id);

        $similar_tags = Tag::where('business_id', $business_id)
            ->where('id', '!=', $tag->id)
            ->get()
            ->map(function ($item) use ($tag) {
                similar_text(strtolower($tag->name), strtolower($item->name), $percentage);
                $item->similarity = round($percentage, 2);

                return $item;
            })
            ->filter(function ($item) {
                return $item->similarity >= 50;
            })
            ->sortByDesc('similarity')
            ->values();

        return view('tags.merge_similar')->with(compact('tag', 'similar_tags'));
    }

    /**
     * Merge checked tags into selected tag.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function merge(Request $request, $id)
    {
        if (! auth()->user()->can('tags.update') || ! auth()->user()->can('tags.delete')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $target_tag = Tag::where('business_id', $business_id)->findOrFail($id);

        $merge_tag_ids = $request->input('merge_tag_ids', []);
        if (! is_array($merge_tag_ids)) {
            $merge_tag_ids = [$merge_tag_ids];
        }

        $merge_tag_ids = array_values(array_unique(array_filter($merge_tag_ids, function ($tag_id) use ($target_tag) {
            return (int) $tag_id !== (int) $target_tag->id;
        })));

        if (empty($merge_tag_ids)) {
            $output = [
                'success' => 0,
                'msg' => 'Pilih minimal satu tag untuk digabung.',
            ];

            return redirect()->back()->with('status', $output);
        }

        $valid_merge_tag_ids = Tag::where('business_id', $business_id)
            ->whereIn('id', $merge_tag_ids)
            ->pluck('id')
            ->toArray();

        if (count($valid_merge_tag_ids) != count($merge_tag_ids)) {
            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];

            return redirect()->back()->with('status', $output);
        }

        try {
            DB::beginTransaction();

            $transaction_ids = DB::table('transaction_tags')
                ->whereIn('tag_id', $valid_merge_tag_ids)
                ->pluck('transaction_id')
                ->unique()
                ->values();

            foreach ($transaction_ids as $transaction_id) {
                $target_exists = DB::table('transaction_tags')
                    ->where('transaction_id', $transaction_id)
                    ->where('tag_id', $target_tag->id)
                    ->exists();

                if (! $target_exists) {
                    DB::table('transaction_tags')->insert([
                        'transaction_id' => $transaction_id,
                        'tag_id' => $target_tag->id,
                    ]);
                }
            }

            DB::table('transaction_tags')
                ->whereIn('tag_id', $valid_merge_tag_ids)
                ->delete();

            if (\Schema::hasTable('transaction_sell_line_tags')) {
                $sell_line_ids = DB::table('transaction_sell_line_tags')
                    ->whereIn('tag_id', $valid_merge_tag_ids)
                    ->pluck('transaction_sell_line_id')
                    ->unique()
                    ->values();

                foreach ($sell_line_ids as $sell_line_id) {
                    $target_exists = DB::table('transaction_sell_line_tags')
                        ->where('transaction_sell_line_id', $sell_line_id)
                        ->where('tag_id', $target_tag->id)
                        ->exists();

                    if (! $target_exists) {
                        DB::table('transaction_sell_line_tags')->insert([
                            'transaction_sell_line_id' => $sell_line_id,
                            'tag_id' => $target_tag->id,
                        ]);
                    }
                }

                DB::table('transaction_sell_line_tags')
                    ->whereIn('tag_id', $valid_merge_tag_ids)
                    ->delete();
            }

            foreach ([
                'asset_tags' => 'asset_id',
                'project_task_tags' => 'project_task_id',
                'project_time_log_tags' => 'project_time_log_id',
            ] as $table => $foreign_key) {
                if (! \Schema::hasTable($table)) {
                    continue;
                }

                $related_ids = DB::table($table)
                    ->whereIn('tag_id', $valid_merge_tag_ids)
                    ->pluck($foreign_key)
                    ->unique()
                    ->values();

                foreach ($related_ids as $related_id) {
                    $target_exists = DB::table($table)
                        ->where($foreign_key, $related_id)
                        ->where('tag_id', $target_tag->id)
                        ->exists();

                    if (! $target_exists) {
                        DB::table($table)->insert([
                            $foreign_key => $related_id,
                            'tag_id' => $target_tag->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::table($table)
                    ->whereIn('tag_id', $valid_merge_tag_ids)
                    ->delete();
            }

            Tag::where('business_id', $business_id)
                ->whereIn('id', $valid_merge_tag_ids)
                ->delete();

            DB::commit();

            $output = [
                'success' => 1,
                'msg' => 'Tag berhasil digabung.',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->action([\App\Http\Controllers\TagController::class, 'similar'], [$target_tag->id])->with('status', $output);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (! auth()->user()->can('tags.delete')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->user()->business_id;

                $role = Tag::where('business_id', $business_id)->find($id);

                $role->delete();
                $output = [
                    'success' => true,
                    'msg' => __('Tag Berhasil Dihapus'),
                ];
            } catch (\Exception $e) {
                \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }

    /**
     * Creates new permission if doesn't exist
     *
     * @param  array  $permissions
     * @return void
     */
    private function __createPermissionIfNotExists($permissions)
    {
        $exising_permissions = Permission::whereIn('name', $permissions)
            ->pluck('name')
            ->toArray();

        $non_existing_permissions = array_diff($permissions, $exising_permissions);

        if (! empty($non_existing_permissions)) {
            foreach ($non_existing_permissions as $new_permission) {
                $time_stamp = \Carbon::now()->toDateTimeString();
                Permission::create([
                    'name' => $new_permission,
                    'guard_name' => 'web',
                ]);
            }
        }
    }
}
