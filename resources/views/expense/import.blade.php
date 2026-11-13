@extends('layouts.app')
@section('title', __('expense.import_expense'))

@section('css')
    <style>
        /* Keep the preview usable on small screens and with large imports. The
         * header/footer stay in the viewport while only the preview body scrolls. */
        #expense_import_preview_modal .modal-dialog {
            width: calc(100% - 24px);
            max-width: 1220px;
            height: calc(100vh - 24px);
            height: calc(100dvh - 24px);
            margin: 12px auto;
            display: flex;
            align-items: stretch;
        }

        #expense_import_preview_modal .modal-content {
            width: 100%;
            max-height: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #expense_import_preview_modal .modal-header,
        #expense_import_preview_modal .modal-footer {
            flex: 0 0 auto;
        }

        #expense_import_preview_modal .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        #expense_import_preview_modal .table-responsive {
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        #expense_import_preview_modal #expense_import_preview_table {
            min-width: 760px;
        }

        #expense_import_preview_modal #expense_import_preview_table th,
        #expense_import_preview_modal #expense_import_preview_table td {
            vertical-align: top;
        }

        #expense_import_preview_modal .expense-import-journal-candidates > summary {
            cursor: pointer;
            font-weight: 600;
            margin-top: 6px;
        }

        #expense_import_preview_modal .expense-import-journal-candidates .table {
            margin: 6px 0 0;
            min-width: 520px;
        }

        #expense_import_preview_modal #expense_import_preview_table th:first-child,
        #expense_import_preview_modal #expense_import_preview_table th:nth-child(6),
        #expense_import_preview_modal #expense_import_preview_table td:first-child,
        #expense_import_preview_modal #expense_import_preview_table td:nth-child(6) {
            white-space: nowrap;
        }

        #expense_import_preview_modal .modal-footer {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 8px;
        }

        #expense_import_preview_modal .modal-footer .btn {
            margin: 0;
        }

        @media (max-width: 767px) {
            #expense_import_preview_modal .modal-dialog {
                width: 100%;
                height: 100vh;
                height: 100dvh;
                margin: 0;
            }

            #expense_import_preview_modal .modal-content {
                border-radius: 0;
            }

            #expense_import_preview_modal .modal-body {
                padding: 12px;
            }

            #expense_import_preview_modal #expense_import_summary {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                margin-left: -6px;
                margin-right: -6px;
            }

            #expense_import_preview_modal #expense_import_summary > [class*="col-"] {
                float: none;
                width: auto;
                padding-left: 6px;
                padding-right: 6px;
            }

            #expense_import_preview_modal .small-box {
                margin-bottom: 12px;
            }

            #expense_import_preview_modal .modal-footer .btn {
                flex: 1 1 160px;
            }
        }
    </style>
@endsection

@section('content')

    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('expense.import_expense')
        </h1>
    </section>

    <!-- Main content -->
    <section class="content">

        @if (session('status'))
            <div class="alert alert-{{ data_get(session('status'), 'success') ? 'success' : 'danger' }} alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                {{ data_get(session('status'), 'msg') }}
            </div>
        @endif

        @if (session('notification') || !empty($notification))
            <div class="row">
                <div class="col-sm-12">
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                        @if (!empty($notification['msg']))
                            {{ $notification['msg'] }}
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
                    {!! Form::open([
                        'url' => route('expenses.import.upload'),
                        'method' => 'post',
                        'enctype' => 'multipart/form-data',
                        'id' => 'expense_import_form',
                    ]) !!}
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="col-sm-8">
                                <div class="form-group">
                                    {!! Form::label('name', __('product.file_to_import') . ':') !!}
                                    {!! Form::file('expense_csv', ['accept' => '.xls, .xlsx, .csv', 'required' => 'required', 'id' => 'expense_import_file']) !!}
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <br>
                                <button type="submit" id="expense_import_submit"
                                    class="tw-dw-btn tw-dw-btn-primary tw-text-white"><i class="fa fa-upload"></i> @lang('messages.submit')</button>
                            </div>
                        </div>
                    </div>
                    {!! Form::close() !!}
                    <br><br>
                    <div class="row">
                        <div class="col-sm-4">
                            <a href="{{ asset('files/import_expense_template.xlsx') }}"
                                class="tw-dw-btn tw-dw-btn-success tw-text-white" download><i class="fa fa-download"></i>
                                @lang('lang_v1.download_template_file')</a>
                        </div>
                    </div>
                @endcomponent
            </div>
        </div>

        <div class="modal fade" id="expense_import_progress_modal" tabindex="-1" role="dialog" aria-labelledby="expenseImportProgressTitle" data-status-template="{{ route('expenses.import.status', ['batch' => '__BATCH__']) }}" data-preview-template="{{ route('expenses.import.preview', ['batch' => '__BATCH__']) }}" data-confirm-template="{{ route('expenses.import.confirm', ['batch' => '__BATCH__']) }}" data-cancel-template="{{ route('expenses.import.cancel', ['batch' => '__BATCH__']) }}">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="expenseImportProgressTitle"><i class="fa fa-refresh"></i> Memproses import Expense</h4>
                    </div>
                    <div class="modal-body">
                        <div id="expense_import_progress_error" class="alert alert-danger" style="display:none"></div>
                        <p id="expense_import_progress_message">Menyiapkan file...</p>
                        <div class="progress">
                            <div id="expense_import_progress_bar" class="progress-bar progress-bar-primary progress-bar-striped active" role="progressbar" style="width:0%">0%</div>
                        </div>
                        <p class="text-muted"><span id="expense_import_progress_stage">uploading</span> - <span id="expense_import_progress_count">0 / 0</span> - durasi <span id="expense_import_progress_elapsed">-</span> - pembaruan terakhir <span id="expense_import_progress_heartbeat">-</span></p>
                        <button type="button" id="expense_import_retry_status" class="btn btn-default" style="display:none"><i class="fa fa-refresh"></i> Coba cek lagi</button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" id="expense_import_cancel" class="btn btn-warning"><i class="fa fa-ban"></i> Batalkan</button>
                        <button type="button" id="expense_import_retry_preview" class="btn btn-primary" style="display:none"><i class="fa fa-table"></i> Buka preview &amp; coba lagi</button>
                        <button type="button" id="expense_import_close_progress" class="btn btn-default" data-dismiss="modal" style="display:none">Tutup</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="expense_import_preview_modal" tabindex="-1" role="dialog" aria-labelledby="expenseImportPreviewTitle" data-preview-template="{{ route('expenses.import.preview', ['batch' => '__BATCH__']) }}" data-refresh-template="{{ route('expenses.import.refresh-preview', ['batch' => '__BATCH__']) }}" data-confirm-template="{{ route('expenses.import.confirm', ['batch' => '__BATCH__']) }}" data-date-template="{{ route('expenses.import.rows.date', ['batch' => '__BATCH__', 'row' => '__ROW__']) }}" data-tax-template="{{ route('expenses.import.rows.tax', ['batch' => '__BATCH__', 'row' => '__ROW__']) }}" data-journal-template="{{ route('expenses.import.rows.journal', ['batch' => '__BATCH__', 'row' => '__ROW__']) }}" data-existing-expense-template="{{ route('expenses.import.rows.existing-expense', ['batch' => '__BATCH__', 'row' => '__ROW__']) }}">
            <div class="modal-dialog modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="expenseImportPreviewTitle"><i class="fa fa-table"></i> Preview import Expense</h4>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">Total Amount dianggap sudah termasuk pajak. Jika REFERENCE NO cocok dengan satu posting Journal Expense yang siap, nominal pajak Journal menjadi acuan; atribut operasional lainnya mengikuti Excel. Jika Journal tidak mencatat pajak, pajak menjadi 0 dan total Excel tetap dipertahankan. Tanpa Journal yang cocok, berlaku pajak dari Excel. REFERENCE NO yang sudah ada akan memperbarui Expense lama; bila tanggal berbeda lebih dari 30 hari atau total berbeda lebih dari 10% dan minimal Rp100.000, pilih Lewati, Gabungkan, atau Buat transaksi baru.</div>
                        <div class="form-inline tw-mb-3"><label for="expense_import_preview_status" class="tw-mr-2">Filter status</label><select id="expense_import_preview_status" class="form-control"><option value="">Semua</option><option value="valid">Valid</option><option value="error">Error</option><option value="skipped">Dilewati</option></select> <button type="button" id="expense_import_refresh_preview" class="btn btn-default"><i class="fa fa-refresh"></i> Perbarui preview &amp; kandidat Journal</button><span class="text-muted">Membaca ulang workbook tersimpan; pilihan tanggal, pajak, dan kandidat tetap dipakai.</span></div>
                        <div class="row" id="expense_import_summary">
                            <div class="col-sm-3"><div class="small-box bg-blue"><div class="inner"><h3 data-expense-summary="total">0</h3><p>Total baris</p></div></div></div>
                            <div class="col-sm-3"><div class="small-box bg-green"><div class="inner"><h3 data-expense-summary="valid">0</h3><p>Valid / siap import</p></div></div></div>
                            <div class="col-sm-3"><div class="small-box bg-red"><div class="inner"><h3 data-expense-summary="errors">0</h3><p>Perlu diperbaiki</p></div></div></div>
                            <div class="col-sm-3"><div class="small-box bg-yellow"><div class="inner"><h3 data-expense-summary="skipped">0</h3><p>Dilewati</p></div></div></div>
                        </div>
                        <p class="text-muted" id="expense_import_journal_summary">Journal cocok: 0 grup · perlu pilihan: 0 grup</p>
                        <p class="text-muted" id="expense_import_existing_summary">Expense lama diperbarui: 0 grup · perlu pilihan: 0 grup</p>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped" id="expense_import_preview_table" style="width:100%">
                                <thead><tr><th>Baris Excel</th><th>Referensi</th><th>Tanggal</th><th>Tanggal bayar</th><th>Aksi</th><th>Pajak</th><th>Status</th><th>Detail</th></tr></thead>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Tutup</button>
                        <button type="button" id="expense_import_confirm" class="btn btn-success" disabled><i class="fa fa-check"></i> Import baris valid</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="expense_import_date_modal" tabindex="-1" role="dialog" aria-labelledby="expenseImportDateTitle">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form id="expense_import_date_form">
                        <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="expenseImportDateTitle">Perbaiki tanggal</h4></div>
                        <div class="modal-body">
                            <div id="expense_import_date_error" class="alert alert-danger" style="display:none"></div>
                            <input type="hidden" id="expense_import_date_row">
                            <input type="hidden" id="expense_import_date_field">
                            <div class="form-group"><label for="expense_import_date_value">Tanggal</label><input type="date" class="form-control" id="expense_import_date_value" required></div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Batal</button><button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Simpan dan validasi ulang</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-sm-12">
                @component('components.widget', ['class' => 'box-primary', 'title' => __('lang_v1.instructions')])
                    <table class="table table-striped">
                        <tr>
                            <th>@lang('lang_v1.col_name')</th>
                            <th>@lang('lang_v1.instruction')</th>
                        </tr>

                        <tr>
                            <td>@lang('purchase.business_location')</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td>@lang('expense.expense_category') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td>@lang('lang_v1.category_ins') <br><small class="text-muted">(@lang('lang_v1.category_ins2'))</small></td>
                        </tr>
                        <tr>
                            <td>@lang('product.sub_category') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td>@lang('lang_v1.sub_category_ins') <br><small class="text-muted">(@lang('lang_v1.sub_category_ins2'))</small></td>
                        </tr>
                        <tr>
                            <td>Deskripsi <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td></td>
                        </tr>

                        <tr>
                            <td>@lang('purchase.ref_no') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td>@lang('lang_v1.leave_empty_to_autogenerate')</td>
                        </tr>
                        <tr>
                            <td>Tags <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td>pisahkan tags dengan ; misalnya: BM 7228 TY;BM 1268 UY</td>
                        </tr>


                        <tr>
                            <td>@lang('messages.date') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td>@lang('expense.date_format_instruction')<br><small class="text-muted">Tanggal serial Excel juga dibaca otomatis; jika tanggal tetap tidak valid, perbaiki dari preview sebelum import.</small></td>
                        </tr>

                        <tr>
                            <td>@lang('expense.expense_for') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td>@lang('expense.expense_for_help')</td>
                        </tr>

                        <tr>
                            <td>@lang('lang_v1.contact_id') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td></td>
                        </tr>

                        <tr>
                            <td>@lang('purchase.attach_document') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td>@lang('product.applicable_tax') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td>@lang('expense.expense_note') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td></td>
                        </tr>

                        <tr>
                            <td>@lang('sale.total_amount')</td>
                            <td></td>
                        </tr>

                        <tr>
                            <td>@lang('lang_v1.paid_amount')</td>
                            <td></td>
                        </tr>

                        <tr>
                            <td>@lang('lang_v1.paid_on') </td>
                            <td>@lang('expense.date_format_instruction')<br><small class="text-muted">Tanggal serial Excel juga didukung.</small></td>
                        </tr>

                        <tr>
                            <td>@lang('lang_v1.payment_method') </td>
                            <td>{{ implode(', ', $payment_types) }}</td>
                        </tr>
                        <tr>
                            <td>@lang('lang_v1.payment_account') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td>@lang('sale.payment_note') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                            <td></td>
                        </tr>

                    </table>
                @endcomponent
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                @component('components.widget', ['title' => __('lang_v1.imports')])
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>@lang('lang_v1.import_batch')</th>
                                <th>@lang('lang_v1.import_time')</th>
                                <th>@lang('Import File')</th>
                                <th>@lang('business.created_by')</th>
                                <th>@lang('lang_v1.invoices')</th>
                                @can('expense.delete')
                                    <th>@lang('messages.action')</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($imported_sales_array as $key => $value)
                                <tr>
                                    <td>{{ $key }}</td>
                                    <td>{{ @format_datetime($value['import_time']) }}</td>
                                    <td>{{ $value['import_file'] }}</td>
                                    <td>{{ $value['created_by'] }}</td>
                                    <td>
                                        {{ implode(', ', $value['invoices']) }} <br>
                                        <p class="text-muted text-right">
                                            <small>(@lang('sale.total'): {{ count($value['invoices']) }})</small>
                                        </p>
                                    </td>
                                    @can('expense.delete')
                                        <td><a href="{{ action([\App\Http\Controllers\ExpenseController::class, 'revertImport'], $key) }}"
                                                class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error revert_import"><i
                                                    class="fas fa-undo"></i> @lang('lang_v1.revert_import')</a></td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endcomponent
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                @component('components.widget', ['title' => 'Riwayat preview import Expense'])
                    <div class="table-responsive">
                        <table class="table table-striped" id="expense_import_batches_table">
                            <thead><tr><th>File</th><th>Dibuat</th><th>Status</th><th>Progress</th><th>Ringkasan</th><th>Aksi</th></tr></thead>
                            <tbody>
                                @foreach ($expense_import_batches as $batch)
                                    @php($batchSummary = json_decode((string) $batch->summary, true) ?: [])
                                    <tr>
                                        <td>{{ $batch->file_name }}</td>
                                        <td>{{ @format_datetime($batch->created_at) }}</td>
                                        <td><span class="label label-{{ $batch->status === 'imported' ? 'success' : ($batch->process_state === 'failed' ? 'danger' : 'warning') }}">{{ $batch->process_state ?: $batch->status }}</span></td>
                                        <td>{{ (int) $batch->progress_percent }}%</td>
                                        <td>{{ (int) ($batchSummary['valid'] ?? 0) }} valid - {{ (int) ($batchSummary['errors'] ?? 0) }} error</td>
                                        <td><button type="button" class="btn btn-xs btn-primary expense-import-resume" data-batch="{{ $batch->id }}"><i class="fa fa-eye"></i> Buka</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endcomponent
            </div>
        </div>
    </section>
    <!-- /.content -->

@endsection
@section('javascript')
    <script>window.expenseImportTaxOptions = @json($expense_import_tax_options ?? []);</script>
    <script src="{{ versioned_asset_url(asset('js/expense-import.js'), $asset_v ?? null) }}"></script>
@endsection
