@extends('layouts.app')
@section('title', __('expense.import_expense'))

@section('css')
    <style>
        html, body { overflow-x: hidden; }
        .expense-import-row-progress { min-width: 110px; height: 18px; margin-bottom: 4px; }
        #expense_import_batches_table { width: 100%; table-layout: fixed; scroll-margin-top: 24px; }
        #expense_import_batches_table td { vertical-align: middle; overflow-wrap: anywhere; white-space: normal; }
        #expense_import_batches_table_wrapper, #expense_import_batches_table_wrapper .row { max-width: 100%; }
        @media (max-width: 767px) {
            #expense_import_batches_table, #expense_import_batches_table tbody, #expense_import_batches_table tr, #expense_import_batches_table td { display: block; width: 100%; }
            #expense_import_batches_table thead { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
            #expense_import_batches_table tbody tr { margin-bottom: 12px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
            #expense_import_batches_table tbody td { padding: 6px 2px; border: 0; }
            #expense_import_batches_table tbody td::before { display: block; margin-bottom: 3px; color: #666; font-size: 11px; font-weight: 700; content: attr(data-label); }
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

        <div id="expense_import_index_config" data-status-template="{{ route('expenses.import.status', ['batch' => '__BATCH__']) }}" data-cancel-template="{{ route('expenses.import.cancel', ['batch' => '__BATCH__']) }}" data-preview-page-template="{{ route('expenses.import.preview-page', ['batch' => '__BATCH__']) }}"></div>

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
                    <div id="expense_import_upload_status" class="alert alert-info" style="display:none" aria-live="polite">
                        <strong id="expense_import_upload_message">Menyiapkan upload...</strong>
                        <div class="progress tw-mt-2 tw-mb-1">
                            <div id="expense_import_upload_progress" class="progress-bar progress-bar-success progress-bar-striped active" role="progressbar" style="width:0%">0%</div>
                        </div>
                        <small id="expense_import_upload_detail" class="text-muted">File diproses di latar belakang setelah upload selesai.</small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped" id="expense_import_batches_table">
                            <thead><tr><th>File</th><th>Dibuat</th><th>Status</th><th>Progress</th><th>Ringkasan</th><th>Aksi</th></tr></thead>
                            <tbody>
                                @foreach ($expense_import_batches as $batch)
                                    @php($batchSummary = json_decode((string) $batch->summary, true) ?: [])
                                    @php($batchReady = $batch->status === 'preview' && in_array($batch->process_state ?: 'completed', ['completed', 'failed'], true))
                                    <tr id="expense_import_batch_{{ $batch->id }}" data-expense-import-batch="{{ $batch->id }}" data-process-state="{{ $batch->process_state ?: 'completed' }}">
                                        <td data-label="File">{{ $batch->file_name }}</td>
                                        <td data-label="Dibuat">{{ @format_datetime($batch->created_at) }}</td>
                                        <td data-label="Status"><span class="label label-{{ $batch->status === 'imported' ? 'success' : ($batch->process_state === 'failed' ? 'danger' : ($batch->process_state === 'completed' ? 'info' : 'warning')) }}" data-role="status">{{ $batch->process_state ?: $batch->status }}</span><div class="small text-muted" data-role="message">{{ $batch->progress_message }}</div></td>
                                        <td data-label="Progress"><div class="progress expense-import-row-progress"><div class="progress-bar progress-bar-success{{ in_array($batch->process_state, ['queued', 'processing'], true) ? ' progress-bar-striped active' : '' }}" data-role="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $batch->progress_percent }}" style="width:{{ (int) $batch->progress_percent }}%">{{ (int) $batch->progress_percent }}%</div></div><small class="text-muted" data-role="progress-meta">{{ (int) $batch->progress_current }} / {{ (int) $batch->progress_total }}@if($batch->heartbeat_at) · {{ @format_datetime($batch->heartbeat_at) }}@endif</small></td>
                                        <td data-label="Ringkasan"><span data-role="summary">{{ (int) ($batchSummary['valid'] ?? 0) }} valid - {{ (int) ($batchSummary['errors'] ?? 0) }} error</span></td>
                                        <td data-label="Aksi"><button type="button" class="btn btn-xs btn-success expense-import-open-preview" data-batch="{{ $batch->id }}" style="{{ $batchReady ? '' : 'display:none' }}"><i class="fa fa-eye"></i> Periksa preview</button><button type="button" class="btn btn-xs btn-warning expense-import-cancel" data-batch="{{ $batch->id }}" style="{{ $batch->process_state === 'queued' ? '' : 'display:none' }}"><i class="fa fa-ban"></i> Batalkan</button><span class="text-muted expense-import-done" style="{{ $batch->status === 'imported' || $batch->status === 'cancelled' ? '' : 'display:none' }}">Selesai</span></td>
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
