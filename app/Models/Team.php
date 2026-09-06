<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'monthly_ai_budget_usd' => 'decimal:2',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'joined_at', 'invited_by'])
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Where providers should send webhooks.
     *
     * Stored per team rather than read from APP_URL because a free tunnel
     * (cloudflared quick tunnel, ngrok free) gets a NEW hostname on every
     * restart. Keeping it here lets the user paste the current URL into the UI
     * and re-register every webhook, instead of editing .env and redeploying.
     */
    public function webhookBaseUrl(): string
    {
        $configured = $this->settings['webhook_base_url'] ?? null;

        return rtrim($configured ?: config('app.url'), '/');
    }

    public function setWebhookBaseUrl(?string $url): void
    {
        $settings = $this->settings ?? [];

        if ($url) {
            $settings['webhook_base_url'] = rtrim($url, '/');
        } else {
            unset($settings['webhook_base_url']);
        }

        $this->update(['settings' => $settings]);
    }

    /** True when webhooks would be sent somewhere the provider cannot reach. */
    public function webhookUrlIsReachable(): bool
    {
        $host = parse_url($this->webhookBaseUrl(), PHP_URL_HOST) ?: '';

        return ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && ! str_ends_with($host, '.local')
            && ! str_ends_with($host, '.test');
    }

    public function aiProviders(): HasMany
    {
        return $this->hasMany(AiProvider::class);
    }

    public function aiRequests(): HasMany
    {
        return $this->hasMany(AiRequest::class);
    }

    public function failureSignatures(): HasMany
    {
        return $this->hasMany(FailureSignature::class);
    }

    public function failures(): HasMany
    {
        return $this->hasMany(Failure::class);
    }

    public function remediationPolicies(): HasMany
    {
        return $this->hasMany(RemediationPolicy::class);
    }

    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function knowledgeDocuments(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    /** Logs must never reach an external LLM for these teams. Enforced in Laravel. */
    public function isLocalOnly(): bool
    {
        return $this->privacy_mode === 'local_only';
    }

    public function defaultAiProvider(): ?AiProvider
    {
        return $this->aiProviders()->where('is_default', true)->first();
    }

    /** Month-to-date AI spend. Checked before every model call, not sampled. */
    public function monthToDateSpend(): float
    {
        return (float) $this->aiRequests()
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
    }

    public function hasBudgetRemaining(): bool
    {
        return $this->monthToDateSpend() < (float) $this->monthly_ai_budget_usd;
    }
}
