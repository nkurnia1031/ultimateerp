@extends('layouts.app')
@section('title', __('stock_adjustment.stock_adjustments'))

@section('content')

    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('stock_adjustment.stock_adjustments')
            <small></small>
        </h1>
    </section>


    <!-- Main content -->
    <section class="content">
        @component('components.filters', ['title' => __('report.filters')])
            <div class="col-md-3">
                {!! Form::label('asset_list_filter_asset_id', 'Assets' . ':') !!}
                {!! Form::select('asset_list_filter_asset_id', $assets, null, [
                    'class' => 'form-control select2',
                    'placeholder' => __('lang_v1.all'),
                    'style' => 'width: 100%;',
                ]) !!}
            </div>
        @endcomponent
        @component('components.widget', [
            'class' => 'box-primary',
            'title' => __('stock_adjustment.all_stock_adjustments'),
        ])
            @slot('tool')
                <div class="box-tools">
                    @if (auth()->user()->can('purchase.create'))
                        <a class="tw-dw-btn tw-bg-gradient-to-r tw-from-indigo-600 tw-to-blue-500 tw-font-bold tw-text-white tw-border-none tw-rounded-full pull-right"
                            href="{{ action([\App\Http\Controllers\StockAdjustmentController::class, 'create']) }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                class="icon icon-tabler icons-tabler-outline icon-tabler-plus">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M12 5l0 14" />
                                <path d="M5 12l14 0" />
                            </svg> @lang('messages.add')
                        </a>
                    @endif
                </div>
            @endslot
            <div class="table-responsive">
                <table class="table table-bordered table-striped ajax_view" id="stock_adjustment_table">
                    <thead>
                        <tr>
                            <th>@lang('messages.action')</th>
                            <th>@lang('messages.date')</th>
                            <th>@lang('purchase.ref_no')</th>
                            <th>@lang('business.location')</th>
                            <th>@lang('stock_adjustment.adjustment_type')</th>
                            <th>@lang('stock_adjustment.total_amount')</th>
                            <th>@lang('stock_adjustment.total_amount_recovered')</th>
                            <th>@lang('stock_adjustment.reason_for_stock_adjustment')</th>
                            <th>@lang('lang_v1.added_by')</th>
                        </tr>
                    </thead>
                    <tfoot>
                        <tr class="bg-gray font-17 footer-total text-center">
                            <th colspan="5"><strong>@lang('sale.total'):</strong></th>
                            <th class="footer_total_amount"></th>
                            <th class="footer_total_amount_recovered"></th>
                            <th colspan="2"></th>

                        </tr>
                    </tfoot>
                </table>
            </div>
        @endcomponent

    </section>
    <!-- /.content -->
@stop
@section('javascript')
    <script src="{{ asset('js/stock_adjustment.js?v=' . $asset_v) }}"></script>
    <script>
        $(document).ready(function() {
            stock_adjustment_table = $('#stock_adjustment_table').DataTable({
                processing: true,
                serverSide: true,
                fixedHeader: false,
                ajax: {
                    url: '/stock-adjustments',
                    "data": function(d) {
                        d.asset_id = $('#asset_list_filter_asset_id').val();
                    }
                },
                columnDefs: [{
                    targets: 0,
                    orderable: false,
                    searchable: false,
                }, ],
                aaSorting: [
                    [1, 'desc']
                ],
                columns: [{
                        data: 'action',
                        name: 'action'
                    },
                    {
                        data: 'transaction_date',
                        name: 'transaction_date'
                    },
                    {
                        data: 'ref_no',
                        name: 'ref_no'
                    },
                    {
                        data: 'location_name',
                        name: 'BL.name'
                    },
                    {
                        data: 'adjustment_type',
                        name: 'adjustment_type'
                    },
                    {
                        data: 'final_total',
                        name: 'final_total'
                    },
                    {
                        data: 'total_amount_recovered',
                        name: 'total_amount_recovered'
                    },
                    {
                        data: 'additional_notes',
                        name: 'additional_notes'
                    },
                    {
                        data: 'added_by',
                        name: 'u.first_name'
                    },
                ],
                "footerCallback": function(row, data, start, end, display) {
                    let api = this.api();

                    let total = api
                        .column(5)
                        .nodes()
                        .reduce((sum, td) => {
                            let val = $(td).find('span').data(
                                'orig-value'); // ambil atribut data-orig-value
                            return sum + (parseFloat(val) || 0);
                        }, 0);
                    let total2 = api
                        .column(6)
                        .nodes()
                        .reduce((sum, td) => {
                            let val = $(td).find('span').data(
                                'orig-value'); // ambil atribut data-orig-value
                            return sum + (parseFloat(val) || 0);
                        }, 0);



                    $('.footer_total_amount_recovered').html(__currency_trans_from_en(
                        total2));
                    $('.footer_total_amount').html(__currency_trans_from_en(
                        total));

                },
                fnDrawCallback: function(oSettings) {
                    __currency_convert_recursively($('#stock_adjustment_table'));
                },
            });

        });
        $(document).on('change',
            '#asset_list_filter_asset_id',
            function() {
                stock_adjustment_table.ajax.reload();
            });
    </script>
@endsection

@cannot('view_purchase_price')
    <style>
        .show_price_with_permission {
            display: none !important;
        }
    </style>
@endcannot
