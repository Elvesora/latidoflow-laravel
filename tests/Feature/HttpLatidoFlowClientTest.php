<?php

namespace LatidoFlow\Laravel\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LatidoFlow\Laravel\Runtime\HttpLatidoFlowClient;
use LatidoFlow\Laravel\Tests\TestCase;
use ReflectionMethod;
use RuntimeException;

class HttpLatidoFlowClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('latidoflow.token', 'lf_test_http');
        config()->set('latidoflow.endpoint', 'https://latidoflow.example.test');
        config()->set('latidoflow.http.sync.retry_delays_ms', [0]);
        config()->set('latidoflow.http.runtime.retry_delays_ms', []);
        Http::preventStrayRequests();
    }

    public function test_sync_retries_transient_failures(): void
    {
        Http::fakeSequence()
            ->push(['error' => 'temporary'], 503)
            ->push($this->syncResponse(), 200);

        $response = (new HttpLatidoFlowClient)->sync(['monitors' => []]);

        $this->assertTrue($response->successful());
        Http::assertSentCount(2);
    }

    public function test_successful_sync_requires_the_public_response_contract(): void
    {
        Http::fake([
            'https://latidoflow.example.test/api/v1/monitors/sync' => Http::response('<html>proxy page</html>', 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('definition sync returned an invalid response');

        (new HttpLatidoFlowClient)->sync(['monitors' => []]);
    }

    public function test_successful_sync_rejects_malformed_monitor_entries(): void
    {
        foreach ([null, [], ['uuid' => 'not-a-uuid', 'slug' => 'daily-reports', 'type' => 'heartbeat']] as $entry) {
            Http::fake([
                'https://latidoflow.example.test/api/v1/monitors/sync' => Http::response([
                    'project_uuid' => 'ad469be1-8131-4d03-8d22-d8384aec5605',
                    'environment_uuid' => '55f96f65-ee75-439c-8e28-dd215b2472fb',
                    'monitors' => [$entry],
                ], 200),
            ]);

            try {
                (new HttpLatidoFlowClient)->sync(['monitors' => []]);
                $this->fail('Every synchronized monitor response must match the public response contract.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('invalid response', $exception->getMessage());
            }
        }
    }

    public function test_runtime_reporting_does_not_retry_transient_failures(): void
    {
        Http::fakeSequence()
            ->push(['error' => 'temporary private response'], 503)
            ->push(['run_uuid' => '50af68f7-0f73-438d-b7d6-83380ad5c0e9'], 201);
        $client = new HttpLatidoFlowClient;

        try {
            $client->start($this->reference(), [
                'run_uuid' => '50af68f7-0f73-438d-b7d6-83380ad5c0e9',
                'idempotency_key' => 'laravel-schedule:50af68f7-0f73-438d-b7d6-83380ad5c0e9',
            ]);
            $this->fail('Runtime reporting must fail fast instead of delaying the monitored workload.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
            $this->assertStringNotContainsString('temporary private response', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_missing_token_fails_before_an_http_request_is_sent(): void
    {
        config()->set('latidoflow.token');

        try {
            (new HttpLatidoFlowClient)->sync(['monitors' => []]);
            $this->fail('A token is required for definition synchronization.');
        } catch (RuntimeException $exception) {
            $this->assertSame('LatidoFlow requests require a token.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_invalid_token_characters_fail_without_exposing_the_token(): void
    {
        config()->set('latidoflow.token', "lf_live_secret\r\nInjected: value");

        try {
            (new HttpLatidoFlowClient)->sync(['monitors' => []]);
            $this->fail('Invalid bearer tokens must fail before request construction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The LatidoFlow token format is invalid.', $exception->getMessage());
            $this->assertStringNotContainsString('lf_live_secret', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_connection_failures_are_rethrown_without_the_authorized_request(): void
    {
        config()->set('latidoflow.http.sync.retry_delays_ms', []);
        Http::fake([
            'https://latidoflow.example.test/*' => Http::failedConnection('private transport detail'),
        ]);

        try {
            (new HttpLatidoFlowClient)->sync(['monitors' => []]);
            $this->fail('Connection failures must use a sanitized package exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('LatidoFlow could not be reached.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('private transport detail', $exception->getMessage());
        }
    }

    public function test_runtime_requests_use_bearer_auth_and_trusted_reference_fields(): void
    {
        Http::fake([
            'https://latidoflow.example.test/api/v1/runtime/runs/start' => Http::response([
                'run_uuid' => '50af68f7-0f73-438d-b7d6-83380ad5c0e9',
            ], 201),
        ]);
        $client = new HttpLatidoFlowClient;

        $runUuid = $client->start($this->reference(), [
            'run_uuid' => '50af68f7-0f73-438d-b7d6-83380ad5c0e9',
            'project_slug' => 'untrusted-project',
            'monitor_slug' => 'untrusted-monitor',
        ]);

        $this->assertSame('50af68f7-0f73-438d-b7d6-83380ad5c0e9', $runUuid);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->hasHeader('Authorization', 'Bearer lf_test_http')
                && $payload['project_slug'] === 'example'
                && $payload['environment_slug'] === 'production'
                && $payload['monitor_slug'] === 'daily-reports'
                && ! array_key_exists('token', $payload);
        });
    }

    public function test_sync_uses_the_configured_application_origin_and_ignores_legacy_endpoint_overrides(): void
    {
        config()->set('latidoflow.sync_endpoint', 'https://sync.example.test/definitions');
        Http::fake([
            'https://latidoflow.example.test/api/v1/monitors/sync' => Http::response($this->syncResponse(), 200),
        ]);

        (new HttpLatidoFlowClient)->sync(['monitors' => []]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://latidoflow.example.test/api/v1/monitors/sync');
    }

    public function test_requests_never_follow_redirects(): void
    {
        $method = new ReflectionMethod(HttpLatidoFlowClient::class, 'request');
        $request = $method->invoke(new HttpLatidoFlowClient, 'runtime');

        $this->assertFalse($request->getOptions()['allow_redirects']);
    }

    public function test_plain_http_requires_an_explicit_local_development_override(): void
    {
        config()->set('latidoflow.endpoint', 'http://127.0.0.1:8080');

        try {
            (new HttpLatidoFlowClient)->sync(['monitors' => []]);
            $this->fail('Bearer tokens must not be sent over plain HTTP by default.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('must use HTTPS', $exception->getMessage());
        }

        Http::assertNothingSent();
        config()->set('latidoflow.allow_insecure_http', true);
        Http::fake([
            'http://127.0.0.1:8080/api/v1/monitors/sync' => Http::response($this->syncResponse(), 200),
        ]);

        $this->assertTrue((new HttpLatidoFlowClient)->sync(['monitors' => []])->successful());
    }

    public function test_http_profiles_reject_unbounded_or_non_finite_configuration(): void
    {
        foreach ([NAN, INF, 3600] as $invalidTimeout) {
            config()->set('latidoflow.http.runtime.timeout_seconds', $invalidTimeout);

            try {
                (new HttpLatidoFlowClient)->start($this->reference(), [
                    'run_uuid' => '50af68f7-0f73-438d-b7d6-83380ad5c0e9',
                ]);
                $this->fail('Runtime timeouts must be finite and bounded.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('timeout_seconds configuration is invalid', $exception->getMessage());
            }
        }

        config()->set('latidoflow.http.runtime.timeout_seconds', 1.5);
        config()->set('latidoflow.http.runtime.retry_delays_ms', [600000]);

        try {
            (new HttpLatidoFlowClient)->start($this->reference(), [
                'run_uuid' => '50af68f7-0f73-438d-b7d6-83380ad5c0e9',
            ]);
            $this->fail('Runtime retry delays must be bounded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('retry delay configuration is invalid', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_runtime_start_requires_a_run_uuid_in_the_response(): void
    {
        Http::fake([
            'https://latidoflow.example.test/api/v1/runtime/runs/start' => Http::response([], 201),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('returned no valid run UUID');

        (new HttpLatidoFlowClient)->start($this->reference(), [
            'run_uuid' => '50af68f7-0f73-438d-b7d6-83380ad5c0e9',
        ]);
    }

    public function test_runtime_redirects_are_treated_as_failures(): void
    {
        Http::fake([
            'https://latidoflow.example.test/api/v1/runtime/runs/start' => Http::response('', 302, [
                'Location' => 'https://unexpected.example.test',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed with HTTP 302');

        (new HttpLatidoFlowClient)->start($this->reference(), [
            'run_uuid' => '50af68f7-0f73-438d-b7d6-83380ad5c0e9',
        ]);
    }

    /**
     * @return array{project_slug: string, environment_slug: string, monitor_slug: string}
     */
    private function reference(): array
    {
        return [
            'project_slug' => 'example',
            'environment_slug' => 'production',
            'monitor_slug' => 'daily-reports',
        ];
    }

    /**
     * @return array{project_uuid: string, environment_uuid: string, monitors: array<int, array<string, string>>}
     */
    private function syncResponse(): array
    {
        return [
            'project_uuid' => 'ad469be1-8131-4d03-8d22-d8384aec5605',
            'environment_uuid' => '55f96f65-ee75-439c-8e28-dd215b2472fb',
            'monitors' => [[
                'uuid' => 'a6b771c2-13d5-47ad-93ec-35626222da24',
                'slug' => 'daily-reports',
                'type' => 'heartbeat',
            ]],
        ];
    }
}
