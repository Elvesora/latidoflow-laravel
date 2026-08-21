<?php

use App\Jobs\LatidoFlowConsumerJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use LatidoFlow\Laravel\Facades\LatidoFlow;

config()->set('latidoflow.queues', [[
    'name' => 'LatidoFlow consumer database queue',
    'connection' => 'database',
    'queue' => 'latidoflow-release',
    'job_class' => LatidoFlowConsumerJob::class,
    'runtime_reporting' => true,
]]);

Artisan::command('latidoflow:fixture-dispatch-queue', function (): void {
    LatidoFlowConsumerJob::dispatch();
});

Artisan::command('latidoflow:fixture-success', function (): void {
    if (! LatidoFlow::output([
        'records_processed' => 42,
        'invoices_failed' => 0,
    ])) {
        throw new RuntimeException('The LatidoFlow output fixture was not recorded.');
    }

    if (! LatidoFlow::evidence([
        'report' => [
            'status' => 'complete',
            'records_processed' => 42,
        ],
    ])) {
        throw new RuntimeException('The LatidoFlow evidence fixture was not recorded.');
    }
});

Schedule::command('latidoflow:fixture-success')
    ->everyMinute()
    ->name('LatidoFlow consumer success');

Artisan::command('latidoflow:fixture-before-failure', function (): void {
    file_put_contents(
        storage_path('framework/latidoflow-failure-command-ran'),
        'The command body should not have run.',
    );
});

Schedule::command('latidoflow:fixture-before-failure')
    ->everyMinute()
    ->name('LatidoFlow consumer before failure')
    ->before(function (): void {
        throw new RuntimeException('The consumer before callback failed.');
    });
