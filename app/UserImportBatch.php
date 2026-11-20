<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class UserImportBatch extends Model
{
    protected $table = 'user_import_batches';
    protected $guarded = ['id'];

    protected $casts = [
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'reverted_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return in_array($this->status, ['queued_preview', 'analyzing', 'queued_import', 'importing'], true);
    }

    public function hasSourceSnapshot(): bool
    {
        return filled($this->source_snapshot_path);
    }
}
