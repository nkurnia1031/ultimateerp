@extends('layouts.app')

@section('title', __('Surat Kontrak'))
@section('css')
    <style>
        #print_area .table-bordered td,
        #print_area .table-bordered th {
            color: black !important;
            border: 1px solid #000000 !important;
            padding-top: 5px !important;
            padding-bottom: 5px !important;
            padding-left: 8px !important;
            padding-right: 8px !important;
            color: black !important;
        }

        .listnya li {
            margin-bottom: 5px;
        }

        .listnya {
            margin-top: 10px
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Roboto:wght@400;700&display=swap"
        rel="stylesheet">

    <style>
        .kop-container {
            background-color: #fff;
        }

        .logo-section {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Simulasi Logo Bintang */
        .star-logo {
            font-weight: bold;
            font-size: 24px;
            color: #666;
            border: 2px solid #ccc;
            padding: 10px;
            position: relative;
            /* Jika Anda punya file gambar logo, ganti bagian ini dengan tag <img> */
        }

        .company-name {
            font-family: 'Playfair Display', serif;
            color: #af263d !important;
            /* Warna merah pudar sesuai gambar */
            font-size: 3.5rem;
            letter-spacing: 2px;
            line-height: 1;
        }

        .services {
            font-family: 'Roboto', sans-serif;
            color: #005892 !important;
            /* Warna biru pudar */
            font-size: 1.8rem;
            font-weight: 400;
            letter-spacing: 5px;
            text-transform: uppercase;
        }

        .footer-line {
            border-top: 3px double #333;
            margin-top: 5px;
            padding-top: 5px;
        }

        .address-bar {
            background-color: #fff;
            font-family: 'Roboto', sans-serif;
            font-weight: bold;
            font-size: 0.9rem;
            color: #000000 !important;
        }

        @media (max-width: 768px) {
            .company-name {
                font-size: 2.6rem;
            }

            .services {
                font-size: 1.4rem;
            }
        }
    </style>
@endsection
@section('content')
    @include('crm::layouts.nav')
    <!-- Content Header (Page header) -->
    <section class="content-header no-print">
        <h1>@lang('Surat Kontrak')</h1>
    </section>

    <section class="content no-print">
        @component('components.filters', ['title' => __('report.filters')])
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        {!! Form::label('source', __('crm::lang.source') . ':') !!}
                        {!! Form::select('source', $sources, null, [
                            'class' => 'form-control select2',
                            'id' => 'source',
                            'placeholder' => __('messages.all'),
                        ]) !!}
                    </div>
                </div>

                @if (count($users) > 0)
                    <div class="col-md-4">
                        <div class="form-group">
                            {!! Form::label('user_id', __('lang_v1.assigned_to') . ':') !!}
                            {!! Form::select('user_id', $users, null, [
                                'class' => 'form-control select2',
                                'id' => 'user_id',
                                'placeholder' => __('messages.all'),
                            ]) !!}
                        </div>
                    </div>
                @endif
            </div>
        @endcomponent
        @component('components.widget', ['class' => 'box-primary', 'title' => __('Daftar Surat')])
            @slot('tool')
                <div class="box-tools">
                    <button type="button" class="btn btn-sm btn-primary btn-add-lead pull-right m-5"
                        data-href="{{ action([\Modules\Crm\Http\Controllers\SuratKontrakController::class, 'create']) }}">
                        <i class="fa fa-plus"></i> @lang('messages.add')
                    </button>
                    <button type="button" class="btn btn-sm btn-success btn-add-lead pull-right m-5"
                        data-href="{{ action([\Modules\Crm\Http\Controllers\SuratKontrakController::class, 'import']) }}">
                        <i class="fa fa-upload"></i> Import
                    </button>


                </div>
            @endslot
            <div class="table-responsive">
                <table class="table table-bordered table-striped" id="proposals" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>@lang('messages.action')</th>

                            <th>Nomor Kontrak</th>
                            <th>Pihak Kedua</th>
                            <th>Pekerjaan</th>
                            <th>Kapal MV</th>
                            <th>Estimasi Tonase</th>
                            <th>Lokasi Muat</th>
                            <th>Lokasi Bongkar</th>
                            <th>Mulai Kerja</th>
                            <th>Selesai</th>
                            <th>Jangka Waktu</th>
                            <th>Notes</th>

                        </tr>
                    </thead>
                </table>
            </div>
        @endcomponent
        <div class="modal fade contact_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
        </div>
        <div class="modal fade schedule" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
        </div>
    </section>
@endsection
@section('javascript')
    <script type="text/javascript">
        $(document).ready(function() {
            surat_kontrak_table = $('#proposals').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ action([\Modules\Crm\Http\Controllers\SuratKontrakController::class, 'index']) }}"
                },
                columnDefs: [{
                    targets: 1, // kolom action
                    orderable: false,
                    searchable: false,
                }],
                aaSorting: [
                    [8, 'desc'] // sort berdasarkan mulai_kerja
                ],
                columns: [{
                        data: 'action',
                        name: 'action'
                    },
                    {
                        data: 'nomor',
                        name: 'surat_kontrak.nomor'
                    },
                    {
                        data: 'contact_name',
                        name: 'contacts.name'
                    },
                    {
                        data: 'pekerjaan',
                        name: 'surat_kontrak.pekerjaan'
                    },
                    {
                        data: 'kapal_mv',
                        name: 'surat_kontrak.kapal_mv'
                    },
                    {
                        data: 'tonase',
                        name: 'surat_kontrak.tonase'
                    },
                    {
                        data: 'lokasi_muat',
                        name: 'surat_kontrak.lokasi_muat'
                    },
                    {
                        data: 'lokasi_bongkar',
                        name: 'surat_kontrak.lokasi_bongkar'
                    },
                    {
                        data: 'mulai_kerja',
                        name: 'surat_kontrak.mulai_kerja'
                    },
                    {
                        data: 'selesai',
                        name: 'surat_kontrak.selesai'
                    },
                    {
                        data: 'jangka_waktu',
                        name: 'surat_kontrak.jangka_waktu'
                    },
                    {
                        data: 'notes',
                        name: 'surat_kontrak.notes'
                    }

                ]
            });
            $(document).on('click', '.delete_surat_kontrak', function(e) {
                e.preventDefault();

                var url = $(this).data('href');

                if (confirm('Yakin ingin menghapus Surat Kontrak ini?')) {

                    $.ajax({
                        method: "DELETE",
                        url: url,
                        dataType: "json",
                        data: {
                            _token: $('meta[name="csrf-token"]').attr('content')
                        },
                        success: function(result) {
                            if (result.success) {
                                toastr.success(result.msg);

                                // reload datatable
                                if (typeof surat_kontrak_table !== 'undefined') {
                                    surat_kontrak_table.ajax.reload();
                                }

                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });

                }
            });
        });
        $(document).on('click', '.btn-add-lead', function() {
            var url = $(this).data('href');
            $.ajax({
                method: 'GET',
                url: url,
                dataType: 'html',
                success: function(result) {
                    $('.contact_modal').html(result).modal('show');
                }
            });
        });
    </script>
    <script type="text/javascript">
        $(document).ready(function() {

            // Saat modal dibuka
            $('.contact_modal').on('shown.bs.modal', function() {

                // Init select2
                $(this).find('select').each(function() {
                    if (!$(this).hasClass('select2-hidden-accessible')) {
                        $(this).select2({
                            dropdownParent: $('#add_surat_kontrak_modal')
                        });
                    }
                });

                // Form validation
                $('#surat_kontrak_form').validate({
                    submitHandler: function(form) {

                        $(form).find('button[type="submit"]').attr('disabled', true);

                        $.ajax({
                            method: "POST",
                            url: $(form).attr("action"),
                            dataType: "json",
                            data: $(form).serialize(),
                            success: function(result) {

                                if (result.success == true) {
                                    $('.contact_modal').modal('hide');
                                    toastr.success(result.msg);
                                    surat_kontrak_table.ajax.reload();
                                } else {
                                    toastr.error(result.msg);
                                }

                                $(form).find('button[type="submit"]').attr(
                                    'disabled', false);
                                reset_surat_kontrak_form();
                            }
                        });
                    }
                });
                $(document).on('submit', '#surat_kontrak_import_form', function(event) {
                    event.preventDefault();
                    let form = this;
                    let $form = $(form);
                    if (!$form.data('validator')) {
                        $form.validate({
                            rules: {import_file: {required: true, extension: 'xlsx|xls'}},
                            messages: {import_file: {required: 'File wajib dipilih', extension: 'File harus berformat Excel (.xlsx atau .xls)'}}
                        });
                    }
                    if (!$form.valid()) return;

                    let formData = new FormData(form);
                    $form.find('button[type="submit"]').attr('disabled', true);
                    $.ajax({
                        method: 'POST',
                        url: $form.attr('action'),
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        xhr: function() {
                            let xhr = $.ajaxSettings.xhr();
                            if (xhr.upload) {
                                xhr.upload.addEventListener('progress', function(progress) {
                                    if (progress.lengthComputable) {
                                        let percent = Math.round(progress.loaded / progress.total * 100);
                                        $('#surat_kontrak_upload_progress').removeClass('hidden').find('.progress-bar').css('width', percent + '%').text(percent + '%');
                                    }
                                });
                            }
                            return xhr;
                        },
                        success: function(result) {
                            if (result.success) {
                                toastr.success(result.msg);
                                $('#surat_kontrak_upload_progress').removeClass('hidden').find('.progress-bar').css('width', '100%').text('Upload selesai; batch #' + result.batch_id + ' berjalan di latar belakang');
                                if (window.suratKontrakImportHistory) window.suratKontrakImportHistory.ajax.reload(null, false);
                                if (typeof surat_kontrak_table !== 'undefined') surat_kontrak_table.ajax.reload();
                                form.reset();
                            } else {
                                toastr.error(result.msg || 'Import gagal.');
                            }
                            $form.find('button[type="submit"]').attr('disabled', false);
                        },
                        error: function(xhr) {
                            toastr.error((xhr.responseJSON && (xhr.responseJSON.msg || xhr.responseJSON.message)) || 'Terjadi kesalahan saat import.');
                            $form.find('button[type="submit"]').attr('disabled', false);
                        }
                    });
                });
            });


            // Auto hitung jangka waktu
            $(document).on('change', '#mulai_kerja, #selesai', function() {

                let mulai = new Date($('#mulai_kerja').val());
                let selesai = new Date($('#selesai').val());

                if ($('#mulai_kerja').val() && $('#selesai').val()) {

                    let diffTime = selesai - mulai;
                    let diffDays = diffTime / (1000 * 60 * 60 * 24);

                    if (diffDays >= 0) {
                        $('#jangka_waktu').val(diffDays + ' Hari');
                    } else {
                        $('#jangka_waktu').val('');
                    }
                }
            });




        });


        // Reset form
        function reset_surat_kontrak_form() {
            $('#surat_kontrak_form')[0].reset();
            $('#surat_kontrak_form').find('select').val('').trigger('change');
            $('#jangka_waktu').val('');
        }
    </script>
    <script src="{{ asset('modules/crm/js/jQuery.print.js?v=' . $asset_v) }}"></script>
    <script>
        (function() {
            function escapeImportHtml(value) {
                return $('<div>').text(value == null ? '' : value).html();
            }

            function initSuratKontrakImportHistory() {
                if (!$('#surat_kontrak_import_history').length || $.fn.DataTable.isDataTable('#surat_kontrak_import_history')) {
                    return;
                }
                window.suratKontrakImportHistory = $('#surat_kontrak_import_history').DataTable({
                    processing: true,
                    serverSide: true,
                    searchDelay: 350,
                    pageLength: 10,
                    order: [[0, 'desc']],
                    ajax: {
                        url: @json(route('crm.surat-kontrak-import.history')),
                        data: function(data) {
                            data.status = $('#surat_kontrak_import_status').val();
                        }
                    },
                    columns: [
                        {data: 'id', name: 'id'},
                        {data: 'file_name', name: 'file_name', render: function(value) { return escapeImportHtml(value); }},
                        {data: 'status', name: 'status'},
                        {data: 'progress_percent', name: 'progress_percent', orderable: false, render: function(value, type, row) {
                            return escapeImportHtml(value || 0) + '% (' + escapeImportHtml(row.progress_current || 0) + '/' + escapeImportHtml(row.progress_total || 0) + ')';
                        }},
                        {data: 'created_at', name: 'created_at'},
                        {data: null, orderable: false, searchable: false, render: function(row) {
                            let actions = '<button type="button" class="btn btn-xs btn-info show-contract-import-detail" data-url="' + escapeImportHtml(row.detail_url) + '">Detail</button> ';
                            if (row.restore_url) actions += '<a class="btn btn-xs btn-default" href="' + escapeImportHtml(row.restore_url) + '">Unduh sumber</a> ';
                            if (row.can_retry) actions += '<button type="button" class="btn btn-xs btn-warning retry-contract-import" data-url="' + escapeImportHtml(row.retry_url) + '">Coba ulang</button> ';
                            if (row.can_revert) actions += '<button type="button" class="btn btn-xs btn-danger revert-contract-import" data-url="' + escapeImportHtml(row.revert_url) + '">Revert</button>';
                            return actions;
                        }}
                    ],
                    drawCallback: function() {
                        if (window.suratKontrakImportHistory && window.suratKontrakImportHistory.rows().data().toArray().some(function(row) { return row.active; })) {
                            if (!window.suratKontrakImportPoll) {
                                window.suratKontrakImportPoll = window.setInterval(function() {
                                    if (window.suratKontrakImportHistory) window.suratKontrakImportHistory.ajax.reload(null, false);
                                }, 5000);
                            }
                        }
                    }
                });
            }

            $(document).on('shown.bs.modal', '.contact_modal', initSuratKontrakImportHistory);
            $(document).on('change', '#surat_kontrak_import_status', function() {
                if (window.suratKontrakImportHistory) window.suratKontrakImportHistory.ajax.reload();
            });
            $(document).on('click', '.show-contract-import-detail', function() {
                let $panel = $('#surat_kontrak_import_detail');
                $.get($(this).data('url')).done(function(payload) {
                    let summary = payload.results.summary || {};
                    $panel.find('.import-detail-summary').text('Batch #' + payload.batch.id + ' · ' + (payload.batch.file_name || '') + ' · berhasil ' + (summary.imported_rows || 0) + ', dilewati ' + (summary.skipped_rows || 0) + ', gagal ' + (summary.failed_rows || 0));
                    let $body = $panel.find('tbody').empty();
                    (payload.results.row_outcomes || []).forEach(function(row) {
                        let hasMessage = !!(row.error || row.message);
                        let detail = hasMessage
                            ? window.importHistoryMessageCell(row.error || row.message, 'display')
                            : escapeImportHtml(row.record_label || row.status);
                        if (row.record_url) {
                            let recordLink = '<a href="' + escapeImportHtml(row.record_url) + '">' + escapeImportHtml(row.record_label || row.status || 'Buka hasil') + '</a>';
                            detail = hasMessage ? detail + '<br><small>' + recordLink + '</small>' : recordLink;
                        }
                        $('<tr>').append($('<td>').text(row.sheet || '—'), $('<td>').text(row.source_row || '—'), $('<td>').text(row.status || '—'), $('<td>').html(detail)).appendTo($body);
                    });
                    $panel.removeClass('hidden');
                }).fail(function(xhr) { toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Detail batch tidak dapat dimuat.'); });
            });
            $(document).on('click', '.close-import-detail', function() { $('#surat_kontrak_import_detail').addClass('hidden'); });
            $(document).on('click', '.retry-contract-import, .revert-contract-import', function() {
                let $button = $(this);
                let isRevert = $button.hasClass('revert-contract-import');
                if (isRevert && !confirm('Revert batch hanya berjalan jika data belum berubah dan belum dipakai proses lain. Lanjutkan?')) return;
                $.ajax({
                    url: $button.data('url'),
                    method: 'POST',
                    data: {_token: $('meta[name="csrf-token"]').attr('content')}
                }).done(function() {
                    toastr.success(isRevert ? 'Batch berhasil direvert.' : 'Baris gagal masuk antrean ulang.');
                    if (window.suratKontrakImportHistory) window.suratKontrakImportHistory.ajax.reload(null, false);
                    if (typeof surat_kontrak_table !== 'undefined') surat_kontrak_table.ajax.reload();
                }).fail(function(xhr) { toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Aksi batch gagal.'); });
            });
        })();
    </script>

@endsection
