@extends('layouts.app')
@section('title', __('expense.edit_expense'))

@section('content')

    <!-- Content Header (Page header) -->
    @if (request()->routeIs('expenses.duplicate'))
        @php
            $is_duplicate = true;
            $url = action([\App\Http\Controllers\ExpenseController::class, 'store']);
            $save = 'save';
            $method = 'POST';
        @endphp
        <section class="content-header">
            <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Duplicate Expense</h1>
        </section>
    @else
        @php
            $is_duplicate = false;
            $url = action([\App\Http\Controllers\ExpenseController::class, 'update'], [$expense->id]);
            $save = 'update';
            $method = 'PUT';

        @endphp
        <section class="content-header">
            <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('expense.edit_expense')</h1>
        </section>
    @endif

    <!-- Main content -->
    <section class="content">
        {!! Form::open([
            'url' => $url,
            'method' => $method,
            'id' => 'add_expense_form',
            'files' => true,
        ]) !!}
        <div class="box box-solid">
            <div class="box-body">
                @if (count($business_locations) == 2)
                    @php
                        $default_location = current(array_keys($business_locations->toArray()));
                    @endphp
                @else
                    @php $default_location = null; @endphp
                @endif

                <div class="row">
                    {{-- Business Location --}}
                    <div class="col-md-4">
                        <div class="form-group">
                            {!! Form::label('location_id', __('purchase.business_location') . ':*') !!}
                            {!! Form::select(
                                'location_id',
                                $business_locations,
                                $expense->location_id ?? $default_location,
                                [
                                    'class' => 'form-control select2',
                                    'placeholder' => __('messages.please_select'),
                                    'required',
                                ],
                                $bl_attributes,
                            ) !!}
                        </div>
                    </div>

                    {{-- Reference No --}}
                    <div class="col-md-4">
                        <div class="form-group">
                            {!! Form::label('ref_no', __('purchase.ref_no') . ':') !!}
                            {!! Form::text('ref_no', $expense->ref_no, ['class' => 'form-control']) !!}
                            <small class="form-text text-muted">
                                @lang('lang_v1.leave_empty_to_autogenerate')
                            </small>
                        </div>
                    </div>

                    {{-- Date --}}
                    <div class="col-md-4">
                        <div class="form-group">
                            {!! Form::label('transaction_date', __('messages.date') . ':*') !!}
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                                {!! Form::text('transaction_date', @format_datetime($expense->transaction_date), [
                                    'class' => 'form-control',
                                    'readonly',
                                    'required',
                                    'id' => 'expense_transaction_date',
                                ]) !!}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row ">
                    {{-- Expense For --}}
                    <div class="col-md-4">
                        <div class="form-group">
                            {!! Form::label('expense_for', __('expense.expense_for') . ':') !!}
                            @show_tooltip(__('tooltip.expense_for'))
                            {!! Form::select('expense_for', $users, $expense->expense_for, [
                                'class' => 'form-control select2',
                                'placeholder' => __('messages.please_select'),
                            ]) !!}
                        </div>
                    </div>

                    {{-- Expense For Contact --}}
                    <div class="col-md-4   ">
                        <div class="form-group">
                            {!! Form::label('contact_id', __('lang_v1.expense_for_contact') . ':') !!}
                            {!! Form::select('contact_id', $contacts, $expense->contact_id, [
                                'class' => 'form-control select2',
                                'placeholder' => __('messages.please_select'),
                            ]) !!}
                        </div>
                    </div>
                    <div class="col-md-4 ">
                        <div class="form-group">
                            {!! Form::label('tag_id', __('Tags') . ':') !!}
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="fa fa-tag"></i>
                                </span>
                                {!! Form::select('tag[]', $expense->tags, $expense->tagsids, [
                                    'class' => 'form-control',
                                    'multiple' => 'true',
                                    'id' => 'tag_id',
                                ]) !!}
                                <span class="input-group-btn">
                                    <a target="_blank"
                                        href="{{ action([\App\Http\Controllers\TagController::class, 'create']) }}"
                                        class="btn btn-default bg-white btn-flat" data-name=""><i
                                            class="fa fa-plus-circle text-primary fa-lg"></i></a>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <hr>
                        {{-- DETAIL EXPENSE --}}
                        {!! Form::label('judul', __('expense.all_expenses') . ':*') !!}

                        <table class="table table-bordered" id="expensea_table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Sub Category</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Attach Document</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($expense->expense_lines as $key => $item)
                                    <tr class="expense_row">
                                        <td>{!! Form::select('expense_lines[' . $key . '][category_id]', $expense_categories, $item->category_id, [
                                            'class' => 'form-control select2',
                                            'id' => 'category_id' . $key,
                                            'onchange' => 'get_expense_sub_categories("category_id' . $key . '","sub_category_id' . $key . '")',
                                        ]) !!}</td>
                                        <td>{!! Form::select('expense_lines[' . $key . '][sub_category_id]', $expense_subcategories, $item->category_id, [
                                            'class' => 'form-control select2',
                                            'id' => 'sub_category_id' . $key,
                                        ]) !!}</td>
                                        <td>{!! Form::text('expense_lines[' . $key . '][description]', $item->description, ['class' => 'form-control']) !!}</td>
                                        <td>{!! Form::text('expense_lines[' . $key . '][amount]', $item->amount, [
                                            'class' => 'form-control input_number expense-amount',
                                        ]) !!}</td>
                                        <td class="d-flex">
                                            <input type="hidden" name="expense_lines[{{ $key }}][document]"
                                                value="{{ $item->document }}">
                                            {!! Form::file('expense_lines[' . $key . '][document]', ['class' => 'form-control']) !!}
                                            @if ($item->document_path)
                                                <button data-href="{{ $item->document_path }}"
                                                    class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  tw-dw-btn-info view_uploaded_document"
                                                    data-toggle="tooltip" title="{{ __('lang_v1.view_document') }}"><i
                                                        class="fa fa-eye"></i></button>
                                            @endif
                                        </td>
                                        <td>
                                            <input type="hidden" name="expense_lines[{{ $key }}][id]"
                                                value="{{ $item->id }}">
                                            <button type="button" class="btn btn-danger remove_row">x</button>
                                        </td>
                                    </tr>
                                @endforeach

                            </tbody>
                        </table>
                        <button type="button" class="btn btn-primary" id="add_row">+ Add Line</button>

                        <hr>

                        {{-- DISCOUNT + TOTAL --}}
                        <div class="row">
                            <div class="col-md-4">
                                <label>Discount Type</label>
                                {!! Form::select('discount_type', ['fixed' => 'Fixed', 'percentage' => 'Percentage'], $expense->discount_type, [
                                    'class' => 'form-control',
                                    'id' => 'discount_type',
                                ]) !!}
                            </div>
                            <div class="col-md-4">
                                <label>Discount Value</label>
                                {!! Form::text('discount_amount', $expense->discount_amount, [
                                    'class' => 'form-control input_number',
                                    'id' => 'discount_amount',
                                ]) !!}
                            </div>
                            <div class="col-md-4">
                                <label>Tax</label>
                                {!! Form::select('tax_id', $taxes['tax_rates'], $expense->tax_id, [
                                    'class' => 'form-control select2',
                                    'id' => 'tax_id',
                                ]) !!}
                            </div>
                        </div>

                        <div class="text-right mt-3">
                            <p><b>Subtotal:</b> <span id="subtotal_display">0</span></p>
                            <p><b>Discount:</b> <span id="discount_display">0</span></p>
                            <p><b>Tax:</b> <span id="tax_display">0</span> </p>
                            <h3><b>Total Amount:</b> <span id="grand_total_display">0</span></h3>
                        </div>

                        <div style="display:none;">
                            {!! Form::text('final_total', $expense->final_total, ['id' => 'final_total', 'readonly' => true, 'required']) !!}
                        </div>
                    </div>
                </div>
                <div class="row tw-flex">
                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('additional_notes', __('expense.expense_note') . ':') !!}
                            {!! Form::textarea('additional_notes', $expense->additional_notes, ['class' => 'form-control', 'rows' => 3]) !!}

                        </div>
                    </div>
                    <div class="col-md-3  mt-4 ">
                        <label>
                            {!! Form::checkbox('is_refund', 1, $expense->is_refund, ['class' => 'input-icheck', 'id' => 'is_refund']) !!} @lang('lang_v1.is_refund')?
                        </label>@show_tooltip(__('lang_v1.is_refund_help'))


                    </div>
                    <div class="col-md-3    mt-4">
                        <label>
                            {!! Form::checkbox('is_pay_later', 1, $expense->is_pay_later, [
                                'class' => 'input-icheck',
                                'id' => 'is_pay_later',
                            ]) !!} Pay Later?
                        </label>
                    </div>
                </div>
            </div>



            {{-- NOTES + FLAGS --}}

        </div>


        {{-- PAYMENT --}}
        @include('expense.recur_expense_form_part')
        @component('components.widget', [
            'class' => 'box-solid',
            'id' => 'payment_rows_div',
            'title' => __('purchase.add_payment'),
        ])
            <div class="payment_row">
                @include('sale_pos.partials.payment_row_form', ['row_index' => 0, 'show_date' => true])
                <hr>
                <div class="row">
                    <div class="col-sm-12">
                        <div class="pull-right">
                            <strong>@lang('purchase.payment_due'):</strong>
                            <span id="payment_due">{{ @num_format(0) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @endcomponent

        <div class="col-sm-12 text-center">
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-lg">@lang('messages.' . $save)</button>
        </div>

        {!! Form::close() !!}
    </section>
@stop


@section('javascript')
    <script type="text/javascript">
        $(document).ready(function() {
            $('.select2').select2();
            $('.paid_on').datetimepicker({
                format: moment_date_format + ' ' + moment_time_format,
                ignoreReadonly: true,
            });
            get_expense_sub_categories(`category_id0`, `sub_category_id0`);
            calculateTotals();
            let lastDate = $('#expense_transaction_date').val();

            setInterval(function() {
                let currentDate = $('#expense_transaction_date').val();

                // cek perubahan
                if (currentDate !== lastDate) {
                    lastDate = currentDate;

                    if (!$('#is_pay_later').is(':checked')) {
                        $('.paid_on').val(currentDate);
                    }
                }
            }, 300);

            let line_index = 1;

            // Add row
            $('#add_row').click(function() {
                let row = `<tr class="expense_row">
            <td>{!! Form::select('expense_lines[IDX][category_id]', $expense_categories, null, [
                'class' => 'form-control select2',
                'id' => 'category_id${line_index}',
                'onchange' => 'get_expense_sub_categories("category_id${line_index}","sub_category_id${line_index}")',
            ]) !!}</td>
            <td>{!! Form::select('expense_lines[IDX][sub_category_id]', [], null, [
                'class' => 'form-control select2',
                'id' => 'sub_category_id${line_index}',
            ]) !!}</td>
            <td>{!! Form::text('expense_lines[IDX][description]', null, ['class' => 'form-control']) !!}</td>
            <td>{!! Form::text('expense_lines[IDX][amount]', null, ['class' => 'form-control input_number expense-amount']) !!}</td>
            <td>{!! Form::file('expense_lines[IDX][document]', ['class' => 'form-control']) !!}</td>
            <td><button type="button" class="btn btn-danger remove_row">x</button></td>
        </tr>`;
                $('#expensea_table tbody').append(row.replace(/IDX/g, line_index));
                line_index++;
                get_expense_sub_categories(`category_id${line_index}`, `sub_category_id${line_index}`);
                $('.select2').select2();
            });

            // Remove row
            $(document).on('click', '.remove_row', function() {
                $(this).closest('tr').remove();
                calculateTotals();
            });

            // Calculate totals
            function calculateTotals() {
                let subtotal = 0;
                $('.expense-amount').each(function() {
                    subtotal += __read_number($(this));
                });

                let discount_type = $('#discount_type').val();
                let discount_val = __read_number($('#discount_amount'));
                let discount = (discount_type == 'fixed') ? discount_val : subtotal * (discount_val / 100);

                let after_discount = subtotal - discount;
                let tax_rate = parseFloat($('#tax_id option:selected').val()) || 0;
                let taxRateList = {!! json_encode($taxes['attributes']) !!};
                tax_rate = taxRateList[tax_rate] ? taxRateList[tax_rate]['data-rate'] : 0;
                let tax = after_discount * (tax_rate / 100);
                let grand_total = after_discount + tax;

                $('#subtotal_display').text(__currency_trans_from_en(subtotal, true));
                $('#discount_display').text(__currency_trans_from_en(discount, true));
                $('#tax_display').text(__currency_trans_from_en(tax, true));
                $('#grand_total_display').text(__currency_trans_from_en(grand_total, true));
                __write_number($('#final_total'), grand_total);

                syncPaymentAmount();
            }

            $(document).on('input change', '.expense-amount, #discount_amount, #discount_type, #tax_id',
                calculateTotals);

            // Sync Pay Later
            function syncPaymentAmount() {
                var final_total = __read_number($('#final_total'));
                var is_pay_later = $('#is_pay_later').prop('checked');
                var payment_amount_input = $('.payment_row').find('input.payment-amount').first();

                if (is_pay_later) {
                    __write_number(payment_amount_input, 0);
                } else {
                    __write_number(payment_amount_input, final_total);
                }

                let payment_due = final_total - __read_number(payment_amount_input);
                $('#payment_due').text(__currency_trans_from_en(payment_due, true));
            }

            $('#is_pay_later').on('ifChecked ifUnchecked change', syncPaymentAmount);
            calculateTotals();
        });

        $('#is_refund').on('ifChecked', function(event) {
            $('#recur_expense_div').addClass('hide');
        });
        $('#is_refund').on('ifUnchecked', function(event) {
            $('#recur_expense_div').removeClass('hide');
        });

        function get_expense_sub_categories(id, id2) {
            var cat = $('#' + id).val();
            $.ajax({
                method: 'POST',
                url: '/get-expense-sub-categories',
                dataType: 'html',
                data: {
                    cat_id: cat
                },
                success: function(result) {
                    if (result) {
                        $('#' + id2).html(result);
                    }
                },
            });
        }
    </script>
@endsection
