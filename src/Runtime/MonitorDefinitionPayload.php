<?php

namespace LatidoFlow\Laravel\Runtime;

use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

final class MonitorDefinitionPayload
{
    public const int MAX_MONITORS = 100;

    public const int MAX_PAYLOAD_BYTES = 32 * 1024;

    public function __construct(
        private readonly MonitorIdentity $identities,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Schedule $schedule): array
    {
        $queueDefinitions = $this->queueDefinitions();
        $scheduledMonitors = collect($schedule->events())
            ->reject(fn (Event $event): bool => $this->isDefinitionSyncEvent($event))
            ->map(fn (Event $event): array => $this->scheduledMonitor($event));
        $queueMonitors = $queueDefinitions
            ->map(fn (array $queue): array => $this->queueMonitor($queue));
        $monitors = $scheduledMonitors->merge($queueMonitors)->values();

        if ($monitors->isEmpty()) {
            throw new RuntimeException(
                'LatidoFlow found no monitor definitions. Define at least one scheduled task or configure an allowlisted queue before syncing.',
            );
        }

        $this->validateMonitorIdentities($monitors);
        $this->validateRuntimeQueueMappings($queueDefinitions);
        [$project, $environment] = $this->integrationContext();

        $payload = [
            'project' => $project,
            'environment' => $environment,
            'monitors' => $monitors->all(),
        ];
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        if (strlen($encodedPayload) > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException(
                'The LatidoFlow definition payload exceeds 32 KiB. Reduce configured definitions or metadata before syncing.',
            );
        }

        return $payload;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function queueDefinitions(): Collection
    {
        $configuredQueues = config('latidoflow.queues', []);

        if (! is_array($configuredQueues)) {
            throw new RuntimeException('LatidoFlow queue definitions must be an array.');
        }

        $queueDefinitions = collect($configuredQueues);

        if ($queueDefinitions->contains(fn (mixed $queue): bool => ! is_array($queue))) {
            throw new RuntimeException('Each LatidoFlow queue definition must be an array.');
        }

        return $queueDefinitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledMonitor(Event $event): array
    {
        if ($event->repeatSeconds !== null) {
            throw new RuntimeException(
                'LatidoFlow definition sync does not support sub-minute schedules. Use a schedule of one minute or longer.',
            );
        }

        $identity = $this->identities->scheduled($event);
        $graceSeconds = $this->boundedInteger(
            config('latidoflow.defaults.grace_seconds', 300),
            'schedule grace_seconds',
            0,
            86_400,
        );
        $timeoutSeconds = $this->boundedInteger(
            config('latidoflow.defaults.timeout_seconds', 3600),
            'schedule timeout_seconds',
            1,
            604_800,
        );

        return $this->withConfiguredContracts([
            'name' => $identity['name'],
            'slug' => $identity['slug'],
            'type' => 'heartbeat',
            'cron_expression' => $event->expression,
            'timezone' => $event->timezone instanceof DateTimeZone
                ? $event->timezone->getName()
                : ($event->timezone ?: config('app.timezone')),
            'grace_seconds' => $graceSeconds,
            'timeout_seconds' => $timeoutSeconds,
            'metadata' => $identity['metadata'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $queue
     * @return array<string, mixed>
     */
    private function queueMonitor(array $queue): array
    {
        $queue['start_timeout_seconds'] = $this->boundedInteger(
            $queue['start_timeout_seconds'] ?? config('latidoflow.defaults.queue_start_timeout_seconds', 300),
            'queue start_timeout_seconds',
            1,
            86_400,
        );
        $identity = $this->identities->queue($queue);

        if (($queue['runtime_reporting'] ?? false) === true && ! filled($queue['job_class'] ?? null)) {
            throw new RuntimeException('A runtime-enabled LatidoFlow queue definition requires job_class.');
        }

        $checkIntervalMinutes = $this->boundedInteger(
            $queue['check_interval_minutes'] ?? config('latidoflow.defaults.check_interval_minutes', 60),
            'queue check_interval_minutes',
            1,
            10_080,
        );
        $timeoutSeconds = $this->boundedInteger(
            $queue['timeout_seconds'] ?? config('latidoflow.defaults.timeout_seconds', 3600),
            'queue timeout_seconds',
            1,
            604_800,
        );

        return $this->withConfiguredContracts([
            'name' => $identity['name'],
            'slug' => $identity['slug'],
            'type' => 'heartbeat',
            'check_interval_minutes' => $checkIntervalMinutes,
            'timeout_seconds' => $timeoutSeconds,
            'metadata' => $identity['metadata'],
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $monitors
     */
    private function validateMonitorIdentities(Collection $monitors): void
    {
        if ($monitors->count() > self::MAX_MONITORS) {
            throw new RuntimeException('LatidoFlow supports at most 100 definitions per synchronization request.');
        }

        if ($monitors->contains(fn (array $monitor): bool => ! filled($monitor['slug'] ?? null))) {
            throw new RuntimeException('LatidoFlow monitor names must contain at least one letter or number.');
        }

        if ($monitors->contains(fn (array $monitor): bool => Str::length((string) $monitor['name']) > 160
            || Str::length((string) $monitor['slug']) > 160)) {
            throw new RuntimeException('LatidoFlow monitor names and generated slugs must not exceed 160 characters.');
        }

        $duplicateSlugs = $monitors
            ->groupBy('slug')
            ->filter(fn ($definitions): bool => $definitions->count() > 1)
            ->keys();

        if ($duplicateSlugs->isNotEmpty()) {
            throw new RuntimeException(
                'LatidoFlow monitor slugs must be unique: '.$duplicateSlugs->implode(', ').'.',
            );
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $queueDefinitions
     */
    private function validateRuntimeQueueMappings(Collection $queueDefinitions): void
    {
        $duplicateRuntimeQueues = $queueDefinitions
            ->filter(fn (array $queue): bool => ($queue['runtime_reporting'] ?? false) === true
                && filled($queue['job_class'] ?? null))
            ->map(function (array $queue): string {
                $identity = $this->identities->queue($queue);

                return implode('|', [
                    (string) data_get($identity, 'metadata.connection'),
                    (string) data_get($identity, 'metadata.queue'),
                    (string) data_get($identity, 'metadata.job_class'),
                ]);
            })
            ->duplicates()
            ->unique()
            ->values();

        if ($duplicateRuntimeQueues->isNotEmpty()) {
            throw new RuntimeException(
                'Runtime-enabled LatidoFlow queue definitions must have unique connection, queue, and job_class combinations: '
                .$duplicateRuntimeQueues->implode(', ').'.',
            );
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function integrationContext(): array
    {
        $project = config('latidoflow.project', []);
        $environment = config('latidoflow.environment', []);

        if (! is_array($project) || ! is_array($environment)) {
            throw new RuntimeException('LatidoFlow project and environment configuration must be arrays.');
        }

        $this->validateContext($project, 'project', 120);
        $this->validateContext($environment, 'environment', 120);

        if (isset($environment['kind']) && Str::length((string) $environment['kind']) > 60) {
            throw new RuntimeException('The LatidoFlow environment kind must not exceed 60 characters.');
        }

        return [$project, $environment];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function validateContext(array $context, string $label, int $maximumLength): void
    {
        $name = (string) ($context['name'] ?? '');
        $slug = (string) ($context['slug'] ?? $name);

        if (Str::slug($slug) === '') {
            throw new RuntimeException("The LatidoFlow {$label} name or slug must contain at least one letter or number.");
        }

        if (Str::length($name) > $maximumLength || Str::length($slug) > $maximumLength) {
            throw new RuntimeException("The LatidoFlow {$label} name and slug must not exceed {$maximumLength} characters.");
        }
    }

    private function isDefinitionSyncEvent(Event $event): bool
    {
        $command = is_string($event->command) ? $event->command : '';

        return preg_match('/(?:^|\s)[\'\"]?latidoflow:sync[\'\"]?(?:\s|$)/', $command) === 1;
    }

    private function boundedInteger(mixed $value, string $label, int $minimum, int $maximum): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                "LatidoFlow {$label} must be an integer between {$minimum} and {$maximum}.",
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $monitor
     * @return array<string, mixed>
     */
    private function withConfiguredContracts(array $monitor): array
    {
        $slug = (string) $monitor['slug'];

        foreach (['output_assertions', 'semantic_checks', 'alert_truth'] as $contract) {
            $contractsBySlug = config("latidoflow.{$contract}", []);

            if (is_array($contractsBySlug) && array_key_exists($slug, $contractsBySlug)) {
                $monitor[$contract] = $contractsBySlug[$slug];
            }
        }

        return $monitor;
    }
}
