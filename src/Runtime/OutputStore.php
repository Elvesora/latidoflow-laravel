<?php

namespace LatidoFlow\Laravel\Runtime;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

final class OutputStore
{
    public const int MAX_METRICS = 20;

    public const int MAX_EVIDENCE_BYTES = 16 * 1024;

    public const int MAX_EVIDENCE_DEPTH = 8;

    public const int MAX_EVIDENCE_NODES = 64;

    public const int MAX_EVIDENCE_STRING_BYTES = 2048;

    public const int MAX_EVIDENCE_KEY_BYTES = 64;

    private const string METRIC_PATTERN = '/\A[a-zA-Z][a-zA-Z0-9_.-]{0,63}\z/';

    /** @var array<string, true> */
    private array $failedReads = [];

    public function __construct(
        private readonly ExecutionContext $context,
    ) {}

    /**
     * Add strict, finite numeric output metrics to the current monitored execution.
     *
     * Invalid data and cache failures return false without changing the workload outcome.
     *
     * @param  array<mixed>  $metrics
     */
    public function output(array $metrics): bool
    {
        try {
            $execution = $this->context->current();
            $executionId = is_array($execution) ? ($execution['execution_id'] ?? null) : null;

            if (! is_string($executionId) || $executionId === '') {
                $this->warn('no_active_execution');

                return false;
            }

            if ($this->usesProcessLocalScheduleCache($execution)) {
                $this->warn('schedule_cache_not_cross_process');

                return false;
            }

            $existing = $this->read($executionId);
            $combined = array_replace($existing, $metrics);

            if ($violation = $this->violation($combined)) {
                $this->warn($violation);

                return false;
            }

            $this->cache()->put(
                $this->key($executionId),
                $combined,
                now()->addSeconds(max(60, (int) config('latidoflow.runtime.output_ttl_seconds', 86_400))),
            );

            return true;
        } catch (Throwable $exception) {
            $this->warn('cache_unavailable', $exception);

            return false;
        }
    }

    /**
     * Record one bounded JSON-compatible evidence document for the current execution.
     *
     * A later call replaces the document captured by an earlier call. Invalid data and
     * cache failures return false without changing the workload outcome.
     *
     * @param  array<mixed>  $evidence
     */
    public function evidence(array $evidence): bool
    {
        try {
            $execution = $this->context->current();
            $executionId = is_array($execution) ? ($execution['execution_id'] ?? null) : null;

            if (! is_string($executionId) || $executionId === '') {
                $this->warnEvidence('no_active_execution');

                return false;
            }

            if ($this->usesProcessLocalScheduleCache($execution)) {
                $this->warnEvidence('schedule_cache_not_cross_process');

                return false;
            }

            if ($violation = $this->evidenceViolation($evidence)) {
                $this->warnEvidence($violation);

                return false;
            }

            $this->cache()->put(
                $this->evidenceKey($executionId),
                $evidence,
                now()->addSeconds(max(60, (int) config('latidoflow.runtime.output_ttl_seconds', 86_400))),
            );

            return true;
        } catch (Throwable $exception) {
            $this->warnEvidence('cache_unavailable', $exception);

            return false;
        }
    }

    /**
     * @return array<string, int|float>
     */
    public function get(string $executionId): array
    {
        if (isset($this->failedReads[$executionId])) {
            return [];
        }

        try {
            return $this->read($executionId);
        } catch (Throwable $exception) {
            $this->failedReads[$executionId] = true;
            $this->warn('cache_read_failed', $exception);

            return [];
        }
    }

    /**
     * @return array<mixed>|null
     */
    public function getEvidence(string $executionId): ?array
    {
        if (isset($this->failedReads[$executionId])) {
            return null;
        }

        try {
            return $this->readEvidence($executionId);
        } catch (Throwable $exception) {
            $this->failedReads[$executionId] = true;
            $this->warnEvidence('cache_read_failed', $exception);

            return null;
        }
    }

    public function forget(string $executionId): void
    {
        $readPreviouslyFailed = isset($this->failedReads[$executionId]);
        unset($this->failedReads[$executionId]);

        try {
            $this->cache()->forget($this->key($executionId));
            $this->cache()->forget($this->evidenceKey($executionId));
        } catch (Throwable $exception) {
            $this->warn('cache_cleanup_failed', $exception, ! $readPreviouslyFailed);
        }
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    private function usesProcessLocalScheduleCache(array $execution): bool
    {
        if (($execution['kind'] ?? null) !== 'schedule') {
            return false;
        }

        $store = config('latidoflow.runtime.cache_store') ?: config('cache.default');
        $driver = is_string($store) ? config("cache.stores.{$store}.driver") : null;

        return in_array($driver, ['array', 'null'], true);
    }

    /**
     * @param  array<mixed>  $metrics
     */
    private function violation(array $metrics): ?string
    {
        if (count($metrics) > self::MAX_METRICS) {
            return 'too_many_metrics';
        }

        foreach ($metrics as $metric => $value) {
            if (! is_string($metric) || preg_match(self::METRIC_PATTERN, $metric) !== 1) {
                return 'invalid_metric_name';
            }

            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                return 'invalid_metric_value';
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $evidence
     */
    private function evidenceViolation(array $evidence): ?string
    {
        try {
            $encoded = json_encode($evidence, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'evidence_not_json';
        }

        if (strlen($encoded) > self::MAX_EVIDENCE_BYTES) {
            return 'evidence_too_large';
        }

        $nodes = 0;

        return $this->evidenceNodeViolation($evidence, 0, $nodes);
    }

    private function evidenceNodeViolation(mixed $value, int $depth, int &$nodes): ?string
    {
        $nodes++;

        if ($depth > self::MAX_EVIDENCE_DEPTH || $nodes > self::MAX_EVIDENCE_NODES) {
            return 'evidence_too_complex';
        }

        if (is_float($value) && ! is_finite($value)) {
            return 'evidence_non_finite_number';
        }

        if (is_string($value) && strlen($value) > self::MAX_EVIDENCE_STRING_BYTES) {
            return 'evidence_string_too_long';
        }

        if (! is_array($value)) {
            return is_scalar($value) || $value === null ? null : 'evidence_invalid_type';
        }

        foreach ($value as $key => $child) {
            if (is_string($key)
                && (strlen($key) > self::MAX_EVIDENCE_KEY_BYTES || str_contains($key, "\0"))) {
                return 'evidence_invalid_key';
            }

            if ($violation = $this->evidenceNodeViolation($child, $depth + 1, $nodes)) {
                return $violation;
            }
        }

        return null;
    }

    /**
     * @return array<string, int|float>
     */
    private function read(string $executionId): array
    {
        $metrics = $this->cache()->get($this->key($executionId), []);

        return is_array($metrics) && $this->violation($metrics) === null ? $metrics : [];
    }

    /**
     * @return array<mixed>|null
     */
    private function readEvidence(string $executionId): ?array
    {
        $evidence = $this->cache()->get($this->evidenceKey($executionId));

        return is_array($evidence) && $this->evidenceViolation($evidence) === null ? $evidence : null;
    }

    private function cache(): Repository
    {
        return Cache::store(config('latidoflow.runtime.cache_store'));
    }

    private function key(string $executionId): string
    {
        return 'latidoflow:runtime-output:'.hash('sha256', $executionId);
    }

    private function evidenceKey(string $executionId): string
    {
        return 'latidoflow:runtime-evidence:'.hash('sha256', $executionId);
    }

    private function warn(
        string $reason,
        ?Throwable $exception = null,
        bool $reportException = true,
    ): void {
        if ($exception && $reportException) {
            report($exception);
        }

        Log::warning('LatidoFlow runtime output was not recorded.', array_filter([
            'reason' => $reason,
            'exception_class' => $exception ? $exception::class : null,
        ]));
    }

    private function warnEvidence(string $reason, ?Throwable $exception = null): void
    {
        if ($exception) {
            report($exception);
        }

        Log::warning('LatidoFlow semantic evidence was not recorded.', array_filter([
            'reason' => $reason,
            'exception_class' => $exception ? $exception::class : null,
        ]));
    }
}
