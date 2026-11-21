@extends('layouts.app')
@section('title', __('lang_v1.import_sales'))

@section('content')

    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang($judul)</h1>
    </section>

    <!-- Main content -->
    <section class="content">
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
            <div class="col-md-12">
                @component('components.widget')
                    {!! Form::open([
                        'url' => $link . '/preview',
                        'method' => 'post',
                        'enctype' => 'multipart/form-data',
                        'id' => 'legacy-import-batch-form',
                    ]) !!}
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="col-sm-8">
                                <div class="form-group">
                                    {!! Form::label('name', __('product.file_to_import') . ':') !!}
                                    {!! Form::file('sales', ['required' => 'required', 'accept' => '.csv,.xls,.xlsx']) !!}
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <br>
                                <button type="submit"
                                    class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('lang_v1.upload_and_review')</button>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-12">
                            <br>
                            @if (request()->is('import-pembelian'))
                                <a href="{{ asset('files/import_purchase_template.xlsx') }}"
                                    class="tw-dw-btn tw-dw-btn-success tw-text-white" download><i class="fa fa-download"></i>
                                    @lang('lang_v1.download_template_file')</a>
                            @else
                                <a href="{{ asset('files/import_sales_template.xlsx') }}"
                                    class="tw-dw-btn tw-dw-btn-success tw-text-white" download><i class="fa fa-download"></i>
                                    @lang('lang_v1.download_template_file')</a>
                            @endif
                        </div>
                    </div>

                    {!! Form::close() !!}
                    <div class="progress" id="legacy_import_progress_wrap" style="display:none;margin-top:12px">
                        <div class="progress-bar" id="legacy_import_progress" role="progressbar" style="width:0%">0%</div>
                    </div>
                    <p id="legacy_import_status" class="text-muted" aria-live="polite"></p>
                @endcomponent
            </div>
        </div>
        @include('import_batches.legacy_history', ['historyUrl' => route(request()->is('import-pembelian') ? 'purchase.import.batch.history' : 'sales.import.batch.history')])
        <div class="row">
            <div class="col-md-12">
                @component('components.widget', ['title' => __('lang_v1.instructions')])
                    <table class="table table-condensed">
                        <tr>
                            <td>1.</td>
                            <td>
                                @if (request()->is('import-pembelian'))
                                    {{ str_replace('sales', 'purchases', __('lang_v1.upload_data_in_excel_format')) }}
                                @else
                                    @lang('lang_v1.upload_data_in_excel_format')
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>2.</td>
                            <td>
                                @if (request()->is('import-pembelian'))
                                    {{ str_replace('sell', 'purchase', __('lang_v1.choose_location_and_group_by')) }}
                                @else
                                    @lang('lang_v1.choose_location_and_group_by')
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>3.</td>
                            <td>
                                @if (request()->is('import-pembelian'))
                                    {{ str_replace('sales', 'purchases', __('lang_v1.map_columns_with_respective_sales_fields')) }}
                                @else
                                    @lang('lang_v1.map_columns_with_respective_sales_fields')
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>4.</td>
                            <td>

                                <table class="table table-striped table-slim">
                                    <tr>
                                        <th>@lang('lang_v1.importable_fields')</th>
                                        <th>@lang('lang_v1.instructions')</th>
                                    </tr>
                                    @foreach ($import_fields as $key => $value)
                                        <tr>
                                            <td>

                                                {{ $value['label'] }}


                                            </td>
                                            <td>
                                                <small>{{ $value['instruction'] ?? '' }}</small>
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
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
                                <th>@lang('Import Filename')</th>
                                <th>@lang('business.created_by')</th>
                                <th>@lang('lang_v1.invoices')</th>
                                @can('sell.delete')
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
                                    @can('sell.delete')
                                        <td>
                                            {!! Form::open([
                                                'url' => request()->is('import-pembelian') ? route('purchases.import.revert', $key) : route('sales.import.revert', $key),
                                                'method' => 'post',
                                                'class' => 'revert_import_form',
                                            ]) !!}
                                            <button type="submit" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error revert_import">
                                                <i class="fas fa-undo"></i> @lang('lang_v1.revert_import')
                                            </button>
                                            {!! Form::close() !!}
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endcomponent
            </div>
        </div>
    </section>
@stop
@section('javascript')
    <script src="{{ asset('js/legacy-import-batches.js?v=' . $asset_v) }}"></script>
    <script type="text/javascript">
        $(document).on('submit', 'form.revert_import_form', function(e) {
            e.preventDefault();
            swal({
                title: LANG.sure,
                icon: 'warning',
                buttons: true,
                dangerMode: true,
            }).then(willDelete => {
                if (willDelete) {
                    this.submit();
                } else {
                    return false;
                }
            });
        });
    </script>
@endsection
