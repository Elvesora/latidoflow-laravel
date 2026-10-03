<?php

namespace LatidoFlow\Laravel\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use LatidoFlow\Laravel\Logging\ApplicationLogBuffer;
use LatidoFlow\Laravel\Logging\LatidoFlowApplicationLogHandler;
use LatidoFlow\Laravel\Runtime\ExecutionContext;
use LatidoFlow\Laravel\Tests\TestCase;
use RuntimeException;
use stdClass;

class ApplicationLogDriverTest extends TestCase
{
    public function test_context_only_messages_cannot_discard_neighboring_errors(): void
    {
        $this->enableApplicationLogs();
        Http::fake(['https://latidoflow.test/api/v1/application-logs' => Http::response([], 201)]);

        Log::channel('latidoflow')->warning('', ['cause' => 'context only']);
        Log::channel('latidoflow')->warning(" \t\n ", ['cause' => 'whitespace only']);
        Log::channel('latidoflow')->error('Neighboring error remains available.');
        app(ApplicationLogBuffer::class)->flush();

        Http::assertSentCount(1);
        $logs = Http::recorded()[0][0]->data()['logs'];
        $this->assertCount(3, $logs);
        $this->assertSame('[empty message]', $logs[0]['message']);
        $this->assertSame('[empty message]', $logs[1]['message']);
        $this->assertSame('Neighboring error remains available.', $logs[2]['message']);
        $this->assertSame('context only', $logs[0]['context']['cause']);
    }

    public function test_driver_is_disabled_by_default(): void
    {
        config()->set('latidoflow.token', 'test-workspace-token');
        config()->set('latidoflow.endpoint', 'https://latidoflow.test');
        config()->set('latidoflow.project.slug', 'billing-app');
        config()->set('latidoflow.environment.slug', 'production');
        config()->set('logging.channels.latidoflow', [
            'driver' => 'latidoflow',
            'level' => 'warning',
            'enabled' => false,
        ]);
        Log::forgetChannel('latidoflow');
        Http::preventStrayRequests();

        Log::channel('latidoflow')->emergency('Must not leave the process.');
        app(ApplicationLogBuffer::class)->flush();

        Http::assertNothingSent();
    }

    public function test_default_threshold_filters_info_before_transport_and_sends_warning_and_error(): void
    {
        $this->enableApplicationLogs();
        Http::fake(['https://latidoflow.test/api/v1/application-logs' => Http::response([], 201)]);
        Context::add('request_body', 'must-not-be-captured');

        Log::channel('latidoflow')->info('Routine progress must stay local.');
        Log::channel('latidoflow')->warning('API token=private-value was rejected.', [
            'authorization' => 'Bearer private-token',
            'attempt' => 2,
        ]);
        Log::channel('latidoflow')->error('Queue failed.', [
            'exception' => new RuntimeException('Sensitive exception detail'),
        ]);
        $this->assertSame(2, app(ApplicationLogBuffer::class)->count());
        app(ApplicationLogBuffer::class)->flush();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $logs = $request->data()['logs'] ?? [];

            return $request->url() === 'https://latidoflow.test/api/v1/application-logs'
                && $request->hasHeader('Authorization', 'Bearer test-workspace-token')
                && count($logs) === 2
                && ($logs[0]['level'] ?? null) === 'warning'
                && ($logs[0]['message'] ?? null) === 'API token=[redacted] was rejected.'
                && data_get($logs, '0.context.authorization') === '[redacted]'
                && ($logs[1]['level'] ?? null) === 'error'
                && data_get($logs, '1.context.exception.exception_class') === RuntimeException::class
                && ! str_contains($request->body(), 'Routine progress')
                && ! str_contains($request->body(), 'private-value')
                && ! str_contains($request->body(), 'private-token')
                && ! str_contains($request->body(), 'Sensitive exception detail')
                && ! str_contains($request->body(), 'must-not-be-captured');
        });
    }

    public function test_driver_batches_at_32_and_keeps_each_request_within_32_kib(): void
    {
        $this->enableApplicationLogs();
        Http::fake(['https://latidoflow.test/api/v1/application-logs' => Http::response([], 201)]);

        foreach (range(1, 33) as $sequence) {
            Log::channel('latidoflow')->warning("Batch entry {$sequence}", ['sequence' => $sequence]);
        }

        app(ApplicationLogBuffer::class)->flush();

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $this->assertSame([32, 1], $recorded->map(fn (array $pair): int => count($pair[0]->data()['logs']))->all());
        $this->assertTrue($recorded->every(fn (array $pair): bool => strlen($pair[0]->body()) <= 32 * 1024));
    }

    public function test_driver_uses_the_same_normalized_project_and_environment_identity_as_runtime_reporting(): void
    {
        $this->enableApplicationLogs();
        config()->set('latidoflow.project.slug');
        config()->set('latidoflow.project.name', 'Billing App');
        config()->set('latidoflow.environment.slug', 'Production US');
        Http::fake(['https://latidoflow.test/api/v1/application-logs' => Http::response([], 201)]);

        Log::channel('latidoflow')->warning('Identity check.');
        app(ApplicationLogBuffer::class)->flush();

        Http::assertSent(fn (Request $request): bool => $request['project_slug'] === 'billing-app'
            && $request['environment_slug'] === 'production-us');
    }

    public function test_long_ascii_and_utf8_logs_remain_valid_within_the_ingestion_contract(): void
    {
        $this->enableApplicationLogs();
        Http::fake(['https://latidoflow.test/api/v1/application-logs' => Http::response([], 201)]);

        Log::channel('latidoflow')->warning(str_repeat('x', 10001));
        Log::channel('latidoflow')->error(str_repeat('🙂', 3000), [str_repeat('ж', 100) => str_repeat('я', 1025)]);
        app(ApplicationLogBuffer::class)->flush();

        $logs = Http::recorded()->flatMap(fn (array $pair): array => $pair[0]->data()['logs']);
        $this->assertCount(2, $logs);
        $this->assertTrue($logs->every(fn (array $log): bool => strlen($log['message']) <= 10000
            && mb_check_encoding($log['message'], 'UTF-8')
            && str_ends_with($log['message'], '...[truncated]')));
        $this->assertTrue(Http::recorded()->every(fn (array $pair): bool => strlen($pair[0]->body()) <= 32768));
        $this->assertJson(Http::recorded()->last()[0]->body());
    }

    public function test_deep_wide_and_large_contexts_cannot_poison_an_otherwise_valid_batch(): void
    {
        $this->enableApplicationLogs();
        Http::fake(['https://latidoflow.test/api/v1/application-logs' => Http::response([], 201)]);
        $deep = ['one' => ['two' => ['three' => ['four' => ['five' => 'value']]]]];
        $wide = array_fill(0, 110, 'value');
        $large = array_fill(0, 10, str_repeat('x', 900));

        foreach ([$deep, $wide, $large] as $context) {
            Log::channel('latidoflow')->warning('Keep this warning.', $context);
        }

        Log::channel('latidoflow')->error('Preserve useful diagnosis.', ['attempt' => 3]);
        app(ApplicationLogBuffer::class)->flush();

        Http::assertSentCount(1);
        $logs = Http::recorded()->sole()[0]->data()['logs'];
        $this->assertCount(4, $logs);

        foreach (array_slice($logs, 0, 3) as $log) {
            $this->assertSame(['_latidoflow' => '[truncated-context]'], $log['context']);
            $this->assertSame('Keep this warning.', $log['message']);
        }

        $this->assertSame(['attempt' => 3], $logs[3]['context']);
    }

    public function test_http_termination_and_queue_exception_boundaries_flush_without_crossing_run_contexts(): void
    {
        $this->enableApplicationLogs();
        Http::fake(['https://latidoflow.test/api/v1/application-logs' => Http::response([], 201)]);
        $contexts = app(ExecutionContext::class);
        $firstRunUuid = (string) Str::uuid();
        $secondRunUuid = (string) Str::uuid();

        $contexts->push([
            'execution_id' => $firstRunUuid,
            'run_uuid' => $firstRunUuid,
        ]);
        Log::channel('latidoflow')->warning('First job warning.');
        $contexts->clear();
        $contexts->push([
            'execution_id' => $secondRunUuid,
            'run_uuid' => $secondRunUuid,
        ]);
        Log::channel('latidoflow')->error('Second job error.');

        Event::dispatch(new JobExceptionOccurred('database', new stdClass, new RuntimeException('job failed')));

        $contexts->clear();
        Log::channel('latidoflow')->warning('HTTP request warning.');
        $this->app->terminate();

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $jobLogs = $recorded[0][0]->data()['logs'];
        $requestLogs = $recorded[1][0]->data()['logs'];
        $this->assertSame($firstRunUuid, $jobLogs[0]['run_uuid']);
        $this->assertSame($secondRunUuid, $jobLogs[1]['run_uuid']);
        $this->assertArrayNotHasKey('run_uuid', $requestLogs[0]);
    }

    public function test_transport_failure_is_fail_open_and_drops_the_failed_batch(): void
    {
        $this->enableApplicationLogs();
        Exceptions::fake();
        $transportAttempts = 0;
        Http::fake(function () use (&$transportAttempts): never {
            $transportAttempts++;

            throw new ConnectionException('Private transport failure detail.');
        });
        $workloadCompleted = false;

        Log::channel('latidoflow')->warning('A warning before workload completion.');
        app(ApplicationLogBuffer::class)->flush();
        $workloadCompleted = true;

        $this->assertTrue($workloadCompleted);
        $this->assertSame(0, app(ApplicationLogBuffer::class)->count());
        $this->assertSame(1, $transportAttempts);
        Exceptions::assertReported(RuntimeException::class);
        Exceptions::assertReportedCount(1);
    }

    public function test_enabled_logs_are_ignored_for_legacy_custom_clients_without_breaking_lifecycle_calls(): void
    {
        $this->enableApplicationLogs();
        Exceptions::fake();
        Http::preventStrayRequests();

        $client = new LegacyLatidoFlowClient;
        $this->app->instance(LatidoFlowClient::class, $client);

        $logger = Log::channel('latidoflow');
        $this->assertInstanceOf(LatidoFlowApplicationLogHandler::class, $logger->getHandlers()[0]);
        $logger->warning('This legacy client cannot receive application logs.');
        app(ApplicationLogBuffer::class)->flush();

        $runUuid = (string) Str::uuid();

        $this->assertSame($runUuid, $client->start(
            ['monitor_uuid' => (string) Str::uuid()],
            ['run_uuid' => $runUuid],
        ));
        $this->assertCount(1, $client->lifecycleCalls);
        $this->assertSame('start', $client->lifecycleCalls[0]);
        $this->assertSame(0, app(ApplicationLogBuffer::class)->count());
        Exceptions::assertNothingReported();
        Http::assertNothingSent();
    }

    private function enableApplicationLogs(): void
    {
        config()->set('latidoflow.token', 'test-workspace-token');
        config()->set('latidoflow.endpoint', 'https://latidoflow.test');
        config()->set('latidoflow.project.slug', 'billing-app');
        config()->set('latidoflow.environment.slug', 'production');
        config()->set('latidoflow.application_logs.enabled', true);
        config()->set('latidoflow.application_logs.level', 'warning');
        config()->set('latidoflow.application_logs.source', 'laravel');
        config()->set('logging.channels.latidoflow', [
            'driver' => 'latidoflow',
            'level' => 'warning',
            'enabled' => true,
        ]);
        Log::forgetChannel('latidoflow');
    }
}

final class LegacyLatidoFlowClient implements LatidoFlowClient
{
    /** @var array<int, string> */
    public array $lifecycleCalls = [];

    public function sync(array $payload): Response
    {
        throw new RuntimeException('Not implemented by the legacy client.');
    }

    public function queued(array $reference, array $payload): void
    {
        $this->lifecycleCalls[] = 'queued';
    }

    public function start(array $reference, array $payload): string
    {
        $this->lifecycleCalls[] = 'start';

        return (string) $payload['run_uuid'];
    }

    public function skipped(array $reference, array $payload): void
    {
        $this->lifecycleCalls[] = 'skipped';
    }

    public function heartbeat(string $runUuid, array $payload): void
    {
        $this->lifecycleCalls[] = 'heartbeat';
    }

    public function success(string $runUuid, array $payload): void
    {
        $this->lifecycleCalls[] = 'success';
    }

    public function fail(string $runUuid, array $payload): void
    {
        $this->lifecycleCalls[] = 'fail';
    }
}
