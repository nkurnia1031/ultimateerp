@extends('layouts.app')

@section('title', 'Preview Kop Surat Kontrak')

@section('content')
    <div class="container-fluid no-print" style="padding: 15px 20px;">
        <a href="{{ route('crm.surat-kontrak-templates.index') }}" class="btn btn-default"><i class="fa fa-arrow-left"></i> Kembali</a>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa fa-print"></i> Cetak Preview</button>
        <span class="text-muted ml-1">Contoh data, bukan transaksi pelanggan.</span>
    </div>

    <div class="surat-kontrak-preview-page">
        @include('crm::surat_kontrak.partials.document', [
            'surat_kontrak' => $sample,
            'rendered_components' => $rendered_components,
            'template' => $template,
            'business' => $business,
            'is_preview' => true,
        ])
    </div>
@endsection

@section('css')
    <style>
        @media print { .no-print { display: none !important; } body { background: #fff !important; } .surat-kontrak-preview-page { padding: 0 !important; } }
        .surat-kontrak-preview-page { background: #e9eef2; padding: 20px; }
        .surat-kontrak-preview-page .surat-kontrak-document { max-width: 900px; margin: 0 auto; }
    </style>
@endsection
