<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Larastan reads neither the `casts()` method's enum entries nor
 * `immutable_datetime` at level 5, so without these it infers the raw
 * column types and every enum method call on them looks like a call on a
 * string.
 *
 * @property int $id
 * @property int $job_id
 * @property string|null $excerpt
 * @property string|null $error_block
 * @property string|null $stack_trace
 * @property bool $is_redacted
 * @property bool $truncated
 * @property array<int,string>|null $redaction_types
 */
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
