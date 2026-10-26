@php
    $items = collect($products)
        ->filter(static fn ($item) => is_array($item) && trim((string) ($item['name'] ?? '')) !== '')
        ->values();
@endphp

@if ($items->isEmpty())
    <span class="text-muted">&mdash;</span>
@else
    <div class="purchase-product-list" title="{{ $items->map(static fn ($item) => $item['name'].' × '.$item['quantity'].(! empty($item['unit']) ? ' '.$item['unit'] : ''))->implode(', ') }}">
        @foreach ($items as $item)
            <div class="purchase-product-list__item">
                <i class="fa fa-cube text-muted" aria-hidden="true"></i>
                <span>
                    {{ $item['name'] }}
                    <small class="text-muted">× {{ @format_quantity($item['quantity']) }} {{ $item['unit'] }}</small>
                </span>
            </div>
        @endforeach

    </div>
@endif
