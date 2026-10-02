<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Business;
use App\Account;

use App\Transaction;
use App\User;
use App\Services\UserBusinessImportService;
use App\Utils\ModuleUtil;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;
use App\Events\UserCreatedOrModified;

class ManageUserController extends Controller
{
    /**
     * Constructor
     *
     * @param  Util  $commonUtil
     * @return void
     */
    public function __construct(ModuleUtil $moduleUtil, UserBusinessImportService $userBusinessImport)
    {
        $this->moduleUtil = $moduleUtil;
        $this->userBusinessImport = $userBusinessImport;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (! auth()->user()->can('user.view') && ! auth()->user()->can('user.create')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $user_id = request()->session()->get('user.id');

            $users = User::where('business_id', $business_id)
                ->user()
                ->where('is_cmmsn_agnt', 0)
                ->select([
                    'id',
                    'username',
                    DB::raw("CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as full_name"),
                    'email',
                    'allow_login',
                ]);

            return Datatables::of($users)
                ->addColumn(
                    'role',
                    function ($row) {
                        $role_name = $this->moduleUtil->getUserRoleName($row->id);

                        return $role_name;
                    }
                )
                ->addColumn('login_status', function ($row) {
                    return $row->allow_login
                        ? '<span class="label bg-green">Login aktif</span>'
                        : '<span class="label bg-gray">Login nonaktif</span>';
                })
                ->addColumn(
                    'action',
                    '@can("user.update")
                        <a href="{{action(\'App\Http\Controllers\ManageUserController@edit\', [$id])}}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</a>
                        &nbsp;
                    @endcan
                    @can("user.view")
                    <a href="{{action(\'App\Http\Controllers\ManageUserController@show\', [$id])}}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  tw-dw-btn-info"><i class="fa fa-eye"></i> @lang("messages.view")</a>
                    &nbsp;
                    <a href="{{action(\'App\Http\Controllers\ManageUserController@show\', [$id])}}?view=ledger" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-info"><i class="fas fa-scroll"></i> @lang("lang_v1.ledger")</a>
                    &nbsp;
                    <a href="{{action(\'Modules\Project\Http\Controllers\ProjectController@ledger\', [\'user_id\' => $id])}}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-info"><i class="fas fa-tasks"></i> Project Ledger</a>
                    &nbsp;
                    @endcan
                    @can("user.delete")
                        <button data-href="{{action(\'App\Http\Controllers\ManageUserController@destroy\', [$id])}}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error delete_user_button"><i class="glyphicon glyphicon-trash"></i> @lang("messages.delete")</button>
                    @endcan'
                )
                ->filterColumn('full_name', function ($query, $keyword) {
                    $query->whereRaw("CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) like ?", ["%{$keyword}%"]);
                })
                ->removeColumn('id')
                ->rawColumns(['action', 'login_status'])
                ->make(true);
        }

        return view('manage_user.index', ['can_import_users' => $this->canImportAcrossBusinesses()]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (! auth()->user()->can('user.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not, then check for users quota
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        } elseif (! $this->moduleUtil->isQuotaAvailable('users', $business_id)) {
            return $this->moduleUtil->quotaExpiredResponse('users', $business_id, action([\App\Http\Controllers\ManageUserController::class, 'index']));
        }

        $roles = $this->getRolesArray($business_id);
        $username_ext = $this->moduleUtil->getUsernameExtension();
        $can_import_users = $this->canImportAcrossBusinesses();
        $locations = BusinessLocation::where('business_id', $business_id)
            ->Active()
            ->get();

        //Get user form part from modules
        $form_partials = $this->moduleUtil->getModuleData('moduleViewPartials', ['view' => 'manage_user.create']);

        return view('manage_user.create')
            ->with(compact('roles', 'username_ext', 'locations', 'form_partials', 'can_import_users'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('user.create')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate(['first_name' => ['required', 'string']]);

        if (! empty($request->input('allow_login')) && filled($request->input('username'))) {
            $finalUsername = trim($request->input('username')) . ($this->moduleUtil->getUsernameExtension() ?: '');
            if (mb_strlen($finalUsername) > 191) {
                return back()->withInput()->withErrors(['username' => 'Username beserta suffix business maksimal 191 karakter.']);
            }

            $existingUsername = User::withTrashed()
                ->where('username', $finalUsername)
                ->with('business:id,name')
                ->first();
            if ($existingUsername) {
                $message = 'Username sudah digunakan.';
                if (auth()->user()->can('admin') || auth()->user()->can('superadmin')) {
                    $fullName = trim(implode(' ', array_filter([
                        $existingUsername->surname,
                        $existingUsername->first_name,
                        $existingUsername->last_name,
                    ])));
                    $businessName = optional($existingUsername->business)->name ?: 'Business tidak diketahui';
                    $accountStatus = $existingUsername->deleted_at
                        ? 'akun dihapus; username tetap terpakai'
                        : ($existingUsername->allow_login ? 'login aktif' : 'login nonaktif');
                    $message .= ' Dipakai oleh ' . ($fullName ?: 'user tanpa nama') . ' di ' . $businessName . ' (' . $accountStatus . ').';
                }

                return back()->withInput()->withErrors(['username' => $message]);
            }
        }

        try {
            if (! empty($request->input('dob'))) {
                $request['dob'] = $this->moduleUtil->uf_date($request->input('dob'));
            }

            $request['cmmsn_percent'] = ! empty($request->input('cmmsn_percent')) ? $this->moduleUtil->num_uf($request->input('cmmsn_percent')) : 0;

            $request['max_sales_discount_percent'] = ! is_null($request->input('max_sales_discount_percent')) ? $this->moduleUtil->num_uf($request->input('max_sales_discount_percent')) : null;

            $user = $this->moduleUtil->createUser($request);

            event(new UserCreatedOrModified($user, 'added'));

            $output = [
                'success' => 1,
                'msg' => __('user.user_added'),
            ];
        } catch (\Exception $e) {
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect('users')->with('status', $output);
    }

    /**
     * Return username availability with business details for authorized user managers.
     */
    public function checkUsernameAvailability(Request $request)
    {
        abort_unless(auth()->user()->can('user.create'), 403, 'Unauthorized action.');

        $validated = $request->validate([
            'username' => ['nullable', 'string', 'max:191'],
        ]);
        $username = trim((string) ($validated['username'] ?? ''));
        if ($username === '') {
            return response()->json(['available' => true, 'username' => '', 'matches' => []]);
        }

        $query = User::withTrashed()
            ->where('username', $username)
            ->with('business:id,name');
        $matches = $query->get(['id', 'username', 'surname', 'first_name', 'last_name', 'business_id', 'status', 'allow_login', 'deleted_at']);
        $canSeeBusinessDetails = auth()->user()->can('admin') || auth()->user()->can('superadmin');

        return response()->json([
            'available' => $matches->isEmpty(),
            'username' => $username,
            'matches' => $canSeeBusinessDetails ? $matches->map(function (User $user) {
                return [
                    'name' => trim(implode(' ', array_filter([$user->surname, $user->first_name, $user->last_name]))),
                    'business' => optional($user->business)->name ?: 'Business tidak diketahui',
                    'status' => $user->deleted_at
                        ? 'Akun dihapus (username tetap terpakai)'
                        : (($user->allow_login ? 'Login aktif' : 'Login nonaktif') . ' · ' . ($user->status ?: 'status tidak diketahui')),
                ];
            })->values() : [],
            'message' => $matches->isEmpty() ? 'Username tersedia.' : 'Username sudah digunakan.',
        ]);
    }

    /** Show the business-to-business user import screen. */
    public function importUsers()
    {
        $this->authorizeBusinessUserImport();

        $targetBusinessId = (int) request()->session()->get('user.business_id');
        if (! $this->moduleUtil->isSubscribed($targetBusinessId)) {
            return $this->moduleUtil->expiredResponse();
        } elseif (! $this->moduleUtil->isQuotaAvailable('users', $targetBusinessId)) {
            return $this->moduleUtil->quotaExpiredResponse('users', $targetBusinessId, action([self::class, 'index']));
        }

        $sourceBusinesses = $this->userBusinessImport->businessesForImport($targetBusinessId);
        $targetBusiness = Business::findOrFail($targetBusinessId);

        return view('manage_user.import', compact('sourceBusinesses', 'targetBusinessId', 'targetBusiness'));
    }

    /** Search users within the selected source business. */
    public function searchImportSourceUsers(Request $request)
    {
        $this->authorizeBusinessUserImport();
        $validated = $request->validate([
            'source_business_id' => ['required', 'integer', 'exists:business,id'],
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $targetBusinessId = (int) $request->session()->get('user.business_id');

        return response()->json($this->userBusinessImport->searchSourceUsers(
            (int) $validated['source_business_id'],
            $targetBusinessId,
            $validated['q']
        ));
    }

    /** Return profile and access mappings for the selected source user. */
    public function previewImportedUser(Request $request)
    {
        $this->authorizeBusinessUserImport();
        $validated = $request->validate([
            'source_business_id' => ['required', 'integer', 'exists:business,id'],
            'source_user_id' => ['required', 'integer'],
        ]);
        $targetBusinessId = (int) $request->session()->get('user.business_id');

        return response()->json($this->userBusinessImport->preview(
            (int) $validated['source_business_id'],
            $targetBusinessId,
            (int) $validated['source_user_id']
        ));
    }

    /** Search contacts only within the active destination business. */
    public function searchImportTargetContacts(Request $request)
    {
        $this->authorizeBusinessUserImport();
        $validated = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);

        return response()->json($this->userBusinessImport->searchTargetContacts(
            (int) $request->session()->get('user.business_id'),
            $validated['q']
        ));
    }

    /** Import a user after all target-business mappings are confirmed. */
    public function storeImportedUser(Request $request)
    {
        $this->authorizeBusinessUserImport();
        $validated = $request->validate([
            'source_business_id' => ['required', 'integer', 'exists:business,id'],
            'source_user_id' => ['required', 'integer'],
            'username' => ['nullable', 'string', 'max:191'],
            'role_map' => ['nullable', 'array'],
            'location_map' => ['nullable', 'array'],
            'contact_map' => ['nullable', 'array'],
        ]);

        $targetBusinessId = (int) $request->session()->get('user.business_id');
        if (! $this->moduleUtil->isSubscribed($targetBusinessId)) {
            return response()->json(['success' => false, 'message' => 'Business tujuan tidak memiliki langganan aktif.'], 422);
        } elseif (! $this->moduleUtil->isQuotaAvailable('users', $targetBusinessId)) {
            return response()->json(['success' => false, 'message' => 'Kuota user business tujuan telah habis.'], 422);
        }

        $user = $this->userBusinessImport->import(
            (int) $validated['source_business_id'],
            $targetBusinessId,
            (int) $validated['source_user_id'],
            $validated
        );
        $this->moduleUtil->activityLog($user, 'imported', null, [
            'source_business_id' => (int) $validated['source_business_id'],
            'source_user_id' => (int) $validated['source_user_id'],
        ], true, $targetBusinessId);
        event(new UserCreatedOrModified($user, 'added'));

        return response()->json([
            'success' => true,
            'message' => 'User berhasil diimpor ke business ini.',
            'redirect' => action([self::class, 'index']),
        ]);
    }

    private function authorizeBusinessUserImport(): void
    {
        abort_unless(
            auth()->user()->can('user.create')
            && (auth()->user()->can('admin') || auth()->user()->can('superadmin')),
            403,
            'Hanya admin dengan izin membuat user yang dapat mengimpor user lintas business.'
        );
    }

    private function canImportAcrossBusinesses(): bool
    {
        return auth()->user()->can('user.create')
            && (auth()->user()->can('admin') || auth()->user()->can('superadmin'));
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (! auth()->user()->can('user.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $user = User::where('business_id', $business_id)
            ->with(['contactAccess', 'account'])
            ->find($id);

        //Get user view part from modules
        $view_partials = $this->moduleUtil->getModuleData('moduleViewPartials', ['view' => 'manage_user.show', 'user' => $user]);

        $users = User::forDropdown($business_id, false);

        $activities = Activity::forSubject($user)
            ->with(['causer', 'subject'])
            ->latest()
            ->get();

        $view_type = request()->get('view');
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('manage_user.show')->with(compact('user', 'view_partials', 'users', 'activities', 'view_type', 'business_locations'));
    }

    /**
     * Shows ledger for a user.
     *
     * @return \Illuminate\Http\Response
     */
    public function getLedger(Request $request)
    {
        if (! auth()->user()->can('user.view')) {
            abort(403, 'Unauthorized action.');
        }

        $current_user_id = auth()->id();
        $can_view_all_expense_users = auth()->user()->can('all_expense.access');
        $can_view_own_expense = auth()->user()->can('view_own_expense');
        $can_view_expense_ledger = $can_view_all_expense_users || $can_view_own_expense;
        $can_view_all_payroll_users = auth()->user()->can('essentials.view_all_payroll');
        $can_view_own_payroll = auth()->user()->can('essentials.create_payroll');
        $can_view_payroll_ledger = $can_view_all_payroll_users || $can_view_own_payroll;

        if (! $can_view_expense_ledger && ! $can_view_payroll_ledger) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $user_id = $request->input('user_id');

        if (! $can_view_all_expense_users && ! $can_view_all_payroll_users && (int) $user_id !== (int) $current_user_id) {
            abort(403, 'Unauthorized action.');
        }

        $user = User::where('business_id', $business_id)
            ->user()
            ->findOrFail($user_id);

        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        $location_id = $request->input('location_id');

        $ledger_details = $this->getUserLedgerDetails(
            $business_id,
            $user->id,
            $start_date,
            $end_date,
            $location_id,
            [
                'can_view_expense_ledger' => $can_view_expense_ledger,
                'can_view_payroll_ledger' => $can_view_payroll_ledger,
                'can_view_expense_details' => $can_view_all_expense_users || ((int) $user->id === (int) $current_user_id && $can_view_own_expense),
                'can_view_payroll_details' => $can_view_all_payroll_users || ((int) $user->id === (int) $current_user_id && $can_view_payroll_ledger),
            ]
        );

        $location = null;
        if (! empty($location_id)) {
            $location = BusinessLocation::where('business_id', $business_id)->find($location_id);
        }

        return view('manage_user.ledger')->with(compact('ledger_details', 'user', 'location'));
    }

    /**
     * Returns summary and rows for user ledger.
     *
     * @param  int  $business_id
     * @param  int  $user_id
     * @param  string|null  $start_date
     * @param  string|null  $end_date
     * @param  int|null  $location_id
     * @param  array  $permissions
     * @return array
     */
    private function getUserLedgerDetails($business_id, $user_id, $start_date = null, $end_date = null, $location_id = null, array $permissions = [])
    {
        $payment_subquery = DB::raw('(SELECT transaction_id, SUM(IF(is_return = 0, amount, amount * -1)) as amount_paid FROM transaction_payments GROUP BY transaction_id) AS TP');
        $visible_transaction_types = [];

        if (! empty($permissions['can_view_expense_ledger'])) {
            $visible_transaction_types = ['expense', 'expense_refund'];
        }

        if (! empty($permissions['can_view_payroll_ledger'])) {
            $visible_transaction_types[] = 'payroll';
        }

        $query = Transaction::leftJoin('expense_categories AS ec', 'transactions.expense_category_id', '=', 'ec.id')
            ->leftJoin('expense_categories AS esc', 'transactions.expense_sub_category_id', '=', 'esc.id')
            ->leftJoin('business_locations AS bl', 'transactions.location_id', '=', 'bl.id')
            ->leftJoin('contacts AS c', 'transactions.contact_id', '=', 'c.id')
            ->leftJoin('users AS usr', 'transactions.created_by', '=', 'usr.id')
            ->leftJoin($payment_subquery, 'transactions.id', '=', 'TP.transaction_id')
            ->where('transactions.business_id', $business_id)
            ->whereIn('transactions.type', $visible_transaction_types)
            ->where('transactions.expense_for', $user_id);

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

        $summary = (clone $query)
            ->select(
                DB::raw("SUM(IF(transactions.type = 'expense_refund', -1 * transactions.final_total, transactions.final_total)) as total_amount"),
                DB::raw("SUM(IF(transactions.type IN ('expense', 'expense_refund'), IF(transactions.type = 'expense_refund', -1 * transactions.final_total, transactions.final_total), 0)) as total_expense"),
                DB::raw("SUM(IF(transactions.type = 'payroll', transactions.final_total, 0)) as total_payroll"),
                DB::raw("SUM(IF(transactions.type = 'expense_refund', -1 * IFNULL(TP.amount_paid, 0), IFNULL(TP.amount_paid, 0))) as total_paid"),
                DB::raw("SUM(IF(transactions.payment_status = 'paid', 1, 0)) as paid_count"),
                DB::raw("SUM(IF(transactions.payment_status = 'partial', 1, 0)) as partial_count"),
                DB::raw("SUM(IF(transactions.payment_status = 'due', 1, 0)) as due_count"),
                DB::raw('COUNT(transactions.id) as total_transactions')
            )
            ->first();

        $ledger = $query
            ->select(
                'transactions.id',
                'transactions.transaction_date',
                'transactions.ref_no',
                'transactions.type',
                'transactions.payment_status',
                'transactions.final_total',
                'transactions.additional_notes',
                'ec.name as category',
                'esc.name as sub_category',
                'bl.name as location_name',
                'c.name as contact_name',
                'c.supplier_business_name',
                DB::raw('IFNULL(TP.amount_paid, 0) as amount_paid'),
                DB::raw("CONCAT(COALESCE(usr.surname, ''), ' ', COALESCE(usr.first_name, ''), ' ', COALESCE(usr.last_name, '')) as added_by"),
                DB::raw("(SELECT GROUP_CONCAT(DISTINCT ec2.name SEPARATOR ', ') FROM expense_lines el LEFT JOIN expense_categories ec2 ON el.category_id = ec2.id WHERE el.transaction_id = transactions.id) AS line_categories"),
                DB::raw("(SELECT GROUP_CONCAT(DISTINCT esc2.name SEPARATOR ', ') FROM expense_lines el LEFT JOIN expense_categories esc2 ON el.sub_category_id = esc2.id WHERE el.transaction_id = transactions.id) AS line_sub_categories"),
                DB::raw("(SELECT GROUP_CONCAT(el.description SEPARATOR '\n') FROM expense_lines el WHERE el.transaction_id = transactions.id) AS descriptions"),
                DB::raw("DATE_FORMAT(transactions.transaction_date, '%M %Y') as payroll_period")
            )
            ->orderBy('transactions.transaction_date', 'asc')
            ->orderBy('transactions.id', 'asc')
            ->get()
            ->map(function ($row) use ($permissions) {
                $total = $row->type == 'expense_refund' ? -1 * $row->final_total : $row->final_total;
                $paid = $row->type == 'expense_refund' ? -1 * $row->amount_paid : $row->amount_paid;

                $row->signed_total = $total;
                $row->signed_paid = $paid;
                $row->signed_due = $total - $paid;

                if ($row->type == 'payroll') {
                    $row->display_type = __('essentials::lang.payroll');
                    $row->display_category = $row->payroll_period;
                    $row->display_sub_category = '--';
                    $row->display_contact = '--';
                    $row->show_url = ! empty($permissions['can_view_payroll_details']) ? action([\Modules\Essentials\Http\Controllers\PayrollController::class, 'show'], [$row->id]) : null;
                } else {
                    $row->display_type = __('lang_v1.expense');
                    $row->display_category = ! empty($row->line_categories) ? $row->line_categories : $row->category;
                    $row->display_sub_category = ! empty($row->line_sub_categories) ? $row->line_sub_categories : $row->sub_category;
                    $row->display_contact = ! empty($row->supplier_business_name) ? $row->supplier_business_name : $row->contact_name;
                    $row->show_url = ! empty($permissions['can_view_expense_details']) ? action([\App\Http\Controllers\ExpenseController::class, 'show'], [$row->id]) : null;
                }

                $row->display_category = ! empty($row->display_category) ? $row->display_category : '--';
                $row->display_sub_category = ! empty($row->display_sub_category) ? $row->display_sub_category : '--';
                $row->display_contact = ! empty($row->display_contact) ? $row->display_contact : '--';

                return $row;
            });

        $total_amount = $summary->total_amount ?? 0;
        $total_expense = $summary->total_expense ?? 0;
        $total_payroll = $summary->total_payroll ?? 0;
        $total_paid = $summary->total_paid ?? 0;

        return [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'total_amount' => $total_amount,
            'total_expense' => $total_expense,
            'total_payroll' => $total_payroll,
            'total_paid' => $total_paid,
            'total_due' => $total_amount - $total_paid,
            'total_transactions' => $summary->total_transactions ?? 0,
            'paid_count' => $summary->paid_count ?? 0,
            'partial_count' => $summary->partial_count ?? 0,
            'due_count' => $summary->due_count ?? 0,
            'show_expense_summary' => ! empty($permissions['can_view_expense_ledger']),
            'show_payroll_summary' => ! empty($permissions['can_view_payroll_ledger']),
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
        if (! auth()->user()->can('user.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $user = User::where('business_id', $business_id)
            ->with(['contactAccess'])
            ->findOrFail($id);

        $roles = $this->getRolesArray($business_id);

        $contact_access = $user->contactAccess->pluck('name', 'id')->toArray();

        if ($user->status == 'active') {
            $is_checked_checkbox = true;
        } else {
            $is_checked_checkbox = false;
        }

        $locations = BusinessLocation::where('business_id', $business_id)
            ->get();

        $permitted_locations = $user->permitted_locations();
        $username_ext = $this->moduleUtil->getUsernameExtension();

        //Get user form part from modules
        $form_partials = $this->moduleUtil->getModuleData('moduleViewPartials', ['view' => 'manage_user.edit', 'user' => $user]);
        // dd($form_partials);
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false, true);
        }
        return view('manage_user.edit')
            ->with(compact('accounts', 'roles', 'user', 'contact_access', 'is_checked_checkbox', 'locations', 'permitted_locations', 'form_partials', 'username_ext'));
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
        //Disable in demo
        $notAllowed = $this->moduleUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return $notAllowed;
        }

        if (! auth()->user()->can('user.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $user_data = $request->only([
                'surname',
                'first_name',
                'last_name',
                'email',
                'selected_contacts',
                'marital_status',
                'blood_group',
                'contact_number',
                'fb_link',
                'twitter_link',
                'social_media_1',
                'social_media_2',
                'permanent_address',
                'current_address',
                'guardian_name',
                'custom_field_1',
                'custom_field_2',
                'custom_field_3',
                'custom_field_4',
                'id_proof_name',
                'id_proof_number',
                'account_id',
                'cmmsn_percent',
                'gender',
                'max_sales_discount_percent',
                'family_number',
                'alt_number',
                'is_enable_service_staff_pin'
            ]);

            $user_data['status'] = ! empty($request->input('is_active')) ? 'active' : 'inactive';

            $user_data['is_enable_service_staff_pin'] = ! empty($request->input('is_enable_service_staff_pin')) ? true : false;

            $business_id = request()->session()->get('user.business_id');

            if (! isset($user_data['selected_contacts'])) {
                $user_data['selected_contacts'] = 0;
            }

            if (empty($request->input('allow_login'))) {
                $user_data['username'] = null;
                $user_data['password'] = null;
                $user_data['allow_login'] = 0;
            } else {
                $user_data['allow_login'] = 1;
            }

            if (! empty($request->input('password'))) {
                $user_data['password'] = $user_data['allow_login'] == 1 ? Hash::make($request->input('password')) : null;
            }


            if (! empty($request->input('service_staff_pin'))) {
                $user_data['service_staff_pin'] = $request->input('service_staff_pin');
            }


            //Sales commission percentage
            $user_data['cmmsn_percent'] = ! empty($user_data['cmmsn_percent']) ? $this->moduleUtil->num_uf($user_data['cmmsn_percent']) : 0;

            $user_data['max_sales_discount_percent'] = ! is_null($user_data['max_sales_discount_percent']) ? $this->moduleUtil->num_uf($user_data['max_sales_discount_percent']) : null;

            if (! empty($request->input('dob'))) {
                $user_data['dob'] = $this->moduleUtil->uf_date($request->input('dob'));
            }

            if (! empty($request->input('bank_details'))) {
                $user_data['bank_details'] = json_encode($request->input('bank_details'));
            }

            DB::beginTransaction();

            if ($user_data['allow_login'] && $request->has('username')) {
                $user_data['username'] = $request->input('username');
                $ref_count = $this->moduleUtil->setAndGetReferenceCount('username');
                if (blank($user_data['username'])) {
                    $user_data['username'] = $this->moduleUtil->generateReferenceNumber('username', $ref_count);
                }

                $username_ext = $this->moduleUtil->getUsernameExtension();
                if (! empty($username_ext)) {
                    $user_data['username'] .= $username_ext;
                }
            }

            $user = User::where('business_id', $business_id)
                ->findOrFail($id);

            $user->update($user_data);
            $role_id = $request->input('role');
            $user_role = $user->roles->first();
            $previous_role = ! empty($user_role->id) ? $user_role->id : 0;
            if ($previous_role != $role_id) {
                $is_admin = $this->moduleUtil->is_admin($user);
                $all_admins = $this->getAdmins();
                //If only one admin then can not change role
                if ($is_admin && count($all_admins) <= 1) {
                    throw new \Exception(__('lang_v1.cannot_change_role'));
                }
                if (! empty($previous_role)) {
                    $user->removeRole($user_role->name);
                }

                $role = Role::findOrFail($role_id);
                $user->assignRole($role->name);
            }

            //Grant Location permissions
            $this->moduleUtil->giveLocationPermissions($user, $request);

            //Assign selected contacts
            if ($user_data['selected_contacts'] == 1) {
                $contact_ids = $request->get('selected_contact_ids');
            } else {
                $contact_ids = [];
            }
            $user->contactAccess()->sync($contact_ids);

            //Update module fields for user
            $this->moduleUtil->getModuleData('afterModelSaved', ['event' => 'user_saved', 'model_instance' => $user]);

            $this->moduleUtil->activityLog($user, 'edited', null, ['name' => $user->user_full_name]);

            event(new UserCreatedOrModified($user, 'updated'));

            $output = [
                'success' => 1,
                'msg' => __('user.user_update_success'),
            ];

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => $e->getMessage(),
            ];
        }

        return redirect('users')->with('status', $output);
    }

    private function getAdmins()
    {
        $business_id = request()->session()->get('user.business_id');
        $admins = User::role('Admin#' . $business_id)->get();

        return $admins;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //Disable in demo
        $notAllowed = $this->moduleUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return $notAllowed;
        }

        if (! auth()->user()->can('user.delete')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');

                $user = User::where('business_id', $business_id)
                    ->findOrFail($id);

                $this->moduleUtil->activityLog($user, 'deleted', null, ['name' => $user->user_full_name, 'id' => $user->id]);

                $user->delete();
                event(new UserCreatedOrModified($user, 'deleted'));

                $output = [
                    'success' => true,
                    'msg' => __('user.user_delete_success'),
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
     * Retrives roles array (Hides admin role from non admin users)
     *
     * @param  int  $business_id
     * @return array $roles
     */
    private function getRolesArray($business_id)
    {
        $roles_array = Role::where('business_id', $business_id)->get()->pluck('name', 'id');
        $roles = [];

        $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);

        foreach ($roles_array as $key => $value) {
            if (! $is_admin && $value == 'Admin#' . $business_id) {
                continue;
            }
            $roles[$key] = str_replace('#' . $business_id, '', $value);
        }

        return $roles;
    }

    /**
     * Signes in from user id
     *
     * @param  int  $id
     */
    public function signInAsUser($id)
    {
        if (! auth()->user()->can('superadmin') && empty(session('previous_user_id'))) {
            abort(403, 'Unauthorized action.');
        }

        $user_id = auth()->user()->id;
        $username = auth()->user()->username;
        session()->flush();

        if (request()->has('save_current')) {
            session(['previous_user_id' => $user_id, 'previous_username' => $username]);
        }

        Auth::loginUsingId($id);

        return redirect()->route('home');
    }
}
