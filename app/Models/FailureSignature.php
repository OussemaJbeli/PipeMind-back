<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FailureCategory;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FailureSignature extends Model
{
    use BelongsToTeam,  HasFactory, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => FailureCategory::class,
            'is_known' => 'boolean',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolution_confirmed_at' => 'immutable_datetime',
        ];
    }

    public function failures(): HasMany
    {
        return $this->hasMany(Failure::class, 'signature_id');
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(FailureEmbedding::class, 'signature_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolution_confirmed_by');
    }
}
