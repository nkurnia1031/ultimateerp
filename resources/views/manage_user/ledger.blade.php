@php
    $show_date_range = !empty($ledger_details['start_date']) && !empty($ledger_details['end_date']);
    $show_total_row = !empty($ledger_details['show_expense_summary']) && !empty($ledger_details['show_payroll_summary']);
@endphp

<style>
    .ledger-note-preview {
        display: block;
        max-width: 240px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
</style>

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
        @if(!empty($ledger_details['show_expense_summary']))
            <tr>
                <td>@lang('expense.expenses')</td>
                <td class="align-right">@format_currency($ledger_details['total_expense'])</td>
            </tr>
        @endif
        @if(!empty($ledger_details['show_payroll_summary']))
            <tr>
                <td>@lang('essentials::lang.payroll')</td>
                <td class="align-right">@format_currency($ledger_details['total_payroll'])</td>
            </tr>
        @endif
        @if($show_total_row)
            <tr>
                <td>@lang('sale.total')</td>
                <td class="align-right">@format_currency($ledger_details['total_amount'])</td>
            </tr>
        @endif
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
                    <th>@lang('account.transaction_type')</th>
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
                    @php
                        $full_note = !empty($data->descriptions) ? $data->descriptions : $data->additional_notes;
                        $note_preview = !empty($full_note) ? \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', trim($full_note)), 90) : '';
                    @endphp
                    <tr>
                        <td>{{@format_datetime($data->transaction_date)}}</td>
                        <td>
                            {{$data->ref_no}}
                            @if($data->type == 'expense_refund')
                                <br><small class="label bg-gray">@lang('lang_v1.refund')</small>
                            @endif
                        </td>
                        <td>{{$data->display_type}}</td>
                        <td>{{$data->display_category}}</td>
                        <td>{{$data->display_sub_category}}</td>
                        <td>{{$data->location_name}}</td>
                        <td>{{$data->display_contact}}</td>
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
                            @if(!empty($note_preview))
                                <span class="ledger-note-preview" title="{{ $full_note }}">
                                    {{ $note_preview }}
                                </span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="no-print">
                            @if(!empty($data->show_url))
                                <a href="#" data-href="{{$data->show_url}}" class="btn-modal btn btn-xs btn-info" data-container=".payment_modal">
                                    <i class="fas fa-eye"></i>
                                </a>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
