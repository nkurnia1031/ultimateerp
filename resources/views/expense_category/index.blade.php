@extends('layouts.app')
@section('title', __('expense.expense_categories'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang( 'expense.expense_categories' )
        <small  class="tw-text-sm md:tw-text-base tw-text-gray-700 tw-font-semibold">@lang( 'expense.manage_your_expense_categories' )</small>
    </h1>
</section>

<!-- Main content -->
<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => __( 'expense.all_your_expense_categories' )])
        @slot('tool')
            <div class="box-tools">
                <button type="button" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-primary tw-font-bold tw-rounded-full pull-right tw-mr-2" data-toggle="modal" data-target="#business_expense_category_import_modal">
                    <i class="fa fa-exchange"></i> Import dari Bisnis
                </button>
                <a class="tw-dw-btn tw-bg-gradient-to-r tw-from-indigo-600 tw-to-blue-500 tw-font-bold tw-text-white tw-border-none tw-rounded-full btn-modal pull-right"
                    data-href="{{action([\App\Http\Controllers\ExpenseCategoryController::class, 'create'])}}" 
                    data-container=".expense_category_modal">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="icon icon-tabler icons-tabler-outline icon-tabler-plus">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M12 5l0 14" />
                        <path d="M5 12l14 0" />
                    </svg> @lang('messages.add')
                </a>
            </div>
        @endslot
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="expense_category_table">
                <thead>
                    <tr>
                        <th>@lang( 'expense.category_name' )</th>
                        <th>@lang( 'expense.category_code' )</th>
                        <th>@lang( 'messages.action' )</th>
                    </tr>
                </thead>
            </table>
        </div>
    @endcomponent

    <div class="modal fade expense_category_modal" tabindex="-1" role="dialog" 
    	aria-labelledby="gridSystemModalLabel">
    </div>

    <div class="modal fade" id="business_expense_category_import_modal" tabindex="-1" role="dialog" aria-labelledby="businessExpenseCategoryImportTitle">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form id="business_expense_category_import_form" action="{{ route('expense-categories.import.business') }}" data-preview-url="{{ route('expense-categories.import.business.preview') }}" method="post">
                    @csrf
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="businessExpenseCategoryImportTitle"><i class="fa fa-exchange"></i> Import Kategori Biaya dari Bisnis</h4>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">Pilih bisnis sumber untuk melakukan cross-check. Kategori induk diproses lebih dulu agar subkategori tetap terhubung. Kategori yang sudah ada akan dilewati.</div>
                        <div class="form-group">
                            <label for="business_expense_category_import_source">Bisnis sumber</label>
                            <select class="form-control" id="business_expense_category_import_source" name="source_business_id" required>
                                <option value="">-- Pilih bisnis sumber --</option>
                                @foreach ($import_businesses as $businessId => $businessName)
                                    <option value="{{ $businessId }}">{{ $businessName }}{{ (int) $businessId === (int) session('user.business_id') ? ' (bisnis aktif)' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <label class="checkbox-inline tw-mb-4"><input type="checkbox" name="skip_existing" value="1" checked> Lewati kategori yang sudah ada</label>
                        <div id="business_expense_category_import_loading" class="alert alert-warning" style="display:none"><i class="fa fa-spinner fa-spin"></i> Memeriksa kategori sumber...</div>
                        <div id="business_expense_category_import_error" class="alert alert-danger" style="display:none"></div>
                        <div id="business_expense_category_import_preview" style="display:none">
                            <div class="row">
                                <div class="col-sm-4"><div class="small-box bg-blue"><div class="inner"><h3 data-expense-category-import-count="total">0</h3><p>Total sumber</p></div></div></div>
                                <div class="col-sm-4"><div class="small-box bg-green"><div class="inner"><h3 data-expense-category-import-count="new">0</h3><p>Kategori baru</p></div></div></div>
                                <div class="col-sm-4"><div class="small-box bg-yellow"><div class="inner"><h3 data-expense-category-import-count="existing">0</h3><p>Sudah ada / skip</p></div></div></div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-condensed table-bordered" id="business_expense_category_import_preview_table">
                                    <thead><tr><th>Path kategori</th><th>Nama</th><th>Kode</th><th>Status</th><th>Metode</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            <p class="text-muted" id="business_expense_category_import_truncated" style="display:none">Preview menampilkan maksimal 100 baris. Jumlah total tetap dihitung server.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Tutup</button>
                        <button type="button" class="btn btn-primary" id="business_expense_category_import_preview_button"><i class="fa fa-search"></i> Cross-check</button>
                        <button type="submit" class="btn btn-success" id="business_expense_category_import_submit" disabled><i class="fa fa-upload"></i> Import kategori baru</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @if (auth()->user()->can('admin') || auth()->user()->can('superadmin'))
        @include('import_copies.history', ['copyType' => 'expense_categories', 'historyTitle' => 'Riwayat salin kategori biaya'])
    @endif

</section>
<!-- /.content -->

@endsection

@section('javascript')
    <script src="{{ versioned_asset_url(Module::asset('accounting:js/expense_category_business_import.js'), $asset_v ?? null) }}"></script>
@endsection
