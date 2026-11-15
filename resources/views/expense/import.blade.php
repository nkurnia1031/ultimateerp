@extends('layouts.app')
@section('title', __('expense.import_expense'))

@section('css')
    <style>
        html, body { overflow-x: hidden; }
        .expense-import-row-progress { min-width: 110px; height: 18px; margin-bottom: 4px; }
        #expense_import_history_table { width: 100%; table-layout: fixed; scroll-margin-top: 24px; }
        #expense_import_history_table td { vertical-align: middle; overflow-wrap: anywhere; white-space: normal; }
        #expense_import_history_table_wrapper, #expense_import_history_table_wrapper .row { max-width: 100%; }
        #expense_import_history_table_wrapper .dataTables_length,
        #expense_import_history_table_wrapper .dataTables_filter,
        #expense_import_history_table_wrapper .dataTables_info,
        #expense_import_history_table_wrapper .dataTables_paginate { white-space: normal; }
        .expense-import-progress { min-width: 90px; height: 16px; margin: 0 0 4px; }
        .expense-import-history-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        #expense_import_references_modal .expense-import-reference-item { width: 100%; text-align: left; overflow-wrap: anywhere; }
        #expense_import_reference_results { max-height: 50vh; overflow-y: auto; }
        @media (max-width: 767px) {
            #expense_import_history_table, #expense_import_history_table tbody, #expense_import_history_table tr, #expense_import_history_table td { display: block; width: 100%; }
            #expense_import_history_table thead { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
            #expense_import_history_table tbody tr { margin-bottom: 12px; padding: 8px; border: 1px solid #ddd; border-radius: 6px; background: #fff; }
            #expense_import_history_table tbody td { padding: 6px 2px; border: 0; }
            #expense_import_history_table tbody td::before { display: block; margin-bottom: 3px; color: #666; font-size: 11px; font-weight: 700; content: attr(data-label); }
            #expense_import_history_table_wrapper .row { display: flex; flex-wrap: wrap; }
            #expense_import_history_table_wrapper .dataTables_length,
            #expense_import_history_table_wrapper .dataTables_filter,
            #expense_import_history_table_wrapper .dataTables_info,
            #expense_import_history_table_wrapper .dataTables_paginate { float: none; width: 100%; text-align: left; }
            #expense_import_history_table_wrapper .dataTables_filter input { max-width: 70%; }
            #expense_import_history_table_wrapper .pagination { display: flex; flex-wrap: wrap; margin: 6px 0; }
            .expense-import-progress { min-width: 0; }
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
                        <div class="col-sm-4 col-xs-12">
                            <a href="{{ asset('files/import_expense_template.xlsx') }}"
                                class="tw-dw-btn tw-dw-btn-success tw-text-white" download><i class="fa fa-download"></i>
                                @lang('lang_v1.download_template_file')</a>
                            <button type="button" class="btn btn-default" id="expense_import_instructions_button" data-toggle="modal" data-target="#expense_import_instructions_modal">
                                <i class="fa fa-info-circle"></i> Petunjuk format
                            </button>
                        </div>
                    </div>
                @endcomponent
            </div>
        </div>

        <div id="expense_import_index_config" data-status-template="{{ route('expenses.import.status', ['batch' => '__BATCH__']) }}" data-cancel-template="{{ route('expenses.import.cancel', ['batch' => '__BATCH__']) }}" data-preview-page-template="{{ route('expenses.import.preview-page', ['batch' => '__BATCH__']) }}"></div>

        <div class="modal fade" id="expense_import_references_modal" tabindex="-1" role="dialog" aria-labelledby="expense_import_references_title" aria-hidden="true">
            <div class="modal-dialog modal-md" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="expense_import_references_title">Referensi Expense yang diimpor</h4>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="expense_import_reference_search">Cari nomor referensi</label>
                            <input type="search" class="form-control" id="expense_import_reference_search" autocomplete="off" placeholder="Ketik nomor referensi...">
                        </div>
                        <p id="expense_import_references_status" class="text-muted" role="status" aria-live="polite"></p>
                        <div id="expense_import_reference_results" class="list-group"></div>
                        <button type="button" class="btn btn-default btn-block" id="expense_import_references_load_more" style="display:none">Muat referensi berikutnya</button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade payment_modal" tabindex="-1" role="dialog" aria-labelledby="expense_import_expense_detail_title"></div>

        <div class="modal fade" id="expense_import_instructions_modal" tabindex="-1" role="dialog" aria-labelledby="expense_import_instructions_title">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="expense_import_instructions_title">@lang('lang_v1.instructions')</h4>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
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
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Tutup</button></div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                @component('components.widget', ['title' => 'Riwayat import Expense'])
                    <div id="expense_import_upload_status" class="alert alert-info" style="display:none" aria-live="polite">
                        <strong id="expense_import_upload_message">Menyiapkan upload...</strong>
                        <div class="progress tw-mt-2 tw-mb-1">
                            <div id="expense_import_upload_progress" class="progress-bar progress-bar-success progress-bar-striped active" role="progressbar" style="width:0%">0%</div>
                        </div>
                        <small id="expense_import_upload_detail" class="text-muted">File diproses di latar belakang setelah upload selesai.</small>
                    </div>
                    <div class="expense-import-history-table-wrap">
                        <table class="table table-striped" id="expense_import_history_table">
                            <thead><tr><th>Batch</th><th>File</th><th>Dibuat</th><th>Oleh</th><th>Status</th><th>Progress</th><th>Ringkasan</th><th>Aksi</th></tr></thead>
                            <tbody>
                                @foreach ($expense_import_history as $historyRow)
                                    <tr id="expense_import_batch_{{ $historyRow['id'] }}"
                                        data-expense-import-history-row="{{ $historyRow['id'] }}"
                                        @if($historyRow['batch_id']) data-expense-import-batch="{{ $historyRow['batch_id'] }}" data-process-state="{{ $historyRow['process_state'] }}" @endif>
                                        <td data-label="Batch" data-order="{{ $historyRow['sort_at'] }}">{{ $historyRow['batch_number'] ?: 'Preview' }}</td>
                                        <td data-label="File" data-role="file">{{ $historyRow['file_name'] }}</td>
                                        <td data-label="Dibuat" data-role="created" data-order="{{ $historyRow['sort_at'] }}">{{ @format_datetime($historyRow['created_at']) }}</td>
                                        <td data-label="Oleh">{{ $historyRow['created_by'] }}</td>
                                        <td data-label="Status"><span class="label label-{{ $historyRow['status_class'] }}" data-role="status">{{ $historyRow['status_label'] }}</span><div class="small text-muted" data-role="message">{{ $historyRow['status_message'] }}</div></td>
                                        <td data-label="Progress"><div class="progress expense-import-progress"><div class="progress-bar progress-bar-success{{ in_array($historyRow['process_state'] ?? '', ['queued', 'processing'], true) ? ' progress-bar-striped active' : '' }}" data-role="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $historyRow['progress_percent'] }}" style="width:{{ $historyRow['progress_percent'] }}%">{{ $historyRow['progress_percent'] }}%</div></div><small class="text-muted" data-role="progress-meta">{{ $historyRow['progress_meta'] }}</small></td>
                                        <td data-label="Ringkasan"><span data-role="summary">{{ $historyRow['summary_text'] }}</span>@if($historyRow['summary_detail'])<details class="small text-muted" data-role="summary-detail"><summary>Referensi ({{ $historyRow['reference_count'] }})</summary><div>{{ $historyRow['summary_detail'] }}</div></details>@endif</td>
                                        <td data-label="Aksi"><div class="expense-import-history-actions">
                                            @if($historyRow['can_preview'])<button type="button" class="btn btn-xs btn-success expense-import-open-preview" data-batch="{{ $historyRow['batch_id'] }}"><i class="fa fa-eye"></i> Periksa preview</button>@endif
                                            @if($historyRow['can_cancel'])<button type="button" class="btn btn-xs btn-warning expense-import-cancel" data-batch="{{ $historyRow['batch_id'] }}"><i class="fa fa-ban"></i> Batalkan</button>@endif
                                            @if(($historyRow['can_view_references'] ?? false) && $historyRow['reference_count'] > 0)<button type="button" class="btn btn-xs btn-info expense-import-open-references" data-batch="{{ $historyRow['batch_id'] }}" data-references-url="{{ $historyRow['references_url'] }}" data-reference-count="{{ $historyRow['reference_count'] }}"><i class="fa fa-list"></i> Referensi ({{ $historyRow['reference_count'] }})</button>@endif
                                            @if($historyRow['restore_url'] ?? false)<a href="{{ $historyRow['restore_url'] }}" class="btn btn-xs btn-success expense-import-restore-workbook"><i class="fa fa-file-excel-o"></i> Unduh Excel</a>@endif
                                            @if($historyRow['done'])<span class="text-muted expense-import-done">Selesai</span>@endif
                                            @can('expense.delete')
                                                @if($historyRow['revert_url'])<a href="{{ $historyRow['revert_url'] }}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error revert_import"><i class="fas fa-undo"></i> @lang('lang_v1.revert_import')</a>@endif
                                            @endcan
                                        </div></td>
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
