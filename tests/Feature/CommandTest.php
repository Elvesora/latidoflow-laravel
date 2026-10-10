<?php

namespace LatidoFlow\Laravel\Tests\Feature;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use LatidoFlow\Laravel\Commands\InstallCommand;
use LatidoFlow\Laravel\Contracts\ApplicationLogClient;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use LatidoFlow\Laravel\Tests\TestCase;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

class CommandTest extends TestCase
{
    public function test_install_does_not_overwrite_existing_configuration_by_default(): void
    {
        $command = new RecordingInstallCommand;
        $command->setLaravel($this->app);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([[
            'command' => 'vendor:publish',
            'arguments' => ['--tag' => 'latidoflow-config'],
        ]], $command->calls);
        $this->assertStringContainsString('use Illuminate\Support\Facades\Schedule;', $tester->getDisplay());
        $this->assertStringContainsString("Schedule::command('latidoflow:sync')", $tester->getDisplay());
        $this->assertStringNotContainsString('$schedule->command', $tester->getDisplay());
    }

    public function test_install_force_option_explicitly_overwrites_configuration(): void
    {
        $command = new RecordingInstallCommand;
        $command->setLaravel($this->app);

        $exitCode = (new CommandTester($command))->execute(['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([[
            'command' => 'vendor:publish',
            'arguments' => [
                '--tag' => 'latidoflow-config',
                '--force' => true,
            ],
        ]], $command->calls);
    }

    public function test_install_propagates_configuration_publish_failure(): void
    {
        $command = new RecordingInstallCommand(1);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('configuration could not be published', $tester->getDisplay());
        $this->assertStringNotContainsString('Then run: php artisan latidoflow:verify', $tester->getDisplay());
    }

    public function test_install_warns_when_existing_configuration_is_left_unchanged(): void
    {
        $configurationPath = config_path('latidoflow.php');
        $originalContents = is_file($configurationPath) ? file_get_contents($configurationPath) : null;
        file_put_contents($configurationPath, "<?php\n\nreturn ['existing' => true];\n");

        try {
            $command = new RecordingInstallCommand;
            $command->setLaravel($this->app);
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([]);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('left unchanged', $tester->getDisplay());
            $this->assertStringContainsString('Use --force to replace it', $tester->getDisplay());
        } finally {
            if (is_string($originalContents)) {
                file_put_contents($configurationPath, $originalContents);
            } elseif (is_file($configurationPath)) {
                unlink($configurationPath);
            }
        }
    }

    public function test_verify_synchronizes_current_definitions_instead_of_a_synthetic_monitor(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('latidoflow:sync')->hourly()->name('LatidoFlow definition sync');
        $schedule->command('reports:daily')->daily()->description('Daily reports');
        $client = new RecordingSyncClient(200);
        $this->app->instance(Schedule::class, $schedule);
        $this->app->instance(LatidoFlowClient::class, $client);

        $this->artisan('latidoflow:verify')
            ->expectsOutputToContain('current definitions verified')
            ->assertSuccessful();

        $this->assertCount(1, $client->payloads);
        $this->assertCount(1, $client->payloads[0]['monitors']);
        $this->assertSame('daily-reports', $client->payloads[0]['monitors'][0]['slug']);
        $this->assertNotSame('latidoflow-verification', $client->payloads[0]['monitors'][0]['slug']);
    }

    public function test_verify_returns_failure_when_definition_sync_is_rejected(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->description('Daily reports');
        $client = new RecordingSyncClient(401);
        $this->app->instance(Schedule::class, $schedule);
        $this->app->instance(LatidoFlowClient::class, $client);

        $this->artisan('latidoflow:verify')
            ->expectsOutputToContain('failed with HTTP 401')
            ->assertFailed();

        $this->assertCount(1, $client->payloads);
        $this->assertSame('daily-reports', $client->payloads[0]['monitors'][0]['slug']);
    }

    public function test_sync_rejection_reports_only_http_status_and_returns_failure(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->description('Daily reports');
        $client = new RecordingSyncClient(422, [
            'message' => 'Private validation detail with customer token.',
        ]);
        $this->app->instance(Schedule::class, $schedule);
        $this->app->instance(LatidoFlowClient::class, $client);

        $exitCode = Artisan::call('latidoflow:sync');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('failed with HTTP 422', $output);
        $this->assertStringNotContainsString('Private validation detail', $output);
        $this->assertStringNotContainsString('customer token', $output);
        $this->assertCount(1, $client->payloads);
    }

    public function test_sync_redirect_is_not_treated_as_success(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->description('Daily reports');
        $client = new RecordingSyncClient(302);
        $this->app->instance(Schedule::class, $schedule);
        $this->app->instance(LatidoFlowClient::class, $client);

        $this->artisan('latidoflow:sync')
            ->expectsOutputToContain('failed with HTTP 302')
            ->assertFailed();
    }

    public function test_doctor_reports_pass_warning_and_mutation_without_reading_runtime_evidence(): void
    {
        $this->configureDoctorHttp();
        $this->bindDoctorSchedule();

        $this->assertDoctorCommand(0, [
            '[pass] package:',
            '[pass] origin:',
            '[pass] token:',
            '[pass] definitions:',
            '[warning] cache:',
            '[pass] queue:',
            '[pass] pipeline:',
            '[pass] sync:',
            '(mutation)',
            'Workspace integration',
            'does not read runtime evidence with the ingestion token',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://latidoflow.example.test/health/monitoring-pipeline'
            && ! $request->hasHeader('Authorization'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://latidoflow.example.test/api/v1/monitors/sync');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/runs')
            || str_contains($request->url(), '/runtime/'));
    }

    public function test_doctor_skip_sync_is_read_only(): void
    {
        $this->configureDoctorHttp();
        $this->bindDoctorSchedule();

        $this->assertDoctorCommand(0, ['not run (read-only)'], ['(mutation)'], ['--skip-sync' => true]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://latidoflow.example.test/health/monitoring-pipeline');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/monitors/sync'));
    }

    public function test_doctor_invalid_auth_is_a_blocker_without_response_bodies(): void
    {
        $this->configureDoctorEnvironment();
        $this->bindDoctorSchedule();
        Http::fake([
            'https://latidoflow.example.test/health/monitoring-pipeline' => Http::response([
                'ok' => true,
                'status' => 'healthy',
            ], 200),
            'https://latidoflow.example.test/api/v1/monitors/sync' => Http::response([
                'message' => 'Token lf_secret_redact_me is revoked.',
                'authorization' => 'Bearer lf_secret_redact_me',
            ], 401),
        ]);

        $this->assertDoctorCommand(1, [
            '[blocker] sync:',
            'authentication failed with HTTP 401',
        ], [
            'lf_secret_redact_me',
            'Token ',
            'revoked',
        ]);
    }

    public function test_doctor_timeout_is_a_blocker_without_transport_details(): void
    {
        $this->configureDoctorEnvironment();
        config()->set('latidoflow.token', 'lf_secret_redact_me');
        config()->set('latidoflow.http.sync.retry_delays_ms', []);
        Http::fake(function (): never {
            throw new ConnectionException(
                'cURL error 28: Operation timed out after 3000 milliseconds contacting https://latidoflow.example.test/health/monitoring-pipeline',
            );
        });
        $this->bindDoctorSchedule();

        $this->assertDoctorCommand(1, [
            '[blocker] pipeline:',
            'timed out',
        ], [
            'cURL',
            'lf_secret_redact_me',
            'latidoflow.example.test',
        ]);
    }

    public function test_doctor_redacts_tokens_bodies_headers_secret_urls_and_local_paths(): void
    {
        $secretToken = 'lf_secret_redact_me';
        $this->configureDoctorEnvironment();
        config()->set('latidoflow.token', $secretToken);
        Http::fake([
            'https://latidoflow.example.test/health/monitoring-pipeline' => Http::response(
                '<html>leak '.$secretToken.' '.base_path().'</html>',
                302,
                ['Location' => 'https://user:'.$secretToken.'@evil.example.test/callback'],
            ),
            'https://latidoflow.example.test/api/v1/monitors/sync' => Http::response([
                'error' => $secretToken,
            ], 500),
        ]);
        $this->bindDoctorSchedule();

        $this->assertDoctorCommand(1, [
            '[blocker] pipeline:',
        ], [
            $secretToken,
            'evil.example.test',
            base_path(),
            config_path('latidoflow.php'),
            '<html>',
        ]);
    }

    public function test_doctor_missing_token_is_a_blocker_and_still_checks_the_public_pipeline(): void
    {
        $this->configureDoctorHttp();
        config()->set('latidoflow.token');
        $this->bindDoctorSchedule();

        $this->assertDoctorCommand(1, [
            '[blocker] token:',
            '[pass] pipeline:',
            'not run because an earlier check blocked',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://latidoflow.example.test/health/monitoring-pipeline'
            && ! $request->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/monitors/sync'));
    }

    public function test_doctor_duplicate_slugs_do_not_expose_configured_names(): void
    {
        $this->configureDoctorHttp();
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->name('private-secret-monitor');
        $schedule->command('reports:weekly')->weekly()->name('private-secret-monitor');
        $this->app->instance(Schedule::class, $schedule);

        $this->assertDoctorCommand(1, [
            '[blocker] definitions:',
            'LatidoFlow monitor slugs must be unique.',
        ], ['private-secret-monitor']);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/monitors/sync'));
    }

    public function test_doctor_duplicate_queue_mappings_do_not_expose_tokens_secret_urls_or_local_paths(): void
    {
        $this->configureDoctorHttp();
        $this->bindDoctorSchedule();
        $token = 'lf_private_duplicate_queue_token';
        $secretUrl = 'https://user:'.$token.'@private.example.test/queue';
        $localPath = base_path('private-worker.php');
        $queue = [
            'connection' => 'sync',
            'queue' => $secretUrl,
            'job_class' => $localPath,
            'runtime_reporting' => true,
        ];
        config()->set('latidoflow.token', $token);
        config()->set('latidoflow.queues', [
            ['name' => 'First queue', ...$queue],
            ['name' => 'Second queue', ...$queue],
        ]);

        $this->assertDoctorCommand(1, [
            '[blocker] definitions:',
            'Runtime-enabled LatidoFlow queue definitions must have unique connection, queue, and job_class combinations.',
        ], [$token, $secretUrl, $localPath]);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/monitors/sync'));
    }

    public function test_doctor_custom_transport_errors_do_not_expose_exception_details(): void
    {
        $this->configureDoctorHttp();
        $this->bindDoctorSchedule();
        $token = 'lf_private_transport_token';
        $secretUrl = 'https://user:'.$token.'@private.example.test/sync';
        $localPath = base_path('private-transport.php');
        $client = Mockery::mock(LatidoFlowClient::class);
        $client->shouldReceive('sync')->once()->andThrow(new RuntimeException(
            'Private transport response body '.$token.' '.$secretUrl.' '.$localPath,
        ));
        $this->app->instance(LatidoFlowClient::class, $client);

        $this->assertDoctorCommand(1, [
            '[blocker] sync:',
            'definition sync could not complete; check the transport and sync configuration',
            '(mutation)',
        ], [$token, $secretUrl, $localPath, 'Private transport response body']);
    }

    public function test_doctor_empty_definitions_and_invalid_origin_are_blockers(): void
    {
        $this->configureDoctorEnvironment();
        config()->set('latidoflow.endpoint', 'not-an-origin');
        $this->app->instance(Schedule::class, new Schedule('UTC'));

        $this->assertDoctorCommand(1, [
            '[blocker] origin:',
            '[blocker] definitions:',
            'Define at least one scheduled task or configure an allowlisted queue before syncing.',
        ]);

        Http::assertNothingSent();
    }

    public function test_doctor_unhealthy_pipeline_is_a_blocker_without_the_json_payload(): void
    {
        $this->configureDoctorEnvironment();
        $this->bindDoctorSchedule();
        Http::fake([
            'https://latidoflow.example.test/health/monitoring-pipeline' => Http::response([
                'ok' => false,
                'status' => 'unhealthy',
                'checks' => [
                    'scheduler' => ['ok' => false, 'reason' => 'private scheduler hostname /var/app'],
                ],
            ], 503),
            'https://latidoflow.example.test/api/v1/monitors/sync' => Http::response($this->doctorSyncResponse(), 200),
        ]);

        $this->assertDoctorCommand(1, [
            '[blocker] pipeline:',
            'unhealthy',
        ], [
            'private scheduler hostname',
            '/var/app',
        ]);
    }

    public function test_doctor_warns_when_runtime_queues_use_the_sync_driver(): void
    {
        $this->configureDoctorHttp();
        $this->bindDoctorSchedule();
        config()->set('latidoflow.queues', [[
            'name' => 'Invoices queue',
            'connection' => 'sync',
            'queue' => 'default',
            'job_class' => 'App\\Jobs\\SyncInvoices',
            'runtime_reporting' => true,
        ]]);

        $this->assertDoctorCommand(0, ['[warning] queue:']);
    }

    public function test_doctor_mentions_unlisted_failure_reporting_without_queue_definitions(): void
    {
        $this->configureDoctorHttp();
        $this->bindDoctorSchedule();
        config()->set('latidoflow.queues', []);
        config()->set('latidoflow.queue_unlisted_failures.enabled', true);

        $this->assertDoctorCommand(0, [
            '[pass] queue:',
            'unlisted queue-failure reporting is enabled',
        ], parameters: ['--skip-sync' => true]);
    }

    public function test_doctor_checks_later_queue_blockers_before_returning_a_sync_driver_warning(): void
    {
        $this->configureDoctorHttp();
        $this->bindDoctorSchedule();
        config()->set('latidoflow.queues', [[
            'name' => 'Synchronous invoices',
            'connection' => 'sync',
            'queue' => 'default',
            'job_class' => 'App\\Jobs\\SyncInvoices',
            'runtime_reporting' => true,
        ], [
            'name' => 'Async invoices',
            'connection' => 'missing-connection',
            'queue' => 'default',
            'job_class' => 'App\\Jobs\\ExportInvoices',
            'runtime_reporting' => true,
        ]]);

        $this->assertDoctorCommand(1, [
            '[blocker] queue:',
            'a runtime queue connection is not configured',
        ], ['[warning] queue:']);
    }

    private function configureDoctorEnvironment(): void
    {
        Http::swap(new HttpFactory);
        config()->set('latidoflow.token', 'lf_test_doctor');
        config()->set('latidoflow.endpoint', 'https://latidoflow.example.test');
        config()->set('latidoflow.http.sync.retry_delays_ms', [0]);
        Http::preventStrayRequests();
    }

    private function configureDoctorHttp(): void
    {
        $this->configureDoctorEnvironment();
        Http::fake([
            'https://latidoflow.example.test/health/monitoring-pipeline' => Http::response([
                'ok' => true,
                'status' => 'healthy',
            ], 200),
            'https://latidoflow.example.test/api/v1/monitors/sync' => Http::response($this->doctorSyncResponse(), 200),
        ]);
    }

    /**
     * @param  list<string>  $contains
     * @param  list<string>  $missing
     * @param  array<string, mixed>  $parameters
     */
    private function assertDoctorCommand(
        int $exitCode,
        array $contains,
        array $missing = [],
        array $parameters = [],
    ): void {
        $actualExitCode = Artisan::call('latidoflow:doctor', $parameters);
        $output = Artisan::output();
        $this->assertSame($exitCode, $actualExitCode, $output);

        foreach ($contains as $needle) {
            $this->assertStringContainsString($needle, $output, $output);
        }

        foreach ($missing as $needle) {
            $this->assertStringNotContainsString($needle, $output, $output);
        }
    }

    private function bindDoctorSchedule(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->name('Daily reports');
        $this->app->instance(Schedule::class, $schedule);
    }

    /**
     * @return array{project_uuid: string, environment_uuid: string, monitors: array<int, array<string, string>>}
     */
    private function doctorSyncResponse(): array
    {
        return [
            'project_uuid' => 'ad469be1-8131-4d03-8d22-d8384aec5605',
            'environment_uuid' => '55f96f65-ee75-439c-8e28-dd215b2472fb',
            'monitors' => [[
                'uuid' => 'a6b771c2-13d5-47ad-93ec-35626222da24',
                'slug' => 'daily-reports',
                'type' => 'heartbeat',
            ]],
        ];
    }
}

final class RecordingInstallCommand extends InstallCommand
{
    /** @var array<int, array{command: mixed, arguments: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        private readonly int $childExitCode = 0,
    ) {
        parent::__construct();
    }

    public function call($command, array $arguments = []): int
    {
        $this->calls[] = compact('command', 'arguments');

        return $this->childExitCode;
    }
}

final class RecordingSyncClient implements ApplicationLogClient, LatidoFlowClient
{
    /** @var array<int, array<string, mixed>> */
    public array $payloads = [];

    public function __construct(
        private readonly int $status,
        private readonly array $responseBody = [],
    ) {}

    public function sync(array $payload): Response
    {
        $this->payloads[] = $payload;

        return new Response(new PsrResponse($this->status, [
            'Content-Type' => 'application/json',
        ], json_encode($this->responseBody, JSON_THROW_ON_ERROR)));
    }

    public function queued(array $reference, array $payload): void {}

    public function start(array $reference, array $payload): string
    {
        return (string) ($payload['run_uuid'] ?? '');
    }

    public function skipped(array $reference, array $payload): void {}

    public function heartbeat(string $runUuid, array $payload): void {}

    public function success(string $runUuid, array $payload): void {}

    public function fail(string $runUuid, array $payload): void {}

    public function applicationLogs(array $payload): void {}
}
