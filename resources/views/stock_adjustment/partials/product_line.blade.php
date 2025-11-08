<table class="table table-condensed bg-gray">
    <tr class="bg-green">
        <th>@lang('sale.product')</th>
        <th>@lang('sale.qty')</th>

    </tr>
    @foreach ($stock_adjustment_lines as $stock_adjustment_line)
        <tr>
            <td>
                {{ $stock_adjustment_line->variation->full_name }}
            </td>
            <td>
                {{ @format_quantity($stock_adjustment_line->quantity) }}
            </td>

        </tr>
    @endforeach
</table>
