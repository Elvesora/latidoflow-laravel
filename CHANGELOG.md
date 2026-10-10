# Changelog

All notable changes to `latidoflow/laravel` will be documented in this file.

The project follows [Semantic Versioning](https://semver.org/).

The entries below describe the current repository source; they do not claim that a tagged or published package release already contains unreleased changes.

## v1.3.0 - 2026-10-10

### Added

- Laravel 12 support alongside Laravel 13.
- PHP 8.2 as the minimum supported runtime.
- An opt-in aggregate monitor for failures from queued job classes outside the runtime allowlist.

### Changed

- Expanded CI coverage across PHP 8.2 through 8.5, Laravel 12 and 13, lowest and highest dependency sets, and clean consumer installations for both supported Laravel majors.

## v1.2.0 - 2026-10-04

### Added

- An opt-in `latidoflow` PSR logging driver with a warning default threshold, bounded redacted batches, run correlation, and fail-open flushing at HTTP and queue boundaries.
- Application log forwarding is an opt-in capability for custom transports; v1.1 `LatidoFlowClient` implementations remain compatible and silently skip log batches when they do not implement it.

## v1.1.0

### Added

- Automatic lifecycle reporting for safely named minute-or-longer `command()` and `exec()` schedules in foreground and supported `runInBackground()` modes; background terminal completion uses `ScheduledBackgroundTaskFinished` and Laravel's propagated hidden `Context`.
- Shared cache-backed correlation for background output and evidence, with `unsupported_user` for background schedules configured with `user(...)`.
- `latidoflow:doctor` diagnoses package version, HTTPS origin, token presence, definition construction, cache and queue suitability, public monitoring-pipeline health, and optional mutating definition sync. Blockers return a nonzero exit code. Output is redacted: no tokens, response bodies, headers, secret URLs, local paths, or ingestion-token runtime-evidence reads. `--skip-sync` keeps the run read-only. Pipeline health uses an unauthenticated, redirect-free GET.

### Changed

- Background completion reports only an observed terminal exit code. It does not fabricate a terminal event when `schedule:finish` is unavailable, an after callback throws before Laravel emits `ScheduledBackgroundTaskFinished`, or the definition is gone; unnamed schedules remain non-automatic, sub-minute schedules remain unsupported, and the existing runtime timeout remains the incomplete-run path.

### Fixed

- Corrected the initial release history to identify the published feature set as version 1.0.0.
- Declared the supported 1.x security line and linked the repository's private vulnerability reporting flow.
- Redacted configured definition values and custom transport exception text from doctor diagnostics.
- Checked every runtime queue connection before returning a synchronous-driver warning, so a later missing connection remains a blocker.
- Extended the clean Laravel distribution check to cover all four commands, preserved configuration, unauthenticated pipeline diagnosis, and definition synchronization.

## 1.0.0 - 2026-08-21

### Added

- PHP 8.3 support with lowest-dependency CI coverage.
- Laravel 13 package discovery and configuration publishing.
- Definition synchronization and verification over the application's actual schedule and queue definitions, with the sync housekeeping command excluded.
- Per-event schedule timezones plus fail-fast validation for empty definition sets, sub-minute schedules, duplicate identities, more than 100 definitions, and payloads larger than 32 KiB.
- Named foreground scheduler and allowlisted queue lifecycle reporting; background schedule definitions are synchronized with an explicit `unsupported_background` status instead of claiming cross-process lifecycle support.
- Queue allowlists match Laravel's queued job class independently of a job's custom display name.
- Bounded numeric business-output metrics.
- Bounded typed semantic evidence for successful scheduler and allowlisted queue executions, with independent V1 output compatibility.
- Slug-keyed versioned semantic-check and alert-truth definition contracts with preserve-on-omission and explicit-clear semantics.
- Separate bounded HTTP profiles for operator-driven synchronization and fail-open runtime lifecycle reporting.
- Redirect-free HTTP transport through one validated LatidoFlow application origin, sanitized connection failures, bearer-token header validation, and successful-response contract checks.
- Scheduler metadata excludes command arguments and command-derived fingerprints; unnamed schedules require unique configured names when more than one is synchronized.
- Scheduler execution UUIDs are generated without relying on an optional Illuminate Support suggestion.
- Scheduled-workload output rejects process-local cache drivers so a child command cannot claim to persist evidence that the scheduler process cannot read.
- Cache cleanup is retried after transient reads without reporting the same cache outage twice.
- Queue attempts clear stale output before processing and clean up on Laravel timeout events; scheduler failures are reported even when an earlier user callback fails before execution.
- HTTP timeout and retry configuration is finite and bounded, and bearer tokens require HTTPS unless a local-development override is explicitly enabled.
- Manual `queue:retry` replays receive a fresh monitoring run identity instead of reusing an already-terminal failed run.
- Sync response monitor entries and definition timing fields are validated against the public ingestion contract.
- Asynchronous queue jobs clear inherited LatidoFlow context before allowlist matching, preventing nested child output from leaking into a parent run.
