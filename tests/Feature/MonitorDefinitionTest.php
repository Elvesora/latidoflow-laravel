<?php

namespace LatidoFlow\Laravel\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use LatidoFlow\Laravel\Runtime\MonitorDefinitionPayload;
use LatidoFlow\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class MonitorDefinitionTest extends TestCase
{
    public function test_sync_command_emits_first_class_heartbeat_definitions_with_source_kinds(): void
    {
        config()->set('latidoflow.defaults.grace_seconds', 120);
        config()->set('latidoflow.defaults.timeout_seconds', 900);
        config()->set('latidoflow.defaults.check_interval_minutes', 15);
        config()->set('latidoflow.queues', [[
            'name' => 'Invoices queue',
            'connection' => 'redis',
            'queue' => 'invoices',
            'job_class' => 'App\\Jobs\\SendInvoices',
            'runtime_reporting' => true,
        ]]);
        config()->set('latidoflow.output_assertions', [
            'daily-reports' => [
                ['metric' => 'records_processed', 'operator' => 'gte', 'value' => 1],
            ],
            'invoices-queue' => [],
        ]);
        config()->set('latidoflow.semantic_checks', [
            'daily-reports' => [
                'version' => 2,
                'rules' => [[
                    'id' => 'report-complete',
                    'source' => 'output',
                    'path' => '$.report.status',
                    'expect' => ['operator' => 'equals', 'value' => 'complete'],
                ]],
            ],
        ]);
        config()->set('latidoflow.alert_truth', [
            'invoices-queue' => [
                'failure_threshold' => 2,
                'sample_size' => 3,
                'recovery_threshold' => 1,
            ],
        ]);
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->description('Daily reports');

        $payload = $this->syncPayload($schedule);
        $scheduler = collect($payload['monitors'])->firstWhere('metadata.source', 'laravel-scheduler');
        $queue = collect($payload['monitors'])->firstWhere('metadata.source', 'laravel-queue');

        $this->assertSame('heartbeat', $scheduler['type']);
        $this->assertSame('scheduled', $scheduler['metadata']['source_kind']);
        $this->assertSame('automatic', $scheduler['metadata']['runtime_reporting']);
        $this->assertArrayNotHasKey('command', $scheduler['metadata']);
        $this->assertSame('0 0 * * *', $scheduler['cron_expression']);
        $this->assertSame([
            ['metric' => 'records_processed', 'operator' => 'gte', 'value' => 1],
        ], $scheduler['output_assertions']);
        $this->assertSame(2, $scheduler['semantic_checks']['version']);
        $this->assertSame('$.report.status', $scheduler['semantic_checks']['rules'][0]['path']);
        $this->assertArrayNotHasKey('alert_truth', $scheduler);
        $this->assertArrayNotHasKey('output_assertions', $scheduler['metadata']);
        $this->assertArrayNotHasKey('semantic_checks', $scheduler['metadata']);
        $this->assertSame('heartbeat', $queue['type']);
        $this->assertSame('queue', $queue['metadata']['source_kind']);
        $this->assertSame('invoices', $queue['metadata']['queue']);
        $this->assertSame('automatic', $queue['metadata']['runtime_reporting']);
        $this->assertSame(300, $queue['metadata']['start_timeout_seconds']);
        $this->assertSame([], $queue['output_assertions']);
        $this->assertSame(2, $queue['alert_truth']['failure_threshold']);
        $this->assertSame(3, $queue['alert_truth']['sample_size']);
        $this->assertArrayNotHasKey('semantic_checks', $queue);
        $this->assertArrayNotHasKey('output_assertions', $queue['metadata']);
        $this->assertArrayNotHasKey('alert_truth', $queue['metadata']);
    }

    public function test_sync_command_omits_contracts_when_a_generated_slug_is_not_configured(): void
    {
        config()->set('latidoflow.output_assertions', [
            'another-monitor' => [
                ['metric' => 'records_processed', 'operator' => 'gte', 'value' => 1],
            ],
        ]);
        config()->set('latidoflow.semantic_checks', [
            'another-monitor' => ['version' => 2, 'rules' => []],
        ]);
        config()->set('latidoflow.alert_truth', [
            'another-monitor' => ['failure_threshold' => 1, 'sample_size' => 1],
        ]);
        config()->set('latidoflow.queues', [[
            'name' => 'Invoices queue',
        ]]);
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->description('Daily reports');

        $payload = $this->syncPayload($schedule);

        foreach ($payload['monitors'] as $monitor) {
            $this->assertArrayNotHasKey('output_assertions', $monitor);
            $this->assertArrayNotHasKey('semantic_checks', $monitor);
            $this->assertArrayNotHasKey('alert_truth', $monitor);
        }
    }

    public function test_sync_command_preserves_explicit_contract_clear_values(): void
    {
        config()->set('latidoflow.output_assertions', ['daily-reports' => []]);
        config()->set('latidoflow.semantic_checks', ['daily-reports' => null]);
        config()->set('latidoflow.alert_truth', ['daily-reports' => null]);
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->description('Daily reports');

        $monitor = $this->syncPayload($schedule)['monitors'][0];

        $this->assertSame([], $monitor['output_assertions']);
        $this->assertNull($monitor['semantic_checks']);
        $this->assertNull($monitor['alert_truth']);
    }

    public function test_definition_sync_housekeeping_is_excluded_from_the_payload(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('latidoflow:sync --no-interaction')->hourly()->name('LatidoFlow definition sync');
        $schedule->command('reports:daily')->daily()->description('Daily reports');

        $payload = $this->syncPayload($schedule);

        $this->assertCount(1, $payload['monitors']);
        $this->assertSame('daily-reports', $payload['monitors'][0]['slug']);
        $this->assertStringNotContainsString('latidoflow-sync', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_empty_definitions_are_rejected_with_an_actionable_message(): void
    {
        config()->set('latidoflow.queues', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('found no monitor definitions');

        $this->syncPayload(new Schedule('UTC'));
    }

    public function test_schedule_specific_timezone_is_preserved(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')
            ->daily()
            ->timezone('Europe/Madrid')
            ->description('Daily reports');

        $payload = $this->syncPayload($schedule);

        $this->assertSame('Europe/Madrid', $payload['monitors'][0]['timezone']);
    }

    public function test_background_schedules_are_synchronized_without_claiming_runtime_reporting(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('reports:background')
            ->daily()
            ->description('Background reports')
            ->runInBackground();

        $definition = $this->syncPayload($schedule)['monitors'][0];

        $this->assertSame('unsupported_background', $definition['metadata']['runtime_reporting']);
        $this->assertTrue($definition['metadata']['run_in_background']);
    }

    public function test_sub_minute_schedules_are_rejected(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('reports:frequent')->everyTenSeconds()->description('Frequent reports');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support sub-minute schedules');

        $this->syncPayload($schedule);
    }

    public function test_sync_command_rejects_duplicate_generated_monitor_slugs(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('reports:first')->daily()->description('Daily reports');
        $schedule->command('reports:second')->hourly()->description('Daily reports');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('daily-reports');

        $this->syncPayload($schedule);
    }

    public function test_runtime_enabled_queue_requires_an_exact_job_class(): void
    {
        config()->set('latidoflow.queues', [[
            'name' => 'Invoices queue',
            'runtime_reporting' => true,
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires job_class');

        $this->syncPayload(new Schedule('UTC'));
    }

    public function test_duplicate_runtime_queue_mappings_are_rejected_even_when_monitor_names_differ(): void
    {
        config()->set('latidoflow.queues', [
            [
                'name' => 'Primary invoice exports',
                'connection' => 'redis',
                'queue' => 'billing',
                'job_class' => 'App\\Jobs\\ExportInvoices',
                'runtime_reporting' => true,
            ],
            [
                'name' => 'Secondary invoice exports',
                'connection' => 'redis',
                'queue' => 'billing',
                'job_class' => 'App\\Jobs\\ExportInvoices',
                'runtime_reporting' => true,
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unique connection, queue, and job_class combinations');

        $this->syncPayload(new Schedule('UTC'));
    }

    public function test_monitor_names_must_generate_a_non_empty_slug(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->description('!!!');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('monitor names must contain at least one letter or number');

        $this->syncPayload($schedule);
    }

    /**
     * @param  array<string, mixed>  $project
     * @param  array<string, mixed>  $environment
     */
    #[DataProvider('invalidIntegrationContextProvider')]
    public function test_project_and_environment_context_must_generate_valid_slugs(
        array $project,
        array $environment,
        string $expectedMessage,
    ): void {
        config()->set('latidoflow.project', $project);
        config()->set('latidoflow.environment', $environment);
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily')->daily()->description('Daily reports');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->syncPayload($schedule);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function invalidIntegrationContextProvider(): array
    {
        return [
            'project slug' => [
                ['name' => '!!!', 'slug' => ''],
                ['name' => 'Production', 'slug' => 'production'],
                'project name or slug must contain at least one letter or number',
            ],
            'environment slug' => [
                ['name' => 'Example', 'slug' => 'example'],
                ['name' => '!!!', 'slug' => ''],
                'environment name or slug must contain at least one letter or number',
            ],
        ];
    }

    #[DataProvider('invalidMonitorTimingProvider')]
    public function test_monitor_timing_values_must_match_the_ingestion_contract(
        string $configPath,
        mixed $value,
        bool $usesSchedule,
        string $expectedMessage,
    ): void {
        config()->set($configPath, $value);
        $schedule = new Schedule('UTC');

        if ($usesSchedule) {
            $schedule->command('reports:daily')->daily()->description('Daily reports');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->syncPayload($schedule);
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: bool, 3: string}>
     */
    public static function invalidMonitorTimingProvider(): array
    {
        return [
            'schedule grace type' => [
                'latidoflow.defaults.grace_seconds',
                '120',
                true,
                'schedule grace_seconds must be an integer between 0 and 86400',
            ],
            'schedule timeout maximum' => [
                'latidoflow.defaults.timeout_seconds',
                604_801,
                true,
                'schedule timeout_seconds must be an integer between 1 and 604800',
            ],
            'queue interval minimum' => [
                'latidoflow.queues',
                [['name' => 'Invoices', 'check_interval_minutes' => 0]],
                false,
                'queue check_interval_minutes must be an integer between 1 and 10080',
            ],
            'queue start timeout type' => [
                'latidoflow.queues',
                [['name' => 'Invoices', 'start_timeout_seconds' => 300.5]],
                false,
                'queue start_timeout_seconds must be an integer between 1 and 86400',
            ],
        ];
    }

    public function test_definition_sync_rejects_more_than_one_hundred_monitors(): void
    {
        $schedule = new Schedule('UTC');

        foreach (range(1, 101) as $monitorNumber) {
            $schedule->command("reports:monitor-{$monitorNumber}")
                ->daily()
                ->description("Monitor {$monitorNumber}");
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at most 100 definitions');

        $this->syncPayload($schedule);
    }

    public function test_definition_sync_rejects_payloads_larger_than_thirty_two_kibibytes(): void
    {
        $schedule = new Schedule('UTC');

        foreach (range(1, 100) as $monitorNumber) {
            $description = str_repeat('a', 156).'-'.str_pad((string) $monitorNumber, 3, '0', STR_PAD_LEFT);
            $schedule->command("reports:large-{$monitorNumber}")
                ->daily()
                ->description($description);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payload exceeds 32 KiB');

        $this->syncPayload($schedule);
    }

    public function test_unnamed_schedule_does_not_expose_command_arguments_or_claim_automatic_runtime_reporting(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command('reports:daily --customer=private-reference')->daily();

        $payload = $this->syncPayload($schedule);
        $definition = $payload['monitors'][0];

        $this->assertSame('Unnamed Laravel schedule', $definition['name']);
        $this->assertSame('name_required', $definition['metadata']['runtime_reporting']);
        $this->assertArrayNotHasKey('command', $definition['metadata']);
        $this->assertArrayNotHasKey('schedule_identity', $definition['metadata']);
        $this->assertStringNotContainsString('private-reference', json_encode($definition, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function syncPayload(Schedule $schedule): array
    {
        return $this->app->make(MonitorDefinitionPayload::class)->build($schedule);
    }
}
