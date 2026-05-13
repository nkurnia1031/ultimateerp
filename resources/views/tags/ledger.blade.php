@php
    $show_date_range = !empty($ledger_details['start_date']) && !empty($ledger_details['end_date']);

    $type_labels = [
        'sell' => __('sale.sale'),
        'sell_return' => __('lang_v1.sell_return'),
        'purchase' => __('lang_v1.purchase'),
        'purchase_return' => __('lang_v1.purchase_return'),
        'expense' => __('expense.expenses'),
        'expense_refund' => __('lang_v1.refund'),
    ];
@endphp

<div class="col-md-12">
    <p class="text-center" style="text-align: center;">
        <strong>@lang('lang_v1.ledger') - {{$tag->name}}</strong>
        @if($show_date_range)
            <br>
            <b>{{$ledger_details['start_date']}} @lang('lang_v1.to') {{$ledger_details['end_date']}}</b>
        @endif
        @if(!empty($location))
            <br>
            <b>{{$location->name}}</b>
        @endif
    </p>
</div>

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

<div class="col-md-12">
    <div class="table-responsive">
        <table class="table table-striped" id="tag_ledger_table">
            <thead>
                <tr>
                    <th>@lang('messages.date')</th>
                    <th>Source</th>
                    <th>@lang('purchase.ref_no')</th>
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
