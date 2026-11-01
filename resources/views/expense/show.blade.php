<div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
        <div class="modal-mody">
            <!-- Content Header (Page header) -->


            <!-- Main content -->
            <section class="content">

                <div class="box box-solid">
                    <div class="box-body">
                        <div class="row">
                            <div class="col-sm-4 col-xs-6">
                                <div class="form-group">
                                    {!! Form::label('location_id', __('purchase.business_location') . ':*') !!}
                                    <br>
                                    {{ $business_locations[$expense->location_id] ?? '' }}

                                </div>
                            </div>

                            <div class="col-sm-4 col-xs-6">
                                <div class="form-group">
                                    {!! Form::label('ref_no', __('purchase.ref_no') . ':*') !!}
                                    <br>
                                    {!! $expense->ref_no !!}
                                </div>
                            </div>
                            <div class="col-sm-4 col-xs-6">
                                <div class="form-group">
                                    {!! Form::label('recur_details', __('lang_v1.recur_details') . ':') !!}
                                    <br>
                                    {!! $expense->recur_details !!}
                                </div>
                            </div>
                            <div class="clearfix"></div>
                            <div class="col-sm-4 col-xs-6">
                                <div class="form-group">
                                    {!! Form::label('transaction_date', __('messages.date') . ':*') !!}

                                    <br>
                                    {{ @format_datetime($expense->transaction_date) }}
                                </div>
                            </div>
                            <div class="col-sm-4 col-xs-6">
                                <div class="form-group">
                                    {!! Form::label('expense_for', __('expense.expense_for') . ':') !!} @show_tooltip(__('tooltip.expense_for'))
                                    <br>
                                    {{ $users[$expense->expense_for] ?? '' }}

                                </div>
                            </div>
                            <div class="col-sm-4 col-xs-6">
                                <div class="form-group">
                                    {!! Form::label('contact_id', __('lang_v1.expense_for_contact') . ':') !!}
                                    <br>
                                    {{ $contacts[$expense->contact_id] ?? '' }}

                                </div>
                            </div>
                            <div class="clearfix"></div>

                            <div class="col-sm-4 col-xs-6">
                                <div class="form-group">
                                    {!! Form::label('tax_id', __('product.applicable_tax') . ':') !!}
                                    <br>
                                    {{ $taxes['tax_rates'][$expense->tax_id] ?? '' }}

                                </div>
                            </div>
                            <div class="col-sm-4 col-xs-6">
                                <div class="form-group">
                                    {!! Form::label('final_total', __('sale.total_amount') . ':*') !!}
                                    <br>
                                    @php
                                        $final_total = $expense->final_total;
                                        $type = $expense->type;
                                        if ($type == 'expense_refund') {
                                            $final_total = -1 * $final_total;
                                        }
                                    @endphp
                                    <span class="display_currency final-total" data-currency_symbol="true"
                                        data-orig-value="@if ($type == 'expense_refund') {{ -1 * $final_total }}@else{{ $final_total }} @endif">
                                        @if ($type == 'expense_refund')
                                            -
                                        @endif @format_currency($final_total)
                                    </span>

                                </div>


                            </div>
                            <div class="col-sm-4 col-xs-6">
                                <div class="form-group">
                                    @if (!empty($expense->tags))
                                        <b>@lang('Tags'):</b>
                                        <div class="tw-flex tw-flex-wrap">
                                            @foreach ($expense->tags as $item)
                                                <div class="label bg-light-green tw-m-1">{{ $item->name }}</div>
                                            @endforeach

                                        </div>
                                    @endif

                                </div>
                            </div>
                            <div class="clearfix"></div>


                        </div>
                    </div>
                </div> <!--box end-->
                <div class="box box-solid">
                    <div class="box-body">
                        <div class="row">
                            <div class="col-sm-12">
                                {!! Form::label('judul', __('expense.all_expenses') . ':*') !!}

                                <table class="table table-bordered w-100">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Sub Category</th>
                                            <th>Description</th>
                                            <th>Amount</th>
                                            <th>Attach Document</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($expense->expense_lines as $item)
                                            <tr class="expense_row">
                                                <td>{{ $item->category?->name }}</td>
                                                <td>{{ $item->subCategory?->name }}</td>
                                                <td>{{ $item->description }}</td>
                                                <td><span class="display_currency"
                                                        data-currency_symbol="true">{{ $item->amount }}</span></td>
                                                <td>
                                                    @if ($item->document_path)
                                                        <button data-href="{{ $item->document_path }}"
                                                            class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  tw-dw-btn-info view_uploaded_document"
                                                            data-toggle="tooltip"
                                                            title="{{ __('lang_v1.view_document') }}"><i
                                                                class="fa fa-eye"></i></button>
                                                    @endif
                                                </td>

                                            </tr>
                                        @endforeach

                                    </tbody>
                                </table>
                                <div class="text-right mt-3">
                                    <p><b>Subtotal:</b> <span id="subtotal_display"><span class="display_currency"
                                                data-currency_symbol="true">{{ $expense->expense_lines->sum('amount') }}</span>
                                        </span>
                                    </p>
                                    <p><b>Discount:</b> <span id="discount_display"><span class="display_currency"
                                                data-currency_symbol="true">{{ $expense->expense_lines->sum('amount') - $expense->total_before_tax }}</span></span>
                                    </p>
                                    <p><b>Tax:</b> <span id="tax_display"><span class="display_currency"
                                                data-currency_symbol="true">{{ $expense->tax_amount }}</span></span>
                                    </p>
                                    <h3><b>Total Amount:</b> <span id="grand_total_display"><span
                                                class="display_currency"
                                                data-currency_symbol="true">{{ $expense->final_total }}</span></span>
                                    </h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box box-solid ">
                    <div class="box-body">

                        <div class="row">

                            <div class="col-sm-12 col-xs-12">
                                <h4>{{ __('sale.payment_info') }}:</h4>
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <table class="table table-bordered w-100">
                                    <tr class="bg-green">
                                        <th>#</th>
                                        <th>{{ __('messages.date') }}</th>
                                        <th>{{ __('purchase.ref_no') }}</th>
                                        <th>{{ __('sale.amount') }}</th>
                                        <th>{{ __('sale.payment_mode') }}</th>
                                        <th>{{ __('sale.payment_note') }}</th>
                                        <th>{{ __('Lampiran') }}</th>
                                    </tr>
                                    @php
                                        $total_paid = 0;
                                    @endphp
                                    @forelse($expense->payment_lines as $payment_line)
                                        @php
                                            $total_paid += $payment_line->amount;
                                        @endphp
                                        <tr>
                                            <td>{{ $loop->iteration }}</td>
                                            <td>{{ @format_date($payment_line->paid_on) }}</td>
                                            <td>{{ $payment_line->payment_ref_no }}</td>
                                            <td><span class="display_currency"
                                                    data-currency_symbol="true">{{ $payment_line->amount }}</span>
                                            </td>
                                            <td>{{ $payment_methods[$payment_line->method] ?? '' }}</td>
                                            <td>
                                                @if ($payment_line->note)
                                                    {{ ucfirst($payment_line->note) }}
                                                @else
                                                    --
                                                @endif
                                            </td>
                                            <td>
                                                @if ($payment_line->document_path)
                                                    <button data-href="{{ $payment_line->document_path }}"
                                                        class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  tw-dw-btn-info view_uploaded_document"
                                                        data-toggle="tooltip"
                                                        title="{{ __('lang_v1.view_document') }}"><i
                                                            class="fa fa-eye"></i></button>
                                                @endif

                                            </td>

                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center">
                                                @lang('purchase.no_payments')
                                            </td>
                                        </tr>
                                    @endforelse
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                @if ($expense->media->isNotEmpty())
                    <div class="box box-solid">
                        <div class="box-body">
                            <strong>@lang('lang_v1.attachments'):</strong><br>
                            @include('sell.partials.media_table', ['medias' => $expense->media])
                        </div>
                    </div>
                @endif

            </section>
        </div>
        <div class="modal-footer">
            <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white no-print" aria-label="Print"
                onclick="$(this).closest('div.modal-content').printThis();"><i class="fa fa-print"></i>
                @lang('messages.print')
            </button>
            <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white no-print"
                data-dismiss="modal">@lang('messages.close')</button>
        </div>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        var element = $('div.modal-lg');
        __currency_convert_recursively(element);
    });
</script>
