@foreach ($tags as $item)
    @php
        $tag_name = $item->name ?? '';
        $tag_id = $item->id ?? null;
    @endphp

    @if (!empty($tag_id))
        <a href="{{ action([\App\Http\Controllers\TagController::class, 'show'], [$tag_id]) }}?view=ledger" class="label bg-light-green" title="@lang('lang_v1.ledger')">
            <span>{{ $tag_name }}</span>
        </a>
    @else
        <a href="javascript:;" class="label bg-light-green">
            <span>{{ $tag_name }}</span>
        </a>
    @endif
@endforeach
