@php
$record_not_available = true;
@endphp
@foreach($users as $user)
	<div class="box box-solid box-success">
		<div class="box-body">
			<div class="table-responsive">
				<caption>
					<span class="font-17 text-bold">
						{{$user->user_full_name}}:
					</span>
				</caption>
				@foreach($user->projects as $project)
					@if($project->timeLogs->count() > 0)
						@php
							$record_not_available = false;
						@endphp
						<ol>
							<strong><code>{{$project->name}}</code>:</strong>
						</ol>
						<ol>
			    			<table class="table table-striped">
			    				<thead>
			    					<tr>
			    						<th>@lang('project::lang.task')</th>
									<th>@lang('project::lang.shift')</th>
									<th>@lang('project::lang.work_period_1')</th>
									<th>@lang('project::lang.work_period_2')</th>
									<th>@lang('project::lang.total_work_hours')</th>
			    						<th>
			    							@lang('brand.note')
			    						</th>
			    					</tr>
			    				</thead>
			    				<tbody>
			    					@php
			    						$total_sec = 0;
			    					@endphp
				    				@foreach($project->timeLogs as $timeLog)
				    					@php
										$second = $timeLog->total_seconds;
		        							$total_sec += $second;
				    					@endphp
				    					<tr>
				    						<td>
				    							@if(isset($timeLog->task))
				    								{{$timeLog->task->subject}}
				    								<small>
				    									<code>
				    										{{$timeLog->task->task_id}}
				    									</code>
				    								</small>
				    							@endif
				    						</td>
											<td>
												{{ $timeLog->shift ? __('project::lang.shift_'.$timeLog->shift) : '-' }}
											</td>
											<td>
												{{@format_datetime($timeLog->start_datetime)}} - {{@format_datetime($timeLog->end_datetime)}}
											</td>
											<td>
												@if($timeLog->second_start_datetime && $timeLog->second_end_datetime)
													{{@format_datetime($timeLog->second_start_datetime)}} - {{@format_datetime($timeLog->second_end_datetime)}}
												@else - @endif
											</td>
											<td>
												{{$timeLog->formatted_duration}}
											</td>
											<td>
												{{$timeLog->note}}
											</td>
				    					</tr>
				    				@endforeach
				    			</tbody>
				    			<tfoot>
				    				<tr class="bg-gray">
									<td colspan="4"></td>
				    					<td>
				    						@php
												$hours = floor($total_sec / 3600);
												$minutes = floor(($total_sec / 60) % 60);
											@endphp
											{{sprintf('%02d:%02d', $hours, $minutes)}}
				    					</td>
				    					<td></td>
				    				</tr>
				    			</tfoot>
			    			</table>
						</ol>
			    	@endif
				@endforeach
			</div>
		</div>
	</div>
@endforeach
@if($record_not_available)
	<div class="callout callout-info">
        <h4>
        	<i class="fa fa-warning"></i>
        	@lang('project::lang.no_record_found')
        </h4>
    </div>
@endif
