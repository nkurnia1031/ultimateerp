@php
    $components = $rendered_components ?? [];
    $header_components = [];
    $in_body = false;
    foreach ($components as $component) {
        if (($component['type'] ?? '') === 'document_title') {
            $in_body = true;
        }
        if (! $in_body) {
            $header_components[] = $component;
        }
    }
    $show_letterhead_image = ($template->letterhead_mode ?? 'components') === 'image'
        && ! empty($template->letterhead_image)
        && file_exists(public_path('uploads/surat_kontrak_logos/' . $template->letterhead_image));
    $image_url = $show_letterhead_image
        ? asset('uploads/surat_kontrak_logos/' . rawurlencode($template->letterhead_image))
        : null;
@endphp

@if ($show_letterhead_image)
    <div class="text-center" style="margin-bottom: 18px;"><img src="{{ $image_url }}" alt="Kop surat bisnis" style="max-width: 100%; max-height: 180px;"></div>
@else
    <div class="surat-kontrak-letterhead">
        @foreach ($header_components as $component)
            @continue(empty($component['visible']))
            @php $type = $component['type']; $attributes = $component['attributes']; @endphp
            @php
                $layout_column = in_array(($attributes['layout_column'] ?? ''), ['left', 'center', 'right', 'full'], true)
                    ? $attributes['layout_column']
                    : ($type === 'logo' ? (($attributes['source'] ?? '') === 'logo_2' ? 'right' : 'left') : ($type === 'separator' ? 'full' : 'center'));
                $grid_column = $layout_column === 'left' ? '1' : ($layout_column === 'right' ? '3' : ($layout_column === 'full' ? '1 / -1' : '2'));
                $layout_style = 'grid-column: ' . $grid_column . ';';
                if ($type === 'logo') {
                    $justify_content = ($attributes['align'] ?? 'center') === 'right' ? 'flex-end' : (($attributes['align'] ?? 'center') === 'left' ? 'flex-start' : 'center');
                    $layout_style .= ' grid-row: span ' . (int) ($attributes['row_span'] ?? 3) . '; width: 100%; align-self: stretch; justify-content: ' . $justify_content . ';';
                } elseif ($layout_column !== 'full') {
                    $layout_style .= ' width: 100%; justify-self: center;';
                } else {
                    $layout_style .= ' width: 100%; justify-self: stretch;';
                }
            @endphp
            <div class="skt-header-component{{ $type === 'logo' ? ' skt-header-logo' : '' }}{{ $type === 'text' ? ' skt-header-text' : '' }}" style="{{ $component['style'] }}{{ $layout_style }}">
                @if ($type === 'logo' && ! empty($component['asset_url']))
                    <img src="{{ $component['asset_url'] }}" alt="{{ e($component['label']) }}" style="max-height: {{ (int) $attributes['max_height'] }}px;">
                @elseif ($type === 'text')
                    {!! nl2br(e($component['text_value'])) !!}
                @elseif ($type === 'rich_text')
                    <div class="skt-rich-text">{!! $component['html_value'] !!}</div>
                @elseif ($type === 'contact_info')
                    {!! nl2br(e($component['text_value'])) !!}
                @elseif ($type === 'separator')
                    <hr style="border: 0; border-top: 2px solid {{ e($attributes['color']) }}; margin: 0;">
                @endif
            </div>
        @endforeach
    </div>
@endif
