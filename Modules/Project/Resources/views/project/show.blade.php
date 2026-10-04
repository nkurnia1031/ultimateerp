@extends('layouts.app')
@section('title', __('project::lang.view_project'))
@section('content')
@include('layouts.partials.fleet-datatables')
<section class="content-header">
    <a href="{{ action([\Modules\Project\Http\Controllers\ProjectImportController::class, 'export'], [$project->id]) }}" class="btn btn-success pull-right">
        <i class="fas fa-file-excel"></i> @lang('project::lang.export_project_excel')
    </a>
    <h1>
        <i class="fas fa-check-circle"></i>
        {{ucFirst($project->name)}}
    </h1>
</section>
<section class="content">
	<div class="row">
		<div class="col-md-12">
           <!-- Custom Tabs -->
           <!-- project_id to be used in datatable -->
           	<input type="hidden" name="project_id" id="project_id" value="{{$project->id}}">
            <div class="nav-tabs-custom">
                <ul class="nav nav-tabs">
                    <li class="
                        @if($tab_view == 'overview')
                            active
                        @else
                            ''
                        @endif">
                        <a href="#project_overview" data-toggle="tab" aria-expanded="true" data-url="{{action([\Modules\Project\Http\Controllers\ProjectController::class, 'show'], [$project->id]).'?view=overview'}}">
                        	<i class="fas fa-tachometer-alt"></i>
                        	@lang('project::lang.overview')
                        </a>
                    </li>

                    <li class="
                        @if($tab_view == 'activities')
                            active
                        @else
                            ''
                        @endif">
                        <a href="#activities" data-toggle="tab" aria-expanded="true">
                            <i class="fas fa-chart-line"></i>
                            @lang('lang_v1.activities')
                        </a>
                    </li>

                    <li class="
                        @if($tab_view == 'project_task')
                            active
                        @else
                            ''
                        @endif">
                        <a href="#project_task" data-toggle="tab" aria-expanded="true">
                        	<i class="fa fa-tasks"></i>
                        	@lang('project::lang.task')
                        </a>
                    </li>

                    @if(isset($project->settings['enable_timelog']) && $project->settings['enable_timelog'])
                        <li class="
                            @if($tab_view == 'time_log')
                                active
                            @else
                                ''
                            @endif">
                        	<a href="#time_log" data-toggle="tab" aria-expanded="true">
                        		<i class="fas fa-clock"></i>
                        		@lang('project::lang.time_logs')
                        	</a>
                        </li>
                    @endif

                    @if(isset($project->settings['enable_notes_documents']) && $project->settings['enable_notes_documents'])
                        <li class="
                            @if($tab_view == 'documents_and_notes')
                                active
                            @else
                                ''
                            @endif">
                            <a href="#documents_and_notes" data-toggle="tab" aria-expanded="true">
                                <i class="fas fa-file-image"></i>
                                @lang('project::lang.documents_and_notes')
                            </a>
                        </li>
                    @endif
                    
                    @if((isset($project->settings['enable_invoice']) && $project->settings['enable_invoice']) && $is_lead_or_admin)
                    <li class="
                        @if($tab_view == 'project_invoices')
                            active
                        @else
                            ''
                        @endif">
                        <a href="#project_invoices" data-toggle="tab" aria-expanded="true">
                            <i class="fa fa-file"></i>
                            @lang('project::lang.invoices')
                        </a>
                    </li>
                    @endif

                    @if(auth()->user()->can('asset.view') || auth()->user()->can('asset.fleet_trips.manage') || auth()->user()->can('asset.fleet_trips.create') || auth()->user()->can('asset.fleet_billing.manage') || auth()->user()->can('bonus_ayak.manage') || auth()->user()->can('bonus_ayak.access') || auth()->user()->can('superadmin'))
                    <li class="
                        @if($tab_view == 'heavy_equipment_trips')
                            active
                        @else
                            ''
                        @endif">
                        <a href="#heavy_equipment_trips" data-toggle="tab" aria-expanded="true" data-url="{{ action([\Modules\Project\Http\Controllers\ProjectController::class, 'show'], [$project->id]).'?view=heavy_equipment_trips' }}">
                            <i class="fas fa-truck-moving"></i>
                            Trip Angkutan ({{ $heavy_equipment_trip_count }})
                        </a>
                    </li>
                    @endif

                    @if($is_lead_or_admin)
                    <li class="
                        @if($tab_view == 'project_settings')
                            active
                        @else
                            ''
                        @endif">
                        <a href="#project_settings" data-toggle="tab" aria-expanded="true">
                            <i class="fa fa-cogs"></i>
                            @lang('role.settings')
                        </a>
                    </li>
                    @endif
                </ul>

                <div class="tab-content">
                    <div class="tab-pane
                        @if($tab_view == 'overview')
                            active
                        @else
                            ''
                        @endif" id="project_overview"> 
                        @includeIf('project::project.partials.overview')
                    </div>

                    <div class="tab-pane
                        @if($tab_view == 'activities')
                            active
                        @else
                            ''
                        @endif" id="activities">
                        <ul class="timeline">
                        </ul>
                    </div>

                    <div class="tab-pane
                        @if($tab_view == 'project_task')
                            active
                        @else
                            ''
                        @endif" id="project_task">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    {!! Form::label('assigned_to_filter', __('project::lang.assigned_to') . ':') !!}
                                    <div class="input-group">
                                        <span class="input-group-addon">
                                            <i class="fa fa-user"></i>
                                        </span>
                                        {!! Form::select('assigned_to_filter', $project_members, null, ['class' => 'form-control select2', 'placeholder' => __('messages.all'), 'style' => 'width: 100%;']); !!}
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 status_filter
                                @if(isset($project->settings['task_view']) &&
                                $project->settings['task_view'] == 'kanban')
                                    hide
                                @endif">
                                <div class="form-group">
                                    {!! Form::label('status_filter', __('sale.status') . ':') !!}
                                    {!! Form::select('status_filter', $statuses, null, ['class' => 'form-control select2', 'placeholder' => __('messages.all'), 'style' => 'width: 100%;']); !!}
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    {!! Form::label('priority_filter', __('project::lang.priority') .':') !!}
                                    {!! Form::select('priority_filter', $priorities, null, ['class' => 'form-control select2', 'placeholder' => __('messages.all'), 'style' => 'width: 100%;']); !!}
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    {!! Form::label('due_date_filter', __('project::lang.due_date') . ':') !!}
                                    {!! Form::select('due_date_filter', $due_dates, null, ['class' => 'form-control select2', 'placeholder' => __('messages.all'), 'style' => 'width: 100%;']); !!}
                                </div>
                            </div>
                        </div>
                        @includeIf('project::task.index')
                    </div>

                    @if(isset($project->settings['enable_timelog']) && $project->settings['enable_timelog'])
                        <div class="tab-pane
                            @if($tab_view == 'time_log')
                                active
                            @else
                                ''
                            @endif" id="time_log">
                        	@includeIf('project::time_logs.index')
                        </div>
                    @endif

                    @if(isset($project->settings['enable_notes_documents']) && $project->settings['enable_notes_documents'])
                        <!-- model id like project_id, user_id -->
                        <input type="hidden" name="notable_id" id="notable_id" value="{{$project->id}}">
                        <!-- model name like App\User -->
                        <input type="hidden" name="notable_type" id="notable_type" value="Modules\Project\Entities\Project">
                        <div class="tab-pane document_note_body
                            @if($tab_view == 'documents_and_notes')
                                active
                            @else
                                ''
                            @endif" id="documents_and_notes">
                        </div>
                    @endif

                    @if((isset($project->settings['enable_invoice']) && $project->settings['enable_invoice']) && $is_lead_or_admin)
                        <div class="tab-pane
                            @if($tab_view == 'project_invoices')
                                active
                            @else
                                ''
                            @endif" id="project_invoices">
                            @includeif('project::invoice.index')
                        </div>
                    @endif
                    @if(auth()->user()->can('asset.view') || auth()->user()->can('asset.fleet_trips.manage') || auth()->user()->can('asset.fleet_trips.create') || auth()->user()->can('asset.fleet_billing.manage') || auth()->user()->can('bonus_ayak.manage') || auth()->user()->can('bonus_ayak.access') || auth()->user()->can('superadmin'))
                        <div class="tab-pane
                            @if($tab_view == 'heavy_equipment_trips')
                                active
                            @else
                                ''
                            @endif" id="heavy_equipment_trips">
                            <div class="row">
                                <div class="col-md-2"><strong>Total ton</strong><br>{{ number_format($heavy_equipment_trip_total['tons'], 2, ',', '.') }}</div>
                                <div class="col-md-2"><strong>Pendapatan</strong><br>@format_currency($heavy_equipment_trip_total['revenue'])</div>
                                <div class="col-md-2"><strong>Upah sopir</strong><br>@format_currency($heavy_equipment_trip_total['driver_pay'])</div>
                                <div class="col-md-2"><strong>Bonus Ayak</strong><br>@format_currency($heavy_equipment_trip_total['bonus_ayak'])</div>
                                <div class="col-md-4"><strong>Kontribusi setelah Bonus</strong><br>@format_currency($heavy_equipment_trip_total['contribution'])</div>
                            </div>
                            <br>
                            @if(auth()->user()->can('asset.fleet_trips.manage') || auth()->user()->can('asset.fleet_trips.create') || auth()->user()->can('superadmin'))
                                <a class="btn btn-success" href="{{ route('asset.fleet.trips.create', ['project_id' => $project->id, 'customer_id' => $project->contact_id, 'return_context' => 'project', 'return_id' => $project->id]) }}"><i class="fa fa-plus"></i> Buat SPB</a>
                            @endif
                            @if(auth()->user()->can('asset.fleet_trips.manage') || auth()->user()->can('asset.fleet_billing.manage') || auth()->user()->can('superadmin'))
                                <a class="btn btn-primary" href="{{ route('asset.fleet.trips.project-invoice.choose', ['project_id' => $project->id]) }}"><i class="fa fa-file-invoice"></i> Pilih SPB untuk Invoice</a>
                            @endif
                            @if(auth()->user()->can('bonus_ayak.access') || auth()->user()->can('superadmin'))
                                <a class="btn btn-warning" href="{{ route('bonus-ayak.index', ['project_id' => $project->id]) }}"><i class="fa fa-gift"></i> Tambah Bonus Ayak</a>
                            @endif
                            <a class="btn btn-default" href="{{ route('asset.fleet.trips.index', ['project_id' => $project->id]) }}">Kelola semua SPB Project</a>
                            <br><br>
                            <div class="table-responsive fleet-datatable-wrap"><table id="project-heavy-equipment-trips-table" class="table table-bordered table-striped fleet-datatable"><thead><tr><th>Trip</th><th>Tanggal</th><th>Rute</th><th>Unit</th><th>Sopir</th><th>Ton</th><th>Total</th><th>Status</th></tr></thead><tbody>@forelse($heavy_equipment_trips as $heavyTrip)<tr><td><a href="{{ route('asset.fleet.trips.show', $heavyTrip->id) }}">{{ $heavyTrip->trip_no }}</a></td><td>{{ optional($heavyTrip->trip_date)->format('d-m-Y') }}</td><td>{{ $heavyTrip->route->name }}</td><td>{{ $heavyTrip->asset->name }}</td><td>{{ $heavyTrip->driver->user_full_name }}</td><td>{{ $heavyTrip->actual_tons }}</td><td>@format_currency($heavyTrip->revenue_total)</td><td>{{ strtoupper($heavyTrip->status) }}</td></tr>@empty<tr><td colspan="8" class="text-center">Belum ada trip untuk project ini.</td></tr>@endforelse</tbody></table></div>
                            <div class="well well-sm"><strong>Bonus Ayak Project:</strong> @format_currency($heavy_equipment_bonus_total) <span class="text-muted">(bukan line invoice pelanggan)</span></div>
                            <div class="table-responsive fleet-datatable-wrap"><table id="project-heavy-equipment-bonus-table" class="table table-bordered table-striped fleet-datatable"><thead><tr><th>Tanggal</th><th>Sopir</th><th>Unit</th><th>Trip</th><th>Bonus</th></tr></thead><tbody>@forelse($heavy_equipment_bonus_ayaks as $bonusAyak)<tr><td>{{ optional($bonusAyak->tanggal)->format('d-m-Y') }}</td><td>{{ optional($bonusAyak->driver)->user_full_name ?: $bonusAyak->nama_operator }}</td><td>{{ optional($bonusAyak->asset)->name ?: $bonusAyak->unit }}</td><td>{{ $bonusAyak->trip }}</td><td>@format_currency($bonusAyak->bonus)</td></tr>@empty<tr><td colspan="5" class="text-center">Belum ada Bonus Ayak untuk project ini.</td></tr>@endforelse</tbody></table></div>
                        </div>
                    @endif
                    @if($is_lead_or_admin)
                        <div class="tab-pane
                            @if($tab_view == 'project_settings')
                                active
                            @else
                                ''
                            @endif" id="project_settings">
                            @includeIf('project::settings.create')
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade project_task_model" tabindex="-1" role="dialog"></div>
    <div class="modal fade" tabindex="-1" role="dialog" id="time_log_model"></div>
    <div class="modal fade view_project_task_model" tabindex="-1" role="dialog"></div>
    <div class="modal fade payment_modal" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel"></div>
    <div class="modal fade edit_payment_modal" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel"></div>
</section>
<link rel="stylesheet" href="{{ asset('modules/project/sass/project.css?v=' . $asset_v) }}">
@endsection
@section('javascript')
<script src="{{ asset('modules/project/js/project.js?v=' . $asset_v) }}"></script>
<script src="{{ asset('js/payment.js?v=' . $asset_v) }}"></script>
<script type="text/javascript">
    var tab_view = '{!!$tab_view!!}';

    if (tab_view == 'activities') {
        initializeActivities();
    } else if (tab_view == 'project_task') {
        initializeProjectTaskDatatable();
    } else if (tab_view == 'time_log') {
        initializeTimeLogDatatable();
    } else if (tab_view == 'documents_and_notes') {
        initializeNotesDataTable();
    } else if (tab_view == 'project_invoices') {
        initializeInvoiceDatatable();
    }
</script>
@endsection
@push('scripts')
<script>
$(function () {
    initFleetDataTable('#project-heavy-equipment-trips-table', { order: [[1, 'desc']], columnDefs: [{ targets: [0, 2, 3, 4, 5, 6, 7], orderable: false }] });
    initFleetDataTable('#project-heavy-equipment-bonus-table', { order: [[0, 'desc']], columnDefs: [{ targets: [1, 2, 3, 4], orderable: false }] });
});
</script>
@endpush
