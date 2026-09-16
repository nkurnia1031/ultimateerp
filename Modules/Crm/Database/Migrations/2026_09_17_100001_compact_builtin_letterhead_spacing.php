<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CompactBuiltinLetterheadSpacing extends Migration
{
    public function up()
    {
        $this->updateBuiltinLogoRowSpan(3, 2);
    }

    public function down()
    {
        $this->updateBuiltinLogoRowSpan(2, 3);
    }

    private function updateBuiltinLogoRowSpan($newSpan, $fromSpan)
    {
        if (! Schema::hasTable('surat_kontrak_templates')) {
            return;
        }

        DB::table('surat_kontrak_templates')
            ->select(['id', 'components'])
            ->orderBy('id')
            ->chunkById(100, function ($templates) use ($newSpan, $fromSpan) {
                foreach ($templates as $template) {
                    $components = json_decode((string) $template->components, true);
                    if (! is_array($components)) {
                        continue;
                    }

                    $changed = false;
                    foreach ($components as &$component) {
                        if (! is_array($component)
                            || ($component['id'] ?? '') !== 'builtin-logo-1'
                            || (int) ($component['attributes']['row_span'] ?? 0) !== $fromSpan) {
                            continue;
                        }

                        $component['attributes']['row_span'] = $newSpan;
                        $changed = true;
                    }
                    unset($component);

                    if ($changed) {
                        DB::table('surat_kontrak_templates')
                            ->where('id', $template->id)
                            ->update([
                                'components' => json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ]);
                    }
                }
            });
    }
}
