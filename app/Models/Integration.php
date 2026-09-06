<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Integration extends Model
{
    use BelongsToTeam, HasFactory, HasUuid, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * A leaked token here compromises the user's entire CI platform. A feature test
     * asserts these never appear in any API response.
     */
    protected $hidden = ['credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'scopes' => 'array',
            'settings' => 'array',
            'last_verified_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PipelineEvent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function token(): ?string
    {
        return $this->credentials['token'] ?? null;
    }

    public function webhookUrl(): string
    {
        // Must match the route registered in routes/api.php, which carries the
        // `api` prefix. A mismatch here silently registers a webhook that 404s
        // on every delivery.
        // loadMissing, not a bare property read: lazy loading is disabled outside
        // production, so touching $this->team on a model that did not eager-load
        // it throws LazyLoadingViolationException.
        $this->loadMissing('team');

        return $this->team->webhookBaseUrl()."/api/webhooks/{$this->provider}/{$this->uuid}";
    }

    /** github.com / gitlab.com rather than a self-hosted instance. */
    public function isCloudInstance(): bool
    {
        $host = parse_url((string) $this->base_url, PHP_URL_HOST) ?: '';

        return $this->base_url === null
            || in_array($host, ['github.com', 'api.github.com', 'gitlab.com'], true);
    }

    public function looksStale(): bool
    {
        return $this->status === 'active'
            && $this->projects()->where('is_active', true)->exists()
            && (! $this->last_event_at || $this->last_event_at->lt(now()->subDay()));
    }
}
