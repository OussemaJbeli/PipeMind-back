<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    use BelongsToTeam, HasFactory, HasUuid;

    protected $guarded = ['id'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'settings' => 'array',
            'is_default' => 'boolean',
            'is_local' => 'boolean',
            'temperature' => 'float',
            'input_cost_per_1k' => 'decimal:6',
            'output_cost_per_1k' => 'decimal:6',
            'last_tested_at' => 'immutable_datetime',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(AiRequest::class);
    }

    public function cost(int $promptTokens, int $completionTokens): float
    {
        return round(
            $promptTokens / 1000 * (float) $this->input_cost_per_1k
            + $completionTokens / 1000 * (float) $this->output_cost_per_1k,
            6
        );
    }

    /**
     * Sent per-request to the AI service, so that service stores no team's
     * credentials at rest.
     *
     * @return array<string,mixed>
     */
    public function toOverride(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'api_key' => $this->api_key,
            'base_url' => $this->base_url,
            'input_cost_per_1k' => (float) $this->input_cost_per_1k,
            'output_cost_per_1k' => (float) $this->output_cost_per_1k,
        ];
    }
}
