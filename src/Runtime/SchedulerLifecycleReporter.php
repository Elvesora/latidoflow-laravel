<?php

namespace LatidoFlow\Laravel\Runtime;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use Throwable;
use WeakMap;

final class SchedulerLifecycleReporter
{
    /** @var WeakMap<Event, bool> */
    private WeakMap $registered;

    /** @var WeakMap<Event, array<string, mixed>> */
    private WeakMap $pending;

    /** @var WeakMap<Event, array<string, mixed>> */
    private WeakMap $active;

    public function __construct(
        private readonly LatidoFlowClient $client,
        private readonly MonitorIdentity $identities,
        private readonly ExecutionContext $contexts,
        private readonly OutputStore $outputs,
    ) {
        $this->registered = new WeakMap;
        $this->pending = new WeakMap;
        $this->active = new WeakMap;
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        $identity = $this->identities->scheduled($event->task);

        if (! $identity['automatic'] || ! $this->enabled()) {
            return;
        }

        $this->pending[$event->task] = $this->execution($identity);

        if (isset($this->registered[$event->task])) {
            return;
        }

        $this->registered[$event->task] = true;
        $event->task->before(fn () => $this->begin($event->task));
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $identity = $this->identities->scheduled($event->task);

        if (! $identity['automatic'] || ! $this->enabled()) {
            return;
        }

        $execution = $this->execution($identity);
        $this->safely(fn () => $this->client->skipped(
            $execution['reference'],
            $this->skipPayload($execution, 'Laravel schedule filters or pause state skipped this occurrence.'),
        ));
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $execution = $this->active[$event->task] ?? null;

        if (! is_array($execution)) {
            $pendingExecution = $this->pending[$event->task] ?? null;

            if (is_array($pendingExecution)) {
                $this->safely(fn () => $this->client->skipped(
                    $pendingExecution['reference'],
                    $this->skipPayload($pendingExecution, 'Laravel withoutOverlapping skipped this occurrence.'),
                ));
            }

            unset($this->pending[$event->task], $this->active[$event->task]);

            return;
        }

        if ($event->task->exitCode !== 0) {
            return;
        }

        $this->complete($execution, true, 0);
        unset($this->pending[$event->task], $this->active[$event->task]);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $execution = $this->active[$event->task]
            ?? $this->pending[$event->task]
            ?? null;

        if (! is_array($execution)) {
            return;
        }

        $this->complete($execution, false, $event->task->exitCode, $event->exception);
        unset($this->pending[$event->task], $this->active[$event->task]);
    }

    private function begin(Event $task): void
    {
        $execution = $this->pending[$task] ?? null;

        if (! is_array($execution)) {
            return;
        }

        $this->safely(function () use (&$execution): void {
            $execution['run_uuid'] = $this->client->start(
                $execution['reference'],
                $this->startPayload($execution),
            );
            $execution['start_reported'] = true;
        });

        $this->pending[$task] = $execution;
        $this->active[$task] = $execution;
        $this->contexts->push($execution);
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    private function complete(
        array $execution,
        bool $successful,
        ?int $exitCode,
        ?Throwable $exception = null,
    ): void {
        $this->safely(function () use (&$execution, $successful, $exitCode, $exception): void {
            $execution['run_uuid'] = $this->ensureStarted($execution);
            $payload = [
                'event_idempotency_key' => $execution['idempotency_key'].($successful ? ':success' : ':failed'),
                'source' => 'laravel_scheduler',
                'occurred_at' => now()->toIso8601String(),
                'exit_code' => $exitCode,
                'metadata' => [
                    'run_in_background' => data_get($execution, 'identity.metadata.run_in_background', false),
                ],
            ];

            if ($successful) {
                $payload['output'] = $this->outputs->get($execution['execution_id']);
                $evidence = $this->outputs->getEvidence($execution['execution_id']);

                if ($evidence !== null) {
                    $payload['evidence'] = $evidence;
                }

                $this->client->success($execution['run_uuid'], $payload);

                return;
            }

            $payload['message'] = 'Laravel scheduled task failed'.($exception ? ': '.class_basename($exception) : '.');
            $this->client->fail($execution['run_uuid'], $payload);
        });

        $this->outputs->forget($execution['execution_id']);
        $this->contexts->remove($execution['execution_id']);
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
     * @param  array{name: string, slug: string, automatic: bool, metadata: array<string, mixed>}  $identity
     * @return array<string, mixed>
     */
    private function execution(array $identity): array
    {
        $executionId = $this->identities->freshRunUuid();

        return [
            'kind' => 'schedule',
            'execution_id' => $executionId,
            'run_uuid' => $executionId,
            'start_reported' => false,
            'idempotency_key' => 'laravel-schedule:'.$executionId,
            'started_at' => now()->toIso8601String(),
            'identity' => $identity,
            'reference' => $this->identities->reference($identity['slug']),
        ];
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
            'event_idempotency_key' => $execution['idempotency_key'].':started',
            'source' => 'laravel_scheduler',
            'occurred_at' => $execution['started_at'],
            'metadata' => [
                'run_in_background' => data_get($execution, 'identity.metadata.run_in_background', false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed>
     */
    private function skipPayload(array $execution, string $message): array
    {
        return [
            'idempotency_key' => $execution['idempotency_key'],
            'event_idempotency_key' => $execution['idempotency_key'].':skipped',
            'source' => 'laravel_scheduler',
            'occurred_at' => now()->toIso8601String(),
            'message' => $message,
        ];
    }

    private function enabled(): bool
    {
        return (bool) config('latidoflow.runtime.enabled', true)
            && filled(config('latidoflow.token'));
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('LatidoFlow scheduler runtime event was not reported.', [
                'exception_class' => $exception::class,
            ]);
        }
    }
}
