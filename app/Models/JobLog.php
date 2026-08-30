<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobLog extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'redaction_types' => 'array',
            'is_redacted' => 'boolean',
            'truncated' => 'boolean',
            'fetched_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(PipelineJob::class, 'job_id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
