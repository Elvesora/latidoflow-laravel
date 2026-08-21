<?php

namespace LatidoFlow\Laravel\Tests\Feature;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Console\RetryCommand;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LatidoFlow\Laravel\Commands\VerifyCommand;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use LatidoFlow\Laravel\Facades\LatidoFlow;
use LatidoFlow\Laravel\Runtime\ExecutionContext;
use LatidoFlow\Laravel\Runtime\HttpLatidoFlowClient;
use LatidoFlow\Laravel\Runtime\MonitorIdentity;
use LatidoFlow\Laravel\Runtime\OutputStore;
use LatidoFlow\Laravel\Runtime\QueueLifecycleReporter;
use LatidoFlow\Laravel\Runtime\SchedulerLifecycleReporter;
use LatidoFlow\Laravel\Tests\TestCase;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

class RuntimeReporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('latidoflow.token', 'lf_test_runtime');
        config()->set('latidoflow.endpoint', 'https://latidoflow.example.test');
        config()->set('latidoflow.http.sync.retry_delays_ms', [0]);
        config()->set('latidoflow.http.runtime.retry_delays_ms', []);
        config()->set('latidoflow.runtime.enabled', true);
        config()->set('latidoflow.runtime.cache_store', 'array');
        config()->set('latidoflow.project', ['name' => 'Example', 'slug' => 'example']);
        config()->set('latidoflow.environment', ['name' => 'Production', 'slug' => 'production']);
        config()->set('latidoflow.queues', [[
            'name' => 'Invoice exports',
            'connection' => 'redis',
            'queue' => 'billing',
            'job_class' => 'App\\Jobs\\ExportInvoices',
            'runtime_reporting' => true,
        ]]);
        Context::flush();
        Cache::store('array')->flush();
        Cache::store('file')->flush();
        Http::preventStrayRequests();
        LatidoFlow::clearResolvedInstance(OutputStore::class);
    }

    public function test_scheduler_starts_only_after_laravel_overlap_gate_and_reports_bounded_output(): void
    {
        [$client, $contexts, $outputs, $reporter] = $this->schedulerReporter();
        $schedule = new Schedule('UTC');
        $task = $schedule->command('reports:daily')->daily()->description('Daily reports');

        $reporter->starting(new ScheduledTaskStarting($task));
        $this->assertSame([], $client->calls);

        $task->callBeforeCallbacks($this->app);
        $this->assertSame('start', $client->calls[0]['type']);
        $this->assertTrue(Str::isUuid($client->calls[0]['payload']['run_uuid']));
        $this->assertSame('4', $client->calls[0]['payload']['run_uuid'][14]);
        $this->assertTrue(LatidoFlow::output(['records_processed' => 42]));
        $this->assertTrue(LatidoFlow::evidence([
            'report' => [
                'status' => 'complete',
                'records_processed' => 42,
                'had_warnings' => false,
                'completed_at' => '2026-08-08T12:00:00Z',
            ],
        ]));

        $task->exitCode = 0;
        $reporter->finished(new ScheduledTaskFinished($task, 1.25));

        $this->assertSame('success', $client->calls[1]['type']);
        $this->assertSame(['records_processed' => 42], $client->calls[1]['payload']['output']);
        $this->assertSame('complete', $client->calls[1]['payload']['evidence']['report']['status']);
        $this->assertFalse($client->calls[1]['payload']['evidence']['report']['had_warnings']);
        $this->assertNull($contexts->current());
        $this->assertSame([], $outputs->get($client->calls[0]['payload']['run_uuid']));
        $this->assertNull($outputs->getEvidence($client->calls[0]['payload']['run_uuid']));
    }

    public function test_scheduler_overlap_and_nonzero_exit_never_emit_false_success(): void
    {
        [$client, , , $reporter] = $this->schedulerReporter();
        $schedule = new Schedule('UTC');
        $overlap = $schedule->command('reports:overlap')->daily()->description('Overlap reports');

        $reporter->starting(new ScheduledTaskStarting($overlap));
        $reporter->finished(new ScheduledTaskFinished($overlap, 0.0));

        $this->assertSame(['skipped'], array_column($client->calls, 'type'));

        $failed = $schedule->command('reports:failed')->daily()->description('Failed reports');
        $reporter->starting(new ScheduledTaskStarting($failed));
        $failed->callBeforeCallbacks($this->app);
        $failed->exitCode = 1;
        $reporter->finished(new ScheduledTaskFinished($failed, 0.5));

        $this->assertNotContains('success', array_column($client->calls, 'type'));

        $reporter->failed(new ScheduledTaskFailed($failed, new RuntimeException('private command output')));

        $this->assertSame('fail', $client->calls[array_key_last($client->calls)]['type']);
        $this->assertStringNotContainsString(
            'private command output',
            $client->calls[array_key_last($client->calls)]['payload']['message'],
        );
    }

    public function test_scheduler_preserves_an_explicit_empty_evidence_document(): void
    {
        [$client, , , $reporter] = $this->schedulerReporter();
        $schedule = new Schedule('UTC');
        $task = $schedule->command('reports:empty')->daily()->description('Empty evidence report');

        $reporter->starting(new ScheduledTaskStarting($task));
        $task->callBeforeCallbacks($this->app);
        $this->assertTrue(LatidoFlow::evidence([]));
        $task->exitCode = 0;
        $reporter->finished(new ScheduledTaskFinished($task, 0.1));

        $this->assertArrayHasKey('evidence', $client->calls[1]['payload']);
        $this->assertSame([], $client->calls[1]['payload']['evidence']);
    }

    public function test_background_schedules_do_not_start_in_process_runtime_reporting(): void
    {
        [$client, $contexts, , $reporter] = $this->schedulerReporter();
        $schedule = new Schedule('UTC');
        $task = $schedule->command('reports:background')
            ->daily()
            ->description('Background reports')
            ->runInBackground();

        $reporter->starting(new ScheduledTaskStarting($task));
        $task->callBeforeCallbacks($this->app);
        $reporter->finished(new ScheduledTaskFinished($task, 0.01));

        $this->assertNull($contexts->current());
        $this->assertSame([], $client->calls);
    }

    public function test_scheduler_reports_failure_when_an_existing_before_callback_throws(): void
    {
        [$client, $contexts, , $reporter] = $this->schedulerReporter();
        $schedule = new Schedule('UTC');
        $task = $schedule->command('reports:before-failure')
            ->daily()
            ->description('Before callback failure')
            ->before(fn () => throw new RuntimeException('private callback failure'));
        $failure = null;

        $reporter->starting(new ScheduledTaskStarting($task));

        try {
            $task->callBeforeCallbacks($this->app);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $failure);
        $reporter->failed(new ScheduledTaskFailed($task, $failure));

        $this->assertSame(['start', 'fail'], array_column($client->calls, 'type'));
        $this->assertNull($contexts->current());
        $this->assertStringNotContainsString('private callback failure', data_get($client->calls[1], 'payload.message'));
    }

    public function test_queue_reporter_tracks_dispatch_processing_retry_success_and_clears_worker_output(): void
    {
        [$client, $contexts, $outputs, $reporter] = $this->queueReporter();
        $jobUuid = 'd1ab9734-31e7-49c8-b606-2ea24380834f';
        $payload = json_encode([
            'uuid' => $jobUuid,
            'displayName' => 'Customer-facing invoice export',
            'data' => [
                'commandName' => 'App\\Jobs\\ExportInvoices',
                'command' => 'serialized-private-payload',
            ],
        ], JSON_THROW_ON_ERROR);

        $reporter->queued(new JobQueued('redis', 'billing', 10, 'App\\Jobs\\ExportInvoices', $payload, 60));
        $job = $this->queueJob($jobUuid);
        $contexts->push(['kind' => 'queue', 'execution_id' => $jobUuid]);
        $this->assertTrue($outputs->output(['stale_from_previous_attempt' => 1]));
        $contexts->remove($jobUuid);
        $reporter->processing(new JobProcessing('redis', $job));
        $this->assertTrue(LatidoFlow::output(['invoices_exported' => 9]));
        $this->assertTrue(LatidoFlow::evidence([
            'export' => ['status' => 'complete', 'invoice_ids' => [10, 11, 12]],
        ]));
        $reporter->processed(new JobProcessed('redis', $job));

        $this->assertSame(['queued', 'start', 'success'], array_column($client->calls, 'type'));
        $this->assertSame(60, data_get($client->calls[0], 'payload.metadata.queue.delay_seconds'));
        $this->assertSame('App\\Jobs\\ExportInvoices', data_get($client->calls[0], 'payload.metadata.job_class'));
        $this->assertNotSame('Customer-facing invoice export', data_get($client->calls[0], 'payload.metadata.job_class'));
        $this->assertArrayNotHasKey('command', $client->calls[0]['payload']['metadata']);
        $this->assertSame(['invoices_exported' => 9], $client->calls[2]['payload']['output']);
        $this->assertArrayNotHasKey('stale_from_previous_attempt', $client->calls[2]['payload']['output']);
        $this->assertSame([10, 11, 12], $client->calls[2]['payload']['evidence']['export']['invoice_ids']);
        $this->assertNull($contexts->current());
        $this->assertSame([], $outputs->get($jobUuid));
        $this->assertNull($outputs->getEvidence($jobUuid));
        $this->assertFalse(LatidoFlow::output(['must_not_leak' => 1]));

        $retryUuid = 'b45c2953-ecf6-4aba-9f30-b2464f9f8e18';
        $retryJob = $this->queueJob($retryUuid, released: true, attempts: 2);
        $reporter->processing(new JobProcessing('redis', $retryJob));
        $reporter->releasedAfterException(new JobReleasedAfterException('redis', $retryJob, 30));
        $reporter->attempted(new JobAttempted('redis', $retryJob, new RuntimeException('retry')));

        $heartbeat = collect($client->calls)->last(fn (array $call): bool => $call['type'] === 'heartbeat');
        $this->assertSame('retrying', data_get($heartbeat, 'payload.metadata.state'));
        $this->assertSame(30, data_get($heartbeat, 'payload.metadata.backoff_seconds'));
        $this->assertNull($contexts->current());
    }

    public function test_queue_timeout_reports_retry_state_and_clears_attempt_output(): void
    {
        [$client, $contexts, $outputs, $reporter] = $this->queueReporter();
        $jobUuid = '68d91978-321a-4afd-a654-3831434a1ef6';
        $job = $this->queueJob($jobUuid);

        $reporter->processing(new JobProcessing('redis', $job));
        $this->assertTrue($outputs->output(['partial_records' => 3]));
        $reporter->timedOut(new JobTimedOut('redis', $job, 30));

        $this->assertSame(['start', 'heartbeat'], array_column($client->calls, 'type'));
        $this->assertSame('timed_out', data_get($client->calls[1], 'payload.metadata.state'));
        $this->assertNull($contexts->current());
        $this->assertSame([], $outputs->get($jobUuid));
    }

    public function test_async_queue_processing_does_not_inherit_a_parent_execution_context(): void
    {
        [$client, $contexts, , $reporter] = $this->queueReporter();
        $contexts->push([
            'kind' => 'queue',
            'execution_id' => 'parent-execution',
        ]);
        $untrackedJob = $this->queueJob(
            'ca545b45-d434-4826-9c83-58a5fc6b6d69',
            jobClass: 'App\\Jobs\\UntrackedJob',
        );

        $reporter->processing(new JobProcessing('redis', $untrackedJob));

        $this->assertNull($contexts->current());
        $this->assertFalse(LatidoFlow::output(['must_not_reach_parent' => 1]));
        $this->assertSame([], $client->calls);

        $contexts->push([
            'kind' => 'queue',
            'execution_id' => 'second-parent-execution',
        ]);
        $trackedJob = $this->queueJob('4b558294-4775-4aa8-90dc-740ea0ef75e4');
        $reporter->processing(new JobProcessing('redis', $trackedJob));
        $this->assertSame($trackedJob->uuid(), data_get($contexts->current(), 'execution_id'));
        $reporter->processed(new JobProcessed('redis', $trackedJob));
        $this->assertNull($contexts->current());
    }

    public function test_sync_queue_processing_preserves_the_outer_execution_context(): void
    {
        [, $contexts, , $reporter] = $this->queueReporter();
        $parent = [
            'kind' => 'queue',
            'execution_id' => 'synchronous-parent-execution',
        ];
        $contexts->push($parent);
        $untrackedJob = $this->queueJob(
            'a13a9345-8668-4b16-bfbc-ad3f9ac1b738',
            jobClass: 'App\\Jobs\\UntrackedJob',
        );

        $reporter->processing(new JobProcessing('sync', $untrackedJob));

        $this->assertSame($parent, $contexts->current());
    }

    public function test_service_provider_clears_laravel_hydrated_context_before_async_job_execution(): void
    {
        $contexts = $this->app->make(ExecutionContext::class);
        $contexts->push([
            'kind' => 'queue',
            'execution_id' => 'hydrated-parent-execution',
        ]);
        $hydratedContext = Context::dehydrate();
        Context::flush();
        $job = $this->queueJob(
            '3b8205e6-660d-4911-9416-b70946b423b4',
            payload: [
                'uuid' => '3b8205e6-660d-4911-9416-b70946b423b4',
                'illuminate:log:context' => $hydratedContext,
            ],
            jobClass: 'App\\Jobs\\UntrackedJob',
        );

        $this->app->make('events')->dispatch(new JobProcessing('redis', $job));

        $this->assertNull($contexts->current());
    }

    public function test_manual_queue_retry_receives_a_new_run_identity(): void
    {
        [$client, , , $reporter] = $this->queueReporter();
        $originalUuid = '140f9f3d-87e7-4b36-960f-864806671432';
        $failedJob = (object) [
            'connection' => 'redis',
            'queue' => 'billing',
            'payload' => json_encode([
                'uuid' => $originalUuid,
                'data' => ['commandName' => 'App\\Jobs\\ExportInvoices'],
            ], JSON_THROW_ON_ERROR),
        ];

        $reporter->retryRequested(new JobRetryRequested($failedJob));
        $retriedPayload = json_decode($failedJob->payload, true, flags: JSON_THROW_ON_ERROR);
        $retriedRunUuid = $retriedPayload['latidoflow_run_uuid'] ?? null;

        $this->assertTrue(Str::isUuid($retriedRunUuid));
        $this->assertNotSame($originalUuid, $retriedRunUuid);

        $job = $this->queueJob($originalUuid, payload: $retriedPayload);
        $reporter->processing(new JobProcessing('redis', $job));
        $reporter->processed(new JobProcessed('redis', $job));

        $this->assertSame($retriedRunUuid, data_get($client->calls[0], 'payload.run_uuid'));
        $this->assertSame($retriedRunUuid, data_get($client->calls[1], 'reference.run_uuid'));
    }

    public function test_laravel_queue_retry_command_pushes_a_fresh_monitoring_identity(): void
    {
        $originalUuid = '2e96d975-6112-491b-b40e-c97cbeea95f5';
        $failedJob = (object) [
            'id' => 'failed-1',
            'connection' => 'redis',
            'queue' => 'billing',
            'payload' => json_encode([
                'uuid' => $originalUuid,
                'attempts' => 3,
                'data' => ['commandName' => 'App\\Jobs\\ExportInvoices'],
            ], JSON_THROW_ON_ERROR),
        ];
        $failedProvider = Mockery::mock(FailedJobProviderInterface::class);
        $failedProvider->shouldReceive('find')->once()->with('failed-1')->andReturn($failedJob);
        $failedProvider->shouldReceive('forget')->once()->with('failed-1')->andReturnTrue();
        $connection = new RecordingRawQueueConnection;
        $queueManager = Mockery::mock();
        $queueManager->shouldReceive('connection')->twice()->with('redis')->andReturn($connection);
        $this->app->instance('queue.failer', $failedProvider);
        $this->app->instance('queue', $queueManager);
        $command = new RetryCommand;
        $command->setLaravel($this->app);

        $exitCode = (new CommandTester($command))->execute(['id' => ['failed-1']]);

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $connection->payloads);
        $retriedPayload = json_decode($connection->payloads[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($originalUuid, $retriedPayload['uuid']);
        $this->assertSame(0, $retriedPayload['attempts']);
        $this->assertTrue(Str::isUuid($retriedPayload['latidoflow_run_uuid'] ?? null));
        $this->assertNotSame($originalUuid, $retriedPayload['latidoflow_run_uuid']);
    }

    public function test_queue_failure_sends_exception_class_without_exception_text(): void
    {
        [$client, , , $reporter] = $this->queueReporter();
        $job = $this->queueJob('0d70b7cc-2bc8-471e-94ef-c4827e2737e0', failed: true);

        $reporter->processing(new JobProcessing('redis', $job));
        $reporter->failed(new JobFailed('redis', $job, new RuntimeException('secret=customer-token')));

        $failure = collect($client->calls)->last();
        $this->assertSame('fail', $failure['type']);
        $this->assertStringContainsString('RuntimeException', $failure['payload']['message']);
        $this->assertStringNotContainsString('customer-token', $failure['payload']['message']);
    }

    public function test_output_helper_rejects_invalid_metrics_and_transport_failures_remain_fail_open(): void
    {
        [$client, , , $reporter] = $this->schedulerReporter();
        $schedule = new Schedule('UTC');
        $task = $schedule->command('reports:daily')->daily()->description('Daily reports');
        $client->throw = true;

        $reporter->starting(new ScheduledTaskStarting($task));
        $task->callBeforeCallbacks($this->app);

        $this->assertFalse(LatidoFlow::output(['records_processed' => '42']));
        $this->assertFalse(LatidoFlow::output(array_fill(0, 21, 1)));

        $task->exitCode = 0;
        $reporter->finished(new ScheduledTaskFinished($task, 0.1));
        $this->assertNull(app(ExecutionContext::class)->current());
    }

    public function test_semantic_evidence_is_typed_bounded_and_does_not_change_numeric_output(): void
    {
        $contexts = new ExecutionContext;
        $contexts->push(['execution_id' => 'runtime-typed-evidence']);
        $outputs = new OutputStore($contexts);
        $this->app->instance(OutputStore::class, $outputs);
        LatidoFlow::clearResolvedInstance(OutputStore::class);
        $firstEvidence = [
            'report' => [
                'status' => 'complete',
                'records_processed' => 42,
                'had_warnings' => false,
                'failure_reason' => null,
            ],
        ];

        $this->assertTrue(LatidoFlow::output(['records_processed' => 42]));
        $this->assertTrue(LatidoFlow::evidence($firstEvidence));
        $this->assertSame($firstEvidence, $outputs->getEvidence('runtime-typed-evidence'));

        $this->assertFalse(LatidoFlow::evidence(['invalid' => INF]));
        $this->assertFalse(LatidoFlow::evidence(['oversized' => str_repeat('a', 2049)]));
        $this->assertFalse(LatidoFlow::evidence(array_fill(0, 64, true)));
        $this->assertFalse(LatidoFlow::evidence(['invalid' => new \stdClass]));

        $this->assertSame($firstEvidence, $outputs->getEvidence('runtime-typed-evidence'));
        $this->assertSame(['records_processed' => 42], $outputs->get('runtime-typed-evidence'));

        $replacement = ['report' => ['status' => 'superseded']];
        $this->assertTrue(LatidoFlow::evidence($replacement));
        $this->assertSame($replacement, $outputs->getEvidence('runtime-typed-evidence'));
    }

    public function test_scheduler_transport_failure_is_reported_once_and_remains_fail_open(): void
    {
        Exceptions::fake();
        [$client, , , $reporter] = $this->schedulerReporter();
        $client->throw = true;
        $schedule = new Schedule('UTC');
        $task = $schedule->command('reports:daily')->daily()->description('Daily reports');

        $reporter->skipped(new ScheduledTaskSkipped($task));

        $this->assertSame(['skipped'], array_column($client->calls, 'type'));
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage() === 'Simulated transport failure with private response.',
        );
        Exceptions::assertReportedCount(1);
    }

    public function test_queue_transport_failure_is_reported_once_and_remains_fail_open(): void
    {
        Exceptions::fake();
        [$client, , , $reporter] = $this->queueReporter();
        $client->throw = true;
        $jobUuid = '0f4df484-0362-41e8-8824-38828b46ea6a';
        $payload = json_encode([
            'uuid' => $jobUuid,
            'displayName' => 'Customer-facing invoice export',
            'data' => [
                'commandName' => 'App\\Jobs\\ExportInvoices',
                'command' => 'serialized-private-payload',
            ],
        ], JSON_THROW_ON_ERROR);

        $reporter->queued(new JobQueued('redis', 'billing', 10, 'App\\Jobs\\ExportInvoices', $payload, 60));

        $this->assertSame(['queued'], array_column($client->calls, 'type'));
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage() === 'Simulated transport failure with private response.',
        );
        Exceptions::assertReportedCount(1);
    }

    public function test_output_cache_failure_is_reported_once_and_remains_fail_open(): void
    {
        Exceptions::fake();
        config()->set('latidoflow.runtime.cache_store', 'missing');
        $contexts = new ExecutionContext;
        $contexts->push(['execution_id' => 'runtime-output-cache-failure']);
        $outputs = new OutputStore($contexts);

        $this->assertFalse($outputs->output(['records_processed' => 42]));

        Exceptions::assertReported(
            fn (InvalidArgumentException $exception): bool => str_contains($exception->getMessage(), 'Cache store [missing] is not defined.'),
        );
        Exceptions::assertReportedCount(1);
    }

    public function test_semantic_evidence_cache_failure_is_reported_once_and_remains_fail_open(): void
    {
        Exceptions::fake();
        config()->set('latidoflow.runtime.cache_store', 'missing');
        $contexts = new ExecutionContext;
        $contexts->push(['execution_id' => 'runtime-evidence-cache-failure']);
        $outputs = new OutputStore($contexts);

        $this->assertFalse($outputs->evidence(['report' => ['status' => 'complete']]));

        Exceptions::assertReported(
            fn (InvalidArgumentException $exception): bool => str_contains($exception->getMessage(), 'Cache store [missing] is not defined.'),
        );
        Exceptions::assertReportedCount(1);
    }

    public function test_scheduled_output_rejects_a_process_local_cache_store(): void
    {
        Exceptions::fake();
        config()->set('latidoflow.runtime.cache_store', 'array');
        $contexts = new ExecutionContext;
        $contexts->push([
            'kind' => 'schedule',
            'execution_id' => 'runtime-process-local-cache',
        ]);
        $outputs = new OutputStore($contexts);

        $this->assertFalse($outputs->output(['records_processed' => 42]));
        $this->assertFalse($outputs->evidence(['report' => ['status' => 'complete']]));
        Exceptions::assertNothingReported();
    }

    public function test_output_cache_read_failure_does_not_suppress_terminal_success(): void
    {
        Exceptions::fake();
        [$client, , , $reporter] = $this->schedulerReporter();
        config()->set('latidoflow.runtime.cache_store', 'missing');
        $schedule = new Schedule('UTC');
        $task = $schedule->command('reports:daily')->daily()->description('Daily reports');

        $reporter->starting(new ScheduledTaskStarting($task));
        $task->callBeforeCallbacks($this->app);
        $task->exitCode = 0;
        $reporter->finished(new ScheduledTaskFinished($task, 0.1));

        $this->assertSame(['start', 'success'], array_column($client->calls, 'type'));
        $this->assertSame([], $client->calls[1]['payload']['output']);
        $this->assertArrayNotHasKey('evidence', $client->calls[1]['payload']);
        Exceptions::assertReportedCount(1);
    }

    public function test_cleanup_retries_after_a_transient_cache_read_failure(): void
    {
        Exceptions::fake();
        $executionId = 'runtime-output-transient-read-failure';
        $contexts = new ExecutionContext;
        $contexts->push(['execution_id' => $executionId]);
        $outputs = new OutputStore($contexts);

        $this->assertTrue($outputs->output(['records_processed' => 42]));
        $this->assertTrue($outputs->evidence(['report' => ['status' => 'complete']]));

        config()->set('latidoflow.runtime.cache_store', 'missing');
        $this->assertSame([], $outputs->get($executionId));

        config()->set('latidoflow.runtime.cache_store', 'array');
        $outputs->forget($executionId);

        $keyHash = hash('sha256', $executionId);
        $this->assertFalse(Cache::store('array')->has('latidoflow:runtime-output:'.$keyHash));
        $this->assertFalse(Cache::store('array')->has('latidoflow:runtime-evidence:'.$keyHash));
        Exceptions::assertReportedCount(1);
    }

    public function test_output_cache_cleanup_failure_is_reported_once_and_remains_fail_open(): void
    {
        Exceptions::fake();
        config()->set('latidoflow.runtime.cache_store', 'missing');
        $outputs = new OutputStore(new ExecutionContext);

        $outputs->forget('runtime-output-cache-cleanup');

        Exceptions::assertReported(
            fn (InvalidArgumentException $exception): bool => str_contains($exception->getMessage(), 'Cache store [missing] is not defined.'),
        );
        Exceptions::assertReportedCount(1);
    }

    public function test_invalid_output_metrics_are_expected_and_not_reported(): void
    {
        Exceptions::fake();
        $contexts = new ExecutionContext;
        $contexts->push(['execution_id' => 'runtime-output-invalid-metrics']);
        $outputs = new OutputStore($contexts);

        $this->assertFalse($outputs->output(['records_processed' => '42']));

        Exceptions::assertNothingReported();
    }

    public function test_http_client_retries_sync_but_runtime_failures_do_not_expose_response_body(): void
    {
        Http::fakeSequence()
            ->push(['error' => 'private upstream detail'], 503)
            ->push([
                'project_uuid' => 'ad469be1-8131-4d03-8d22-d8384aec5605',
                'environment_uuid' => '55f96f65-ee75-439c-8e28-dd215b2472fb',
                'monitors' => [[
                    'uuid' => 'a6b771c2-13d5-47ad-93ec-35626222da24',
                    'slug' => 'daily-reports',
                    'type' => 'heartbeat',
                ]],
            ], 200)
            ->push(['error' => 'do-not-log-this-body'], 422);
        $client = new HttpLatidoFlowClient;

        $response = $client->sync(['monitors' => []]);

        $this->assertTrue($response->successful());
        Http::assertSentCount(2);

        try {
            $client->skipped([
                'project_slug' => 'example',
                'environment_slug' => 'production',
                'monitor_slug' => 'daily-reports',
            ], ['idempotency_key' => 'laravel-schedule:invalid']);
            $this->fail('A rejected runtime request must be observable to the reporter.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 422', $exception->getMessage());
            $this->assertStringNotContainsString('do-not-log-this-body', $exception->getMessage());
        }
    }

    public function test_verification_http_rejection_is_expected_and_not_reported(): void
    {
        Exceptions::fake();
        Http::fake([
            'https://latidoflow.example.test/api/v1/monitors/sync' => Http::response([
                'message' => 'Rejected private response.',
            ], 401),
        ]);
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->description('Daily reports');
        $this->app->instance(Schedule::class, $schedule);
        $this->app->instance(LatidoFlowClient::class, new HttpLatidoFlowClient);
        $command = new VerifyCommand;
        $command->setLaravel($this->app);

        $exitCode = (new CommandTester($command))->execute([]);

        $this->assertSame(1, $exitCode);
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'monitors.0.slug') === 'daily-reports');
        Exceptions::assertNothingReported();
    }

    public function test_service_provider_registers_scheduler_and_queue_runtime_listeners(): void
    {
        $events = $this->app->make('events');

        $this->assertTrue($events->hasListeners(ScheduledTaskStarting::class));
        $this->assertFalse($events->hasListeners(ScheduledBackgroundTaskFinished::class));
        $this->assertTrue($events->hasListeners(JobProcessing::class));
        $this->assertTrue($events->hasListeners(JobAttempted::class));
        $this->assertTrue($events->hasListeners(JobRetryRequested::class));
        $this->assertTrue($events->hasListeners(JobTimedOut::class));
    }

    /**
     * @return array{0: RecordingLatidoFlowClient, 1: ExecutionContext, 2: OutputStore, 3: SchedulerLifecycleReporter}
     */
    private function schedulerReporter(): array
    {
        config()->set('latidoflow.runtime.cache_store', 'file');
        $client = new RecordingLatidoFlowClient;
        $contexts = new ExecutionContext;
        $outputs = new OutputStore($contexts);
        $this->app->instance(ExecutionContext::class, $contexts);
        $this->app->instance(OutputStore::class, $outputs);
        LatidoFlow::clearResolvedInstance(OutputStore::class);

        return [
            $client,
            $contexts,
            $outputs,
            new SchedulerLifecycleReporter($client, new MonitorIdentity, $contexts, $outputs),
        ];
    }

    /**
     * @return array{0: RecordingLatidoFlowClient, 1: ExecutionContext, 2: OutputStore, 3: QueueLifecycleReporter}
     */
    private function queueReporter(): array
    {
        $client = new RecordingLatidoFlowClient;
        $contexts = new ExecutionContext;
        $outputs = new OutputStore($contexts);
        $this->app->instance(ExecutionContext::class, $contexts);
        $this->app->instance(OutputStore::class, $outputs);
        LatidoFlow::clearResolvedInstance(OutputStore::class);

        return [
            $client,
            $contexts,
            $outputs,
            new QueueLifecycleReporter($client, new MonitorIdentity, $contexts, $outputs),
        ];
    }

    private function queueJob(
        string $uuid,
        bool $released = false,
        bool $failed = false,
        int $attempts = 1,
        ?array $payload = null,
        string $jobClass = 'App\\Jobs\\ExportInvoices',
    ): Job {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldReceive('resolveQueuedJobClass')->andReturn($jobClass);
        $job->shouldReceive('getQueue')->andReturn('billing');
        $job->shouldReceive('attempts')->andReturn($attempts);
        $job->shouldReceive('payload')->andReturn($payload ?? ['uuid' => $uuid]);
        $job->shouldReceive('isReleased')->andReturn($released);
        $job->shouldReceive('hasFailed')->andReturn($failed);

        return $job;
    }
}

final class RecordingLatidoFlowClient implements LatidoFlowClient
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public bool $throw = false;

    public function sync(array $payload): Response
    {
        throw new RuntimeException('Not implemented by the recording client.');
    }

    public function queued(array $reference, array $payload): void
    {
        $this->record('queued', $reference, $payload);
    }

    public function start(array $reference, array $payload): string
    {
        $this->record('start', $reference, $payload);

        return (string) $payload['run_uuid'];
    }

    public function skipped(array $reference, array $payload): void
    {
        $this->record('skipped', $reference, $payload);
    }

    public function heartbeat(string $runUuid, array $payload): void
    {
        $this->record('heartbeat', ['run_uuid' => $runUuid], $payload);
    }

    public function success(string $runUuid, array $payload): void
    {
        $this->record('success', ['run_uuid' => $runUuid], $payload);
    }

    public function fail(string $runUuid, array $payload): void
    {
        $this->record('fail', ['run_uuid' => $runUuid], $payload);
    }

    /**
     * @param  array<string, mixed>  $reference
     * @param  array<string, mixed>  $payload
     */
    private function record(string $type, array $reference, array $payload): void
    {
        $this->calls[] = compact('type', 'reference', 'payload');

        if ($this->throw) {
            throw new RuntimeException('Simulated transport failure with private response.');
        }
    }
}

final class RecordingRawQueueConnection
{
    /** @var array<int, string> */
    public array $payloads = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function pushRaw(string $payload, string $queue, array $options = []): string
    {
        $this->payloads[] = $payload;

        return 'queued-job-id';
    }
}
