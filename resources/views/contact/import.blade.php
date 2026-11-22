@extends('layouts.app')
@section('title', __('lang_v1.import_contacts'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('lang_v1.import_contacts')
    </h1>
</section>

<!-- Main content -->
<section class="content">
    
    @if (session('notification') || !empty($notification))
        <div class="row">
            <div class="col-sm-12">
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    @if(!empty($notification['msg']))
                        {{$notification['msg']}}
                    @elseif(session('notification.msg'))
                        {{ session('notification.msg') }}
                    @endif
                </div>
            </div>  
        </div>     
    @endif
    
    <div class="row">
        <div class="col-sm-12">
            @component('components.widget', ['class' => 'box-primary'])
                {!! Form::open(['url' => route('contacts.import.upload'), 'method' => 'post', 'enctype' => 'multipart/form-data', 'id' => 'contact_csv_import_form' ]) !!}
                    <div class="row">
                        <div class="col-sm-6">
                        <div class="col-sm-8">
                            <div class="form-group">
                                {!! Form::label('name', __( 'product.file_to_import' ) . ':') !!}
                                {!! Form::file('contacts_csv', ['accept'=> '.xls,.xlsx,.csv', 'required' => 'required']); !!}
                              </div>
                        </div>
                        <div class="col-sm-4">
                        <br>
                            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('messages.submit')</button>
                        </div>
                        </div>
                    </div>
                    <div id="contact_csv_upload_progress" class="progress hidden" aria-live="polite">
                        <div class="progress-bar" role="progressbar" style="width:0%">0%</div>
                    </div>

                {!! Form::close() !!}
                <br><br>
                <div class="row">
                    <div class="col-sm-4">
                        <a href="{{ asset('files/import_contacts_csv_template.xls') }}" class="tw-dw-btn tw-dw-btn-success tw-text-white" download><i class="fa fa-download"></i> @lang('lang_v1.download_template_file')</a>
                    </div>
                </div>
            @endcomponent
        </div>
    </div>
    <div class="row">
        <div class="col-sm-12">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Riwayat import kontak'])
                <div class="row">
                    <div class="col-sm-3 form-group">
                        <label for="contact_import_status">Status</label>
                        <select id="contact_import_status" class="form-control">
                            <option value="">Semua status</option>
                            <option value="queued_preview">queued</option>
                            <option value="analyzing">memproses</option>
                            <option value="imported">selesai</option>
                            <option value="partial">sebagian gagal</option>
                            <option value="failed">gagal</option>
                            <option value="reverted">direvert</option>
                        </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="contact_import_history" class="table table-bordered table-striped">
                        <thead><tr><th>ID</th><th>File</th><th>Status</th><th>Progres</th><th>Dibuat</th><th>Aksi</th></tr></thead>
                    </table>
                </div>
                <div id="contact_import_detail" class="well hidden">
                    <h4>Detail hasil import</h4>
                    <div class="contact-import-summary"></div>
                    <div class="table-responsive">
                        <table class="table table-condensed table-bordered">
                            <thead><tr><th>Sheet</th><th>Baris</th><th>Status</th><th>Hasil / error</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            @endcomponent
        </div>
    </div>
    <div class="row">
        <div class="col-sm-12">
            @component('components.widget', ['class' => 'box-primary', 'title' => __('lang_v1.instructions')])
                <strong>@lang('lang_v1.instruction_line1')</strong><br>
                    @lang('lang_v1.instruction_line2')
                    <br><br>
                <table class="table table-striped">
                    <tr>
                        <th>@lang('lang_v1.col_no')</th>
                        <th>@lang('lang_v1.col_name')</th>
                        <th>@lang('lang_v1.instruction')</th>
                    </tr>
                    <tr>
                        <td>1</td>
                        <td>@lang('contact.contact_type') <small class="text-muted">(@lang('lang_v1.required'))</small></td>
                        <td>{!! __('lang_v1.import_contact_type_ins') !!}</td>
                    </tr>
                    <tr>
                        <td>2</td>
                        <td>@lang('business.prefix') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>3</td>
                        <td>@lang('business.first_name') <small class="text-muted">(@lang('lang_v1.required'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>4</td>
                        <td>@lang('lang_v1.middle_name') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>5</td>
                        <td>@lang('business.last_name') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>6</td>
                        <td>@lang('business.business_name') <br><small class="text-muted">(@lang('lang_v1.required_if_supplier'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>7</td>
                        <td>@lang('lang_v1.contact_id') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>@lang('lang_v1.contact_id_ins')</td>
                    </tr>
                    <tr>
                        <td>8</td>
                        <td>@lang('contact.tax_no') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>9</td>
                        <td>@lang('lang_v1.opening_balance') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>10</td>
                        <td>@lang('contact.pay_term') <br><small class="text-muted">(@lang('lang_v1.required_if_supplier'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>11</td>
                        <td>@lang('contact.pay_term_period') <br><small class="text-muted">(@lang('lang_v1.required_if_supplier'))</small></td>
                        <td><strong>@lang('lang_v1.pay_term_period_ins')</strong></td>
                    </tr>
                    <tr>
                        <td>12</td>
                        <td>@lang('lang_v1.credit_limit') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>13</td>
                        <td>@lang('business.email') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>14</td>
                        <td>@lang('contact.mobile') <small class="text-muted">(@lang('lang_v1.required'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>15</td>
                        <td>@lang('contact.alternate_contact_number') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>16</td>
                        <td>@lang('contact.landline') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>17</td>
                        <td>@lang('business.city') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>18</td>
                        <td>@lang('business.state') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>19</td>
                        <td>@lang('business.country') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>20</td>
                        <td>@lang('lang_v1.address_line_1') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>21</td>
                        <td>@lang('lang_v1.address_line_2') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>22</td>
                        <td>@lang('business.zip_code') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>23</td>
                        <td>@lang('lang_v1.dob') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>@lang('lang_v1.dob_ins') ({{\Carbon::now()->format('Y-m-d')}})</td>
                    </tr>
                    @php
                        $custom_labels = json_decode(session('business.custom_labels'), true);
                    @endphp
                    <tr>
                        <td>24</td>
                         <td>{{ $custom_labels['contact']['custom_field_1'] ?? __('lang_v1.contact_custom_field1') }} <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>25</td>
                        <td>{{ $custom_labels['contact']['custom_field_2'] ?? __('lang_v1.contact_custom_field2') }} <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>26</td>
                        <td>{{ $custom_labels['contact']['custom_field_3'] ?? __('lang_v1.contact_custom_field3') }} <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>27</td>
                        <td>{{ $custom_labels['contact']['custom_field_4'] ?? __('lang_v1.contact_custom_field4') }} <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                </table>
            @endcomponent
        </div>
    </div>
</section>
<!-- /.content -->

<script>
    $(function() {
        function escapeContactImport(value) {
            return $('<div>').text(value == null ? '' : value).html();
        }
        var contactImportHistory = $('#contact_import_history').DataTable({
            processing: true,
            serverSide: true,
            searchDelay: 350,
            pageLength: 25,
            order: [[0, 'desc']],
            ajax: {
                url: @json(route('contacts.import.history')),
                data: function(data) { data.status = $('#contact_import_status').val(); }
            },
            columns: [
                {data: 'id', name: 'id'},
                {data: 'file_name', name: 'file_name', render: escapeContactImport},
                {data: 'status', name: 'status'},
                {data: 'progress_percent', name: 'progress_percent', orderable: false, render: function(value, type, row) {
                    return escapeContactImport(value || 0) + '% (' + escapeContactImport(row.progress_current || 0) + '/' + escapeContactImport(row.progress_total || 0) + ')';
                }},
                {data: 'created_at', name: 'created_at'},
                {data: null, orderable: false, searchable: false, render: function(row) {
                    var actions = '<button class="btn btn-xs btn-info contact-import-detail" data-url="' + escapeContactImport(row.detail_url) + '">Detail</button> ';
                    if (row.restore_url) actions += '<a class="btn btn-xs btn-default" href="' + escapeContactImport(row.restore_url) + '">Unduh sumber</a> ';
                    if (row.can_retry) actions += '<button class="btn btn-xs btn-warning contact-import-retry" data-url="' + escapeContactImport(row.retry_url) + '">Coba ulang</button> ';
                    if (row.can_revert) actions += '<button class="btn btn-xs btn-danger contact-import-revert" data-url="' + escapeContactImport(row.revert_url) + '">Revert</button>';
                    return actions;
                }}
            ]
        });
        window.setInterval(function() {
            var active = contactImportHistory.rows().data().toArray().some(function(row) { return row.active; });
            if (active) contactImportHistory.ajax.reload(null, false);
        }, 5000);
        $('#contact_import_status').on('change', function() { contactImportHistory.ajax.reload(); });

        $('#contact_csv_import_form').on('submit', function(event) {
            event.preventDefault();
            var form = this;
            var submit = $(form).find('button[type="submit"]');
            submit.prop('disabled', true);
            $.ajax({
                method: 'POST',
                url: $(form).attr('action'),
                data: new FormData(form),
                processData: false,
                contentType: false,
                dataType: 'json',
                xhr: function() {
                    var xhr = $.ajaxSettings.xhr();
                    if (xhr.upload) xhr.upload.addEventListener('progress', function(progress) {
                        if (progress.lengthComputable) {
                            var percent = Math.round(progress.loaded / progress.total * 100);
                            $('#contact_csv_upload_progress').removeClass('hidden').find('.progress-bar').css('width', percent + '%').text(percent + '%');
                        }
                    });
                    return xhr;
                }
            }).done(function(result) {
                toastr.success(result.message || 'File kontak masuk antrean.');
                $('#contact_csv_upload_progress').removeClass('hidden').find('.progress-bar').css('width', '100%').text('Upload selesai; batch #' + result.batch_id + ' berjalan');
                form.reset();
                contactImportHistory.ajax.reload();
            }).fail(function(xhr) {
                toastr.error((xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.msg)) || 'File kontak gagal diunggah.');
            }).always(function() { submit.prop('disabled', false); });
        });

        $(document).on('click', '.contact-import-detail', function() {
            var panel = $('#contact_import_detail');
            $.get($(this).data('url')).done(function(payload) {
                var summary = payload.results.summary || {};
                panel.find('.contact-import-summary').text('Batch #' + payload.batch.id + ' · dibuat ' + (summary.created_rows || 0) + ', cocok ' + (summary.matched_rows || 0) + ', dilewati ' + (summary.skipped_rows || 0) + ', gagal ' + (summary.failed_rows || 0));
                var body = panel.find('tbody').empty();
                (payload.results.row_outcomes || []).forEach(function(row) {
                    var detail = escapeContactImport(row.error || row.message || row.record_label || row.status);
                    if (row.record_url) detail = '<a href="' + escapeContactImport(row.record_url) + '">' + detail + '</a>';
                    $('<tr>').append($('<td>').text(row.sheet || '—'), $('<td>').text(row.source_row || '—'), $('<td>').text(row.status || '—'), $('<td>').html(detail)).appendTo(body);
                });
                panel.removeClass('hidden');
            }).fail(function(xhr) { toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Detail batch tidak dapat dimuat.'); });
        });
        $(document).on('click', '.contact-import-retry, .contact-import-revert', function() {
            var button = $(this);
            var revert = button.hasClass('contact-import-revert');
            if (revert && !confirm('Contact dan saldo awal hanya direvert jika belum berubah atau digunakan transaksi lain. Lanjutkan?')) return;
            $.post(button.data('url'), {_token: $('meta[name="csrf-token"]').attr('content')}).done(function() {
                toastr.success(revert ? 'Batch berhasil direvert.' : 'Baris gagal dijadwalkan ulang.');
                contactImportHistory.ajax.reload(null, false);
            }).fail(function(xhr) { toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Aksi batch gagal.'); });
        });
    });
</script>

@endsection
