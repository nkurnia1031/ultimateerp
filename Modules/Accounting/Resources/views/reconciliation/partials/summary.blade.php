@forelse($summary['by_year_type_component'] as $row)
    <tr>
        <td>{{ $row['year'] }}</td>
        <td>{{ $row['type_label'] }}</td>
        <td>{{ $row['component_label'] }}</td>
        <td class="text-right">{{ number_format($row['finding_count']) }}</td>
        <td class="text-right">@format_currency($row['gross_amount'])</td>
        <td class="text-right">@format_currency($row['net_amount'])</td>
    </tr>
@empty
    <tr><td colspan="6" class="text-center text-muted">Belum ada temuan pada filter ini.</td></tr>
@endforelse
