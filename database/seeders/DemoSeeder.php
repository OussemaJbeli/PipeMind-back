<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ActionType;
use App\Enums\TeamRole;
use App\Models\ActivityLog;
use App\Models\AiProvider;
use App\Models\AiRequest;
use App\Models\Analysis;
use App\Models\AnalysisEvidence;
use App\Models\Anomaly;
use App\Models\CommitChange;
use App\Models\Failure;
use App\Models\FailureSignature;
use App\Models\Integration;
use App\Models\JobLog;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\ProjectMetricDaily;
use App\Models\Recommendation;
use App\Models\Remediation;
use App\Models\RemediationPolicy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Reproduces PipeMind-data/ui/workspace.png and ui/project.png exactly.
 *
 * The frontend (roadmaps 11-16) is built entirely against this data, so ingestion
 * can be swapped in later without the UI changing a line.
 */
class DemoSeeder extends Seeder
{
    /** Matches the mockup: name, stack, success rate, failures today, pipelines. */
    private const PROJECTS = [
        // name, stack, success%, failures today, pipelines, colour, icon,
        // last-pipeline status, minutes since last pipeline
        ['biker-api',    ['Laravel', 'Docker', 'GitHub'],      98.0, 2, 124, '#6366F1', 'code',       'success',  2],
        ['biker-front',  ['Vue', 'TypeScript', 'GitHub'],      94.0, 1,  87, '#42B883', 'component',  'success', 10],
        ['biker-mobile', ['React Native', 'Docker', 'GitHub'], 91.0, 3,  63, '#61DAFB', 'smartphone', 'failed',  45],
        ['biker-admin',  ['Laravel', 'Docker', 'GitHub'],      97.0, 0,  56, '#8B5CF6', 'code',       'success', 60],
    ];

    /** Realistic distribution — deliberately NOT uniform. Uniform data hides bugs. */
    private const CATEGORY_WEIGHTS = [
        'TEST' => 24, 'DEPENDENCY' => 22, 'DATABASE' => 16, 'DOCKER' => 12,
        'NETWORK' => 9, 'CONFIGURATION' => 7, 'BUILD' => 5, 'AUTHENTICATION' => 3,
        'RESOURCE' => 2,
    ];

    private const ERRORS = [
        'DATABASE' => ['SQLSTATE[HY000] [2002] Connection refused', 'ConnectionRefused'],
        'TEST' => ['FAIL login.test.ts — Expected status 200, Received status 401', 'Assertion'],
        'DEPENDENCY' => ['npm ERR! ERESOLVE unable to resolve dependency tree', 'Conflict'],
        'DOCKER' => ['failed to solve: process "/bin/sh -c npm ci" did not complete successfully', 'BuildFailed'],
        'NETWORK' => ['getaddrinfo EAI_AGAIN registry.npmjs.org', 'DNSFailure'],
        'CONFIGURATION' => ['ERROR: variable APP_KEY not set', 'MissingEnvVar'],
        'BUILD' => ["src/auth.ts:42:18 - error TS2345: Argument of type 'string | undefined'", 'TypeCheck'],
        'AUTHENTICATION' => ['HTTP 401 Unauthorized — token has expired', 'ExpiredToken'],
        'RESOURCE' => ['FATAL ERROR: JavaScript heap out of memory — exit code 137', 'OutOfMemory'],
    ];

    private const STAGES = ['checkout', 'install', 'lint', 'test', 'build', 'deploy'];

    public function run(): void
    {
        $user = User::create([
            'name' => 'Oussema Jbeli',
            'email' => 'jbelioussema33@gmail.com',
            'password' => Hash::make('123456789az'),
            'email_verified_at' => now(),
            'timezone' => 'Africa/Tunis',
            'theme' => 'dark',
            'job_title' => 'DevOps Engineer',
            'onboarded_at' => now()->subMonths(2),
            'last_login_at' => now(),
        ]);

        $team = Team::create([
            'name' => 'OJ Team',
            'slug' => 'oj-team',
            'owner_id' => $user->id,
            'plan' => 'free',
            'privacy_mode' => 'cloud_redacted',
            'monthly_ai_budget_usd' => 25.00,
        ]);

        $user->teams()->attach($team, ['role' => TeamRole::OWNER->value, 'joined_at' => now()]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        // Everything below runs inside the team binding — same contract as a queued job.
        withTeam($team, function () use ($team, $user): void {
            $this->seedPolicies($team, $user);
            $provider = $this->seedAiProvider($team);
            $integration = $this->seedIntegration($team, $user);

            foreach (self::PROJECTS as $spec) {
                $this->seedProject($team, $user, $integration, $provider, ...$spec);
            }

            $this->seedWorkspaceActivity($team);
        });

        $this->command->info('Demo workspace ready — jbelioussema33@gmail.com / 123456789az');
    }

    private function seedPolicies(Team $team, User $user): void
    {
        foreach (config('pipemind.default_policies') as $policy) {
            RemediationPolicy::create([...$policy, 'team_id' => $team->id, 'created_by' => $user->id]);
        }
    }

    private function seedAiProvider(Team $team): AiProvider
    {
        // The stub is the default until a real key is configured. Seeding a
        // keyless Gemini as the default instead would make every analysis in a
        // fresh workspace fail with "No Gemini API key configured" — which reads
        // like a broken application rather than an unfinished setup.
        //
        // Gemini is seeded alongside it as `untested`, so the workspace shows
        // what to configure without pretending it already works.
        AiProvider::factory()->create(['team_id' => $team->id, 'is_default' => false]);

        return AiProvider::factory()->stub()->create(['team_id' => $team->id, 'is_default' => true]);
    }

    private function seedIntegration(Team $team, User $user): Integration
    {
        // Deliberately the generic provider, not GitHub: a fake GitHub token
        // would show as a broken connection forever and trip the "no events in
        // 24h" reconciliation warning. Generic is push-only, so an idle one is
        // an honest state rather than an error.
        return Integration::factory()->create([
            'team_id' => $team->id,
            'created_by' => $user->id,
            'provider' => 'generic',
            'name' => 'Sample data',
            'base_url' => null,
            'status' => 'active',
            'last_event_at' => now()->subMinutes(3),
        ]);
    }

    private function seedProject(
        Team $team, User $user, Integration $integration, AiProvider $provider,
        string $name, array $stack, float $successRate, int $failuresToday,
        int $pipelineCount, string $color, string $icon,
        string $lastStatus, int $lastMinutesAgo,
    ): void {
        $project = Project::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'ai_provider_id' => $provider->id,
            'name' => $name,
            'slug' => $name,
            'external_id' => (string) fake()->unique()->numberBetween(1000, 9999),
            'external_path' => "OussemaJbeli/{$name}",
            'repository_url' => "https://github.com/OussemaJbeli/{$name}.git",
            'web_url' => "https://github.com/OussemaJbeli/{$name}",
            'default_branch' => 'main',
            'icon' => $icon,
            'color' => $color,
            'tech_stack' => $stack,
            'created_by' => $user->id,
            'created_at' => now()->subDays(fake()->numberBetween(20, 90)),
        ]);

        $failures = $this->seedPipelines(
            $project, $team, $user, $successRate, $pipelineCount, $failuresToday,
            $lastStatus, $lastMinutesAgo,
        );
        $this->seedMetrics($project, $team, $successRate);
        $this->seedAnomalies($project, $team, $name);
        $this->seedRemediations($project, $team, $user, $failures, $name);
        $this->refreshProjectStats($project, $successRate, $failuresToday, $pipelineCount);
    }

    /** @return array<int,Failure> */
    private function seedPipelines(Project $project, Team $team, User $user,
        float $successRate, int $count, int $failuresToday,
        string $lastStatus, int $lastMinutesAgo): array
    {
        $failures = [];
        $iid = $count;
        $todayFailures = 0;

        for ($i = 0; $i < $count; $i++) {
            // Spread across 60 days, weighted toward recent.
            $ageMinutes = $i === 0
                ? $lastMinutesAgo
                : (int) round(($i / $count) ** 1.4 * 60 * 24 * 60);
            $finishedAt = now()->subMinutes($ageMinutes);

            $isToday = $ageMinutes < 1440;

            // The newest pipeline is pinned so the project card matches the mockup
            // ("Last pipeline: #821 ✓ Success 2 min ago"). health_status is derived
            // from it, and it must be deterministic rather than a lucky roll.
            if ($i === 0) {
                $shouldFail = $lastStatus === 'failed';
            } elseif ($isToday && $todayFailures < $failuresToday) {
                $shouldFail = true;
            } elseif ($isToday) {
                $shouldFail = false;
            } else {
                $shouldFail = fake()->boolean((int) round(100 - $successRate));
            }

            if ($shouldFail && $isToday) {
                $todayFailures++;
            }

            $duration = fake()->numberBetween(90, 320);
            $ref = fake()->randomElement(['main', 'main', 'feature/payment', 'feature/auth', 'bugfix/db-conn']);
            $sha = fake()->sha1();

            $pipeline = Pipeline::create([
                'project_id' => $project->id,
                'external_id' => (string) fake()->unique()->numberBetween(100000, 999999),
                'iid' => $iid--,
                'provider' => 'github',
                'status' => $shouldFail ? 'failed' : 'success',
                'source' => fake()->randomElement(['push', 'push', 'merge_request', 'schedule']),
                'ref' => $ref,
                'commit_sha' => $sha,
                'commit_short_sha' => substr($sha, 0, 8),
                'commit_message' => fake()->sentence(5),
                'commit_author_name' => fake()->randomElement(['Oussema', 'Sarah', 'Karim']),
                'commit_author_email' => fake()->safeEmail(),
                'web_url' => "https://github.com/OussemaJbeli/{$project->name}/actions/runs/{$iid}",
                'started_at' => $finishedAt->copy()->subSeconds($duration),
                'finished_at' => $finishedAt,
                'duration_seconds' => $duration,
                'queue_seconds' => fake()->numberBetween(0, 25),
                'jobs_total' => count(self::STAGES),
                'jobs_failed' => $shouldFail ? 1 : 0,
                'jobs_succeeded' => $shouldFail ? count(self::STAGES) - 1 : count(self::STAGES),
                'has_failure' => $shouldFail,
                // Backdate: a pipeline row is created when the pipeline starts. Leaving
                // this at now() makes every "today" query count the entire history.
                'created_at' => $finishedAt->copy()->subSeconds($duration),
                'updated_at' => $finishedAt,
            ]);

            $failedJob = $this->seedStagesAndJobs($pipeline, $shouldFail);
            $this->seedCommitChanges($pipeline, $project);

            if ($shouldFail && $failedJob) {
                $failures[] = $this->seedFailure($pipeline, $failedJob, $project, $team, $user);
            }
        }

        // One running pipeline for the "live" state on the board.
        Pipeline::factory()->running()->create([
            'project_id' => $project->id,
            'iid' => $project->pipelines()->max('iid') + 1,
            'ref' => 'feature/auth',
            'started_at' => now()->subMinutes(24),
        ]);

        return $failures;
    }

    private function seedStagesAndJobs(Pipeline $pipeline, bool $shouldFail): ?PipelineJob
    {
        // The failure lands in a plausible stage — never in checkout.
        $failIndex = $shouldFail ? fake()->numberBetween(1, 4) : -1;
        $failedJob = null;

        foreach (self::STAGES as $i => $stageName) {
            $skipped = $failIndex >= 0 && $i > $failIndex;
            $failed = $i === $failIndex;

            $stage = PipelineStage::create([
                'pipeline_id' => $pipeline->id,
                'name' => $stageName,
                'position' => $i,
                'status' => $failed ? 'failed' : ($skipped ? 'skipped' : 'success'),
                'jobs_count' => 1,
            ]);

            $job = PipelineJob::create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'external_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
                'name' => $this->jobNameFor($stageName),
                'stage_name' => $stageName,
                'position' => $i,
                'status' => $failed ? 'failed' : ($skipped ? 'skipped' : 'success'),
                'exit_code' => $failed ? 1 : ($skipped ? null : 0),
                'duration_seconds' => $skipped ? null : fake()->numberBetween(4, 140),
                'peak_memory_mb' => $skipped ? null : fake()->numberBetween(180, 900),
            ]);

            if ($failed) {
                $failedJob = $job;
            }
        }

        return $failedJob;
    }

    private function jobNameFor(string $stage): string
    {
        return match ($stage) {
            'checkout' => 'checkout',
            'install' => 'npm-ci',
            'lint' => 'eslint',
            'test' => 'backend-tests',
            'build' => 'docker-build',
            'deploy' => 'deploy-staging',
        };
    }

    private function seedCommitChanges(Pipeline $pipeline, Project $project): void
    {
        $files = fake()->randomElements([
            ['docker-compose.yml', true, false],
            ['package.json', false, true],
            ['app/Services/PaymentService.php', false, false],
            ['src/services/auth.ts', false, false],
            ['.gitlab-ci.yml', true, false],
            ['composer.lock', false, true],
        ], fake()->numberBetween(1, 3));

        foreach ($files as [$path, $isConfig, $isDep]) {
            CommitChange::create([
                'pipeline_id' => $pipeline->id,
                'project_id' => $project->id,
                'file_path' => $path,
                'change_type' => 'modified',
                'additions' => fake()->numberBetween(1, 80),
                'deletions' => fake()->numberBetween(0, 20),
                'is_config' => $isConfig,
                'is_dependency' => $isDep,
            ]);
        }
    }

    private function seedFailure(Pipeline $pipeline, PipelineJob $job,
        Project $project, Team $team, User $user): Failure
    {
        $category = $this->weightedCategory();
        [$errorMessage, $subcategory] = self::ERRORS[$category];

        $signature = FailureSignature::firstOrCreate(
            ['team_id' => $team->id, 'hash' => hash('sha256', "{$category}::{$errorMessage}")],
            [
                'normalized_error' => strtolower(preg_replace('/\d+/', '<num>', $errorMessage)),
                'sample_error' => $errorMessage,
                'category' => $category,
                'subcategory' => $subcategory,
                'first_seen_at' => $pipeline->finished_at,
            ]
        );

        $signature->increment('occurrence_count');
        $signature->update(['last_seen_at' => $pipeline->finished_at]);

        $isResolved = fake()->boolean(30);
        // Mockup MTTR tile reads 18m — keep the distribution centred there.
        $ttr = $isResolved ? fake()->numberBetween(420, 1740) : null;

        $failure = Failure::create([
            'team_id' => $team->id,
            'project_id' => $project->id,
            'pipeline_id' => $pipeline->id,
            'job_id' => $job->id,
            'signature_id' => $signature->id,
            'status' => $isResolved ? 'resolved' : 'analyzed',
            'severity' => $pipeline->ref === 'main' ? 'high' : 'medium',
            'category' => $category,
            'subcategory' => $subcategory,
            'stage_name' => $job->stage_name,
            'job_name' => $job->name,
            'error_message' => $errorMessage,
            'exit_code' => 1,
            'occurrence_index' => $signature->occurrence_count,
            'failed_at' => $pipeline->finished_at,
            'detected_at' => $pipeline->finished_at->addSeconds(3),
            'resolved_at' => $isResolved ? $pipeline->finished_at->addSeconds($ttr) : null,
            'resolved_by' => $isResolved ? $user->id : null,
            'resolution_type' => $isResolved ? 'fixed' : null,
            'time_to_resolution_seconds' => $ttr,
        ]);

        $this->seedJobLog($job, $pipeline, $project, $errorMessage);

        // ~70% of failures carry a completed analysis.
        if (fake()->boolean(70)) {
            $this->seedAnalysis($failure, $team, $category, $errorMessage, $project);
        }

        return $failure;
    }

    private function seedJobLog(PipelineJob $job, Pipeline $pipeline, Project $project, string $error): void
    {
        JobLog::create([
            'job_id' => $job->id,
            'pipeline_id' => $pipeline->id,
            'project_id' => $project->id,
            'storage_path' => "logs/{$project->uuid}/{$pipeline->iid}/{$job->id}.log",
            'size_bytes' => fake()->numberBetween(240_000, 3_400_000),
            'line_count' => fake()->numberBetween(1_800, 48_000),
            'checksum_sha256' => hash('sha256', $error),
            'is_redacted' => true,
            'redaction_count' => fake()->numberBetween(1, 5),
            'redaction_types' => fake()->randomElements(['env_secret', 'bearer', 'db_url', 'email'], 2),
            'excerpt' => "$ php artisan test --testsuite=Integration\n{$error}\n  at DatabaseTest.php:42\nProcess exited with code 1",
            'excerpt_start_line' => 1281,
            'excerpt_end_line' => 1310,
            'error_block' => $error,
            'fetched_at' => $pipeline->finished_at,
            'processed_at' => $pipeline->finished_at->addSeconds(2),
        ]);
    }

    private function seedAnalysis(Failure $failure, Team $team, string $category,
        string $error, Project $project): void
    {
        $confidence = fake()->randomFloat(3, 0.72, 0.96);
        $tokens = fake()->numberBetween(1500, 3200);
        $completion = fake()->numberBetween(220, 480);
        $cost = round($tokens / 1000 * 0.000075 + $completion / 1000 * 0.0003, 6);

        $analysis = Analysis::create([
            'failure_id' => $failure->id,
            'team_id' => $team->id,
            'status' => 'completed',
            'ai_service_version' => '0.1.0',
            'category' => $category,
            'subcategory' => $failure->subcategory,
            'severity' => $failure->severity->value,
            'confidence' => $confidence,
            'summary' => $this->summaryFor($category),
            'root_cause' => $this->rootCauseFor($category),
            'classification_source' => fake()->randomElement(['rules', 'rules', 'hybrid', 'ml', 'llm']),
            'classification_confidence' => fake()->randomFloat(3, 0.70, 0.98),
            'used_rag' => fake()->boolean(60),
            'similar_failures_count' => fake()->numberBetween(0, 4),
            'model_provider' => 'gemini',
            'model_name' => 'gemini-2.0-flash',
            'prompt_tokens' => $tokens,
            'completion_tokens' => $completion,
            'cost_usd' => $cost,
            'latency_ms' => fake()->numberBetween(2100, 6800),
            'started_at' => $failure->detected_at,
            'completed_at' => $failure->detected_at->addSeconds(6),
        ]);

        // Evidence always cites something real — that is what makes it evidence.
        AnalysisEvidence::create([
            'analysis_id' => $analysis->id, 'type' => 'log_line', 'content' => $error,
            'source_ref' => 'job_logs#L1294', 'line_number' => 1294, 'weight' => 0.95, 'position' => 0,
        ]);
        AnalysisEvidence::create([
            'analysis_id' => $analysis->id, 'type' => 'changed_file',
            'content' => 'docker-compose.yml modified in this commit',
            'source_ref' => 'docker-compose.yml', 'weight' => 0.82, 'position' => 1,
        ]);

        foreach ($this->recommendationsFor($category) as $i => $rec) {
            Recommendation::create([
                'analysis_id' => $analysis->id,
                'failure_id' => $failure->id,
                'title' => $rec[0],
                'description' => $rec[1],
                'action_type' => $rec[2],
                'risk' => ActionType::from($rec[2])->risk()->value,
                'confidence' => fake()->randomFloat(3, 0.70, 0.95),
                'affected_files' => $rec[3],
                'position' => $i,
            ]);
        }

        AiRequest::create([
            'team_id' => $team->id, 'project_id' => $project->id,
            'failure_id' => $failure->id, 'analysis_id' => $analysis->id,
            'operation' => 'analyze', 'provider' => 'gemini', 'model' => 'gemini-2.0-flash',
            'prompt_tokens' => $tokens, 'completion_tokens' => $completion,
            'total_tokens' => $tokens + $completion, 'cost_usd' => $cost,
            'latency_ms' => $analysis->latency_ms, 'created_at' => $analysis->completed_at,
        ]);
    }

    private function summaryFor(string $c): string
    {
        return match ($c) {
            'DATABASE' => 'Database was unavailable when integration tests started.',
            'DEPENDENCY' => 'A transitive dependency requires an incompatible major version.',
            'TEST' => 'The login test received 401 where 200 was expected.',
            'DOCKER' => 'The image build failed during dependency installation.',
            'NETWORK' => 'DNS resolution for the package registry failed.',
            'CONFIGURATION' => 'A required environment variable was not set in CI.',
            'BUILD' => 'A TypeScript type error blocked compilation.',
            'AUTHENTICATION' => 'The deployment token has expired.',
            default => 'The job exceeded its available memory.',
        };
    }

    private function rootCauseFor(string $c): string
    {
        return match ($c) {
            'DATABASE' => 'The database container had not finished its startup sequence before the test job began connecting. docker-compose.yml was modified in this commit and the healthcheck-based depends_on condition was removed.',
            'DEPENDENCY' => 'package-x declares a peer dependency on Vue 2 while the project uses Vue 3. npm cannot satisfy both constraints.',
            'TEST' => 'The authorization token is not attached to subsequent API requests after login, so the protected endpoint rejects the call.',
            'DOCKER' => 'npm ci failed inside the build stage because the lockfile is out of sync with package.json.',
            'NETWORK' => 'The runner could not resolve registry.npmjs.org — likely a transient DNS issue on the runner host.',
            'CONFIGURATION' => 'APP_KEY is not defined in the CI variables, so the application could not boot.',
            'BUILD' => 'A value typed string|undefined is passed where string is required; a null check is missing.',
            'AUTHENTICATION' => 'The CI deployment token passed its expiry date and needs rotation.',
            default => 'The Node build process exceeded the default heap limit while bundling.',
        };
    }

    /** @return array<int,array{0:string,1:string,2:string,3:array}> */
    private function recommendationsFor(string $c): array
    {
        return match ($c) {
            'DATABASE' => [
                ['Add a database readiness healthcheck', 'Restore the healthcheck-based depends_on so the test job waits for Postgres.', 'edit_file', ['docker-compose.yml']],
                ['Increase the startup timeout', 'Give the database container more time before tests begin.', 'update_config', ['docker-compose.yml']],
            ],
            'NETWORK' => [
                ['Retry the job', 'DNS failures of this kind are usually transient.', 'retry_job', []],
            ],
            'DEPENDENCY' => [
                ['Replace package-x with a Vue 3 compatible version', 'Upgrade or swap the package so the peer range is satisfiable.', 'update_dependency', ['package.json', 'package-lock.json']],
            ],
            default => [
                ['Investigate the failing job', 'Review the log excerpt and the changed files for this commit.', 'investigate', []],
            ],
        };
    }

    private function weightedCategory(): string
    {
        $total = array_sum(self::CATEGORY_WEIGHTS);
        $roll = fake()->numberBetween(1, $total);

        foreach (self::CATEGORY_WEIGHTS as $category => $weight) {
            if (($roll -= $weight) <= 0) {
                return $category;
            }
        }

        return 'UNKNOWN';
    }

    /** 60 days of history — every chart and sparkline needs it. */
    private function seedMetrics(Project $project, Team $team, float $successRate): void
    {
        for ($d = 59; $d >= 0; $d--) {
            $date = now()->subDays($d)->toDateString();
            $total = fake()->numberBetween(8, 24);
            $failed = (int) round($total * (100 - $successRate) / 100 * fake()->randomFloat(2, 0.4, 1.8));
            $failed = min($failed, $total);
            $success = $total - $failed;

            $byCategory = [];
            for ($i = 0; $i < $failed; $i++) {
                $c = $this->weightedCategory();
                $byCategory[$c] = ($byCategory[$c] ?? 0) + 1;
            }

            ProjectMetricDaily::create([
                'project_id' => $project->id,
                'team_id' => $team->id,
                'date' => $date,
                'pipelines_total' => $total,
                'pipelines_success' => $success,
                'pipelines_failed' => $failed,
                'success_rate' => $total > 0 ? round($success / $total * 100, 2) : 0,
                'avg_duration_seconds' => fake()->numberBetween(180, 260),
                'p50_duration_seconds' => fake()->numberBetween(170, 240),
                'p95_duration_seconds' => fake()->numberBetween(280, 420),
                'failures_count' => $failed,
                'failures_resolved' => (int) round($failed * 0.7),
                'mttr_seconds' => fake()->numberBetween(960, 1320),
                'failures_by_category' => $byCategory,
                'analyses_count' => (int) round($failed * 0.7),
                'ai_cost_usd' => round($failed * 0.0005, 6),
            ]);
        }
    }

    private function seedAnomalies(Project $project, Team $team, string $name): void
    {
        if ($name !== 'biker-api') {
            return;
        }

        Anomaly::create([
            'team_id' => $team->id, 'project_id' => $project->id,
            'type' => 'duration', 'severity' => 'high', 'metric_name' => 'duration_seconds',
            'observed_value' => 664, 'baseline_value' => 162,
            'deviation_ratio' => 4.1, 'z_score' => 9.2, 'detection_method' => 'mad',
            'title' => 'Build duration 4.1x above baseline',
            'description' => 'npm-build took 11m 04s against a 30-day median of 2m 42s.',
            'possible_causes' => ['Dependency download slow or retried', 'Build cache miss', 'Runner resource contention'],
            'detected_at' => now()->subMinutes(32),
        ]);

        Anomaly::create([
            'team_id' => $team->id, 'project_id' => $project->id,
            'type' => 'failure_rate', 'severity' => 'medium', 'metric_name' => 'failure_rate',
            'observed_value' => 0.18, 'baseline_value' => 0.06,
            'deviation_ratio' => 3.0, 'z_score' => 4.4, 'detection_method' => 'zscore',
            'title' => 'Failure rate rising this week',
            'description' => '7-day failure rate is 18% against a 30-day baseline of 6%.',
            'detected_at' => now()->subHours(5),
        ]);
    }

    private function seedRemediations(Project $project, Team $team, User $user, array $failures, string $name): void
    {
        if ($name !== 'biker-api' || count($failures) === 0) {
            return;
        }

        $failure = $failures[0];
        $rec = $failure->recommendations()->first();

        Remediation::create([
            'team_id' => $team->id, 'project_id' => $project->id,
            'failure_id' => $failure->id, 'recommendation_id' => $rec?->id,
            'action_type' => 'edit_file', 'risk' => 'medium',
            'policy_decision' => 'requires_approval',
            'policy_reason' => 'Risk medium exceeds the automatic limit (low).',
            'status' => 'pending_approval',
            'expires_at' => now()->addDay(),
            'created_at' => now()->subMinutes(4),
        ]);

        Remediation::factory()->succeeded()->create([
            'team_id' => $team->id, 'project_id' => $project->id,
            'failure_id' => $failure->id, 'action_type' => 'retry_job', 'risk' => 'low',
            'created_at' => now()->subHours(2),
        ]);
    }

    private function refreshProjectStats(Project $project, float $rate, int $failuresToday, int $count): void
    {
        $last = $project->pipelines()->whereNotNull('finished_at')->latest('finished_at')->first();

        $project->forceFill([
            'pipelines_count' => $count,
            'success_rate' => $rate,
            'failures_today' => $failuresToday,
            'last_pipeline_id' => $last?->id,
            'last_pipeline_at' => $last?->finished_at,
            'health_status' => $last?->status->value === 'failed' ? 'failing' : ($rate < 90 ? 'degraded' : 'healthy'),
        ])->save();
    }

    private function seedWorkspaceActivity(Team $team): void
    {
        $projects = Project::all();

        $entries = [
            ['pipeline.failed', 'error', 'Pipeline #821 failed on step database:integration', 2],
            ['pipeline.succeeded', 'success', 'Pipeline #491 completed successfully', 10],
            ['anomaly.detected', 'warning', 'Pipeline #302 failed on step e2e-tests', 45],
            ['pipeline.succeeded', 'success', 'Pipeline #210 completed successfully', 60],
            ['analysis.completed', 'info', 'New failure pattern detected', 120],
        ];

        foreach ($entries as $i => [$action, $level, $description, $minutesAgo]) {
            $project = $projects[$i % $projects->count()];

            ActivityLog::create([
                'team_id' => $team->id,
                'project_id' => $project->id,
                'actor_type' => str_starts_with($action, 'analysis') ? 'ai' : 'system',
                'action' => $action,
                'level' => $level,
                'title' => $project->name,
                'description' => $description,
                'subject_type' => 'pipeline',
                'created_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        // Broader history for the activity page.
        foreach (range(1, 35) as $i) {
            $project = $projects->random();
            $action = fake()->randomElement([
                'pipeline.succeeded', 'pipeline.failed', 'failure.detected',
                'analysis.completed', 'failure.resolved', 'job.retried',
            ]);

            ActivityLog::create([
                'team_id' => $team->id,
                'project_id' => $project->id,
                'actor_type' => str_starts_with($action, 'analysis') ? 'ai' : 'system',
                'action' => $action,
                'level' => str_contains($action, 'failed') ? 'error' : 'info',
                'title' => $project->name,
                'description' => Str::headline(str_replace('.', ' ', $action)),
                'created_at' => now()->subHours(fake()->numberBetween(3, 72)),
            ]);
        }
    }
}
