@extends('layouts.app')

@php
    $service = app(\Modules\Crm\Services\SuratKontrakTemplateService::class);
    $is_edit = $template->exists;
    $default_components = $template->components ?: $service->defaultComponents();
    $old_components = old('components');
    $initial_components = $old_components ? (json_decode($old_components, true) ?: $default_components) : $default_components;
    $form_url = $is_edit ? route('crm.surat-kontrak-templates.update', $template->id) : route('crm.surat-kontrak-templates.store');
    $location = $business->locations->first();
    $business_address = $location ? collect([
        $location->landmark,
        $location->city,
        $location->state,
        $location->zip_code,
        $location->country,
    ])->filter(function ($value) { return trim((string) $value) !== ''; })->implode(', ') : '';
    $business_contact = $location ? collect([
        $location->mobile ? 'Tel. ' . $location->mobile : null,
        $location->alternate_number ? 'Tel. ' . $location->alternate_number : null,
        $location->email ? 'Email: ' . $location->email : null,
        $location->website,
    ])->filter()->implode(' | ') : '';
    $logo_1_url = !empty($template->logo_1) && file_exists(public_path('uploads/surat_kontrak_logos/' . $template->logo_1))
        ? asset('uploads/surat_kontrak_logos/' . rawurlencode($template->logo_1))
        : null;
    $logo_2_url = !empty($template->logo_2) && file_exists(public_path('uploads/surat_kontrak_logos/' . $template->logo_2))
        ? asset('uploads/surat_kontrak_logos/' . rawurlencode($template->logo_2))
        : null;
    $letterhead_image_url = !empty($template->letterhead_image) && file_exists(public_path('uploads/surat_kontrak_logos/' . $template->letterhead_image))
        ? asset('uploads/surat_kontrak_logos/' . rawurlencode($template->letterhead_image))
        : null;
@endphp

@section('title', $is_edit ? 'Edit Template Kop Surat' : 'Buat Template Kop Surat')

@section('content')
    @include('crm::layouts.nav')

    <section class="content-header no-print skt-page-header">
        <div>
            <span class="skt-eyebrow">CRM / PENGATURAN SURAT KONTRAK</span>
            <h1>{{ $is_edit ? 'Edit Template Kop Surat' : 'Buat Template Kop Surat' }}</h1>
            <p class="text-muted mb-0">Pilih komponen langsung pada preview. Pengaturan komponen terpilih muncul tepat di bawahnya.</p>
        </div>
        <a href="{{ route('crm.surat-kontrak-templates.index') }}" class="btn btn-default skt-button skt-button-neutral">
            <i class="fa fa-arrow-left" aria-hidden="true"></i> Kembali
        </a>
    </section>

    <section class="content skt-template-editor-page">
        @if ($errors->any())
            <div class="alert alert-danger no-print" role="alert">
                <strong>Template belum tersimpan.</strong>
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {!! Form::open(['url' => $form_url, 'method' => $is_edit ? 'put' : 'post', 'files' => true, 'id' => 'surat_kontrak_template_form']) !!}
            <input type="hidden" name="components" id="components_payload" value="">

            <div class="row skt-editor-topline">
                <div class="col-lg-4 col-md-5 no-print skt-editor-sidebar">
                    <div class="skt-control-dock">
                        <div class="skt-control-dock-heading">
                            <div>
                                <span class="skt-section-kicker">KONTROL TEMPLATE</span>
                                <h2>Pengaturan kop surat</h2>
                            </div>
                            <i class="fa fa-sliders" aria-hidden="true"></i>
                        </div>
                        <p>Pilih pengaturan yang ingin dibuka. Preview di sebelah kanan akan tetap terlihat.</p>
                        <div class="skt-control-dock-actions">
                            <button type="button" class="btn btn-default skt-button skt-button-neutral" id="open_identity_modal"><i class="fa fa-id-card-o" aria-hidden="true"></i> Identitas template</button>
                            <button type="button" class="btn btn-primary skt-button skt-button-primary" id="open_add_modal"><i class="fa fa-plus" aria-hidden="true"></i> Tambah komponen</button>
                        </div>
                    </div>

                    <div class="box box-solid skt-settings-card skt-modal-source" id="template_identity_card">
                        <div class="box-header with-border">
                            <span class="skt-section-kicker">01</span>
                            <h3 class="box-title">Identitas template</h3>
                        </div>
                        <div class="box-body">
                            <div class="form-group">
                                <label for="name">Nama template <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" class="form-control" required maxlength="190" value="{{ old('name', $template->name) }}">
                                <p class="help-block">Nama ini hanya untuk membedakan template di daftar.</p>
                            </div>
                            <div class="form-group">
                                <label for="letterhead_mode">Cara membuat kop</label>
                                <select name="letterhead_mode" id="letterhead_mode" class="form-control">
                                    <option value="components" @selected(old('letterhead_mode', $template->letterhead_mode ?: 'components') === 'components')>Susun dari komponen</option>
                                    <option value="image" @selected(old('letterhead_mode', $template->letterhead_mode ?: 'components') === 'image')>Gunakan gambar kop penuh</option>
                                </select>
                                <p class="help-block">Gunakan komponen untuk kop yang mudah dirawat. Gambar penuh hanya untuk kebutuhan khusus.</p>
                            </div>
                            <div class="form-group letterhead-image-fields">
                                <label for="letterhead_image">Gambar kop penuh <span class="text-muted">(opsional)</span></label>
                                <input type="file" name="letterhead_image" id="letterhead_image" class="skt-file-input" accept="image/jpeg,image/png,image/webp">
                                <label for="letterhead_image" class="skt-file-picker"><i class="fa fa-upload" aria-hidden="true"></i> Pilih gambar kop</label>
                                <p class="help-block file-name-hint" data-for="letterhead_image">{{ $template->letterhead_image ? 'File saat ini: ' . $template->letterhead_image : 'JPG, PNG, atau WEBP; maksimal 4 MB.' }}</p>
                                @if (!empty($template->letterhead_image))
                                    <label class="font-weight-normal"><input type="checkbox" name="remove_letterhead_image" value="1"> Hapus gambar kop</label>
                                @endif
                            </div>
                            <div class="skt-check-row">
                                <input type="hidden" name="is_active" value="0">
                                <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->exists ? $template->is_active : true))> <span>Template aktif</span></label>
                                <label><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $template->exists ? $template->is_default : false))> <span>Jadikan default</span></label>
                            </div>
                        </div>
                    </div>

                    <div class="box box-solid skt-add-component-card skt-modal-source" id="component_add_card">
                        <div class="box-header with-border">
                            <span class="skt-section-kicker">02</span>
                            <h3 class="box-title">Tambah komponen</h3>
                        </div>
                        <div class="box-body">
                            <p class="skt-help-copy">Tambahkan elemen kop atau isi surat dari daftar aman berikut.</p>
                            <label class="sr-only" for="component_to_add">Komponen yang akan ditambahkan</label>
                            <select id="component_to_add" class="form-control">
                                @foreach ($component_definitions as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="button" class="btn btn-primary btn-block skt-button skt-button-primary" id="add_component">
                                <i class="fa fa-plus" aria-hidden="true"></i> Tambah ke preview
                            </button>
                            <div class="skt-quick-actions">
                                <button type="button" class="btn btn-default skt-button skt-button-neutral" id="open_order_modal">
                                    <i class="fa fa-list-ol" aria-hidden="true"></i> Susun komponen
                                </button>
                                <button type="button" class="btn btn-default skt-button skt-button-neutral" id="open_body_modal">
                                    <i class="fa fa-align-left" aria-hidden="true"></i> Edit isi ketentuan
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="skt-editor-tip no-print">
                        <i class="fa fa-lightbulb-o" aria-hidden="true"></i>
                        <div><strong>Tips cepat</strong><br>Hover komponen pada kertas, lalu pilih <b>Edit</b>. Komponen terpilih akan diberi garis biru.</div>
                    </div>
                </div>

                <div class="col-lg-8 col-md-7 skt-preview-column">
                    <div class="box box-solid skt-preview-card">
                        <div class="box-header with-border skt-preview-header">
                            <div>
                                <span class="skt-section-kicker">03</span>
                                <h3 class="box-title">Preview kop surat</h3>
                            </div>
                            <span class="skt-live-badge"><span></span> Live preview</span>
                        </div>
                        <div class="box-body">
                            <div class="skt-preview-note" role="status">
                                <i class="fa fa-mouse-pointer" aria-hidden="true"></i>
                                <span>Perubahan terlihat langsung. Klik komponen, atau pilih dengan keyboard lalu tekan Enter/Space.</span>
                            </div>
                            <div class="skt-preview-viewport">
                                <div id="skt_live_preview" class="skt-preview-paper" tabindex="0" aria-label="Preview kop surat interaktif"></div>
                            </div>
                        </div>
                    </div>

                    <div class="box box-solid skt-component-editor" id="skt_component_editor">
                        <div class="box-header with-border skt-preview-header">
                            <div>
                                <span class="skt-section-kicker">04</span>
                                <h3 class="box-title">Edit komponen</h3>
                            </div>
                            <span class="skt-selected-label" id="selected_component_label">Belum ada komponen dipilih</span>
                        </div>
                        <div class="box-body">
                            <div id="component_editor_empty" class="skt-empty-editor">
                                <i class="fa fa-hand-pointer-o" aria-hidden="true"></i>
                                <strong>Pilih komponen pada preview</strong>
                                <span>Panel pengaturan komponen akan muncul di sini.</span>
                            </div>
                            <div id="component_editor_fields" class="skt-editor-fields" hidden>
                                <div class="skt-selected-banner"><i class="fa fa-pencil" aria-hidden="true"></i> Anda sedang mengedit komponen yang diberi garis biru pada preview.</div>
                                <div class="row">
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label for="component_label">Nama komponen</label>
                                            <input type="text" id="component_label" class="form-control" maxlength="120">
                                        </div>
                                    </div>
                                    <div class="col-sm-3">
                                        <div class="form-group">
                                            <label for="component_type">Jenis komponen</label>
                                            <select id="component_type" class="form-control">
                                                @foreach ($component_definitions as $key => $label)
                                                    <option value="{{ $key }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-sm-3">
                                        <div class="form-group">
                                            <label for="component_visible">Tampilan</label>
                                            <select id="component_visible" class="form-control">
                                                <option value="1">Tampilkan</option>
                                                <option value="0">Sembunyikan</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-3">
                                        <div class="form-group">
                                            <label for="component_align">Perataan</label>
                                            <select id="component_align" class="form-control">
                                                <option value="left">Kiri</option>
                                                <option value="center">Tengah</option>
                                                <option value="right">Kanan</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-sm-3">
                                        <div class="form-group">
                                            <label for="component_width">Lebar (%)</label>
                                            <input type="number" id="component_width" class="form-control" min="10" max="100">
                                        </div>
                                    </div>
                                    <div class="col-sm-3">
                                        <div class="form-group">
                                            <label for="component_font_size">Ukuran font</label>
                                            <input type="number" id="component_font_size" class="form-control" min="8" max="48">
                                        </div>
                                    </div>
                                    <div class="col-sm-3">
                                        <div class="form-group">
                                            <label for="component_font_weight">Ketebalan</label>
                                            <select id="component_font_weight" class="form-control">
                                                <option value="400">Normal</option>
                                                <option value="500">Sedang</option>
                                                <option value="600">Semi tebal</option>
                                                <option value="700">Tebal</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-sm-3 component-field-font-family" hidden>
                                        <div class="form-group">
                                            <label for="component_font_family">Jenis huruf</label>
                                            <select id="component_font_family" class="form-control">
                                                <option value="Arial">Arial</option>
                                                <option value="Georgia">Georgia</option>
                                                <option value="Times New Roman">Times New Roman</option>
                                                <option value="Verdana">Verdana</option>
                                            </select>
                                            <p class="help-block">Georgia atau Times New Roman cocok untuk kop bergaya formal.</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-8 component-field-text">
                                        <div class="form-group">
                                            <label for="component_text">Teks / placeholder</label>
                                            <textarea id="component_text" class="form-control" rows="2" placeholder="Gunakan placeholder dari daftar yang tersedia"></textarea>
                                            <p class="help-block" id="component_text_help">Contoh: nama bisnis dapat memakai placeholder business.name.</p>
                                        </div>
                                    </div>
                                    <div class="col-sm-8 component-field-logo-upload" hidden>
                                        <div class="skt-logo-upload-grid">
                                            <div class="form-group skt-logo-slot skt-logo-slot-1" hidden>
                                                <label for="component_logo_1_file">Upload Logo 1</label>
                                                <input type="file" name="logo_1" id="component_logo_1_file" class="skt-file-input" accept="image/jpeg,image/png,image/webp">
                                                <label for="component_logo_1_file" class="skt-file-picker"><i class="fa fa-upload" aria-hidden="true"></i> Pilih Logo 1</label>
                                                <p class="help-block file-name-hint" data-for="component_logo_1_file">{{ $template->logo_1 ? 'File saat ini: ' . $template->logo_1 : 'Pilih JPG, PNG, atau WEBP; maksimal 2 MB.' }}</p>
                                                @if (!empty($template->logo_1))
                                                    <label class="font-weight-normal"><input type="checkbox" name="remove_logo_1" value="1"> Hapus Logo 1</label>
                                                @endif
                                            </div>
                                            <div class="form-group skt-logo-slot skt-logo-slot-2" hidden>
                                                <label for="component_logo_2_file">Upload Logo 2</label>
                                                <input type="file" name="logo_2" id="component_logo_2_file" class="skt-file-input" accept="image/jpeg,image/png,image/webp">
                                                <label for="component_logo_2_file" class="skt-file-picker"><i class="fa fa-upload" aria-hidden="true"></i> Pilih Logo 2</label>
                                                <p class="help-block file-name-hint" data-for="component_logo_2_file">{{ $template->logo_2 ? 'File saat ini: ' . $template->logo_2 : 'Pilih JPG, PNG, atau WEBP; maksimal 2 MB.' }}</p>
                                                @if (!empty($template->logo_2))
                                                    <label class="font-weight-normal"><input type="checkbox" name="remove_logo_2" value="1"> Hapus Logo 2</label>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-4 component-field-business-note" hidden>
                                        <div class="skt-inline-note"><i class="fa fa-info-circle" aria-hidden="true"></i> Upload langsung disediakan untuk logo yang sedang dipilih. Tidak perlu memilih sumber.</div>
                                    </div>
                                    <input type="hidden" id="component_source" value="logo_1">
                                    <div class="col-sm-4 component-field-heading" hidden>
                                        <div class="form-group">
                                            <label for="component_heading">Judul bagian</label>
                                            <input type="text" id="component_heading" class="form-control" maxlength="190">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-4 component-field-content-key" hidden>
                                        <div class="form-group">
                                            <label for="component_content_key">Isi teks kaya</label>
                                            <select id="component_content_key" class="form-control">
                                                <option value="body_html">Gunakan isi ketentuan di bawah</option>
                                                <option value="">Gunakan teks komponen</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-sm-4 component-field-color" hidden>
                                        <div class="form-group">
                                            <label for="component_color">Warna komponen</label>
                                            <div class="skt-color-controls">
                                                <input type="color" id="component_color" class="form-control skt-color-input" value="#333333" aria-label="Pilih warna komponen">
                                                <input type="text" id="component_color_hex" class="form-control skt-color-hex" value="#333333" maxlength="7" pattern="^#[0-9a-fA-F]{6}$" inputmode="text" autocomplete="off" aria-label="Kode warna hex" aria-describedby="component_color_help">
                                            </div>
                                            <p class="help-block" id="component_color_help">Pilih warna atau isi kode hex, misalnya <code>#B91C1C</code>. Berlaku untuk teks, judul, alamat, isi, dan warna garis.</p>
                                        </div>
                                    </div>
                                    <div class="col-sm-4 component-field-max-height" hidden>
                                        <div class="form-group">
                                            <label for="component_max_height">Tinggi logo (px)</label>
                                            <input type="number" id="component_max_height" class="form-control" min="20" max="240">
                                        </div>
                                    </div>
                                    <div class="col-sm-4 component-field-layout-column" hidden>
                                        <div class="form-group">
                                            <label for="component_layout_column">Kolom header</label>
                                            <select id="component_layout_column" class="form-control">
                                                <option value="left">Kiri</option>
                                                <option value="center">Tengah</option>
                                                <option value="right">Kanan</option>
                                                <option value="full">Lebar penuh</option>
                                            </select>
                                            <p class="help-block">Logo dapat ditempatkan di sisi tertentu; garis dan kontak dapat memakai lebar penuh.</p>
                                        </div>
                                    </div>
                                    <div class="col-sm-4 component-field-row-span" hidden>
                                        <div class="form-group">
                                            <label for="component_row_span">Rentang vertikal</label>
                                            <select id="component_row_span" class="form-control">
                                                <option value="1">1 baris</option>
                                                <option value="2">2 baris</option>
                                                <option value="3">3 baris</option>
                                                <option value="4">4 baris</option>
                                                <option value="5">5 baris</option>
                                                <option value="6">6 baris</option>
                                            </select>
                                            <p class="help-block">Contoh 3 baris: logo membentang dari judul, subjudul, hingga alamat.</p>
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <div class="form-group">
                                            <label for="component_margin_bottom">Jarak bawah (px)</label>
                                            <input type="number" id="component_margin_bottom" class="form-control" min="0" max="64">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="box box-solid skt-order-card skt-modal-source no-print" id="component_order_card">
                <div class="box-header with-border skt-preview-header">
                    <div>
                        <span class="skt-section-kicker">05</span>
                        <h3 class="box-title">Urutan dan tindakan komponen</h3>
                    </div>
                    <span class="text-muted">Tarik komponen pada preview, atau gunakan tombol naik/turun.</span>
                </div>
                <div class="box-body">
                    <div id="component_list" class="skt-component-list" aria-live="polite"></div>
                </div>
            </div>

            <div class="box box-solid skt-rich-text-card skt-modal-source no-print" id="body_html_card">
                <div class="box-header with-border skt-preview-header">
                    <div>
                        <span class="skt-section-kicker">06</span>
                        <h3 class="box-title">Isi ketentuan</h3>
                    </div>
                    <span class="text-muted">Dipakai oleh komponen Teks kaya yang memilih isi ketentuan.</span>
                </div>
                <div class="box-body">
                    <label for="body_html">Ketentuan pelaksanaan</label>
                    <textarea name="body_html" id="body_html" form="surat_kontrak_template_form" class="form-control" rows="10">{{ old('body_html', $template->body_html ?: $service->defaultBodyHtml()) }}</textarea>
                    <p class="help-block">Script dan atribut berbahaya akan dibuang saat disimpan.</p>
                </div>
            </div>

            <div class="skt-form-actions no-print">
                <a href="{{ route('crm.surat-kontrak-templates.index') }}" class="btn btn-default skt-button skt-button-neutral">Batal</a>
                <button type="submit" class="btn btn-primary skt-button skt-button-primary"><i class="fa fa-save" aria-hidden="true"></i> Simpan Template</button>
            </div>
            <div class="skt-modal" id="skt_identity_modal" hidden role="dialog" aria-modal="true" aria-labelledby="skt_identity_modal_title">
                <div class="skt-modal-backdrop" data-close-modal="skt_identity_modal"></div>
                <div class="skt-modal-dialog skt-modal-dialog-compact" role="document">
                    <div class="skt-modal-header">
                        <div>
                            <span class="skt-section-kicker">PENGATURAN</span>
                            <h2 id="skt_identity_modal_title">Identitas template</h2>
                        </div>
                        <button type="button" class="skt-modal-close" data-close-modal="skt_identity_modal" aria-label="Tutup identitas template" title="Tutup"><i class="fa fa-times" aria-hidden="true"></i></button>
                    </div>
                    <div class="skt-modal-body" id="skt_identity_modal_body"></div>
                </div>
            </div>

            <div class="skt-modal" id="skt_add_modal" hidden role="dialog" aria-modal="true" aria-labelledby="skt_add_modal_title">
                <div class="skt-modal-backdrop" data-close-modal="skt_add_modal"></div>
                <div class="skt-modal-dialog skt-modal-dialog-compact" role="document">
                    <div class="skt-modal-header">
                        <div>
                            <span class="skt-section-kicker">KOMPONEN</span>
                            <h2 id="skt_add_modal_title">Tambah komponen</h2>
                        </div>
                        <button type="button" class="skt-modal-close" data-close-modal="skt_add_modal" aria-label="Tutup tambah komponen" title="Tutup"><i class="fa fa-times" aria-hidden="true"></i></button>
                    </div>
                    <div class="skt-modal-body" id="skt_add_modal_body"></div>
                </div>
            </div>

            <div class="skt-modal" id="skt_component_modal" hidden role="dialog" aria-modal="true" aria-labelledby="skt_component_modal_title">
                <div class="skt-modal-backdrop" data-close-modal="skt_component_modal"></div>
                <div class="skt-modal-dialog" role="document">
                    <div class="skt-modal-header">
                        <div>
                            <span class="skt-section-kicker">PENGATURAN</span>
                            <h2 id="skt_component_modal_title">Susun komponen kop</h2>
                        </div>
                        <button type="button" class="skt-modal-close" data-close-modal="skt_component_modal" aria-label="Tutup susun komponen" title="Tutup"><i class="fa fa-times" aria-hidden="true"></i></button>
                    </div>
                    <div class="skt-modal-body" id="skt_component_modal_body"></div>
                </div>
            </div>

            <div class="skt-modal" id="skt_body_modal" hidden role="dialog" aria-modal="true" aria-labelledby="skt_body_modal_title">
                <div class="skt-modal-backdrop" data-close-modal="skt_body_modal"></div>
                <div class="skt-modal-dialog skt-modal-dialog-wide" role="document">
                    <div class="skt-modal-header">
                        <div>
                            <span class="skt-section-kicker">ISI SURAT</span>
                            <h2 id="skt_body_modal_title">Edit isi ketentuan</h2>
                        </div>
                        <button type="button" class="skt-modal-close" data-close-modal="skt_body_modal" aria-label="Tutup isi ketentuan" title="Tutup"><i class="fa fa-times" aria-hidden="true"></i></button>
                    </div>
                    <div class="skt-modal-body" id="skt_body_modal_body"></div>
                </div>
            </div>

        {!! Form::close() !!}

    </section>
@endsection

@section('javascript')
    <style>
        .skt-template-editor-page { --skt-ink: #20364a; --skt-muted: #6b7c8b; --skt-line: #d9e2e8; --skt-primary: #1769aa; --skt-soft: #f4f8fb; }
        .skt-editor-sidebar { min-width: 0; }
        .skt-control-dock { margin-bottom: 16px; padding: 18px; border: 1px solid var(--skt-line); border-radius: 10px; background: #fff; box-shadow: 0 5px 20px rgba(32, 54, 74, .06); }
        .skt-control-dock-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .skt-control-dock-heading h2 { margin: 4px 0 8px; color: var(--skt-ink); font-size: 18px; font-weight: 700; }
        .skt-control-dock-heading > i { color: #1687b5; font-size: 20px; }
        .skt-control-dock p { margin: 0 0 14px; color: var(--skt-muted); font-size: 12px; line-height: 1.5; }
        .skt-control-dock-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .skt-control-dock-actions .skt-button { margin: 0; padding-left: 8px; padding-right: 8px; }
        .skt-page-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 18px; }
        .skt-page-header h1 { margin: 4px 0 6px; color: var(--skt-ink); }
        .skt-eyebrow, .skt-section-kicker { color: #0c8790; font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        .skt-button { min-height: 40px; padding: 9px 15px; border-radius: 5px; }
        .skt-button-neutral { background: #fff; border: 1px solid #aab8c4; color: #334e68; }
        .skt-button-neutral:hover, .skt-button-neutral:focus { background: #f2f6f8; color: #20364a; border-color: #718596; }
        .skt-button-primary { background: #1687b5; border-color: #1687b5; }
        .skt-button-primary:hover, .skt-button-primary:focus { background: #0d6f98; border-color: #0d6f98; }
        .skt-settings-card, .skt-add-component-card, .skt-preview-card, .skt-component-editor, .skt-order-card, .skt-rich-text-card { border: 1px solid var(--skt-line); border-radius: 10px; box-shadow: 0 5px 20px rgba(32, 54, 74, .06); overflow: hidden; }
        .skt-editor-topline .box { margin-bottom: 16px; }
        .skt-settings-card .box-header, .skt-add-component-card .box-header, .skt-preview-card .box-header, .skt-component-editor .box-header, .skt-order-card .box-header, .skt-rich-text-card .box-header { padding: 14px 18px; background: #fff; }
        .skt-settings-card .box-title, .skt-add-component-card .box-title, .skt-preview-card .box-title, .skt-component-editor .box-title, .skt-order-card .box-title, .skt-rich-text-card .box-title { margin-left: 8px; color: var(--skt-ink); font-weight: 700; }
        .skt-settings-card .box-body, .skt-add-component-card .box-body, .skt-preview-card .box-body, .skt-component-editor .box-body, .skt-order-card .box-body, .skt-rich-text-card .box-body { padding: 18px; }
        .skt-settings-card label, .skt-component-editor label, .skt-rich-text-card label { color: #334e68; font-size: 12px; font-weight: 700; }
        .skt-settings-card .form-control, .skt-component-editor .form-control, .skt-rich-text-card .form-control, .skt-add-component-card .form-control { border: 1px solid #b9cbd7; border-radius: 5px; min-height: 40px; box-shadow: none; }
        .skt-settings-card .form-control:focus, .skt-component-editor .form-control:focus, .skt-add-component-card .form-control:focus, .skt-rich-text-card .form-control:focus { border-color: var(--skt-primary); box-shadow: 0 0 0 3px rgba(23, 105, 170, .12); }
        .skt-check-row { display: flex; flex-direction: column; gap: 10px; padding-top: 4px; }
        .skt-check-row label { font-weight: 400; }
        .skt-check-row input { margin-right: 5px; }
        .skt-help-copy { color: var(--skt-muted); line-height: 1.5; margin-bottom: 12px; }
        .skt-add-component-card .skt-button { margin-top: 10px; }
        .skt-quick-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
        .skt-quick-actions .skt-button { margin-top: 0; padding-left: 8px; padding-right: 8px; }
        .skt-editor-tip { display: flex; gap: 10px; padding: 13px 14px; border-left: 3px solid #16a2a4; border-radius: 6px; background: #edf8f7; color: #41606b; font-size: 12px; line-height: 1.5; }
        .skt-editor-tip > i { color: #0a8d8f; font-size: 18px; margin-top: 2px; }
        .skt-preview-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .skt-preview-column { min-width: 0; }
        .skt-preview-card { position: sticky; top: 12px; z-index: 2; }
        .skt-live-badge { color: #0a7e68; background: #e7f7f1; border: 1px solid #a9dfcc; border-radius: 20px; padding: 5px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .skt-live-badge span { display: inline-block; width: 7px; height: 7px; margin-right: 4px; border-radius: 50%; background: #17b897; }
        .skt-selected-label { color: var(--skt-muted); font-size: 12px; text-align: right; }
        .skt-preview-note { display: flex; gap: 8px; align-items: center; padding: 9px 11px; border: 1px solid #d8edf2; border-radius: 5px; background: #f2fbfc; color: #4c6b77; font-size: 12px; margin-bottom: 14px; }
        .skt-preview-note i { color: #0b8c96; }
        .skt-preview-viewport { min-height: 520px; padding: 24px; overflow: auto; background: linear-gradient(135deg, #edf2f5 25%, #e7edf1 25%, #e7edf1 50%, #edf2f5 50%, #edf2f5 75%, #e7edf1 75%); background-size: 18px 18px; border: 1px solid #d5e0e7; border-radius: 7px; }
        .skt-preview-paper { width: 100%; max-width: 760px; min-height: 460px; margin: 0 auto; padding: 34px 42px 44px; background: #fff; box-shadow: 0 10px 28px rgba(32, 54, 74, .15); color: #111; font-family: Arial, sans-serif; line-height: 1.45; outline: none; }
        .skt-preview-paper:focus { box-shadow: 0 0 0 3px rgba(23, 105, 170, .16), 0 10px 28px rgba(32, 54, 74, .15); }
        .skt-preview-letterhead { border-bottom: 0; }
        .skt-preview-header-grid { display: grid; grid-template-columns: minmax(72px, 1fr) minmax(0, 2.8fr) minmax(72px, 1fr); grid-auto-rows: auto; column-gap: 8px; align-items: stretch; }
        .skt-preview-header-grid .skt-preview-header-component { justify-self: stretch; padding-top: 0; padding-bottom: 0; min-height: 0; }
        .skt-preview-header-grid .skt-preview-text { line-height: 1.15; white-space: nowrap; }
        .skt-preview-header-grid .skt-preview-logo { min-width: 0; }
        .skt-preview-header-grid .skt-preview-logo img { max-width: 100%; }
        .skt-preview-body { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: center; }
        .skt-preview-component { position: relative; box-sizing: border-box; min-height: 22px; padding: 4px 7px; border: 1px solid transparent; border-radius: 4px; cursor: pointer; transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease; }
        .skt-preview-component[draggable="true"] { cursor: grab; }
        .skt-preview-component.is-dragging { opacity: .45; }
        .skt-preview-component.is-drop-target { border-color: #efaa32; box-shadow: 0 0 0 3px rgba(239, 170, 50, .2); }
        .skt-preview-component:hover, .skt-preview-component:focus-within { border-color: #75b8e4; background: rgba(222, 242, 253, .7); }
        .skt-preview-component.is-selected { border: 2px solid #1672ad; background: rgba(222, 242, 253, .54); box-shadow: 0 0 0 3px rgba(22, 114, 173, .13); }
        .skt-preview-component.is-hidden { opacity: .48; background: repeating-linear-gradient(135deg, #f5f7f8, #f5f7f8 5px, #edf1f3 5px, #edf1f3 10px); }
        .skt-preview-edit { position: absolute; z-index: 2; top: -13px; right: 5px; display: none; min-height: 30px; padding: 5px 9px; border: 1px solid #1769aa; border-radius: 4px; background: #1769aa; color: #fff; font-size: 11px; font-weight: 700; box-shadow: 0 2px 7px rgba(32, 54, 74, .2); }
        .skt-preview-component:hover .skt-preview-edit, .skt-preview-component:focus-within .skt-preview-edit, .skt-preview-component.is-selected .skt-preview-edit { display: inline-flex; align-items: center; gap: 4px; }
        .skt-preview-edit:focus { outline: 3px solid rgba(22, 114, 173, .28); outline-offset: 1px; }
        .skt-preview-component img { max-width: 100%; object-fit: contain; }
        .skt-preview-paper table { width: 100%; border-collapse: collapse; margin: 8px 0 12px; }
        .skt-preview-paper td, .skt-preview-paper th { border: 1px solid #333; padding: 6px; vertical-align: top; }
        .skt-preview-paper .skt-signature td { border: 0; padding-top: 4px; }
        .skt-preview-paper .skt-heading { margin: 0 0 5px; }
        .skt-preview-paper .skt-rich-text ol, .skt-preview-paper .skt-rich-text ul { padding-left: 22px; }
        .skt-preview-paper .skt-rich-text p { margin: 0 0 6px; }
        .skt-preview-empty { color: #8193a2; border: 1px dashed #aabdc8; padding: 16px; text-align: center; }
        .skt-preview-mode-note { width: 100%; padding: 9px; border: 1px dashed #d8a63a; background: #fff9e9; color: #795b16; text-align: center; font-size: 11px; }
        .skt-empty-editor { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 120px; gap: 5px; color: #708391; text-align: center; }
        .skt-empty-editor i { color: #75aeca; font-size: 24px; margin-bottom: 4px; }
        .skt-empty-editor strong { color: var(--skt-ink); }
        .skt-editor-fields[hidden] { display: none; }
        .skt-selected-banner { margin-bottom: 14px; padding: 9px 11px; border-left: 3px solid #1672ad; border-radius: 4px; background: #eef7fc; color: #476575; font-size: 12px; }
        .skt-inline-note { min-height: 40px; padding: 9px 11px; border: 1px solid #d8edf2; border-radius: 5px; background: #f2fbfc; color: #4c6b77; font-size: 11px; line-height: 1.45; }
        .skt-inline-note i { color: #0b8c96; margin-right: 3px; }
        .skt-component-editor .row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 12px; margin: 0 -6px; }
        .skt-component-editor .row > [class*="col-"] { float: none; width: auto; min-width: 0; padding: 0 6px; }
        .skt-component-editor .row > [hidden] { display: none !important; }
        .skt-component-editor .row > .col-sm-6 { grid-column: 1 / -1; }
        .skt-component-editor .row > .component-field-text { grid-column: 1 / -1; }
        .skt-component-editor .row > .component-field-logo-upload { grid-column: 1 / -1; }
        .skt-component-editor .row > .component-field-business-note { grid-column: 1 / -1; }
        .skt-component-editor .form-group { min-width: 0; }
        .skt-component-editor input, .skt-component-editor select, .skt-component-editor textarea { max-width: 100%; }
        .skt-logo-upload-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
        .skt-logo-slot[hidden] { display: none; }
        .skt-file-input { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .skt-file-picker { display: inline-flex; align-items: center; gap: 7px; min-height: 40px; max-width: 100%; margin: 0; padding: 9px 13px; border: 1px solid #9fb3c1; border-radius: 5px; background: #fff; color: #334e68; cursor: pointer; font-size: 12px; font-weight: 700 !important; line-height: 1.2; }
        .skt-file-picker:hover { border-color: #1769aa; background: #f2f8fc; color: #1769aa; }
        .skt-file-input:focus + .skt-file-picker { outline: 3px solid rgba(23, 105, 170, .2); outline-offset: 2px; border-color: #1769aa; }
        .skt-file-picker i { color: #1687b5; }
        .skt-logo-slot .file-name-hint, .letterhead-image-fields .file-name-hint { overflow-wrap: anywhere; }
        .skt-color-controls { display: flex; align-items: center; gap: 8px; }
        .skt-color-input { flex: 0 0 52px; width: 52px !important; padding: 3px !important; cursor: pointer; }
        .skt-color-hex { flex: 1 1 auto; min-width: 0; font-family: Consolas, monospace; letter-spacing: .04em; text-transform: uppercase; }
        .skt-component-list { display: flex; flex-direction: column; gap: 8px; }
        .skt-component-row { display: flex; align-items: center; gap: 10px; min-height: 54px; padding: 8px 10px; border: 1px solid #d9e2e8; border-radius: 6px; background: #fff; }
        .skt-component-row.is-selected { border-color: #3c9bd0; background: #f1f9fd; box-shadow: 0 0 0 2px rgba(60, 155, 208, .1); }
        .skt-component-row.is-hidden { opacity: .62; }
        .skt-component-row .skt-row-index { width: 24px; height: 24px; border-radius: 50%; background: #e8f0f4; color: #4f6878; font-size: 11px; line-height: 24px; text-align: center; font-weight: 700; flex: 0 0 auto; }
        .skt-component-row .skt-row-main { min-width: 0; flex: 1; }
        .skt-component-row .skt-row-title { display: block; color: var(--skt-ink); font-size: 13px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .skt-component-row .skt-row-meta { color: var(--skt-muted); font-size: 11px; }
        .skt-component-row .skt-row-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 5px; }
        .skt-component-row .skt-row-actions .btn { min-height: 34px; min-width: 34px; padding: 6px 8px; }
        .skt-form-actions { display: flex; justify-content: flex-end; gap: 10px; margin: 16px 0 24px; }
        .skt-modal-source { display: none; }
        .skt-modal[hidden] { display: none; }
        .skt-modal { position: fixed; z-index: 2000; inset: 0; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .skt-modal-backdrop { position: absolute; inset: 0; background: rgba(20, 39, 54, .58); }
        .skt-modal-dialog { position: relative; z-index: 1; display: flex; flex-direction: column; width: min(820px, 100%); max-height: min(760px, calc(100vh - 40px)); border: 1px solid #cbd9e2; border-radius: 10px; background: #f7fafc; box-shadow: 0 18px 50px rgba(20, 39, 54, .28); overflow: hidden; }
        .skt-modal-dialog-compact { width: min(620px, 100%); }
        .skt-modal-dialog-wide { width: min(960px, 100%); }
        .skt-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 18px 20px; border-bottom: 1px solid #dbe5eb; background: #fff; }
        .skt-modal-header h2 { margin: 3px 0 0; color: var(--skt-ink); font-size: 20px; font-weight: 700; }
        .skt-modal-close { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border: 1px solid #aab8c4; border-radius: 5px; background: #fff; color: #334e68; }
        .skt-modal-close:hover, .skt-modal-close:focus { border-color: #718596; background: #f2f6f8; color: #20364a; }
        .skt-modal-body { min-height: 0; overflow: auto; padding: 18px 20px 22px; }
        .skt-modal-body .skt-order-card, .skt-modal-body .skt-rich-text-card { display: block; margin: 0; box-shadow: none; }
        .skt-modal-body .skt-order-card .box-header, .skt-modal-body .skt-rich-text-card .box-header { display: none; }
        .skt-modal-body .skt-order-card .box-body, .skt-modal-body .skt-rich-text-card .box-body { padding: 0; }
        .skt-modal-body .skt-rich-text-card .help-block { margin-bottom: 0; }
        body.skt-modal-open { overflow: hidden; }
        .letterhead-image-fields.is-disabled { display: none; }
        @media (max-width: 767px) {
            .skt-page-header { align-items: flex-start; flex-direction: column; }
            .skt-page-header .skt-button { width: 100%; }
            .skt-editor-topline { display: flex; flex-direction: column; }
            .skt-editor-sidebar { display: contents; }
            .skt-control-dock { order: 1; }
            .skt-preview-column { order: 2; width: 100%; float: none; }
            .skt-component-editor { order: 3; width: 100%; }
            .skt-editor-tip { order: 4; }
            .skt-control-dock-actions { grid-template-columns: 1fr; }
            .skt-preview-card { position: static; }
            .skt-preview-viewport { padding: 10px; min-height: 420px; }
            .skt-preview-paper { min-width: 560px; padding: 24px 28px 34px; }
            .skt-quick-actions { grid-template-columns: 1fr; }
            .skt-component-editor .row { grid-template-columns: 1fr; }
            .skt-component-editor .row > .col-sm-6 { grid-column: auto; }
            .skt-component-editor .row > .component-field-text { grid-column: auto; }
            .skt-logo-upload-grid { grid-template-columns: 1fr; }
            .skt-component-row { align-items: flex-start; flex-wrap: wrap; }
            .skt-component-row .skt-row-actions { width: 100%; justify-content: flex-start; padding-left: 34px; }
            .skt-form-actions { flex-direction: column-reverse; }
            .skt-form-actions .skt-button { width: 100%; }
            .skt-selected-label { max-width: 45%; }
            .skt-modal { padding: 10px; }
            .skt-modal-dialog { max-height: calc(100vh - 20px); }
            .skt-modal-header { padding: 14px 15px; }
            .skt-modal-body { padding: 15px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .skt-preview-component { transition: none; }
        }
    </style>
    <script>
        (function () {
            var state = {!! json_encode($initial_components, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
            var definitions = {!! json_encode($component_definitions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
            var previewData = {!! json_encode([
                'businessName' => $business->name,
                'businessAddress' => $business_address,
                'businessContact' => $business_contact,
                'logo1' => $logo_1_url,
                'logo2' => $logo_2_url,
                'letterheadImage' => $letterhead_image_url,
                'sampleCustomer' => 'Contoh Customer',
                'sampleTitle' => 'SURAT PERJANJIAN KERJA (SPK)',
                'sampleNumber' => '001/SPK/DEMO/2026',
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
            var preview = document.getElementById('skt_live_preview');
            var componentList = document.getElementById('component_list');
            var editorEmpty = document.getElementById('component_editor_empty');
            var editorFields = document.getElementById('component_editor_fields');
            var selectedLabel = document.getElementById('selected_component_label');
            var selectedIndex = state.length ? 0 : -1;
            var dragIndex = null;
            var activeModal = null;
            var placeholderBusinessName = String.fromCharCode(123, 123) + 'business.name' + String.fromCharCode(125, 125);
            var placeholderBusinessAddress = String.fromCharCode(123, 123) + 'business.address' + String.fromCharCode(125, 125);
            var placeholderBusinessContact = String.fromCharCode(123, 123) + 'business.contact' + String.fromCharCode(125, 125);

            function escapeHtml(value) {
                return String(value === undefined || value === null ? '' : value)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function attr(component, key, fallback) {
                component.attributes = component.attributes || {};
                return component.attributes[key] === undefined || component.attributes[key] === null ? fallback : component.attributes[key];
            }

            function number(value, fallback, min, max) {
                var parsed = parseInt(value, 10);
                if (isNaN(parsed)) parsed = fallback;
                return Math.max(min, Math.min(max, parsed));
            }

            function colorValue(value, fallback) {
                var candidate = String(value || '');
                return /^#[0-9a-f]{6}$/i.test(candidate) ? candidate : fallback;
            }

            function fontFamilyValue(value) {
                var families = {
                    'Arial': 'Arial, sans-serif',
                    'Georgia': 'Georgia, serif',
                    'Times New Roman': 'Times New Roman, Times, serif',
                    'Verdana': 'Verdana, sans-serif'
                };
                return families[String(value || '')] || families.Arial;
            }

            function replacePlaceholders(value) {
                var values = {
                    'business.name': previewData.businessName,
                    'business.address': previewData.businessAddress,
                    'business.contact': previewData.businessContact,
                    'party1.name': previewData.businessName,
                    'party1.title': 'Direktur',
                    'party2.name': previewData.sampleCustomer,
                    'contract.nomor': previewData.sampleNumber,
                    'contract.pekerjaan': 'Pengangkutan Cangkang',
                };
                return String(value || '').replace(/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/g, function (_, key) {
                    return values[key] === undefined ? '' : values[key];
                });
            }

            function safeRichHtml(value) {
                var wrapper = document.createElement('div');
                wrapper.innerHTML = String(value || '');
                wrapper.querySelectorAll('script, iframe, object, embed, style, link, meta').forEach(function (node) { node.remove(); });
                wrapper.querySelectorAll('*').forEach(function (node) {
                    Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
                        if (/^on/i.test(attribute.name) || /^(style|class|id|src|href)$/i.test(attribute.name)) node.removeAttribute(attribute.name);
                    });
                });
                return wrapper.innerHTML;
            }

            function styleFor(component, isHeader) {
                var a = component.attributes || {};
                var style = 'text-align:' + (a.align || 'left') + ';' +
                    'font-size:' + number(a.font_size, 12, 8, 48) + 'px;' +
                    'font-weight:' + (a.font_weight || '400') + ';' +
                    'font-family:' + fontFamilyValue(a.font_family) + ';' +
                    'color:' + colorValue(a.color, '#333333') + ';' +
                    'width:' + number(a.width, 100, 10, 100) + '%;' +
                    'margin-bottom:' + number(a.margin_bottom, 8, 0, 64) + 'px;';
                if (!isHeader) return style;

                var column = ['left', 'center', 'right', 'full'].indexOf(a.layout_column) !== -1 ? a.layout_column : 'center';
                var gridColumn = column === 'left' ? '1' : (column === 'right' ? '3' : (column === 'full' ? '1 / -1' : '2'));
                style += 'grid-column:' + gridColumn + ';';
                if (component.type === 'logo') {
                    var justify = a.align === 'right' ? 'flex-end' : (a.align === 'left' ? 'flex-start' : 'center');
                    style += 'grid-row:span ' + number(a.row_span, 3, 1, 6) + ';width:100%;align-self:stretch;display:flex;align-items:center;justify-content:' + justify + ';';
                } else if (column !== 'full') {
                    style += 'width:100%;';
                    style += 'justify-self:center;';
                } else {
                    style += 'width:100%;';
                    style += 'justify-self:stretch;';
                }
                return style;
            }

            function defaultAttributes(type) {
                var attributes = { text: '', source: 'logo_1', content_key: null, heading: '', label: 'Nomor', align: 'left', font_size: 12, font_weight: '400', font_family: 'Arial', width: 100, max_height: 112, margin_bottom: 8, color: '#333333', layout_column: 'center', row_span: 1 };
                if (type === 'text') attributes.text = placeholderBusinessName;
                if (type === 'contact_info') attributes.text = placeholderBusinessAddress + '\n' + placeholderBusinessContact;
                if (type === 'document_title') { attributes.text = 'SURAT PERJANJIAN KERJA (SPK)'; attributes.align = 'center'; attributes.font_weight = '700'; }
                if (type === 'document_number') { attributes.align = 'center'; attributes.label = 'Nomor'; }
                if (type === 'rich_text') { attributes.content_key = 'body_html'; attributes.heading = 'KETENTUAN PELAKSANAAN'; }
                if (type === 'separator') attributes.width = 100;
                if (type === 'separator') attributes.layout_column = 'full';
                if (type === 'logo') { attributes.source = 'logo_2'; attributes.layout_column = 'right'; attributes.row_span = 3; }
                return attributes;
            }

            function ensureComponent(component) {
                var originalAttributes = component.attributes || {};
                var hadLayoutColumn = originalAttributes.layout_column !== undefined && originalAttributes.layout_column !== null && originalAttributes.layout_column !== '';
                var hadRowSpan = originalAttributes.row_span !== undefined && originalAttributes.row_span !== null && originalAttributes.row_span !== '';
                var originalSource = originalAttributes.source;
                component.attributes = Object.assign(defaultAttributes(component.type || 'text'), originalAttributes);
                if (component.attributes.source === 'business_logo') component.attributes.source = 'logo_1';
                if (!hadLayoutColumn || !component.attributes.layout_column) {
                    component.attributes.layout_column = component.type === 'logo'
                        ? (originalSource === 'logo_2' ? 'right' : 'left')
                        : (component.type === 'separator' ? 'full' : 'center');
                }
                if (!hadRowSpan || !component.attributes.row_span) component.attributes.row_span = component.type === 'logo' ? 3 : 1;
                if (component.type === 'contact_info' && !String(component.attributes.text || '').trim()) component.attributes.text = placeholderBusinessAddress + '\n' + placeholderBusinessContact;
                component.label = component.label || definitions[component.type] || component.type || 'Komponen';
                if (component.visible === undefined) component.visible = true;
                return component;
            }

            function componentText(component) { return replacePlaceholders(attr(component, 'text', '')); }

            function previewBodyHtml() {
                var editor = window.tinymce && tinymce.get('body_html');
                return editor ? editor.getContent() : document.getElementById('body_html').value;
            }

            function previewInner(component) {
                var type = component.type || 'text';
                var a = component.attributes || {};
                var text = componentText(component);
                if (type === 'logo') {
                    var source = a.source === 'logo_2' ? previewData.logo2 : previewData.logo1;
                    return source ? '<img src="' + escapeHtml(source) + '" alt="' + escapeHtml(component.label) + '" style="max-height:' + number(a.max_height, 112, 20, 240) + 'px;">' : '<span class="skt-preview-empty"><i class="fa fa-picture-o"></i><br>' + escapeHtml(a.source === 'logo_2' ? 'Logo 2 belum diunggah' : 'Logo 1 belum diunggah') + '</span>';
                }
                if (type === 'text' || type === 'document_title' || type === 'contact_info') {
                    if (type === 'contact_info' && !text.trim()) text = previewData.businessAddress + (previewData.businessContact ? '\n' + previewData.businessContact : '');
                    return escapeHtml(text).replace(/\n/g, '<br>');
                }
                if (type === 'separator') return '<hr style="border:0;border-top:2px solid ' + escapeHtml(colorValue(a.color, '#333333')) + ';margin:0;">';
                if (type === 'document_number') return '<span>' + escapeHtml(a.label || 'Nomor') + ': ' + escapeHtml(previewData.sampleNumber) + '</span>';
                if (type === 'parties') return '<table><tr><td width="50%"><strong>PIHAK PERTAMA</strong><br>' + escapeHtml(previewData.businessName) + '<br>Direktur<br>Alamat: ' + escapeHtml(previewData.businessAddress || '-') + '</td><td width="50%"><strong>PIHAK KEDUA</strong><br>' + escapeHtml(previewData.sampleCustomer) + '<br>Jabatan: Penanggung Jawab<br>Alamat: Alamat customer contoh</td></tr></table>';
                if (type === 'job_summary') return '<table><tr><td><strong>Pekerjaan</strong><br>Pengangkutan Cangkang</td></tr></table>';
                if (type === 'details_table') return (a.heading ? '<p class="skt-heading"><strong>' + escapeHtml(a.heading) + '</strong></p>' : '') + '<table><tr><td width="30%">Kapal MV</td><td>MV. Contoh</td></tr><tr><td>Tonase</td><td>1.000 Ton</td></tr><tr><td>Lokasi Muat</td><td>Lokasi Muat Contoh</td></tr><tr><td>Lokasi Bongkar</td><td>Lokasi Bongkar Contoh</td></tr></table>';
                if (type === 'rich_text') return (a.heading ? '<p class="skt-heading"><strong>' + escapeHtml(a.heading) + '</strong></p>' : '') + '<div class="skt-rich-text">' + safeRichHtml(a.content_key === 'body_html' ? previewBodyHtml() : text) + '</div>';
                if (type === 'signatures') return '<table class="skt-signature"><tr><td width="50%"><strong>PIHAK PERTAMA</strong><br>' + escapeHtml(previewData.businessName) + '<br>Direktur<br><br><br>(___________________)</td><td width="50%"><strong>PIHAK KEDUA</strong><br>' + escapeHtml(previewData.sampleCustomer) + '<br>Penanggung Jawab<br><br><br>(___________________)</td></tr></table>';
                return escapeHtml(text);
            }

            function renderPreviewComponent(component, index, isHeader) {
                ensureComponent(component);
                var selected = index === selectedIndex ? ' is-selected' : '';
                var hidden = component.visible === false ? ' is-hidden' : '';
                var headerClass = isHeader ? ' skt-preview-header-component skt-preview-' + escapeHtml(component.type) + (component.type === 'text' ? ' skt-preview-text' : '') : '';
                return '<div class="skt-preview-component' + headerClass + selected + hidden + '" data-index="' + index + '" style="' + styleFor(component, isHeader) + '" tabindex="0" role="group" draggable="true" aria-grabbed="false" aria-label="Pilih ' + escapeHtml(component.label) + '. Tarik untuk mengubah urutan.">' +
                    '<button type="button" class="skt-preview-edit" data-edit-index="' + index + '" draggable="false" aria-label="Edit ' + escapeHtml(component.label) + '" title="Edit komponen"><i class="fa fa-pencil" aria-hidden="true"></i> Edit</button>' + previewInner(component) + '</div>';
            }

            function renderPreview() {
                var mode = document.getElementById('letterhead_mode').value;
                if (!state.length) { preview.innerHTML = '<div class="skt-preview-empty">Belum ada komponen. Tambahkan komponen dari panel kiri.</div>'; return; }
                var html = '';
                if (mode === 'image' && previewData.letterheadImage) {
                    html += '<div class="skt-preview-mode-note">Preview menggunakan gambar kop penuh. Ubah ke mode komponen untuk mengedit elemen satu per satu.</div><div class="skt-preview-component" style="width:100%;"><img src="' + escapeHtml(previewData.letterheadImage) + '" alt="Gambar kop penuh" style="max-width:100%;max-height:180px;"></div>';
                } else {
                    var headerItems = [];
                    var bodyItems = [];
                    var inBody = false;
                    state.forEach(function (component, index) {
                        if (component.type === 'document_title') inBody = true;
                        (inBody ? bodyItems : headerItems).push({ component: component, index: index });
                    });
                    html += '<div class="skt-preview-letterhead skt-preview-header-grid">';
                    headerItems.forEach(function (item) { html += renderPreviewComponent(item.component, item.index, true); });
                    html += '</div><div class="skt-preview-body">';
                    bodyItems.forEach(function (item) { html += renderPreviewComponent(item.component, item.index, false); });
                    html += '</div>';
                }
                preview.innerHTML = html;
            }

            function syncFormToState() {
                var component = state[selectedIndex];
                if (!component) return;
                ensureComponent(component);
                component.label = document.getElementById('component_label').value;
                component.type = document.getElementById('component_type').value;
                component.visible = document.getElementById('component_visible').value === '1';
                component.attributes.align = document.getElementById('component_align').value;
                component.attributes.width = document.getElementById('component_width').value;
                component.attributes.font_size = document.getElementById('component_font_size').value;
                component.attributes.font_weight = document.getElementById('component_font_weight').value;
                component.attributes.font_family = document.getElementById('component_font_family').value;
                component.attributes.margin_bottom = document.getElementById('component_margin_bottom').value;
                component.attributes.text = document.getElementById('component_text').value;
                component.attributes.source = document.getElementById('component_source').value;
                component.attributes.heading = document.getElementById('component_heading').value;
                component.attributes.content_key = document.getElementById('component_content_key').value || null;
                component.attributes.color = colorValue(
                    document.getElementById('component_color_hex').value,
                    colorValue(document.getElementById('component_color').value, '#333333')
                );
                component.attributes.max_height = document.getElementById('component_max_height').value;
                component.attributes.layout_column = document.getElementById('component_layout_column').value;
                component.attributes.row_span = document.getElementById('component_row_span').value;
            }

            function setFieldVisible(selector, visible) { document.querySelector(selector).hidden = !visible; }

            function renderEditor() {
                var component = state[selectedIndex];
                if (!component) {
                    editorEmpty.hidden = false; editorFields.hidden = true; selectedLabel.textContent = 'Belum ada komponen dipilih'; return;
                }
                ensureComponent(component);
                editorEmpty.hidden = true; editorFields.hidden = false;
                selectedLabel.textContent = component.label || definitions[component.type];
                document.getElementById('component_label').value = component.label || '';
                document.getElementById('component_type').value = component.type;
                document.getElementById('component_visible').value = component.visible === false ? '0' : '1';
                document.getElementById('component_align').value = attr(component, 'align', 'left');
                document.getElementById('component_width').value = attr(component, 'width', 100);
                document.getElementById('component_font_size').value = attr(component, 'font_size', 12);
                document.getElementById('component_font_weight').value = attr(component, 'font_weight', '400');
                document.getElementById('component_font_family').value = ['Arial', 'Georgia', 'Times New Roman', 'Verdana'].indexOf(attr(component, 'font_family', 'Arial')) !== -1 ? attr(component, 'font_family', 'Arial') : 'Arial';
                document.getElementById('component_text').value = attr(component, 'text', '');
                document.getElementById('component_source').value = attr(component, 'source', 'logo_1') === 'business_logo' ? 'logo_1' : attr(component, 'source', 'logo_1');
                document.getElementById('component_heading').value = attr(component, 'heading', '');
                document.getElementById('component_content_key').value = attr(component, 'content_key', '') || '';
                var componentColor = colorValue(attr(component, 'color', '#333333'), '#333333');
                document.getElementById('component_color').value = componentColor;
                document.getElementById('component_color_hex').value = componentColor;
                document.getElementById('component_max_height').value = attr(component, 'max_height', 112);
                document.getElementById('component_layout_column').value = attr(component, 'layout_column', component.type === 'logo' && attr(component, 'source', 'logo_1') === 'logo_2' ? 'right' : 'center');
                document.getElementById('component_row_span').value = attr(component, 'row_span', component.type === 'logo' ? 3 : 1);
                document.getElementById('component_margin_bottom').value = attr(component, 'margin_bottom', 8);
                var type = component.type;
                var firstBodyIndex = state.findIndex(function (item) { return (item.type || '') === 'document_title'; });
                var isHeader = firstBodyIndex === -1 || selectedIndex < firstBodyIndex;
                setFieldVisible('.component-field-text', ['text', 'document_title', 'rich_text', 'contact_info'].indexOf(type) !== -1);
                var logoSource = attr(component, 'source', 'logo_1') === 'business_logo' ? 'logo_1' : attr(component, 'source', 'logo_1');
                setFieldVisible('.component-field-logo-upload', type === 'logo');
                setFieldVisible('.component-field-business-note', type === 'logo');
                setFieldVisible('.skt-logo-slot-1', type === 'logo' && logoSource === 'logo_1');
                setFieldVisible('.skt-logo-slot-2', type === 'logo' && logoSource === 'logo_2');
                setFieldVisible('.component-field-heading', ['rich_text', 'details_table'].indexOf(type) !== -1);
                setFieldVisible('.component-field-content-key', type === 'rich_text');
                setFieldVisible('.component-field-color', type !== 'logo');
                setFieldVisible('.component-field-font-family', type !== 'logo');
                setFieldVisible('.component-field-max-height', type === 'logo');
                setFieldVisible('.component-field-layout-column', isHeader);
                setFieldVisible('.component-field-row-span', type === 'logo');
                document.getElementById('component_text').placeholder = type === 'document_title'
                    ? 'Contoh: SURAT PERJANJIAN KERJA (SPK)'
                    : (type === 'contact_info' ? placeholderBusinessAddress + '\n' + placeholderBusinessContact : 'Gunakan placeholder, misalnya ' + placeholderBusinessName);
                document.getElementById('component_text_help').textContent = type === 'contact_info'
                    ? 'Isi manual atau gunakan placeholder business.address dan business.contact. Baris baru akan tampil sebagai baris terpisah.'
                    : 'Contoh: nama bisnis dapat memakai placeholder business.name.';
            }

            function renderComponentList() {
                componentList.innerHTML = '';
                state.forEach(function (component, index) {
                    ensureComponent(component);
                    var row = document.createElement('div');
                    row.className = 'skt-component-row' + (index === selectedIndex ? ' is-selected' : '') + (component.visible === false ? ' is-hidden' : '');
                    row.setAttribute('data-index', index);
                    row.innerHTML = '<span class="skt-row-index">' + (index + 1) + '</span><div class="skt-row-main"><span class="skt-row-title">' + escapeHtml(component.label || definitions[component.type]) + '</span><span class="skt-row-meta">' + escapeHtml(definitions[component.type] || component.type) + ' · ' + (component.visible === false ? 'Tersembunyi' : 'Tampil') + '</span></div><div class="skt-row-actions"><button type="button" class="btn btn-default btn-sm row-edit" title="Edit komponen"><i class="fa fa-pencil" aria-hidden="true"></i> Edit</button><button type="button" class="btn btn-default btn-sm row-up" title="Naikkan" aria-label="Naikkan komponen"><i class="fa fa-arrow-up" aria-hidden="true"></i></button><button type="button" class="btn btn-default btn-sm row-down" title="Turunkan" aria-label="Turunkan komponen"><i class="fa fa-arrow-down" aria-hidden="true"></i></button><button type="button" class="btn btn-default btn-sm row-toggle" title="Tampilkan atau sembunyikan">' + (component.visible === false ? '<i class="fa fa-eye" aria-hidden="true"></i> Tampilkan' : '<i class="fa fa-eye-slash" aria-hidden="true"></i> Sembunyikan') + '</button><button type="button" class="btn btn-danger btn-sm row-remove" title="Hapus komponen"><i class="fa fa-trash" aria-hidden="true"></i> Hapus</button></div>';
                    componentList.appendChild(row);
                });
            }

            function selectComponent(index) {
                selectedIndex = index; renderEditor(); renderComponentList(); renderPreview();
                var editor = document.getElementById('skt_component_editor');
                if (window.matchMedia && window.matchMedia('(max-width: 767px)').matches) editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            preview.addEventListener('click', function (event) {
                var component = event.target.closest('.skt-preview-component');
                if (component) selectComponent(parseInt(component.getAttribute('data-index'), 10));
            });
            preview.addEventListener('keydown', function (event) {
                var component = event.target.closest('.skt-preview-component');
                if (component && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); selectComponent(parseInt(component.getAttribute('data-index'), 10)); }
            });
            function clearDropTargets() {
                preview.querySelectorAll('.skt-preview-component.is-drop-target').forEach(function (item) { item.classList.remove('is-drop-target'); });
            }
            function moveComponent(fromIndex, toIndex) {
                if (fromIndex === null || toIndex === null || fromIndex === toIndex || fromIndex < 0 || toIndex < 0 || fromIndex >= state.length || toIndex >= state.length) return;
                var moved = state.splice(fromIndex, 1)[0];
                state.splice(toIndex, 0, moved);
                selectedIndex = toIndex;
                renderEditor(); renderComponentList(); renderPreview();
            }
            preview.addEventListener('dragstart', function (event) {
                var component = event.target.closest('.skt-preview-component[data-index]');
                if (!component || event.target.closest('.skt-preview-edit')) return;
                dragIndex = parseInt(component.getAttribute('data-index'), 10);
                component.classList.add('is-dragging');
                component.setAttribute('aria-grabbed', 'true');
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', String(dragIndex));
                }
            });
            preview.addEventListener('dragover', function (event) {
                var component = event.target.closest('.skt-preview-component[data-index]');
                if (!component || dragIndex === null) return;
                event.preventDefault();
                clearDropTargets();
                if (parseInt(component.getAttribute('data-index'), 10) !== dragIndex) component.classList.add('is-drop-target');
                if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
            });
            preview.addEventListener('drop', function (event) {
                var component = event.target.closest('.skt-preview-component[data-index]');
                if (!component) return;
                event.preventDefault();
                var fromIndex = dragIndex;
                var toIndex = parseInt(component.getAttribute('data-index'), 10);
                if (event.dataTransfer && event.dataTransfer.getData('text/plain')) fromIndex = parseInt(event.dataTransfer.getData('text/plain'), 10);
                clearDropTargets();
                moveComponent(fromIndex, toIndex);
                dragIndex = null;
            });
            preview.addEventListener('dragend', function (event) {
                var component = event.target.closest('.skt-preview-component[data-index]');
                if (component) { component.classList.remove('is-dragging'); component.setAttribute('aria-grabbed', 'false'); }
                clearDropTargets();
                dragIndex = null;
            });
            function closeModal(id) {
                var modal = document.getElementById(id);
                if (!modal) return;
                modal.hidden = true;
                if (activeModal === modal) activeModal = null;
                if (!activeModal) document.body.classList.remove('skt-modal-open');
            }
            function openModal(id) {
                var modal = document.getElementById(id);
                if (!modal) return;
                if (activeModal) closeModal(activeModal.id);
                modal.hidden = false;
                activeModal = modal;
                document.body.classList.add('skt-modal-open');
                var focusTarget = modal.querySelector('.skt-modal-close, input, textarea, button');
                if (focusTarget) focusTarget.focus();
            }
            var sidebar = document.querySelector('.skt-editor-sidebar');
            var componentEditor = document.getElementById('skt_component_editor');
            var editorTip = sidebar ? sidebar.querySelector('.skt-editor-tip') : null;
            if (sidebar && componentEditor) sidebar.insertBefore(componentEditor, editorTip || null);
            var identityCard = document.getElementById('template_identity_card');
            var addCard = document.getElementById('component_add_card');
            var identityModalBody = document.getElementById('skt_identity_modal_body');
            var addModalBody = document.getElementById('skt_add_modal_body');
            if (identityCard && identityModalBody) { identityCard.classList.remove('skt-modal-source'); identityModalBody.appendChild(identityCard); }
            if (addCard && addModalBody) { addCard.classList.remove('skt-modal-source'); addModalBody.appendChild(addCard); }
            var orderCard = document.getElementById('component_order_card');
            var bodyCard = document.getElementById('body_html_card');
            var orderModalBody = document.getElementById('skt_component_modal_body');
            var bodyModalBody = document.getElementById('skt_body_modal_body');
            if (orderCard && orderModalBody) { orderCard.classList.remove('skt-modal-source'); orderModalBody.appendChild(orderCard); }
            if (bodyCard && bodyModalBody) { bodyCard.classList.remove('skt-modal-source'); bodyModalBody.appendChild(bodyCard); }
            document.getElementById('open_identity_modal').addEventListener('click', function () { openModal('skt_identity_modal'); });
            document.getElementById('open_add_modal').addEventListener('click', function () { openModal('skt_add_modal'); });
            document.getElementById('open_order_modal').addEventListener('click', function () { openModal('skt_component_modal'); });
            document.getElementById('open_body_modal').addEventListener('click', function () { openModal('skt_body_modal'); });
            document.querySelectorAll('[data-close-modal]').forEach(function (control) {
                control.addEventListener('click', function (event) {
                    if (control.classList.contains('skt-modal-backdrop') && event.target !== control) return;
                    closeModal(control.getAttribute('data-close-modal'));
                });
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && activeModal) closeModal(activeModal.id);
            });
            componentList.addEventListener('click', function (event) {
                var row = event.target.closest('.skt-component-row');
                if (!row) return;
                var index = parseInt(row.getAttribute('data-index'), 10);
                if (event.target.closest('.row-up')) { if (index > 0) { var up = state[index - 1]; state[index - 1] = state[index]; state[index] = up; selectedIndex = index - 1; } }
                else if (event.target.closest('.row-down')) { if (index < state.length - 1) { var down = state[index + 1]; state[index + 1] = state[index]; state[index] = down; selectedIndex = index + 1; } }
                else if (event.target.closest('.row-toggle')) state[index].visible = state[index].visible === false;
                else if (event.target.closest('.row-remove')) { state.splice(index, 1); selectedIndex = state.length ? Math.min(index, state.length - 1) : -1; }
                else if (event.target.closest('.row-edit')) { selectComponent(index); closeModal('skt_component_modal'); return; }
                else { selectComponent(index); return; }
                renderEditor(); renderComponentList(); renderPreview();
            });
            document.getElementById('add_component').addEventListener('click', function () {
                var type = document.getElementById('component_to_add').value;
                state.push({ id: type + '-' + Date.now(), type: type, label: definitions[type], visible: true, attributes: defaultAttributes(type) });
                selectComponent(state.length - 1);
            });
            document.getElementById('component_color').addEventListener('input', function () {
                document.getElementById('component_color_hex').value = this.value;
            });
            document.getElementById('component_color_hex').addEventListener('input', function () {
                var value = String(this.value || '').trim();
                if (/^[0-9a-f]{6}$/i.test(value)) {
                    value = '#' + value;
                    this.value = value;
                }
                if (/^#[0-9a-f]{6}$/i.test(value)) document.getElementById('component_color').value = value;
            });
            editorFields.addEventListener('input', function () { syncFormToState(); renderComponentList(); renderPreview(); selectedLabel.textContent = state[selectedIndex] ? state[selectedIndex].label : ''; });
            editorFields.addEventListener('change', function () { syncFormToState(); renderEditor(); renderComponentList(); renderPreview(); });
            document.getElementById('letterhead_mode').addEventListener('change', function () {
                document.querySelector('.letterhead-image-fields').classList.toggle('is-disabled', this.value !== 'image'); renderPreview();
            });
            Array.prototype.forEach.call(document.querySelectorAll('input[type="file"]'), function (input) {
                input.addEventListener('change', function () {
                    var hint = document.querySelector('.file-name-hint[data-for="' + input.id + '"]');
                    if (hint && input.files.length) hint.textContent = 'File baru: ' + input.files[0].name;
                    if ((input.id === 'component_logo_1_file' || input.id === 'component_logo_2_file') && input.files.length && window.FileReader) {
                        var reader = new FileReader();
                        reader.onload = function (event) {
                            if (input.id === 'component_logo_1_file') previewData.logo1 = event.target.result;
                            if (input.id === 'component_logo_2_file') previewData.logo2 = event.target.result;
                            renderPreview();
                        };
                        reader.readAsDataURL(input.files[0]);
                    }
                });
            });
            document.getElementById('surat_kontrak_template_form').addEventListener('submit', function () {
                if (state[selectedIndex]) syncFormToState();
                if (window.tinymce && tinymce.get('body_html')) tinymce.get('body_html').save();
                document.getElementById('components_payload').value = JSON.stringify(state);
            });
            if (window.tinymce) {
                tinymce.init({ selector: '#body_html', height: 260, menubar: false, plugins: 'lists link', toolbar: 'undo redo | bold italic underline | bullist numlist | removeformat', setup: function (editor) { editor.on('input change keyup undo redo', function () { renderPreview(); }); } });
            }
            document.querySelector('.letterhead-image-fields').classList.toggle('is-disabled', document.getElementById('letterhead_mode').value !== 'image');
            state = state.map(ensureComponent);
            renderEditor(); renderComponentList(); renderPreview();
        }());
    </script>
@endsection
