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
                            <th>Tonase</th>
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
                $('#surat_kontrak_import_form').validate({
                    rules: {
                        import_file: {
                            required: true,
                            extension: "xlsx|xls"
                        }
                    },
                    messages: {
                        import_file: {
                            required: "File wajib dipilih",
                            extension: "File harus berformat Excel (.xlsx atau .xls)"
                        }
                    },
                    submitHandler: function(form) {

                        let formData = new FormData(form);

                        $(form).find('button[type="submit"]').attr('disabled', true);

                        $.ajax({
                            method: "POST",
                            url: $(form).attr("action"),
                            data: formData,
                            processData: false, // WAJIB untuk file upload
                            contentType: false, // WAJIB untuk file upload
                            dataType: "json",
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
                                form.reset();
                            },
                            error: function(xhr) {
                                toastr.error("Terjadi kesalahan saat import.");
                                $(form).find('button[type="submit"]').attr(
                                    'disabled', false);
                            }
                        });
                    }
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
    <script src="{{ asset('modules/crm/js/jQuery.print.js') }}"></script>

@endsection
