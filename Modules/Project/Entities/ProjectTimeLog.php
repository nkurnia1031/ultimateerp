<?php

namespace Modules\Project\Entities;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ProjectTimeLog extends Model
{
    use LogsActivity;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pjt_project_time_logs';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    protected static $logUnguarded = true;

    protected static $logOnlyDirty = true;

    protected $appends = ['total_seconds', 'formatted_duration'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['*'])
            ->logOnlyDirty();
    }
    
    /**
     * Get the task for time log.
     */
    public function task()
    {
        return $this->belongsTo('Modules\Project\Entities\ProjectTask', 'project_task_id');
    }

    /**
     * Get the user for time log.
     */
    public function user()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }

    /**
     * Get the user who added time log.
     */
    public function createdBy()
    {
        return $this->belongsTo(\App\User::class, 'created_by');
    }

    /**
     * Return the project for a time log.
     */
    public function project()
    {
        return $this->belongsTo('Modules\Project\Entities\Project', 'project_id');
    }

    public function tags()
    {
        return $this->belongsToMany(\App\Tag::class, 'project_time_log_tags', 'project_time_log_id', 'tag_id');
    }

    public function getTotalSecondsAttribute()
    {
        $seconds = $this->periodSeconds($this->start_datetime, $this->end_datetime);
        $seconds += $this->periodSeconds($this->second_start_datetime, $this->second_end_datetime);

        return $seconds;
    }

    public function getFormattedDurationAttribute()
    {
        $hours = floor($this->total_seconds / 3600);
        $minutes = floor(($this->total_seconds / 60) % 60);

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    private function periodSeconds($start, $end)
    {
        if (empty($start) || empty($end)) {
            return 0;
        }

        return max(0, Carbon::parse($start)->diffInSeconds(Carbon::parse($end), false));
    }
}
