<?php

namespace LatidoFlow\Laravel\Tests\Feature;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use LatidoFlow\Laravel\Commands\InstallCommand;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use LatidoFlow\Laravel\Tests\TestCase;
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

final class RecordingSyncClient implements LatidoFlowClient
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
}
