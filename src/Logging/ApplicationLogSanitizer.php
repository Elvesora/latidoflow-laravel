<?php

namespace LatidoFlow\Laravel\Logging;

use JsonException;
use Stringable;
use Throwable;

final class ApplicationLogSanitizer
{
    private const array SECRET_PATTERNS = [
        'authorization',
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'cookie',
        'credential',
        'private_key',
        'routing_key',
    ];

    private int $nodes = 0;

    public function message(string $message): string
    {
        return trim($message) === '' ? '[empty message]' : $this->redactAndTruncate($message, 10000);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function context(array $context): array
    {
        $this->nodes = 0;
        $clean = $this->value($context, 0);
        $clean = is_array($clean) ? $clean : [];
        $nodes = 0;

        try {
            $withinBytes = strlen(json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) <= 8192;
        } catch (JsonException) {
            $withinBytes = false;
        }

        return $withinBytes && $this->withinContextBounds($clean, 0, $nodes)
            ? $clean
            : ['_latidoflow' => '[truncated-context]'];
    }

    private function value(mixed $value, int $depth): mixed
    {
        $this->nodes++;

        if ($this->nodes > 100) {
            return '[truncated-nodes]';
        }

        if ($depth > 4) {
            return '[truncated-depth]';
        }

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $nestedValue) {
                $normalizedKey = is_int($key) ? $key : mb_strcut($key, 0, 120, 'UTF-8');

                if (is_string($normalizedKey) && $this->isSecretKey($normalizedKey)) {
                    $clean[$normalizedKey] = '[redacted]';

                    continue;
                }

                $clean[$normalizedKey] = $this->value($nestedValue, $depth + 1);
            }

            return $clean;
        }

        if ($value instanceof Throwable) {
            return ['exception_class' => $value::class];
        }

        if ($value instanceof Stringable) {
            return ['object_class' => $value::class];
        }

        if (is_object($value)) {
            return ['object_class' => $value::class];
        }

        if (is_resource($value)) {
            return '[resource]';
        }

        if (is_string($value)) {
            return $this->redactAndTruncate($value, 1024);
        }

        if (is_float($value) && ! is_finite($value)) {
            return '[non-finite-number]';
        }

        return $value;
    }

    private function redactAndTruncate(string $value, int $maxLength): string
    {
        $value = preg_replace(
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            'Bearer [redacted]',
            $value,
        ) ?? $value;
        $secretNames = implode('|', array_map('preg_quote', self::SECRET_PATTERNS));
        $value = preg_replace(
            '/\b('.$secretNames.')\s*([:=])\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;&]+)/i',
            '$1$2[redacted]',
            $value,
        ) ?? $value;
        $value = preg_replace_callback(
            '~https?://[^\s<>"\']+~i',
            function (array $matches): string {
                $parts = parse_url($matches[0]);

                if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                    return '[redacted-url]';
                }

                $url = strtolower((string) $parts['scheme']).'://'.$parts['host'];

                if (isset($parts['port'])) {
                    $url .= ':'.$parts['port'];
                }

                $url .= $parts['path'] ?? '';

                return isset($parts['query']) || isset($parts['user']) || isset($parts['pass'])
                    ? $url.'?[redacted]'
                    : $url;
            },
            $value,
        ) ?? $value;

        $suffix = '...[truncated]';

        return strlen($value) > $maxLength
            ? mb_strcut($value, 0, $maxLength - strlen($suffix), 'UTF-8').$suffix
            : $value;
    }

    private function isSecretKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SECRET_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function withinContextBounds(mixed $value, int $depth, int &$nodes): bool
    {
        if (++$nodes > 100 || $depth > 4) {
            return false;
        }

        if (is_string($value) && mb_strlen($value) > 1024) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if ((is_string($key) && mb_strlen($key) > 1024) || ! $this->withinContextBounds($child, $depth + 1, $nodes)) {
                    return false;
                }
            }
        }

        return true;
    }
}
