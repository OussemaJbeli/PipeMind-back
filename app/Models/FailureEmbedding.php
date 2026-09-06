<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FailureEmbedding extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public $timestamps = false;

    public function failure(): BelongsTo
    {
        return $this->belongsTo(Failure::class);
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(FailureSignature::class, 'signature_id');
    }
}
