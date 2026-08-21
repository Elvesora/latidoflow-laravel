<?php

namespace LatidoFlow\Laravel\Runtime;

use Illuminate\Support\Facades\Context;

final class ExecutionContext
{
    private const string CONTEXT_KEY = 'latidoflow.runtime.executions';

    /**
     * @param  array<string, mixed>  $execution
     */
    public function push(array $execution): void
    {
        $stack = Context::getHidden(self::CONTEXT_KEY, []);
        $stack = is_array($stack) && array_is_list($stack) ? $stack : [];
        $stack[] = $execution;

        Context::addHidden(self::CONTEXT_KEY, $stack);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        $stack = Context::getHidden(self::CONTEXT_KEY, []);

        if (! is_array($stack) || ! array_is_list($stack) || $stack === []) {
            return null;
        }

        $execution = $stack[array_key_last($stack)];

        return is_array($execution) ? $execution : null;
    }

    public function remove(string $executionId): void
    {
        $stack = Context::getHidden(self::CONTEXT_KEY, []);

        if (! is_array($stack) || ! array_is_list($stack)) {
            Context::forgetHidden(self::CONTEXT_KEY);

            return;
        }

        $stack = array_values(array_filter(
            $stack,
            fn (mixed $execution): bool => ! is_array($execution)
                || ($execution['execution_id'] ?? null) !== $executionId,
        ));

        if ($stack === []) {
            Context::forgetHidden(self::CONTEXT_KEY);

            return;
        }

        Context::addHidden(self::CONTEXT_KEY, $stack);
    }

    public function clear(): void
    {
        Context::forgetHidden(self::CONTEXT_KEY);
    }
}
