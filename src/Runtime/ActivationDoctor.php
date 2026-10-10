<?php

namespace LatidoFlow\Laravel\Runtime;

use Composer\InstalledVersions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use RuntimeException;
use Throwable;

final class ActivationDoctor
{
    public const EVIDENCE_INSTRUCTION = 'Look up the latest accepted run from Workspace integration or the management API. The doctor does not read runtime evidence with the ingestion token.';

    public function __construct(
        private readonly MonitorDefinitionPayload $payloads,
        private readonly LatidoFlowClient $client,
        private readonly HttpLatidoFlowClient $http,
    ) {}

    public function diagnose(Schedule $schedule, bool $runSync): DoctorReport
    {
        $origin = $this->originCheck();
        $token = $this->tokenCheck();
        $definitions = $this->definitionsCheck($schedule);
        $checks = [
            $this->packageCheck(),
            $origin,
            $token,
            $definitions,
            $this->cacheCheck(),
            $this->queueCheck(),
        ];

        if ($origin->status === DoctorCheckStatus::Blocker) {
            $checks[] = new DoctorCheck('pipeline', DoctorCheckStatus::Pass, 'not run because an earlier check blocked');
        } else {
            $checks[] = $this->pipelineCheck();
        }

        $canSync = $runSync
            && $origin->status !== DoctorCheckStatus::Blocker
            && $token->status !== DoctorCheckStatus::Blocker
            && $definitions->status !== DoctorCheckStatus::Blocker;

        if (! $runSync) {
            $checks[] = new DoctorCheck('sync', DoctorCheckStatus::Pass, 'not run (read-only)');
        } elseif (! $canSync) {
            $checks[] = new DoctorCheck('sync', DoctorCheckStatus::Pass, 'not run because an earlier check blocked');
        } else {
            $checks[] = $this->syncCheck($schedule);
        }

        return new DoctorReport($checks, self::EVIDENCE_INSTRUCTION);
    }

    private function packageCheck(): DoctorCheck
    {
        if (! class_exists(InstalledVersions::class)
            || ! InstalledVersions::isInstalled('latidoflow/laravel')) {
            return new DoctorCheck('package', DoctorCheckStatus::Warning, 'package version could not be determined');
        }

        $version = InstalledVersions::getPrettyVersion('latidoflow/laravel');

        if (! is_string($version) || $version === '') {
            return new DoctorCheck('package', DoctorCheckStatus::Warning, 'package version could not be determined');
        }

        return new DoctorCheck('package', DoctorCheckStatus::Pass, 'latidoflow/laravel '.$version);
    }

    private function originCheck(): DoctorCheck
    {
        try {
            $this->http->applicationOrigin();
        } catch (RuntimeException $exception) {
            return new DoctorCheck('origin', DoctorCheckStatus::Blocker, $exception->getMessage());
        }

        $endpoint = config('latidoflow.endpoint');
        $scheme = is_string($endpoint) ? strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) : '';

        if ($scheme === 'http') {
            return new DoctorCheck('origin', DoctorCheckStatus::Warning, 'insecure HTTP is enabled; tokens will be sent without TLS');
        }

        return new DoctorCheck('origin', DoctorCheckStatus::Pass, 'HTTPS origin is configured');
    }

    private function tokenCheck(): DoctorCheck
    {
        try {
            $this->http->assertTokenConfigured();
        } catch (RuntimeException $exception) {
            return new DoctorCheck('token', DoctorCheckStatus::Blocker, $exception->getMessage());
        }

        return new DoctorCheck('token', DoctorCheckStatus::Pass, 'token is present and well-formed');
    }

    private function definitionsCheck(Schedule $schedule): DoctorCheck
    {
        try {
            $payload = $this->payloads->build($schedule);
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();

            if (Str::startsWith($message, [
                'LatidoFlow monitor slugs must be unique: ',
                'Runtime-enabled LatidoFlow queue definitions must have unique connection, queue, and job_class combinations: ',
            ])) {
                $message = Str::before($message, ':').'.';
            }

            return new DoctorCheck('definitions', DoctorCheckStatus::Blocker, $message);
        }

        $count = count($payload['monitors'] ?? []);

        return new DoctorCheck(
            'definitions',
            DoctorCheckStatus::Pass,
            $count === 1
                ? '1 monitor definition is ready'
                : $count.' monitor definitions are ready',
        );
    }

    private function cacheCheck(): DoctorCheck
    {
        $storeName = config('latidoflow.runtime.cache_store') ?: config('cache.default');

        try {
            $store = Cache::store(is_string($storeName) ? $storeName : null);
            $key = 'latidoflow:doctor:'.Str::uuid();
            $store->put($key, 'ok', 10);
            $read = $store->get($key);
            $store->forget($key);
        } catch (Throwable) {
            return new DoctorCheck('cache', DoctorCheckStatus::Blocker, 'cache store is not available');
        }

        if ($read !== 'ok') {
            return new DoctorCheck('cache', DoctorCheckStatus::Blocker, 'cache store did not round-trip a diagnostic value');
        }

        $driver = is_string($storeName) ? config("cache.stores.{$storeName}.driver") : null;

        if (in_array($driver, ['array', 'null'], true)) {
            return new DoctorCheck(
                'cache',
                DoctorCheckStatus::Warning,
                'process-local cache cannot share scheduled output across processes',
            );
        }

        return new DoctorCheck('cache', DoctorCheckStatus::Pass, 'cache store can store diagnostic output');
    }

    private function queueCheck(): DoctorCheck
    {
        $configuredQueues = config('latidoflow.queues', []);

        if (! is_array($configuredQueues)) {
            return new DoctorCheck('queue', DoctorCheckStatus::Blocker, 'queue definitions must be an array');
        }

        $runtimeQueues = [];

        foreach ($configuredQueues as $queue) {
            if (! is_array($queue)) {
                return new DoctorCheck('queue', DoctorCheckStatus::Blocker, 'each queue definition must be an array');
            }

            if (($queue['runtime_reporting'] ?? false) === true) {
                $runtimeQueues[] = $queue;
            }
        }

        if ($runtimeQueues === []) {
            $unlistedFailureReporting = config('latidoflow.queue_unlisted_failures.enabled', false) === true
                ? 'enabled'
                : 'disabled';

            return new DoctorCheck(
                'queue',
                DoctorCheckStatus::Pass,
                'no runtime queue allowlist is configured; unlisted queue-failure reporting is '.$unlistedFailureReporting,
            );
        }

        $usesSyncDriver = false;

        foreach ($runtimeQueues as $queue) {
            $connection = $queue['connection'] ?? config('queue.default');

            if (! is_string($connection) || $connection === '' || ! is_array(config("queue.connections.{$connection}"))) {
                return new DoctorCheck('queue', DoctorCheckStatus::Blocker, 'a runtime queue connection is not configured');
            }

            $driver = config("queue.connections.{$connection}.driver");

            if ($driver === 'sync') {
                $usesSyncDriver = true;
            }
        }

        if ($usesSyncDriver) {
            return new DoctorCheck(
                'queue',
                DoctorCheckStatus::Warning,
                'runtime queue reporting uses a sync driver, so queue-wait evidence will not appear',
            );
        }

        return new DoctorCheck('queue', DoctorCheckStatus::Pass, 'runtime queue connections are configured');
    }

    private function pipelineCheck(): DoctorCheck
    {
        try {
            $response = $this->http->monitoringPipeline();
        } catch (RuntimeException $exception) {
            return new DoctorCheck('pipeline', DoctorCheckStatus::Blocker, $exception->getMessage());
        }

        return $this->classifyPipeline($response);
    }

    private function classifyPipeline(Response $response): DoctorCheck
    {
        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            return new DoctorCheck('pipeline', DoctorCheckStatus::Blocker, 'public monitoring pipeline redirected with HTTP '.$status);
        }

        if ($status === 401 || $status === 403) {
            return new DoctorCheck('pipeline', DoctorCheckStatus::Blocker, 'public monitoring pipeline authentication failed with HTTP '.$status);
        }

        $ok = $response->json('ok');
        $health = $response->json('status');

        if ($response->successful() && $ok === true && $health === 'healthy') {
            return new DoctorCheck('pipeline', DoctorCheckStatus::Pass, 'public monitoring pipeline is healthy');
        }

        if ($status === 503 || $ok === false || $health === 'unhealthy') {
            return new DoctorCheck('pipeline', DoctorCheckStatus::Blocker, 'public monitoring pipeline is unhealthy');
        }

        return new DoctorCheck('pipeline', DoctorCheckStatus::Blocker, 'public monitoring pipeline returned HTTP '.$status);
    }

    private function syncCheck(Schedule $schedule): DoctorCheck
    {
        try {
            $response = $this->client->sync($this->payloads->build($schedule));
        } catch (RuntimeException $exception) {
            $message = match ($exception->getMessage()) {
                'LatidoFlow request timed out.' => 'LatidoFlow request timed out.',
                'LatidoFlow could not be reached.' => 'LatidoFlow could not be reached.',
                'LatidoFlow definition sync returned an invalid response.' => 'LatidoFlow definition sync returned an invalid response.',
                default => 'definition sync could not complete; check the transport and sync configuration',
            };

            return new DoctorCheck('sync', DoctorCheckStatus::Blocker, $message, mutates: true);
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            return new DoctorCheck('sync', DoctorCheckStatus::Blocker, 'authentication failed with HTTP '.$status, mutates: true);
        }

        if ($response->successful()) {
            return new DoctorCheck('sync', DoctorCheckStatus::Pass, 'definition sync succeeded with HTTP '.$status, mutates: true);
        }

        return new DoctorCheck('sync', DoctorCheckStatus::Blocker, 'definition sync failed with HTTP '.$status, mutates: true);
    }
}
