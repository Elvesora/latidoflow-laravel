<?php

namespace LatidoFlow\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use LatidoFlow\Laravel\Runtime\MonitorDefinitionPayload;

class VerifyCommand extends Command
{
    protected $signature = 'latidoflow:verify';

    protected $description = 'Verify the LatidoFlow token by synchronizing the current monitor definitions';

    public function handle(
        Schedule $schedule,
        LatidoFlowClient $client,
        MonitorDefinitionPayload $payloads,
    ): int {
        $response = $client->sync($payloads->build($schedule));

        if ($response->successful()) {
            $this->components->info('LatidoFlow token and current definitions verified. Execution proof still requires a real heartbeat or lifecycle event.');

            return self::SUCCESS;
        }

        $this->components->error('LatidoFlow verification failed with HTTP '.$response->status().'.');

        return self::FAILURE;
    }
}
