@extends('layouts.app')
@section('title', 'Asset Tag Ledger')

@php
    $type_labels = [
        'sell' => __('sale.sale'),
        'sell_return' => __('lang_v1.sell_return'),
        'purchase' => __('lang_v1.purchase'),
        'purchase_return' => __('lang_v1.purchase_return'),
        'expense' => __('expense.expenses'),
        'expense_refund' => __('lang_v1.refund'),
    ];
@endphp

@section('content')
    @includeIf('assetmanagement::layouts.nav')

    <section class="content-header no-print">
        <h1>
            Tag Ledger
            <small>{{$asset->name}} ({{$asset->asset_code}})</small>
        </h1>
    </section>

    <section class="content no-print">
        <div class="box box-solid">
            <div class="box-header with-border">
                <h5 class="box-title">Asset Details</h5>
            </div>
            <div class="box-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-condensed">
                            <tr>
                                <td><strong>@lang('assetmanagement::lang.asset_name')</strong></td>
                                <td>{{$asset->name}}</td>
                            </tr>
                            <tr>
                                <td><strong>@lang('assetmanagement::lang.asset_code')</strong></td>
                                <td>{{$asset->asset_code}}</td>
                            </tr>
                            <tr>
                                <td><strong>@lang('business.business_location')</strong></td>
                                <td>{{$asset->businessLocation->name ?? ''}}</td>
                            </tr>
                            <tr>
                                <td><strong>@lang('assetmanagement::lang.asset_category')</strong></td>
                                <td>{{$asset->category_name}}</td>
                            </tr>
                            <tr>
                                <td><strong>@lang('lang_v1.quantity')</strong></td>
                                <td>{{@format_quantity($asset->quantity)}}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-condensed">
                            <tr>
                                <td><strong>@lang('assetmanagement::lang.series_model')</strong></td>
                                <td>{{$asset->model}}</td>
                            </tr>
                            <tr>
                                <td><strong>@lang('assetmanagement::lang.serial_no')</strong></td>
                                <td>{{$asset->serial_no}}</td>
                            </tr>
                            <tr>
                                <td><strong>@lang('purchase.purchase_date')</strong></td>
                                <td>@if(!empty($asset->purchase_date)) {{@format_date($asset->purchase_date)}} @endif</td>
                            </tr>
                            <tr>
                                <td><strong>@lang('sale.unit_price')</strong></td>
                                <td>@format_currency($asset->unit_price)</td>
                            </tr>
                            <tr>
                                <td><strong>Tags</strong></td>
                                <td>
                                    @forelse($asset->tags as $tag)
                                        <span class="label bg-blue">{{$tag->name}}</span>
                                    @empty
                                        <span class="text-muted">No tags linked to this asset.</span>
                                    @endforelse
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12 text-right">
                        <a href="{{action([\Modules\AssetManagement\Http\Controllers\AssetController::class, 'index'])}}" class="btn btn-default">
                            Back
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 col-sm-12">
                <table class="table table-condensed">
                    <tr>
                        <td>@lang('sale.sale')</td>
                        <td class="align-right">@format_currency($ledger_details['total_sales'])</td>
                    </tr>
                    <tr>
                        <td>@lang('lang_v1.purchase')</td>
                        <td class="align-right">@format_currency($ledger_details['total_purchases'])</td>
                    </tr>
                    <tr>
                        <td>@lang('expense.expenses')</td>
                        <td class="align-right">@format_currency($ledger_details['total_expenses'])</td>
                    </tr>
                </table>
            </div>

            <div class="col-md-6 col-sm-12">
                <table class="table table-condensed">
                    <tr>
                        <td>Amount In</td>
                        <td class="align-right">@format_currency($ledger_details['total_in'])</td>
                    </tr>
                    <tr>
                        <td>Amount Out</td>
                        <td class="align-right">@format_currency($ledger_details['total_out'])</td>
                    </tr>
                    <tr>
                        <td><strong>Net Result</strong></td>
                        <td class="align-right"><strong>@format_currency($ledger_details['net_result'])</strong></td>
                    </tr>
                    <tr>
                        <td>Total Transactions</td>
                        <td class="align-right">{{$ledger_details['total_transactions']}}</td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-striped" id="asset_tag_ledger_table">
                        <thead>
                            <tr>
                                <th>@lang('messages.date')</th>
                                <th>Source</th>
                                <th>@lang('purchase.ref_no')</th>
                                <th>Matched Tags</th>
                                <th>@lang('brand.note')</th>
                                <th>@lang('contact.contact')</th>
                                <th>@lang('purchase.business_location')</th>
                                <th>@lang('sale.payment_status')</th>
                                <th class="align-right">Amount In</th>
                                <th class="align-right">Amount Out</th>
                                <th class="align-right">Balance</th>
                                <th>@lang('lang_v1.added_by')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ledger_details['ledger'] as $data)
                                <tr>
                                    <td>{{@format_datetime($data->transaction_date)}}</td>
                                    <td>{{isset($type_labels[$data->type]) ? $type_labels[$data->type] : $data->type}}</td>
                                    <td>{{$data->reference_no}}</td>
                                    <td>{{$data->tag_names}}</td>
                                    <td>{!! nl2br(e($data->description)) !!}</td>
                                    <td>{{$data->related_entity}}</td>
                                    <td>{{$data->location_name}}</td>
                                    <td>
                                        @if(!empty($data->payment_status))
                                            <span class="label @payment_status($data->payment_status)">
                                                {{__('lang_v1.' . $data->payment_status)}}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="align-right">@format_currency($data->amount_in)</td>
                                    <td class="align-right">@format_currency($data->amount_out)</td>
                                    <td class="align-right">@format_currency($data->running_balance)</td>
                                    <td>{{$data->added_by}}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('javascript')
    <script type="text/javascript">
        $(document).ready(function() {
            $('#asset_tag_ledger_table').DataTable({
                searching: false,
                ordering: false,
                paging: false,
                fixedHeader: false,
                dom: 't'
            });
            __currency_convert_recursively($('.content'));
        });
    </script>
@endsection
