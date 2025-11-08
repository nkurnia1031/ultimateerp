<button type="button"
    data-href="{{ action([\App\Http\Controllers\StockAdjustmentController::class, 'show'], [$row->id]) }}"
    class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  tw-dw-btn-primary btn-modal" data-container=".view_modal"><i
        class="fa fa-eye" aria-hidden="true"></i> @lang('messages.view')</button>
&nbsp;
@if ($row->type != 'opening_stock')
    <button type="button"
        data-href="{{ action([\App\Http\Controllers\StockAdjustmentController::class, 'destroy'], [$row->id]) }}"
        class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  tw-dw-btn-error delete_stock_adjustment {{ $hide }}"><i
            class="fa fa-trash" aria-hidden="true"></i> @lang('messages.delete')</button>
@else
    <button type="button"
        data-href="{{ action([\App\Http\Controllers\PurchaseController::class, 'destroy'], [$row->id]) }}"
        class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  tw-dw-btn-error delete_stock_adjustment  {{ $hide }}"><i
            class="fa fa-trash" aria-hidden="true"></i> @lang('messages.delete')</button>
@endif
