<?php

namespace LatidoFlow\Laravel\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LatidoFlow\Laravel\Tests\TestCase;

class QueuedListenerDatabaseTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'payments',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('queue.failed.driver', 'null');
        $app['config']->set('latidoflow.token', 'lf_test_listener');
        $app['config']->set('latidoflow.endpoint', 'https://latidoflow.example.test');
        $app['config']->set('latidoflow.queues', [[
            'name' => 'Payment confirmations',
            'connection' => 'database',
            'queue' => 'payments',
            'job_class' => PaymentConfirmationListener::class,
            'runtime_reporting' => true,
            'expect_recurring_runs' => false,
            'start_timeout_seconds' => 300,
        ]]);
    }

    protected function defineRoutes($router): void
    {
        $router->post('/payment-confirmed', function (Request $request): JsonResponse {
            Event::dispatch(new PaymentConfirmedEvent('private-payment-reference', $request->integer('delay')));

            return response()->json(['accepted' => true]);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        PaymentConfirmationListener::$handled = 0;
        Event::listen(PaymentConfirmedEvent::class, PaymentConfirmationListener::class);
        Exceptions::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://latidoflow.example.test/*' => fn (ClientRequest $request) => Http::response([
                'run_uuid' => $request->data()['run_uuid'] ?? null,
            ], 202),
        ]);
    }

    public function test_http_payment_dispatch_reports_a_real_pending_listener_without_starting_a_worker(): void
    {
        $this->postJson('/payment-confirmed')->assertOk()->assertJson(['accepted' => true]);

        $pending = DB::table('jobs')->sole();
        $payload = json_decode($pending->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(CallQueuedListener::class, $payload['data']['commandName']);
        $this->assertSame(PaymentConfirmationListener::class, $payload['displayName']);
        $this->assertNull($pending->reserved_at);
        $this->assertSame(0, $pending->attempts);
        $this->assertSame(0, PaymentConfirmationListener::$handled);
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://latidoflow.example.test/api/v1/runtime/runs/queued'
            && $request['run_uuid'] === $payload['uuid']
            && $request['queue_connection'] === 'database'
            && $request['queue_name'] === 'payments'
            && $request['monitor_slug'] === 'payment-confirmations'
            && data_get($request->data(), 'metadata.job_class') === PaymentConfirmationListener::class
            && ! str_contains($request->body(), 'private-payment-reference')
            && ! str_contains($request->body(), 'commandName'));
    }

    public function test_an_unallowlisted_listener_on_the_same_database_queue_is_not_reported(): void
    {
        Event::listen(PaymentConfirmedEvent::class, UnlistedPaymentListener::class);

        $this->postJson('/payment-confirmed')->assertOk();

        $this->assertSame(2, DB::table('jobs')->whereNull('reserved_at')->where('attempts', 0)->count());
        $this->assertSame(0, PaymentConfirmationListener::$handled);
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => data_get($request->data(), 'metadata.job_class') === PaymentConfirmationListener::class);
        Http::assertNotSent(fn (ClientRequest $request): bool => data_get($request->data(), 'metadata.job_class') === UnlistedPaymentListener::class);
    }

    public function test_a_delayed_listener_reports_the_delay_while_remaining_pending(): void
    {
        $this->freezeTime();

        $this->postJson('/payment-confirmed', ['delay' => 600])->assertOk();

        $pending = DB::table('jobs')->sole();
        $this->assertSame(now()->addSeconds(600)->timestamp, $pending->available_at);
        $this->assertNull($pending->reserved_at);
        $this->assertSame(0, PaymentConfirmationListener::$handled);
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => data_get($request->data(), 'metadata.queue.delay_seconds') === 600);
    }

    public function test_a_later_database_worker_completes_the_same_listener_run(): void
    {
        $this->postJson('/payment-confirmed')->assertOk();
        $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, PaymentConfirmationListener::$handled);

        $this->app->make('queue.worker')->runNextJob('database', 'payments', new WorkerOptions(sleep: 0));

        $this->assertSame(1, PaymentConfirmationListener::$handled);
        $this->assertSame(0, DB::table('jobs')->count());
        Exceptions::assertNothingReported();
        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://latidoflow.example.test/api/v1/runtime/runs/start'
            && $request['run_uuid'] === $payload['uuid']
            && data_get($request->data(), 'metadata.job_class') === PaymentConfirmationListener::class);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://latidoflow.example.test/api/v1/runs/'.$payload['uuid'].'/success'
            && data_get($request->data(), 'metadata.job_class') === PaymentConfirmationListener::class
            && ! str_contains($request->body(), 'private-payment-reference'));
    }
}

final class PaymentConfirmedEvent
{
    public function __construct(public string $privatePaymentReference, public int $delaySeconds) {}
}

class PaymentConfirmationListener implements ShouldQueue
{
    public static int $handled = 0;

    public string $connection = 'database';

    public string $queue = 'payments';

    public function withDelay(PaymentConfirmedEvent $event): int
    {
        return $event->delaySeconds;
    }

    public function handle(PaymentConfirmedEvent $event): void
    {
        self::$handled++;
    }
}

final class UnlistedPaymentListener extends PaymentConfirmationListener {}
