<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_general_ledger_backfill_reviews')) {
            Schema::table('accounting_general_ledger_backfill_reviews', function (Blueprint $table) {
                if (! Schema::hasColumn('accounting_general_ledger_backfill_reviews', 'location_id')) {
                    $table->unsignedInteger('location_id')->nullable()->index('aglbr_location_index');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_reviews', 'mapping_key')) {
                    $table->string('mapping_key', 64)->nullable()->index('aglbr_mapping_key_index');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_reviews', 'transaction_kind')) {
                    $table->string('transaction_kind', 24)->nullable()->index('aglbr_transaction_kind_index');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_reviews', 'main_account_id')) {
                    $table->unsignedInteger('main_account_id')->nullable()->index('aglbr_main_account_index');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_reviews', 'counterpart_main_account_id')) {
                    $table->unsignedInteger('counterpart_main_account_id')->nullable()->index('aglbr_counterpart_account_index');
                }
                if (! Schema::hasColumn('accounting_general_ledger_backfill_reviews', 'retry_batch_id')) {
                    $table->unsignedBigInteger('retry_batch_id')->nullable();
                }
            });

            $this->backfillMappingScope();
        }

        if (! Schema::hasTable('accounting_general_ledger_vat_accounts')) {
            Schema::create('accounting_general_ledger_vat_accounts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('business_id');
                $table->string('direction', 10);
                $table->unsignedBigInteger('accounting_account_id');
                $table->unsignedInteger('created_by')->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'direction'], 'aglvata_business_direction_unique');
                $table->index(['business_id', 'accounting_account_id'], 'aglvata_business_account_index');
            });
        }

        if (! Schema::hasTable('accounting_general_ledger_backfill_retry_batches')) {
            Schema::create('accounting_general_ledger_backfill_retry_batches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('created_by');
                $table->string('status', 24)->default('queued');
                $table->unsignedInteger('total_items')->default(0);
                $table->unsignedInteger('processed_items')->default(0);
                $table->unsignedInteger('resolved_items')->default(0);
                $table->unsignedInteger('failed_items')->default(0);
                $table->text('last_message')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'status', 'id'], 'aglrrb_business_status_index');
            });
        }

        if (! Schema::hasTable('accounting_general_ledger_backfill_retry_items')) {
            Schema::create('accounting_general_ledger_backfill_retry_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('batch_id');
                $table->unsignedBigInteger('review_id');
                $table->string('status', 24)->default('queued');
                $table->text('message')->nullable();
                $table->timestamps();
                $table->unique(['batch_id', 'review_id'], 'aglrr_items_batch_review_unique');
                $table->index(['batch_id', 'status', 'id'], 'aglrr_items_batch_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_general_ledger_backfill_retry_items');
        Schema::dropIfExists('accounting_general_ledger_backfill_retry_batches');
        Schema::dropIfExists('accounting_general_ledger_vat_accounts');

        if (Schema::hasTable('accounting_general_ledger_backfill_reviews')) {
            Schema::table('accounting_general_ledger_backfill_reviews', function (Blueprint $table) {
                foreach (['location_id', 'mapping_key', 'transaction_kind', 'main_account_id', 'counterpart_main_account_id', 'retry_batch_id'] as $column) {
                    if (Schema::hasColumn('accounting_general_ledger_backfill_reviews', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function backfillMappingScope(): void
    {
        DB::table('accounting_general_ledger_backfill_reviews')
            ->where(function ($query) {
                $query->whereNull('mapping_key')->orWhereNull('transaction_kind');
            })
            ->orderBy('id')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    $context = json_decode((string) ($item->source_context ?? '[]'), true);
                    if (! is_array($context)) {
                        $context = [];
                    }

                    $type = (string) ($context['transaction_type'] ?? '');
                    $mappingKey = null;
                    if ($item->source_record_type === 'App\\Transaction') {
                        $mappingKey = $type === 'sell' ? 'sale' : ($type === 'purchase' ? 'purchases' : ($type === 'expense'
                            ? (! empty($context['expense_category_id']) ? 'expense_'.(int) $context['expense_category_id'] : 'expense')
                            : null));
                    } elseif ($item->source_record_type === 'App\\TransactionPayment') {
                        $mappingKey = in_array($type, ['sell', 'purchase'], true) ? $type.'_payment' : null;
                    }

                    DB::table('accounting_general_ledger_backfill_reviews')->where('id', $item->id)->update([
                        'location_id' => empty($context['location_id']) ? null : (int) $context['location_id'],
                        'mapping_key' => $mappingKey,
                        'transaction_kind' => $type !== '' ? $type : null,
                        'main_account_id' => empty($context['payment_account_id'])
                            ? (empty($context['account_id']) ? null : (int) $context['account_id'])
                            : (int) $context['payment_account_id'],
                        'counterpart_main_account_id' => empty($context['counterpart_account_id'])
                            ? null
                            : (int) $context['counterpart_account_id'],
                    ]);
                }
            });
    }
};
