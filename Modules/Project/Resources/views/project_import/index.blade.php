@extends('layouts.app')
@section('title', __('project::lang.import_project'))

@section('content')
@include('project::layouts.nav')

<section class="content-header">
    <h1>
        @lang('project::lang.import_project')
        <small>@lang('project::lang.import_project_subtitle')</small>
    </h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-8">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-file-excel"></i> @lang('project::lang.upload_project_template')</h3>
                </div>

                {!! Form::open(['action' => '\Modules\Project\Http\Controllers\ProjectImportController@store', 'method' => 'post', 'files' => true]) !!}
                    <div class="box-body">
                        <div class="callout callout-info">
                            <h4>@lang('project::lang.before_importing')</h4>
                            <p>@lang('project::lang.project_import_atomic_note')</p>
                        </div>

                        <div class="form-group @if($errors->has('project_file')) has-error @endif">
                            {!! Form::label('project_file', __('project::lang.project_excel_file') . ':*') !!}
                            {!! Form::file('project_file', ['accept' => '.xlsx,.xls', 'required' => true]) !!}
                            <p class="help-block">@lang('project::lang.project_excel_file_help')</p>
                            @if($errors->has('project_file'))
                                <span class="help-block">{{ $errors->first('project_file') }}</span>
                            @endif
                        </div>

                        <div class="checkbox @if($errors->has('confirm_import')) has-error @endif">
                            <label>
                                {!! Form::checkbox('confirm_import', 1, false, ['required' => true]) !!}
                                @lang('project::lang.confirm_project_import')
                            </label>
                            @if($errors->has('confirm_import'))
                                <span class="help-block">{{ $errors->first('confirm_import') }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-upload"></i> @lang('project::lang.import_project')
                        </button>
                        <a href="{{ action([\Modules\Project\Http\Controllers\ProjectImportController::class, 'downloadTemplate']) }}" class="btn btn-success pull-right">
                            <i class="fa fa-download"></i> @lang('project::lang.download_project_template')
                        </a>
                    </div>
                {!! Form::close() !!}
            </div>
        </div>

        <div class="col-md-4">
            <div class="box box-solid">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang('project::lang.template_contents')</h3>
                </div>
                <div class="box-body">
                    <ol>
                        <li><strong>Project</strong> — @lang('project::lang.template_project_help')</li>
                        <li><strong>Tasks</strong> — @lang('project::lang.template_tasks_help')</li>
                        <li><strong>Time Logs</strong> — @lang('project::lang.template_timelogs_help')</li>
                        <li><strong>Invoice</strong> — @lang('project::lang.template_invoice_help')</li>
                        <li><strong>Referensi</strong> — @lang('project::lang.template_reference_help')</li>
                    </ol>
                    <hr>
                    <p class="text-muted"><i class="fa fa-info-circle"></i> @lang('project::lang.optional_sheets_note')</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
