<ul class="list-group">
    @foreach ($row->product as $item)
        <li class="list-group-item d-flex justify-content-between text-nowrap"><span>{{ $item[0] }} </span><span
                class="badge badge-dark">{{ number_format($item[1], 0) }}</span></li>
    @endforeach


</ul>
