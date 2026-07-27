@extends('layouts.app')
@section('title', 'Project Ledger')

@section('content')
@include('project::layouts.nav')

<section class="content-header">
    <h1>
        {{$context['title']}}
        @if(!empty($context['subtitle']))
            <small>{{$context['subtitle']}}</small>
        @endif
    </h1>
</section>

<section class="content no-print">
    @if(!empty($context['tags']) && $context['tags']->count() > 0)
        <div class="box box-solid">
            <div class="box-body">
                <strong>Tags:</strong>
                @foreach($context['tags'] as $tag)
                    <span class="label bg-blue">{{$tag->name}}</span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="row">
        <div class="col-md-3 col-sm-6">
            <div class="info-box info-box-new-style">
                <span class="info-box-icon bg-aqua"><i class="fas fa-clock"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('project::lang.total_time')</span>
                    <span class="info-box-number">{{$ledger_details['total_hours']}}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="info-box info-box-new-style">
                <span class="info-box-icon bg-green"><i class="fas fa-file-invoice-dollar"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('project::lang.invoice')</span>
                    <span class="info-box-number">@format_currency($ledger_details['total_invoice'])</span>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="info-box info-box-new-style">
                <span class="info-box-icon bg-yellow"><i class="fas fa-money-check"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('sale.total_paid')</span>
                    <span class="info-box-number">@format_currency($ledger_details['total_paid'])</span>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="info-box info-box-new-style">
                <span class="info-box-icon bg-purple"><i class="fas fa-tasks"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('project::lang.time_logs')</span>
                    <span class="info-box-number">{{$ledger_details['total_rows']}}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Project Work Ledger</h3>
        </div>
        <div class="box-body">
            <div class="table-responsive">
                <table class="table table-striped" id="project_work_ledger_table">
                    <thead>
                        <tr>
                            <th>@lang('project::lang.project')</th>
                            <th>@lang('project::lang.task')</th>
                            <th>@lang('role.user')</th>
                            <th>@lang('project::lang.start_date_time')</th>
                            <th>@lang('project::lang.end_date_time')</th>
                            <th>@lang('project::lang.work_hour')</th>
                            <th>Tags</th>
                            <th>@lang('contact.customer')</th>
                            <th>@lang('sale.invoice_no')</th>
                            <th class="align-right">@lang('project::lang.invoice')</th>
                            <th class="align-right">@lang('sale.total_paid')</th>
                            <th>@lang('brand.note')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ledger_details['rows'] as $row)
                            <tr>
                                <td>{{$row->project_name}}</td>
                                <td>
                                    @if(!empty($row->task_subject))
                                        {{$row->task_subject}}
                                        <small><code>{{$row->task_id}}</code></small>
                                    @endif
                                </td>
                                <td>{{$row->user_name}}</td>
                                <td>{{@format_datetime($row->start_datetime)}}@if($row->second_start_datetime)<br>{{@format_datetime($row->second_start_datetime)}}@endif</td>
                                <td>{{@format_datetime($row->end_datetime)}}@if($row->second_end_datetime)<br>{{@format_datetime($row->second_end_datetime)}}@endif</td>
                                <td>{{$row->work_hour}}</td>
                                <td>{{$row->tag_names}}</td>
                                <td>{{$row->customer_name}}</td>
                                <td>{{$row->invoice_nos}}</td>
                                <td class="align-right">@format_currency($row->allocated_invoice_total)</td>
                                <td class="align-right">@format_currency($row->allocated_paid_total)</td>
                                <td>{!! nl2br(e($row->note)) !!}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="text-center">
                                    @lang('project::lang.no_record_found')
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="help-block">
                Nilai invoice dan paid dialokasikan proporsional berdasarkan durasi timelog dalam project.
            </p>
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $('#project_work_ledger_table').DataTable({
            searching: true,
            ordering: false,
            paging: false,
            fixedHeader: false
        });

        __currency_convert_recursively($('.content'));
    });
</script>
@endsection
