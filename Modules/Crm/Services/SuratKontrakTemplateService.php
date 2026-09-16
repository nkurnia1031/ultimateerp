<?php

namespace Modules\Crm\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Crm\Entities\SuratKontrakTemplate;

class SuratKontrakTemplateService
{
    public const SCHEMA_VERSION = '1.1';

    public function getForBusiness($business_id, $include_inactive = true)
    {
        $query = SuratKontrakTemplate::forBusiness($business_id)
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('name');

        if (! $include_inactive) {
            $query->where('is_active', 1);
        }

        return $query->get();
    }

    public function getActiveForBusiness($business_id)
    {
        return SuratKontrakTemplate::forBusiness($business_id)
            ->where('is_active', 1)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();
    }

    public function defaultBodyHtml()
    {
        return '<ol>' .
            '<li>Pihak Kedua wajib melaksanakan pekerjaan sesuai standar operasional.</li>' .
            '<li>Pihak Kedua bertanggung jawab atas kelancaran pelaksanaan pekerjaan.</li>' .
            '<li>Segala hal teknis akan dikoordinasikan antara kedua belah pihak.</li>' .
            '<li>Setiap pembayaran atas pekerjaan yang dilaksanakan oleh Pihak Kedua akan dikenakan potongan Pajak Penghasilan (PPh) Pasal 23 sesuai dengan ketentuan perpajakan yang berlaku.</li>' .
            '</ol>';
    }

    /**
     * Template kop bawaan yang dapat disalin ke business mana pun.
     *
     * Header pada definisi ini sengaja tidak memakai placeholder business.
     * Setelah disalin, komponen tetap dapat diedit melalui editor template,
     * sedangkan isi kontraknya tetap memakai komponen operasional standar.
     */
    public function builtinTemplates()
    {
        return [
            'cv-bm-transport' => [
                'key' => 'cv-bm-transport',
                'name' => 'Kop CV BM Transport (Bawaan)',
                'description' => 'Kop CV BM Transport dengan logo, judul, subjudul, dan kontak tetap seperti referensi.',
                'title' => 'CV BM TRANSPORT',
                'subtitle' => 'JASA ANGKUTAN & CONTRACTOR',
                'contact' => 'JL. JERUK No. 48 A TELP. (0765) 38552 E-mail: cv.bmtransport@ymail.com – DUMAI',
                'logo_asset' => 'images/surat_kontrak_builtin/bmt-logo.png',
                'components' => $this->builtinLetterheadComponents(
                    'CV BM TRANSPORT',
                    'JASA ANGKUTAN & CONTRACTOR',
                    'JL. JERUK No. 48 A TELP. (0765) 38552 E-mail: cv.bmtransport@ymail.com – DUMAI'
                ),
                'body_html' => $this->defaultBodyHtml(),
            ],
            'pt-bintang-makmur-terus' => [
                'key' => 'pt-bintang-makmur-terus',
                'name' => 'Kop PT Bintang Makmur Terus (Bawaan)',
                'description' => 'Kop PT Bintang Makmur Terus dengan logo, judul, subjudul, dan kontak tetap seperti referensi.',
                'title' => 'PT. BINTANG MAKMUR TERUS',
                'subtitle' => 'JASA ANGKUTAN & CONTRACTOR',
                'contact' => 'JL. JERUK No. 48 A TELP. 0852-6557-3888 E-mail: bintangmakmurterus@gmail.com – DUMAI',
                'logo_asset' => 'images/surat_kontrak_builtin/bmt-logo.png',
                'components' => $this->builtinLetterheadComponents(
                    'PT. BINTANG MAKMUR TERUS',
                    'JASA ANGKUTAN & CONTRACTOR',
                    'JL. JERUK No. 48 A TELP. 0852-6557-3888 E-mail: bintangmakmurterus@gmail.com – DUMAI'
                ),
                'body_html' => $this->defaultBodyHtml(),
            ],
        ];
    }

    public function getBuiltinTemplate($key)
    {
        return $this->builtinTemplates()[$key] ?? null;
    }

    private function builtinLetterheadComponents($title, $subtitle, $contact)
    {
        $components = [
            [
                'id' => 'builtin-logo-1',
                'type' => 'logo',
                'label' => 'Logo BMT bawaan',
                'visible' => true,
                'attributes' => [
                    'source' => 'logo_1',
                    'width' => 100,
                    'align' => 'center',
                    'max_height' => 118,
                    'layout_column' => 'left',
                    // Logo membentang melewati judul dan subjudul. Grid akan
                    // menempatkan ruang sisa di bawah subjudul sehingga jarak
                    // antar teks tetap rapat, sementara garis tetap berada di
                    // bawah area logo.
                    'row_span' => 3,
                    'margin_bottom' => 0,
                ],
            ],
            [
                'id' => 'builtin-title',
                'type' => 'text',
                'label' => 'Nama perusahaan bawaan',
                'visible' => true,
                'attributes' => [
                    'text' => $title,
                    'width' => 100,
                    'align' => 'center',
                    'font_size' => 38,
                    'font_weight' => '400',
                    'font_family' => 'Georgia',
                    'color' => '#e85d65',
                    'layout_column' => 'center',
                    'margin_bottom' => 0,
                ],
            ],
            [
                'id' => 'builtin-subtitle',
                'type' => 'text',
                'label' => 'Bidang usaha bawaan',
                'visible' => true,
                'attributes' => [
                    'text' => $subtitle,
                    'width' => 100,
                    'align' => 'center',
                    'font_size' => 25,
                    'font_weight' => '400',
                    'font_family' => 'Georgia',
                    'color' => '#5d78ab',
                    'layout_column' => 'center',
                    'margin_bottom' => 0,
                ],
            ],
            [
                'id' => 'builtin-separator-top',
                'type' => 'separator',
                'label' => 'Garis atas kop bawaan',
                'visible' => true,
                'attributes' => [
                    'width' => 100,
                    'color' => '#111111',
                    'layout_column' => 'full',
                    'margin_bottom' => 0,
                ],
            ],
            [
                'id' => 'builtin-contact',
                'type' => 'text',
                'label' => 'Alamat dan kontak bawaan',
                'visible' => true,
                'attributes' => [
                    'text' => $contact,
                    'width' => 100,
                    'align' => 'center',
                    'font_size' => 12,
                    'font_weight' => '700',
                    'font_family' => 'Arial',
                    'color' => '#111111',
                    'layout_column' => 'center',
                    'margin_bottom' => 0,
                ],
            ],
            [
                'id' => 'builtin-separator-bottom',
                'type' => 'separator',
                'label' => 'Garis bawah kop bawaan',
                'visible' => true,
                'attributes' => [
                    'width' => 100,
                    'color' => '#111111',
                    'layout_column' => 'full',
                    'margin_bottom' => 0,
                ],
            ],
        ];

        $in_body = false;
        foreach ($this->defaultComponents() as $component) {
            if (($component['type'] ?? '') === 'document_title') {
                $in_body = true;
            }
            if ($in_body) {
                $components[] = $component;
            }
        }

        return $components;
    }

    public function defaultComponents()
    {
        return [
            [
                'id' => 'logo-1',
                'type' => 'logo',
                'label' => 'Logo 1',
                'visible' => true,
                'attributes' => ['source' => 'logo_1', 'width' => 18, 'align' => 'center', 'max_height' => 112, 'layout_column' => 'left', 'row_span' => 3],
            ],
            [
                'id' => 'logo-2',
                'type' => 'logo',
                'label' => 'Logo 2',
                'visible' => false,
                'attributes' => ['source' => 'logo_2', 'width' => 18, 'align' => 'center', 'max_height' => 112, 'layout_column' => 'right', 'row_span' => 3],
            ],
            [
                'id' => 'business-name',
                'type' => 'text',
                'label' => 'Nama bisnis',
                'visible' => true,
                'attributes' => ['text' => '{{business.name}}', 'width' => 100, 'align' => 'center', 'font_size' => 36, 'font_weight' => '700', 'font_family' => 'Georgia', 'color' => '#e85d65', 'layout_column' => 'center'],
            ],
            [
                'id' => 'business-subtitle',
                'type' => 'text',
                'label' => 'Subjudul/bidang usaha',
                'visible' => true,
                'attributes' => ['text' => 'JASA ANGKUTAN & CONTRACTOR', 'width' => 100, 'align' => 'center', 'font_size' => 24, 'font_weight' => '400', 'font_family' => 'Georgia', 'color' => '#5d78ab', 'layout_column' => 'center'],
            ],
            [
                'id' => 'business-contact',
                'type' => 'contact_info',
                'label' => 'Alamat dan kontak',
                'visible' => true,
                'attributes' => ['text' => "{{business.address}}\n{{business.contact}}", 'width' => 100, 'align' => 'center', 'font_size' => 10, 'font_family' => 'Arial', 'color' => '#111111', 'layout_column' => 'center'],
            ],
            [
                'id' => 'header-separator',
                'type' => 'separator',
                'label' => 'Garis kop surat',
                'visible' => true,
                'attributes' => ['width' => 100, 'color' => '#111111', 'margin_bottom' => 18, 'layout_column' => 'full'],
            ],
            [
                'id' => 'document-title',
                'type' => 'document_title',
                'label' => 'Judul dokumen',
                'visible' => true,
                'attributes' => ['text' => 'SURAT PERJANJIAN KERJA (SPK)', 'align' => 'center', 'font_size' => 16, 'font_weight' => '700'],
            ],
            [
                'id' => 'document-number',
                'type' => 'document_number',
                'label' => 'Nomor dokumen',
                'visible' => true,
                'attributes' => ['label' => 'Nomor', 'align' => 'center', 'font_size' => 12],
            ],
            [
                'id' => 'parties',
                'type' => 'parties',
                'label' => 'Pihak pertama dan kedua',
                'visible' => true,
                'attributes' => ['font_size' => 12, 'margin_bottom' => 18],
            ],
            [
                'id' => 'job-summary',
                'type' => 'job_summary',
                'label' => 'Pekerjaan',
                'visible' => true,
                'attributes' => ['font_size' => 12, 'margin_bottom' => 18],
            ],
            [
                'id' => 'details-table',
                'type' => 'details_table',
                'label' => 'Detail pekerjaan',
                'visible' => true,
                'attributes' => ['heading' => 'DETAIL PEKERJAAN', 'font_size' => 12, 'margin_bottom' => 18],
            ],
            [
                'id' => 'terms',
                'type' => 'rich_text',
                'label' => 'Ketentuan pelaksanaan',
                'visible' => true,
                'attributes' => ['heading' => 'KETENTUAN PELAKSANAAN', 'content_key' => 'body_html', 'font_size' => 12, 'margin_bottom' => 12],
            ],
            [
                'id' => 'closing',
                'type' => 'rich_text',
                'label' => 'Penutup',
                'visible' => true,
                'attributes' => ['text' => 'Demikian SPK ini dibuat dan disepakati bersama.', 'font_size' => 12, 'margin_bottom' => 18],
            ],
            [
                'id' => 'signatures',
                'type' => 'signatures',
                'label' => 'Tanda tangan',
                'visible' => true,
                'attributes' => ['font_size' => 12],
            ],
        ];
    }

    public function componentDefinitions()
    {
        return [
            'logo' => 'Logo',
            'text' => 'Teks',
            'rich_text' => 'Teks kaya',
            'contact_info' => 'Alamat dan kontak',
            'separator' => 'Garis pemisah',
            'document_title' => 'Judul dokumen',
            'document_number' => 'Nomor dokumen',
            'parties' => 'Data para pihak',
            'job_summary' => 'Ringkasan pekerjaan',
            'details_table' => 'Tabel detail pekerjaan',
            'signatures' => 'Tanda tangan',
        ];
    }

    public function normalizeComponents($components)
    {
        if (is_string($components)) {
            $components = json_decode($components, true);
        }

        if (! is_array($components)) {
            return $this->defaultComponents();
        }

        $allowed = array_keys($this->componentDefinitions());
        $alignments = ['left', 'center', 'right'];
        $weights = ['400', '500', '600', '700'];
        $normalized = [];
        $used_ids = [];

        foreach (array_slice($components, 0, 40) as $index => $component) {
            if (! is_array($component) || ! in_array($component['type'] ?? null, $allowed, true)) {
                continue;
            }

            $type = $component['type'];
            $attributes = is_array($component['attributes'] ?? null) ? $component['attributes'] : [];
            $id = preg_replace('/[^a-z0-9-]+/i', '-', (string) ($component['id'] ?? ($type . '-' . ($index + 1))));
            $id = trim($id, '-') ?: ($type . '-' . ($index + 1));
            if (isset($used_ids[$id])) {
                $id .= '-' . ($index + 1);
            }
            $used_ids[$id] = true;

            $default_text = $type === 'contact_info' ? "{{business.address}}\n{{business.contact}}" : '';
            $default_column = $type === 'logo'
                ? (($attributes['source'] ?? '') === 'logo_2' ? 'right' : 'left')
                : ($type === 'separator' ? 'full' : 'center');
            $font_weight = (string) ($attributes['font_weight'] ?? '400');
            $item = [
                'id' => $id,
                'type' => $type,
                'label' => Str::limit(strip_tags((string) ($component['label'] ?? $this->componentDefinitions()[$type])), 120, ''),
                'visible' => filter_var($component['visible'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'attributes' => [
                    'text' => Str::limit((string) ($attributes['text'] ?? $default_text), 5000, ''),
                    'source' => in_array(($attributes['source'] ?? ''), ['logo_1', 'logo_2'], true)
                        ? $attributes['source']
                        : 'logo_1',
                    'content_key' => ($attributes['content_key'] ?? '') === 'body_html' ? 'body_html' : null,
                    'heading' => Str::limit(strip_tags((string) ($attributes['heading'] ?? '')), 190, ''),
                    'label' => Str::limit(strip_tags((string) ($attributes['label'] ?? 'Nomor')), 100, ''),
                    'align' => in_array(($attributes['align'] ?? ''), $alignments, true) ? $attributes['align'] : 'left',
                    'font_size' => $this->number($attributes['font_size'] ?? 12, 8, 48),
                    'font_weight' => in_array($font_weight, $weights, true) ? $font_weight : '400',
                    'font_family' => $this->fontFamilyKey($attributes['font_family'] ?? 'Arial'),
                    'width' => $this->number($attributes['width'] ?? 100, 10, 100),
                    'max_height' => $this->number($attributes['max_height'] ?? ($type === 'logo' ? 112 : 80), 20, 240),
                    'margin_bottom' => $this->number($attributes['margin_bottom'] ?? 8, 0, 64),
                    'color' => $this->color($attributes['color'] ?? '#333333'),
                    'layout_column' => in_array(($attributes['layout_column'] ?? ''), ['left', 'center', 'right', 'full'], true)
                        ? $attributes['layout_column']
                        : $default_column,
                    'row_span' => $this->number($attributes['row_span'] ?? ($type === 'logo' ? 3 : 1), 1, 6),
                ],
            ];

            $normalized[] = $item;
        }

        return ! empty($normalized) ? $normalized : $this->defaultComponents();
    }

    public function sanitizeHtml($html)
    {
        $html = (string) $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/\s+(style|class|id)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/\s+(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\')/i', '', $html);

        return strip_tags($html, '<p><br><strong><b><em><i><u><ol><ul><li><table><thead><tbody><tr><th><td><div><span><hr>');
    }

    public function defaultTemplateData()
    {
        return [
            'name' => 'Template Kop Surat Default',
            'is_active' => true,
            'is_default' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'letterhead_mode' => 'components',
            'components' => $this->defaultComponents(),
            'body_html' => $this->defaultBodyHtml(),
        ];
    }

    public function prepareComponents($template, $business, $contract = null)
    {
        $components = $this->normalizeComponents($template->components ?? null);
        $context = $this->context($business, $contract);
        $body_html = ! empty($template->body_html) ? $template->body_html : $this->defaultBodyHtml();

        return array_map(function ($component) use ($context, $template, $business, $body_html) {
            $attributes = $component['attributes'];
            $component['style'] = $this->style($attributes);
            $component_text = $attributes['text'] ?? '';
            if ($component['type'] === 'contact_info' && trim((string) $component_text) === '') {
                $component_text = "{{business.address}}\n{{business.contact}}";
            }
            $component['text_value'] = $this->replaceTextPlaceholders($component_text, $context);
            $rich_text = ($attributes['content_key'] ?? null) === 'body_html'
                ? $body_html
                : ($attributes['text'] ?? '');
            $component['html_value'] = $this->sanitizeHtml($this->replacePlaceholders($rich_text, $context));
            $component['asset_url'] = null;

            if ($component['type'] === 'logo') {
                $file = $attributes['source'] === 'logo_2' ? $template->logo_2 : $template->logo_1;
                $folder = 'surat_kontrak_logos';
                if (! empty($file) && file_exists(public_path('uploads/' . $folder . '/' . $file))) {
                    $component['asset_url'] = asset('uploads/' . $folder . '/' . rawurlencode($file));
                }

                // Komponen logo bersifat opsional. Jangan sisakan kolom kosong
                // apabila file belum tersedia di business/template aktif.
                if (empty($component['asset_url'])) {
                    $component['visible'] = false;
                }
            }

            if ($component['type'] === 'text' && empty($component['text_value'])) {
                $component['visible'] = false;
            }

            return $component;
        }, $components);
    }

    public function context($business, $contract = null)
    {
        $location = $business->locations->first();
        $address = $location ? collect([
            $location->landmark,
            $location->city,
            $location->state,
            $location->zip_code,
            $location->country,
        ])->filter(function ($value) {
            return trim((string) $value) !== '';
        })->implode(', ') : '';
        $contact = $location ? collect([
            $location->mobile ? 'Tel. ' . $location->mobile : null,
            $location->alternate_number ? 'Tel. ' . $location->alternate_number : null,
            $location->email ? 'Email: ' . $location->email : null,
            $location->website,
        ])->filter()->implode(' | ') : '';

        $get = function ($key, $fallback = '-') use ($contract) {
            if (is_array($contract)) {
                $value = $contract[$key] ?? null;
            } else {
                $value = $contract ? ($contract->{$key} ?? null) : null;
            }

            return ($value === null || $value === '') ? $fallback : (string) $value;
        };

        return [
            'business.name' => (string) $business->name,
            'business.address' => $address,
            'business.contact' => $contact,
            'party1.name' => (string) $business->name,
            'party1.title' => 'Direktur',
            'party1.address' => $address,
            'party2.name' => $get('supplier_business_name', $get('contact_name', $get('name'))),
            'party2.title' => $get('jabatan_pihak_2'),
            'party2.address' => $get('address_line_1', $get('address_line_2')),
            'contract.nomor' => $get('nomor'),
            'contract.pekerjaan' => $get('pekerjaan'),
            'contract.kapal_mv' => $get('kapal_mv'),
            'contract.tonase' => $get('tonase'),
            'contract.lokasi_muat' => $get('lokasi_muat'),
            'contract.lokasi_bongkar' => $get('lokasi_bongkar'),
            'contract.jangka_waktu' => $get('jangka_waktu'),
            'contract.mulai_kerja' => $this->formatDate($get('mulai_kerja', '')),
            'contract.selesai' => $this->formatDate($get('selesai', '')),
            'contract.period' => $this->formatDate($get('mulai_kerja', '')) . ' s/d ' . $this->formatDate($get('selesai', '')),
        ];
    }

    public function replacePlaceholders($text, array $context)
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function ($matches) use ($context) {
            $value = $context[$matches[1]] ?? '';

            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }, (string) $text);
    }

    public function replaceTextPlaceholders($text, array $context)
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function ($matches) use ($context) {
            return (string) ($context[$matches[1]] ?? '');
        }, (string) $text);
    }

    public function style(array $attributes)
    {
        $styles = [
            'text-align:' . ($attributes['align'] ?? 'left'),
            'font-size:' . $this->number($attributes['font_size'] ?? 12, 8, 48) . 'px !important',
            'font-weight:' . ($attributes['font_weight'] ?? '400') . ' !important',
            'font-family:' . $this->fontFamilyCss($attributes['font_family'] ?? 'Arial') . ' !important',
            'color:' . $this->color($attributes['color'] ?? '#333333') . ' !important',
            'width:' . $this->number($attributes['width'] ?? 100, 10, 100) . '%',
            'margin-bottom:' . $this->number($attributes['margin_bottom'] ?? 8, 0, 64) . 'px',
        ];

        return implode(';', $styles) . ';';
    }

    private function number($value, $min, $max)
    {
        $value = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $value));
    }

    private function color($value)
    {
        return preg_match('/^#[0-9a-f]{6}$/i', (string) $value) ? $value : '#333333';
    }

    private function fontFamilyKey($value)
    {
        $allowed = ['Arial', 'Georgia', 'Times New Roman', 'Verdana'];

        return in_array((string) $value, $allowed, true) ? (string) $value : 'Arial';
    }

    private function fontFamilyCss($value)
    {
        return [
            'Arial' => 'Arial, sans-serif',
            'Georgia' => 'Georgia, serif',
            'Times New Roman' => "'Times New Roman', Times, serif",
            'Verdana' => 'Verdana, sans-serif',
        ][$this->fontFamilyKey($value)];
    }

    private function formatDate($value)
    {
        if (empty($value) || $value === '-') {
            return '-';
        }

        try {
            return Carbon::parse($value)->format('d F Y');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }
}
