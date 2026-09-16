<?php

namespace Modules\Crm\Http\Controllers;

use App\Business;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Crm\Entities\SuratKontrak;
use Modules\Crm\Entities\SuratKontrakTemplate;
use Modules\Crm\Services\SuratKontrakTemplateService;

class SuratKontrakTemplateController extends Controller
{
    protected $moduleUtil;

    protected $commonUtil;

    protected $templateService;

    public function __construct(ModuleUtil $moduleUtil, Util $commonUtil, SuratKontrakTemplateService $templateService)
    {
        $this->moduleUtil = $moduleUtil;
        $this->commonUtil = $commonUtil;
        $this->templateService = $templateService;
    }

    public function index()
    {
        $business_id = $this->assertAccess();
        $templates = $this->templateService->getForBusiness($business_id);
        $builtins = $this->templateService->builtinTemplates();

        return view('crm::surat_kontrak.template.index', compact('templates', 'builtins'));
    }

    public function copyBuiltin($key)
    {
        $business_id = $this->assertAccess();
        $builtin = $this->templateService->getBuiltinTemplate($key);

        abort_if(empty($builtin), 404, 'Template kop bawaan tidak ditemukan.');

        $copied_logo = null;

        try {
            DB::beginTransaction();

            $copied_logo = $this->copyBuiltinLogo($builtin);
            $template = new SuratKontrakTemplate();
            $template->business_id = $business_id;
            $template->created_by = auth()->id();
            $template->name = $builtin['name'];
            $template->letterhead_mode = 'components';
            $template->components = $this->templateService->normalizeComponents($builtin['components']);
            $template->body_html = $this->templateService->sanitizeHtml($builtin['body_html']);
            $template->logo_1 = $copied_logo;
            $template->is_active = false;
            $template->is_default = false;
            $template->schema_version = SuratKontrakTemplateService::SCHEMA_VERSION;
            $template->save();

            DB::commit();

            return redirect()->route('crm.surat-kontrak-templates.edit', $template->id)->with('status', [
                'success' => 1,
                'msg' => 'Template bawaan berhasil disalin. Periksa dan aktifkan jika sudah sesuai.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->deleteUploadedFile($copied_logo, 'surat_kontrak_logos');
            \Log::error('Gagal menyalin template kop bawaan: ' . $e->getMessage(), ['exception' => $e, 'builtin' => $key]);

            return back()->withErrors(['template' => 'Template bawaan tidak dapat disalin. Periksa file logo bawaan dan coba lagi.']);
        }
    }

    public function create()
    {
        $business_id = $this->assertAccess();
        $template = new SuratKontrakTemplate($this->templateService->defaultTemplateData());
        $template->components = $this->templateService->defaultComponents();
        $business = Business::with('locations')->findOrFail($business_id);

        return view('crm::surat_kontrak.template.form', [
            'template' => $template,
            'business' => $business,
            'component_definitions' => $this->templateService->componentDefinitions(),
        ]);
    }

    public function store(Request $request)
    {
        $business_id = $this->assertAccess();
        $data = $this->validatedData($request);

        try {
            DB::beginTransaction();
            $template = new SuratKontrakTemplate();
            $template->business_id = $business_id;
            $template->created_by = auth()->id();
            $this->saveTemplate($template, $data, $request);
            DB::commit();

            return redirect()->route('crm.surat-kontrak-templates.index')->with('status', [
                'success' => 1,
                'msg' => 'Template kop surat berhasil dibuat.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Gagal membuat template surat kontrak: ' . $e->getMessage(), ['exception' => $e]);

            return back()->withInput()->withErrors(['template' => 'Template tidak dapat disimpan. Periksa data dan coba lagi.']);
        }
    }

    public function edit($id)
    {
        $business_id = $this->assertAccess();
        $template = SuratKontrakTemplate::forBusiness($business_id)->findOrFail($id);
        $business = Business::with('locations')->findOrFail($business_id);

        return view('crm::surat_kontrak.template.form', [
            'template' => $template,
            'business' => $business,
            'component_definitions' => $this->templateService->componentDefinitions(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $business_id = $this->assertAccess();
        $template = SuratKontrakTemplate::forBusiness($business_id)->findOrFail($id);
        $data = $this->validatedData($request);

        try {
            DB::beginTransaction();
            $this->saveTemplate($template, $data, $request);
            $template->updated_by = auth()->id();
            $template->save();
            DB::commit();

            return redirect()->route('crm.surat-kontrak-templates.index')->with('status', [
                'success' => 1,
                'msg' => 'Template kop surat berhasil diperbarui.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Gagal memperbarui template surat kontrak: ' . $e->getMessage(), ['exception' => $e]);

            return back()->withInput()->withErrors(['template' => 'Template tidak dapat diperbarui. Periksa data dan coba lagi.']);
        }
    }

    public function destroy($id)
    {
        $business_id = $this->assertAccess();
        $template = SuratKontrakTemplate::forBusiness($business_id)->findOrFail($id);

        if ($template->is_default) {
            return back()->withErrors(['template' => 'Template default tidak dapat dihapus. Aktifkan template lain sebagai default terlebih dahulu.']);
        }

        $this->deleteUploadedFile($template->logo_2, 'surat_kontrak_logos');
        $this->deleteUploadedFile($template->logo_1, 'surat_kontrak_logos');
        $this->deleteUploadedFile($template->letterhead_image, 'surat_kontrak_logos');
        $template->delete();

        return redirect()->route('crm.surat-kontrak-templates.index')->with('status', [
            'success' => 1,
            'msg' => 'Template kop surat berhasil dihapus.',
        ]);
    }

    public function setDefault($id)
    {
        $business_id = $this->assertAccess();
        $template = SuratKontrakTemplate::forBusiness($business_id)->findOrFail($id);

        DB::transaction(function () use ($business_id, $template) {
            SuratKontrakTemplate::forBusiness($business_id)->update(['is_default' => 0]);
            $template->is_default = true;
            $template->is_active = true;
            $template->updated_by = auth()->id();
            $template->save();
        });

        return back()->with('status', [
            'success' => 1,
            'msg' => 'Template default berhasil diubah.',
        ]);
    }

    public function preview($id)
    {
        $business_id = $this->assertAccess(false);
        $template = SuratKontrakTemplate::forBusiness($business_id)->findOrFail($id);
        $business = Business::with('locations')->findOrFail($business_id);
        $sample = new SuratKontrak([
            'nomor' => '001/SPK/DEMO/2026',
            'supplier_business_name' => 'Contoh Customer',
            'jabatan_pihak_2' => 'Penanggung Jawab',
            'address_line_1' => 'Alamat customer contoh',
            'pekerjaan' => 'Pengangkutan Cangkang',
            'kapal_mv' => 'MV. Contoh',
            'tonase' => '1.000 Ton',
            'lokasi_muat' => 'Lokasi Muat Contoh',
            'lokasi_bongkar' => 'Lokasi Bongkar Contoh',
            'jangka_waktu' => '30 Hari',
            'mulai_kerja' => now()->format('Y-m-d'),
            'selesai' => now()->addDays(30)->format('Y-m-d'),
        ]);
        $rendered_components = $this->templateService->prepareComponents($template, $business, $sample);

        return view('crm::surat_kontrak.template.preview', compact('template', 'business', 'sample', 'rendered_components'));
    }

    private function assertAccess($manage = true)
    {
        $business_id = (int) request()->session()->get('user.business_id');
        $has_module = auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module');
        $is_admin = $this->commonUtil->is_admin(auth()->user(), $business_id);
        $has_permission = auth()->user()->can('crm.access_surat_kontrak_template');

        if (! $has_module || ($manage && ! auth()->user()->can('superadmin') && ! $is_admin && ! $has_permission)) {
            abort(403, 'Unauthorized action.');
        }

        return $business_id;
    }

    private function validatedData(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:190',
            'letterhead_mode' => 'required|in:components,image',
            'components' => 'required|json',
            'body_html' => 'nullable|string|max:50000',
            'logo_1' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:2048',
            'logo_2' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:2048',
            'letterhead_image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $data['components'] = $this->templateService->normalizeComponents($data['components']);
        $data['body_html'] = $this->templateService->sanitizeHtml($data['body_html'] ?? '');
        $data['is_active'] = $request->boolean('is_active', true);
        $data['is_default'] = $request->boolean('is_default', false);

        return $data;
    }

    private function saveTemplate(SuratKontrakTemplate $template, array $data, Request $request)
    {
        $template->name = $data['name'];
        $template->letterhead_mode = $data['letterhead_mode'];
        $template->components = $data['components'];
        $template->body_html = $data['body_html'];
        $template->is_active = $data['is_active'];
        $template->schema_version = SuratKontrakTemplateService::SCHEMA_VERSION;

        $has_other_template = SuratKontrakTemplate::forBusiness($template->business_id)
            ->where('id', '!=', $template->id ?: 0)
            ->exists();

        if (! $has_other_template) {
            $data['is_default'] = true;
        }

        if ($data['is_default']) {
            SuratKontrakTemplate::forBusiness($template->business_id)
                ->where('id', '!=', $template->id ?: 0)
                ->update(['is_default' => 0]);
            $template->is_default = true;
            $template->is_active = true;
        } elseif ($template->is_default) {
            $template->is_default = true;
        }

        if ($request->hasFile('logo_1')) {
            $this->deleteUploadedFile($template->logo_1, 'surat_kontrak_logos');
            $template->logo_1 = $this->commonUtil->uploadFile($request, 'logo_1', 'surat_kontrak_logos', 'image');
        } elseif ($request->boolean('remove_logo_1')) {
            $this->deleteUploadedFile($template->logo_1, 'surat_kontrak_logos');
            $template->logo_1 = null;
        }

        if ($request->hasFile('logo_2')) {
            $this->deleteUploadedFile($template->logo_2, 'surat_kontrak_logos');
            $template->logo_2 = $this->commonUtil->uploadFile($request, 'logo_2', 'surat_kontrak_logos', 'image');
        } elseif ($request->boolean('remove_logo_2')) {
            $this->deleteUploadedFile($template->logo_2, 'surat_kontrak_logos');
            $template->logo_2 = null;
        }

        if ($request->hasFile('letterhead_image')) {
            $this->deleteUploadedFile($template->letterhead_image, 'surat_kontrak_logos');
            $template->letterhead_image = $this->commonUtil->uploadFile($request, 'letterhead_image', 'surat_kontrak_logos', 'image');
        } elseif ($request->boolean('remove_letterhead_image')) {
            $this->deleteUploadedFile($template->letterhead_image, 'surat_kontrak_logos');
            $template->letterhead_image = null;
        }

        $template->save();
    }

    private function deleteUploadedFile($file, $folder)
    {
        if (empty($file) || basename($file) !== $file) {
            return;
        }

        $path = public_path('uploads/' . $folder . '/' . $file);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function copyBuiltinLogo(array $builtin)
    {
        $source = public_path($builtin['logo_asset'] ?? '');
        if (! is_file($source)) {
            throw new \RuntimeException('File logo template bawaan tidak tersedia.');
        }

        $directory = public_path('uploads/surat_kontrak_logos');
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Folder penyimpanan logo tidak dapat dibuat.');
        }

        $target_name = 'builtin_' . $builtin['key'] . '_' . Str::lower(Str::random(20)) . '.png';
        $target = $directory . DIRECTORY_SEPARATOR . $target_name;
        if (! File::copy($source, $target)) {
            throw new \RuntimeException('File logo template bawaan tidak dapat disalin.');
        }

        return $target_name;
    }
}
