<?php

namespace Modules\Accounting\Http\Controllers;

use App\Account;
use App\AccountTransaction;
use App\Contact;
use App\Transaction;
use App\TransactionPayment;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Entities\AccountingAccTransMapping;
use Modules\Accounting\Jobs\AnalyzeJournalImportJob;
use Modules\Accounting\Jobs\ProcessJournalImportJob;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\JournalImportInvoiceService;
use Modules\Accounting\Services\JournalImportQueueService;
use Modules\Accounting\Services\JournalImportCancellationService;
use Modules\Accounting\Services\JournalTaxReconciliationService;
use Modules\Accounting\Utils\AccountingUtil;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Yajra\DataTables\Facades\DataTables;

class JournalEntryController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $util;

    protected $journalImportDraftService;

    protected $journalImportQueueService;

    /**
     * Constructor
     *
     * @param  ProductUtils  $product
     * @return void
     */
    public function __construct(Util $util, ModuleUtil $moduleUtil, AccountingUtil $accountingUtil, JournalImportDraftService $journalImportDraftService, JournalImportQueueService $journalImportQueueService)
    {
        $this->util = $util;
        $this->moduleUtil = $moduleUtil;
        $this->accountingUtil = $accountingUtil;
        $this->journalImportDraftService = $journalImportDraftService;
        $this->journalImportQueueService = $journalImportQueueService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $journal = AccountingAccTransMapping::where('accounting_acc_trans_mappings.business_id', $business_id)
                        ->join('users as u', 'accounting_acc_trans_mappings.created_by', 'u.id')
                        ->where('type', 'journal_entry')
                        ->select(['accounting_acc_trans_mappings.id', 'ref_no', 'operation_date', 'note',
                            DB::raw("CONCAT(COALESCE(u.surname, ''),' ',COALESCE(u.first_name, ''),' ',COALESCE(u.last_name,'')) as added_by"),
                        ]);

            if (! empty(request()->start_date) && ! empty(request()->end_date)) {
                $start = request()->start_date;
                $end = request()->end_date;
                $journal->whereDate('accounting_acc_trans_mappings.operation_date', '>=', $start)
                            ->whereDate('accounting_acc_trans_mappings.operation_date', '<=', $end);
            }

            return Datatables::of($journal)
                ->addColumn(
                    'action', function ($row) {
                        $html = '<div class="btn-group">
                                <button type="button" class="btn btn-info dropdown-toggle btn-xs" 
                                    data-toggle="dropdown" aria-expanded="false">'.
                                    __('messages.actions').
                                    '<span class="caret"></span><span class="sr-only">Toggle Dropdown
                                    </span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-right" role="menu">';
                        if (auth()->user()->can('accounting.view_journal')) {
                            // $html .= '<li>
                            //         <a href="#" data-href="'.action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'show'], [$row->id]).'">
                            //             <i class="fas fa-eye" aria-hidden="true"></i>'.__("messages.view").'
                            //         </a>
                            //         </li>';
                        }

                        if (auth()->user()->can('accounting.edit_journal')) {
                            $html .= '<li>
                                    <a href="'.action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'edit'], [$row->id]).'">
                                        <i class="fas fa-edit"></i>'.__('messages.edit').'
                                    </a>
                                </li>';
                        }

                        if (auth()->user()->can('accounting.delete_journal')) {
                            $html .= '<li>
                                    <a href="#" data-href="'.action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'destroy'], [$row->id]).'" class="delete_journal_button">
                                        <i class="fas fa-trash" aria-hidden="true"></i>'.__('messages.delete').'
                                    </a>
                                    </li>';
                        }

                        $html .= '</ul></div>';

                        return $html;
                    })
                ->rawColumns(['action'])
                ->make(true);
        }

        return view('accounting::journal_entry.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.add_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        return view('accounting::journal_entry.create');
    }

    public function import()
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.add_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $taxReviewCounts = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $business_id)
            ->where('tax_reconciliation_status', 'review')
            ->select('journal_import_id', DB::raw('COUNT(*) as tax_reconciliation_review_count'))
            ->groupBy('journal_import_id');

        $imports = DB::table('accounting_journal_imports as aji')
            ->leftJoin('users as u', 'aji.created_by', '=', 'u.id')
            ->leftJoinSub($taxReviewCounts, 'tax_review_counts', function ($join) {
                $join->on('tax_review_counts.journal_import_id', '=', 'aji.id');
            })
            ->where('aji.business_id', $business_id)
            ->select(
                'aji.*',
                DB::raw("CONCAT(COALESCE(u.surname, ''),' ',COALESCE(u.first_name, ''),' ',COALESCE(u.last_name,'')) as added_by"),
                DB::raw('COALESCE(tax_review_counts.tax_reconciliation_review_count, 0) as tax_reconciliation_review_count')
            )
            ->orderBy('aji.id', 'desc')
            ->limit(25)
            ->get();

        $taxReconciliationReviews = $this->getTaxReconciliationReviews($business_id);

        return view('accounting::journal_entry.import')->with(compact('imports', 'taxReconciliationReviews'));
    }

    public function analyzeImport(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.add_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'journal_file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $file = $request->file('journal_file');
        $file_name = Str::uuid().'.'.$file->getClientOriginalExtension();
        $upload_path = $this->journalImportStorageDirectory();
        $file->move($upload_path, $file_name);
        $path = 'journal_imports/'.$file_name;
        $journal_import_id = DB::table('accounting_journal_imports')->insertGetId([
            'business_id' => $business_id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'queued',
            'last_message' => 'File diterima; menunggu worker queue imports.',
            'created_by' => request()->session()->get('user.id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AnalyzeJournalImportJob::dispatch($journal_import_id);

        return redirect()->action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'import'])
            ->with('status', [
                'success' => 1,
                'msg' => 'File berhasil diupload. Analisa berjalan di background.',
            ]);
    }

    public function reviewImport($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.add_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $business_id)
            ->where('id', $id)
            ->first();

        if (empty($batch) || ! in_array($batch->status, ['analyzed', 'partially_imported', 'failed', 'cancelled'], true)) {
            return redirect()->action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'import'])
                ->with('status', [
                    'success' => 0,
                    'msg' => 'Draft belum siap dibuka atau sedang diproses.',
                ]);
        }

        $analysisPath = ! empty($batch->analysis_path) ? $this->resolveJournalImportFilePath($batch->analysis_path) : null;
        $analysis = $analysisPath && file_exists($analysisPath)
            ? $this->readTemporaryJournalAnalysis($batch->analysis_path)
            : $this->analysisFromJournalDraftRows((int) $batch->id, (int) $business_id, $batch->summary);
        if (empty($analysis['entries'])) {
            if ($batch->status === 'cancelled' && ! empty($batch->file_path)) {
                $filePath = $this->resolveJournalImportFilePath($batch->file_path);
                if (! empty($filePath) && file_exists($filePath)) {
                    $restarted = DB::transaction(function () use ($batch, $business_id) {
                        $lockedBatch = DB::table('accounting_journal_imports')
                            ->where('business_id', $business_id)
                            ->where('id', $batch->id)
                            ->lockForUpdate()
                            ->first();
                        if (empty($lockedBatch) || $lockedBatch->status !== 'cancelled') {
                            return false;
                        }

                        $hasDraftRows = DB::table('accounting_journal_import_rows')
                            ->where('journal_import_id', $lockedBatch->id)
                            ->where('business_id', $business_id)
                            ->exists();
                        if ($hasDraftRows) {
                            return false;
                        }

                        DB::table('accounting_journal_imports')
                            ->where('id', $lockedBatch->id)
                            ->where('business_id', $business_id)
                            ->update([
                                'status' => 'queued',
                                'cancel_requested_at' => null,
                                'cancel_requested_by' => null,
                                'cancelled_at' => null,
                                'cancelled_by' => null,
                                'last_message' => 'Analisis dilanjutkan kembali atas permintaan pengguna.',
                                'error_message' => null,
                                'updated_at' => now(),
                            ]);

                        return true;
                    });

                    if ($restarted) {
                        AnalyzeJournalImportJob::dispatch((int) $batch->id);

                        return redirect()->action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'import'])
                            ->with('status', [
                                'success' => 1,
                                'msg' => 'Analisis dilanjutkan. Buka Pantau progres untuk melihat statusnya.',
                            ]);
                    }
                }
            }

            return redirect()->action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'import'])
                ->with('status', [
                    'success' => 0,
                    'msg' => 'Draft baris tidak tersedia untuk batch ini.',
                ]);
        }
        $this->journalImportDraftService->persistEntries((int) $batch->id, (int) $business_id, $analysis['entries']);
        $path = $batch->file_path;
        $analysis_path = $batch->analysis_path;
        $original_file_name = $batch->file_name;
        $account_options = $this->getAccountingAccountOptions($business_id);
        $main_account_options = $this->getMainAccountOptions($business_id);
        $imports = DB::table('accounting_journal_imports as aji')
            ->leftJoin('users as u', 'aji.created_by', '=', 'u.id')
            ->where('aji.business_id', $business_id)
            ->select(
                'aji.*',
                DB::raw("CONCAT(COALESCE(u.surname, ''),' ',COALESCE(u.first_name, ''),' ',COALESCE(u.last_name,'')) as added_by")
            )
            ->orderBy('aji.id', 'desc')
            ->limit(25)
            ->get();

        $taxReconciliationReviews = $this->getTaxReconciliationReviews($business_id);

        $draftTypeCounts = array_fill_keys($this->journalImportDraftService->sourceTypes(), 0);
        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $business_id)
            ->select('source_type', DB::raw('COUNT(*) as row_count'))
            ->groupBy('source_type')
            ->get()
            ->each(function ($row) use (&$draftTypeCounts) {
                if (array_key_exists($row->source_type, $draftTypeCounts)) {
                    $draftTypeCounts[$row->source_type] = (int) $row->row_count;
                }
            });

        return view('accounting::journal_entry.import')
            ->with(compact('batch', 'analysis', 'path', 'analysis_path', 'imports', 'original_file_name', 'account_options', 'main_account_options', 'taxReconciliationReviews', 'draftTypeCounts'));
    }

    public function draftRows(Request $request, $id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $this->authorizeJournalImportDraftAccess($business_id);

        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $business_id)
            ->where('id', $id)
            ->first();
        if (empty($batch)) {
            abort(404);
        }

        $request->validate([
            'type' => 'required|string',
            'review_filter' => 'nullable|in:all,reviewed,unreviewed',
            'status_filter' => 'nullable|in:all,errors,differences',
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $type = $request->input('type');
        if (! in_array($type, $this->journalImportDraftService->sourceTypes(), true)) {
            return response()->json(['success' => 0, 'msg' => 'Jenis transaksi tidak valid.'], 422);
        }

        $query = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $business_id)
            ->where('source_type', $type);
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($builder) use ($like) {
                $builder->where('source_number', 'like', $like)
                    ->orWhere('entry_data', 'like', $like)
                    ->orWhere('draft_data', 'like', $like)
                    ->orWhere('processing_message', 'like', $like)
                    ->orWhere('processing_error', 'like', $like);
            });
        }
        if ($request->input('status_filter') === 'errors') {
            $query->where(function ($builder) {
                $builder->where('processing_status', 'failed')
                    ->orWhere(function ($messageQuery) {
                        $messageQuery->whereNotNull('processing_error')->where('processing_error', '<>', '');
                    });
            });
        } elseif ($request->input('status_filter') === 'differences') {
            $query->whereIn('reconciliation_status', ['mismatch', 'approved', 'auto_approved']);
        }
        if ($request->input('review_filter') === 'reviewed') {
            $query->where('is_reviewed', 1);
        } elseif ($request->input('review_filter') === 'unreviewed') {
            $query->where('is_reviewed', 0);
        }

        $importOptions = ! empty($batch->import_options) ? json_decode($batch->import_options, true) : [];
        $importOptions = is_array($importOptions) ? $importOptions : [];
        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));
        $rows = $query->orderBy('entry_index')->paginate($perPage);
        $sourceIds = $rows->getCollection()
            ->filter(fn ($row) => ($row->source_record_type ?? null) === \App\Transaction::class && ! empty($row->source_record_id))
            ->pluck('source_record_id')->unique()->values()->all();
        $sourceComponentsById = $sourceIds === [] ? [] : DB::table('transactions')
            ->where('business_id', $business_id)
            ->whereIn('id', $sourceIds)
            ->get(['id', 'total_before_tax', 'tax_amount', 'final_total'])
            ->keyBy('id')->map(fn ($source) => [
                'base' => $source->total_before_tax === null ? null : (float) $source->total_before_tax,
                'tax' => $source->tax_amount === null ? null : (float) $source->tax_amount,
                'total' => $source->final_total === null ? null : (float) $source->final_total,
            ])->all();
        $rows->getCollection()->transform(function ($row) use ($importOptions, $sourceComponentsById) {
            return $this->journalImportDraftRowResponse($row, $importOptions, $sourceComponentsById);
        });

        return response()->json([
            'success' => 1,
            'rows' => $rows->items(),
            'page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    public function updateDraftRow(Request $request, $id, $row_id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $this->authorizeJournalImportDraftAccess($business_id);

        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $business_id)
            ->where('id', $id)
            ->first();
        if (empty($batch)) {
            abort(404);
        }
        if (! in_array($batch->status, ['analyzed', 'partially_imported', 'failed', 'cancelled'], true)) {
            return response()->json(['success' => 0, 'msg' => 'Draft ini sedang diproses atau sudah selesai.'], 409);
        }

        $row = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $business_id)
            ->where('id', $row_id)
            ->first();
        if (empty($row)) {
            abort(404);
        }
        if (in_array($row->processing_status ?? 'pending', ['queued', 'running'], true)) {
            return response()->json(['success' => 0, 'msg' => 'Baris sedang diproses oleh worker.'], 409);
        }

        $validated = $request->validate([
            'source_action' => 'sometimes|in:create,update,link,review,journal',
            'source_record_type' => 'sometimes|nullable|string|max:64',
            'source_record_id' => 'sometimes|nullable|integer|min:1',
            'is_reviewed' => 'sometimes|boolean',
            'draft_data' => 'sometimes|array',
        ]);
        if (($row->processing_status ?? 'pending') === 'completed'
            && (array_key_exists('source_action', $validated)
                || array_key_exists('source_record_type', $validated)
                || array_key_exists('source_record_id', $validated)
                || array_key_exists('draft_data', $validated))) {
            return response()->json(['success' => 0, 'msg' => 'Baris yang sudah selesai tidak dapat diubah.'], 409);
        }

        $entry = json_decode((string) $row->entry_data, true) ?: [];
        $action = $validated['source_action'] ?? $row->source_action;
        $recordType = array_key_exists('source_record_type', $validated)
            ? $validated['source_record_type']
            : $row->source_record_type;
        $recordId = array_key_exists('source_record_id', $validated)
            ? ($validated['source_record_id'] === null ? null : (int) $validated['source_record_id'])
            : ($row->source_record_id === null ? null : (int) $row->source_record_id);

        if (in_array($action, ['create', 'review', 'journal'], true)) {
            $recordType = null;
            $recordId = null;
        }
        if (! $this->journalImportDraftService->isValidSourceDecision($entry, $action, $recordType, $recordId)) {
            return response()->json([
                'success' => 0,
                'msg' => 'Pilihan relasi sumber tidak cocok dengan kandidat yang tersedia.',
            ], 422);
        }
        if ($recordType !== null && $recordId !== null && ! $this->journalImportDraftService->isCurrentSourceRecordAllowed(
            $row->source_type,
            $recordType,
            $recordId,
            $business_id
        )) {
            return response()->json([
                'success' => 0,
                'msg' => 'Sumber transaksi tidak lagi tersedia pada business aktif.',
            ], 422);
        }

        $draftData = array_key_exists('draft_data', $validated)
            ? $this->validateJournalImportDraftData($validated['draft_data'], $business_id, $row->source_type, (int) $batch->id)
            : (json_decode((string) $row->draft_data, true) ?: []);
        $hasReviewMutation = array_key_exists('draft_data', $validated)
            || array_key_exists('source_action', $validated)
            || array_key_exists('source_record_type', $validated)
            || array_key_exists('source_record_id', $validated);
        $reviewed = array_key_exists('is_reviewed', $validated)
            ? (bool) $validated['is_reviewed']
            : ($hasReviewMutation ? false : (bool) $row->is_reviewed);
        $importOptions = ! empty($batch->import_options) ? json_decode($batch->import_options, true) : [];
        $importOptions = is_array($importOptions) ? $importOptions : [];

        DB::table('accounting_journal_import_rows')
            ->where('id', $row->id)
            ->where('business_id', $business_id)
            ->update([
                'source_action' => $action,
                'source_record_type' => $recordType,
                'source_record_id' => $recordId,
                'draft_data' => json_encode($draftData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_reviewed' => $reviewed,
                'reviewed_by' => $reviewed ? (int) $request->session()->get('user.id') : null,
                'reviewed_at' => $reviewed ? now() : null,
                'updated_at' => now(),
            ]);

        if (in_array($row->source_type, ['Sales Invoice', 'Purchase Invoice', 'Expense', 'Receive Payment', 'Purchase Payment', 'Bank Deposit', 'Bank Transfer', 'Bank Withdrawal', 'Reversal', 'Stock Adjustment', 'Stock In/Out', 'Opening Balance'], true)) {
            $updatedRow = DB::table('accounting_journal_import_rows')->where('id', $row->id)->first();
            $serviceClass = $row->source_type === 'Expense'
                ? \Modules\Accounting\Services\JournalImportExpenseService::class
                : (in_array($row->source_type, ['Receive Payment', 'Purchase Payment'], true)
                    ? \Modules\Accounting\Services\JournalImportPaymentService::class
                    : (in_array($row->source_type, ['Bank Deposit', 'Bank Transfer', 'Bank Withdrawal', 'Reversal'], true)
                        ? \Modules\Accounting\Services\JournalImportBankTransactionService::class
                        : (in_array($row->source_type, ['Stock Adjustment', 'Stock In/Out', 'Opening Balance'], true)
                            ? \Modules\Accounting\Services\JournalImportStockService::class
                            : JournalImportInvoiceService::class)));
            $businessTotal = app($serviceClass)->draftTotal(
                $updatedRow,
                $entry,
                $batch,
                $importOptions
            );
            $journalTotal = isset($entry['debit_total']) ? (float) $entry['debit_total'] : null;
            if ($journalTotal === null && ! empty($entry['lines']) && is_array($entry['lines'])) {
                $journalTotal = (float) collect($entry['lines'])
                    ->filter(fn ($line) => strtolower((string) ($line['type'] ?? '')) === 'debit')
                    ->sum(fn ($line) => (float) ($line['debit'] ?? $line['amount'] ?? 0));
            }
            if ($businessTotal !== null && $journalTotal !== null && is_finite($journalTotal)) {
                $this->journalImportQueueService->recordReconciliation(
                    (int) $row->id,
                    $business_id,
                    $businessTotal,
                    $journalTotal
                );
            } else {
                $this->journalImportQueueService->clearReconciliation((int) $row->id, $business_id);
            }
        }

        $updatedRow = DB::table('accounting_journal_import_rows')->where('id', $row->id)->first();

        return response()->json([
            'success' => 1,
            'row' => $this->journalImportDraftRowResponse($updatedRow, $importOptions),
            'msg' => 'Draft tersimpan.',
        ]);
    }

    public function approveDraftDiscrepancy(Request $request, $id, $row_id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $this->authorizeJournalImportDraftAccess($business_id);

        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $business_id)
            ->where('id', $id)
            ->first();
        if (empty($batch)) {
            abort(404);
        }
        if (! in_array($batch->status, ['analyzed', 'partially_imported', 'failed', 'cancelled'], true)) {
            return response()->json(['success' => 0, 'msg' => 'Tunggu antrean selesai sebelum menyetujui selisih.'], 409);
        }

        $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ]);

        $rowExists = DB::table('accounting_journal_import_rows')
            ->where('id', $row_id)
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $business_id)
            ->exists();
        if (! $rowExists) {
            abort(404);
        }

        $row = $this->journalImportQueueService->approveDiscrepancy(
            (int) $row_id,
            $business_id,
            (int) $request->session()->get('user.id'),
            $request->input('reason')
        );

        return response()->json([
            'success' => 1,
            'row' => $this->journalImportDraftRowResponse($row),
            'msg' => 'Persetujuan selisih tersimpan dengan audit.',
        ]);
    }

    public function approveDraftDiscrepancies(Request $request, $id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $this->authorizeJournalImportDraftAccess($business_id);

        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $business_id)
            ->where('id', $id)
            ->first();
        if (empty($batch)) {
            abort(404);
        }
        if (! in_array($batch->status, ['analyzed', 'partially_imported', 'failed', 'cancelled'], true)) {
            return response()->json(['success' => 0, 'msg' => 'Tunggu antrean selesai sebelum menyetujui selisih.'], 409);
        }

        $validated = $request->validate([
            'row_ids' => 'required|array|min:1|max:100',
            'row_ids.*' => 'required|integer|min:1|distinct',
            'reason' => 'required|string|min:5|max:1000',
        ]);
        $rows = $this->journalImportQueueService->approveDiscrepancies(
            (int) $batch->id,
            $business_id,
            (int) $request->session()->get('user.id'),
            $validated['row_ids'],
            $validated['reason']
        );

        $importOptions = ! empty($batch->import_options) ? json_decode($batch->import_options, true) : [];
        $importOptions = is_array($importOptions) ? $importOptions : [];
        $sourceIds = collect($rows)
            ->filter(fn ($row) => ($row->source_record_type ?? null) === \App\Transaction::class && ! empty($row->source_record_id))
            ->pluck('source_record_id')->unique()->values()->all();
        $sourceComponentsById = $sourceIds === [] ? [] : DB::table('transactions')
            ->where('business_id', $business_id)
            ->whereIn('id', $sourceIds)
            ->get(['id', 'total_before_tax', 'tax_amount', 'final_total'])
            ->keyBy('id')->map(fn ($source) => [
                'base' => $source->total_before_tax === null ? null : (float) $source->total_before_tax,
                'tax' => $source->tax_amount === null ? null : (float) $source->tax_amount,
                'total' => $source->final_total === null ? null : (float) $source->final_total,
            ])->all();

        return response()->json([
            'success' => 1,
            'rows' => array_map(
                fn ($row) => $this->journalImportDraftRowResponse($row, $importOptions, $sourceComponentsById),
                $rows
            ),
            'msg' => count($rows).' selisih disetujui dan ditandai ditinjau.',
        ]);
    }

    public function draftOptions(Request $request, $id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $this->authorizeJournalImportDraftAccess($business_id);

        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $business_id)
            ->where('id', $id)
            ->first();
        if (empty($batch)) {
            abort(404);
        }

        $request->validate([
            'kind' => 'required|in:contact,customer,supplier,location,payment_account,transfer_account,reversal_target,payment_method,invoice,expense_category,product,unit',
            'q' => 'nullable|string|max:100',
            'source_type' => 'nullable|in:Journal Entry,Sales Invoice,Purchase Invoice,Expense,Receive Payment,Purchase Payment,Bank Deposit,Bank Transfer,Bank Withdrawal,Stock Adjustment,Stock In/Out,Opening Balance,Reversal',
            'selected_id' => 'nullable|string|max:64',
            'variation_id' => 'nullable|integer|min:1',
        ]);
        $kind = $request->input('kind');
        $sourceType = (string) $request->input('source_type', '');
        $search = trim((string) $request->input('q', ''));
        $selectedId = $request->input('selected_id');

        if ($kind === 'payment_method') {
            $paymentMethods = app(\App\Utils\Util::class)->payment_types(null, false, $business_id);
            unset($paymentMethods['advance']);
            $options = collect($paymentMethods)
                ->filter(function ($label, $method) use ($selectedId, $search) {
                    if ($selectedId !== null && $selectedId !== '') {
                        return (string) $method === (string) $selectedId;
                    }

                    return $search === '' || stripos((string) $label, $search) !== false;
                })
                ->map(fn ($label, $method) => ['id' => (string) $method, 'text' => (string) $label])
                ->values();
        } elseif ($kind === 'invoice') {
            $transactionType = $sourceType === 'Receive Payment' ? 'sell' : ($sourceType === 'Purchase Payment' ? 'purchase' : null);
            $invoiceDraftType = $sourceType === 'Receive Payment' ? 'Sales Invoice' : ($sourceType === 'Purchase Payment' ? 'Purchase Invoice' : null);
            if ($transactionType === null || $invoiceDraftType === null) {
                return response()->json(['results' => []]);
            }

            $invoiceOptions = collect();
            if ($selectedId !== null && preg_match('/^transaction:([1-9][0-9]*)$/', (string) $selectedId, $matches)) {
                $invoiceQuery = DB::table('transactions as source_invoice')
                    ->leftJoin('contacts as source_contact', 'source_contact.id', '=', 'source_invoice.contact_id')
                    ->where('source_invoice.id', (int) $matches[1])
                    ->where('source_invoice.business_id', $business_id)
                    ->where('source_invoice.type', $transactionType)
                    ->where('source_invoice.status', 'final')
                    ->whereNull('source_contact.deleted_at');
                $invoiceOptions = $invoiceQuery->limit(1)->get([
                    'source_invoice.id', 'source_invoice.invoice_no', 'source_invoice.ref_no',
                    'source_invoice.transaction_date', 'source_invoice.final_total', 'source_contact.name as contact_name',
                ])->map(function ($invoice) {
                    $reference = trim((string) ($invoice->invoice_no ?: $invoice->ref_no ?: ''));
                    $text = 'Invoice #'.$reference.' · '.trim((string) $invoice->contact_name).' · '.number_format((float) $invoice->final_total, 2, '.', ',');

                    return ['id' => 'transaction:'.(int) $invoice->id, 'text' => $text];
                });
            } elseif ($selectedId !== null && preg_match('/^draft:([1-9][0-9]*)$/', (string) $selectedId, $matches)) {
                $invoiceRow = DB::table('accounting_journal_import_rows')
                    ->where('id', (int) $matches[1])
                    ->where('journal_import_id', $batch->id)
                    ->where('business_id', $business_id)
                    ->where('source_type', $invoiceDraftType)
                    ->whereIn('source_action', ['create', 'update', 'link'])
                    ->first(['id', 'source_number', 'processing_status']);
                if ($invoiceRow) {
                    $invoiceOptions->push([
                        'id' => 'draft:'.(int) $invoiceRow->id,
                        'text' => $invoiceDraftType.' #'.(string) $invoiceRow->source_number.' · batch · '.$invoiceRow->processing_status,
                    ]);
                }
            } else {
                $invoiceQuery = DB::table('transactions as source_invoice')
                    ->leftJoin('contacts as source_contact', 'source_contact.id', '=', 'source_invoice.contact_id')
                    ->where('source_invoice.business_id', $business_id)
                    ->where('source_invoice.type', $transactionType)
                    ->where('source_invoice.status', 'final')
                    ->whereNull('source_contact.deleted_at');
                if ($search !== '') {
                    $invoiceQuery->where(function ($query) use ($search) {
                        $query->where('source_invoice.invoice_no', 'like', '%'.$search.'%')
                            ->orWhere('source_invoice.ref_no', 'like', '%'.$search.'%')
                            ->orWhere('source_contact.name', 'like', '%'.$search.'%')
                            ->orWhere('source_contact.supplier_business_name', 'like', '%'.$search.'%');
                    });
                } else {
                    $invoiceQuery->whereRaw('1 = 0');
                }
                $invoiceOptions = $invoiceQuery->orderByDesc('source_invoice.transaction_date')->limit(20)->get([
                    'source_invoice.id', 'source_invoice.invoice_no', 'source_invoice.ref_no',
                    'source_invoice.transaction_date', 'source_invoice.final_total', 'source_contact.name as contact_name',
                ])->map(function ($invoice) {
                    $reference = trim((string) ($invoice->invoice_no ?: $invoice->ref_no ?: ''));
                    $text = 'Invoice #'.$reference.' · '.trim((string) $invoice->contact_name).' · '.number_format((float) $invoice->final_total, 2, '.', ',');

                    return ['id' => 'transaction:'.(int) $invoice->id, 'text' => $text];
                });

                if ($search !== '') {
                    $draftInvoices = DB::table('accounting_journal_import_rows')
                        ->where('journal_import_id', $batch->id)
                        ->where('business_id', $business_id)
                        ->where('source_type', $invoiceDraftType)
                        ->whereIn('source_action', ['create', 'update', 'link'])
                        ->where('source_number', 'like', '%'.$search.'%')
                        ->orderBy('entry_index')
                        ->limit(20)
                        ->get(['id', 'source_number', 'processing_status'])
                        ->map(fn ($invoiceRow) => [
                            'id' => 'draft:'.(int) $invoiceRow->id,
                            'text' => $invoiceDraftType.' #'.(string) $invoiceRow->source_number.' · batch · '.$invoiceRow->processing_status,
                        ]);
                    $invoiceOptions = $invoiceOptions->concat($draftInvoices)->take(20)->values();
                }
            }
            $options = $invoiceOptions;
        } elseif (in_array($kind, ['contact', 'customer', 'supplier'], true)) {
            $query = DB::table('contacts')
                ->where('business_id', $business_id)
                ->whereNull('deleted_at');
            if ($kind === 'customer') {
                $query->whereIn('type', ['customer', 'both']);
            } elseif ($kind === 'supplier') {
                $query->whereIn('type', ['supplier', 'both']);
            }
            if ($selectedId) {
                $query->where('id', $selectedId);
            } elseif ($search !== '') {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('supplier_business_name', 'like', '%'.$search.'%')
                        ->orWhere('contact_id', 'like', '%'.$search.'%')
                        ->orWhere('mobile', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            } else {
                $query->whereRaw('1 = 0');
            }
            $options = $query->orderBy('name')->limit(20)->get(['id', 'name', 'supplier_business_name', 'contact_id', 'mobile', 'email'])
                ->map(function ($contact) {
                    return [
                        'id' => (int) $contact->id,
                        'text' => Contact::supplierDisplayName($contact, true),
                    ];
                });
        } elseif ($kind === 'location') {
            $query = DB::table('business_locations')->where('business_id', $business_id)->whereNull('deleted_at');
            if ($selectedId) {
                $query->where('id', $selectedId);
            } elseif ($search !== '') {
                $query->where('name', 'like', '%'.$search.'%');
            } else {
                $query->whereRaw('1 = 0');
            }
            $options = $query->orderBy('name')->limit(20)->get(['id', 'name'])
                ->map(fn ($item) => ['id' => (int) $item->id, 'text' => $item->name]);
        } elseif (in_array($kind, ['payment_account', 'transfer_account'], true)) {
            $query = DB::table('accounts')->where('business_id', $business_id)->where('is_closed', 0)->whereNull('deleted_at');
            if ($selectedId) {
                $query->where('id', $selectedId);
            } elseif ($search !== '') {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')->orWhere('account_number', 'like', '%'.$search.'%');
                });
            } else {
                $query->whereRaw('1 = 0');
            }
            $options = $query->orderBy('name')->limit(20)->get(['id', 'name', 'account_number'])
                ->map(fn ($item) => ['id' => (int) $item->id, 'text' => $item->name.' ('.$item->account_number.')']);
        } elseif ($kind === 'reversal_target') {
            if ($sourceType !== 'Reversal') {
                return response()->json(['results' => []]);
            }
            $query = DB::table('account_transactions as source')
                ->join('accounts as source_account', 'source_account.id', '=', 'source.account_id')
                ->where('source_account.business_id', $business_id)
                ->whereNull('source_account.deleted_at')
                ->whereNull('source.deleted_at')
                ->whereIn('source.sub_type', ['deposit', 'fund_transfer', 'withdrawal'])
                ->whereNotExists(function ($query) use ($business_id) {
                    $query->select(DB::raw(1))
                        ->from('account_transactions as reversal')
                        ->join('accounts as reversal_account', 'reversal_account.id', '=', 'reversal.account_id')
                        ->where('reversal_account.business_id', $business_id)
                        ->whereNull('reversal.deleted_at')
                        ->where('reversal.sub_type', 'reversal')
                        ->whereColumn('reversal.transfer_transaction_id', 'source.id');
                });
            if ($selectedId !== null && $selectedId !== '') {
                $query->where('source.id', $selectedId);
            } elseif ($search !== '') {
                $query->where(function ($query) use ($search) {
                    $query->where('source.reff_no', 'like', '%'.$search.'%')
                        ->orWhere('source.note', 'like', '%'.$search.'%')
                        ->orWhere('source.id', 'like', '%'.$search.'%');
                });
            } else {
                $query->whereRaw('1 = 0');
            }
            $options = $query->orderByDesc('source.operation_date')->limit(20)->get([
                'source.id', 'source.reff_no', 'source.sub_type', 'source.type',
                'source.amount', 'source.operation_date', 'source_account.name as account_name',
            ])->map(function ($item) {
                $reference = trim((string) $item->reff_no);
                $label = 'Transaksi #'.(int) $item->id.' · '.$item->account_name.' · '.$item->sub_type.' · '.number_format((float) $item->amount, 2, '.', ',');
                if ($reference !== '') {
                    $label .= ' · '.$reference;
                }

                return ['id' => (int) $item->id, 'text' => $label];
            });
        } elseif ($kind === 'expense_category') {
            $query = DB::table('expense_categories')->where('business_id', $business_id)->whereNull('deleted_at')->whereNull('parent_id');
            if ($selectedId) {
                $query->where('id', $selectedId);
            } elseif ($search !== '') {
                $query->where('name', 'like', '%'.$search.'%');
            } else {
                $query->whereRaw('1 = 0');
            }
            $options = $query->orderBy('name')->limit(20)->get(['id', 'name'])
                ->map(fn ($item) => ['id' => (int) $item->id, 'text' => $item->name]);
        } elseif ($kind === 'unit') {
            $variationId = (int) $request->input('variation_id', 0);
            $product = DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('v.id', $variationId)
                ->where('p.business_id', $business_id)
                ->where('p.is_inactive', 0)
                ->where('p.enable_stock', 1)
                ->whereNull('p.deleted_at')
                ->whereNull('v.deleted_at')
                ->first(['p.unit_id', 'p.sub_unit_ids']);
            if (! $product) {
                return response()->json(['results' => []]);
            }
            $unitIds = array_values(array_unique(array_merge(
                [(int) $product->unit_id],
                array_map('intval', json_decode((string) $product->sub_unit_ids, true) ?: [])
            )));
            $query = DB::table('units')->where('business_id', $business_id)->whereNull('deleted_at')->whereIn('id', $unitIds);
            if ($selectedId !== null && $selectedId !== '') {
                $query->where('id', $selectedId);
            } elseif ($search !== '') {
                $query->where(function ($query) use ($search) {
                    $query->where('actual_name', 'like', '%'.$search.'%')->orWhere('short_name', 'like', '%'.$search.'%');
                });
            } else {
                $query->whereRaw('1 = 0');
            }
            $options = $query->orderBy('actual_name')->limit(20)->get(['id', 'actual_name', 'short_name'])
                ->map(fn ($item) => ['id' => (int) $item->id, 'text' => trim($item->actual_name.' ('.$item->short_name.')')]);
        } else {
            $query = DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('p.business_id', $business_id)
                ->where('p.is_inactive', 0)
                ->whereNull('v.deleted_at');
            if (in_array($sourceType, ['Stock Adjustment', 'Stock In/Out', 'Opening Balance'], true)) {
                $query->where('p.enable_stock', 1);
            }
            if ($selectedId) {
                $query->where('v.id', $selectedId);
            } elseif ($search !== '') {
                $query->where(function ($query) use ($search) {
                    $query->where('p.name', 'like', '%'.$search.'%')
                        ->orWhere('p.sku', 'like', '%'.$search.'%')
                        ->orWhere('v.name', 'like', '%'.$search.'%')
                        ->orWhere('v.sub_sku', 'like', '%'.$search.'%');
                });
            } else {
                $query->whereRaw('1 = 0');
            }
            $options = $query->orderBy('p.name')->limit(20)->get(['v.id', 'p.name', 'v.name as variation_name', 'v.sub_sku'])
                ->map(function ($item) {
                    $text = $item->name;
                    if (! empty($item->variation_name)) {
                        $text .= ' — '.$item->variation_name;
                    }
                    if (! empty($item->sub_sku)) {
                        $text .= ' ('.$item->sub_sku.')';
                    }

                    return ['id' => (int) $item->id, 'text' => $text];
                });
        }

        return response()->json(['results' => $options->values()]);
    }

    private function authorizeJournalImportDraftAccess(int $business_id): void
    {
        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! auth()->user()->can('accounting.add_journal')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function journalImportDraftRowResponse($row, array $importOptions = [], ?array $sourceComponentsById = null): array
    {
        $response = $this->journalImportDraftService->rowResponse($row);
        $entry = json_decode((string) $row->entry_data, true) ?: [];
        $components = app(\Modules\Accounting\Services\JournalImportAccountComponentService::class)
            ->groupJournalLines($entry, (int) $row->business_id, $importOptions);
        $response['journal_components'] = $components;
        $componentSide = $row->source_type === 'Sales Invoice' ? 'credit' : 'debit';
        $response['journal_base_total'] = round((float) collect($components)->reject(fn ($component) => $component['is_tax'])->sum($componentSide), 4);
        $response['journal_tax_total'] = round((float) collect($components)->filter(fn ($component) => $component['is_tax'])->sum($componentSide), 4);
        $response['source_components'] = null;

        if (($row->source_record_type ?? null) === \App\Transaction::class && ! empty($row->source_record_id)) {
            if ($sourceComponentsById !== null) {
                $response['source_components'] = $sourceComponentsById[$row->source_record_id] ?? null;
            } else {
                $source = DB::table('transactions')
                    ->where('id', $row->source_record_id)
                    ->where('business_id', $row->business_id)
                    ->first(['total_before_tax', 'tax_amount', 'final_total']);
                if ($source) {
                    $response['source_components'] = [
                        'base' => $source->total_before_tax === null ? null : (float) $source->total_before_tax,
                        'tax' => $source->tax_amount === null ? null : (float) $source->tax_amount,
                        'total' => $source->final_total === null ? null : (float) $source->final_total,
                    ];
                }
            }
        }

        return $response;
    }

    private function validateJournalImportDraftData(array $draft_data, int $business_id, string $source_type, ?int $import_id = null): array
    {
        return $this->journalImportDraftService->validateDraftData($draft_data, $business_id, $source_type, $import_id);
    }

    private function analysisFromJournalDraftRows(int $importId, int $businessId, ?string $summary): array
    {
        $rows = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->orderBy('entry_index')
            ->get(['entry_data']);
        $entries = $rows->map(function ($row) {
            return json_decode((string) $row->entry_data, true) ?: [];
        })->all();

        $analysis = json_decode((string) $summary, true) ?: [];
        $analysis['entries'] = $entries;
        $analysis['total_entries'] = count($entries);
        $analysis['types'] = collect($entries)->countBy(function ($entry) {
            return $entry['source_type'] ?? 'Unknown';
        })->all();
        $analysis['missing_accounts'] = $analysis['missing_accounts'] ?? [];
        $analysis['source_link_matched_count'] = $analysis['source_link_matched_count'] ?? 0;
        $analysis['source_link_review_count'] = $analysis['source_link_review_count'] ?? 0;
        $analysis['source_link_unmatched_count'] = $analysis['source_link_unmatched_count'] ?? 0;
        $analysis['source_link_ambiguous_count'] = $analysis['source_link_ambiguous_count'] ?? 0;

        return $analysis;
    }

    private function journalDraftProcessingCounts(int $importId, int $businessId): array
    {
        $counts = [
            'pending' => 0,
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->select('processing_status', DB::raw('COUNT(*) as row_count'))
            ->groupBy('processing_status')
            ->get()
            ->each(function ($row) use (&$counts) {
                if (array_key_exists($row->processing_status, $counts)) {
                    $counts[$row->processing_status] = (int) $row->row_count;
                }
            });

        return $counts;
    }

    public function startImport(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.add_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'import_id' => 'required|integer',
        ]);

        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $business_id)
            ->where('id', $request->input('import_id'))
            ->first();

        if (empty($batch)) {
            return response()->json([
                'success' => 0,
                'msg' => 'Batch tidak ditemukan pada business aktif.',
            ], 422);
        }

        $hasDraftRows = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $business_id)
            ->exists();
        if (! $hasDraftRows) {
            return response()->json(['success' => 0, 'msg' => 'Draft belum memiliki baris untuk diproses.'], 422);
        }

        $options = $request->only([
            'create_missing_accounts',
            'create_missing_main_accounts',
            'account_mappings',
            'main_account_mappings',
        ]);
        $queueResult = DB::transaction(function () use ($batch, $business_id, $options) {
            $lockedBatch = DB::table('accounting_journal_imports')
                ->where('id', $batch->id)
                ->where('business_id', $business_id)
                ->lockForUpdate()
                ->first();

            if (empty($lockedBatch) || ! in_array($lockedBatch->status, ['analyzed', 'partially_imported', 'failed', 'cancelled'], true)) {
                return ['error' => 'Batch sedang diproses atau tidak dapat dilanjutkan.'];
            }

            DB::table('accounting_journal_imports')
                ->where('id', $lockedBatch->id)
                ->where('business_id', $business_id)
                ->update([
                    'status' => 'preparing_import',
                    'cancel_requested_at' => null,
                    'cancel_requested_by' => null,
                    'cancelled_at' => null,
                    'cancelled_by' => null,
                    'prepare_cursor' => 0,
                    'tax_reconciliation_cursor' => 0,
                    'import_options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'last_message' => 'Memvalidasi dan menyiapkan antrean import dalam beberapa bagian.',
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            return [
                'status' => 'preparing_import',
                'last_message' => 'Memvalidasi dan menyiapkan antrean import dalam beberapa bagian.',
            ];
        });

        if (! empty($queueResult['error'])) {
            return response()->json(['success' => 0, 'msg' => $queueResult['error']], 409);
        }

        try {
            ProcessJournalImportJob::dispatch($batch->id, 'prepare');
        } catch (\Throwable $exception) {
            DB::table('accounting_journal_imports')
                ->where('id', $batch->id)
                ->where('business_id', $business_id)
                ->whereNull('cancel_requested_at')
                ->update([
                    'status' => 'failed',
                    'last_message' => 'Persiapan import belum masuk ke worker queue.',
                    'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);

            return response()->json(['success' => 0, 'msg' => 'Gagal mengirim persiapan import ke worker queue.'], 500);
        }

        return response()->json([
            'success' => 1,
            'import_id' => $batch->id,
            'total_entries' => (int) $batch->total_entries,
            'status' => $queueResult['status'],
            'last_message' => $queueResult['last_message'],
        ]);
    }

    public function importStatus($id)
    {
        $business_id = (int) request()->session()->get('user.business_id');
        $this->authorizeJournalImportDraftAccess($business_id);

        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $business_id)
            ->where('id', $id)
            ->first();

        if (empty($batch)) {
            abort(404);
        }

        $queueCounts = $this->journalDraftProcessingCounts((int) $batch->id, $business_id);

        return response()->json([
            'success' => 1,
            'id' => $batch->id,
            'status' => $batch->status,
            'cancel_requested' => ! empty($batch->cancel_requested_at),
            'cancel_requested_at' => $batch->cancel_requested_at,
            'cancelled_at' => $batch->cancelled_at,
            'processed_entries' => (int) $batch->processed_entries,
            'total_entries' => (int) $batch->total_entries,
            'imported_entries' => (int) $batch->imported_entries,
            'skipped_entries' => (int) $batch->skipped_entries,
            'queue_counts' => $queueCounts,
            'queue_total' => $queueCounts['queued'] + $queueCounts['running'] + $queueCounts['completed'] + $queueCounts['failed'],
            'queue_processed' => $queueCounts['completed'] + $queueCounts['failed'],
            'last_message' => $batch->last_message,
            'error_message' => $batch->error_message,
            'created_at' => $batch->created_at,
            'updated_at' => $batch->updated_at,
        ]);
    }

    public function terminateImport(Request $request, $id, JournalImportCancellationService $cancellationService)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $this->authorizeJournalImportDraftAccess($business_id);

        $result = $cancellationService->requestStop(
            (int) $id,
            $business_id,
            (int) $request->session()->get('user.id')
        );

        if ($result['result'] === 'not_found') {
            abort(404);
        }

        if ($result['result'] === 'not_active') {
            return response()->json([
                'success' => 0,
                'msg' => 'Batch ini tidak sedang berjalan dan tidak dapat dihentikan.',
            ], 409);
        }

        return response()->json([
            'success' => 1,
            'id' => (int) $id,
            'status' => $result['status'],
            'cancel_requested' => $result['result'] !== 'cancelled',
            'msg' => $result['result'] === 'already_requested'
                ? 'Permintaan terminate sudah diterima.'
                : ($result['result'] === 'cancelled'
                    ? 'Import dihentikan; baris yang belum diproses dapat dilanjutkan dari web.'
                    : 'Permintaan terminate diterima; baris yang sedang diproses akan diselesaikan terlebih dahulu.'),
        ]);
    }

    public function processImport(Request $request)
    {
        // Preserve the old endpoint while ensuring all Journal imports use the
        // same persistent row state and background worker as the new UI.
        return $this->startImport($request);
    }

    public function storeImport(Request $request)
    {
        // Keep older form submissions on the persistent draft queue path.
        return $this->startImport($request);
    }

    private function processJournalImportEntries(Request $request, $analysis, $entries)
    {
        $business_id = request()->session()->get('user.business_id');
        $user_id = request()->session()->get('user.id');
        $journal_import_id = (int) $request->input('import_id');
        $create_missing_accounts = $request->boolean('create_missing_accounts');
        $create_missing_main_accounts = $request->boolean('create_missing_main_accounts');
        $account_mappings = array_filter($request->input('account_mappings', []));
        $main_account_mappings = array_filter($request->input('main_account_mappings', []));
        $account_cache = $this->getAccountingAccountCache($business_id);
        $main_account_cache = $this->getMainAccountCache($business_id);
        $imported = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            if ($entry['is_unbalanced'] || $entry['is_duplicate'] || $entry['exists_in_system']) {
                $skipped++;
                continue;
            }

            if (! empty($entry['source_key']) && AccountingAccTransMapping::where('business_id', $business_id)
                ->where('type', 'journal_entry')
                ->where('import_source_key', $entry['source_key'])
                ->exists()) {
                $skipped++;
                continue;
            }

            $resolved_lines = [];
            foreach ($entry['lines'] as $line) {
                $main_account_id = $this->resolveMainAccountId($line, $business_id, $user_id, $journal_import_id, $main_account_cache, $create_missing_main_accounts, $main_account_mappings);
                if (empty($main_account_id)) {
                    $skipped++;
                    continue 2;
                }

                $accounting_account_id = $this->resolveAccountingAccountId($line, $business_id, $user_id, $account_cache, $create_missing_accounts, $account_mappings, $main_account_id);
                if (empty($accounting_account_id)) {
                    $skipped++;
                    continue 2;
                }

                $line['main_account_id'] = $main_account_id;
                $line['accounting_account_id'] = $accounting_account_id;
                $resolved_lines[] = $line;
            }

            $mapping = new AccountingAccTransMapping();
            $mapping->journal_import_id = $journal_import_id;
            $mapping->import_source_key = $entry['source_key'];
            $mapping->business_id = $business_id;
            $mapping->ref_no = $entry['source_type'].' #'.$entry['source_number'];
            $mapping->note = trim('Imported journal: '.$entry['source_type'].' #'.$entry['source_number']."\n".$entry['description']);
            $mapping->type = 'journal_entry';
            $mapping->created_by = $user_id;
            $mapping->operation_date = $entry['date'];
            $this->applyJournalSourceLinkToMapping($mapping, $entry);
            $mapping->save();

            foreach ($resolved_lines as $line) {
                $account_transaction = new AccountTransaction();
                $account_transaction->journal_import_id = $journal_import_id;
                $account_transaction->account_id = $line['main_account_id'];
                $account_transaction->amount = $line['debit'] > 0 ? $line['debit'] : $line['credit'];
                $account_transaction->type = $line['debit'] > 0 ? 'debit' : 'credit';
                $account_transaction->sub_type = null;
                $account_transaction->reff_no = $entry['source_type'].' #'.$entry['source_number'];
                $account_transaction->operation_date = $entry['date'];
                $account_transaction->created_by = $user_id;
                $account_transaction->note = $line['description'];
                $account_transaction->save();

                $transaction = new AccountingAccountsTransaction();
                $transaction->journal_import_id = $journal_import_id;
                $transaction->accounting_account_id = $line['accounting_account_id'];
                $transaction->amount = $line['debit'] > 0 ? $line['debit'] : $line['credit'];
                $transaction->type = $line['debit'] > 0 ? 'debit' : 'credit';
                $transaction->sub_type = 'journal_entry';
                $transaction->acc_trans_mapping_id = $mapping->id;
                $transaction->created_by = $user_id;
                $transaction->operation_date = $entry['date'];
                $transaction->note = $line['description'];
                $transaction->save();
            }

            $imported++;
        }

        return compact('imported', 'skipped');
    }

    public function processJournalImportEntriesForBatch($import, $entries, $options)
    {
        $business_id = $import->business_id;
        $user_id = $import->created_by;
        $journal_import_id = $import->id;
        $create_missing_accounts = ! empty($options['create_missing_accounts']);
        $create_missing_main_accounts = ! empty($options['create_missing_main_accounts']);
        $account_mappings = ! empty($options['account_mappings']) && is_array($options['account_mappings']) ? array_filter($options['account_mappings']) : [];
        $main_account_mappings = ! empty($options['main_account_mappings']) && is_array($options['main_account_mappings']) ? array_filter($options['main_account_mappings']) : [];
        $account_cache = $this->getAccountingAccountCache($business_id);
        $main_account_cache = $this->getMainAccountCache($business_id);
        $imported = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            if ($entry['is_unbalanced'] || $entry['is_duplicate'] || $entry['exists_in_system']) {
                $skipped++;
                continue;
            }

            if (! empty($entry['source_key']) && AccountingAccTransMapping::where('business_id', $business_id)
                ->where('type', 'journal_entry')
                ->where('import_source_key', $entry['source_key'])
                ->exists()) {
                $skipped++;
                continue;
            }

            $resolved_lines = [];
            foreach ($entry['lines'] as $line) {
                $main_account_id = $this->resolveMainAccountId($line, $business_id, $user_id, $journal_import_id, $main_account_cache, $create_missing_main_accounts, $main_account_mappings);
                if (empty($main_account_id)) {
                    $skipped++;
                    continue 2;
                }

                $accounting_account_id = $this->resolveAccountingAccountId($line, $business_id, $user_id, $account_cache, $create_missing_accounts, $account_mappings, $main_account_id);
                if (empty($accounting_account_id)) {
                    $skipped++;
                    continue 2;
                }

                $line['main_account_id'] = $main_account_id;
                $line['accounting_account_id'] = $accounting_account_id;
                $resolved_lines[] = $line;
            }

            $mapping = new AccountingAccTransMapping();
            $mapping->journal_import_id = $journal_import_id;
            $mapping->import_source_key = $entry['source_key'];
            $mapping->business_id = $business_id;
            $mapping->ref_no = $entry['source_type'].' #'.$entry['source_number'];
            $mapping->note = trim('Imported journal: '.$entry['source_type'].' #'.$entry['source_number']."\n".$entry['description']);
            $mapping->type = 'journal_entry';
            $mapping->created_by = $user_id;
            $mapping->operation_date = $entry['date'];
            $this->applyJournalSourceLinkToMapping($mapping, $entry);
            $mapping->save();

            foreach ($resolved_lines as $line) {
                $account_transaction = new AccountTransaction();
                $account_transaction->journal_import_id = $journal_import_id;
                $account_transaction->account_id = $line['main_account_id'];
                $account_transaction->amount = $line['debit'] > 0 ? $line['debit'] : $line['credit'];
                $account_transaction->type = $line['debit'] > 0 ? 'debit' : 'credit';
                $account_transaction->sub_type = null;
                $account_transaction->reff_no = $entry['source_type'].' #'.$entry['source_number'];
                $account_transaction->operation_date = $entry['date'];
                $account_transaction->created_by = $user_id;
                $account_transaction->note = $line['description'];
                $account_transaction->save();

                $transaction = new AccountingAccountsTransaction();
                $transaction->journal_import_id = $journal_import_id;
                $transaction->accounting_account_id = $line['accounting_account_id'];
                $transaction->amount = $line['debit'] > 0 ? $line['debit'] : $line['credit'];
                $transaction->type = $line['debit'] > 0 ? 'debit' : 'credit';
                $transaction->sub_type = 'journal_entry';
                $transaction->acc_trans_mapping_id = $mapping->id;
                $transaction->created_by = $user_id;
                $transaction->operation_date = $entry['date'];
                $transaction->note = $line['description'];
                $transaction->save();
            }

            $imported++;
        }

        return compact('imported', 'skipped');
    }

    public function reconcileJournalImportTaxes(int $journalImportId, int $businessId): array
    {
        return app(JournalTaxReconciliationService::class)->reconcileImport($journalImportId, $businessId);
    }

    public function revertJournalImportTaxes(int $journalImportId, int $businessId): int
    {
        return app(JournalTaxReconciliationService::class)->revertImport($journalImportId, $businessId);
    }

    public function revertImport($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.delete_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            $import = DB::table('accounting_journal_imports')
                ->where('business_id', $business_id)
                ->where('id', $id)
                ->where('status', 'imported')
                ->first();

            if (empty($import)) {
                throw new \Exception('Import batch tidak ditemukan atau sudah direvert.');
            }

            $this->revertJournalImportTaxes((int) $id, (int) $business_id);
            app(\Modules\Accounting\Services\JournalImportStockService::class)->revertImport((int) $id, (int) $business_id);
            app(\Modules\Accounting\Services\JournalImportPaymentService::class)->revertImport((int) $id, (int) $business_id);
            app(\Modules\Accounting\Services\JournalImportBankTransactionService::class)->revertImport((int) $id, (int) $business_id);
            app(JournalImportInvoiceService::class)->revertImport((int) $id, (int) $business_id);
            app(\Modules\Accounting\Services\JournalImportExpenseService::class)->revertImport((int) $id, (int) $business_id);
            AccountTransaction::where('journal_import_id', $id)->delete();
            AccountingAccountsTransaction::where('journal_import_id', $id)->delete();
            AccountingAccTransMapping::where('business_id', $business_id)
                ->where('journal_import_id', $id)
                ->delete();
            Account::where('business_id', $business_id)
                ->where('journal_import_id', $id)
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('account_transactions')
                        ->whereRaw('account_transactions.account_id = accounts.id')
                        ->whereNull('account_transactions.deleted_at');
                })
                ->delete();

            DB::table('accounting_journal_imports')
                ->where('id', $id)
                ->update([
                    'status' => 'reverted',
                    'reverted_by' => request()->session()->get('user.id'),
                    'reverted_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::commit();

            $output = [
                'success' => 1,
                'msg' => 'Import journal berhasil direvert.',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong').': '.$e->getMessage(),
            ];
        }

        return redirect()->back()->with('status', $output);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.add_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            $user_id = request()->session()->get('user.id');

            $account_ids = $request->get('account_id');
            $credits = $request->get('credit');
            $debits = $request->get('debit');
            $journal_date = $request->get('journal_date');

            $accounting_settings = $this->accountingUtil->getAccountingSettings($business_id);

            $ref_no = $request->get('ref_no');
            $ref_count = $this->util->setAndGetReferenceCount('journal_entry');
            if (empty($ref_no)) {
                $prefix = ! empty($accounting_settings['journal_entry_prefix']) ?
                $accounting_settings['journal_entry_prefix'] : '';

                //Generate reference number
                $ref_no = $this->util->generateReferenceNumber('journal_entry', $ref_count, $business_id, $prefix);
            }

            $acc_trans_mapping = new AccountingAccTransMapping();
            $acc_trans_mapping->business_id = $business_id;
            $acc_trans_mapping->ref_no = $ref_no;
            $acc_trans_mapping->note = $request->get('note');
            $acc_trans_mapping->type = 'journal_entry';
            $acc_trans_mapping->created_by = $user_id;
            $acc_trans_mapping->operation_date = $this->util->uf_date($journal_date, true);
            $acc_trans_mapping->save();

            //save details in account trnsactions table
            foreach ($account_ids as $index => $account_id) {
                if (! empty($account_id)) {
                    $transaction_row = [];
                    $transaction_row['accounting_account_id'] = $account_id;

                    if (! empty($credits[$index])) {
                        $transaction_row['amount'] = $credits[$index];
                        $transaction_row['type'] = 'credit';
                    }

                    if (! empty($debits[$index])) {
                        $transaction_row['amount'] = $debits[$index];
                        $transaction_row['type'] = 'debit';
                    }

                    $transaction_row['created_by'] = $user_id;
                    $transaction_row['operation_date'] = $this->util->uf_date($journal_date, true);
                    $transaction_row['sub_type'] = 'journal_entry';
                    $transaction_row['acc_trans_mapping_id'] = $acc_trans_mapping->id;

                    $accounts_transactions = new AccountingAccountsTransaction();
                    $accounts_transactions->fill($transaction_row);
                    $accounts_transactions->save();
                }
            }

            DB::commit();

            $output = ['success' => 1,
                'msg' => __('lang_v1.added_success'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->route('journal-entry.index')->with('status', $output);
    }

    /**
     * Show the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.view_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        return view('accounting::journal_entry.show');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.edit_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $journal = AccountingAccTransMapping::where('business_id', $business_id)
                    ->where('type', 'journal_entry')
                    ->where('id', $id)
                    ->firstOrFail();
        $accounts_transactions = AccountingAccountsTransaction::with('account')
                                    ->where('acc_trans_mapping_id', $id)
                                    ->get()->toArray();

        return view('accounting::journal_entry.edit')
            ->with(compact('journal', 'accounts_transactions'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.edit_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            $user_id = request()->session()->get('user.id');

            $account_ids = $request->get('account_id');
            $accounts_transactions_id = $request->get('accounts_transactions_id');
            $credits = $request->get('credit');
            $debits = $request->get('debit');
            $journal_date = $request->get('journal_date');

            $acc_trans_mapping = AccountingAccTransMapping::where('business_id', $business_id)
                        ->where('type', 'journal_entry')
                        ->where('id', $id)
                        ->firstOrFail();
            $acc_trans_mapping->note = $request->get('note');
            $acc_trans_mapping->operation_date = $this->util->uf_date($journal_date, true);
            $acc_trans_mapping->update();

            //save details in account trnsactions table
            foreach ($account_ids as $index => $account_id) {
                if (! empty($account_id)) {
                    $transaction_row = [];
                    $transaction_row['accounting_account_id'] = $account_id;

                    if (! empty($credits[$index])) {
                        $transaction_row['amount'] = $credits[$index];
                        $transaction_row['type'] = 'credit';
                    }

                    if (! empty($debits[$index])) {
                        $transaction_row['amount'] = $debits[$index];
                        $transaction_row['type'] = 'debit';
                    }

                    $transaction_row['created_by'] = $user_id;
                    $transaction_row['operation_date'] = $this->util->uf_date($journal_date, true);
                    $transaction_row['sub_type'] = 'journal_entry';
                    $transaction_row['acc_trans_mapping_id'] = $acc_trans_mapping->id;

                    if (! empty($accounts_transactions_id[$index])) {
                        $accounts_transactions = AccountingAccountsTransaction::find($accounts_transactions_id[$index]);
                        $accounts_transactions->fill($transaction_row);
                        $accounts_transactions->update();
                    } else {
                        $accounts_transactions = new AccountingAccountsTransaction();
                        $accounts_transactions->fill($transaction_row);
                        $accounts_transactions->save();
                    }
                } elseif (! empty($accounts_transactions_id[$index])) {
                    AccountingAccountsTransaction::delete($accounts_transactions_id[$index]);
                }
            }

            $output = ['success' => 1,
                'msg' => __('lang_v1.updated_success'),
            ];

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            print_r($e->getMessage());
            exit;
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->route('journal-entry.index')->with('status', $output);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        $business_id = request()->session()->get('user.business_id');
        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.delete_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $user_id = request()->session()->get('user.id');

        $acc_trans_mapping = AccountingAccTransMapping::where('id', $id)
                        ->where('business_id', $business_id)->firstOrFail();

        if (! empty($acc_trans_mapping)) {
            $acc_trans_mapping->delete();
            AccountingAccountsTransaction::where('acc_trans_mapping_id', $id)->delete();
        }

        return ['success' => 1,
            'msg' => __('lang_v1.deleted_success'),
        ];
    }

    public function analyzeJournalWorkbook($path, $business_id, ?callable $progressCallback = null)
    {
        $source_date_range = null;
        $account_cache = $this->getAccountingAccountCache($business_id);
        $account_candidates = $this->getAccountMatchCandidates($business_id);
        $existing_source_keys = AccountingAccTransMapping::where('business_id', $business_id)
            ->whereNotNull('import_source_key')
            ->pluck('import_source_key')
            ->flip()
            ->toArray();

        $entries = [];
        $current = null;
        $duplicate_keys = [];
        $missing_accounts = [];
        $line_hashes = [];

        if ($progressCallback) {
            $progressCallback(['message' => 'Membuka workbook dan menyiapkan analisis.']);
        }

        $workbookPath = $this->resolveJournalImportFilePath($path);
        if (empty($workbookPath) || ! is_file($workbookPath)) {
            throw new \RuntimeException('File workbook Journal Import tidak ditemukan pada penyimpanan bersama.');
        }

        foreach ($this->readJournalWorkbookRows($workbookPath, $progressCallback) as $index => $row) {
            if ($progressCallback && $index > 0 && $index % 500 === 0) {
                $progressCallback([
                    'processed_rows' => $index,
                    'message' => 'Menganalisis baris '.$index.' dari workbook Excel.',
                ]);
            }

            if ($index === 2) {
                $source_date_range = $row[0] ?? null;
            }

            if ($index < 6) {
                continue;
            }

            $account_no = trim((string) ($row[0] ?? ''));
            $account_name = trim((string) ($row[1] ?? ''));
            $description = trim((string) ($row[2] ?? ''));
            $debit = $this->parseImportAmount($row[3] ?? 0);
            $credit = $this->parseImportAmount($row[4] ?? 0);
            $account_no_label = strtolower($this->normalizeImportText($account_no));
            $account_name_label = strtolower($this->normalizeImportText($account_name));

            if (in_array($account_no_label, ['total', 'grand total']) || in_array($account_name_label, ['total', 'grand total'])) {
                continue;
            }

            if ($account_no === '' && $account_name !== '' && $description === '' && $debit == 0 && $credit == 0) {
                if (! $this->isJournalImportHeader($account_name)) {
                    continue;
                }

                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = $this->newJournalImportEntry($account_name, $index + 1);
                continue;
            }

            if ($current !== null && ($account_no !== '' || $account_name !== '' || $description !== '' || $debit != 0 || $credit != 0)) {
                $line = [
                    'row' => $index + 1,
                    'account_no' => $account_no,
                    'account_name' => $account_name,
                    'description' => $description,
                    'debit' => $debit,
                    'credit' => $credit,
                ];

                $line_key = implode('|', [
                    $current['source_key'],
                    $account_no,
                    $account_name,
                    $description,
                    $debit,
                    $credit,
                ]);

                if (isset($line_hashes[$line_key])) {
                    $duplicate_keys[$line_key] = true;
                    $current['is_duplicate'] = true;
                }
                $line_hashes[$line_key] = true;

                if (! $this->accountExistsInCache($line, $account_cache)) {
                    $account_key = $this->journalLineAccountKey($line);
                    $missing_accounts[$account_key] = [
                        'key' => $account_key,
                        'account_no' => $account_no,
                        'account_name' => $account_name,
                        'sample_row' => $index + 1,
                        'suggested_accounting_accounts' => $this->suggestAccountMatches($line, $account_candidates['accounting']),
                        'suggested_payment_accounts' => $this->suggestAccountMatches($line, $account_candidates['payment']),
                    ];
                    $current['has_missing_accounts'] = true;
                }

                $current['debit_total'] += $debit;
                $current['credit_total'] += $credit;
                $current['description'] = trim($current['description']."\n".$description);
                $current['lines'][] = $line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        foreach ($entries as $key => $entry) {
            $entries[$key]['is_unbalanced'] = abs($entry['debit_total'] - $entry['credit_total']) > 0.01;
            $entries[$key]['exists_in_system'] = isset($existing_source_keys[$entry['source_key']]);
            $entries[$key]['duplicate_matches'] = $entries[$key]['exists_in_system']
                ? ['Jenis, nomor, dan tanggal ini sudah pernah diimport ke GL.']
                : [];
            $entries[$key]['line_count'] = count($entry['lines']);
        }

        app(\Modules\Accounting\Services\JournalImportIdentityService::class)
            ->markRepeatedSourceIdentities($entries);

        if ($progressCallback) {
            $progressCallback(['message' => 'Mencocokkan referensi transaksi sumber dengan data bisnis.']);
        }

        $source_links = $this->resolveJournalSourceLinks($entries, $business_id);
        foreach ($entries as $key => $entry) {
            $entries[$key] = array_merge($entry, $source_links[$key] ?? [
                'source_link_status' => 'unmatched',
                'source_record_type' => null,
                'source_record_id' => null,
                'source_link_note' => 'Sumber transaksi tidak dapat dipastikan.',
                'source_link_label' => null,
                'source_link_candidates' => [],
            ]);
        }

        $types = [];
        foreach ($entries as $entry) {
            $types[$entry['source_type']] = ($types[$entry['source_type']] ?? 0) + 1;
        }

        $source_link_counts = collect($entries)->countBy('source_link_status');
        $source_link_matched_count = (int) ($source_link_counts['matched'] ?? 0);
        $source_link_unmatched_count = (int) ($source_link_counts['unmatched'] ?? 0);
        $source_link_ambiguous_count = (int) ($source_link_counts['ambiguous'] ?? 0);
        $duplicate_entry_count = count(array_filter($entries, function ($entry) {
            return ! empty($entry['is_duplicate']) || ! empty($entry['source_identity_conflict']);
        }));

        return [
            'source_date_range' => $source_date_range,
            'total_entries' => count($entries),
            'types' => $types,
            'journal_entry_count' => count(array_filter($entries, fn ($entry) => $entry['source_type'] === 'Journal Entry')),
            'non_journal_count' => count(array_filter($entries, fn ($entry) => $entry['source_type'] !== 'Journal Entry')),
            'unbalanced_count' => count(array_filter($entries, fn ($entry) => $entry['is_unbalanced'])),
            'duplicate_count' => $duplicate_entry_count,
            'existing_count' => count(array_filter($entries, fn ($entry) => $entry['exists_in_system'])),
            'source_link_matched_count' => $source_link_matched_count,
            'source_link_unmatched_count' => $source_link_unmatched_count,
            'source_link_ambiguous_count' => $source_link_ambiguous_count,
            'source_link_review_count' => $source_link_unmatched_count + $source_link_ambiguous_count,
            'missing_accounts' => array_values($missing_accounts),
            'entries' => $entries,
        ];
    }

    private function resolveJournalSourceLinks(array $entries, int $business_id): array
    {
        $sourceMatcher = app(\Modules\Accounting\Services\JournalImportSourceMatcher::class);
        $definitions = [
            'Journal Entry' => ['model' => null, 'record_type' => null],
            'Sales Invoice' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['sell']],
            'Purchase Invoice' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['purchase']],
            'Expense' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['expense']],
            'Purchase Payment' => ['model' => 'payment', 'record_type' => TransactionPayment::class, 'types' => ['purchase']],
            'Receive Payment' => ['model' => 'payment', 'record_type' => TransactionPayment::class, 'types' => ['sell']],
            'Bank Deposit' => ['model' => 'account_transaction', 'record_type' => AccountTransaction::class, 'sub_type' => 'deposit', 'ledger_type' => 'credit'],
            'Bank Transfer' => ['model' => 'account_transaction', 'record_type' => AccountTransaction::class, 'sub_type' => 'fund_transfer', 'ledger_type' => 'debit'],
            'Bank Withdrawal' => ['model' => 'account_transaction', 'record_type' => AccountTransaction::class, 'sub_type' => 'withdrawal', 'ledger_type' => 'debit'],
            'Stock Adjustment' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['stock_adjustment']],
            'Stock In/Out' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['opening_stock', 'sell_transfer', 'purchase_transfer', 'stock_adjustment']],
            'Opening Balance' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['opening_balance', 'opening_stock']],
            'Reversal' => ['model' => 'account_transaction', 'record_type' => AccountTransaction::class, 'sub_type' => 'reversal'],
        ];

        $entriesByType = [];
        foreach ($entries as $key => $entry) {
            $entriesByType[$entry['source_type']][] = [$key, $entry];
        }

        $links = [];
        foreach ($entriesByType as $sourceType => $typedEntries) {
            $definition = $definitions[$sourceType] ?? null;
            if ($definition === null) {
                foreach ($typedEntries as [$key]) {
                    $links[$key] = $this->unmatchedJournalSourceLink('Jenis sumber belum memiliki aturan pencocokan yang aman.');
                }
                continue;
            }

            if ($definition['model'] === null) {
                foreach ($typedEntries as [$key]) {
                    $links[$key] = [
                        'source_link_status' => 'not_applicable',
                        'source_record_type' => null,
                        'source_record_id' => null,
                        'source_link_note' => 'Journal Entry manual tidak memiliki transaksi sumber untuk ditautkan.',
                        'source_link_label' => null,
                        'source_link_candidates' => [],
                        'source_link_candidate_details' => [],
                    ];
                }
                continue;
            }

            $sourceNumbers = collect($typedEntries)
                ->map(fn ($item) => trim((string) ($item[1]['source_number'] ?? '')))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $numericIds = [];
            foreach ($sourceNumbers as $sourceNumber) {
                if (filter_var($sourceNumber, FILTER_VALIDATE_INT) !== false) {
                    $numericIds[] = $sourceNumber;
                } elseif (preg_match('/^(\d+)\/(.+)$/', $sourceNumber, $idAndReference)) {
                    $numericIds[] = $idAndReference[1];
                }
            }
            $numericIds = array_values(array_unique($numericIds));
            $candidates = collect();

            if ($sourceNumbers !== [] || $numericIds !== []) {
                if ($definition['model'] === 'transaction') {
                    $query = DB::table('transactions')
                        ->where('business_id', $business_id)
                        ->whereIn('type', $definition['types'])
                        ->where(function ($query) use ($sourceNumbers, $numericIds) {
                            if ($sourceNumbers !== []) {
                                $query->whereIn('invoice_no', $sourceNumbers)
                                    ->orWhereIn('ref_no', $sourceNumbers);
                            }
                            if ($numericIds !== []) {
                                $method = $sourceNumbers === [] ? 'whereIn' : 'orWhereIn';
                                $query->{$method}('id', $numericIds);
                            }
                        })
                        ->select(
                            'id',
                            'invoice_no as reference_one',
                            'ref_no as reference_two',
                            'transaction_date as source_date',
                            'final_total as source_amount'
                        );
                    $candidates = $query->get()->map(fn ($row) => $this->journalSourceCandidate($row, $definition['record_type'], ['reference_one', 'reference_two']));
                } elseif ($definition['model'] === 'payment') {
                    $query = DB::table('transaction_payments')
                        ->join('transactions as source_transactions', 'source_transactions.id', '=', 'transaction_payments.transaction_id')
                        ->where('source_transactions.business_id', $business_id)
                        ->whereIn('source_transactions.type', $definition['types'])
                        ->where(function ($query) use ($sourceNumbers, $numericIds) {
                            if ($sourceNumbers !== []) {
                                $query->whereIn('transaction_payments.payment_ref_no', $sourceNumbers);
                            }
                            if ($numericIds !== []) {
                                $method = $sourceNumbers === [] ? 'whereIn' : 'orWhereIn';
                                $query->{$method}('transaction_payments.id', $numericIds);
                            }
                        })
                        ->select(
                            'transaction_payments.id',
                            'transaction_payments.payment_ref_no as reference_one',
                            DB::raw('NULL as reference_two'),
                            'transaction_payments.paid_on as source_date',
                            'transaction_payments.amount as source_amount'
                        );
                    $candidates = $query->get()->map(fn ($row) => $this->journalSourceCandidate($row, $definition['record_type'], ['reference_one']));
                } else {
                    $query = DB::table('account_transactions')
                        ->join('accounts as source_accounts', 'source_accounts.id', '=', 'account_transactions.account_id')
                        ->where('source_accounts.business_id', $business_id)
                        ->where('account_transactions.sub_type', $definition['sub_type'])
                        ->whereNull('account_transactions.deleted_at')
                        ->where(function ($query) use ($sourceNumbers, $numericIds) {
                            if ($sourceNumbers !== []) {
                                $query->whereIn('account_transactions.reff_no', $sourceNumbers);
                            }
                            if ($numericIds !== []) {
                                $method = $sourceNumbers === [] ? 'whereIn' : 'orWhereIn';
                                $query->{$method}('account_transactions.id', $numericIds);
                            }
                        })
                        ->select(
                            'account_transactions.id',
                            'account_transactions.reff_no as reference_one',
                            DB::raw('NULL as reference_two'),
                            'account_transactions.operation_date as source_date',
                            'account_transactions.amount as source_amount'
                        );
                    $candidates = $query->get()->map(fn ($row) => $this->journalSourceCandidate($row, $definition['record_type'], ['reference_one']));
                }
            }

            $candidateIndex = $sourceMatcher->indexCandidates($candidates->all());

            foreach ($typedEntries as [$key, $entry]) {
                $sourceNumber = trim((string) ($entry['source_number'] ?? ''));
                $matches = collect($sourceMatcher->match(
                    $sourceNumber,
                    (string) ($entry['date'] ?? ''),
                    (float) ($entry['debit_total'] ?? 0),
                    $candidateIndex
                ));

                if ($matches->count() === 1) {
                    $candidate = $matches->first();
                    $validationNotes = [];
                    if ($candidate['date'] !== $entry['date']) {
                        $validationNotes[] = 'tanggal sumber berbeda dari tanggal Journal';
                    }
                    if (abs((float) $candidate['amount'] - (float) $entry['debit_total']) > 0.01) {
                        $validationNotes[] = 'total sumber berbeda dari total debit Journal';
                    }
                    $linkNote = 'Sumber unik ditemukan berdasarkan jenis dan nomor dokumen.';
                    if ($validationNotes !== []) {
                        $linkNote .= ' Perlu ditinjau: '.implode('; ', $validationNotes).'.';
                    }
                    $links[$key] = [
                        'source_link_status' => 'matched',
                        'source_record_type' => $candidate['record_type'],
                        'source_record_id' => $candidate['id'],
                        'source_link_note' => $linkNote,
                        'source_link_label' => $candidate['label'],
                        'source_link_candidates' => [$candidate['label']],
                        'source_link_candidate_details' => [$candidate],
                    ];
                } elseif ($matches->count() > 1) {
                    $labels = $matches->take(5)->pluck('label')->values()->all();
                    $links[$key] = [
                        'source_link_status' => 'ambiguous',
                        'source_record_type' => null,
                        'source_record_id' => null,
                        'source_link_note' => 'Ditemukan '.$matches->count().' sumber yang cocok; relasi perlu ditinjau.',
                        'source_link_label' => null,
                        'source_link_candidates' => $labels,
                        'source_link_candidate_details' => $matches->values()->all(),
                    ];
                } else {
                    $links[$key] = $this->unmatchedJournalSourceLink(
                        "Tidak ditemukan sumber dengan nomor '{$sourceNumber}' untuk jenis transaksi ini dalam business aktif."
                    );
                }
            }
        }

        return $links;
    }

    private function journalSourceCandidate($row, string $recordType, array $referenceFields): array
    {
        $references = [];
        foreach ($referenceFields as $field) {
            $reference = trim((string) ($row->{$field} ?? ''));
            if ($reference !== '') {
                $references[] = $reference;
            }
        }
        $id = (int) $row->id;
        $referenceLabel = $references[0] ?? null;

        return [
            'id' => $id,
            'record_type' => $recordType,
            'date' => substr((string) $row->source_date, 0, 10),
            'amount' => (float) $row->source_amount,
            'references' => $references,
            'label' => class_basename($recordType).' #'.$id.($referenceLabel !== null && $referenceLabel !== (string) $id ? ' ('.$referenceLabel.')' : ''),
        ];
    }

    private function applyJournalSourceLinkToMapping(AccountingAccTransMapping $mapping, array $entry): void
    {
        $mapping->source_record_type = $entry['source_record_type'] ?? null;
        $mapping->source_record_id = $entry['source_record_id'] ?? null;
        $mapping->source_link_status = $entry['source_link_status'] ?? 'unmatched';
        $mapping->source_link_note = $entry['source_link_note'] ?? 'Sumber transaksi perlu ditinjau.';
    }

    private function unmatchedJournalSourceLink(string $note): array
    {
        return [
            'source_link_status' => 'unmatched',
            'source_record_type' => null,
            'source_record_id' => null,
            'source_link_note' => $note,
            'source_link_label' => null,
            'source_link_candidates' => [],
            'source_link_candidate_details' => [],
        ];
    }

    private function readJournalWorkbookRows($full_path, ?callable $progressCallback = null)
    {
        $reader = IOFactory::createReaderForFile($full_path);
        $reader->setReadDataOnly(true);
        $worksheet_info = $reader->listWorksheetInfo($full_path);
        $highest_row = (int) ($worksheet_info[0]['totalRows'] ?? 0);
        $highest_column = $worksheet_info[0]['lastColumnLetter'] ?? 'E';
        $chunk_size = 5000;
        $processed_rows = 0;

        if ($progressCallback) {
            $progressCallback([
                'processed_rows' => 0,
                'total_rows' => $highest_row,
                'message' => 'Membaca baris 0 / '.$highest_row.' dari workbook Excel.',
            ]);
        }

        for ($start_row = 1; $start_row <= $highest_row; $start_row += $chunk_size) {
            $end_row = min($start_row + $chunk_size - 1, $highest_row);

            if ($progressCallback) {
                $progressCallback([
                    'processed_rows' => $processed_rows,
                    'total_rows' => $highest_row,
                    'message' => 'Menyiapkan baris '.$start_row.'-'.$end_row.' / '.$highest_row.' dari workbook Excel.',
                ]);
            }

            $reader->setReadFilter(new class($start_row, $end_row) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                private $start_row;

                private $end_row;

                public function __construct($start_row, $end_row)
                {
                    $this->start_row = $start_row;
                    $this->end_row = $end_row;
                }

                public function readCell($columnAddress, $row, $worksheetName = '')
                {
                    return $row >= $this->start_row && $row <= $this->end_row;
                }
            });
                    if (! empty($definition['ledger_type'])) {
                        $query->where('account_transactions.type', $definition['ledger_type']);
                    }

            $spreadsheet = $reader->load($full_path);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->rangeToArray('A'.$start_row.':'.$highest_column.$end_row, null, true, true, false);

            foreach ($rows as $offset => $row) {
                $processed_rows = $start_row + $offset;
                yield ($start_row + $offset - 1) => $row;

                if ($progressCallback && ($processed_rows === $end_row || $processed_rows === $highest_row)) {
                    $progressCallback([
                        'processed_rows' => $processed_rows,
                        'total_rows' => $highest_row,
                        'message' => 'Menganalisis baris '.$processed_rows.' / '.$highest_row.' dari workbook Excel.',
                    ]);
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $sheet, $rows);
        }
    }

    public function deleteTemporaryJournalFile($path)
    {
        $full_path = $this->resolveJournalImportFilePath($path);

        if (! empty($full_path) && is_file($full_path)) {
            @unlink($full_path);
        }
    }

    public function writeTemporaryJournalAnalysis($analysis)
    {
        $this->journalImportStorageDirectory();
        $file_name = Str::uuid().'.json';
        $path = 'journal_imports/'.$file_name;
        $full_path = $this->resolveJournalImportFilePath($path);
        if (empty($full_path) || file_put_contents($full_path, json_encode($analysis), LOCK_EX) === false) {
            throw new \RuntimeException('File analisa Journal Import tidak dapat disimpan pada penyimpanan bersama.');
        }

        return $path;
    }

    public function compactJournalAnalysisSummary($analysis)
    {
        unset($analysis['entries']);

        return $analysis;
    }

    public function readTemporaryJournalAnalysis($path)
    {
        $full_path = $this->resolveJournalImportFilePath($path);
        if (empty($full_path) || ! is_file($full_path)) {
            throw new \Exception('File analisa import tidak ditemukan.');
        }

        $analysis = json_decode(file_get_contents($full_path), true);
        if (empty($analysis) || ! isset($analysis['entries'])) {
            throw new \Exception('File analisa import tidak valid.');
        }

        return $analysis;
    }

    private function journalImportStorageDirectory(): string
    {
        $directory = Storage::disk('journal_imports')->path('');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Folder penyimpanan Journal Import tidak dapat dibuat.');
        }

        return $directory;
    }

    private function resolveJournalImportFilePath($path): ?string
    {
        $path = str_replace('\\', '/', trim((string) $path));
        if ($path === '' || strpos($path, "\0") !== false || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            return null;
        }

        if (Str::startsWith($path, 'journal_imports/')) {
            $filename = substr($path, strlen('journal_imports/'));
            if ($filename === '' || basename($filename) !== $filename) {
                return null;
            }
            $root = Storage::disk('journal_imports')->path('');
        } elseif (Str::startsWith($path, 'uploads/temp/journal_imports/')) {
            // Backward compatibility for batches created before private shared storage.
            $filename = substr($path, strlen('uploads/temp/journal_imports/'));
            if ($filename === '' || basename($filename) !== $filename) {
                return null;
            }
            $root = public_path('uploads/temp/journal_imports');
        } else {
            return null;
        }

        $root = realpath($root);
        if ($root === false) {
            return null;
        }
        $fullPath = $root.DIRECTORY_SEPARATOR.$filename;
        $parent = realpath(dirname($fullPath));
        if ($parent === false || $parent !== $root || is_link($fullPath)) {
            return null;
        }

        $resolvedFile = realpath($fullPath);
        if ($resolvedFile !== false) {
            $resolvedParent = realpath(dirname($resolvedFile));
            if ($resolvedParent !== $root) {
                return null;
            }

            return $resolvedFile;
        }

        return $fullPath;
    }

    private function newJournalImportEntry($header, $row)
    {
        $entry = [
            'header_row' => $row,
            'source_type' => 'Unknown',
            'source_number' => null,
            'source_date' => null,
            'date' => null,
            'source_key' => null,
            'description' => '',
            'debit_total' => 0,
            'credit_total' => 0,
            'lines' => [],
            'is_duplicate' => false,
            'is_unbalanced' => false,
            'exists_in_system' => false,
            'has_missing_accounts' => false,
        ];

        if (preg_match('/^(.*?)\s+#(.+?)\s+\|\s+(\d{2}\/\d{2}\/\d{4})$/', $header, $matches)) {
            $entry['source_type'] = trim($matches[1]);
            $entry['source_number'] = trim($matches[2]);
            $entry['source_date'] = trim($matches[3]);
            $entry['date'] = Carbon::createFromFormat('d/m/Y', $entry['source_date'])->format('Y-m-d');
            $entry['source_key'] = implode('|', [$entry['source_type'], $entry['source_number'], $entry['date']]);
        } else {
            $entry['source_key'] = 'unknown|'.$row.'|'.Str::slug($header);
        }

        return $entry;
    }

    private function isJournalImportHeader($header)
    {
        return preg_match('/^(.*?)\s+#(.+?)\s+\|\s+(\d{2}\/\d{2}\/\d{4})$/', trim((string) $header)) === 1;
    }

    private function getAccountingAccountCache($business_id)
    {
        $accounts = AccountingAccount::where('business_id', $business_id)
            ->select('id', 'account_id', 'name', 'gl_code')
            ->get();

        return [
            'by_gl_code' => $accounts->whereNotNull('gl_code')->keyBy(fn ($account) => strtolower(trim($account->gl_code)))->toArray(),
            'by_name' => $accounts->keyBy(fn ($account) => strtolower(trim($account->name)))->toArray(),
        ];
    }

    private function getTaxReconciliationReviews(int $business_id)
    {
        return DB::table('accounting_acc_trans_mappings as mapping')
            ->join('accounting_journal_imports as import', 'import.id', '=', 'mapping.journal_import_id')
            ->where('mapping.business_id', $business_id)
            ->where('import.business_id', $business_id)
            ->where('mapping.tax_reconciliation_status', 'review')
            ->orderByDesc('mapping.updated_at')
            ->limit(50)
            ->get([
                'import.file_name',
                'import.created_at as import_created_at',
                'mapping.ref_no',
                'mapping.source_record_id',
                'mapping.tax_reconciliation_note',
                'mapping.journal_tax_amount',
                'mapping.calculated_tax_amount',
                'mapping.journal_total_amount',
                'mapping.recalculated_final_total',
            ]);
    }

    private function getAccountingAccountOptions($business_id)
    {
        return AccountingAccount::where('business_id', $business_id)
            ->orderBy('gl_code')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function ($account) {
                $label = trim(($account->gl_code ? $account->gl_code.' - ' : '').$account->name);

                return [$account->id => $label];
            })
            ->toArray();
    }

    private function getMainAccountOptions($business_id)
    {
        return Account::where('business_id', $business_id)
            ->whereNull('deleted_at')
            ->orderBy('account_number')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function ($account) {
                $label = trim(($account->account_number ? $account->account_number.' - ' : '').$account->name);

                return [$account->id => $label];
            })
            ->toArray();
    }

    private function getMainAccountCache($business_id)
    {
        $accounts = Account::where('business_id', $business_id)
            ->whereNull('deleted_at')
            ->select('id', 'name', 'account_number')
            ->get();

        return [
            'by_number' => $accounts->whereNotNull('account_number')->keyBy(fn ($account) => strtolower(trim($account->account_number)))->toArray(),
            'by_name' => $accounts->keyBy(fn ($account) => strtolower(trim($account->name)))->toArray(),
        ];
    }

    private function getAccountMatchCandidates($business_id)
    {
        $accounting = AccountingAccount::where('business_id', $business_id)
            ->select('id', 'name', 'gl_code')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'number' => $account->gl_code,
                    'source' => 'accounting',
                ];
            })
            ->toArray();

        $payment = DB::table('accounts')
            ->where('business_id', $business_id)
            ->whereNull('deleted_at')
            ->select('id', 'name', 'account_number')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'number' => $account->account_number,
                    'source' => 'payment',
                ];
            })
            ->toArray();

        return compact('accounting', 'payment');
    }

    private function suggestAccountMatches($line, $candidates, $limit = 5)
    {
        $suggestions = [];
        foreach ($candidates as $candidate) {
            $score = $this->accountMatchScore($line, $candidate);
            if ($score >= 50) {
                $candidate['score'] = $score;
                $suggestions[] = $candidate;
            }
        }

        usort($suggestions, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($suggestions, 0, $limit);
    }

    private function accountMatchScore($line, $candidate)
    {
        $line_number = strtolower(trim($line['account_no']));
        $candidate_number = strtolower(trim((string) ($candidate['number'] ?? '')));
        if ($line_number !== '' && $candidate_number !== '') {
            if ($line_number === $candidate_number) {
                return 100;
            }

            similar_text($line_number, $candidate_number, $number_percent);
            if ($number_percent >= 85) {
                return (int) $number_percent;
            }
        }

        $line_name = $this->normalizeImportText($line['account_name']);
        $candidate_name = $this->normalizeImportText($candidate['name']);
        if ($line_name === '' || $candidate_name === '') {
            return 0;
        }

        if ($line_name === $candidate_name) {
            return 95;
        }

        if (Str::contains($candidate_name, $line_name) || Str::contains($line_name, $candidate_name)) {
            return 80;
        }

        similar_text($line_name, $candidate_name, $name_percent);

        return (int) $name_percent;
    }

    private function normalizeImportText($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function accountExistsInCache($line, $account_cache)
    {
        return ! empty($this->findAccountInCache($line, $account_cache));
    }

    private function findAccountInCache($line, $account_cache)
    {
        $account_no = strtolower(trim($line['account_no']));
        $account_name = strtolower(trim($line['account_name']));

        if ($account_no !== '' && ! empty($account_cache['by_gl_code'][$account_no])) {
            return $account_cache['by_gl_code'][$account_no];
        }

        if ($account_name !== '' && ! empty($account_cache['by_name'][$account_name])) {
            return $account_cache['by_name'][$account_name];
        }

        return null;
    }

    private function resolveMainAccountId($line, $business_id, $user_id, $journal_import_id, &$main_account_cache, $create_missing_main_accounts, $main_account_mappings = [])
    {
        $account_key = $this->journalLineAccountKey($line);
        if (! empty($main_account_mappings[$account_key])) {
            $mapped_account_id = (int) $main_account_mappings[$account_key];
            $exists = Account::where('business_id', $business_id)
                ->where('id', $mapped_account_id)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                return $mapped_account_id;
            }
        }

        $account_no = strtolower(trim($line['account_no']));
        $account_name = strtolower(trim($line['account_name']));

        if ($account_no !== '' && ! empty($main_account_cache['by_number'][$account_no])) {
            return $main_account_cache['by_number'][$account_no]['id'];
        }

        if ($account_name !== '' && ! empty($main_account_cache['by_name'][$account_name])) {
            return $main_account_cache['by_name'][$account_name]['id'];
        }

        if (! $create_missing_main_accounts) {
            return null;
        }

        $account = Account::create([
            'journal_import_id' => $journal_import_id,
            'business_id' => $business_id,
            'name' => $line['account_name'] ?: $line['account_no'],
            'account_number' => $line['account_no'] ?: $line['account_name'],
            'account_type_id' => null,
            'note' => 'Imported from journal entry',
            'created_by' => $user_id,
            'is_closed' => 0,
        ]);

        $main_account_cache = $this->getMainAccountCache($business_id);

        return $account->id;
    }

    private function resolveAccountingAccountId($line, $business_id, $user_id, &$account_cache, $create_missing_accounts, $account_mappings = [], $main_account_id = null)
    {
        $account_key = $this->journalLineAccountKey($line);
        if (! empty($account_mappings[$account_key])) {
            $mapped_account_id = (int) $account_mappings[$account_key];
            $exists = AccountingAccount::where('business_id', $business_id)
                ->where('id', $mapped_account_id)
                ->exists();

            if ($exists) {
                if (! empty($main_account_id)) {
                    AccountingAccount::where('business_id', $business_id)
                        ->where('id', $mapped_account_id)
                        ->whereNull('account_id')
                        ->update(['account_id' => $main_account_id]);
                }

                return $mapped_account_id;
            }
        }

        $account = $this->findAccountInCache($line, $account_cache);
        if (! empty($account)) {
            if (! empty($main_account_id) && empty($account['account_id'])) {
                AccountingAccount::where('business_id', $business_id)
                    ->where('id', $account['id'])
                    ->whereNull('account_id')
                    ->update(['account_id' => $main_account_id]);
            }

            return $account['id'];
        }

        if (! $create_missing_accounts) {
            return null;
        }

        $created = AccountingAccount::create([
            'account_id' => $main_account_id,
            'business_id' => $business_id,
            'name' => $line['account_name'] ?: $line['account_no'],
            'gl_code' => $line['account_no'],
            'account_primary_type' => $this->inferPrimaryAccountType($line['account_no']),
            'status' => 'active',
            'created_by' => $user_id,
        ]);

        $account_cache = $this->getAccountingAccountCache($business_id);

        return $created->id;
    }

    private function journalLineAccountKey($line)
    {
        return md5(strtolower(trim($line['account_no']).'|'.trim($line['account_name'])));
    }

    private function inferPrimaryAccountType($gl_code)
    {
        $first = substr(trim((string) $gl_code), 0, 1);

        if ($first === '1') {
            return 'asset';
        }
        if ($first === '2') {
            return 'liability';
        }
        if ($first === '3') {
            return 'equity';
        }
        if (in_array($first, ['4', '5'])) {
            return 'income';
        }

        return 'expenses';
    }

    private function parseImportAmount($value)
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (float) str_replace([',', ' '], '', (string) $value);
    }
}
