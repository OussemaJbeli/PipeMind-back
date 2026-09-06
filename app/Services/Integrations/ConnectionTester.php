<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Exceptions\Integrations\IntegrationUnauthorized;
use App\Exceptions\Integrations\IntegrationUnreachable;
use App\Integrations\DTO\ProviderIdentity;
use App\Integrations\ProviderRegistry;
use App\Models\Integration;
use App\Models\Team;
use Throwable;

/**
 * Tests a connection BEFORE the integration is saved.
 *
 * Half of all integration support burden is a token with the wrong scope.
 * Reporting "connected as X, can retry jobs: no" at step 3 of the wizard turns a
 * confusing failure three days later into a clear choice now.
 */
class ConnectionTester
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * @param  array<string,mixed>  $credentials
     * @return array{ok:bool,identity:?ProviderIdentity,error:?string,error_code:?string}
     */
    public function test(Team $team, string $provider, ?string $baseUrl, array $credentials): array
    {
        // A throwaway, unsaved model: the wizard has to prove the credentials
        // work before it is willing to persist them.
        $probe = new Integration([
            'team_id' => $team->id,
            'provider' => $provider,
            'name' => 'probe',
            'base_url' => $baseUrl,
            'credentials' => $credentials,
            'webhook_secret' => 'probe',
        ]);

        try {
            $identity = $this->registry->make($provider)->verify($probe);

            return ['ok' => true, 'identity' => $identity, 'error' => null, 'error_code' => null];
        } catch (IntegrationUnauthorized $e) {
            return $this->failure($e, 'INTEGRATION_UNAUTHORIZED');
        } catch (IntegrationUnreachable $e) {
            return $this->failure($e, 'INTEGRATION_UNREACHABLE');
        } catch (Throwable $e) {
            return $this->failure($e, 'INTEGRATION_TEST_FAILED');
        }
    }

    /** @return array{ok:bool,identity:null,error:string,error_code:string} */
    private function failure(Throwable $e, string $code): array
    {
        return [
            'ok' => false,
            'identity' => null,
            'error' => $e->getMessage(),
            'error_code' => $code,
        ];
    }

    /** Retest a stored integration and record the outcome. */
    public function retest(Integration $integration): array
    {
        $result = $this->test(
            $integration->team,
            $integration->provider,
            $integration->base_url,
            $integration->credentials ?? [],
        );

        $integration->update([
            'status' => $result['ok'] ? 'active' : 'error',
            'last_verified_at' => $result['ok'] ? now() : $integration->last_verified_at,
            'last_error' => $result['ok'] ? null : $result['error'],
            // Backfills the account for integrations created before it was
            // recorded, so the duplicate check has something to compare.
            'credentials' => $result['ok']
                ? [...($integration->credentials ?? []), 'account' => $result['identity']->username]
                : $integration->credentials,
        ]);

        return $result;
    }
}
