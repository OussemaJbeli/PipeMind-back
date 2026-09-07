<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkspaceSettingsController extends Controller
{
    public function show(): array
    {
        $team = currentTeam();

        return ['data' => [
            'uuid' => $team->uuid,
            'name' => $team->name,
            'slug' => $team->slug,
            'logo_url' => $team->logo_url,
            'plan' => $team->plan,
            'privacy_mode' => $team->privacy_mode,
            'monthly_ai_budget_usd' => (float) $team->monthly_ai_budget_usd,
            'timezone' => $team->settings['timezone'] ?? config('app.timezone'),
            'webhook_base_url' => $team->settings['webhook_base_url'] ?? null,
            'created_at' => $team->created_at?->toIso8601String(),

            // What deleting the workspace would actually destroy. Shown beside
            // the danger zone rather than discovered afterwards.
            'contents' => [
                'projects' => $team->projects()->count(),
                'integrations' => $team->integrations()->count(),
                'failures' => $team->failures()->count(),
                'members' => $team->users()->count(),
            ],
        ]];
    }

    public function update(Request $request): JsonResponse|array
    {
        $team = currentTeam();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'privacy_mode' => ['sometimes', Rule::in(['cloud_redacted', 'local_only'])],
            'monthly_ai_budget_usd' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
        ]);

        // Switching to local_only with no local provider would break every
        // analysis on the next failure. Refuse now, while the user is looking
        // at the setting, rather than an hour later inside a queue.
        if (($data['privacy_mode'] ?? null) === 'local_only'
            && ! $team->aiProviders()->where('is_local', true)->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Add an active local AI provider (Ollama) before switching to local-only, '
                    .'or analyses will start failing.',
                'error_code' => 'NO_LOCAL_PROVIDER',
                'retryable' => false,
            ], 422);
        }

        $team->fill(array_diff_key($data, ['timezone' => null]));

        if (isset($data['timezone'])) {
            // Merged, not replaced: `settings` also holds webhook_base_url, and
            // assigning a fresh array would silently drop it.
            $team->settings = [...$team->settings ?? [], 'timezone' => $data['timezone']];
        }

        $team->save();

        activity_log(null, 'workspace.updated', 'info', $team->name,
            'Workspace settings changed: '.implode(', ', array_keys($data)), $team);

        return $this->show();
    }

    /**
     * AI spend against the ceiling.
     *
     * The page where "why is this expensive" gets answered, so the numbers live
     * here rather than buried in settings.
     */
    public function usage(Request $request): array
    {
        $team = currentTeam();
        $days = min($request->integer('days', 30), 365);
        $since = now()->subDays($days);
        $monthStart = now()->startOfMonth();

        $window = DB::table('ai_requests')
            ->where('team_id', $team->id)
            ->where('created_at', '>=', $since)
            ->selectRaw("count(*) as total,
                         coalesce(sum(cost_usd), 0) as cost,
                         coalesce(sum(total_tokens), 0) as tokens,
                         coalesce(avg(latency_ms), 0) as latency,
                         count(*) FILTER (WHERE cache_hit) as cached,
                         count(*) FILTER (WHERE status <> 'success') as errors")
            ->first();

        // Month-to-date is the figure the budget guard actually enforces, so it
        // has to be measured over the same window the guard uses — not the
        // display range, or the bar would disagree with the thing blocking you.
        $monthCost = (float) DB::table('ai_requests')
            ->where('team_id', $team->id)
            ->where('status', 'success')
            ->where('created_at', '>=', $monthStart)
            ->sum('cost_usd');

        $budget = (float) $team->monthly_ai_budget_usd;
        $elapsed = max(1, $monthStart->diffInDays(now()) + 1);
        $inMonth = now()->daysInMonth;

        $byModel = DB::table('ai_requests')
            ->where('team_id', $team->id)
            ->where('created_at', '>=', $since)
            ->groupBy('provider', 'model')
            ->selectRaw('provider, model, count(*) as calls,
                         coalesce(sum(cost_usd), 0) as cost,
                         coalesce(sum(total_tokens), 0) as tokens')
            ->orderByRaw('sum(cost_usd) desc')
            ->get();

        $total = (int) ($window->total ?? 0);

        return ['data' => [
            'range_days' => $days,
            'month_to_date_usd' => round($monthCost, 6),
            'monthly_budget_usd' => $budget,
            'budget_used_ratio' => $budget > 0 ? round(min(1.0, $monthCost / $budget), 4) : null,
            // Straight-line from spend so far. Crude, and labelled as a
            // projection rather than a forecast.
            'projected_month_end_usd' => round($monthCost / $elapsed * $inMonth, 6),

            'requests' => $total,
            'cost_usd' => round((float) ($window->cost ?? 0), 6),
            'tokens' => (int) ($window->tokens ?? 0),
            'avg_latency_ms' => (int) ($window->latency ?? 0),
            'cache_hit_rate' => $total ? round(((int) $window->cached) / $total, 4) : 0.0,
            'error_rate' => $total ? round(((int) $window->errors) / $total, 4) : 0.0,

            'by_model' => $byModel->map(fn ($row) => [
                'provider' => $row->provider,
                'model' => $row->model,
                'calls' => (int) $row->calls,
                'cost_usd' => round((float) $row->cost, 6),
                'tokens' => (int) $row->tokens,
            ])->all(),
        ]];
    }
}
