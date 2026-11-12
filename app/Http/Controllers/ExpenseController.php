<?php

namespace App\Http\Controllers;

use App\Account;
use App\AccountTransaction;
use App\BusinessLocation;
use App\Contact;
use App\Events\ExpenseCreatedOrModified;
use App\ExpenseCategory;
use App\TaxRate;
use App\Transaction;
use App\Tag;
use App\User;
use App\Utils\CashRegisterUtil;
use App\Utils\ModuleUtil;
use App\Utils\TransactionUtil;
use App\Utils\TagTransactionUtil;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

use DB;
use Excel;
use App\Utils\AccountTransactionUtil;
use App\Http\Controllers\ImportSalesController;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;
use App\Utils\ProductUtil;
use App\Utils\BusinessUtil;
use App\Jobs\ProcessExpenseImport;
use App\Jobs\ProcessExpensePreview;
use App\Services\ExpenseImportProcessService;
use App\Services\ExpenseImportService;


class ExpenseController extends Controller
{
    protected $AccountTransactionUtil;
    protected $transactionUtil;
    protected $moduleUtil;
    protected $dummyPaymentLine;
    protected $cashRegisterUtil;
    protected $TagTransactionUtil;
    protected $expenseImportService;
    protected $expenseImportProcessService;


    /**
     * Constructor
     *
     * @param  TransactionUtil  $transactionUtil
     * @return void
     */
    public function __construct(TagTransactionUtil $TagTransactionUtil, AccountTransactionUtil $AccountTransactionUtil, TransactionUtil $transactionUtil, ModuleUtil $moduleUtil, CashRegisterUtil $cashRegisterUtil, ExpenseImportService $expenseImportService, ExpenseImportProcessService $expenseImportProcessService)
    {
        $this->transactionUtil = $transactionUtil;
        $this->TagTransactionUtil = $TagTransactionUtil;
        $this->AccountTransactionUtil = $AccountTransactionUtil;
        $this->moduleUtil = $moduleUtil;
        $this->dummyPaymentLine = [
            'method' => 'cash',
            // 'account_id' => auth()->user()->account_id,
            'amount' => 0,
            'note' => '',
            'card_transaction_number' => '',
            'card_number' => '',
            'card_type' => '',
            'card_holder_name' => '',
            'card_month' => '',
            'card_year' => '',
            'card_security' => '',
            'cheque_number' => '',
            'bank_account_number' => '',
            'is_return' => 0,
            'transaction_no' => '',
        ];
        $this->cashRegisterUtil = $cashRegisterUtil;
        $this->expenseImportService = $expenseImportService;
        $this->expenseImportProcessService = $expenseImportProcessService;
    }

    public function revertImport($batch)
    {
        $productUtil = new ProductUtil();
        $businessUtil = new BusinessUtil();
        $transactionUtil = $this->transactionUtil;
        $accountTransactionUtil = $this->AccountTransactionUtil;
        $moduleUtil = $this->moduleUtil;

        $ImportSalesController = new ImportSalesController(
            $productUtil,
            $businessUtil,
            $transactionUtil,
            $accountTransactionUtil,
            $moduleUtil
        );
        return $ImportSalesController->revertSaleImport($batch);
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function normalizeOldOne($query)
    {
        return;
        $data = $query->get();

        DB::beginTransaction();
        foreach ($data as $d) {
            if (empty($d->expense_lines) || count($d->expense_lines) == 0) {
                $line = \App\ExpenseLine::create([
                    'transaction_id' => $d->id,
                    'category_id' => $d->expense_category_id,
                    'sub_category_id' => $d->expense_sub_category_id,
                    'description' => $d->additional_notes,
                    'document' => $d->document,
                    'amount' => $d->final_total,
                ]);
            }
        }
        DB::commit();
    }
    public function index()
    {

        if (! auth()->user()->can('all_expense.access') && ! auth()->user()->can('view_own_expense')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $expenses = Transaction::leftJoin('expense_categories AS ec', 'transactions.expense_category_id', '=', 'ec.id')
                ->leftJoin('expense_categories AS esc', 'transactions.expense_sub_category_id', '=', 'esc.id')
                ->join(
                    'business_locations AS bl',
                    'transactions.location_id',
                    '=',
                    'bl.id'
                )
                ->leftJoin('tax_rates as tr', 'transactions.tax_id', '=', 'tr.id')
                ->leftJoin('users AS U', 'transactions.expense_for', '=', 'U.id')
                ->leftJoin('users AS usr', 'transactions.created_by', '=', 'usr.id')
                ->leftJoin('contacts AS c', 'transactions.contact_id', '=', 'c.id')
                ->leftJoin(
                    'transaction_payments AS TP',
                    'transactions.id',
                    '=',
                    'TP.transaction_id'
                )
                ->where('transactions.business_id', $business_id)
                ->whereIn('transactions.type', ['expense', 'expense_refund'])
                ->select(
                    'transactions.id',
                    'transactions.contact_id as c_id',
                    'transactions.document',
                    'transaction_date',
                    'ref_no',
                    'expense_category_id',
                    'expense_sub_category_id',
                    'ec.name as category',
                    'esc.name as sub_category',
                    DB::raw("(SELECT GROUP_CONCAT(el.description SEPARATOR '\n') FROM expense_lines el WHERE el.transaction_id = transactions.id ) AS deskripsi"),
                    'payment_status',
                    'additional_notes',
                    'transactions.total_before_tax',
                    'final_total',
                    'transactions.is_recurring',
                    'transactions.recur_interval',
                    'transactions.recur_interval_type',
                    'transactions.recur_repetitions',
                    'transactions.subscription_repeat_on',
                    'bl.name as location_name',
                    DB::raw("CONCAT(COALESCE(U.surname, ''),' ',COALESCE(U.first_name, ''),' ',COALESCE(U.last_name,'')) as expense_for"),
                    DB::raw("CONCAT(tr.name ,' (', tr.amount ,' )') as tax"),
                    DB::raw('SUM(TP.amount) as amount_paid'),
                    DB::raw("CONCAT(COALESCE(usr.surname, ''),' ',COALESCE(usr.first_name, ''),' ',COALESCE(usr.last_name,'')) as added_by"),
                    'transactions.recur_parent_id',
                    'c.name as contact_name',
                    'c.contact_id as contact_id',
                    'c.supplier_business_name as supplier_business_name',
                    'transactions.type'
                )
                ->with([
                    'recurring_parent',
                    'expense_lines' => function ($q) {
                        $q->with(['category', 'subCategory']);
                    }
                ])
                ->groupBy('transactions.id');
            $this->normalizeOldOne($expenses);

            //Add condition for expense for,used in sales representative expense report & list of expense
            if (request()->has('expense_for')) {
                $expense_for = request()->get('expense_for');
                if (! empty($expense_for)) {
                    $expenses->where('transactions.expense_for', $expense_for);
                }
            }

            if (request()->has('created_by')) {
                $created_by = request()->get('created_by');
                if (! empty($created_by)) {
                    $expenses->where('transactions.created_by', $created_by);
                }
            }

            if (request()->has('contact_id')) {
                $contact_id = request()->get('contact_id');
                if (! empty($contact_id)) {
                    $expenses->where('transactions.contact_id', $contact_id);
                }
            }

            //Add condition for location,used in sales representative expense report & list of expense
            if (request()->has('location_id')) {
                $location_id = request()->get('location_id');
                if (! empty($location_id)) {
                    $expenses->where('transactions.location_id', $location_id);
                }
            }

            //Add condition for expense category, used in list of expense,
            if (request()->has('expense_category_id')) {
                $expense_category_id = request()->get('expense_category_id');
                if (! empty($expense_category_id)) {
                    $expenses->where('transactions.expense_category_id', $expense_category_id);
                }
            }

            //Add condition for expense sub category, used in list of expense,
            if (request()->has('expense_sub_category_id')) {
                $expense_sub_category_id = request()->get('expense_sub_category_id');
                if (! empty($expense_sub_category_id)) {
                    $expenses->where('transactions.expense_sub_category_id', $expense_sub_category_id);
                }
            }

            //Add condition for start and end date filter, uses in sales representative expense report & list of expense
            if (! empty(request()->start_date) && ! empty(request()->end_date)) {
                $start = request()->start_date;
                $end = request()->end_date;
                $expenses->whereDate('transaction_date', '>=', $start)
                    ->whereDate('transaction_date', '<=', $end);
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $expenses->whereIn('transactions.location_id', $permitted_locations);
            }

            //Add condition for payment status for the list of expense
            if (request()->has('payment_status')) {
                $payment_status = request()->get('payment_status');
                if (! empty($payment_status)) {
                    $expenses->where('transactions.payment_status', $payment_status);
                }
            }

            $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);
            if (! $is_admin && ! auth()->user()->can('all_expense.access')) {
                $user_id = auth()->user()->id;
                $expenses->where(function ($query) use ($user_id) {
                    $query->where('transactions.created_by', $user_id)
                        ->orWhere('transactions.expense_for', $user_id);
                });
            }

            return Datatables::of($expenses)
                ->addColumn('action', '<div class="btn-group">
                        <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  tw-dw-btn-info tw-w-max dropdown-toggle" 
                            data-toggle="dropdown" aria-expanded="false"> @lang("messages.actions")<span class="caret"></span><span class="sr-only">Toggle Dropdown
                                </span>
                        </button>
                    <ul class="dropdown-menu dropdown-menu-left" role="menu">
                    
                    <li><a href="#" data-href="{{action(\'App\Http\Controllers\ExpenseController@show\', [$id])}} " class="btn-modal" data-container=".payment_modal"><i class="fas fa-eye" aria-hidden="true"></i> @lang("messages.view")</a></li>
                    @if(auth()->user()->can("expense.create"))
                        <li><a target="_blank" href="{{action(\'App\Http\Controllers\ExpenseController@duplicate\', [$id])}}"><i class="glyphicon glyphicon-copy"></i> @lang("Duplicate")</a></li>
                    @endif
                    @if(auth()->user()->can("expense.edit"))
                        <li><a target="_blank" href="{{action(\'App\Http\Controllers\ExpenseController@edit\', [$id])}}"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</a></li>
                    @endif
                    @if($document)
                        <li><a href="{{ url(\'uploads/documents/\' . $document)}}" 
                        download=""><i class="fa fa-download" aria-hidden="true"></i> @lang("purchase.download_document")</a></li>
                        @if(isFileImage($document) || isFilePdf($document))
                            <li><a href="#" data-href="{{ url(\'uploads/documents/\' . $document)}}" class="view_uploaded_document"><i class="fas fa-file-image" aria-hidden="true"></i>@lang("lang_v1.view_document")</a></li>
                        @endif
                    @endif
                    @if(auth()->user()->can("expense.delete"))
                        <li>
                        <a href="#" data-href="{{action(\'App\Http\Controllers\ExpenseController@destroy\', [$id])}}" class="delete_expense"><i class="glyphicon glyphicon-trash"></i> @lang("messages.delete")</a></li>
                    @endif
                    <li class="divider"></li> 
                    @if($payment_status != "paid")
                        <li><a href="{{action([\App\Http\Controllers\TransactionPaymentController::class, \'addPayment\'], [$id])}}" class="add_payment_modal"><i class="fas fa-money-bill-alt" aria-hidden="true"></i> @lang("purchase.add_payment")</a></li>
                    @endif
                    <li><a href="{{action([\App\Http\Controllers\TransactionPaymentController::class, \'show\'], [$id])}}" class="view_payment_modal"><i class="fas fa-money-bill-alt" aria-hidden="true" ></i> @lang("purchase.view_payments")</a></li>
                    </ul></div>')
                ->removeColumn('id')
                ->editColumn(
                    'total_before_tax',
                    '<span class="display_currency total-before-tax" data-currency_symbol="true" data-orig-value="@if($type=="expense_refund"){{-1 * $total_before_tax}}@else{{$total_before_tax}}@endif">@if($type=="expense_refund") - @endif @format_currency($total_before_tax)</span>'
                )
                ->editColumn(
                    'final_total',
                    '<span class="display_currency final-total" data-currency_symbol="true" data-orig-value="@if($type=="expense_refund"){{-1 * $final_total}}@else{{$final_total}}@endif">@if($type=="expense_refund") - @endif @format_currency($final_total)</span>'
                )
                ->editColumn(
                    'category',
                    function ($row) {
                        // $category = $row->category;
                        $category_line = $row->expense_lines->pluck('category.name');
                        // $category_line = $category_line->merge([$category]);
                        return $category_line->unique()->filter()->implode(', ');
                    }
                )
                ->editColumn(
                    'sub_category',
                    function ($row) {
                        // $category = $row->category;
                        $category_line = $row->expense_lines->pluck('subCategory.name');
                        // $category_line = $category_line->merge([$category]);
                        return $category_line->unique()->filter()->implode(', ');
                    }
                )
                ->editColumn(
                    'deskripsi',
                    function ($row) {
                        if (empty($row->deskripsi)) {
                            return '-';
                        }
                        $desc = explode("\n", $row->deskripsi);
                        $desc = '<ul style="padding-left: 15px; margin:0;list-style:outside"> <li class="text-nowrap">' . implode('</li><li>', $desc) . '</li></ul>';
                        return $desc;
                    }
                )
                ->filterColumn('deskripsi', function ($query, $keyword) {
                    $query->whereRaw(
                        "(SELECT GROUP_CONCAT(el.description SEPARATOR ' ') 
                          FROM expense_lines el 
                          WHERE el.transaction_id = transactions.id) LIKE ?",
                        ["%{$keyword}%"]
                    );
                })
                ->editColumn('tags', function ($row) {

                    return (string) view('purchase.partials.tags', ['tags' => $row->tags]);
                })
                ->filterColumn('tags', function ($query, $keyword) {
                    $query->whereHas('tags', function ($q) use ($keyword) {
                        $q->where('name', 'like', "%{$keyword}%");
                    });
                })
                ->orderColumn('tags', function ($query, $order) {
                    $query->leftJoin('transaction_tags', 'transactions.id', '=', 'transaction_tags.transaction_id')
                        ->leftJoin('tags', 'tags.id', '=', 'transaction_tags.tag_id')
                        ->groupBy('transactions.id') // penting biar nggak duplikat row
                        ->orderByRaw("GROUP_CONCAT(tags.name ORDER BY tags.name ASC SEPARATOR ', ') {$order}");
                })
                ->editColumn(
                    'contact_name',
                    function ($row) {
                        if (!empty($row->c_id)) {
                            return '<span class="">'
                                . (!empty($row->contact_name) ? $row->contact_name . ' / ' : '')
                                . (!empty($row->contact_id) ? '(' . $row->contact_id . ')' : '')
                                . (!empty($row->supplier_business_name) ? '/' . $row->supplier_business_name  : '') . '</span>';
                        }
                    }
                )
                ->editColumn('transaction_date', '{{@format_datetime($transaction_date)}}')
                ->editColumn(
                    'payment_status',
                    '<a href="{{ action([\App\Http\Controllers\TransactionPaymentController::class, \'show\'], [$id])}}" class="view_payment_modal payment-status" data-orig-value="{{$payment_status}}" data-status-name="{{__(\'lang_v1.\' . $payment_status)}}"><span class="label @payment_status($payment_status)">{{__(\'lang_v1.\' . $payment_status)}}
                        </span></a>'
                )
                ->addColumn('payment_due', function ($row) {
                    $due = $row->final_total - $row->amount_paid;

                    if ($row->type == 'expense_refund') {
                        $due = -1 * $due;
                    }

                    return '<span class="display_currency payment_due" data-currency_symbol="true" data-orig-value="' . $due . '">' . $this->transactionUtil->num_f($due, true) . '</span>';
                })
                ->addColumn('recur_details', function ($row) {
                    $details = '<small>';
                    if ($row->is_recurring == 1) {
                        $type = $row->recur_interval == 1 ? Str::singular(__('lang_v1.' . $row->recur_interval_type)) : __('lang_v1.' . $row->recur_interval_type);
                        $recur_interval = $row->recur_interval . $type;

                        $details .= __('lang_v1.recur_interval') . ': ' . $recur_interval;
                        if (! empty($row->recur_repetitions)) {
                            $details .= ', ' . __('lang_v1.no_of_repetitions') . ': ' . $row->recur_repetitions;
                        }
                        if ($row->recur_interval_type == 'months' && ! empty($row->subscription_repeat_on)) {
                            $details .= '<br><small class="text-muted">' .
                                __('lang_v1.repeat_on') . ': ' . str_ordinal($row->subscription_repeat_on);
                        }
                    } elseif (! empty($row->recur_parent_id)) {
                        $details .= __('lang_v1.recurred_from') . ': ' . $row->recurring_parent->ref_no;
                    }
                    $details .= '</small>';

                    return $details;
                })
                ->editColumn('ref_no', function ($row) {
                    $ref_no = $row->ref_no;
                    if (! empty($row->is_recurring)) {
                        $ref_no .= ' &nbsp;<small class="label bg-red label-round no-print" title="' . __('lang_v1.recurring_expense') . '"><i class="fas fa-recycle"></i></small>';
                    }

                    if (! empty($row->recur_parent_id)) {
                        $ref_no .= ' &nbsp;<small class="label bg-info label-round no-print" title="' . __('lang_v1.generated_recurring_expense') . '"><i class="fas fa-recycle"></i></small>';
                    }

                    if ($row->type == 'expense_refund') {
                        $ref_no .= ' &nbsp;<small class="label bg-gray">' . __('lang_v1.refund') . '</small>';
                    }

                    return $ref_no;
                })
                ->rawColumns(['deskripsi', 'tags', 'total_before_tax', 'final_total', 'action', 'payment_status', 'contact_name', 'payment_due', 'ref_no', 'recur_details'])
                ->make(true);
        }

        $business_id = request()->session()->get('user.business_id');

        $categories = ExpenseCategory::where('business_id', $business_id)
            ->whereNull('parent_id')
            ->pluck('name', 'id');

        $users = User::forDropdown($business_id, false, true, true);

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        $contacts = Contact::contactDropdown($business_id, false, false);

        $sub_categories = ExpenseCategory::where('business_id', $business_id)
            ->whereNotNull('parent_id')
            ->pluck('name', 'id')
            ->toArray();

        return view('expense.index')
            ->with(compact('categories', 'business_locations', 'users', 'contacts', 'sub_categories'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (! auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse(action([\App\Http\Controllers\ExpenseController::class, 'index']));
        }

        $business_locations = BusinessLocation::forDropdown($business_id, false, true);

        $bl_attributes = $business_locations['attributes'];
        $business_locations = $business_locations['locations'];

        $expense_categories = ExpenseCategory::where('business_id', $business_id)
            ->whereNull('parent_id')
            ->pluck('name', 'id');

        $users = User::forDropdown($business_id, true, true);

        $taxes = TaxRate::forBusinessDropdown($business_id, true, true);

        $this->dummyPaymentLine['account_id'] = auth()->user()->account_id;

        $payment_line = $this->dummyPaymentLine;

        $payment_types = $this->transactionUtil->payment_types(null, false, $business_id);

        $contacts = Contact::contactDropdown($business_id, false, false);


        //Accounts
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false, true);
        }
        if (request()->ajax()) {
            return view('expense.add_expense_modal')
                ->with(compact('expense_categories', 'business_locations', 'users', 'taxes', 'payment_line', 'payment_types', 'accounts', 'bl_attributes', 'contacts'));
        }

        return view('expense.create')
            ->with(compact('expense_categories', 'business_locations', 'users', 'taxes', 'payment_line', 'payment_types', 'accounts', 'bl_attributes', 'contacts'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');

            //Check if subscribed or not
            if (! $this->moduleUtil->isSubscribed($business_id)) {
                return $this->moduleUtil->expiredResponse(action([\App\Http\Controllers\ExpenseController::class, 'index']));
            }

            //Validate document size
            $request->validate([
                'document' => 'file|max:' . (config('constants.document_size_limit') / 1000),
            ]);

            $user_id = $request->session()->get('user.id');
            // $request->merge([
            //     'final_total' => $expenseLine->sum('amount'),
            // ]);


            DB::beginTransaction();

            $expense = $this->transactionUtil->createExpense($request, $business_id, $user_id);

            if (request()->ajax()) {
                $payments = ! empty($request->input('payment')) ? $request->input('payment') : [];
                $this->cashRegisterUtil->addSellPayments($expense, $payments);
            }
            $tag_ids = $request->get('tag');
            $this->TagTransactionUtil->updateTagTransaction($expense->id, $tag_ids);

            $this->transactionUtil->activityLog($expense, 'added');

            event(new ExpenseCreatedOrModified($expense));

            DB::commit();

            $output = [
                'success' => 1,
                'msg' => __('expense.expense_add_success'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        if (request()->ajax()) {
            return $output;
        }

        return redirect('expenses')->with('status', $output);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse(action([\App\Http\Controllers\ExpenseController::class, 'index']));
        }

        $business_locations = BusinessLocation::forDropdown($business_id);

        $expense_categories = ExpenseCategory::where('business_id', $business_id)
            ->whereNull('parent_id')
            ->pluck('name', 'id');
        $expense = Transaction::where('business_id', $business_id)
            ->where('id', $id)
            ->with(['expense_lines' => function ($q) {
                $q->with(['category', 'subCategory']);
            }, 'media.uploaded_by_user'])
            ->first();

        $users = User::forDropdown($business_id, true, true);

        $taxes = TaxRate::forBusinessDropdown($business_id, true, true);

        $contacts = Contact::contactDropdown($business_id, false, false);
        $payment_line = null;
        $payment_types = $this->transactionUtil->payment_types($expense->location_id, true);


        //Sub-category
        $sub_categories = [];

        if (!empty($expense->expense_category_id)) {
            $sub_categories = ExpenseCategory::where('business_id', $business_id)
                ->where('parent_id', $expense->expense_category_id)
                ->pluck('name', 'id')
                ->toArray();
        }

        //Accounts
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false, true);
        }
        $details = '<small>';
        $row = $expense;
        if ($row->is_recurring == 1) {
            $type = $row->recur_interval == 1 ? Str::singular(__('lang_v1.' . $row->recur_interval_type)) : __('lang_v1.' . $row->recur_interval_type);
            $recur_interval = $row->recur_interval . $type;

            $details .= __('lang_v1.recur_interval') . ': ' . $recur_interval;
            if (! empty($row->recur_repetitions)) {
                $details .= ', ' . __('lang_v1.no_of_repetitions') . ': ' . $row->recur_repetitions;
            }
            if ($row->recur_interval_type == 'months' && ! empty($row->subscription_repeat_on)) {
                $details .= '<br><small class="text-muted">' .
                    __('lang_v1.repeat_on') . ': ' . str_ordinal($row->subscription_repeat_on);
            }
        } elseif (! empty($row->recur_parent_id)) {
            $details .= __('lang_v1.recurred_from') . ': ' . $row->recurring_parent->ref_no;
        }
        $details .= '</small>';

        $expense->recur_details = $details;
        $ref_no = $row->ref_no;
        if (! empty($row->is_recurring)) {
            $ref_no .= ' &nbsp;<small class="label bg-red label-round no-print" title="' . __('lang_v1.recurring_expense') . '"><i class="fas fa-recycle"></i></small>';
        }

        if (! empty($row->recur_parent_id)) {
            $ref_no .= ' &nbsp;<small class="label bg-info label-round no-print" title="' . __('lang_v1.generated_recurring_expense') . '"><i class="fas fa-recycle"></i></small>';
        }

        if ($row->type == 'expense_refund') {
            $ref_no .= ' &nbsp;<small class="label bg-gray">' . __('lang_v1.refund') . '</small>';
        }
        $expense->ref_no = $ref_no;


        return view('expense.show')
            ->with(compact('accounts', 'payment_line', 'payment_types', 'expense', 'expense_categories', 'business_locations', 'users', 'taxes', 'contacts', 'sub_categories'));
    }
    public function duplicate($id)
    {
        // dd(request()->route()->getName());
        return $this->edit($id);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (! auth()->user()->can('expense.edit')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse(action([\App\Http\Controllers\ExpenseController::class, 'index']));
        }

        $business_locations = BusinessLocation::forDropdown($business_id, false, true);
        $bl_attributes = $business_locations['attributes'];
        $business_locations = $business_locations['locations'];


        $expense_categories = ExpenseCategory::where('business_id', $business_id)
            ->whereNull('parent_id')
            ->pluck('name', 'id');
        $expense_subcategories = ExpenseCategory::where('business_id', $business_id)
            ->whereNotNull('parent_id')
            ->pluck('name', 'id');
        $expense = Transaction::where('business_id', $business_id)
            ->where('id', $id)
            ->with(['expense_lines', 'tags'])
            ->first();

        $users = User::forDropdown($business_id, true, true);

        $taxes = TaxRate::forBusinessDropdown($business_id, true, true);

        $contacts = Contact::contactDropdown($business_id, false, false);
        $payment_line =   $expense;
        $payment_types = null;
        $this->dummyPaymentLine['account_id'] = auth()->user()->account_id;

        // if (request()->route()->getName() == 'expenses.duplicate') {

        $payment_types = $this->transactionUtil->payment_types($expense->location_id, false, $business_id);

        $payment_line = $this->transactionUtil->getPaymentDetails($id);
        //If no payment lines found then add dummy payment line.
        $this->dummyPaymentLine['account_id'] = auth()->user()->account_id;

        if (empty($payment_line)) {
            $payment_line = $this->dummyPaymentLine;
            // $payment_line['account_id'] = auth()->user()->account_id;
        } else {
            $payment_line = $payment_line[0];
        }

        // }
        //Sub-category
        $sub_categories = [];

        if (!empty($expense->expense_category_id)) {
            $sub_categories = ExpenseCategory::where('business_id', $business_id)
                ->where('parent_id', $expense->expense_category_id)
                ->pluck('name', 'id')
                ->toArray();
        }

        //Accounts
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false, true);
        }
        $expense->tags = $expense->tags->pluck('name', 'id')->toArray();
        $expense->tagsids = array_keys($expense->tags);
        return view('expense.edit')
            ->with(compact('expense_subcategories', 'bl_attributes', 'accounts', 'payment_line', 'payment_types', 'expense', 'expense_categories', 'business_locations', 'users', 'taxes', 'contacts', 'sub_categories'));
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
        if (!auth()->user()->can('expense.edit')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            //Validate document size
            $request->validate([
                'document' => 'file|max:' . (config('constants.document_size_limit') / 1000),
            ]);

            $business_id = $request->session()->get('user.business_id');

            //Check if subscribed or not
            if (!$this->moduleUtil->isSubscribed($business_id)) {
                return $this->moduleUtil->expiredResponse(action([\App\Http\Controllers\ExpenseController::class, 'index']));
            }

            $expense = $this->transactionUtil->updateExpense($request, $id, $business_id);
            $tag_ids = $request->get('tag');
            $this->TagTransactionUtil->updateTagTransaction($id, $tag_ids);

            $this->transactionUtil->activityLog($expense, 'edited');

            event(new ExpenseCreatedOrModified($expense));

            $output = [
                'success' => 1,
                'msg' => __('expense.expense_update_success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect('expenses')->with('status', $output);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('expense.delete')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');

                $expense = Transaction::where('business_id', $business_id)
                    ->where(function ($q) {
                        $q->where('type', 'expense')
                            ->orWhere('type', 'expense_refund');
                    })
                    ->where('id', $id)
                    ->first();

                //Delete Cash register transactions
                $expense->cash_register_payments()->delete();

                $expense->delete();

                //Delete account transactions
                AccountTransaction::where('transaction_id', $expense->id)->delete();

                event(new ExpenseCreatedOrModified($expense, true));

                $output = [
                    'success' => true,
                    'msg' => __('expense.expense_delete_success'),
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

    public function importExpense()
    {

        if (!auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $payment_types = $this->transactionUtil->payment_types(null, false, $business_id);
        $expense_import_tax_options = TaxRate::where('business_id', $business_id)
            ->orderBy('is_tax_group')
            ->orderBy('name')
            ->get(['id', 'name', 'amount', 'is_tax_group'])
            ->map(function ($tax) {
                $amount = is_numeric($tax->amount)
                    ? rtrim(rtrim(number_format((float) $tax->amount, 4, '.', ''), '0'), '.')
                    : null;
                $label = ($tax->is_tax_group ? 'Grup: ' : '').$tax->name;
                if (! $tax->is_tax_group && $amount !== null) {
                    $label .= ' ('.$amount.'%)';
                }

                return [
                    'id' => (int) $tax->id,
                    'label' => $label,
                ];
            })
            ->values()
            ->all();
        $zip_loaded = extension_loaded('zip') ? true : false;
        $imported_sales = Transaction::where('business_id', $business_id)
            ->where('type', 'expense')
            ->whereNotNull('import_batch')
            ->with(['sales_person'])
            ->select('id', 'import_batch', 'import_time', 'import_file', 'invoice_no', 'ref_no', 'created_by')
            ->orderBy('import_batch', 'desc')
            ->get();

        $imported_sales_array = [];
        foreach ($imported_sales as $sale) {
            $imported_sales_array[$sale->import_batch]['import_time'] = $sale->import_time;
            $imported_sales_array[$sale->import_batch]['import_file'] = $sale->import_file;
            $imported_sales_array[$sale->import_batch]['created_by'] = optional($sale->sales_person)->user_full_name ?: '-';
            $imported_sales_array[$sale->import_batch]['invoices'][] = $sale->ref_no;
        }

        $expense_import_batches = Schema::hasTable('expense_import_batches')
            ? DB::table('expense_import_batches')->where('business_id', $business_id)->orderByDesc('created_at')->limit(25)->get()
            : collect();

        //Check if zip extension it loaded or not.
        if ($zip_loaded === false) {
            $output = [
                'success' => 0,
                'msg' => 'Please install/enable PHP Zip archive for import',
            ];

            return view('expense.import', compact('payment_types', 'imported_sales_array', 'expense_import_batches', 'expense_import_tax_options'))
                ->with('notification', $output);
        } else {
            return view('expense.import', compact('payment_types', 'imported_sales_array', 'expense_import_batches', 'expense_import_tax_options'));
        }
    }

    /**
     * Accept an Expense workbook and create a durable preview batch. The
     * actual parsing is performed by the queue worker so the browser is not
     * held open while a large workbook is inspected.
     */
    public function storeExpenseImport(Request $request)
    {
        if (! auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'expense_csv' => ['required', 'file', 'mimes:xls,xlsx,csv', 'max:51200'],
        ]);
        $businessId = (int) $request->session()->get('user.business_id');
        $batch = $this->expenseImportService->storeUploadedFile(
            $request->file('expense_csv'),
            $businessId,
            (int) $request->session()->get('user.id')
        );
        ProcessExpensePreview::dispatch($batch->id, $businessId);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'batch_id' => $batch->id,
                'status_url' => route('expenses.import.status', $batch->id),
                'preview_url' => route('expenses.import.preview', $batch->id),
                'message' => 'Upload diterima dan preview sedang diproses.',
            ], 202);
        }

        return redirect()->route('expenses.import')->with('status', [
            'success' => true,
            'msg' => 'Upload diterima dan preview sedang diproses.',
        ]);
    }

    public function expenseImportStatus(Request $request, string $batchId)
    {
        $businessId = $this->authorizeExpenseImport($request);
        return response()->json($this->expenseImportProcessService->status($batchId, $businessId));
    }

    public function expenseImportPreviewSummary(Request $request, string $batchId)
    {
        $businessId = $this->authorizeExpenseImport($request);
        return response()->json([
            'summary' => $this->expenseImportService->summary($batchId, $businessId),
        ]);
    }

    public function expenseImportPreviewRows(Request $request, string $batchId)
    {
        $businessId = $this->authorizeExpenseImport($request);
        $sortColumns = ['source_row', 'group_key', 'group_key', 'group_key', 'source_row', 'status', 'status', 'source_row'];
        $sortIndex = (int) $request->input('order.0.column', 0);
        $result = $this->expenseImportService->previewRows(
            $batchId,
            $businessId,
            (int) $request->input('start', 0),
            (int) $request->input('length', 25),
            (string) data_get($request->input('search'), 'value', ''),
            $request->input('status'),
            $sortColumns[$sortIndex] ?? 'source_row',
            (string) $request->input('order.0.dir', 'asc')
        );

        return response()->json([
            'draw' => (int) $request->input('draw', 0),
            'recordsTotal' => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data' => $result['data'],
            'summary' => $this->expenseImportService->summary($batchId, $businessId),
        ]);
    }

    public function expenseImportRefreshPreview(Request $request, string $batchId)
    {
        $businessId = $this->authorizeExpenseImport($request);
        $batch = DB::table('expense_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->first();

        abort_if(! $batch, 404, 'Batch import Expense tidak ditemukan.');

        $state = $batch->process_state ?: 'completed';
        $operation = $batch->operation ?: 'preview';
        abort_unless(
            $batch->status === 'preview'
                && $operation === 'preview'
                && in_array($state, ['completed', 'failed'], true),
            409,
            'Preview hanya dapat diperbarui sebelum proses import dimulai dan saat tidak ada proses yang berjalan.'
        );

        $this->expenseImportProcessService->queued($batchId, $businessId, 'preview');
        ProcessExpensePreview::dispatch($batchId, $businessId);

        return response()->json([
            'success' => true,
            'batch_id' => $batchId,
            'status_url' => route('expenses.import.status', $batchId),
            'message' => 'Preview dan kandidat Journal sedang diperbarui dari workbook tersimpan.',
        ], 202);
    }

    public function expenseImportUpdateDate(Request $request, string $batchId, int $rowId)
    {
        $businessId = $this->authorizeExpenseImport($request);
        $validated = $request->validate([
            'field' => ['required', 'in:expense_date,paid_on_date'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $result = $this->expenseImportService->updateDate($batchId, $rowId, $businessId, $validated['field'], $validated['date']);

        return response()->json(['success' => true, 'summary' => $result, 'message' => 'Tanggal diperbarui dan preview dihitung ulang.']);
    }

    public function expenseImportUpdateTax(Request $request, string $batchId, int $rowId)
    {
        $businessId = $this->authorizeExpenseImport($request);
        $validated = $request->validate([
            'tax_rate_id' => ['required', 'integer', 'min:1'],
        ]);
        $result = $this->expenseImportService->updateTax(
            $batchId,
            $rowId,
            $businessId,
            (int) $validated['tax_rate_id']
        );

        return response()->json(['success' => true, 'summary' => $result, 'message' => 'TaxRate dipetakan dan preview dihitung ulang.']);
    }

    public function expenseImportUpdateJournalMatch(Request $request, string $batchId, int $rowId)
    {
        $businessId = $this->authorizeExpenseImport($request);
        $validated = $request->validate([
            'journal_mapping_id' => ['required', 'integer', 'min:1'],
        ]);
        $result = $this->expenseImportService->updateJournalMatch(
            $batchId,
            $rowId,
            $businessId,
            (int) $validated['journal_mapping_id']
        );

        return response()->json(['success' => true, 'summary' => $result, 'message' => 'Posting Journal dipilih dan preview dihitung ulang.']);
    }

    public function expenseImportUpdateExistingExpenseAction(Request $request, string $batchId, int $rowId)
    {
        $businessId = $this->authorizeExpenseImport($request);
        $validated = $request->validate([
            'action' => ['required', 'regex:/^(skip|new|merge:[1-9][0-9]*)$/'],
        ]);
        $result = $this->expenseImportService->updateExistingExpenseAction(
            $batchId,
            $rowId,
            $businessId,
            (string) $validated['action']
        );

        return response()->json(['success' => true, 'summary' => $result, 'message' => 'Pilihan transaksi untuk referensi ini disimpan dan preview dihitung ulang.']);
    }

    public function expenseImportConfirm(Request $request, string $batchId)
    {
        $businessId = $this->authorizeExpenseImport($request);
        $refreshPreview = false;
        $batch = DB::transaction(function () use ($batchId, $businessId, &$refreshPreview) {
            $batch = DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->lockForUpdate()->first();
            abort_if(! $batch, 404, 'Batch import Expense tidak ditemukan.');
            $processState = $batch->process_state ?: 'completed';
            // A failed import is safe to retry because the import service wraps
            // all transaction writes in one database transaction. This also
            // makes batches that failed before the worker bug was fixed
            // recoverable from the existing preview screen.
            $canRetryImport = $processState === 'failed' && $batch->operation === 'import';
            abort_unless($batch->status === 'preview' && ($processState === 'completed' || $canRetryImport), 409, 'Preview belum selesai atau sudah diproses.');
            if ($this->expenseImportService->needsJournalCandidateRefresh($batchId, $businessId)) {
                $refreshPreview = true;

                return $batch;
            }

            $summary = $this->expenseImportService->summary($batchId, $businessId);
            abort_unless($summary['importable'] > 0, 422, 'Tidak ada baris valid untuk diimpor. Perbaiki error terlebih dahulu.');
            DB::table('expense_import_batches')->where('id', $batchId)->update([
                'operation' => 'import', 'process_state' => 'queued', 'stage' => 'uploading',
                // Progress is reported per generated transaction (group), not
                // per spreadsheet line. Multiple spreadsheet rows sharing a
                // reference become one expense transaction.
                'progress_current' => 0, 'progress_total' => max(1, (int) ($summary['groups'] ?? $summary['importable'])), 'progress_percent' => 0,
                'progress_message' => 'Menunggu proses import.', 'error_message' => null,
                'failed_at' => null, 'completed_at' => null, 'started_at' => null,
                'cancel_requested_at' => null, 'heartbeat_at' => now(), 'updated_at' => now(),
            ]);
            return $batch;
        });

        if ($refreshPreview) {
            $this->expenseImportProcessService->queued($batch->id, $businessId, 'preview');
            ProcessExpensePreview::dispatch($batch->id, $businessId);

            return response()->json([
                'success' => true,
                'batch_id' => $batch->id,
                'operation' => 'preview',
                'status_url' => route('expenses.import.status', $batch->id),
                'message' => 'Ditemukan satu posting Journal yang siap. Preview diperbarui otomatis; periksa hasilnya lalu konfirmasi import kembali.',
            ], 202);
        }

        ProcessExpenseImport::dispatch($batch->id, $businessId, (int) $request->session()->get('user.id'));

        return response()->json([
            'success' => true,
            'batch_id' => $batch->id,
            'status_url' => route('expenses.import.status', $batch->id),
            'message' => 'Import diterima dan sedang diproses.',
        ], 202);
    }

    public function expenseImportCancel(Request $request, string $batchId)
    {
        $businessId = $this->authorizeExpenseImport($request);
        $cancelled = $this->expenseImportProcessService->cancel($batchId, $businessId);
        return response()->json([
            'success' => $cancelled,
            'message' => $cancelled ? 'Proses dibatalkan.' : 'Proses sudah berjalan atau selesai.',
            'status' => $this->expenseImportProcessService->status($batchId, $businessId),
        ], $cancelled ? 200 : 409);
    }

    private function authorizeExpenseImport(Request $request): int
    {
        abort_unless(auth()->user()->can('expense.add'), 403, 'Unauthorized action.');
        return (int) $request->session()->get('user.business_id');
    }

    public function storeExpenseImportLegacy(Request $request)
    {

        try {
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', -1);

            $business_id = $request->session()->get('user.business_id');
            $user_id = $request->session()->get('user.id');
            $import_batch = Transaction::where('business_id', $business_id)->max('import_batch');
            $now = \Carbon::now()->toDateTimeString();
            if (empty($import_batch)) {
                $import_batch = 1;
            } else {
                $import_batch = $import_batch + 1;
            }


            if ($request->hasFile('expense_csv')) {
                $file = $request->file('expense_csv');
                $filename = $file->getClientOriginalName();
                $parsed_array = Excel::toArray([], $file);

                $is_valid = true;
                $error_msg = '';

                //Remove header row
                $header = $parsed_array[0][0];
                $imported_data = array_splice($parsed_array[0], 1);

                $expense_array = [];
                DB::beginTransaction();
                $imported_data = collect($imported_data)->filter(function ($value) {
                    return !empty(trim($value[4]));
                })->values()->map(function ($row) use ($header, $import_batch, $now, $filename) {
                    $item = array_combine($header, $row);
                    $item['import_batch'] = $import_batch;
                    $item['import_time'] = $now;
                    $item['import_file'] = $filename;
                    return $item;
                });
                $imported_data = $imported_data->groupBy('REFERENCE NO')->map(function ($row) use ($header) {
                    $item = $row->first();
                  
                    $item['TOTAL AMOUNT'] = $row->sum('TOTAL AMOUNT');
                    $item['PAID AMOUNT'] = $row->sum('PAID AMOUNT');
                    $item['lines'] = $row->toArray();
                    return $item;
                });

                $lines = [];

                foreach ($imported_data as $key => $value) {

                    $row_no = $key;
                    $expense_array['business_id'] = $business_id;
                    $expense_array['created_by'] = $user_id;

                    if (!empty(trim($value['LOCATION']))) {
                        $location_name = trim($value['LOCATION']);
                        $location = BusinessLocation::where('name', $location_name)
                            ->where('business_id', $business_id)
                            ->first();
                        if (!empty($location)) {
                            $expense_array['location_id'] = $location->id;
                        } else {
                            $is_valid = false;
                            $error_msg = "No location with name '$location_name' found in row no. $row_no";
                            break;
                        }
                    } else {
                        $location = BusinessLocation::where('business_id', $business_id)->first();
                        $expense_array['location_id'] = $location->id;
                    }

                    //Check if category exists else create new
                    $expense_array['expense_category_id']=null;
                    $category_name = trim($value['CATEGORY']);
                    if (!empty($category_name)) {
                        $category = ExpenseCategory::where('business_id', $business_id)
                            ->where('name', $category_name)
                            ->first();
                        if (empty($category)) {
                            $category = new ExpenseCategory;
                            $category->business_id = $business_id;
                            $category->name = $category_name;
                            $category->save();
                        }
                        $expense_array['expense_category_id'] = $category->id;
                    }

                    //  Add Sub-Category
                    $sub_category_name = trim($value['SUB-CATEGORY']);
                    $expense_array['expense_sub_category_id']= null;
                    if (!empty($sub_category_name)) {
                        $sub_category = ExpenseCategory::where('business_id', $business_id)
                            ->where('name', $sub_category_name)
                            ->where('parent_id', $category->id)
                            ->first();
                        if (empty($sub_category)) {
                            $sub_category = new ExpenseCategory;
                            $sub_category->business_id = $business_id;
                            $sub_category->name = $sub_category_name;
                            $sub_category->parent_id = $category->id;
                            $sub_category->save();
                        }
                        $expense_array['expense_sub_category_id'] = $sub_category->id;
                    }

                    //Update reference count
                    $ref_count = $this->transactionUtil->setAndGetReferenceCount('expense', $business_id);
                    //Generate reference number
                    $ref_no = trim($value['REFERENCE NO']);
                    if (empty($ref_no)) {
                        $expense_array['ref_no'] = $this->transactionUtil->generateReferenceNumber('expense', $ref_count, $business_id);
                    } else {
                        $expense_array['ref_no'] = $ref_no;
                    }

                    $date = trim($value['EXPENSE DATE']);

                    //check if date is correct
                    if (!empty($date)) {
                        try {
                            \Carbon::parse($date);
                        } catch (\Exception $e) {
                            throw new \Exception(__('lang_v1.invalid_date_format_at', ['row' => $row_no]));
                        }
                    }

                    if (!empty($date)) {
                        $expense_array['transaction_date'] = $date;
                    } else {
                        $expense_array['transaction_date'] = \Carbon::now()->toDateTimeString();
                    }

                    $user_value = trim($value['EXPENSE FOR']);

                    if (!empty($user_value)) {
                        $user = User::where('email', $user_value)
                            ->orWhere('username', $user_value)
                            ->orWhere('surname', $user_value)
                            ->orWhere('first_name', $user_value)
                            ->orWhere('last_name', $user_value)
                            ->orWhereRaw("CONCAT(surname, ' ', first_name, ' ', last_name) = ?", [$user_value])
                            ->orWhereRaw("CONCAT(surname, ' ', first_name) = ?", [$user_value])
                            ->orWhereRaw("TRIM(CONCAT(surname, ' ', first_name, ' ', COALESCE(last_name, ''))) = ?", [$user_value])
                            ->first();
                            
                        if (!empty($user)) {
                            $expense_array['expense_for'] = $user->id;
                        } else {
                           $user= User::create([
                                'email' => $user_value . '_' . Str::random(5) . '@example.com',
                                'username' => $user_value . '_' . Str::random(5),
                                'first_name' => $user_value,
                                'last_name' => ' ',
                                'password' => Hash::make(Str::random(8)),
                                'business_id' => $business_id,
                                'created_by' => $user_id,
                            ]);
                            $expense_array['expense_for'] = $user->id;
                        }
                    }

                    $contact_id = trim($value['EXPENSE FOR CONTACT ID']);
                    if (!empty($contact_id)) {
                        $contact = Contact::where('business_id', $business_id)
                            ->where(function ($query) use ($contact_id) {
                                $query->where('supplier_business_name',  $contact_id)
                                    ->orWhere('name',  $contact_id);
                            })
                            ->first();
                    }
                    if (empty($contact)) {
                        $contact = Contact::create([
                            'business_id' => $business_id,
                            'type' => 'both',
                            'name' =>  $contact_id,
                            'supplier_business_name' =>  $contact_id,
                            'email' => ' ',
                            'mobile' => ' ',
                            'created_by' => auth()->user()->id,
                        ]);
                    }
                    $contact_id =  $contact->id;
                    // if ($expense_array['ref_no'] == '17619') {
                    //     dd();
                    // }



                    $note = trim($value['EXPENSE NOTE']);
                    if (!empty($note)) {
                        $expense_array['additional_notes'] = $note;
                    }

                    $final_total = trim($value['TOTAL AMOUNT']);

                    if (!empty($final_total)) {
                        $expense_array['final_total'] = $this->transactionUtil->num_uf($final_total);
                    } else {
                        $is_valid = false;
                        $error_msg = "Amount not found in row no. $row_no";
                        break;
                    }

                    $expense_array['total_before_tax'] = $expense_array['final_total'];

                    //Add Tax
                    $tax_name = trim($value['APPLICABLE TAX']);
                    $tax_amount = 0;
                    if (!empty($tax_name)) {
                        $tax = TaxRate::where('business_id', $business_id)
                            ->where('name', $tax_name)
                            ->first();
                        if (!empty($tax)) {
                            $expense_array['tax_id'] = $tax->id;
                            $tax_amount = $tax->amount;
                            $expense_array['total_before_tax'] = $this->transactionUtil->calc_percentage_base($expense_array['final_total'], $tax_amount);
                            $expense_array['tax_amount'] = $expense_array['final_total'] - $expense_array['total_before_tax'];
                        }

                        //image name

                        // if (!empty($image_name)) {
                        //     if (filter_var($image_name, FILTER_VALIDATE_URL)) {
                        //         $source_image = file_get_contents($image_name);

                        //         $path = parse_url($image_name, PHP_URL_PATH);
                        //         $new_name = time() . '_' . basename($path);
                        //         $dest_img = public_path() . '/uploads/documents/' . $new_name;
                        //         file_put_contents($dest_img, $source_image);
                        //         $expense_array['document'] = $new_name;
                        //     } else {
                        //         $expense_array['document'] = $image_name;
                        //     }
                        // } else {
                        //     $expense_array['document'] = '';
                        // }

                    }
                    $image_name = $value['ATTACH DOCUMENT'];

                    $expense_array['document'] = $image_name;

                    $expense_array['status'] = 'final';
                    $expense_array['payment_status'] = 'due';
                    $expense_array['type'] = 'expense';
                    $expense_array['import_batch'] = $import_batch;
                    $expense_array['import_time'] = $now;
                    $expense_array['import_file'] = $filename;
                    $expense_array['contact_id'] = $contact_id;
                   
                    $transaction = Transaction::create($expense_array);
                    foreach ($value['lines'] as $key2 => $value2) {
                        $expense_array['expense_category_id']=null;
                        $category_name = trim($value2['CATEGORY']);
                        if (!empty($category_name)) {
                            $category = ExpenseCategory::where('business_id', $business_id)
                                ->where('name', $category_name)
                                ->first();
                            if (empty($category)) {
                                $category = new ExpenseCategory;
                                $category->business_id = $business_id;
                                $category->name = $category_name;
                                $category->save();
                            }
                            $expense_array['expense_category_id'] = $category->id;
                        }
    
                        //  Add Sub-Category
                        $sub_category_name = trim($value2['SUB-CATEGORY']);
                        $expense_array['expense_sub_category_id']= null;
                        if (!empty($sub_category_name)) {
                            $sub_category = ExpenseCategory::where('business_id', $business_id)
                                ->where('name', $sub_category_name)
                                ->where('parent_id', $category->id)
                                ->first();
                            if (empty($sub_category)) {
                                $sub_category = new ExpenseCategory;
                                $sub_category->business_id = $business_id;
                                $sub_category->name = $sub_category_name;
                                $sub_category->parent_id = $category->id;
                                $sub_category->save();
                            }
                            $expense_array['expense_sub_category_id'] = $sub_category->id;
                        }
                        array_push($lines, [
                            'transaction_id' => $transaction->id,
                            'category_id' => $expense_array['expense_category_id'],
                            'sub_category_id' => $expense_array['expense_sub_category_id'],
                            'description' => trim($value2['DESCRIPTION']),
                            'amount' => $this->transactionUtil->num_uf(trim($value2['TOTAL AMOUNT'])),
                        ]);
                    }


                    $paid_amount = trim($value['PAID AMOUNT']);

                    if (!empty($paid_amount)) {
                        $paid_amount = $this->transactionUtil->num_uf($paid_amount);
                    } else {
                        $is_valid = false;
                        $error_msg = "Amount not found in row no. $row_no";
                        break;
                    }

                    $paid_on = trim($value['PAID ON DATE']);

                    //check if date is correct
                    if (!empty($paid_on)) {
                        try {
                            \Carbon::parse($paid_on);
                        } catch (\Exception $e) {
                            throw new \Exception(__('lang_v1.invalid_date_format_at', ['row' => $row_no]));
                        }
                    }

                    if (!empty($paid_on)) {
                        $paid_on = $paid_on;
                    } else {
                        $paid_on = \Carbon::now()->toDateTimeString();
                    }

                    $payment_method = trim($value['PAYMENT METHOD']);
                    if (!empty($payment_method)) {

                        $payment_types = $this->transactionUtil->payment_types(null, false, $business_id);
                        if (!in_array($payment_method, $payment_types)) {
                            $is_valid = false;
                            $error_msg = "This Payment Method not exit in row no. $row_no";
                            break;
                        }
                        $payment_method = array_search($payment_method, $payment_types);
                    } else {
                        $payment_method = 'other';

                        // $is_valid = false;
                        // $error_msg = "Payment Method not found in row no. $row_no";
                        // break;
                    }

                    $account_number = trim($value['PAYMENT ACCOUND']);
                    $account_id  = $this->AccountTransactionUtil->getPaymentAccount($account_number, $business_id);

                    $t_no = 0;
                    for ($i = 1; $i < 8; $i++) {
                        if ($payment_method == 'custom_pay_' . $i) {
                            $t_no = $i;
                            break;
                        }
                    }

                    $payment[] = [
                        'amount' => $paid_amount,
                        'paid_on' => $paid_on,
                        'method' => $payment_method,
                        'account_id' => $account_id,
                        'note' =>  trim($value['PAYMENT NOTE']),
                        "transaction_no_{$t_no}" => null
                    ];
                    $this->transactionUtil->createOrUpdatePaymentLines($transaction, $payment, null, null, false);
                    $payment = [];
                    $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);
                    if (!empty($value['TAGS'])) {
                        $tags = collect(explode(';', $value['TAGS']))
                            ->map(fn($tag) => trim($tag))
                            ->filter()
                            ->map(function ($tag) use ($business_id) {
                                try {
                                    return Tag::firstOrCreate([
                                        'business_id' => $business_id,
                                        'name'        => $tag,
                                    ])->id;
                                } catch (\Throwable $e) {
                                    \Log::error("Gagal membuat/menemukan tag: {$tag}", [
                                        'business_id' => $business_id,
                                        'error'       => $e->getMessage(),
                                    ]);
                                    return null; // skip kalau gagal
                                }
                            })
                            ->filter() // buang null hasil gagal
                            ->unique() // pastikan ID tidak dobel
                            ->values()
                            ->toArray();
                        if (!empty($tags)) {
                            $this->transactionUtil->updateTagTransaction($transaction->id, $tags);
                        }
                    }
                }
                \App\ExpenseLine::insert($lines);

                if (!$is_valid) {
                    dd($error_msg);
                    throw new \Exception($error_msg);
                }
            }

            DB::commit();

            $output = [
                'success' => 1,
                'msg' => __('product.file_imported_successfully'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => $e->getMessage(),
            ];

            return redirect('import-expense')->with('notification', $output);
        }

        return redirect('import-expense')->with('status', $output);
    }
}
