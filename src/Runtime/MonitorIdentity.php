<?php

namespace LatidoFlow\Laravel\Runtime;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

final class MonitorIdentity
{
    public function freshRunUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hexadecimal = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hexadecimal, 0, 8),
            substr($hexadecimal, 8, 4),
            substr($hexadecimal, 12, 4),
            substr($hexadecimal, 16, 4),
            substr($hexadecimal, 20, 12),
        );
    }

    /**
     * @return array{name: string, slug: string, automatic: bool, metadata: array<string, mixed>}
     */
    public function scheduled(Event $event): array
    {
        $hasSafeName = filled($event->description);
        $runsInBackground = (bool) $event->runInBackground;
        $name = $hasSafeName
            ? (string) $event->description
            : 'Unnamed Laravel schedule';
        $slug = Str::slug($name);
        $automatic = $hasSafeName && $slug !== '' && ! $runsInBackground;
        $runtimeReporting = $runsInBackground
            ? 'unsupported_background'
            : ($automatic ? 'automatic' : 'name_required');

        return [
            'name' => $name,
            'slug' => $slug,
            'automatic' => $automatic,
            'metadata' => [
                'source' => 'laravel-scheduler',
                'source_kind' => 'scheduled',
                'runtime_reporting' => $runtimeReporting,
                'run_in_background' => $runsInBackground,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{name: string, slug: string, automatic: bool, metadata: array<string, mixed>}
     */
    public function queue(array $definition): array
    {
        $name = (string) ($definition['name'] ?? 'Unnamed Laravel queue job');
        $jobClass = is_string($definition['job_class'] ?? null)
            ? trim($definition['job_class'])
            : '';
        $slug = Str::slug($name);
        $automatic = ($definition['runtime_reporting'] ?? false) === true
            && $jobClass !== ''
            && $slug !== '';

        return [
            'name' => $name,
            'slug' => $slug,
            'automatic' => $automatic,
            'metadata' => [
                'source' => 'laravel-queue',
                'source_kind' => 'queue',
                'runtime_reporting' => $automatic ? 'automatic' : 'disabled',
                'connection' => $definition['connection'] ?? config('queue.default'),
                'queue' => $definition['queue'] ?? 'default',
                'job_class' => $jobClass !== '' ? $jobClass : null,
                'start_timeout_seconds' => max(
                    1,
                    (int) ($definition['start_timeout_seconds'] ?? config('latidoflow.defaults.queue_start_timeout_seconds', 300)),
                ),
            ],
        ];
    }

    /**
     * @return array{project_slug: string, environment_slug: string, monitor_slug: string}
     */
    public function reference(string $monitorSlug): array
    {
        $project = config('latidoflow.project', []);
        $environment = config('latidoflow.environment', []);
        $projectSlug = Str::slug((string) ($project['slug'] ?? $project['name'] ?? 'default'));
        $environmentSlug = Str::slug((string) ($environment['slug'] ?? $environment['name'] ?? 'production'));

        return [
            'project_slug' => $projectSlug !== '' ? $projectSlug : 'default',
            'environment_slug' => $environmentSlug !== '' ? $environmentSlug : 'production',
            'monitor_slug' => $monitorSlug,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchingQueue(string $connection, ?string $queue, string $jobClass): ?array
    {
        $matches = collect(config('latidoflow.queues', []))
            ->filter(function (mixed $definition) use ($connection, $queue, $jobClass): bool {
                if (! is_array($definition)) {
                    return false;
                }

                $identity = $this->queue($definition);

                if (! $identity['automatic'] || data_get($identity, 'metadata.job_class') !== $jobClass) {
                    return false;
                }

                $configuredConnection = data_get($identity, 'metadata.connection');
                $configuredQueue = data_get($identity, 'metadata.queue');

                return $configuredConnection === $connection
                    && $configuredQueue === ($queue ?: 'default');
            })
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
