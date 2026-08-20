@if(isset($moduleAssets) && is_array($moduleAssets))
    {{-- Render CSS files --}}
    @if(!empty($moduleAssets['css']))
        @foreach($moduleAssets['css'] as $css)
            <link rel="stylesheet" href="{{ Module::asset($css['path']) }}">
        @endforeach
    @endif
    
    {{-- Render JS files --}}
    @if(!empty($moduleAssets['js']))
        @foreach($moduleAssets['js'] as $js)
            <script src="{{ versioned_asset_url(Module::asset($js['path']), $asset_v ?? null) }}"></script>
        @endforeach
    @endif
@endif
