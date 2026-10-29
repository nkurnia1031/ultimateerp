<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalAlignedCoaProvisioner;

return new class extends Migration
{
    public function up()
    {
        $provisioner = app(JournalAlignedCoaProvisioner::class);

        DB::table('business')
            ->orderBy('id')
            ->chunk(100, function ($businesses) use ($provisioner) {
                foreach ($businesses as $business) {
                    $result = $provisioner->provisionBusiness($business->id);
                    $mappingSkipReasons = [];
                    foreach ($result['mapping_skips'] as $mappingSkip) {
                        $mappingSkipReasons[$mappingSkip['reason']] = isset($mappingSkipReasons[$mappingSkip['reason']])
                            ? $mappingSkipReasons[$mappingSkip['reason']] + 1
                            : 1;
                    }

                    \Log::info('Journal-aligned CoA provisioning completed.', [
                        'business_id' => (int) $business->id,
                        'created' => $result['created'],
                        'reused' => $result['reused'],
                        'crosswalks_created' => $result['crosswalks_created'],
                        'mapping_skip_reasons' => $mappingSkipReasons,
                        'conflicts' => count($result['conflicts']),
                        'skipped' => $result['skipped'],
                    ]);
                }
            });
    }

    /**
     * Provisioned accounts may be referenced by journal postings after deploy.
     * Keep this migration forward-only so rollback cannot remove live CoA rows.
     */
    public function down()
    {
        // Intentionally no-op.
    }
};
