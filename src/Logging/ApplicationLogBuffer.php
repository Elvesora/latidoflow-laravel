<?php

namespace LatidoFlow\Laravel\Logging;

use Illuminate\Support\Str;
use LatidoFlow\Laravel\Contracts\ApplicationLogClient;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use LatidoFlow\Laravel\Runtime\ExecutionContext;
use Throwable;

final class ApplicationLogBuffer
{
    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    private bool $flushing = false;

    public function __construct(
        private readonly LatidoFlowClient $client,
        private readonly ExecutionContext $contexts,
        private readonly ApplicationLogSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function add(string $level, string $message, array $context, string $observedAt): void
    {
        if (! $this->enabled() || $this->flushing || ! $this->client instanceof ApplicationLogClient) {
            return;
        }

        $execution = $this->contexts->current();
        $event = [
            'event_uuid' => (string) Str::uuid(),
            'level' => strtolower($level),
            'message' => $this->sanitizer->message($message),
            'context' => $this->sanitizer->context($context),
            'observed_at' => $observedAt,
        ];
        $runUuid = is_array($execution) && is_string($execution['run_uuid'] ?? null)
            ? $execution['run_uuid']
            : null;

        if (is_string($runUuid) && Str::isUuid($runUuid)) {
            $event['run_uuid'] = $runUuid;
        }

        $maxBytes = $this->maxBatchBytes();

        if ($this->events !== [] && $this->payloadBytes([...$this->events, $event]) > $maxBytes) {
            $this->flush();
        }

        if ($this->payloadBytes([$event]) > $maxBytes) {
            $event['context'] = ['_latidoflow' => '[truncated-size]'];
        }

        if ($this->payloadBytes([$event]) > $maxBytes) {
            $event['message'] = mb_strcut($event['message'], 0, 986, 'UTF-8').'...[truncated]';
        }

        if ($this->payloadBytes([$event]) > $maxBytes) {
            return;
        }

        $this->events[] = $event;

        if (count($this->events) >= $this->batchSize()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->events === [] || $this->flushing) {
            return;
        }

        if (! $this->client instanceof ApplicationLogClient) {
            $this->events = [];

            return;
        }

        $events = $this->events;
        $this->events = [];
        $this->flushing = true;

        try {
            $payload = $this->envelope($events);

            if ($payload !== null) {
                $this->client->applicationLogs($payload);
            }
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
            }
        } finally {
            $this->flushing = false;
        }
    }

    public function count(): int
    {
        return count($this->events);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>|null
     */
    private function envelope(array $events): ?array
    {
        $projectConfig = config('latidoflow.project', []);
        $environmentConfig = config('latidoflow.environment', []);
        $projectSlug = Str::slug((string) (is_array($projectConfig)
            ? ($projectConfig['slug'] ?? $projectConfig['name'] ?? 'default')
            : 'default'));
        $environmentSlug = Str::slug((string) (is_array($environmentConfig)
            ? ($environmentConfig['slug'] ?? $environmentConfig['name'] ?? 'production')
            : 'production'));
        $project = $this->identifier($projectSlug !== '' ? $projectSlug : 'default', 100);
        $environment = $this->identifier($environmentSlug !== '' ? $environmentSlug : 'production', 100);
        $source = $this->identifier(config('latidoflow.application_logs.source'), 120, allowDots: true);

        if ($project === null || $environment === null || $source === null) {
            return null;
        }

        return [
            'project_slug' => $project,
            'environment_slug' => $environment,
            'source' => $source,
            'logs' => $events,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function payloadBytes(array $events): int
    {
        $payload = $this->envelope($events);

        if ($payload === null) {
            return PHP_INT_MAX;
        }

        try {
            return strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return PHP_INT_MAX;
        }
    }

    private function enabled(): bool
    {
        return config('latidoflow.application_logs.enabled') === true
            && filled(config('latidoflow.token'));
    }

    private function batchSize(): int
    {
        return max(1, min(32, (int) config('latidoflow.application_logs.batch_size', 32)));
    }

    private function maxBatchBytes(): int
    {
        return max(4096, min(32 * 1024, (int) config('latidoflow.application_logs.max_batch_bytes', 32 * 1024)));
    }

    private function identifier(mixed $value, int $maxLength, bool $allowDots = false): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > $maxLength) {
            return null;
        }

        $pattern = $allowDots
            ? '/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D'
            : '/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D';

        return preg_match($pattern, $value) === 1 ? $value : null;
    }
}
