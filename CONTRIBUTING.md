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

## Release procedure

Release the standalone `Elvesora/latidoflow-laravel` repository separately from the hosted application. Existing tags are immutable; v1.0.0 identifies `25306be38c8f6ab96ca95e212a22d4c0f9761d1d` and must never be moved. A new public command is an additive minor release under Semantic Versioning. The doctor is introduced in v1.1.0.

1. Review the exact release delta, including untracked source files. Keep unrelated application or package edits out. Confirm the README command examples, supported PHP/Laravel range, security policy, and dated CHANGELOG match the candidate.
2. Run the checks below from the standalone package. The CI matrix covers PHP 8.3 with lowest supported dependencies and PHP 8.4/8.5 with current dependencies. The local consumer fixture requires Bash, PHP, Composer, curl, and unzip.

```bash
composer validate --strict --no-interaction
composer test
composer audit --no-interaction
php vendor/bin/pint --dirty --format agent
git diff --check
composer archive --format=zip --dir=.build/release-v1.1.0 --file=latidoflow-laravel-v1.1.0
bash tests/Fixtures/Consumer/verify.sh .build/release-v1.1.0/latidoflow-laravel-v1.1.0.zip
```

The CI workflow also checks style and rejects development-only files, private credentials, local paths, and internal-only markers in the distribution. Use the exact named archive above, never the first ZIP found in an old build directory. Static analysis is not a release requirement: no static-analysis dependency or configuration is present. Do not report that gate as passed.

3. Commit only the reviewed candidate after publication is authorized. Require successful CI for that exact commit before creating its new annotated tag and GitHub release. Inspect the remote tag list first; never force-push, reuse, or replace a published version. Let Packagist index the new immutable tag and verify its source reference matches the reviewed commit.
4. Install the exact released version from Packagist in a fresh Laravel 13 application using an empty Composer home and no custom repository or authentication. Retain PHP, Composer, Laravel, package version and source reference, commands, timestamps, and results without credentials. Check package discovery, all four commands, configuration preservation, and absence of development-only files. A locally built archive or path repository is pre-release fixture evidence, not this public distribution gate.
5. In an authorized isolated hosted workspace, use a reveal-once ingestion token without retaining it in logs or evidence. Synchronize one safe named foreground workload, run it, and inspect its newly accepted run through the authenticated workspace or separately scoped management API. Record definition sync and actual execution as different gates. Do not use the ingestion token to read runs.
6. Prove validation and authentication rejection, bounded unreachable-network behavior, and preservation of the workload outcome. Follow the README upgrade/removal procedure to check uninstall, reinstall, and restoration of the previous Composer lockfile. Preserve customer configuration and distinguish adapter rollback from already persisted hosted state.
7. Deploy matching public instructions only after their referenced package is available. Verify the public Composer/doctor commands, the authenticated onboarding instructions, and the REST fallback. Record hosted deployment identity separately from package version.

Keep production credentials, customer identifiers, request bodies, and raw error responses out of release evidence. Five external customer installations and a sub-ten-minute first-signal target require separately observed onboarding results; local fixtures and internal workspaces do not establish those outcomes.
