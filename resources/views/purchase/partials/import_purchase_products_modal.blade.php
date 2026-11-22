<div class="modal fade" tabindex="-1" role="dialog" id="import_purchase_products_modal">
	<div class="modal-dialog modal-lg" role="document">
  		<div class="modal-content">
  			<div class="modal-header">
			    <button type="button" class="close no-print" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
			    <h4 class="modal-title">@lang('product.import_products')</h4>
			</div>
			<div class="modal-body">
				<div class="row">
					<div class="col-md-12">
						<strong>@lang( 'product.file_to_import' ):</strong>
					</div>
					<div class="col-md-12">
						<div id="import_product_dz" class="dropzone"></div>
						<div class="progress" id="purchase_product_import_progress_wrap" style="display:none;margin-top:10px">
							<div id="purchase_product_import_progress" class="progress-bar" role="progressbar" style="width:0%">0%</div>
						</div>
						<p id="purchase_product_import_status" class="text-muted" aria-live="polite"></p>
					</div>
					<div class="col-md-12 mt-10">
						<a href="{{ asset('files/import_purchase_products_template.xls') }}" class="tw-dw-btn tw-dw-btn-success tw-text-white" download><i class="fa fa-download"></i> @lang('lang_v1.download_template_file')</a>
					</div>
				</div>
				<div class="row">
					<div class="col-md-12">
						<h4>{{__('lang_v1.instructions')}}:</h4>
						<strong>@lang('lang_v1.instruction_line1')</strong><br>
		                    @lang('lang_v1.instruction_line2')
		                    <br><br>
						<table class="table table-striped">
		                    <tr>
		                        <th>@lang('lang_v1.col_no')</th>
		                        <th>@lang('lang_v1.col_name')</th>
		                        <th>@lang('lang_v1.instruction')</th>
		                    </tr>
		                    <tr>
		                    	<td>1</td>
		                        <td>@lang('product.sku') <small class="text-muted">(@lang('lang_v1.required'))</small></td>
		                        <td></td>
		                    </tr>
		                    <tr>
		                    	<td>2</td>
		                        <td>@lang('purchase.purchase_quantity') <small class="text-muted">(@lang('lang_v1.required'))</small></td>
		                        <td></td>
		                    </tr>
		                    <tr>
		                    	<td>3</td>
		                        <td>@lang( 'lang_v1.unit_cost_before_discount' ) <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
		                        <td></td>
		                    </tr>
		                    <tr>
		                    	<td>4</td>
		                        <td>@lang( 'lang_v1.discount_percent' ) <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
		                        <td></td>
		                    </tr>
		                    <tr>
		                    	<td>5</td>
		                        <td>@lang( 'purchase.product_tax' ) <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
		                        <td></td>
		                    </tr>
		                    <tr>
		                    	<td>6</td>
		                        <td>@lang('lang_v1.lot_number') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
		                        <td>{!! __('lang_v1.lot_number_instructions') !!}</td>
		                    </tr>
		                    <tr>
		                    	<td>7</td>
		                        <td>@lang('product.mfg_date') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
		                        <td>{!! __('lang_v1.exp_date_instructions') !!} <br>{!! __('lang_v1.date_ins') !!} </td>
		                    </tr>
		                    <tr>
		                    	<td>8</td>
		                        <td>@lang('product.exp_date') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
		                        <td>{!! __('lang_v1.exp_date_instructions') !!} <br>{!! __('lang_v1.date_ins') !!}</td>
		                    </tr>
		                </table>
		            </div>
				</div>
				<hr>
				<h4>Riwayat impor baris Purchase</h4>
				<table class="table table-bordered table-striped" id="purchase_product_import_history" width="100%">
					<thead><tr><th>ID</th><th>Workbook</th><th>Status</th><th>Progress</th><th>Waktu</th><th>Aksi</th></tr></thead>
				</table>
			</div>
			<div class="modal-footer">
      			<button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white" id="import_purchase_products"> @lang( 'lang_v1.import' )</button>
      			<button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white no-print" data-dismiss="modal">@lang( 'messages.close' )</button>
    		</div>
  		</div>
  	</div>
</div>
