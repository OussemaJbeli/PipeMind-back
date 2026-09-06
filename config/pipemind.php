<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI service
    |--------------------------------------------------------------------------
    | The Python intelligence layer. Internal network only — never exposed
    | publicly. Laravel is its only client.
    */
    'ai' => [
        'url' => env('AI_SERVICE_URL', 'http://localhost:8001'),
        'token' => env('AI_SERVICE_TOKEN'),
        'timeout' => (int) env('AI_SERVICE_TIMEOUT', 120),
        'contract_version' => env('AI_CONTRACT_VERSION', 'v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion
    |--------------------------------------------------------------------------
    */
    'ingestion' => [
        // Reject webhooks whose timestamp is older than this (replay protection).
        'webhook_tolerance_seconds' => (int) env('PIPEMIND_WEBHOOK_TOLERANCE_SECONDS', 300),

        // Hard cap per job log. Larger logs are tail-truncated: the error is at
        // the end of a CI log essentially always.
        'max_log_bytes' => 50 * 1024 * 1024,

        // Green-job logs are pure storage cost.
        'fetch_logs_for_statuses' => ['failed', 'canceled'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis
    |--------------------------------------------------------------------------
    */
    'analysis' => [
        'auto_analyze' => true,
        'min_confidence_to_show' => 0.40,

        // Same signature in the same project returns the cached analysis.
        // Scoped to the project deliberately: identical error text in a different
        // codebase can have a genuinely different cause.
        'cache_ttl_hours' => 168,

        'max_similar_failures' => 5,
        // 0.75 returned zero rows in practice — see
        // PipeMind-data/experiments/similarity-threshold.md. The AI service owns the
        // authoritative value; this mirrors it for display.
        'similarity_threshold' => 0.55,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Consumed by pipemind:prune (scheduled daily).
    */
    'retention' => [
        'pipeline_events_days' => 30,
        'commit_changes_days' => 90,
        'activity_logs_days' => 90,
        'ai_requests_days' => 180,
        'raw_logs_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | File signals
    |--------------------------------------------------------------------------
    | Path patterns → commit_changes.is_config / is_dependency. These two flags
    | are the highest-signal features for root-cause correlation: "the pipeline
    | broke and docker-compose.yml changed" is most of the diagnosis.
    */
    'file_signals' => [
        'config' => [
            'docker-compose*.yml', 'docker-compose*.yaml', 'Dockerfile*', '.dockerignore',
            '.env*', '*.ci.yml', '*.ci.yaml', '.gitlab-ci.yml', '.github/workflows/*',
            'Jenkinsfile', 'azure-pipelines.yml', 'bitbucket-pipelines.yml',
            'k8s/*', 'kubernetes/*', 'helm/*', 'charts/*',
            'nginx*.conf', 'php.ini', 'supervisord.conf', 'Procfile',
            'vite.config.*', 'webpack.config.*', 'tsconfig.json', 'phpunit.xml',
        ],
        'dependency' => [
            'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock',
            'composer.json', 'composer.lock',
            'requirements*.txt', 'pyproject.toml', 'poetry.lock', 'Pipfile*', 'uv.lock',
            'go.mod', 'go.sum', 'Gemfile', 'Gemfile.lock',
            'pom.xml', 'build.gradle', 'build.gradle.kts', 'Cargo.toml', 'Cargo.lock',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default remediation policy
    |--------------------------------------------------------------------------
    | Seeded for every new team. Risk is assigned from action_type by code and is
    | never taken from the model — a model that can label a production rollback
    | "low risk" could otherwise talk its way past the safety layer.
    */
    'default_policies' => [
        ['action_type' => 'investigate',          'mode' => 'auto',      'max_risk' => 'low',      'min_confidence' => 0.00],
        ['action_type' => 'retry_job',            'mode' => 'auto',      'max_risk' => 'low',      'min_confidence' => 0.85],
        ['action_type' => 'create_issue',         'mode' => 'auto',      'max_risk' => 'low',      'min_confidence' => 0.70],
        ['action_type' => 'retry_pipeline',       'mode' => 'approval',  'max_risk' => 'low',      'min_confidence' => 0.85],
        ['action_type' => 'create_merge_request', 'mode' => 'approval',  'max_risk' => 'medium',   'min_confidence' => 0.90],
        ['action_type' => 'edit_file',            'mode' => 'approval',  'max_risk' => 'medium',   'min_confidence' => 0.90],
        ['action_type' => 'update_dependency',    'mode' => 'approval',  'max_risk' => 'medium',   'min_confidence' => 0.90],
        ['action_type' => 'update_config',        'mode' => 'approval',  'max_risk' => 'high',     'min_confidence' => 0.95],
        ['action_type' => 'rollback_deployment',  'mode' => 'forbidden', 'max_risk' => 'critical', 'min_confidence' => 1.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health thresholds
    |--------------------------------------------------------------------------
    | projects.health_status is "right now"; success_rate is a 30-day average.
    | A project at 98% whose last pipeline just failed must not render green.
    */
    'health' => [
        'degraded_below_success_rate' => 90.0,
    ],
];
