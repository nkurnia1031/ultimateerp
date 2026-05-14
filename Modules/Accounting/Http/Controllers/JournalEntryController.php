<?php

namespace Modules\Accounting\Http\Controllers;

use App\Account;
use App\AccountTransaction;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Entities\AccountingAccTransMapping;
use Modules\Accounting\Jobs\AnalyzeJournalImportJob;
use Modules\Accounting\Jobs\ProcessJournalImportJob;
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

        return view('accounting::journal_entry.import')->with(compact('imports'));
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
            'last_message' => 'File uploaded. Waiting for analyzer worker...',
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

        return view('accounting::journal_entry.import')
            ->with(compact('batch', 'analysis', 'path', 'analysis_path', 'imports', 'original_file_name', 'account_options', 'main_account_options'));
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
            ->where('status', 'analyzed')
            ->first();

        if (empty($batch)) {
            return response()->json([
                'success' => 0,
                'msg' => 'Batch belum siap untuk import.',
            ], 422);
        }

        DB::table('accounting_journal_imports')
            ->where('id', $batch->id)
            ->update([
                'status' => 'queued_import',
                'processed_entries' => 0,
                'import_options' => json_encode($request->except(['_token', 'import_id'])),
                'last_message' => 'Waiting for import worker...',
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
        ]);

        $analysis = $this->readTemporaryJournalAnalysis($request->input('analysis_path'));
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

            DB::table('accounting_journal_imports')
                ->where('id', $request->input('import_id'))
                ->where('business_id', $business_id)
                ->update([
                    'imported_entries' => DB::raw('imported_entries + '.$result['imported']),
                    'skipped_entries' => DB::raw('skipped_entries + '.$result['skipped']),
                    'processed_entries' => $next_offset,
                    'status' => $status,
                    'last_message' => 'Processed '.$next_offset.' / '.count($analysis['entries']),
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
        ]);

        if (! file_exists(public_path($request->input('file_path')))) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => __('messages.something_went_wrong'),
            ]);
        }

        $analysis = $this->analyzeJournalWorkbook($request->input('file_path'), $business_id);

        try {
            DB::beginTransaction();

            $user_id = request()->session()->get('user.id');
            $include_non_journal = $request->boolean('include_non_journal');
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
                'unsupported_entries' => $analysis['non_journal_count'],
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

                if ($entry['source_type'] !== 'Journal Entry' && ! $include_non_journal) {
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

            DB::table('accounting_journal_imports')
                ->where('id', $journal_import_id)
                ->update([
                    'imported_entries' => $imported,
                    'skipped_entries' => $skipped,
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
        $include_non_journal = $request->boolean('include_non_journal');
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

            if ($entry['source_type'] !== 'Journal Entry' && ! $include_non_journal) {
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
        $include_non_journal = ! empty($options['include_non_journal']);
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

            if ($entry['source_type'] !== 'Journal Entry' && ! $include_non_journal) {
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

    public function analyzeJournalWorkbook($path, $business_id)
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

        foreach ($this->readJournalWorkbookRows(public_path($path)) as $index => $row) {
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
            $entries[$key]['duplicate_matches'] = $this->findExistingFinancialMatches($entry, $business_id);
            $entries[$key]['exists_in_system'] = isset($existing_source_keys[$entry['source_key']]) || ! empty($entries[$key]['duplicate_matches']);
            $entries[$key]['line_count'] = count($entry['lines']);
        }

        $types = [];
        foreach ($entries as $entry) {
            $types[$entry['source_type']] = ($types[$entry['source_type']] ?? 0) + 1;
        }

        return [
            'source_date_range' => $source_date_range,
            'total_entries' => count($entries),
            'types' => $types,
            'journal_entry_count' => count(array_filter($entries, fn ($entry) => $entry['source_type'] === 'Journal Entry')),
            'non_journal_count' => count(array_filter($entries, fn ($entry) => $entry['source_type'] !== 'Journal Entry')),
            'unbalanced_count' => count(array_filter($entries, fn ($entry) => $entry['is_unbalanced'])),
            'duplicate_count' => count($duplicate_keys),
            'existing_count' => count(array_filter($entries, fn ($entry) => $entry['exists_in_system'])),
            'missing_accounts' => array_values($missing_accounts),
            'entries' => $entries,
        ];
    }

    private function readJournalWorkbookRows($full_path)
    {
        $reader = IOFactory::createReaderForFile($full_path);
        $reader->setReadDataOnly(true);
        $worksheet_info = $reader->listWorksheetInfo($full_path);
        $highest_row = (int) ($worksheet_info[0]['totalRows'] ?? 0);
        $highest_column = $worksheet_info[0]['lastColumnLetter'] ?? 'E';
        $chunk_size = 500;

        for ($start_row = 1; $start_row <= $highest_row; $start_row += $chunk_size) {
            $end_row = min($start_row + $chunk_size - 1, $highest_row);
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
                yield ($start_row + $offset - 1) => $row;
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $sheet, $rows);
        }
    }

    private function findExistingFinancialMatches($entry, $business_id)
    {
        if (empty($entry['date'])) {
            return [];
        }

        $matches = [];
        $date = $entry['date'];
        $source_number = (string) $entry['source_number'];
        $ref_no = $entry['source_type'].' #'.$entry['source_number'];
        $amount = round((float) $entry['debit_total'], 4);

        $accounting_duplicate = DB::table('accounting_acc_trans_mappings as atm')
            ->join('accounting_accounts_transactions as aat', 'aat.acc_trans_mapping_id', '=', 'atm.id')
            ->where('atm.business_id', $business_id)
            ->whereDate('atm.operation_date', $date)
            ->where(function ($q) use ($ref_no, $source_number) {
                $q->where('atm.ref_no', $ref_no)
                    ->orWhere('atm.ref_no', $source_number)
                    ->orWhere('atm.ref_no', 'like', '%#'.$source_number);
            })
            ->where('aat.type', 'debit')
            ->groupBy('atm.id', 'atm.ref_no')
            ->havingRaw('ROUND(SUM(aat.amount), 4) = ?', [$amount])
            ->select('atm.ref_no')
            ->first();
        if (! empty($accounting_duplicate)) {
            $matches[] = 'Accounting journal: '.$accounting_duplicate->ref_no;
        }

        $transaction_duplicate = DB::table('transactions')
            ->where('business_id', $business_id)
            ->whereDate('transaction_date', $date)
            ->where(function ($q) use ($source_number, $ref_no) {
                $q->where('invoice_no', $source_number)
                    ->orWhere('ref_no', $source_number)
                    ->orWhere('invoice_no', $ref_no)
                    ->orWhere('ref_no', $ref_no);
            })
            ->whereRaw('ROUND(final_total, 4) = ?', [$amount])
            ->select('id', 'type', 'invoice_no', 'ref_no')
            ->first();
        if (! empty($transaction_duplicate)) {
            $matches[] = 'Transaction '.$transaction_duplicate->type.': '.($transaction_duplicate->invoice_no ?: $transaction_duplicate->ref_no);
        }

        $payment_duplicate = DB::table('transaction_payments as tp')
            ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->whereDate('tp.paid_on', $date)
            ->where(function ($q) use ($source_number, $ref_no) {
                $q->where('tp.payment_ref_no', $source_number)
                    ->orWhere('tp.payment_ref_no', $ref_no);
            })
            ->whereRaw('ROUND(tp.amount, 4) = ?', [$amount])
            ->select('tp.payment_ref_no')
            ->first();
        if (! empty($payment_duplicate)) {
            $matches[] = 'Payment: '.$payment_duplicate->payment_ref_no;
        }

        $account_transaction_duplicate = DB::table('account_transactions as at')
            ->join('accounts as a', 'at.account_id', '=', 'a.id')
            ->where('a.business_id', $business_id)
            ->whereNull('at.deleted_at')
            ->whereDate('at.operation_date', $date)
            ->where(function ($q) use ($source_number, $ref_no) {
                $q->where('at.reff_no', $source_number)
                    ->orWhere('at.reff_no', $ref_no);
            })
            ->whereRaw('ROUND(at.amount, 4) = ?', [$amount])
            ->select('at.reff_no')
            ->first();
        if (! empty($account_transaction_duplicate)) {
            $matches[] = 'Payment account transaction: '.$account_transaction_duplicate->reff_no;
        }

        return $matches;
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
