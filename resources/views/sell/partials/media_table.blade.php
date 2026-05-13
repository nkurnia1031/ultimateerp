<table class="table table-condensed">
	@forelse($medias as $media)
		<tr>
			<td>
				@if(isFileImage($media->display_url))
					{!! $media->thumbnail() !!}
					<br>
				@endif
				{{$media->display_name}}
			</td>
			<td>
				<small>
					@lang('lang_v1.uploaded_by'):
					{{$media->uploaded_by_user->user_full_name}}
				</small>
			</td>
			<td>
				<a href="{{$media->display_url}}" download="{{$media->display_name}}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  tw-dw-btn-accent no-print"><i class="fas fa-download"></i> @lang('lang_v1.download')</a>
				@if(isFileImage($media->display_url) || isFilePdf($media->display_url))
					<button type="button" data-href="{{$media->display_url}}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-info no-print view_uploaded_document"><i class="fas fa-eye"></i> @lang('lang_v1.view_document')</button>
				@endif
				@if(!empty($delete))
					<button type="button" data-href="{{action([\App\Http\Controllers\ProductController::class, 'deleteMedia'], [$media->id])}}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  tw-dw-btn-error delete-media no-print"><i class="fas fa-trash"></i> @lang('messages.delete')</a>
				@endif
			</td>
		</tr>
	@empty
		<tr>
			<td colspan="3" class="text-center">@lang('lang_v1.no_attachment_found')</td>
		</tr>
	@endforelse
</table>
