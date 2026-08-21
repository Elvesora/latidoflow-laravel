<?php

return [
    'token' => env('LATIDOFLOW_TOKEN'),
    'endpoint' => env('LATIDOFLOW_ENDPOINT', 'https://www.latidoflow.com'),
    'allow_insecure_http' => (bool) env('LATIDOFLOW_ALLOW_INSECURE_HTTP', false),
    'http' => [
        'sync' => [
            'connect_timeout_seconds' => 1,
            'timeout_seconds' => 3,
            'retry_delays_ms' => [100, 500],
        ],
        'runtime' => [
            'connect_timeout_seconds' => 0.5,
            'timeout_seconds' => 1.5,
            'retry_delays_ms' => [],
        ],
    ],
    'runtime' => [
        'enabled' => true,
        'cache_store' => env('LATIDOFLOW_CACHE_STORE'),
        'output_ttl_seconds' => 86_400,
    ],
    'project' => [
        'name' => env('APP_NAME', 'Laravel App'),
        'slug' => env('LATIDOFLOW_PROJECT_SLUG'),
    ],
    'environment' => [
        'name' => env('APP_ENV', 'production'),
        'slug' => env('APP_ENV', 'production'),
        'kind' => env('APP_ENV', 'production'),
        'is_production' => env('APP_ENV', 'production') === 'production',
    ],
    'defaults' => [
        'grace_seconds' => 300,
        'timeout_seconds' => 3600,
        'check_interval_minutes' => 60,
        'queue_start_timeout_seconds' => 300,
    ],
    'output_assertions' => [
        // Keys match the generated schedule or queue monitor slug.
        // 'daily-reports' => [
        //     ['metric' => 'records_processed', 'operator' => 'gte', 'value' => 1],
        // ],
    ],
    'semantic_checks' => [
        // Keys match the generated schedule or queue monitor slug.
        // 'daily-reports' => [
        //     'version' => 2,
        //     'rules' => [[
        //         'id' => 'records_processed',
        //         'source' => 'output',
        //         'path' => '$.report.records_processed',
        //         'expect' => ['operator' => 'gte', 'value' => 1],
        //     ]],
        // ],
    ],
    'alert_truth' => [
        // Keys match the generated schedule or queue monitor slug.
        // 'daily-reports' => [
        //     'failure_threshold' => 2,
        //     'sample_size' => 3,
        //     'recovery_threshold' => 1,
        // ],
    ],
    'queues' => [
        // Runtime reporting is opt-in and intended for low-volume, business-critical jobs.
        // ['name' => 'Invoices queue', 'connection' => 'redis', 'queue' => 'invoices', 'job_class' => App\Jobs\SyncInvoices::class, 'runtime_reporting' => true],
    ],
];
