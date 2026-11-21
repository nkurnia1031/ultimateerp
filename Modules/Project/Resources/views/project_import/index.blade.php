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

                {!! Form::open(['action' => '\Modules\Project\Http\Controllers\ProjectImportController@store', 'method' => 'post', 'files' => true, 'id' => 'project_import_form']) !!}
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
                        <div id="project_import_upload_progress" class="progress hidden" aria-live="polite">
                            <div class="progress-bar progress-bar-primary" role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <div id="project_import_upload_message" class="help-block" aria-live="polite"></div>
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

    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-header with-border">
                    <h3 class="box-title">Riwayat Import Project</h3>
                    <div class="box-tools pull-right">
                        <select id="project_import_history_status" class="form-control input-sm">
                            <option value="">Semua status</option>
                            <option value="queued_preview">Menunggu</option>
                            <option value="analyzing">Diproses</option>
                            <option value="imported">Selesai</option>
                            <option value="failed">Gagal</option>
                            <option value="reverted">Direvert</option>
                        </select>
                    </div>
                </div>
                <div class="box-body table-responsive">
                    <table class="table table-bordered table-striped" id="project_import_history_table" width="100%">
                        <thead><tr><th>File</th><th>Status</th><th>Progress</th><th>Pesan</th><th>Dibuat</th><th>Aksi</th></tr></thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="project_import_detail_modal" tabindex="-1" role="dialog" aria-labelledby="project_import_detail_title">
    <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="project_import_detail_title">Detail Import Project</h4></div>
        <div class="modal-body"><pre id="project_import_detail_body" style="max-height:60vh;overflow:auto;white-space:pre-wrap"></pre></div>
    </div></div>
</div>
@endsection

@section('javascript')
<script>
    $(function () {
        var csrf = $('meta[name="csrf-token"]').attr('content');
        var historyTable = $('#project_import_history_table').DataTable({
            processing: true,
            serverSide: true,
            searching: true,
            ajax: {
                url: @json(route('project.import.history')),
                data: function (data) {
                    data.status = $('#project_import_history_status').val() || '';
                }
            },
            columns: [
                {data: 'file_name', name: 'file_name'},
                {data: 'status', name: 'status'},
                {data: null, orderable: false, searchable: false, render: function (row) {
                    var percent = Math.max(0, Math.min(100, parseInt(row.progress_percent || 0, 10)));
                    return '<div class="progress"><div class="progress-bar" role="progressbar" style="width:' + percent + '%">' + percent + '%</div></div>' +
                        '<small>' + (parseInt(row.progress_current || 0, 10)) + ' / ' + (parseInt(row.progress_total || 0, 10) || '—') + '</small>';
                }},
                {data: 'last_message', name: 'last_message', defaultContent: ''},
                {data: 'created_at', name: 'created_at'},
                {data: null, orderable: false, searchable: false, render: function (row) {
                    var actions = '<button type="button" class="btn btn-xs btn-default project-import-detail" data-url="' + row.detail_url + '">Detail</button> ';
                    if (row.restore_url) {
                        actions += '<a class="btn btn-xs btn-success" href="' + row.restore_url + '">Unduh sumber</a> ';
                    }
                    if (row.can_retry) {
                        actions += '<button type="button" class="btn btn-xs btn-warning project-import-retry" data-url="' + row.retry_url + '">Coba lagi</button> ';
                    }
                    if (row.can_revert) {
                        actions += '<button type="button" class="btn btn-xs btn-danger project-import-revert" data-url="' + row.revert_url + '">Revert</button>';
                    }
                    return actions;
                }}
            ],
            order: [[4, 'desc']]
        });
        $('#project_import_history_status').on('change', function () { historyTable.ajax.reload(); });

        $('#project_import_form').on('submit', function (event) {
            event.preventDefault();
            var form = this;
            var progress = $('#project_import_upload_progress').removeClass('hidden').find('.progress-bar').css('width', '0%').text('0%');
            $('#project_import_upload_message').text('Mengunggah workbook…');
            $.ajax({
                url: $(form).attr('action'),
                method: 'POST',
                data: new FormData(form),
                processData: false,
                contentType: false,
                dataType: 'json',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                xhr: function () {
                    var xhr = $.ajaxSettings.xhr();
                    if (xhr.upload) {
                        xhr.upload.addEventListener('progress', function (event) {
                            if (event.lengthComputable) {
                                var percent = Math.round((event.loaded / event.total) * 100);
                                progress.css('width', percent + '%').text(percent + '%');
                            }
                        });
                    }
                    return xhr;
                }
            }).done(function (result) {
                $('#project_import_upload_message').text(result.message || 'Batch import dibuat.');
                form.reset();
                historyTable.ajax.reload(null, false);
            }).fail(function (xhr) {
                var message = xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.errors && xhr.responseJSON.errors.project_file && xhr.responseJSON.errors.project_file[0]);
                $('#project_import_upload_message').text(message || 'Workbook gagal dimasukkan ke antrean.');
            });
        });

        $(document).on('click', '.project-import-detail', function () {
            $.getJSON($(this).data('url')).done(function (result) {
                $('#project_import_detail_body').text(JSON.stringify(result, null, 2));
                $('#project_import_detail_modal').modal('show');
            });
        });
        $(document).on('click', '.project-import-retry, .project-import-revert', function () {
            var button = $(this);
            var isRevert = button.hasClass('project-import-revert');
            swal({title: LANG.sure, icon: 'warning', buttons: true, dangerMode: isRevert}).then(function (confirmed) {
                if (!confirmed) return;
                $.ajax({url: button.data('url'), method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}})
                    .done(function (result) { toastr.success(result.message || 'Selesai.'); historyTable.ajax.reload(null, false); })
                    .fail(function (xhr) { toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Aksi gagal.'); });
            });
        });
        window.setInterval(function () { historyTable.ajax.reload(null, false); }, 5000);
    });
</script>
@endsection
