<?php

namespace LatidoFlow\Laravel;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use LatidoFlow\Laravel\Commands\InstallCommand;
use LatidoFlow\Laravel\Commands\SyncCommand;
use LatidoFlow\Laravel\Commands\VerifyCommand;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use LatidoFlow\Laravel\Runtime\ExecutionContext;
use LatidoFlow\Laravel\Runtime\HttpLatidoFlowClient;
use LatidoFlow\Laravel\Runtime\MonitorDefinitionPayload;
use LatidoFlow\Laravel\Runtime\MonitorIdentity;
use LatidoFlow\Laravel\Runtime\OutputStore;
use LatidoFlow\Laravel\Runtime\QueueLifecycleReporter;
use LatidoFlow\Laravel\Runtime\SchedulerLifecycleReporter;

class LatidoFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/latidoflow.php', 'latidoflow');
        $this->app->singleton(LatidoFlowClient::class, HttpLatidoFlowClient::class);
        $this->app->singleton(MonitorIdentity::class);
        $this->app->singleton(MonitorDefinitionPayload::class);
        $this->app->singleton(ExecutionContext::class);
        $this->app->singleton(OutputStore::class);
        $this->app->singleton(SchedulerLifecycleReporter::class);
        $this->app->singleton(QueueLifecycleReporter::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/latidoflow.php' => config_path('latidoflow.php'),
        ], 'latidoflow-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                SyncCommand::class,
                VerifyCommand::class,
            ]);
        }

        $this->registerRuntimeListeners($this->app->make(Dispatcher::class));
    }

    private function registerRuntimeListeners(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $this->app->make(SchedulerLifecycleReporter::class)->starting($event));
        $events->listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => $this->app->make(SchedulerLifecycleReporter::class)->skipped($event));
        $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $this->app->make(SchedulerLifecycleReporter::class)->finished($event));
        $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $this->app->make(SchedulerLifecycleReporter::class)->failed($event));
        $queueEvents = [
            'Illuminate\\Queue\\Events\\JobQueued' => 'queued',
            'Illuminate\\Queue\\Events\\JobProcessing' => 'processing',
            'Illuminate\\Queue\\Events\\JobProcessed' => 'processed',
            'Illuminate\\Queue\\Events\\JobFailed' => 'failed',
            'Illuminate\\Queue\\Events\\JobReleasedAfterException' => 'releasedAfterException',
            'Illuminate\\Queue\\Events\\JobAttempted' => 'attempted',
            'Illuminate\\Queue\\Events\\JobRetryRequested' => 'retryRequested',
            'Illuminate\\Queue\\Events\\JobTimedOut' => 'timedOut',
        ];

        foreach ($queueEvents as $eventClass => $method) {
            if (! class_exists($eventClass)) {
                continue;
            }

            $events->listen($eventClass, fn (object $event) => $this->app->make(QueueLifecycleReporter::class)->{$method}($event));
        }
    }
}
