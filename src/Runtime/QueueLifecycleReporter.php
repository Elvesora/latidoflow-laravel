<?php

namespace LatidoFlow\Laravel\Runtime;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use Throwable;

final class QueueLifecycleReporter
{
    private const string RUN_UUID_PAYLOAD_KEY = 'latidoflow_run_uuid';

    public function __construct(
        private readonly LatidoFlowClient $client,
        private readonly MonitorIdentity $identities,
        private readonly ExecutionContext $contexts,
        private readonly OutputStore $outputs,
    ) {}

    public function queued(JobQueued $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->safely(function () use ($event): void {
            $payload = $event->payload();
            $jobClass = is_string(data_get($payload, 'data.commandName'))
                ? data_get($payload, 'data.commandName')
                : '';
            $jobUuid = is_string($payload['uuid'] ?? null) ? $payload['uuid'] : '';
            $definition = $this->identities->matchingQueue(
                (string) $event->connectionName,
                $event->queue,
                $jobClass,
            );

            if (! is_array($definition) || ! Str::isUuid($jobUuid)) {
                return;
            }

            $identity = $this->identities->queue($definition);
            $idempotencyKey = 'laravel-queue:'.$jobUuid;
            $this->client->queued($this->identities->reference($identity['slug']), [
                'run_uuid' => $jobUuid,
                'idempotency_key' => $idempotencyKey,
                'event_idempotency_key' => $idempotencyKey.':queued',
                'source' => 'laravel_queue',
                'queue_connection' => (string) $event->connectionName,
                'queue_name' => $event->queue ?: 'default',
                'occurred_at' => now()->toIso8601String(),
                'metadata' => [
                    'job_class' => $jobClass,
                    'queue' => [
                        'delay_seconds' => max(0, (int) ($event->delay ?? 0)),
                    ],
                ],
            ]);
        });
    }

    public function processing(JobProcessing $event): void
    {
        if (! $this->isSynchronousConnection($event->connectionName)) {
            $this->contexts->clear();
        }

        if (! $this->enabled()) {
            return;
        }

        $execution = $this->execution($event->connectionName, $event->job);

        if (! is_array($execution)) {
            return;
        }

        $this->outputs->forget($execution['execution_id']);

        $this->safely(function () use (&$execution): void {
            $execution['run_uuid'] = $this->client->start(
                $execution['reference'],
                $this->startPayload($execution),
            );
            $execution['start_reported'] = true;
        });

        $this->contexts->push($execution);
    }

    public function processed(JobProcessed $event): void
    {
        $execution = $this->executionForJob($event->job);

        if (! is_array($execution)) {
            return;
        }

        if ($event->job->hasFailed()) {
            $this->cleanup($execution);

            return;
        }

        if ($event->job->isReleased()) {
            $this->heartbeat($execution, 'released');
            $this->cleanup($execution);

            return;
        }

        $this->safely(function () use ($execution): void {
            $runUuid = $this->ensureStarted($execution);
            $payload = [
                'event_idempotency_key' => $execution['idempotency_key'].':success',
                'source' => 'laravel_queue',
                'occurred_at' => now()->toIso8601String(),
                'exit_code' => 0,
                'output' => $this->outputs->get($execution['execution_id']),
                'metadata' => [
                    'job_class' => $execution['job_class'],
                    'attempt' => $execution['attempt'],
                ],
            ];
            $evidence = $this->outputs->getEvidence($execution['execution_id']);

            if ($evidence !== null) {
                $payload['evidence'] = $evidence;
            }

            $this->client->success($runUuid, $payload);
        });

        $this->cleanup($execution);
    }

    public function failed(JobFailed $event): void
    {
        $execution = $this->executionForJob($event->job)
            ?? $this->execution($event->connectionName, $event->job);

        if (! is_array($execution)) {
            return;
        }

        $this->safely(function () use ($execution, $event): void {
            $runUuid = $this->ensureStarted($execution);
            $this->client->fail($runUuid, [
                'event_idempotency_key' => $execution['idempotency_key'].':failed',
                'source' => 'laravel_queue',
                'occurred_at' => now()->toIso8601String(),
                'message' => 'Laravel queue job failed: '.class_basename($event->exception),
                'metadata' => [
                    'job_class' => $execution['job_class'],
                    'attempt' => $event->job->attempts(),
                ],
            ]);
        });

        $this->cleanup($execution);
    }

    public function releasedAfterException(JobReleasedAfterException $event): void
    {
        $execution = $this->executionForJob($event->job);

        if (! is_array($execution) || $event->job->hasFailed()) {
            return;
        }

        $this->heartbeat($execution, 'retrying', max(0, (int) ($event->backoff ?? 0)));
        $this->outputs->forget($execution['execution_id']);
    }

    public function attempted(JobAttempted $event): void
    {
        $execution = $this->executionForJob($event->job);

        if (is_array($execution)) {
            $this->cleanup($execution);
        }
    }

    public function retryRequested(JobRetryRequested $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->safely(function () use ($event): void {
            $payload = $event->payload();
            $jobClass = is_string(data_get($payload, 'data.commandName'))
                ? data_get($payload, 'data.commandName')
                : '';
            $connection = is_string($event->job->connection ?? null)
                ? $event->job->connection
                : '';
            $queue = is_string($event->job->queue ?? null)
                ? $event->job->queue
                : 'default';

            if (! is_array($payload)
                || ! is_array($this->identities->matchingQueue($connection, $queue, $jobClass))) {
                return;
            }

            $payload[self::RUN_UUID_PAYLOAD_KEY] = $this->identities->freshRunUuid();
            $event->job->payload = json_encode($payload, JSON_THROW_ON_ERROR);
        });
    }

    public function timedOut(JobTimedOut $event): void
    {
        $execution = $this->executionForJob($event->job);

        if (! is_array($execution)) {
            return;
        }

        $this->heartbeat($execution, 'timed_out');
        $this->cleanup($execution);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function execution(string $connection, Job $job): ?array
    {
        $jobUuid = $job->uuid();
        $payload = $job->payload();
        $requestedRunUuid = is_array($payload) && is_string($payload[self::RUN_UUID_PAYLOAD_KEY] ?? null)
            ? $payload[self::RUN_UUID_PAYLOAD_KEY]
            : null;
        $runUuid = Str::isUuid($requestedRunUuid) ? $requestedRunUuid : $jobUuid;
        $jobClass = $job->resolveQueuedJobClass();
        $queue = $job->getQueue() ?: 'default';

        if (! is_string($jobUuid) || ! Str::isUuid($runUuid) || ! is_string($jobClass)) {
            return null;
        }

        $definition = $this->identities->matchingQueue($connection, $queue, $jobClass);

        if (! is_array($definition)) {
            return null;
        }

        $identity = $this->identities->queue($definition);

        return [
            'kind' => 'queue',
            'execution_id' => $runUuid,
            'run_uuid' => $runUuid,
            'start_reported' => false,
            'idempotency_key' => 'laravel-queue:'.$runUuid,
            'started_at' => now()->toIso8601String(),
            'reference' => $this->identities->reference($identity['slug']),
            'job_class' => $jobClass,
            'connection' => $connection,
            'queue' => $queue,
            'attempt' => $job->attempts(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function executionForJob(Job $job): ?array
    {
        $execution = $this->contexts->current();

        if (! is_array($execution) || ($execution['kind'] ?? null) !== 'queue') {
            return null;
        }

        $payload = $job->payload();
        $requestedRunUuid = is_array($payload) && is_string($payload[self::RUN_UUID_PAYLOAD_KEY] ?? null)
            ? $payload[self::RUN_UUID_PAYLOAD_KEY]
            : null;
        $runUuid = Str::isUuid($requestedRunUuid) ? $requestedRunUuid : $job->uuid();

        return ($execution['execution_id'] ?? null) === $runUuid ? $execution : null;
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    private function heartbeat(array $execution, string $state, ?int $backoffSeconds = null): void
    {
        $this->safely(function () use ($execution, $state, $backoffSeconds): void {
            $runUuid = $this->ensureStarted($execution);
            $this->client->heartbeat($runUuid, [
                'event_idempotency_key' => $execution['idempotency_key'].':'.$state.':'.$execution['attempt'],
                'source' => 'laravel_queue',
                'occurred_at' => now()->toIso8601String(),
                'metadata' => array_filter([
                    'job_class' => $execution['job_class'],
                    'attempt' => $execution['attempt'],
                    'state' => $state,
                    'backoff_seconds' => $backoffSeconds,
                ], fn (mixed $value): bool => $value !== null),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed>
     */
    private function startPayload(array $execution): array
    {
        return [
            'run_uuid' => $execution['run_uuid'],
            'idempotency_key' => $execution['idempotency_key'],
            'event_idempotency_key' => $execution['idempotency_key'].':attempt:'.$execution['attempt'],
            'source' => 'laravel_queue',
            'queue_connection' => $execution['connection'],
            'queue_name' => $execution['queue'],
            'occurred_at' => $execution['started_at'],
            'metadata' => [
                'job_class' => $execution['job_class'],
                'attempt' => $execution['attempt'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    private function ensureStarted(array $execution): string
    {
        if (($execution['start_reported'] ?? false) === true
            && is_string($execution['run_uuid'] ?? null)
            && $execution['run_uuid'] !== '') {
            return $execution['run_uuid'];
        }

        return $this->client->start($execution['reference'], $this->startPayload($execution));
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    private function cleanup(array $execution): void
    {
        $this->outputs->forget($execution['execution_id']);
        $this->contexts->remove($execution['execution_id']);
    }

    private function enabled(): bool
    {
        return (bool) config('latidoflow.runtime.enabled', true)
            && filled(config('latidoflow.token'));
    }

    private function isSynchronousConnection(string $connection): bool
    {
        return $connection === 'sync'
            || config("queue.connections.{$connection}.driver") === 'sync';
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('LatidoFlow queue runtime event was not reported.', [
                'exception_class' => $exception::class,
            ]);
        }
    }
}
