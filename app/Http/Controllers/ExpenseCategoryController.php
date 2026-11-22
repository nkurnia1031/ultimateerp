<?php

namespace App\Http\Controllers;

use App\ExpenseCategory;
use App\Business;
use App\Services\ExpenseCategoryBusinessImportService;
use App\Services\BusinessCopyImportBatchService;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class ExpenseCategoryController extends Controller
{
    public function __construct(private ExpenseCategoryBusinessImportService $businessCategoryImport)
    {
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (! (auth()->user()->can('expense.add') || auth()->user()->can('expense.edit'))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $expense_category = ExpenseCategory::where('business_id', $business_id)
                        ->select(['name', 'code', 'id', 'parent_id']);

            return Datatables::of($expense_category)
                ->addColumn(
                    'action',
                    '<button data-href="{{action(\'App\Http\Controllers\ExpenseCategoryController@edit\', [$id])}}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary btn-modal" data-container=".expense_category_modal"><i class="glyphicon glyphicon-edit"></i>  @lang("messages.edit")</button>
                        &nbsp;
                        <button data-href="{{action(\'App\Http\Controllers\ExpenseCategoryController@destroy\', [$id])}}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error delete_expense_category"><i class="glyphicon glyphicon-trash"></i> @lang("messages.delete")</button>'
                )
                ->editColumn('name', function ($row) {
                    if (! empty($row->parent_id)) {
                        return '--'.$row->name;
                    } else {
                        return $row->name;
                    }
                })
                ->removeColumn('id')
                ->removeColumn('parent_id')
                ->rawColumns([2])
                ->make(false);
        }

        $business_id = (int) request()->session()->get('user.business_id');
        $canImportAcrossBusinesses = auth()->user()->can('superadmin') || auth()->user()->can('admin');
        $import_businesses = Business::query()
            ->when(! $canImportAcrossBusinesses, fn ($query) => $query->where('id', $business_id))
            ->where('id', '!=', $business_id)
            ->orderBy('name')
            ->pluck('name', 'id');

        return view('expense_category.index')->with(compact('import_businesses'));
    }

    public function previewBusinessImport(Request $request)
    {
        $validated = $request->validate(['source_business_id' => ['required', 'integer']]);
        $targetBusinessId = (int) $request->session()->get('user.business_id');
        $sourceBusinessId = (int) $validated['source_business_id'];
        $this->authorizeBusinessImport($sourceBusinessId, $targetBusinessId);

        return response()->json($this->businessCategoryImport->preview($sourceBusinessId, $targetBusinessId));
    }

    public function businessImportPreviewPage(Request $request)
    {
        $validated = $request->validate([
            'source_business_id' => ['required', 'integer'],
            'skip_existing' => ['nullable', 'boolean'],
        ]);
        $targetBusinessId = (int) $request->session()->get('user.business_id');
        $sourceBusinessId = (int) $validated['source_business_id'];
        $this->authorizeBusinessImport($sourceBusinessId, $targetBusinessId);

        return view('expense_category.business_import_preview', [
            'sourceBusiness' => Business::findOrFail($sourceBusinessId),
            'skipExisting' => (bool) ($validated['skip_existing'] ?? true),
        ]);
    }

    public function importFromBusiness(Request $request, BusinessCopyImportBatchService $copyBatches)
    {
        $validated = $request->validate([
            'source_business_id' => ['required', 'integer'],
            'skip_existing' => ['nullable', 'boolean'],
        ]);
        $targetBusinessId = (int) $request->session()->get('user.business_id');
        $sourceBusinessId = (int) $validated['source_business_id'];
        $this->authorizeBusinessImport($sourceBusinessId, $targetBusinessId);
        try {
            $queued = $copyBatches->queue('expense_categories', $sourceBusinessId, $targetBusinessId, (int) auth()->id(), [
                'skip_existing' => (bool) ($validated['skip_existing'] ?? true),
            ]);
        } catch (\Throwable $exception) {
            \Log::warning('Expense category business import failed.', [
                'source_business_id' => $sourceBusinessId,
                'target_business_id' => $targetBusinessId,
                'user_id' => auth()->id(),
                'exception' => $exception,
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage() ?: 'Import kategori biaya gagal.',
                ], 422);
            }

            return redirect()->route('expense-categories.index')->withErrors([
                'import' => $exception->getMessage() ?: 'Import kategori biaya gagal.',
            ]);
        }

        $batch = $queued['batch'];
        $message = $queued['duplicate']
            ? 'Salinan ini sudah tercatat pada batch #'.$batch->id.'.'
            : 'Salin kategori biaya masuk antrean pada batch #'.$batch->id.'.';
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'batch_id' => $batch->id, 'status' => $batch->status, 'message' => $message], 202);
        }

        return redirect()->route('expense-categories.index')->with('status', [
            'success' => true,
            'msg' => $message,
        ]);
    }

    private function authorizeBusinessImport(int $sourceBusinessId, int $targetBusinessId): void
    {
        abort_unless(
            auth()->user()->can('expense.add')
            || auth()->user()->can('expense.edit')
            || auth()->user()->can('admin')
            || auth()->user()->can('superadmin'),
            403,
            'Anda tidak memiliki izin import kategori biaya.'
        );
        abort_unless($sourceBusinessId !== $targetBusinessId, 422, 'Bisnis sumber dan tujuan harus berbeda.');
        abort_unless(Business::whereKey($sourceBusinessId)->exists(), 404, 'Bisnis sumber tidak ditemukan.');
        abort_unless(Business::whereKey($targetBusinessId)->exists(), 404, 'Bisnis tujuan tidak ditemukan.');
        if (! auth()->user()->can('admin') && ! auth()->user()->can('superadmin')) {
            abort(403, 'Hanya admin atau superadmin yang dapat memilih bisnis sumber lain.');
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (! (auth()->user()->can('expense.add') || auth()->user()->can('expense.edit'))) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $categories = ExpenseCategory::where('business_id', $business_id)
                        ->whereNull('parent_id')
                        ->pluck('name', 'id');

        return view('expense_category.create')->with(compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! (auth()->user()->can('expense.add') || auth()->user()->can('expense.edit'))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $input = $request->only(['name', 'code']);
            $input['business_id'] = $request->session()->get('user.business_id');

            if (! empty($request->input('add_as_sub_cat')) && $request->input('add_as_sub_cat') == 1 && ! empty($request->input('parent_id'))) {
                $input['parent_id'] = $request->input('parent_id');
            }

            ExpenseCategory::create($input);
            $output = ['success' => true,
                'msg' => __('expense.added_success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return $output;
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\ExpenseCategory  $expenseCategory
     * @return \Illuminate\Http\Response
     */
    public function show(ExpenseCategory $expenseCategory)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (! (auth()->user()->can('expense.add') || auth()->user()->can('expense.edit'))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $expense_category = ExpenseCategory::where('business_id', $business_id)->find($id);

            $categories = ExpenseCategory::where('business_id', $business_id)
                        ->whereNull('parent_id')
                        ->pluck('name', 'id');

            return view('expense_category.edit')
                    ->with(compact('expense_category', 'categories'));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (! (auth()->user()->can('expense.add') || auth()->user()->can('expense.edit'))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $input = $request->only(['name', 'code']);
                $business_id = $request->session()->get('user.business_id');

                $expense_category = ExpenseCategory::where('business_id', $business_id)->findOrFail($id);
                $expense_category->name = $input['name'];
                $expense_category->code = $input['code'];

                if (! empty($request->input('add_as_sub_cat')) && $request->input('add_as_sub_cat') == 1 && ! empty($request->input('parent_id'))) {
                    $expense_category->parent_id = $request->input('parent_id');
                } else {
                    $expense_category->parent_id = null;
                }

                $expense_category->save();

                $output = ['success' => true,
                    'msg' => __('expense.updated_success'),
                ];
            } catch (\Exception $e) {
                \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

                $output = ['success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (! (auth()->user()->can('expense.add') || auth()->user()->can('expense.edit'))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');

                $expense_category = ExpenseCategory::where('business_id', $business_id)->findOrFail($id);
                $expense_category->delete();

                //delete sub categories also
                ExpenseCategory::where('business_id', $business_id)->where('parent_id', $id)->delete();

                $output = ['success' => true,
                    'msg' => __('expense.deleted_success'),
                ];
            } catch (\Exception $e) {
                \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

                $output = ['success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }

    public function getSubCategories(Request $request)
    {
        if (! empty($request->input('cat_id'))) {
            $category_id = $request->input('cat_id');
            $business_id = $request->session()->get('user.business_id');
            $sub_categories = ExpenseCategory::where('business_id', $business_id)
                        ->where('parent_id', $category_id)
                        ->select(['name', 'id'])
                        ->get();
        }

        $html = '<option value="">'.__('lang_v1.none').'</option>';
        if (! empty($sub_categories)) {
            foreach ($sub_categories as $sub_category) {
                $html .= '<option value="'.$sub_category->id.'">'.$sub_category->name.'</option>';
            }
        }
        echo $html;
        exit;
    }
}
