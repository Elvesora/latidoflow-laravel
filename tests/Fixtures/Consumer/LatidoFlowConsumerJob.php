<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LatidoFlow\Laravel\Facades\LatidoFlow;
use RuntimeException;

final class LatidoFlowConsumerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct()
    {
        $this->onConnection('database');
        $this->onQueue('latidoflow-release');
    }

    public function handle(): void
    {
        $retryMarker = storage_path('framework/latidoflow-queue-retry-marker');

        if (! is_file($retryMarker)) {
            file_put_contents($retryMarker, 'first attempt released');

            if (! LatidoFlow::output(['stale_first_attempt' => 1])) {
                throw new RuntimeException('The LatidoFlow first-attempt output fixture was not recorded.');
            }

            throw new RuntimeException('Private queue retry fixture detail.');
        }

        if (! LatidoFlow::output([
            'queue_records_processed' => 17,
            'queue_records_failed' => 0,
        ])) {
            throw new RuntimeException('The LatidoFlow queue output fixture was not recorded.');
        }

        if (! LatidoFlow::evidence([
            'queue_report' => [
                'status' => 'complete',
                'records_processed' => 17,
            ],
        ])) {
            throw new RuntimeException('The LatidoFlow queue evidence fixture was not recorded.');
        }
    }

    public function backoff(): int
    {
        return 0;
    }
}
