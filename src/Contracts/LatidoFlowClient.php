<?php

namespace LatidoFlow\Laravel\Contracts;

use Illuminate\Http\Client\Response;

interface LatidoFlowClient
{
    public function sync(array $payload): Response;

    public function queued(array $reference, array $payload): void;

    public function start(array $reference, array $payload): string;

    public function skipped(array $reference, array $payload): void;

    public function heartbeat(string $runUuid, array $payload): void;

    public function success(string $runUuid, array $payload): void;

    public function fail(string $runUuid, array $payload): void;
}
