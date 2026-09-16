@extends('layouts.app')

@section('title', 'Template Kop Surat Kontrak')

@section('content')
    @include('crm::layouts.nav')

    <style>
        .skt-builtin-library { margin-bottom: 20px; }
        .skt-builtin-card { height: 100%; border: 1px solid #d8e1e8; border-radius: 6px; background: #fff; box-shadow: 0 2px 8px rgba(25, 55, 80, .06); }
        .skt-builtin-card .box-header { min-height: 76px; }
        .skt-builtin-card .box-title { font-weight: 600; }
        .skt-builtin-preview { display: grid; grid-template-columns: 76px minmax(0, 1fr); gap: 6px 10px; align-items: center; min-height: 134px; padding: 12px; border: 1px solid #e5ebef; background: #fff; overflow: hidden; }
        .skt-builtin-preview-logo { display: flex; align-items: center; justify-content: center; height: 88px; }
        .skt-builtin-preview-logo img { max-width: 72px; max-height: 88px; object-fit: contain; }
        .skt-builtin-preview-head { display: flex; min-width: 0; min-height: 88px; flex-direction: column; justify-content: center; }
        .skt-builtin-preview-title { color: #e85d65; font: 400 20px/1.08 Georgia, serif; overflow-wrap: anywhere; }
        .skt-builtin-preview-subtitle { margin-top: 5px; color: #5d78ab; font: 400 13px/1.1 Georgia, serif; overflow-wrap: anywhere; }
        .skt-builtin-preview-line, .skt-builtin-preview-line-bottom, .skt-builtin-preview-contact { grid-column: 1 / -1; width: 100%; }
        .skt-builtin-preview-line { border-top: 2px solid #111; }
        .skt-builtin-preview-contact { color: #111; font: 700 8px/1.2 Arial, sans-serif; overflow-wrap: anywhere; text-align: center; }
        .skt-builtin-preview-line-bottom { border-top: 2px solid #111; }
        .skt-builtin-meta { min-height: 48px; margin-top: 12px; color: #66788a; font-size: 13px; line-height: 1.5; }
        .skt-builtin-actions { margin-top: 14px; }
        @media (max-width: 600px) {
            .skt-builtin-preview { grid-template-columns: 58px minmax(0, 1fr); min-height: 126px; padding: 8px; }
            .skt-builtin-preview-logo img { max-width: 54px; }
            .skt-builtin-preview-title { font-size: 15px; }
            .skt-builtin-preview-subtitle { font-size: 10px; }
            .skt-builtin-preview-contact { font-size: 6px; }
        }
    </style>

    <section class="content-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1>Template Kop Surat Kontrak</h1>
                <p class="text-muted mb-0">Atur kop surat untuk bisnis yang sedang aktif. Template ini hanya digunakan oleh bisnis tersebut.</p>
            </div>
            <a href="{{ route('crm.surat-kontrak-templates.create') }}" class="btn btn-primary">
                <i class="fa fa-plus"></i> Buat Template
            </a>
        </div>
    </section>

    <section class="content">
        @if (session('status'))
            <div class="alert alert-{{ !empty(session('status.success')) ? 'success' : 'danger' }} no-print">
                {{ session('status.msg') }}
            </div>
        @endif
        @if ($errors->has('template'))
            <div class="alert alert-danger no-print">{{ $errors->first('template') }}</div>
        @endif

        <div class="box box-solid skt-builtin-library no-print">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-book"></i> Pustaka template bawaan</h3>
            </div>
            <div class="box-body">
                <p class="text-muted" style="margin-bottom: 16px;">
                    Pilih kop siap pakai berikut untuk menyalinnya ke bisnis aktif. Logo dan tulisan kop ini adalah bawaan tetap,
                    bukan diambil dari identitas bisnis, dan masih dapat diedit setelah disalin.
                </p>
                <div class="row">
                    @foreach ($builtins as $key => $builtin)
                        <div class="col-md-6" style="margin-bottom: 16px;">
                            <div class="skt-builtin-card">
                                <div class="box-header with-border">
                                    <h4 class="box-title">{{ $builtin['name'] }}</h4>
                                </div>
                                <div class="box-body">
                                    <div class="skt-builtin-preview" aria-label="Pratinjau {{ $builtin['name'] }}">
                                        <div class="skt-builtin-preview-logo">
                                            <img src="{{ asset($builtin['logo_asset']) }}" alt="Logo bawaan template">
                                        </div>
                                        <div class="skt-builtin-preview-head">
                                            <div class="skt-builtin-preview-title">{{ $builtin['title'] }}</div>
                                            <div class="skt-builtin-preview-subtitle">{{ $builtin['subtitle'] }}</div>
                                        </div>
                                        <div class="skt-builtin-preview-line"></div>
                                        <div class="skt-builtin-preview-contact">{{ $builtin['contact'] }}</div>
                                        <div class="skt-builtin-preview-line-bottom"></div>
                                    </div>
                                    <div class="skt-builtin-meta">{{ $builtin['description'] }}</div>
                                    <div class="skt-builtin-actions">
                                        <form method="post" action="{{ route('crm.surat-kontrak-templates.builtins.copy', $key) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-info" onclick="return confirm('Salin template ini ke bisnis aktif? Template baru akan dibuat nonaktif agar dapat diperiksa terlebih dahulu.')">
                                                <i class="fa fa-copy"></i> Salin sebagai template baru
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">Template bisnis aktif</h3>
            </div>
            <div class="box-body">
                @if ($templates->isEmpty())
                    <div class="alert alert-info mb-0">
                        Belum ada template khusus. Surat kontrak akan memakai tampilan default dengan identitas bisnis aktif.
                        <a href="{{ route('crm.surat-kontrak-templates.create') }}">Buat template pertama</a>.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="surat_kontrak_templates_table">
                            <thead>
                                <tr>
                                    <th>Nama template</th>
                                    <th>Mode kop</th>
                                    <th>Status</th>
                                    <th>Diperbarui</th>
                                    <th class="text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($templates as $template)
                                    <tr>
                                        <td>
                                            <strong>{{ $template->name }}</strong>
                                            @if ($template->is_default)
                                                <span class="label label-success ml-1">Default</span>
                                            @endif
                                        </td>
                                        <td>{{ $template->letterhead_mode === 'image' ? 'Gambar kop penuh' : 'Komponen' }}</td>
                                        <td>
                                            <span class="label label-{{ $template->is_active ? 'success' : 'default' }}">
                                                {{ $template->is_active ? 'Aktif' : 'Nonaktif' }}
                                            </span>
                                        </td>
                                        <td>{{ optional($template->updated_at)->format('d/m/Y H:i') }}</td>
                                        <td class="text-right" style="white-space: nowrap;">
                                            <a href="{{ route('crm.surat-kontrak-templates.preview', $template->id) }}" class="btn btn-default btn-sm" target="_blank">
                                                <i class="fa fa-eye"></i> Preview
                                            </a>
                                            <a href="{{ route('crm.surat-kontrak-templates.edit', $template->id) }}" class="btn btn-primary btn-sm">
                                                <i class="fa fa-edit"></i> Edit
                                            </a>
                                            @if (!$template->is_default)
                                                <form method="post" action="{{ route('crm.surat-kontrak-templates.default', $template->id) }}" style="display:inline-block;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Jadikan template ini sebagai default?')">
                                                        Jadikan default
                                                    </button>
                                                </form>
                                                <form method="post" action="{{ route('crm.surat-kontrak-templates.destroy', $template->id) }}" style="display:inline-block;">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Hapus template ini?')">
                                                        <i class="fa fa-trash"></i> Hapus
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
