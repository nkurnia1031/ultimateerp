@foreach ($tags as $item)
    <a href="javascript:;" class="label bg-light-green">
        <span>{{ $item->name }}
        </span></a>
@endforeach
