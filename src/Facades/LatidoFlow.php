<?php

namespace LatidoFlow\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use LatidoFlow\Laravel\Runtime\OutputStore;

/**
 * @method static bool output(array $metrics)
 * @method static bool evidence(array $evidence)
 */
final class LatidoFlow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OutputStore::class;
    }
}
