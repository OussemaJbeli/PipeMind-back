<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Integrations\ProviderRegistry;
use App\Models\Integration;
use App\Models\Project;
use Throwable;

/**
 * Registers and re-registers provider webhooks.
 *
 * Re-registration is a first-class operation, not an edge case: a free tunnel
 * gets a new hostname on every restart, and a webhook pointing at a dead URL
 * looks exactly like "nothing is happening".
 */
class WebhookRegistrar
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /** @return array{ok:bool,hook_id:?string,error:?string} */
    public function register(Integration $integration, Project $project): array
    {
        $adapter = $this->registry->for($integration);
        $url = $integration->webhookUrl();

        // Fail here with an actionable message rather than letting the provider
        // reject a loopback URL with an opaque 422 several seconds later.
        if (! $integration->team->webhookUrlIsReachable()) {
            return [
                'ok' => false,
                'hook_id' => null,
                'error' => sprintf(
                    'Webhooks would be sent to %s, which %s cannot reach from the internet. '
                    .'Start a tunnel (`php artisan pipemind:tunnel`) or set a public URL under '
                    .'Integrations → Public webhook URL, then re-register.',
                    $url,
                    ucfirst($integration->provider),
                ),
            ];
        }

        try {
            // Remove the previous hook first so a rotating tunnel does not leave
            // a trail of dead webhooks on the provider side.
            if ($existing = $project->settings['webhook_id'] ?? null) {
                try {
                    $adapter->removeWebhook($integration, $project, (string) $existing);
                } catch (Throwable) {
                    // The old hook may already be gone. Not worth failing over.
                }
            }

            $hookId = $adapter->registerWebhook($integration, $project, $url, $integration->webhook_secret);

            $project->update([
                'settings' => [
                    ...($project->settings ?? []),
                    'webhook_id' => $hookId,
                    'webhook_url' => $url,
                    'webhook_registered_at' => now()->toIso8601String(),
                ],
            ]);

            return ['ok' => true, 'hook_id' => $hookId, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'hook_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Re-point every project's webhook at the current base URL.
     *
     * @return array<int,array{project:string,ok:bool,error:?string}>
     */
    public function reRegisterAll(Integration $integration): array
    {
        $integration->loadMissing('team');

        return $integration->projects()
            ->where('is_active', true)
            ->get()
            ->map(function (Project $project) use ($integration) {
                $result = $this->register($integration, $project);

                return [
                    'project' => $project->name,
                    'slug' => $project->slug,
                    'ok' => $result['ok'],
                    'error' => $result['error'],
                ];
            })->all();
    }

    public function unregister(Integration $integration, Project $project): void
    {
        $hookId = $project->settings['webhook_id'] ?? null;

        if (! $hookId) {
            return;
        }

        try {
            $this->registry->for($integration)->removeWebhook($integration, $project, (string) $hookId);
        } catch (Throwable) {
            // Best effort: a project can be removed from PipeMind even if the
            // provider is unreachable.
        }

        $settings = $project->settings ?? [];
        unset($settings['webhook_id'], $settings['webhook_url'], $settings['webhook_registered_at']);

        $project->update(['settings' => $settings]);
    }
}
