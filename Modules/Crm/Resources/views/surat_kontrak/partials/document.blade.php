@php
    $components = $rendered_components ?? [];
    $header_components = [];
    $body_components = [];
    $in_body = false;
    foreach ($components as $component) {
        if (($component['type'] ?? '') === 'document_title') {
            $in_body = true;
        }
        if ($in_body) {
            $body_components[] = $component;
        } else {
            $header_components[] = $component;
        }
    }
    $show_letterhead_image = ($template->letterhead_mode ?? 'components') === 'image' && !empty($template->letterhead_image) && file_exists(public_path('uploads/surat_kontrak_logos/' . $template->letterhead_image));
    $image_url = $show_letterhead_image ? asset('uploads/surat_kontrak_logos/' . rawurlencode($template->letterhead_image)) : null;
    $context = app(\Modules\Crm\Services\SuratKontrakTemplateService::class)->context($business, $surat_kontrak);
    $esc = function ($key, $fallback = '-') use ($context) { return e($context[$key] ?? $fallback); };
@endphp

<style>
    .surat-kontrak-document { background: #fff; color: #111; font-family: Arial, sans-serif; padding: 28px 38px; line-height: 1.45; }
    .surat-kontrak-letterhead { display: grid; grid-template-columns: minmax(72px, 1fr) minmax(0, 2.8fr) minmax(72px, 1fr); grid-auto-rows: minmax(28px, auto); column-gap: 8px; align-items: stretch; border-bottom: 0; }
    .surat-kontrak-letterhead .skt-header-component { box-sizing: border-box; padding: 3px 6px; }
    .surat-kontrak-letterhead .skt-header-logo { display: flex; align-items: center; }
    .surat-kontrak-letterhead img { max-width: 100%; object-fit: contain; }
    .surat-kontrak-document-title { margin: 4px 0 0; }
    .surat-kontrak-document table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .surat-kontrak-document td, .surat-kontrak-document th { border: 1px solid #333; padding: 6px; vertical-align: top; }
    .surat-kontrak-document .skt-signature td { border: 0; padding-top: 4px; }
    .surat-kontrak-document .skt-rich-text ol, .surat-kontrak-document .skt-rich-text ul { padding-left: 24px; }
    .surat-kontrak-document .skt-rich-text p { margin: 0 0 8px; }
    .surat-kontrak-document .skt-heading { margin: 0 0 5px; }
    @media (max-width: 600px) { .surat-kontrak-document { padding: 18px 14px; font-size: 12px; } .surat-kontrak-letterhead { grid-template-columns: minmax(60px, 1fr) minmax(0, 2.8fr) minmax(60px, 1fr); column-gap: 4px; } }
</style>

<div class="surat-kontrak-document" id="print_area">
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
                <div class="skt-header-component{{ $type === 'logo' ? ' skt-header-logo' : '' }}" style="{{ $component['style'] }}{{ $layout_style }}">
                    @if ($type === 'logo' && !empty($component['asset_url']))
                        <img src="{{ $component['asset_url'] }}" alt="{{ e($component['label']) }}" style="max-height: {{ (int) $attributes['max_height'] }}px;">
                    @elseif ($type === 'text')
                        {!! nl2br(e($component['text_value'])) !!}
                    @elseif ($type === 'rich_text')
                        <div class="skt-rich-text">{!! $component['html_value'] !!}</div>
                    @elseif ($type === 'contact_info')
                        {!! nl2br(e($component['text_value'])) !!}
                    @elseif ($type === 'separator')
                        <hr style="border: 0; border-top: 1px solid {{ e($attributes['color']) }}; margin: 0;">
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="surat-kontrak-body">
        @foreach ($body_components as $component)
            @continue(empty($component['visible']))
            @php $type = $component['type']; $attributes = $component['attributes']; @endphp
            <div class="skt-body-component" style="{{ $component['style'] }}">
                @if ($type === 'text' || $type === 'document_title')
                    <div class="surat-kontrak-document-title">{!! nl2br(e($component['text_value'])) !!}</div>
                @elseif ($type === 'rich_text')
                    @if (!empty($attributes['heading']))<p class="skt-heading"><strong>{{ $attributes['heading'] }}</strong></p>@endif
                    <div class="skt-rich-text">{!! $component['html_value'] !!}</div>
                @elseif ($type === 'document_number')
                    <div>{{ e($attributes['label']) }}: {{ $esc('contract.nomor') }}</div>
                @elseif ($type === 'parties')
                    <table><tr>
                        <td width="50%"><strong>PIHAK PERTAMA</strong><br>{{ $esc('party1.name') }}<br>{{ $esc('party1.title') }}<br>Alamat: {{ $esc('party1.address') }}</td>
                        <td width="50%"><strong>PIHAK KEDUA</strong><br>{{ $esc('party2.name') }}<br>Jabatan: {{ $esc('party2.title') }}<br>Alamat: {{ $esc('party2.address') }}</td>
                    </tr></table>
                @elseif ($type === 'job_summary')
                    <table><tr><td><strong>Pekerjaan</strong><br>{{ $esc('contract.pekerjaan') }}</td></tr></table>
                @elseif ($type === 'details_table')
                    @if (!empty($attributes['heading']))<p class="skt-heading"><strong>{{ $attributes['heading'] }}</strong></p>@endif
                    <table>
                        <tr><td width="30%">Kapal MV</td><td>{{ $esc('contract.kapal_mv') }}</td></tr>
                        <tr><td>Tonase</td><td>{{ $esc('contract.tonase') }}</td></tr>
                        <tr><td>Lokasi Muat</td><td>{{ $esc('contract.lokasi_muat') }}</td></tr>
                        <tr><td>Lokasi Bongkar</td><td>{{ $esc('contract.lokasi_bongkar') }}</td></tr>
                        <tr><td>Jangka Waktu</td><td>{{ $esc('contract.jangka_waktu') }}</td></tr>
                        <tr><td>Mulai Kerja</td><td>{{ $esc('contract.period') }}</td></tr>
                    </table>
                @elseif ($type === 'signatures')
                    <table class="skt-signature"><tr>
                        <td width="50%"><strong>PIHAK PERTAMA</strong><br>{{ $esc('party1.name') }}<br>{{ $esc('party1.title') }}<br><br><br>(___________________)</td>
                        <td width="50%"><strong>PIHAK KEDUA</strong><br>{{ $esc('party2.name') }}<br>{{ $esc('party2.title') }}<br><br><br>(___________________)</td>
                    </tr></table>
                @endif
            </div>
        @endforeach
    </div>
</div>
