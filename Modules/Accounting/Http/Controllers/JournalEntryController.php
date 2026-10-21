<?php

namespace Modules\Accounting\Http\Controllers;

use App\Account;
use App\AccountTransaction;
use App\Transaction;
use App\TransactionPayment;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Entities\AccountingAccTransMapping;
use Modules\Accounting\Jobs\AnalyzeJournalImportJob;
use Modules\Accounting\Jobs\ProcessJournalImportJob;
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

    /**
     * Constructor
     *
     * @param  ProductUtils  $product
     * @return void
     */
    public function __construct(Util $util, ModuleUtil $moduleUtil, AccountingUtil $accountingUtil)
    {
        $this->util = $util;
        $this->moduleUtil = $moduleUtil;
        $this->accountingUtil = $accountingUtil;
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
        $upload_path = public_path('uploads/temp/journal_imports');
        if (! file_exists($upload_path)) {
            mkdir($upload_path, 0755, true);
        }
        $file->move($upload_path, $file_name);
        $path = 'uploads/temp/journal_imports/'.$file_name;
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

        if (empty($batch) || empty($batch->analysis_path) || $batch->status !== 'analyzed') {
            return redirect()->action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'import'])
                ->with('status', [
                    'success' => 0,
                    'msg' => 'Batch belum selesai dianalisa.',
                ]);
        }

        $analysis = $this->readTemporaryJournalAnalysis($batch->analysis_path);
        $tax_mapping_accounts = $this->getJournalTaxMappingAccounts($analysis['entries'], $business_id);
        $tax_rate_options = $this->getJournalTaxRateOptions($business_id);
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

        return view('accounting::journal_entry.import')
            ->with(compact('batch', 'analysis', 'path', 'analysis_path', 'imports', 'original_file_name', 'account_options', 'main_account_options', 'tax_mapping_accounts', 'tax_rate_options', 'taxReconciliationReviews'));
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
            'tax_account_mappings' => 'sometimes|array',
            'tax_account_mappings.*' => 'nullable|string',
        ]);

        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $business_id)
            ->where('id', $request->input('import_id'))
            ->where('status', 'analyzed')
            ->first();

        if (empty($batch)) {
            return response()->json([
                'success' => 0,
                'msg' => 'Batch belum siap untuk import.',
            ], 422);
        }

        $analysis = $this->readTemporaryJournalAnalysis($batch->analysis_path);
        $this->validateJournalTaxAccountMappingSelections(
            $request->input('tax_account_mappings', []),
            $business_id,
            $analysis['entries'],
            $request->input('account_mappings', [])
        );

        DB::table('accounting_journal_imports')
            ->where('id', $batch->id)
            ->update([
                'status' => 'queued_import',
                'processed_entries' => 0,
                'import_options' => json_encode($request->except(['_token', 'import_id'])),
                'last_message' => 'Menunggu worker queue imports untuk mulai menyimpan entry.',
                'updated_at' => now(),
            ]);

        ProcessJournalImportJob::dispatch($batch->id);

        return response()->json([
            'success' => 1,
            'import_id' => $batch->id,
            'total_entries' => $batch->total_entries,
        ]);
    }

    public function importStatus($id)
    {
        $business_id = request()->session()->get('user.business_id');

        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $business_id)
            ->where('id', $id)
            ->first();

        if (empty($batch)) {
            abort(404);
        }

        return response()->json([
            'success' => 1,
            'id' => $batch->id,
            'status' => $batch->status,
            'processed_entries' => (int) $batch->processed_entries,
            'total_entries' => (int) $batch->total_entries,
            'imported_entries' => (int) $batch->imported_entries,
            'skipped_entries' => (int) $batch->skipped_entries,
            'last_message' => $batch->last_message,
            'error_message' => $batch->error_message,
            'created_at' => $batch->created_at,
            'updated_at' => $batch->updated_at,
        ]);
    }

    public function processImport(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.add_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'import_id' => 'required|integer',
            'file_path' => 'required|string',
            'analysis_path' => 'required|string',
            'offset' => 'required|integer|min:0',
            'tax_account_mappings' => 'sometimes|array',
            'tax_account_mappings.*' => 'nullable|string',
        ]);

        $analysis = $this->readTemporaryJournalAnalysis($request->input('analysis_path'));
        $this->validateJournalTaxAccountMappingSelections(
            $request->input('tax_account_mappings', []),
            $business_id,
            $analysis['entries'],
            $request->input('account_mappings', [])
        );
        $offset = (int) $request->input('offset', 0);
        $chunk_size = (int) $request->input('chunk_size', 50);
        $chunk_size = max(10, min($chunk_size, 100));
        $entries = array_slice($analysis['entries'], $offset, $chunk_size);

        try {
            DB::beginTransaction();

            $result = $this->processJournalImportEntries($request, $analysis, $entries);
            $next_offset = $offset + count($entries);
            $is_complete = $next_offset >= count($analysis['entries']);
            $status = $is_complete ? 'imported' : 'processing';
            $taxReconciliation = ['review' => 0];

            if ($is_complete) {
                $this->persistJournalTaxAccountMappings(
                    $analysis,
                    $business_id,
                    request()->session()->get('user.id'),
                    $request->only(['account_mappings', 'tax_account_mappings'])
                );
                $taxReconciliation = $this->reconcileJournalImportTaxes(
                    (int) $request->input('import_id'),
                    (int) $business_id
                );
            }

            DB::table('accounting_journal_imports')
                ->where('id', $request->input('import_id'))
                ->where('business_id', $business_id)
                ->update([
                    'imported_entries' => DB::raw('imported_entries + '.$result['imported']),
                    'skipped_entries' => DB::raw('skipped_entries + '.$result['skipped']),
                    'processed_entries' => $next_offset,
                    'status' => $status,
                    'last_message' => 'Processed '.$next_offset.' / '.count($analysis['entries'])
                        .($is_complete ? '; tax perlu ditinjau: '.($taxReconciliation['review'] ?? 0) : ''),
                    'updated_at' => now(),
                ]);

            DB::commit();

            if ($is_complete) {
                $this->deleteTemporaryJournalFile($request->input('file_path'));
                $this->deleteTemporaryJournalFile($request->input('analysis_path'));
            }

            return response()->json([
                'success' => 1,
                'complete' => $is_complete,
                'next_offset' => $next_offset,
                'processed_entries' => $next_offset,
                'total_entries' => count($analysis['entries']),
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            DB::table('accounting_journal_imports')
                ->where('id', $request->input('import_id'))
                ->where('business_id', $business_id)
                ->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => 0,
                'msg' => __('messages.something_went_wrong').': '.$e->getMessage(),
            ], 500);
        }
    }

    public function storeImport(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (! (auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'accounting_module')) ||
            ! (auth()->user()->can('accounting.add_journal'))) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'file_path' => 'required|string',
            'tax_account_mappings' => 'sometimes|array',
            'tax_account_mappings.*' => 'nullable|string',
        ]);

        if (! file_exists(public_path($request->input('file_path')))) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ]);
        }

        $analysis = $this->analyzeJournalWorkbook($request->input('file_path'), $business_id);
        $this->validateJournalTaxAccountMappingSelections(
            $request->input('tax_account_mappings', []),
            $business_id,
            $analysis['entries'],
            $request->input('account_mappings', [])
        );

        try {
            DB::beginTransaction();

            $user_id = request()->session()->get('user.id');
            $create_missing_accounts = $request->boolean('create_missing_accounts');
            $create_missing_main_accounts = $request->boolean('create_missing_main_accounts');
            $account_mappings = array_filter($request->input('account_mappings', []));
            $main_account_mappings = array_filter($request->input('main_account_mappings', []));

            $journal_import_id = DB::table('accounting_journal_imports')->insertGetId([
                'business_id' => $business_id,
                'file_name' => $request->input('original_file_name'),
                'source_date_range' => $analysis['source_date_range'],
                'total_entries' => $analysis['total_entries'],
                'unbalanced_entries' => $analysis['unbalanced_count'],
                'duplicate_entries' => $analysis['duplicate_count'],
                'missing_accounts' => count($analysis['missing_accounts']),
                'unsupported_entries' => 0,
                'summary' => json_encode($this->compactJournalAnalysisSummary($analysis)),
                'created_by' => $user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $account_cache = $this->getAccountingAccountCache($business_id);
            $main_account_cache = $this->getMainAccountCache($business_id);
            $imported = 0;
            $skipped = 0;

            foreach ($analysis['entries'] as $entry) {
                if ($entry['is_unbalanced'] || $entry['is_duplicate'] || $entry['exists_in_system']) {
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

            $this->persistJournalTaxAccountMappings(
                $analysis,
                $business_id,
                $user_id,
                [
                    'account_mappings' => $account_mappings,
                    'tax_account_mappings' => $request->input('tax_account_mappings', []),
                ]
            );
            $taxReconciliation = $this->reconcileJournalImportTaxes($journal_import_id, (int) $business_id);

            DB::table('accounting_journal_imports')
                ->where('id', $journal_import_id)
                ->update([
                    'imported_entries' => $imported,
                    'skipped_entries' => $skipped,
                    'last_message' => 'Import selesai; tax perlu ditinjau: '.($taxReconciliation['review'] ?? 0),
                    'updated_at' => now(),
                ]);

            DB::commit();
            $this->deleteTemporaryJournalFile($request->input('file_path'));

            $output = [
                'success' => 1,
                'msg' => 'Import journal selesai. Imported: '.$imported.', skipped: '.$skipped,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong').': '.$e->getMessage(),
            ];
        }

        return redirect()->action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'import'])
            ->with('status', $output);
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

    public function persistJournalTaxAccountMappings(array $analysis, int $business_id, int $user_id, array $options): void
    {
        $selections = $options['tax_account_mappings'] ?? [];
        $account_mappings = $options['account_mappings'] ?? [];

        $this->validateJournalTaxAccountMappingSelections(
            $selections,
            $business_id,
            $analysis['entries'] ?? [],
            $account_mappings
        );

        $lines_by_key = [];
        foreach ($analysis['entries'] ?? [] as $entry) {
            foreach ($entry['lines'] ?? [] as $line) {
                $lines_by_key[$this->journalLineAccountKey($line)] = $line;
            }
        }

        $account_cache = $this->getAccountingAccountCache($business_id);
        foreach ($selections as $account_key => $selection) {
            $selection = trim((string) $selection);
            if ($selection === '') {
                continue;
            }

            $line = $lines_by_key[$account_key] ?? null;
            if ($line === null) {
                continue;
            }

            $accounting_account_id = null;
            if (! empty($account_mappings[$account_key])) {
                $accounting_account_id = AccountingAccount::where('business_id', $business_id)
                    ->where('id', (int) $account_mappings[$account_key])
                    ->value('id');
            }
            if (empty($accounting_account_id)) {
                $account = $this->findAccountInCache($line, $account_cache);
                $accounting_account_id = $account['id'] ?? null;
            }
            if (empty($accounting_account_id)) {
                continue;
            }

            $mapping_query = DB::table('accounting_journal_tax_account_mappings')
                ->where('business_id', $business_id)
                ->where('accounting_account_id', $accounting_account_id);

            if ($selection === '__clear__') {
                $mapping_query->delete();
                continue;
            }

            $existing_mapping = $mapping_query->first();
            if (! empty($existing_mapping) && (int) $existing_mapping->tax_rate_id === (int) $selection) {
                continue;
            }

            $now = now();
            if (empty($existing_mapping)) {
                DB::table('accounting_journal_tax_account_mappings')->insert([
                    'business_id' => $business_id,
                    'accounting_account_id' => $accounting_account_id,
                    'tax_rate_id' => (int) $selection,
                    'created_by' => $user_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $mapping_query->update([
                    'tax_rate_id' => (int) $selection,
                    'created_by' => $user_id,
                    'updated_at' => $now,
                ]);
            }
        }
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

        foreach ($this->readJournalWorkbookRows(public_path($path), $progressCallback) as $index => $row) {
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

        return [
            'source_date_range' => $source_date_range,
            'total_entries' => count($entries),
            'types' => $types,
            'journal_entry_count' => count(array_filter($entries, fn ($entry) => $entry['source_type'] === 'Journal Entry')),
            'non_journal_count' => count(array_filter($entries, fn ($entry) => $entry['source_type'] !== 'Journal Entry')),
            'unbalanced_count' => count(array_filter($entries, fn ($entry) => $entry['is_unbalanced'])),
            'duplicate_count' => count($duplicate_keys),
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
        $definitions = [
            'Journal Entry' => ['model' => null, 'record_type' => null],
            'Sales Invoice' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['sell']],
            'Purchase Invoice' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['purchase']],
            'Expense' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['expense']],
            'Purchase Payment' => ['model' => 'payment', 'record_type' => TransactionPayment::class, 'types' => ['purchase']],
            'Receive Payment' => ['model' => 'payment', 'record_type' => TransactionPayment::class, 'types' => ['sell']],
            'Bank Deposit' => ['model' => 'account_transaction', 'record_type' => AccountTransaction::class],
            'Bank Transfer' => ['model' => 'account_transaction', 'record_type' => AccountTransaction::class],
            'Bank Withdrawal' => ['model' => 'account_transaction', 'record_type' => AccountTransaction::class],
            'Stock Adjustment' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['stock_adjustment']],
            'Stock In/Out' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['opening_stock', 'sell_transfer', 'purchase_transfer', 'stock_adjustment']],
            'Opening Balance' => ['model' => 'transaction', 'record_type' => Transaction::class, 'types' => ['opening_balance', 'opening_stock']],
            'Reversal' => ['model' => 'account_transaction', 'record_type' => AccountTransaction::class],
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

            foreach ($typedEntries as [$key, $entry]) {
                $sourceNumber = trim((string) ($entry['source_number'] ?? ''));
                $matches = $candidates->filter(function ($candidate) use ($sourceNumber) {
                    if ($sourceNumber === '') {
                        return false;
                    }
                    foreach ($candidate['references'] as $reference) {
                        if (strcasecmp($reference, $sourceNumber) === 0) {
                            return true;
                        }
                    }

                    return false;
                })->unique('id')->values();

                if ($matches->isEmpty() && $sourceNumber !== '') {
                    $matches = $candidates->filter(function ($candidate) use ($sourceNumber) {
                        if ((string) $candidate['id'] === $sourceNumber) {
                            return true;
                        }

                        if (preg_match('/^(\d+)\/(.+)$/', $sourceNumber, $idAndReference)
                            && (string) $candidate['id'] === $idAndReference[1]) {
                            foreach ($candidate['references'] as $reference) {
                                if (strcasecmp($reference, $idAndReference[2]) === 0) {
                                    return true;
                                }
                            }
                        }

                        return false;
                    })->unique('id')->values();
                }

                if ($matches->count() > 1) {
                    $dateMatches = $matches->filter(fn ($candidate) => $candidate['date'] === $entry['date'])->values();
                    if ($dateMatches->count() === 1) {
                        $matches = $dateMatches;
                    } else {
                        $amountCandidates = $dateMatches->isNotEmpty() ? $dateMatches : $matches;
                        $amountMatches = $amountCandidates
                            ->filter(fn ($candidate) => abs((float) $candidate['amount'] - (float) $entry['debit_total']) <= 0.01)
                            ->values();
                        if ($amountMatches->count() === 1) {
                            $matches = $amountMatches;
                        }
                    }
                }

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
        $full_path = realpath(public_path($path));
        $allowed_path = realpath(public_path('uploads/temp/journal_imports'));

        if (! empty($full_path) && ! empty($allowed_path) && Str::startsWith($full_path, $allowed_path) && file_exists($full_path)) {
            @unlink($full_path);
        }
    }

    public function writeTemporaryJournalAnalysis($analysis)
    {
        $upload_path = public_path('uploads/temp/journal_imports');
        if (! file_exists($upload_path)) {
            mkdir($upload_path, 0755, true);
        }

        $file_name = Str::uuid().'.json';
        $path = 'uploads/temp/journal_imports/'.$file_name;
        file_put_contents(public_path($path), json_encode($analysis));

        return $path;
    }

    public function compactJournalAnalysisSummary($analysis)
    {
        unset($analysis['entries']);

        return $analysis;
    }

    public function readTemporaryJournalAnalysis($path)
    {
        $full_path = realpath(public_path($path));
        $allowed_path = realpath(public_path('uploads/temp/journal_imports'));

        if (empty($full_path) || empty($allowed_path) || ! Str::startsWith($full_path, $allowed_path) || ! file_exists($full_path)) {
            throw new \Exception('File analisa import tidak ditemukan.');
        }

        $analysis = json_decode(file_get_contents($full_path), true);
        if (empty($analysis) || ! isset($analysis['entries'])) {
            throw new \Exception('File analisa import tidak valid.');
        }

        return $analysis;
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

    private function getJournalTaxMappingAccounts(array $entries, int $business_id): array
    {
        $account_cache = $this->getAccountingAccountCache($business_id);
        $accounts = [];

        foreach ($entries as $entry) {
            foreach ($entry['lines'] ?? [] as $line) {
                $key = $this->journalLineAccountKey($line);
                if (isset($accounts[$key])) {
                    continue;
                }

                $system_account = $this->findAccountInCache($line, $account_cache);
                $accounts[$key] = [
                    'key' => $key,
                    'account_no' => $line['account_no'],
                    'account_name' => $line['account_name'],
                    'accounting_account_id' => $system_account['id'] ?? null,
                    'accounting_account_name' => $system_account['name'] ?? null,
                    'tax_rate_id' => null,
                    'clear_stale_mapping' => false,
                ];
            }
        }

        $account_ids = collect($accounts)
            ->pluck('accounting_account_id')
            ->filter()
            ->unique()
            ->values();

        if ($account_ids->isEmpty()) {
            return array_values($accounts);
        }

        $mappings = DB::table('accounting_journal_tax_account_mappings as journal_tax_mappings')
            ->leftJoin('tax_rates as mapped_tax_rates', function ($join) {
                $join->on('mapped_tax_rates.id', '=', 'journal_tax_mappings.tax_rate_id')
                    ->on('mapped_tax_rates.business_id', '=', 'journal_tax_mappings.business_id')
                    ->whereNull('mapped_tax_rates.deleted_at');
            })
            ->where('journal_tax_mappings.business_id', $business_id)
            ->whereIn('journal_tax_mappings.accounting_account_id', $account_ids)
            ->select(
                'journal_tax_mappings.accounting_account_id',
                'journal_tax_mappings.tax_rate_id',
                'mapped_tax_rates.id as active_tax_rate_id'
            )
            ->get()
            ->keyBy('accounting_account_id');

        foreach ($accounts as &$account) {
            $mapping = $mappings->get($account['accounting_account_id']);
            if (empty($mapping)) {
                continue;
            }

            if (empty($mapping->active_tax_rate_id)) {
                $account['clear_stale_mapping'] = true;
            } else {
                $account['tax_rate_id'] = (string) $mapping->tax_rate_id;
            }
        }
        unset($account);

        return array_values($accounts);
    }

    private function getJournalTaxRateOptions(int $business_id): array
    {
        return DB::table('tax_rates')
            ->where('business_id', $business_id)
            ->whereNull('deleted_at')
            ->orderBy('is_tax_group')
            ->orderBy('name')
            ->get(['id', 'name', 'amount', 'is_tax_group'])
            ->mapWithKeys(function ($tax_rate) {
                $prefix = $tax_rate->is_tax_group ? 'Grup: ' : '';
                $label = $prefix.$tax_rate->name;
                if (! $tax_rate->is_tax_group && is_numeric($tax_rate->amount)) {
                    $amount = rtrim(rtrim(number_format((float) $tax_rate->amount, 4, '.', ''), '0'), '.');
                    $label .= ' ('.$amount.'%)';
                }

                return [$tax_rate->id => $label];
            })
            ->all();
    }

    private function validateJournalTaxAccountMappingSelections($selections, int $business_id, array $entries = [], $account_mappings = []): void
    {
        if (! is_array($selections) || ! is_array($account_mappings)) {
            throw ValidationException::withMessages([
                'tax_account_mappings' => 'Format pemetaan akun pajak tidak valid.',
            ]);
        }

        $tax_rate_ids = [];
        foreach ($selections as $account_key => $selection) {
            if (! is_string($selection) && ! is_numeric($selection) && $selection !== null) {
                throw ValidationException::withMessages([
                    'tax_account_mappings' => 'Pilihan TaxRate tidak valid.',
                ]);
            }

            $selection = trim((string) $selection);
            if ($selection === '' || $selection === '__clear__') {
                continue;
            }

            if (! ctype_digit($selection)) {
                throw ValidationException::withMessages([
                    'tax_account_mappings' => 'Pilihan TaxRate harus berasal dari daftar business ini.',
                ]);
            }

            $tax_rate_ids[] = (int) $selection;
        }

        $tax_rate_ids = array_values(array_unique($tax_rate_ids));
        if ($tax_rate_ids !== []) {
            $valid_tax_rate_ids = DB::table('tax_rates')
                ->where('business_id', $business_id)
                ->whereNull('deleted_at')
                ->whereIn('id', $tax_rate_ids)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($valid_tax_rate_ids) !== count($tax_rate_ids)) {
                throw ValidationException::withMessages([
                    'tax_account_mappings' => 'TaxRate atau grup pajak harus berasal dari business yang aktif.',
                ]);
            }
        }

        $selected_keys = collect($selections)
            ->filter(fn ($selection) => trim((string) $selection) !== '')
            ->keys();
        if ($selected_keys->isEmpty() || $entries === []) {
            return;
        }

        $lines_by_key = [];
        foreach ($entries as $entry) {
            foreach ($entry['lines'] ?? [] as $line) {
                $lines_by_key[$this->journalLineAccountKey($line)] = $line;
            }
        }

        $missing_keys = $selected_keys->filter(fn ($key) => ! isset($lines_by_key[$key]));
        if ($missing_keys->isNotEmpty()) {
            throw ValidationException::withMessages([
                'tax_account_mappings' => 'Pemetaan akun pajak tidak sesuai dengan akun pada workbook.',
            ]);
        }

        $account_cache = $this->getAccountingAccountCache($business_id);
        $account_mapping_ids = collect($account_mappings)
            ->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $valid_account_mapping_ids = $account_mapping_ids->isEmpty()
            ? collect()
            : AccountingAccount::where('business_id', $business_id)
                ->whereIn('id', $account_mapping_ids)
                ->pluck('id')
                ->mapWithKeys(fn ($id) => [(int) $id => true]);

        $resolved_selections = [];
        foreach ($selections as $account_key => $selection) {
            $selection = trim((string) $selection);
            if ($selection === '') {
                continue;
            }

            $line = $lines_by_key[$account_key];
            $account_id = null;
            $requested_account_id = $account_mappings[$account_key] ?? null;
            if (is_scalar($requested_account_id)
                && ctype_digit((string) $requested_account_id)
                && $valid_account_mapping_ids->has((int) $requested_account_id)) {
                $account_id = (int) $requested_account_id;
            } else {
                $account = $this->findAccountInCache($line, $account_cache);
                $account_id = $account['id'] ?? null;
            }

            if ($account_id) {
                $identity = 'account:'.$account_id;
            } elseif (trim((string) $line['account_no']) !== '') {
                $identity = 'new-gl:'.strtolower(trim((string) $line['account_no']));
            } else {
                $identity = 'new-name:'.strtolower(trim((string) $line['account_name']));
            }
            if (isset($resolved_selections[$identity]) && $resolved_selections[$identity] !== $selection) {
                throw ValidationException::withMessages([
                    'tax_account_mappings' => 'Satu akun GL tidak dapat dipetakan ke lebih dari satu TaxRate atau grup dalam import yang sama.',
                ]);
            }
            $resolved_selections[$identity] = $selection;
        }
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
