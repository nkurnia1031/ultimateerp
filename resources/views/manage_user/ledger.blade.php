@php
    $show_date_range = !empty($ledger_details['start_date']) && !empty($ledger_details['end_date']);
@endphp

<div class="col-md-12">
    <p class="text-center" style="text-align: center;">
        <strong>@lang('lang_v1.ledger')</strong>
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
            <td>@lang('expense.expenses')</td>
            <td class="align-right">@format_currency($ledger_details['total_expense'])</td>
        </tr>
        <tr>
            <td>@lang('lang_v1.paid_amount')</td>
            <td class="align-right">@format_currency($ledger_details['total_paid'])</td>
        </tr>
        <tr>
            <td>@lang('sale.balance')</td>
            <td class="align-right">@format_currency($ledger_details['total_due'])</td>
        </tr>
    </table>
</div>

<div class="col-md-6 col-sm-12">
    <table class="table table-condensed">
        <tr>
            <td>Total Transactions</td>
            <td class="align-right">{{$ledger_details['total_transactions']}}</td>
        </tr>
        <tr>
            <td>@lang('lang_v1.paid')</td>
            <td class="align-right">{{$ledger_details['paid_count']}}</td>
        </tr>
        <tr>
            <td>@lang('lang_v1.partial') / @lang('lang_v1.due')</td>
            <td class="align-right">{{$ledger_details['partial_count']}} / {{$ledger_details['due_count']}}</td>
        </tr>
    </table>
</div>

<div class="col-md-12">
    <div class="table-responsive">
        <table class="table table-striped" id="user_ledger_table">
            <thead>
                <tr>
                    <th>@lang('messages.date')</th>
                    <th>@lang('purchase.ref_no')</th>
                    <th>@lang('expense.expense_category')</th>
                    <th>@lang('product.sub_category')</th>
                    <th>@lang('purchase.business_location')</th>
                    <th>@lang('contact.contact')</th>
                    <th>@lang('sale.payment_status')</th>
                    <th class="align-right">@lang('sale.total')</th>
                    <th class="align-right">@lang('lang_v1.paid_amount')</th>
                    <th class="align-right">@lang('sale.balance')</th>
                    <th>@lang('lang_v1.added_by')</th>
                    <th>@lang('brand.note')</th>
                    <th class="no-print">@lang('messages.action')</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ledger_details['ledger'] as $data)
                    <tr>
                        <td>{{@format_datetime($data->transaction_date)}}</td>
                        <td>
                            {{$data->ref_no}}
                            @if($data->type == 'expense_refund')
                                <br><small class="label bg-gray">@lang('lang_v1.refund')</small>
                            @endif
                        </td>
                        <td>{{$data->display_category}}</td>
                        <td>{{$data->display_sub_category}}</td>
                        <td>{{$data->location_name}}</td>
                        <td>
                            {{!empty($data->supplier_business_name) ? $data->supplier_business_name : $data->contact_name}}
                        </td>
                        <td>
                            @if(!empty($data->payment_status))
                                <span class="label @payment_status($data->payment_status)">
                                    {{__('lang_v1.' . $data->payment_status)}}
                                </span>
                            @endif
                        </td>
                        <td class="align-right">@format_currency($data->signed_total)</td>
                        <td class="align-right">@format_currency($data->signed_paid)</td>
                        <td class="align-right">@format_currency($data->signed_due)</td>
                        <td>{{$data->added_by}}</td>
                        <td>
                            @if(!empty($data->descriptions))
                                {!! nl2br(e($data->descriptions)) !!}
                            @elseif(!empty($data->additional_notes))
                                {!! nl2br(e($data->additional_notes)) !!}
                            @endif
                        </td>
                        <td class="no-print">
                            <a href="#" data-href="{{action([\App\Http\Controllers\ExpenseController::class, 'show'], [$data->id])}}" class="btn-modal btn btn-xs btn-info" data-container=".payment_modal">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
