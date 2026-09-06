<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\ProviderRegistry;
use App\Jobs\ProcessPipelineEvent;
use App\Models\Integration;
use App\Models\PipelineEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;

/**
 * The controller does four things and nothing else. Everything real happens on
 * a queue, because GitLab disables a webhook after repeated timeouts.
 */
class WebhookController extends Controller
{
    /** Auth material must never be persisted alongside the payload. */
    private const STRIPPED_HEADERS = [
        'authorization', 'cookie', 'x-gitlab-token', 'x-hub-signature',
        'x-hub-signature-256', 'x-pipemind-token', 'proxy-authorization',
    ];

    public function __invoke(
        Request $request,
        ProviderRegistry $registry,
        string $provider,
        string $integrationUuid,
    ): JsonResponse {
        $integration = Integration::withoutGlobalScopes()
            ->where('uuid', $integrationUuid)
            ->where('provider', $provider)
            ->first();

        // Identical response for "unknown integration" and "bad signature": never
        // confirm to an unauthenticated caller that a given UUID exists.
        if (! $integration) {
            return $this->reject();
        }

        $raw = $request->getContent();
        $adapter = $registry->for($integration);

        if (! $adapter->verifySignature($integration, $raw, $request->headers->all())) {
            Log::warning('pipemind.webhook.rejected', [
                'integration_id' => $integration->id,
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            return $this->reject();
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Malformed payload'], 400);
        }

        if (! is_array($payload)) {
            return response()->json(['message' => 'Malformed payload'], 400);
        }

        $eventType = $adapter->eventType($payload, $request->headers->all());

        // Push, issue, note and everything else we do not care about.
        if (! $eventType) {
            return response()->json(['status' => 'ignored'], 202);
        }

        $deliveryId = $this->deliveryId($request, $payload);

        // insertOrIgnore, not create()+catch: in PostgreSQL a raised constraint
        // violation aborts the ENCLOSING transaction, so every subsequent query
        // fails until rollback. ON CONFLICT DO NOTHING never raises, which keeps
        // this safe to call from inside a transaction.
        $inserted = PipelineEvent::insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'integration_id' => $integration->id,
            'provider' => $provider,
            'event_type' => $eventType,
            'external_delivery_id' => $deliveryId,
            'external_object_id' => $adapter->externalPipelineId($payload),
            'signature_valid' => true,
            'payload' => json_encode($payload),
            'headers' => json_encode($this->safeHeaders($request)),
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        // The unique index does the deduplication. A redelivery is a no-op.
        if ($inserted === 0) {
            return response()->json(['status' => 'duplicate'], 202);
        }

        $event = PipelineEvent::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->where('external_delivery_id', $deliveryId)
            ->firstOrFail();

        ProcessPipelineEvent::dispatch($event->id);

        $integration->forceFill(['last_event_at' => now()])->saveQuietly();

        // 202, not 200: accurately "queued", not "done".
        return response()->json(['status' => 'accepted', 'event' => $event->uuid], 202);
    }

    private function reject(): JsonResponse
    {
        return response()->json(['message' => 'Invalid webhook'], 401);
    }

    /**
     * A stable per-delivery identifier. Providers that supply one give us exact
     * deduplication; for the rest we hash the body, which catches the common
     * case of the same event being retried.
     */
    private function deliveryId(Request $request, array $payload): string
    {
        foreach (['x-github-delivery', 'x-gitlab-event-uuid', 'x-pipemind-delivery'] as $header) {
            if ($value = $request->header($header)) {
                return $value;
            }
        }

        return hash('sha256', json_encode($payload) ?: Str::uuid()->toString());
    }

    /** @return array<string,string> */
    private function safeHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $key => $values) {
            if (in_array(strtolower($key), self::STRIPPED_HEADERS, true)) {
                continue;
            }

            $headers[$key] = is_array($values) ? ($values[0] ?? '') : $values;
        }

        return $headers;
    }
}
