<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AddLogo1ToSuratKontrakTemplatesTable extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('surat_kontrak_templates', 'logo_1')) {
            Schema::table('surat_kontrak_templates', function (Blueprint $table) {
                $table->string('logo_1')->nullable()->after('is_default');
            });
        }

        // Existing templates keep their previous appearance, but receive a
        // physically copied file so future business-logo changes do not affect it.
        $templates = DB::table('surat_kontrak_templates as templates')
            ->join('business as businesses', 'businesses.id', '=', 'templates.business_id')
            ->whereNull('templates.logo_1')
            ->whereNotNull('businesses.logo')
            ->select('templates.id', 'businesses.logo')
            ->get();

        $source_dir = public_path('uploads/business_logos');
        $target_dir = public_path('uploads/surat_kontrak_logos');
        if (! File::isDirectory($target_dir)) {
            File::makeDirectory($target_dir, 0755, true);
        }

        foreach ($templates as $template) {
            $source_name = basename((string) $template->logo);
            $source_path = $source_dir . DIRECTORY_SEPARATOR . $source_name;
            if ($source_name === '' || ! File::exists($source_path)) {
                continue;
            }

            $target_name = 'template_' . $template->id . '_logo1_' . $source_name;
            $target_path = $target_dir . DIRECTORY_SEPARATOR . $target_name;
            if (! File::exists($target_path)) {
                File::copy($source_path, $target_path);
            }
            DB::table('surat_kontrak_templates')
                ->where('id', $template->id)
                ->update(['logo_1' => $target_name]);
        }
    }

    public function down()
    {
        if (Schema::hasColumn('surat_kontrak_templates', 'logo_1')) {
            Schema::table('surat_kontrak_templates', function (Blueprint $table) {
                $table->dropColumn('logo_1');
            });
        }
    }
}
