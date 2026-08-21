<?php

namespace LatidoFlow\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use LatidoFlow\Laravel\Contracts\LatidoFlowClient;
use LatidoFlow\Laravel\Runtime\MonitorDefinitionPayload;

class SyncCommand extends Command
{
    protected $signature = 'latidoflow:sync {--dry-run : Print the payload without sending it}';

    protected $description = 'Sync Laravel schedule and queue definitions as LatidoFlow heartbeat monitors';

    public function handle(
        Schedule $schedule,
        LatidoFlowClient $client,
        MonitorDefinitionPayload $payloads,
    ): int {
        $payload = $payloads->build($schedule);

        if ($this->option('dry-run')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $response = $client->sync($payload);

        if (! $response->successful()) {
            $this->components->error('LatidoFlow definition sync failed with HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        $this->components->info('LatidoFlow heartbeat definition sync complete.');

        return self::SUCCESS;
    }
}
