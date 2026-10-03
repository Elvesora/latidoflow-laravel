<?php

namespace LatidoFlow\Laravel\Contracts;

interface ApplicationLogClient
{
    public function applicationLogs(array $payload): void;
}
