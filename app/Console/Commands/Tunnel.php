<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\Team;
use App\Services\Integrations\WebhookRegistrar;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Starts a cloudflared tunnel, captures its URL, saves it as the team's webhook
 * base URL, and re-registers every webhook — then stays running.
 *
 * This is a CLI command rather than a button in the UI on purpose: the tunnel is
 * a long-running process on the developer's machine, and spawning one from an
 * HTTP request would both outlive the request and hand a web endpoint the
 * ability to execute local binaries.
 */
class Tunnel extends Command
{
    protected $signature = 'pipemind:tunnel
                            {--port=8000 : The local port to expose}
                            {--team= : Team slug (defaults to the only team)}
                            {--no-register : Save the URL but skip webhook re-registration}';

    protected $description = 'Expose the local API through a cloudflared tunnel and re-register webhooks';

    public function handle(WebhookRegistrar $registrar): int
    {
        if (! $this->cloudflaredInstalled()) {
            $this->error('cloudflared is not installed.');
            $this->newLine();
            $this->line('Install it with one of:');
            $this->line('  <fg=gray>brew install cloudflared</>');
            $this->line('  <fg=gray>curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -o /usr/local/bin/cloudflared && chmod +x /usr/local/bin/cloudflared</>');
            $this->newLine();
            $this->line('Or paste a tunnel URL manually in the app: Integrations → Public webhook URL.');

            return self::FAILURE;
        }

        $team = $this->resolveTeam();

        if (! $team) {
            return self::FAILURE;
        }

        $port = (int) $this->option('port');

        $this->info("Starting a cloudflared tunnel to http://localhost:{$port} …");

        $process = new Process([
            'cloudflared', 'tunnel', '--url', "http://localhost:{$port}",
            '--no-autoupdate',
        ]);

        $process->setTimeout(null);
        $process->start();

        $url = $this->captureUrl($process);

        if (! $url) {
            $this->error('Could not determine the tunnel URL. Is cloudflared able to reach the internet?');
            $process->stop();

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Tunnel is up: {$url}");

        $team->setWebhookBaseUrl($url);
        $this->line("Saved as the webhook base URL for <fg=cyan>{$team->name}</>.");

        if (! $this->option('no-register')) {
            $this->reRegister($team, $registrar);
        }

        $this->newLine();
        $this->line('<fg=gray>Leave this running. Press Ctrl+C to stop the tunnel.</>');
        $this->line('<fg=gray>The URL changes every restart — run this command again and webhooks re-point automatically.</>');
        $this->newLine();

        // Stream cloudflared's output so connection problems stay visible.
        $process->wait(function (string $type, string $buffer): void {
            foreach (array_filter(explode("\n", $buffer)) as $line) {
                if (str_contains($line, 'ERR') || str_contains($line, 'error')) {
                    $this->line("<fg=red>{$line}</>");
                }
            }
        });

        return self::SUCCESS;
    }

    private function cloudflaredInstalled(): bool
    {
        $which = new Process(['which', 'cloudflared']);
        $which->run();

        return $which->isSuccessful();
    }

    private function resolveTeam(): ?Team
    {
        if ($slug = $this->option('team')) {
            $team = Team::where('slug', $slug)->first();

            if (! $team) {
                $this->error("No team with slug '{$slug}'.");
            }

            return $team;
        }

        $teams = Team::all();

        if ($teams->count() === 1) {
            return $teams->first();
        }

        if ($teams->isEmpty()) {
            $this->error('No teams exist. Run `php artisan migrate:fresh --seed` first.');

            return null;
        }

        $slug = $this->choice('Which team?', $teams->pluck('slug')->all());

        return $teams->firstWhere('slug', $slug);
    }

    /** cloudflared prints the assigned hostname to stderr within a few seconds. */
    private function captureUrl(Process $process): ?string
    {
        $deadline = time() + 30;

        while (time() < $deadline) {
            $output = $process->getOutput().$process->getErrorOutput();

            if (preg_match('~https://[a-z0-9-]+\.trycloudflare\.com~i', $output, $matches)) {
                return $matches[0];
            }

            if (! $process->isRunning()) {
                return null;
            }

            usleep(300_000);
        }

        return null;
    }

    private function reRegister(Team $team, WebhookRegistrar $registrar): void
    {
        $integrations = withTeam($team, fn () => Integration::with('projects')->get());

        if ($integrations->isEmpty()) {
            $this->line('<fg=gray>No integrations yet — add one in the app and its webhook will use this URL.</>');

            return;
        }

        foreach ($integrations as $integration) {
            // The generic provider has nothing to register on the far side.
            if ($integration->provider === 'generic') {
                continue;
            }

            $results = withTeam($team, fn () => $registrar->reRegisterAll($integration));

            if ($results === []) {
                continue;
            }

            $failed = collect($results)->where('ok', false);

            $this->line(sprintf(
                '%s  %s: %d/%d webhook(s) re-registered',
                $failed->isEmpty() ? '<fg=green>✓</>' : '<fg=yellow>!</>',
                $integration->name,
                count($results) - $failed->count(),
                count($results),
            ));

            foreach ($failed as $failure) {
                $this->line("    <fg=red>{$failure['project']}: {$failure['error']}</>");
            }
        }
    }
}
