<?php

namespace LatidoFlow\Laravel\Tests;

use LatidoFlow\Laravel\LatidoFlowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LatidoFlowServiceProvider::class];
    }

    /**
     * @param  mixed  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        $app['config']->set('queue.default', 'sync');
    }
}
