# Changelog

All notable changes to `latidoflow/laravel` will be documented in this file.

The project follows [Semantic Versioning](https://semver.org/).

## 0.1.0 - 2026-08-21

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
