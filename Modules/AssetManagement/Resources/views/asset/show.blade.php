<div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
            <h4 class="modal-title">
                @lang('assetmanagement::lang.view_asset')
                <small><code>{{$asset->asset_code}}</code></small>
            </h4>
        </div>
        <div class="modal-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-condensed">
                        <tr>
                            <td><strong>@lang('assetmanagement::lang.asset_name')</strong></td>
                            <td>{{$asset->name}}</td>
                        </tr>
                        <tr>
                            <td><strong>@lang('assetmanagement::lang.asset_code')</strong></td>
                            <td>{{$asset->asset_code}}</td>
                        </tr>
                        <tr>
                            <td><strong>@lang('business.business_location')</strong></td>
                            <td>{{$asset->businessLocation->name ?? ''}}</td>
                        </tr>
                        <tr>
                            <td><strong>@lang('assetmanagement::lang.asset_category')</strong></td>
                            <td>{{$asset->category_name}}</td>
                        </tr>
                        <tr>
                            <td><strong>@lang('lang_v1.quantity')</strong></td>
                            <td>{{@format_quantity($asset->quantity)}}</td>
                        </tr>
                        <tr>
                            <td><strong>@lang('assetmanagement::lang.is_allocatable')</strong></td>
                            <td>
                                @if($asset->is_allocatable)
                                    <i class="fas fa-check-circle text-success"></i>
                                @else
                                    <i class="fas fa-times-circle text-danger"></i>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-condensed">
                        <tr>
                            <td><strong>@lang('assetmanagement::lang.series_model')</strong></td>
                            <td>{{$asset->model}}</td>
                        </tr>
                        <tr>
                            <td><strong>@lang('assetmanagement::lang.serial_no')</strong></td>
                            <td>{{$asset->serial_no}}</td>
                        </tr>
                        <tr>
                            <td><strong>@lang('purchase.purchase_date')</strong></td>
                            <td>@if(!empty($asset->purchase_date)) {{@format_date($asset->purchase_date)}} @endif</td>
                        </tr>
                        <tr>
                            <td><strong>@lang('assetmanagement::lang.purchase_type')</strong></td>
                            <td>{{$asset->purchase_type}}</td>
                        </tr>
                        <tr>
                            <td><strong>@lang('sale.unit_price')</strong></td>
                            <td>@format_currency($asset->unit_price)</td>
                        </tr>
                        <tr>
                            <td><strong>@lang('assetmanagement::lang.depreciation')</strong></td>
                            <td>@if(!empty($asset->depreciation)) @format_currency($asset->depreciation) @endif</td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-12">
                    <table class="table table-condensed">
                        <tr>
                            <td style="width: 25%;"><strong>Tags</strong></td>
                            <td>
                                @forelse($asset->tags as $tag)
                                    <span class="label bg-blue">{{$tag->name}}</span>
                                @empty
                                    <span class="text-muted">-</span>
                                @endforelse
                            </td>
                        </tr>
                        <tr>
                            <td><strong>@lang('lang_v1.description')</strong></td>
                            <td>{!! nl2br(e($asset->description)) !!}</td>
                        </tr>
                        <tr>
                            <td><strong>STNK</strong></td>
                            <td>
                                @if(!empty($asset->stnk))
                                    <a href="{{url('uploads/documents/' . $asset->stnk)}}" download>
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                @endif
                                @if(!empty($asset->stnk_expired_at))
                                    <br><small>{{@format_date($asset->stnk_expired_at)}}</small>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><strong>TBPKP</strong></td>
                            <td>
                                @if(!empty($asset->tbpkp_expired_at))
                                    {{@format_date($asset->tbpkp_expired_at)}}
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <a href="{{action([\Modules\AssetManagement\Http\Controllers\AssetController::class, 'tagLedger'], [$asset->id])}}" class="btn btn-info">
                <i class="fas fa-scroll"></i> Tag Ledger
            </a>
            <a href="{{ route('asset.fleet.trips.asset-ledger', ['assetId' => $asset->id]) }}" class="btn btn-primary">
                <i class="fas fa-truck-moving"></i> Ledger Trip Angkutan
            </a>
            <button type="button" class="btn btn-default" data-dismiss="modal">
                @lang('messages.close')
            </button>
        </div>
    </div>
</div>
