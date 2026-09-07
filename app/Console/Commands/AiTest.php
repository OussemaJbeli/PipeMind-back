<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiProvider;
use App\Services\Ai\AiGateway;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tests an AI provider's credentials from the terminal.
 *
 * The same check the wizard runs, reachable before the frontend exists — and the
 * fastest way to answer "is this key any good" without pasting it into a form.
 */
class AiTest extends Command
{
    protected $signature = 'pipemind:ai-test
                            {--provider=gemini : gemini, openai, ollama, openai_compatible, stub}
                            {--key= : API key to test. Omitted for a saved provider or a local one}
                            {--model= : Model to check availability for}
                            {--base-url= : For ollama or an OpenAI-compatible gateway}
                            {--saved= : UUID of a saved provider to re-test with its stored key}
                            {--models : List every model the credential can reach}';

    protected $description = 'Test AI provider credentials and list available models';

    public function handle(AiGateway $ai): int
    {
        $config = $this->option('saved')
            ? $this->fromSaved($this->option('saved'))
            : array_filter([
                'provider' => $this->option('provider'),
                'api_key' => $this->option('key'),
                'model' => $this->option('model'),
                'base_url' => $this->option('base-url'),
            ]);

        if (! $config) {
            return self::FAILURE;
        }

        $this->line("Testing <options=bold>{$config['provider']}</>".
            (isset($config['model']) ? " · {$config['model']}" : '').' …');

        try {
            $result = $ai->testProvider($config);
        } catch (Throwable $e) {
            // Reaching the AI service is a different failure from the provider
            // rejecting a key, and conflating them sends people to the wrong fix.
            $this->newLine();
            $this->error('Could not reach the AI service: '.$e->getMessage());
            $this->line('  Start it with: cd PipeMind-ai && ./.venv/bin/uvicorn app.main:app --port 8001');

            return self::FAILURE;
        }

        $this->newLine();

        if (! ($result['ok'] ?? false)) {
            $this->error('✗ '.($result['message'] ?? 'The provider rejected these settings.'));

            return self::FAILURE;
        }

        $this->info('✓ '.($result['message'] ?? 'Connected.'));

        if ($latency = $result['latency_ms'] ?? null) {
            $this->line("  responded in {$latency} ms");
        }

        $models = $result['models'] ?? [];

        if ($models && $this->option('models')) {
            $this->newLine();
            $this->line('Models this credential can reach:');
            foreach ($models as $model) {
                $this->line("  · {$model}");
            }
        } elseif ($models) {
            $this->line('  '.count($models).' models available (--models to list them)');
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function fromSaved(string $uuid): ?array
    {
        $provider = AiProvider::withoutGlobalScopes()->where('uuid', $uuid)->first();

        if (! $provider) {
            $this->error("No saved provider with uuid {$uuid}.");
            $this->line('  List them with: php artisan tinker --execute="App\Models\AiProvider::withoutGlobalScopes()->get([\'uuid\',\'name\'])->each(fn($p)=>print(\"$p->uuid  $p->name\n\"));"');

            return null;
        }

        return [
            'provider' => $provider->provider,
            'api_key' => $provider->api_key,
            'model' => $provider->model,
            'base_url' => $provider->base_url,
        ];
    }
}
