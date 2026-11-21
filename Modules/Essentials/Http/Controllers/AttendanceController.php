<?php

namespace Modules\Essentials\Http\Controllers;

use App\User;
use App\Services\UserImportBatchService;
use App\UserImportBatch;
use App\Utils\ModuleUtil;
use DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Essentials\Entities\EssentialsAttendance;
use Modules\Essentials\Entities\Shift;
use Modules\Essentials\Jobs\ProcessAttendanceImportBatch;
use Modules\Essentials\Services\AttendanceImportBatchService;
use Modules\Essentials\Utils\EssentialsUtil;
use Spatie\Permission\Models\Permission;
use Yajra\DataTables\Facades\DataTables;

class AttendanceController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $moduleUtil;

    protected $essentialsUtil;

    /**
     * Constructor
     *
     * @param  ProductUtils  $product
     * @return void
     */
    public function __construct(ModuleUtil $moduleUtil, EssentialsUtil $essentialsUtil)
    {
        $this->moduleUtil = $moduleUtil;
        $this->essentialsUtil = $essentialsUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');
        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module'))) {
            abort(403, 'Unauthorized action.');
        }
        $can_crud_all_attendance = auth()->user()->can('essentials.crud_all_attendance');
        $can_view_own_attendance = auth()->user()->can('essentials.view_own_attendance');

        if (! $can_crud_all_attendance && ! $can_view_own_attendance) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $attendance = EssentialsAttendance::where('essentials_attendances.business_id', $business_id)
                            ->join('users as u', 'u.id', '=', 'essentials_attendances.user_id')
                            ->leftjoin('essentials_shifts as es', 'es.id', '=', 'essentials_attendances.essentials_shift_id')
                            ->select([
                                'essentials_attendances.id',
                                'clock_in_time',
                                'clock_out_time',
                                'clock_in_note',
                                'clock_out_note',
                                'ip_address',
                                DB::raw('DATE(clock_in_time) as date'),
                                DB::raw("CONCAT(COALESCE(u.surname, ''), ' ', COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as user"),
                                'es.name as shift_name', 'clock_in_location', 'clock_out_location',
                            ])->groupBy('essentials_attendances.id');

            $permitted_locations = auth()->user()->permitted_locations();

            if ($permitted_locations != 'all') {
                $permitted_locations_array = [];

                foreach ($permitted_locations as $loc_id) {
                    $permitted_locations_array[] = 'location.'.$loc_id;
                }
                $permission_ids = Permission::whereIn('name', $permitted_locations_array)
                                        ->pluck('id');

                $attendance->join('model_has_permissions as mhp', 'mhp.model_id', '=', 'u.id')->whereIn('mhp.permission_id', $permission_ids);
            }

            if (! empty(request()->input('employee_id'))) {
                $attendance->where('essentials_attendances.user_id', request()->input('employee_id'));
            }
            if (! empty(request()->start_date) && ! empty(request()->end_date)) {
                $start = request()->start_date;
                $end = request()->end_date;
                $attendance->whereDate('clock_in_time', '>=', $start)
                            ->whereDate('clock_in_time', '<=', $end);
            }

            if (! $can_crud_all_attendance && $can_view_own_attendance) {
                $attendance->where('essentials_attendances.user_id', auth()->user()->id);
            }

            return Datatables::of($attendance)
                    ->addColumn(
                        'action',
                        '@can("essentials.crud_all_attendance") <button data-href="{{action(\'\Modules\Essentials\Http\Controllers\AttendanceController@edit\', [$id])}}" class="btn btn-xs btn-primary btn-modal" data-container="#edit_attendance_modal"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</button>
                        <button class="btn btn-xs btn-danger delete-attendance" data-href="{{action(\'\Modules\Essentials\Http\Controllers\AttendanceController@destroy\', [$id])}}"><i class="fa fa-trash"></i> @lang("messages.delete")</button> @endcan
                        '
                    )
                    ->editColumn('work_duration', function ($row) {
                        $clock_in = \Carbon::parse($row->clock_in_time);
                        if (! empty($row->clock_out_time)) {
                            $clock_out = \Carbon::parse($row->clock_out_time);
                        } else {
                            $clock_out = \Carbon::now();
                        }

                        $html = $clock_in->diffForHumans($clock_out, true, true, 2);

                        return $html;
                    })
                    ->editColumn('clock_in', function ($row) {
                        $html = $this->moduleUtil->format_date($row->clock_in_time, true);
                        if (! empty($row->clock_in_location)) {
                            $html .= '<br>'.$row->clock_in_location.'<br>';
                        }

                        if (! empty($row->clock_in_note)) {
                            $html .= '<br>'.$row->clock_in_note.'<br>';
                        }

                        return $html;
                    })
                    ->editColumn('clock_out', function ($row) {
                        $html = $this->moduleUtil->format_date($row->clock_out_time, true);
                        if (! empty($row->clock_out_location)) {
                            $html .= '<br>'.$row->clock_out_location.'<br>';
                        }

                        if (! empty($row->clock_out_note)) {
                            $html .= '<br>'.$row->clock_out_note.'<br>';
                        }

                        return $html;
                    })
                    ->editColumn('date', '{{@format_date($date)}}')
                    ->rawColumns(['action', 'clock_in', 'work_duration', 'clock_out'])
                    ->filterColumn('user', function ($query, $keyword) {
                        $query->whereRaw("CONCAT(COALESCE(u.surname, ''), ' ', COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) like ?", ["%{$keyword}%"]);
                    })
                    ->make(true);
        }

        $settings = request()->session()->get('business.essentials_settings');
        $settings = ! empty($settings) ? json_decode($settings, true) : [];

        $is_employee_allowed = auth()->user()->can('essentials.allow_users_for_attendance_from_web');
        $clock_in = EssentialsAttendance::where('business_id', $business_id)
                                ->where('user_id', auth()->user()->id)
                                ->whereNull('clock_out_time')
                                ->first();
        $employees = [];
        if ($can_crud_all_attendance) {
            $employees = User::forDropdown($business_id, false);
        }

        $days = $this->moduleUtil->getDays();

        return view('essentials::attendance.index')
            ->with(compact('is_employee_allowed', 'clock_in', 'employees', 'days'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $business_id = request()->session()->get('user.business_id');
        $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module')) && ! $is_admin) {
            abort(403, 'Unauthorized action.');
        }

        $employees = User::forDropdown($business_id, false);

        return view('essentials::attendance.create')->with(compact('employees'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module') || $is_admin)) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $attendance = $request->input('attendance');
            $ip_address = $this->moduleUtil->getUserIpAddr();
            if (! empty($attendance)) {
                foreach ($attendance as $user_id => $value) {
                    $data = [
                        'business_id' => $business_id,
                        'user_id' => $user_id,
                    ];

                    if (! empty($value['clock_in_time'])) {
                        $data['clock_in_time'] = $this->moduleUtil->uf_date($value['clock_in_time'], true);
                    }
                    if (! empty($value['id'])) {
                        $data['id'] = $value['id'];
                    }
                    EssentialsAttendance::updateOrCreate(
                        $data,
                        [
                            'clock_out_time' => ! empty($value['clock_out_time']) ? $this->moduleUtil->uf_date($value['clock_out_time'], true) : null,
                            'ip_address' => ! empty($value['ip_address']) ? $value['ip_address'] : $ip_address,
                            'clock_in_note' => $value['clock_in_note'],
                            'clock_out_note' => $value['clock_out_note'],
                            'essentials_shift_id' => $value['essentials_shift_id'],
                        ]
                    );
                }
            }

            $output = ['success' => true,
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
     * Show the form for editing the specified resource.
     *
     * @return Response
     */
    public function edit($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module') || $is_admin)) {
            abort(403, 'Unauthorized action.');
        }

        $attendance = EssentialsAttendance::where('business_id', $business_id)
                                    ->with(['employee'])
                                    ->find($id);

        return view('essentials::attendance.edit')->with(compact('attendance'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $business_id = $request->session()->get('user.business_id');
        $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module') || $is_admin)) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $input = $request->only(['clock_in_time', 'clock_out_time', 'ip_address', 'clock_in_note', 'clock_out_note']);

            $input['clock_in_time'] = $this->moduleUtil->uf_date($input['clock_in_time'], true);
            $input['clock_out_time'] = ! empty($input['clock_out_time']) ? $this->moduleUtil->uf_date($input['clock_out_time'], true) : null;

            $attendance = EssentialsAttendance::where('business_id', $business_id)
                                            ->where('id', $id)
                                            ->update($input);
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

    /**
     * Remove the specified resource from storage.
     *
     * @return Response
     */
    public function destroy($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module'))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                EssentialsAttendance::where('business_id', $business_id)->where('id', $id)->delete();

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
     * Clock in / Clock out the logged in user.
     *
     * @return Response
     */
    public function clockInClockOut(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module'))) {
            abort(403, 'Unauthorized action.');
        }

        //Check if employees allowed to add their own attendance
        $settings = request()->session()->get('business.essentials_settings');
        $settings = ! empty($settings) ? json_decode($settings, true) : [];
        if (! auth()->user()->can('essentials.allow_users_for_attendance_from_web')) {
            return ['success' => false,
                'msg' => __('essentials::lang.not_allowed'),
            ];
        } elseif ((! empty($settings['is_location_required']) && $settings['is_location_required']) && empty($request->input('clock_in_out_location'))) {
            return ['success' => false,
                'msg' => __('essentials::lang.you_must_enable_location'),
            ];
        }

        try {
            $type = $request->input('type');

            if ($type == 'clock_in') {
                $data = [
                    'business_id' => $business_id,
                    'user_id' => auth()->user()->id,
                    'clock_in_time' => \Carbon::now(),
                    'clock_in_note' => $request->input('clock_in_note'),
                    'ip_address' => $this->moduleUtil->getUserIpAddr(),
                    'clock_in_location' => $request->input('clock_in_out_location'),
                ];

                $output = $this->essentialsUtil->clockin($data, $settings);
            } elseif ($type == 'clock_out') {
                $data = [
                    'business_id' => $business_id,
                    'user_id' => auth()->user()->id,
                    'clock_out_time' => \Carbon::now(),
                    'clock_out_note' => $request->input('clock_out_note'),
                    'clock_out_location' => $request->input('clock_in_out_location'),
                ];

                $output = $this->essentialsUtil->clockout($data, $settings);
            }
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => false,
                'msg' => __('messages.something_went_wrong'),
                'type' => $type,
            ];
        }

        return $output;
    }

    /**
     * Function to get attendance summary of a user
     *
     * @return Response
     */
    public function getUserAttendanceSummary()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module'))) {
            abort(403, 'Unauthorized action.');
        }

        $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);
        $user_id = $is_admin ? request()->input('user_id') : auth()->user()->id;

        if (empty($user_id)) {
            return '';
        }

        $start_date = ! empty(request()->start_date) ? request()->start_date : null;
        $end_date = ! empty(request()->end_date) ? request()->end_date : null;

        $total_work_duration = $this->essentialsUtil->getTotalWorkDuration('hour', $user_id, $business_id, $start_date, $end_date);

        return $total_work_duration;
    }

    /**
     * Function to validate clock in and clock out time
     *
     * @return string
     */
    public function validateClockInClockOut(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $user_ids = explode(',', $request->input('user_ids'));
        $clock_in_time = $request->input('clock_in_time');
        $clock_out_time = $request->input('clock_out_time');
        $attendance_id = $request->input('attendance_id');

        $is_valid = 'true';
        if (! empty($user_ids)) {

            //Check if clock in time falls under any existing attendance range
            $is_clock_in_exists = false;
            if (! empty($clock_in_time)) {
                $clock_in_time = $this->essentialsUtil->uf_date($clock_in_time, true);

                $is_clock_in_exists = EssentialsAttendance::where('business_id', $business_id)
                                        ->where('id', '!=', $attendance_id)
                                        ->whereIn('user_id', $user_ids)
                                        ->where('clock_in_time', '<', $clock_in_time)
                                        ->where('clock_out_time', '>', $clock_in_time)
                                        ->exists();
            }

            //Check if clock out time falls under any existing attendance range
            $is_clock_out_exists = false;
            if (! empty($clock_out_time)) {
                $clock_out_time = $this->essentialsUtil->uf_date($clock_out_time, true);

                $is_clock_out_exists = EssentialsAttendance::where('business_id', $business_id)
                                        ->where('id', '!=', $attendance_id)
                                        ->whereIn('user_id', $user_ids)
                                        ->where('clock_in_time', '<', $clock_out_time)
                                        ->where('clock_out_time', '>', $clock_out_time)
                                        ->exists();
            }

            if ($is_clock_in_exists || $is_clock_out_exists) {
                $is_valid = 'false';
            }
        }

        return $is_valid;
    }

    /**
     * Get attendance summary by shift
     */
    public function getAttendanceByShift()
    {
        $business_id = request()->session()->get('user.business_id');
        $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module') || $is_admin)) {
            abort(403, 'Unauthorized action.');
        }

        $date = $this->moduleUtil->uf_date(request()->input('date'));

        $attendance_data = EssentialsAttendance::where('business_id', $business_id)
                                ->whereDate('clock_in_time', $date)
                                ->whereNotNull('essentials_shift_id')
                                ->with(['shift', 'shift.user_shifts', 'shift.user_shifts.user', 'employee'])
                                ->get();
        $attendance_by_shift = [];
        $date_obj = \Carbon::parse($date);
        foreach ($attendance_data as $data) {
            if (empty($attendance_by_shift[$data->essentials_shift_id])) {
                //Calculate total users in the shift
                $total_users = 0;
                $all_users = [];
                foreach ($data->shift->user_shifts as $user_shift) {
                    if (! empty($user_shift->start_date) && ! empty($user_shift->end_date) && $date_obj->between(\Carbon::parse($user_shift->start_date), \Carbon::parse($user_shift->end_date))) {
                        $total_users++;
                        $all_users[] = $user_shift->user->user_full_name;
                    }
                }
                $attendance_by_shift[$data->essentials_shift_id] = [
                    'present' => 1,
                    'shift' => $data->shift->name,
                    'total' => $total_users,
                    'present_users' => [$data->employee->user_full_name],
                    'all_users' => $all_users,
                ];
            } else {
                if (! in_array($data->employee->user_full_name, $attendance_by_shift[$data->essentials_shift_id]['present_users'])) {
                    $attendance_by_shift[$data->essentials_shift_id]['present']++;
                    $attendance_by_shift[$data->essentials_shift_id]['present_users'][] = $data->employee->user_full_name;
                }
            }
        }

        return view('essentials::attendance.attendance_by_shift_data')->with(compact('attendance_by_shift'));
    }

    /**
     * Get attendance summary by date
     */
    public function getAttendanceByDate()
    {
        $business_id = request()->session()->get('user.business_id');
        $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module') || $is_admin)) {
            abort(403, 'Unauthorized action.');
        }

        $start_date = request()->input('start_date');
        $end_date = request()->input('end_date');

        $attendance_data = EssentialsAttendance::where('business_id', $business_id)
                                ->whereDate('clock_in_time', '>=', $start_date)
                                ->whereDate('clock_in_time', '<=', $end_date)
                                ->select(
                                    'essentials_attendances.*',
                                    DB::raw('COUNT(DISTINCT essentials_attendances.user_id) as total_present'),
                                    DB::raw('CAST(clock_in_time AS DATE) as clock_in_date')
                                )
                                ->groupBy(DB::raw('CAST(clock_in_time AS DATE)'))
                                ->get();

        $all_users = User::where('business_id', $business_id)
                        ->user()
                        ->count();

        $attendance_by_date = [];
        foreach ($attendance_data as $data) {
            $total_present = ! empty($data->total_present) ? $data->total_present : 0;
            $attendance_by_date[] = [
                'present' => $total_present,
                'absent' => $all_users - $total_present,
                'date' => $data->clock_in_date,
            ];
        }

        return view('essentials::attendance.attendance_by_date_data')->with(compact('attendance_by_date'));
    }

    /**
     * Function to import attendance.
     *
     * @param  Request  $request
     * @return Response
     */
    public function importAttendance(Request $request)
    {
        $businessId = $this->authorizeAttendanceImport($request);
        $request->validate(['attendance' => 'required|file|mimes:xls,xlsx|max:5120']);
        $notAllowed = $this->moduleUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return $notAllowed;
        }

        $file = $request->file('attendance');
        $sourceHash = hash_file('sha256', $file->getRealPath());
        $existing = UserImportBatch::where('business_id', $businessId)
            ->where('importer_key', AttendanceImportBatchService::IMPORTER_KEY)
            ->where('source_key', $sourceHash)
            ->first();
        if ($existing && in_array($existing->status, ['queued_preview', 'analyzing', 'importing', 'imported', 'partial'], true)) {
            return response()->json([
                'success' => true,
                'batch_id' => $existing->id,
                'status' => $existing->status,
                'message' => 'File ini sudah tercatat pada riwayat import Attendance.',
            ], 202);
        }
        if ($existing && $existing->status === 'failed') {
            return $this->retryAttendanceBatch($existing, $businessId);
        }
        if ($existing && $existing->status === 'reverted') {
            $sourceHash = hash('sha256', $sourceHash.'|'.Str::uuid());
        }

        $batch = app(UserImportBatchService::class)->create([
            'business_id' => $businessId,
            'created_by' => (int) $request->user()->id,
            'importer_key' => AttendanceImportBatchService::IMPORTER_KEY,
            'source_key' => $sourceHash,
            'file_name' => $file->getClientOriginalName(),
            'status' => 'queued_preview',
            'stage' => 'queued',
            'options_json' => json_encode(['ip_address' => $this->moduleUtil->getUserIpAddr()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_message' => 'Menunggu worker untuk menganalisis workbook Attendance.',
            'heartbeat_at' => now(),
        ]);

        try {
            $extension = strtolower($file->getClientOriginalExtension()) ?: 'xls';
            $path = $file->storeAs('user-imports/'.$batch->id.'/staging', 'source.'.$extension, 'local');
            if (! $path) {
                throw new \RuntimeException('Workbook Attendance tidak dapat disimpan ke storage privat.');
            }
            $batch->staging_path = $path;
            $batch->save();
            app(UserImportBatchService::class)->captureWorkbook($batch, Storage::disk('local')->path($path));
            ProcessAttendanceImportBatch::dispatch((int) $batch->id, $businessId);
        } catch (\Throwable $exception) {
            app(AttendanceImportBatchService::class)->fail($batch, $exception);
            report($exception);

            return response()->json(['success' => false, 'message' => 'Workbook Attendance gagal dimasukkan ke antrean.'], 422);
        }

        return response()->json([
            'success' => true,
            'batch_id' => $batch->id,
            'status' => 'queued_preview',
            'message' => 'Workbook Attendance masuk antrean dan tetap diproses setelah halaman ditutup.',
        ], 202);
    }

    public function importHistory(Request $request, AttendanceImportBatchService $history)
    {
        $businessId = $this->authorizeAttendanceImport($request);

        return response()->json($history->history($request, $businessId, $request->user()->can('essentials.crud_all_attendance')));
    }

    public function importHistoryDetail(Request $request, int $batch, AttendanceImportBatchService $history)
    {
        $businessId = $this->authorizeAttendanceImport($request);
        $detail = $history->detail($batch, $businessId);

        return response()->json([
            'batch' => $detail['batch']->only([
                'id', 'file_name', 'status', 'stage', 'progress_current', 'progress_total',
                'progress_percent', 'summary_json', 'last_message', 'error_message',
                'created_at', 'started_at', 'completed_at', 'failed_at', 'reverted_at',
            ]),
            'results' => $detail['results'],
        ]);
    }

    public function importHistorySource(Request $request, int $batch, UserImportBatchService $batches)
    {
        $businessId = $this->authorizeAttendanceImport($request);
        $shared = $batches->findForBusiness($batch, $businessId, AttendanceImportBatchService::IMPORTER_KEY);
        abort_unless($shared->hasSourceSnapshot(), 404, 'Snapshot workbook Attendance tidak tersedia.');

        return $batches->workbookDownload($shared);
    }

    public function retryImportAttendance(Request $request, int $batch, UserImportBatchService $batches)
    {
        $businessId = $this->authorizeAttendanceImport($request);
        $shared = $batches->findForBusiness($batch, $businessId, AttendanceImportBatchService::IMPORTER_KEY);
        abort_unless($shared->status === 'failed', 422, 'Batch Attendance ini tidak dapat dicoba ulang.');

        return $this->retryAttendanceBatch($shared, $businessId);
    }

    public function revertImportAttendance(Request $request, int $batch, UserImportBatchService $batches, AttendanceImportBatchService $history)
    {
        $businessId = $this->authorizeAttendanceImport($request);
        abort_unless(auth()->user()->can('essentials.crud_all_attendance'), 403, 'Unauthorized action.');
        $shared = $batches->findForBusiness($batch, $businessId, AttendanceImportBatchService::IMPORTER_KEY);
        $history->revert($shared);

        return response()->json(['success' => true, 'message' => 'Record Attendance hasil batch berhasil dikembalikan.']);
    }

    private function retryAttendanceBatch(UserImportBatch $batch, int $businessId)
    {
        abort_unless($batch->hasSourceSnapshot(), 422, 'Snapshot workbook sumber Attendance tidak tersedia.');
        $batch->forceFill([
            'status' => 'queued_preview', 'stage' => 'queued',
            'progress_current' => 0, 'progress_total' => 0, 'progress_percent' => 0,
            'last_message' => 'Menunggu worker untuk mencoba ulang import Attendance.',
            'error_message' => null, 'heartbeat_at' => now(),
            'started_at' => null, 'completed_at' => null, 'failed_at' => null,
        ])->save();
        ProcessAttendanceImportBatch::dispatch((int) $batch->id, $businessId);

        return response()->json(['success' => true, 'batch_id' => $batch->id, 'status' => 'queued_preview', 'message' => 'Batch Attendance masuk antrean ulang.'], 202);
    }

    private function authorizeAttendanceImport(Request $request): int
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $isSuperadmin = $request->user()->can('superadmin');
        $isAdmin = $isSuperadmin ? false : $this->moduleUtil->is_admin($request->user(), $businessId);
        abort_unless(
            $isSuperadmin
                || $this->moduleUtil->hasThePermissionInSubscription($businessId, 'essentials_module')
                || $isAdmin,
            403,
            'Unauthorized action.'
        );

        return $businessId;
    }

    /**
     * Adds attendance row for an employee on add latest attendance form
     *
     * @param  int  $user_id
     * @return Response
     */
    public function getAttendanceRow($user_id)
    {
        $business_id = request()->session()->get('user.business_id');
        $is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'essentials_module') || $is_admin)) {
            abort(403, 'Unauthorized action.');
        }

        $user = User::where('business_id', $business_id)
                    ->findOrFail($user_id);

        $attendance = EssentialsAttendance::where('business_id', $business_id)
                                        ->where('user_id', $user_id)
                                        ->whereNotNull('clock_in_time')
                                        ->whereNull('clock_out_time')
                                        ->first();

        $shifts = Shift::join('essentials_user_shifts as eus', 'eus.essentials_shift_id', '=', 'essentials_shifts.id')
                    ->where('essentials_shifts.business_id', $business_id)
                    ->where('eus.user_id', $user_id)
                    ->where('eus.start_date', '<=', \Carbon::now()->format('Y-m-d'))
                    ->pluck('essentials_shifts.name', 'essentials_shifts.id');

        return view('essentials::attendance.attendance_row')->with(compact('attendance', 'shifts', 'user'));
    }
}
