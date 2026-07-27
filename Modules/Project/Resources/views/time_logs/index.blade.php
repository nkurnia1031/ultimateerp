@if($can_crud_timelog || $is_lead_or_admin)
    <button type="button" class="btn btn-sm btn-primary time_log_btn pull-right" data-href="{{action([\Modules\Project\Http\Controllers\ProjectTimeLogController::class, 'create'], ['project_id' => $project->id])}}">
        @lang('messages.add')&nbsp;
        <i class="fa fa-plus"></i>
    </button> <br><br>
@endif
<div class="table-responsive">
    <table class="table table-bordered table-striped" id="time_logs_table" style="width: 100%;">
        <thead>
            <tr>
                <th> @lang('messages.action')</th>
                <th> @lang('project::lang.task')</th>
                <th>Tags</th>
                <th>@lang('project::lang.shift')</th>
                <th>@lang('project::lang.work_period_1')</th>
                <th>@lang('project::lang.work_period_2')</th>
                <th>@lang('project::lang.total_work_hours')</th>
                <th>@lang('role.user')</th>
                <th>@lang('brand.note')</th>
            </tr>
        </thead>
    </table>
</div>
