<table class="table-bordered table table-condensed">
    @foreach ($row->product as $item)
        <tr>
            <td><span>{{ $item[0] }}</span></td>
            <td>
                <strong>{{ number_format($item[1], 0) }}
                </strong>
            </td>
        </tr>
    @endforeach

</table>
