<div class="row">
    <div class="col-md-4 col-sm-6 col-xs-12">
        <div class="form-group">
            {!! Form::label('tag_ledger_date_range', __('report.date_range') . ':') !!}
            {!! Form::text('tag_ledger_date_range', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'readonly']); !!}
        </div>
    </div>
    <div class="col-md-4 col-sm-6 col-xs-12">
        <div class="form-group">
            {!! Form::label('tag_ledger_location', __('purchase.business_location') . ':') !!}
            {!! Form::select('tag_ledger_location', $business_locations, null, ['class' => 'form-control select2', 'id' => 'tag_ledger_location']); !!}
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-12" id="tag_ledger_div"></div>
</div>
