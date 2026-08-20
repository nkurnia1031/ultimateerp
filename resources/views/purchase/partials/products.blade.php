@php
    $items = collect(explode('||', (string) $products))
        ->map(static fn ($item) => trim($item))
        ->filter()
        ->values();
@endphp

@if ($items->isEmpty())
    <span class="text-muted">&mdash;</span>
@else
    <div class="purchase-product-list" title="{{ $items->implode(', ') }}">
        @foreach ($items->take(3) as $item)
            <div class="purchase-product-list__item">
                <i class="fa fa-cube text-muted" aria-hidden="true"></i>
                <span>{{ $item }}</span>
            </div>
        @endforeach

        @if ($items->count() > 3)
            <div class="purchase-product-list__more">
                +{{ $items->count() - 3 }} barang lainnya
            </div>
        @endif
    </div>
@endif
