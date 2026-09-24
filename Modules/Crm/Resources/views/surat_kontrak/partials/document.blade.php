@php
    $components = $rendered_components ?? [];
    $body_components = [];
    $in_body = false;
    foreach ($components as $component) {
        if (($component['type'] ?? '') === 'document_title') {
            $in_body = true;
        }
        if ($in_body) {
            $body_components[] = $component;
        }
    }
    $context = app(\Modules\Crm\Services\SuratKontrakTemplateService::class)->context($business, $surat_kontrak);
    $esc = function ($key, $fallback = '-') use ($context) { return e($context[$key] ?? $fallback); };
@endphp

<style>
    .surat-kontrak-document { box-sizing: border-box; background: #fff; color: #111; font-family: Arial, sans-serif; padding: 28px 38px; line-height: 1.45; }
    .surat-kontrak-letterhead { display: grid; grid-template-columns: minmax(72px, 1fr) minmax(0, 2.8fr) minmax(72px, 1fr); grid-auto-rows: auto; column-gap: 8px; align-items: stretch; border-bottom: 0; }
    .surat-kontrak-letterhead .skt-header-component { box-sizing: border-box; padding: 0 6px; }
    .surat-kontrak-letterhead .skt-header-text { line-height: 1.15; white-space: nowrap; }
    .surat-kontrak-letterhead .skt-header-logo { display: flex; align-items: center; }
    .surat-kontrak-letterhead img { max-width: 100%; object-fit: contain; }
    .surat-kontrak-document-title { margin: 4px 0 0; }
    .surat-kontrak-document table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .surat-kontrak-document td, .surat-kontrak-document th { border: 1px solid #333; padding: 6px; vertical-align: top; }
    .surat-kontrak-document .skt-signature td { border: 0; padding-top: 4px; }
    .surat-kontrak-document .skt-rich-text ol, .surat-kontrak-document .skt-rich-text ul { padding-left: 24px; }
    .surat-kontrak-document .skt-rich-text p { margin: 0 0 8px; }
    .surat-kontrak-document .skt-heading { margin: 0 0 5px; }
    .surat-kontrak-letterhead .skt-header-component hr { border-top-width: 2px !important; }
    @media print {
        html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
        .surat-kontrak-document { width: 100%; min-width: 0; min-height: auto; margin: 0 !important; padding: 12mm 15mm; line-height: 1.45; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .surat-kontrak-letterhead { grid-template-columns: minmax(72px, 1fr) minmax(0, 2.8fr) minmax(72px, 1fr); grid-auto-rows: auto; column-gap: 8px; }
        .surat-kontrak-letterhead .skt-header-component { padding: 0 6px; }
        .surat-kontrak-document table { page-break-inside: avoid; }
        .surat-kontrak-body .skt-body-component { page-break-inside: avoid; }
    }
    @media (max-width: 600px) { .surat-kontrak-document { padding: 18px 14px; font-size: 12px; } .surat-kontrak-letterhead { grid-template-columns: minmax(60px, 1fr) minmax(0, 2.8fr) minmax(60px, 1fr); column-gap: 4px; } }
</style>

<div class="surat-kontrak-document" id="print_area">
    @include('crm::surat_kontrak.partials.letterhead')
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
