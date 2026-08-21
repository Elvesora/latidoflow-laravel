<?php

namespace LatidoFlow\Laravel\Runtime;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use RuntimeException;
use Throwable;

final class HttpLatidoFlowClient implements LatidoFlowClient
{
    private const array PROFILE_LIMITS = [
        'sync' => [
            'connect_timeout_seconds' => [0.1, 10.0],
            'timeout_seconds' => [0.2, 30.0],
            'retry_count' => 3,
            'retry_delay_ms' => 5000,
        ],
        'runtime' => [
            'connect_timeout_seconds' => [0.1, 2.0],
            'timeout_seconds' => [0.2, 5.0],
            'retry_count' => 1,
            'retry_delay_ms' => 1000,
        ],
    ];

    public function sync(array $payload): Response
    {
        $response = $this->postUrl(
            $this->endpoint('/api/v1/monitors/sync'),
            $payload,
            throwOnFailure: false,
            profile: 'sync',
        );

        if ($response->successful()) {
            $this->validateSyncResponse($response);
        }

        return $response;
    }

    public function queued(array $reference, array $payload): void
    {
        $this->post('/api/v1/runtime/runs/queued', [...$payload, ...$reference]);
    }

    public function start(array $reference, array $payload): string
    {
        $response = $this->post('/api/v1/runtime/runs/start', [...$payload, ...$reference]);
        $runUuid = $response->json('run_uuid');

        if (! is_string($runUuid) || ! Str::isUuid($runUuid)) {
            throw new RuntimeException('LatidoFlow runtime start returned no valid run UUID.');
        }

        return $runUuid;
    }

    public function skipped(array $reference, array $payload): void
    {
        $this->post('/api/v1/runtime/runs/skipped', [...$payload, ...$reference]);
    }

    public function heartbeat(string $runUuid, array $payload): void
    {
        $this->post('/api/v1/runs/'.rawurlencode($runUuid).'/heartbeat', $payload);
    }

    public function success(string $runUuid, array $payload): void
    {
        $this->post('/api/v1/runs/'.rawurlencode($runUuid).'/success', $payload);
    }

    public function fail(string $runUuid, array $payload): void
    {
        $this->post('/api/v1/runs/'.rawurlencode($runUuid).'/fail', $payload);
    }

    private function post(string $path, array $payload): Response
    {
        return $this->postUrl($this->endpoint($path), $payload, profile: 'runtime');
    }

    private function postUrl(
        string $url,
        array $payload,
        bool $throwOnFailure = true,
        string $profile = 'runtime',
    ): Response {
        try {
            $response = $this->request($profile)->post($url, $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('LatidoFlow could not be reached.');
        }

        if ($throwOnFailure && ! $response->successful()) {
            throw new RuntimeException("LatidoFlow request failed with HTTP {$response->status()}.");
        }

        return $response;
    }

    private function request(string $profile): PendingRequest
    {
        $token = config('latidoflow.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('LatidoFlow requests require a token.');
        }

        if (preg_match('/\A[A-Za-z0-9._~-]{1,512}\z/', $token) !== 1) {
            throw new RuntimeException('The LatidoFlow token format is invalid.');
        }

        $defaultConnectTimeout = $profile === 'sync' ? 1.0 : 0.5;
        $defaultTimeout = $profile === 'sync' ? 3.0 : 1.5;
        $defaultRetryDelays = $profile === 'sync' ? [100, 500] : [];
        $connectTimeout = $this->boundedTimeout($profile, 'connect_timeout_seconds', $defaultConnectTimeout);
        $timeout = $this->boundedTimeout($profile, 'timeout_seconds', $defaultTimeout);
        $retryDelays = $this->retryDelays($profile, $defaultRetryDelays);

        $request = Http::withToken($token)
            ->acceptJson()
            ->withoutRedirecting()
            ->connectTimeout($connectTimeout)
            ->timeout($timeout);

        if ($retryDelays === []) {
            return $request;
        }

        return $request->retry(
            $retryDelays,
            when: function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if (! $exception instanceof RequestException) {
                    return false;
                }

                $status = $exception->response->status();

                return $status === 408 || $status === 425 || $status === 429 || $status >= 500;
            },
            throw: false,
        );
    }

    private function endpoint(string $path): string
    {
        $endpoint = config('latidoflow.endpoint');
        $parts = is_string($endpoint) ? parse_url($endpoint) : false;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if (! is_array($parts)
            || ! in_array($scheme, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('The LatidoFlow endpoint must be an absolute HTTP(S) application origin.');
        }

        if ($scheme !== 'https' && config('latidoflow.allow_insecure_http') !== true) {
            throw new RuntimeException('The LatidoFlow endpoint must use HTTPS unless insecure HTTP is explicitly enabled.');
        }

        return rtrim($endpoint, '/').$path;
    }

    private function boundedTimeout(string $profile, string $setting, float $default): float
    {
        $value = config("latidoflow.http.{$profile}.{$setting}", $default);
        $limits = self::PROFILE_LIMITS[$profile][$setting] ?? null;

        if ((! is_int($value) && ! is_float($value))
            || ! is_finite((float) $value)
            || ! is_array($limits)
            || (float) $value < $limits[0]
            || (float) $value > $limits[1]) {
            throw new RuntimeException("The LatidoFlow {$profile} {$setting} configuration is invalid.");
        }

        return (float) $value;
    }

    /**
     * @param  array<int, int>  $default
     * @return array<int, int>
     */
    private function retryDelays(string $profile, array $default): array
    {
        $delays = config("latidoflow.http.{$profile}.retry_delays_ms", $default);
        $maxCount = self::PROFILE_LIMITS[$profile]['retry_count'] ?? null;
        $maxDelay = self::PROFILE_LIMITS[$profile]['retry_delay_ms'] ?? null;

        if (! is_array($delays)
            || ! is_int($maxCount)
            || ! is_int($maxDelay)
            || count($delays) > $maxCount) {
            throw new RuntimeException("The LatidoFlow {$profile} retry delay configuration is invalid.");
        }

        foreach ($delays as $delay) {
            if (! is_int($delay) || $delay < 0 || $delay > $maxDelay) {
                throw new RuntimeException("The LatidoFlow {$profile} retry delay configuration is invalid.");
            }
        }

        return array_values($delays);
    }

    private function validateSyncResponse(Response $response): void
    {
        $monitors = $response->json('monitors');

        if (! Str::isUuid((string) $response->json('project_uuid'))
            || ! Str::isUuid((string) $response->json('environment_uuid'))
            || ! is_array($monitors)
            || $monitors === []) {
            throw new RuntimeException('LatidoFlow definition sync returned an invalid response.');
        }

        foreach ($monitors as $monitor) {
            if (! is_array($monitor)
                || ! Str::isUuid((string) ($monitor['uuid'] ?? ''))
                || ! is_string($monitor['slug'] ?? null)
                || $monitor['slug'] === ''
                || ($monitor['type'] ?? null) !== 'heartbeat'
                || (array_key_exists('next_due_at', $monitor)
                    && ! is_string($monitor['next_due_at'])
                    && $monitor['next_due_at'] !== null)) {
                throw new RuntimeException('LatidoFlow definition sync returned an invalid response.');
            }
        }
    }
}
