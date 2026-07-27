@foreach($project_task->timeLogs as $timeLog)
<tr>
    <td>{{$loop->iteration}}</td>
    <td>
        {{ $timeLog->shift ? __('project::lang.shift_'.$timeLog->shift) : '-' }}
    </td>
    <td>
        {{@format_datetime($timeLog->start_datetime)}} - {{@format_datetime($timeLog->end_datetime)}}
    </td>
    <td>
        @if($timeLog->second_start_datetime && $timeLog->second_end_datetime)
            {{@format_datetime($timeLog->second_start_datetime)}} - {{@format_datetime($timeLog->second_end_datetime)}}
        @else
            -
        @endif
    </td>
    <td>
        {{$timeLog->formatted_duration}}
    </td>
    <td>
       {{$timeLog->user->user_full_name}}
    </td>
    <td>
       {!! $timeLog->note !!}
    </td>
</tr>
@endforeach
