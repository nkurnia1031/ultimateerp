@extends('layouts.app')

@section('title', 'Template Kop Surat Kontrak')

@section('content')
    @include('crm::layouts.nav')

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
