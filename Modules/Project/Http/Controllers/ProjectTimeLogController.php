<?php

namespace Modules\Project\Http\Controllers;

use App\Tag;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Project\Entities\Project;
use Modules\Project\Entities\ProjectMember;
use Modules\Project\Entities\ProjectTask;
use Modules\Project\Entities\ProjectTimeLog;
use Modules\Project\Utils\ProjectUtil;
use Yajra\DataTables\Facades\DataTables;

class ProjectTimeLogController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $commonUtil;

    protected $projectUtil;

    /**
     * Constructor
     *
     * @param CommonUtil
     * @return void
     */
    public function __construct(Util $commonUtil, ProjectUtil $projectUtil)
    {
        $this->commonUtil = $commonUtil;
        $this->projectUtil = $projectUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $project_id = request()->get('project_id');

            $project = Project::where('business_id', $business_id)
                            ->findOrFail($project_id);

            $project_task_time_logs = ProjectTimeLog::where('project_id', $project_id)
                ->with('task', 'task.tags', 'tags', 'user', 'project')
                ->select('*');

            // check permission for crud time log
            $is_admin = $this->commonUtil->is_admin(auth()->user(), $business_id);
            $is_lead = $this->projectUtil->isProjectLead(auth()->user()->id, $project_id);
            $is_member = $this->projectUtil->isProjectMember(auth()->user()->id, $project_id);

            $can_crud = false;
            if ($is_admin || $is_lead) {
                $can_crud = true;
            } elseif ($is_member && (isset($project->settings['members_crud_timelog']) && $project->settings['members_crud_timelog'])) {
                $can_crud = true;
            }

            return Datatables::of($project_task_time_logs)
                    ->addColumn('action', function ($row) use ($can_crud) {
                        $html = '<div class="btn-group">
                                    <button class="btn btn-info dropdown-toggle btn-xs" type="button"  data-toggle="dropdown" aria-expanded="false">
                                        '.__('messages.action').'
                                        <span class="caret"></span>
                                        <span class="sr-only">
                                        '.__('messages.action').'
                                        </span>
                                    </button>
                                    ';

                        if ($can_crud) {
                            $html .= '<ul class="dropdown-menu dropdown-menu-left" role="menu">
                                <li>
                                    <a data-href="'.action([\Modules\Project\Http\Controllers\ProjectTimeLogController::class, 'edit'], [$row->id, 'project_id' => $row->project_id]).'" class="cursor-pointer time_log_btn">
                                        <i class="fa fa-edit"></i>
                                        '.__('messages.edit').'
                                    </a>
                                </li>
                                <li>
                                    <a data-href="'.action([\Modules\Project\Http\Controllers\ProjectTimeLogController::class, 'destroy'], [$row->id]).'"  id="delete_a_time_log" class="cursor-pointer">
                                        <i class="fas fa-trash"></i>
                                        '.__('messages.delete').'
                                    </a>
                                </li>
                                </ul>';
                        }

                        $html .= '
                                </div>';

                        return $html;
                    })
                ->editColumn('task', function ($row) {
                    $task = '';
                    if (! empty($row->task)) {
                        $html = ' <code>('.$row->task->task_id.')</code>';
                        $task = $row->task->subject.$html;
                    }

                    return $task;
                })
                ->addColumn('shift', function ($row) {
                    return $row->shift ? __('project::lang.shift_'.strtolower($row->shift)) : '-';
                })
                ->addColumn('work_period_1', function ($row) {
                    return $this->commonUtil->format_date($row->start_datetime, true).' - '.$this->commonUtil->format_date($row->end_datetime, true);
                })
                ->addColumn('work_period_2', function ($row) {
                    if (empty($row->second_start_datetime) || empty($row->second_end_datetime)) {
                        return '-';
                    }

                    return $this->commonUtil->format_date($row->second_start_datetime, true).' - '.$this->commonUtil->format_date($row->second_end_datetime, true);
                })
                ->editColumn('work_hour', function ($row) {
                    return $row->formatted_duration;
                })
                ->editColumn('user', function ($row) {
                    return $row->user?->user_full_name;
                })
                ->addColumn('tags', function ($row) {
                    $tags = $row->tags;

                    if ($tags->isEmpty() && ! empty($row->task)) {
                        $tags = $row->task->tags;
                    }

                    if ($tags->isEmpty()) {
                        return '';
                    }

                    return $tags->map(function ($tag) {
                        return '<span class="label bg-blue">'.$tag->name.'</span>';
                    })->implode(' ');
                })
                ->removeColumn('id')
                ->rawColumns(['action', 'task', 'tags'])
                ->make(true);
        }

        return view('project::time_logs.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $business_id = request()->session()->get('user.business_id');
        $project_id = request()->get('project_id');
        $task_id = request()->get('task_id', null);

        $project_tasks = ProjectTask::taskDropdown($project_id);
        $project_members = ProjectMember::projectMembersDropdown($project_id);
        $tags = Tag::where('business_id', $business_id)->pluck('name', 'id');

        //check if user is admin/lead & can add time log for other user
        $business_id = request()->session()->get('user.business_id');
        $is_admin = $this->commonUtil->is_admin(auth()->user(), $business_id);
        $is_lead = $this->projectUtil->isProjectLead(auth()->user()->id, $project_id);

        $is_lead_or_admin = false;
        if ($is_admin || $is_lead) {
            $is_lead_or_admin = true;
        }

        $added_from = request()->input('added_from');

        return view('project::time_logs.create')
            ->with(compact('project_tasks', 'project_id', 'project_members', 'is_lead_or_admin', 'added_from', 'task_id', 'tags'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        try {
            $this->validateTimeLog($request);
            $input = $request->only('project_id', 'project_task_id', 'note', 'shift');

            $input['start_datetime'] = $this->commonUtil->uf_date($request->input('start_datetime'), true);
            $input['end_datetime'] = $this->commonUtil->uf_date($request->input('end_datetime'), true);
            $input['second_start_datetime'] = $this->parseOptionalDatetime($request->input('second_start_datetime'));
            $input['second_end_datetime'] = $this->parseOptionalDatetime($request->input('second_end_datetime'));
            $input['created_by'] = $request->session()->get('user.id');

            //check if time log is creating by admin/lead
            $business_id = request()->session()->get('user.business_id');
            $is_admin = $this->commonUtil->is_admin(auth()->user(), $business_id);
            $is_lead = $this->projectUtil->isProjectLead(auth()->user()->id, $input['project_id']);

            if ($is_admin || $is_lead) {
                $input['user_id'] = $request->get('user_id');
            } else {
                $input['user_id'] = $request->session()->get('user.id');
            }

            $project_time_log = ProjectTimeLog::create($input);
            $this->syncTimeLogTags($project_time_log, $request->input('tag_ids', []), $business_id);

            $task_timelog_html = '';
            $added_from = $request->get('added_from');
            if (! empty($added_from) && $added_from == 'task') {
                $project_task = ProjectTask::with(['timeLogs', 'timeLogs.user'])
                        ->where('project_id', $input['project_id'])
                        ->findOrFail($input['project_task_id']);

                $task_timelog_html = view('project::task.partials.time_log_table_body')
                ->with(compact('project_task'))
                ->render();
            }

            $output = [
                'success' => true,
                'task_timelog_html' => $task_timelog_html,
                'added_from' => $added_from,
                'msg' => __('lang_v1.success'),
            ];
        } catch (ValidationException $e) {
            $output = ['success' => false, 'msg' => $e->validator->errors()->first()];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return $output;
    }

    /**
     * Show the specified resource.
     *
     * @return Response
     */
    public function show()
    {
        return view('project::show');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return Response
     */
    public function edit($id)
    {
        $project_id = request()->get('project_id');

        $project_tasks = ProjectTask::taskDropdown($project_id);
        $project_task_time_log = ProjectTimeLog::with('tags')
            ->where('project_id', $project_id)
            ->findOrFail($id);
        $project_members = ProjectMember::projectMembersDropdown($project_id);
        //check if user is admin/lead & can add time log for other user
        $business_id = request()->session()->get('user.business_id');
        $tags = Tag::where('business_id', $business_id)->pluck('name', 'id');
        $is_admin = $this->commonUtil->is_admin(auth()->user(), $business_id);
        $is_lead = $this->projectUtil->isProjectLead(auth()->user()->id, $project_id);

        $is_lead_or_admin = false;
        if ($is_admin || $is_lead) {
            $is_lead_or_admin = true;
        }

        return view('project::time_logs.edit')
            ->with(compact('project_tasks', 'project_task_time_log', 'is_lead_or_admin', 'project_members', 'tags'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function update(Request $request, $id)
    {
        try {
            $this->validateTimeLog($request);
            $input = $request->only('note', 'project_task_id', 'shift');

            if (! empty($request->input('start_datetime'))) {
                $input['start_datetime'] = $this->commonUtil->uf_date($request->input('start_datetime'), true);
            }

            if (! empty($request->input('end_datetime'))) {
                $input['end_datetime'] = $this->commonUtil->uf_date($request->input('end_datetime'), true);
            }

            $input['second_start_datetime'] = $this->parseOptionalDatetime($request->input('second_start_datetime'));
            $input['second_end_datetime'] = $this->parseOptionalDatetime($request->input('second_end_datetime'));

            //check if time log is creating by admin/lead
            $business_id = request()->session()->get('user.business_id');
            $is_admin = $this->commonUtil->is_admin(auth()->user(), $business_id);
            $project_id = $request->get('project_id');
            $is_lead = $this->projectUtil->isProjectLead(auth()->user()->id, $project_id);

            if ($is_admin || $is_lead) {
                $input['user_id'] = $request->get('user_id');
            } else {
                $input['user_id'] = $request->session()->get('user.id');
            }

            $project_task_time_log = ProjectTimeLog::where('project_id', $project_id)
                ->findOrFail($id);

            $project_task_time_log->update($input);
            $this->syncTimeLogTags($project_task_time_log, $request->input('tag_ids', []), $business_id);

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (ValidationException $e) {
            $output = ['success' => false, 'msg' => $e->validator->errors()->first()];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return $output;
    }

    private function syncTimeLogTags(ProjectTimeLog $project_time_log, $tag_ids, $business_id)
    {
        $tag_ids = Tag::where('business_id', $business_id)
            ->whereIn('id', (array) $tag_ids)
            ->pluck('id')
            ->toArray();

        $project_time_log->tags()->sync($tag_ids);
    }

    private function validateTimeLog(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shift' => 'required|in:pagi,malam',
            'start_datetime' => 'required',
            'end_datetime' => 'required',
            'second_start_datetime' => 'nullable|required_with:second_end_datetime',
            'second_end_datetime' => 'nullable|required_with:second_start_datetime',
        ]);

        $validator->after(function ($validator) use ($request) {
            foreach ([
                ['start_datetime', 'end_datetime'],
                ['second_start_datetime', 'second_end_datetime'],
            ] as $period) {
                [$startField, $endField] = $period;
                if (empty($request->input($startField)) || empty($request->input($endField))) {
                    continue;
                }

                $start = $this->commonUtil->uf_date($request->input($startField), true);
                $end = $this->commonUtil->uf_date($request->input($endField), true);
                if (strtotime($end) <= strtotime($start)) {
                    $validator->errors()->add($endField, __('project::lang.end_must_be_after_start'));
                }
            }
        });

        $validator->validate();
    }

    private function parseOptionalDatetime($value)
    {
        return empty($value) ? null : $this->commonUtil->uf_date($value, true);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return Response
     */
    public function destroy($id)
    {
        try {
            $project_task_time_log = ProjectTimeLog::findOrFail($id);
            $project_task_time_log->delete();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return $output;
    }
}
