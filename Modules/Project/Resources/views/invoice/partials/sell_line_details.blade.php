<table class="table bg-gray">
    <thead>
        <tr class="bg-green">
            <th>#</th>
            <th>@lang('project::lang.task')</th>
            <th>@lang('lang_v1.description')</th>
            <th>@lang('sale.qty')</th>
            <th>@lang('sale.unit_price')</th>
            <th>@lang('sale.tax')</th>
            <th>@lang('sale.subtotal')</th>
        </tr>
    </thead>
    <tbody>
        @forelse($sell->project_invoice_lines as $line)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $line->task }}</td>
                <td>{!! nl2br(e($line->description)) !!}</td>
                <td><span class="display_currency" data-currency_symbol="false" data-is_quantity="true">{{ $line->quantity }}</span></td>
                <td><span class="display_currency" data-currency_symbol="true">{{ $line->rate }}</span></td>
                <td>{{ $line->tax->name ?? '-' }}</td>
                <td><span class="display_currency" data-currency_symbol="true">{{ $line->total }}</span></td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center">@lang('project::lang.no_record_found')</td></tr>
        @endforelse
    </tbody>
</table>
