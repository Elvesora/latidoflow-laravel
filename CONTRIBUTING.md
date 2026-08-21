# Contributing

Thank you for improving LatidoFlow for Laravel.

## Development setup

```bash
composer update
composer test
composer lint
composer audit
```

Use PHP 8.3 or later. Tests must not send real network requests, credentials, command arguments, serialized job payloads, exception text, or response bodies.

## Pull requests

- Keep changes focused and follow the existing source structure.
- Add PHPUnit coverage for happy paths, failures, and relevant edge cases.
- Keep onboarding and configuration documentation aligned with the real command, scheduler, queue-worker, configuration-cache, and transport behavior.
- Run formatting with `composer format`, then run the complete check set above.
- Update README and CHANGELOG when public behavior changes.

Report sensitive vulnerabilities through the process in [SECURITY.md](SECURITY.md), not through a public issue.
