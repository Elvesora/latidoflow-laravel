<?php

use App\Jobs\LatidoFlowConsumerJob;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
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

Artisan::command('latidoflow:fixture-before-failure', function (): void {
    file_put_contents(
        storage_path('framework/latidoflow-failure-command-ran'),
        'The command body should not have run.',
    );
});

$runBackgroundFixture = function (string $outcome): int {
    $fixtureDirectory = getenv('LATIDOFLOW_BACKGROUND_FIXTURE_DIRECTORY');

    if (! is_string($fixtureDirectory) || ! is_dir($fixtureDirectory)) {
        throw new RuntimeException('The background fixture directory is unavailable.');
    }

    $processId = getmypid();

    if (! is_int($processId)
        || file_put_contents(
            $fixtureDirectory.DIRECTORY_SEPARATOR."started-{$outcome}-{$processId}",
            (string) $processId,
            LOCK_EX,
        ) === false) {
        throw new RuntimeException('The background fixture start marker could not be written.');
    }

    $releaseGroup = $outcome === 'overlap' ? 'overlap' : 'outcomes';
    $releaseMarker = $fixtureDirectory.DIRECTORY_SEPARATOR."release-{$releaseGroup}";
    $deadline = microtime(true) + 30;

    while (! is_file($releaseMarker)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('The background fixture release marker was not written in time.');
        }

        usleep(50_000);
    }

    if ($outcome === 'exit-7') {
        return 7;
    }

    if (! LatidoFlow::output([
        'background_process_id' => $processId,
        'background_result_code' => $outcome === 'overlap' ? 2 : 1,
    ])) {
        throw new RuntimeException('The background fixture output was not recorded.');
    }

    if (! LatidoFlow::evidence([
        'background_report' => [
            'status' => 'complete',
            'process_id' => $processId,
        ],
    ])) {
        throw new RuntimeException('The background fixture evidence was not recorded.');
    }

    return 0;
};

$markBackgroundFixtureFinished = function (string $outcome): void {
    $fixtureDirectory = getenv('LATIDOFLOW_BACKGROUND_FIXTURE_DIRECTORY');
    $processId = getmypid();

    if (! is_string($fixtureDirectory)
        || ! is_dir($fixtureDirectory)
        || ! is_int($processId)
        || file_put_contents(
            $fixtureDirectory.DIRECTORY_SEPARATOR."finished-{$outcome}-{$processId}",
            (string) $processId,
            LOCK_EX,
        ) === false) {
        throw new RuntimeException('The background fixture finish marker could not be written.');
    }
};

Artisan::command('latidoflow:fixture-background-success', function () use ($runBackgroundFixture): int {
    return $runBackgroundFixture('success');
});

Artisan::command('latidoflow:fixture-background-exit-7', function () use ($runBackgroundFixture): int {
    return $runBackgroundFixture('exit-7');
});

Artisan::command('latidoflow:fixture-background-overlap', function () use ($runBackgroundFixture): int {
    return $runBackgroundFixture('overlap');
});

Event::listen(ScheduledBackgroundTaskFinished::class, function (ScheduledBackgroundTaskFinished $event) use ($markBackgroundFixtureFinished): void {
    $outcome = match ($event->task->description) {
        'LatidoFlow consumer background success' => 'success',
        'LatidoFlow consumer background exit 7' => 'exit-7',
        'LatidoFlow consumer background overlap' => 'overlap',
        default => null,
    };

    if (is_string($outcome)) {
        $markBackgroundFixtureFinished($outcome);
    }
});

$fixturePhase = getenv('LATIDOFLOW_FIXTURE_PHASE');
$registerAllSchedules = ! is_string($fixturePhase) || $fixturePhase === '';

if ($registerAllSchedules || $fixturePhase === 'foreground') {
    Schedule::command('latidoflow:fixture-success')
        ->everyMinute()
        ->name('LatidoFlow consumer success');

    Schedule::command('latidoflow:fixture-before-failure')
        ->everyMinute()
        ->name('LatidoFlow consumer before failure')
        ->before(function (): void {
            throw new RuntimeException('The consumer before callback failed.');
        });
}

if ($registerAllSchedules || $fixturePhase === 'background-outcomes') {
    Schedule::command('latidoflow:fixture-background-success')
        ->everyMinute()
        ->name('LatidoFlow consumer background success')
        ->runInBackground();

    Schedule::command('latidoflow:fixture-background-exit-7')
        ->everyMinute()
        ->name('LatidoFlow consumer background exit 7')
        ->runInBackground();
}

if ($registerAllSchedules || $fixturePhase === 'background-overlap') {
    Schedule::command('latidoflow:fixture-background-overlap')
        ->everyMinute()
        ->name('LatidoFlow consumer background overlap')
        ->runInBackground();
}
