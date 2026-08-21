# LatidoFlow for Laravel

`latidoflow/laravel` reports evidence from named foreground Laravel schedules and explicitly allowlisted queue jobs to LatidoFlow. It synchronizes heartbeat definitions, reports supported lifecycle state, and can attach bounded numeric business-output metrics or typed semantic evidence without sending command arguments, serialized job payloads, exception text, or response bodies.

## Requirements

- PHP 8.3 or later
- Laravel 13
- A LatidoFlow workspace integration token

## Installation

Install the package and publish its configuration:

```bash
composer require latidoflow/laravel
php artisan latidoflow:install
```

The install command preserves an existing `config/latidoflow.php`. Use `php artisan latidoflow:install --force` only when you intentionally want to overwrite that file, and review any local configuration changes first.

The supported application integration surface is the published `latidoflow` configuration, the `latidoflow:*` Artisan commands, the `LatidoFlow` facade methods documented below, and the `LatidoFlow\Laravel\Contracts\LatidoFlowClient` contract for applications that intentionally replace the transport binding.

Create a workspace integration token from the LatidoFlow `/integrations` page and add it to the application environment:

```dotenv
LATIDOFLOW_TOKEN=lf_workspace_token
LATIDOFLOW_PROJECT_SLUG=billing-api
LATIDOFLOW_ENDPOINT=https://www.latidoflow.com
```

`LATIDOFLOW_ENDPOINT` is optional when using the hosted service. Laravel resolves the token into application configuration. If configuration is cached, the resolved token is present in Laravel's generated configuration cache, so protect that file as a secret and never commit it.

## First synchronization

Define at least one workload before running `latidoflow:sync` or `latidoflow:verify`. A named foreground schedule reports automatic runtime lifecycle events:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reports:daily')
    ->daily()
    ->timezone('Europe/Madrid')
    ->name('Daily reports');
```

Alternatively, configure at least one queue definition as described below. Then add the definition-sync housekeeping schedule to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('latidoflow:sync')
    ->hourly()
    ->name('LatidoFlow definition sync');
```

The package excludes this housekeeping command from the synchronized definitions, regardless of its display name. It does not create a dummy verification monitor, and it refuses to send an empty definition set.

Build and validate the current definitions without sending them:

```bash
php artisan latidoflow:sync --dry-run
```

Verify the token by synchronizing those same current definitions:

```bash
php artisan latidoflow:verify
```

`latidoflow:verify` sends the actual schedule and queue definitions; it does not use a placeholder monitor. A successful verification proves authentication and definition synchronization, not that a workload executed. Use `php artisan latidoflow:sync` for later manual synchronizations; the named hourly command keeps them synchronized automatically.

## Scheduled workload reporting

Give each monitored schedule a safe, stable name. Each definition keeps its own configured timezone and falls back to `config('app.timezone')` when the event has no timezone:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reports:daily')
    ->daily()
    ->timezone('Europe/Madrid')
    ->name('Daily reports');
```

Unnamed schedules are synchronized under the generic name `Unnamed Laravel schedule` for visibility but do not report automatic runtime lifecycle events. Command arguments and command-derived fingerprints are never included in the synchronized metadata. Multiple unnamed schedules produce duplicate identities and must be given unique names before synchronization.

Schedules configured with `runInBackground()` are also synchronized for due-time visibility, but their definition is marked `unsupported_background` and this package does not report their runtime lifecycle. Use a foreground scheduled command or an allowlisted queue job when lifecycle reporting and business evidence are required.

Sub-minute Laravel schedules are not supported. If any scheduled definition uses a seconds-based frequency, synchronization stops before making a request. Use a frequency of one minute or longer.

### Definition limits

Each synchronization request may contain at most 100 combined schedule and queue definitions, and the complete JSON payload may not exceed 32 KiB. Synchronization also rejects empty or duplicate generated slugs, monitor names or slugs longer than 160 characters, project or environment names or slugs longer than 120 characters, and duplicate runtime-enabled queue mappings. These validations run before the request is sent.

Timing values are also validated against the ingestion contract: check intervals must be 1–10,080 minutes, grace periods 0–86,400 seconds, monitor timeouts 1–604,800 seconds, and queue-start timeouts 1–86,400 seconds. Values must be integers; invalid configuration fails locally before a dry run or network request.

## Queue workload reporting

Queue reporting is opt-in. Publish the configuration and allowlist an exact job class, connection, and queue:

```php
'queues' => [
    [
        'name' => 'Invoice exports',
        'connection' => 'redis',
        'queue' => 'billing',
        'job_class' => App\Jobs\ExportInvoices::class,
        'runtime_reporting' => true,
        'start_timeout_seconds' => 300,
    ],
],
```

Only one exact matching definition is accepted for a job. Matching uses Laravel's queued job class, so a custom `displayName()` does not break the allowlist. Broad or ambiguous automatic monitoring is intentionally rejected. Runtime reporting is intended for deliberately selected, business-critical workloads rather than unrestricted high-volume queue telemetry.

Automatic releases and retries remain part of the same monitored run. When `queue:retry` replays a terminal failed job, the package adds a fresh random run identity to that failed payload before Laravel pushes it back to the queue. This prevents the replay from being mistaken for the already-terminal run while leaving the job UUID and serialized command unchanged.

Laravel propagates hidden logging context into queued payloads. When an asynchronous worker begins a job, the package clears only its inherited LatidoFlow execution context before applying the queue allowlist, preventing an untracked child job from attaching output to its parent run. The synchronous queue driver keeps the outer context because the child executes within the parent's call stack; an allowlisted synchronous child temporarily stacks its own context and then restores the parent.

## Business output and semantic evidence

Inside the currently monitored schedule or queue job, record up to 20 flat numeric metrics:

```php
use LatidoFlow\Laravel\Facades\LatidoFlow;

LatidoFlow::output([
    'records_processed' => 42,
    'invoices_failed' => 0,
]);
```

Metric names must begin with a letter and may contain letters, numbers, underscores, dots, and hyphens. Values must be finite integers or floats; numeric strings, nested data, and arbitrary payloads are rejected. The helper returns `false` when no monitored execution is active, metrics are invalid, or the configured output cache is unavailable. These conditions do not change the workload result.

Output assertions are configured by generated monitor slug:

```php
'output_assertions' => [
    'daily-reports' => [
        ['metric' => 'records_processed', 'operator' => 'gte', 'value' => 1],
    ],
],
```

Omitting a slug preserves its server-side assertion contract. Configuring an explicit empty array clears that contract during the next synchronization.

For structured checks, record one JSON-compatible evidence document during the monitored execution:

```php
use LatidoFlow\Laravel\Facades\LatidoFlow;

LatidoFlow::evidence([
    'report' => [
        'status' => 'complete',
        'records_processed' => 42,
        'had_warnings' => false,
        'completed_at' => now()->toIso8601String(),
    ],
]);
```

Evidence may contain strings, booleans, finite numbers, `null`, and nested arrays. It is limited to 16 KiB of encoded JSON, 64 total nodes, eight levels of nesting, 2,048 bytes per string, and 64 bytes per string key. Objects, resources, non-finite numbers, invalid JSON strings, and over-limit documents are rejected. A later `evidence()` call replaces the document from an earlier call for the same execution; call it with the final evidence you want evaluated.

Like `output()`, `evidence()` returns `false` when there is no active monitored execution, the value is invalid, or cache storage is unavailable. Local capture is fail-open and never changes the Laravel workload's exit result. A configured server-side semantic check remains fail-closed: absent or invalid evidence can make the LatidoFlow monitor run fail even when the Laravel workload itself exited successfully.

Laravel runs a foreground scheduled command in a child process and its lifecycle callbacks in the scheduler process. Scheduler output and evidence therefore require a cache store shared between processes, such as `file`, `database`, or `redis`. The `array` and `null` cache drivers are rejected for scheduled-workload capture; queue jobs may use them only when their whole lifecycle remains in one worker process. Set `LATIDOFLOW_CACHE_STORE` to an appropriate shared store when Laravel's default cache is process-local.

Configure the versioned semantic contract by generated monitor slug:

```php
'semantic_checks' => [
    'daily-reports' => [
        'version' => 2,
        'rules' => [[
            'id' => 'report-complete',
            'source' => 'output',
            'path' => '$.report.status',
            'expect' => ['operator' => 'equals', 'value' => 'complete'],
        ], [
            'id' => 'records-processed',
            'source' => 'output',
            'path' => '$.report.records_processed',
            'expect' => ['operator' => 'gte', 'value' => 1],
        ]],
    ],
],
```

The package also attaches an `alert_truth` policy when one is configured for the generated slug:

```php
'alert_truth' => [
    'daily-reports' => [
        'failure_threshold' => 2,
        'sample_size' => 3,
        'recovery_threshold' => 1,
        'flap_transition_threshold' => 4,
        'flap_window_seconds' => 600,
        'flap_suppression_seconds' => 900,
        'dependency_monitor_uuids' => [],
    ],
],
```

These contracts are sent as first-class definition fields, never inside monitor metadata. An unconfigured slug omits the field and preserves its server-side contract. Set `semantic_checks` or `alert_truth` to `null` for a slug to explicitly clear that contract. LatidoFlow validates the contract and applies only controls supported by that monitor type; this package does not claim or synthesize independent probe regions for scheduler or queue heartbeats.

## Configuration

Publish `config/latidoflow.php` with `php artisan latidoflow:install` or:

```bash
php artisan vendor:publish --tag=latidoflow-config
```

Important environment variables:

| Variable | Purpose | Default |
| --- | --- | --- |
| `LATIDOFLOW_TOKEN` | Workspace-scoped bearer token | none |
| `LATIDOFLOW_ENDPOINT` | LatidoFlow application origin | `https://www.latidoflow.com` |
| `LATIDOFLOW_ALLOW_INSECURE_HTTP` | Permit plain HTTP only for isolated local development | `false` |
| `LATIDOFLOW_PROJECT_SLUG` | Stable project identifier | derived from `APP_NAME` |
| `LATIDOFLOW_CACHE_STORE` | Cache store used for bounded runtime output and semantic evidence | Laravel default cache store |

Definition synchronization and workload runtime reporting use separate HTTP profiles in `config/latidoflow.php`:

| Profile | Used by | Connect timeout | Request timeout | Default retries |
| --- | --- | ---: | ---: | --- |
| Sync | `latidoflow:sync` and `latidoflow:verify` | 1 second | 3 seconds | delays of 100 ms and 500 ms for connection failures, HTTP 408/425/429, and server errors |
| Runtime | schedule and queue lifecycle events | 0.5 seconds | 1.5 seconds | none |

The sync commands surface transport and HTTP failures to the operator. Runtime instrumentation is fail-open: reporting failures flow through Laravel exception reporting and do not change the monitored workload outcome. All package requests use the configured application origin and never follow redirects, preventing the workspace token from being forwarded through an unexpected redirect. You may adjust both profiles in the published configuration.

HTTP profile settings are validated before any request. Sync connect/request timeouts must remain within 0.1–10/0.2–30 seconds, with at most three retry delays of no more than 5,000 ms each. Runtime connect/request timeouts must remain within 0.1–2/0.2–5 seconds, with at most one retry delay of no more than 1,000 ms. The endpoint must use HTTPS by default. The insecure HTTP override exposes the bearer token in transit and is intended only for an isolated local service; never enable it for a production endpoint.

### Configuration cache and token rotation

Laravel queue workers are long-lived and do not automatically reload changed environment or configuration values. After rotating `LATIDOFLOW_TOKEN`, update the server-side environment, rebuild cached configuration when your deployment uses it, and restart the workers:

```bash
php artisan config:clear
php artisan config:cache
php artisan queue:restart
php artisan latidoflow:verify
```

Omit `config:cache` when the deployment intentionally runs without cached configuration. Laravel stores the `queue:restart` signal in the configured cache, so ensure that cache is available and that the process manager starts replacement workers after they exit. Restart any other long-running PHP process that keeps the old configuration in memory. A cron-driven `schedule:run` invocation starts a fresh process; a persistent `schedule:work` process must be restarted.

## Data boundary

The package sends the configured workspace token only as the HTTP `Authorization` header to the configured LatidoFlow endpoint. It does not include that token in event JSON, metadata, or logs. Event payloads contain configured monitor identity, timestamps, queue metadata, attempt state, exit state, explicitly supplied numeric output, and explicitly supplied typed evidence. They do not contain:

- command arguments;
- serialized queue payloads;
- exception messages or traces;
- LatidoFlow response bodies.

Semantic evidence can itself contain business data. Include only fields needed by configured checks; do not place secrets, personal data, serialized models, or arbitrary payloads in `evidence()`.

Treat the workspace token as a secret. Never commit it, log it, or place it in a client-side environment.

## Development

Install dependencies and run the standalone checks:

```bash
composer update
composer test
composer lint
composer audit
```

Tests prevent stray HTTP requests and load the service provider through Orchestra Testbench. CI also installs the package without symlinks into a clean Laravel 13 application and verifies package discovery and all three Artisan commands.

## Support and security

Use [GitHub issues](https://github.com/Elvesora/latidoflow-laravel/issues) for reproducible non-sensitive defects. Follow [SECURITY.md](SECURITY.md) for vulnerability reports and do not disclose secrets or customer data in a public issue.

## License

LatidoFlow for Laravel is open-source software licensed under the [MIT license](LICENSE).
