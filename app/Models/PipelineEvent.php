<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineEvent extends Model
{
    use HasFactory, HasUuid;

    /**
     * The table is append-only with explicit received_at / processed_at columns
     * and no created_at / updated_at pair.
     */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'signature_valid' => 'boolean',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
