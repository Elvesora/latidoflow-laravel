<?php

declare(strict_types=1);

function failFixture(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);

    exit(1);
}

/**
 * @param  array<string, mixed>  $value
 */
function nestedValue(array $value, string $path): mixed
{
    $current = $value;

    foreach (explode('.', $path) as $segment) {
        if (! is_array($current) || ! array_key_exists($segment, $current)) {
            return null;
        }

        $current = $current[$segment];
    }

    return $current;
}

/**
 * @param  list<array<string, mixed>>  $records
 * @return array<string, mixed>
 */
function startRecordFor(array $records, string $monitorSlug): array
{
    foreach ($records as $record) {
        if (($record['path'] ?? null) === '/api/v1/runtime/runs/start'
            && nestedValue($record, 'payload.monitor_slug') === $monitorSlug) {
            return $record;
        }
    }

    failFixture("No start request was recorded for {$monitorSlug}.");
}

/**
 * @param  list<array<string, mixed>>  $records
 * @return list<array<string, mixed>>
 */
function startRecordsFor(array $records, string $monitorSlug): array
{
    return array_values(array_filter(
        $records,
        fn (array $record): bool => ($record['path'] ?? null) === '/api/v1/runtime/runs/start'
            && nestedValue($record, 'payload.monitor_slug') === $monitorSlug,
    ));
}

/**
 * @param  list<array<string, mixed>>  $records
 * @return list<string>
 */
function backgroundRunUuids(array $records, string $monitorSlug, int $expectedCount): array
{
    $starts = startRecordsFor($records, $monitorSlug);

    if (count($starts) !== $expectedCount) {
        failFixture("Expected {$expectedCount} background start request(s) for {$monitorSlug}.");
    }

    $runUuids = [];

    foreach ($starts as $start) {
        $runUuid = nestedValue($start, 'payload.run_uuid');

        if (! is_string($runUuid)
            || nestedValue($start, 'payload.metadata.run_in_background') !== true) {
            failFixture("The background start request for {$monitorSlug} was invalid.");
        }

        $runUuids[] = $runUuid;
    }

    if (count(array_unique($runUuids)) !== $expectedCount) {
        failFixture("The background runs for {$monitorSlug} did not receive distinct UUIDs.");
    }

    return $runUuids;
}

/**
 * @param  list<array<string, mixed>>  $records
 * @return list<array<string, mixed>>
 */
function terminalRecordsForRun(array $records, string $runUuid): array
{
    return array_values(array_filter(
        $records,
        fn (array $record): bool => in_array(
            $record['path'] ?? null,
            [
                "/api/v1/runs/{$runUuid}/success",
                "/api/v1/runs/{$runUuid}/fail",
            ],
            true,
        ),
    ));
}

/**
 * @param  list<array<string, mixed>>  $records
 * @return array<string, mixed>
 */
function backgroundTerminalFor(
    array $records,
    string $runUuid,
    string $terminal,
    int $exitCode,
): array {
    $terminals = terminalRecordsForRun($records, $runUuid);

    if (count($terminals) !== 1
        || ($terminals[0]['path'] ?? null) !== "/api/v1/runs/{$runUuid}/{$terminal}"
        || nestedValue($terminals[0], 'payload.exit_code') !== $exitCode
        || nestedValue($terminals[0], 'payload.metadata.run_in_background') !== true) {
        failFixture("The background run {$runUuid} did not report the expected {$terminal} terminal.");
    }

    return $terminals[0];
}

function assertBackgroundMarker(string $outcome, int $processId): void
{
    $fixtureDirectory = getenv('LATIDOFLOW_BACKGROUND_FIXTURE_DIRECTORY');

    if (! is_string($fixtureDirectory)
        || ! is_file($fixtureDirectory.DIRECTORY_SEPARATOR."started-{$outcome}-{$processId}")) {
        failFixture("The {$outcome} background output did not match a child-process marker.");
    }
}

/**
 * @param  list<array<string, mixed>>  $records
 */
function assertBackgroundRunsArePending(array $records, string $monitorSlug, int $expectedCount): void
{
    foreach (backgroundRunUuids($records, $monitorSlug, $expectedCount) as $runUuid) {
        if (terminalRecordsForRun($records, $runUuid) !== []) {
            failFixture("The background run {$runUuid} reported a terminal before its release marker.");
        }
    }
}

/**
 * @param  list<array<string, mixed>>  $records
 */
function assertBackgroundOutcomesComplete(array $records): void
{
    $successRunUuid = backgroundRunUuids($records, 'latidoflow-consumer-background-success', 1)[0];
    $success = backgroundTerminalFor($records, $successRunUuid, 'success', 0);
    $successProcessId = nestedValue($success, 'payload.output.background_process_id');

    if (! is_int($successProcessId)
        || $successProcessId <= 0
        || nestedValue($success, 'payload.output.background_result_code') !== 1
        || nestedValue($success, 'payload.evidence.background_report.status') !== 'complete'
        || nestedValue($success, 'payload.evidence.background_report.process_id') !== $successProcessId) {
        failFixture('The successful background command did not report its child-process output and evidence.');
    }

    assertBackgroundMarker('success', $successProcessId);

    $failureRunUuid = backgroundRunUuids($records, 'latidoflow-consumer-background-exit-7', 1)[0];
    $failure = backgroundTerminalFor($records, $failureRunUuid, 'fail', 7);

    if (nestedValue($failure, 'payload.message') !== 'Laravel scheduled task failed.') {
        failFixture('The exit-7 background command did not report the expected safe failure message.');
    }
}

/**
 * @param  list<array<string, mixed>>  $records
 */
function assertBackgroundOverlapComplete(array $records): void
{
    $processIds = [];

    foreach (backgroundRunUuids($records, 'latidoflow-consumer-background-overlap', 2) as $runUuid) {
        $success = backgroundTerminalFor($records, $runUuid, 'success', 0);
        $processId = nestedValue($success, 'payload.output.background_process_id');

        if (! is_int($processId)
            || $processId <= 0
            || nestedValue($success, 'payload.output.background_result_code') !== 2
            || nestedValue($success, 'payload.evidence.background_report.status') !== 'complete'
            || nestedValue($success, 'payload.evidence.background_report.process_id') !== $processId) {
            failFixture("The overlapping background run {$runUuid} did not report its child-process output and evidence.");
        }

        assertBackgroundMarker('overlap', $processId);
        $processIds[] = $processId;
    }

    if (count(array_unique($processIds)) !== 2) {
        failFixture('The overlapping background runs did not preserve distinct child-process output.');
    }
}

/**
 * @param  list<array<string, mixed>>  $records
 * @return array<string, mixed>
 */
function recordForPath(array $records, string $path): array
{
    foreach ($records as $record) {
        if (($record['path'] ?? null) === $path) {
            return $record;
        }
    }

    failFixture("No request was recorded for {$path}.");
}

$requestLog = $argv[1] ?? null;
$unexpectedCommandMarker = $argv[2] ?? null;

if (! is_string($requestLog) || ! is_file($requestLog)) {
    failFixture('The fixture request log was not created.');
}

if (! is_string($unexpectedCommandMarker) || $unexpectedCommandMarker === '') {
    failFixture('The unexpected-command marker path was not provided.');
}

$lines = file($requestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if (! is_array($lines)) {
    failFixture('The fixture request log could not be read.');
}

$records = [];

foreach ($lines as $line) {
    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($record)) {
        failFixture('The fixture request log contains a non-object record.');
    }

    $records[] = $record;
}

$pipelineRequests = array_values(array_filter(
    $records,
    fn (array $record): bool => ($record['path'] ?? null) === '/health/monitoring-pipeline',
));

foreach ($pipelineRequests as $record) {
    if (($record['method'] ?? null) !== 'GET'
        || ($record['authorization_present'] ?? null) !== false
        || ($record['authorized'] ?? null) !== false
        || ($record['payload'] ?? null) !== []) {
        failFixture('A public pipeline request included authorization, a payload, or an incorrect method.');
    }
}

if (($argv[3] ?? null) === '--doctor-read-only') {
    if (count($records) !== 1 || count($pipelineRequests) !== 1) {
        failFixture('The read-only doctor must send exactly one unauthenticated pipeline request and no mutations.');
    }

    fwrite(STDOUT, 'Clean Laravel read-only doctor fixture passed.'.PHP_EOL);

    exit(0);
}

$syncRequests = array_values(array_filter(
    $records,
    fn (array $record): bool => ($record['path'] ?? null) === '/api/v1/monitors/sync',
));

if (count($pipelineRequests) !== 2 || count($syncRequests) !== 1) {
    failFixture('Expected two public pipeline checks and one mutating doctor definition sync.');
}

$sync = $syncRequests[0];
$definitions = nestedValue($sync, 'payload.monitors');

if (($sync['method'] ?? null) !== 'POST'
    || ($sync['authorized'] ?? null) !== true
    || ! is_array($definitions)
    || count($definitions) !== 6
    || array_column($definitions, 'slug') !== [
        'latidoflow-consumer-success',
        'latidoflow-consumer-before-failure',
        'latidoflow-consumer-background-success',
        'latidoflow-consumer-background-exit-7',
        'latidoflow-consumer-background-overlap',
        'latidoflow-consumer-database-queue',
    ]) {
    failFixture('The mutating doctor did not synchronize the actual consumer schedule and queue definitions.');
}

$applicationLogRequests = array_values(array_filter(
    $records,
    fn (array $record): bool => ($record['path'] ?? null) === '/api/v1/application-logs',
));

if (count($applicationLogRequests) !== 1) {
    failFixture('Expected exactly one enabled application-log batch and no disabled application-log request.');
}

$applicationLogRequest = $applicationLogRequests[0];
$applicationLogs = nestedValue($applicationLogRequest, 'payload.logs');

if (($applicationLogRequest['method'] ?? null) !== 'POST'
    || ($applicationLogRequest['authorized'] ?? null) !== true
    || nestedValue($applicationLogRequest, 'payload.project_slug') !== 'latidoflow-consumer'
    || nestedValue($applicationLogRequest, 'payload.environment_slug') !== 'ci'
    || nestedValue($applicationLogRequest, 'payload.source') !== 'laravel'
    || ! is_array($applicationLogs)
    || count($applicationLogs) !== 1
    || ! is_array($applicationLogs[0] ?? null)
    || nestedValue($applicationLogs[0], 'level') !== 'warning'
    || nestedValue($applicationLogs[0], 'message') !== 'Release API token=[redacted] was rejected.'
    || nestedValue($applicationLogs[0], 'context.authorization') !== '[redacted]'
    || nestedValue($applicationLogs[0], 'context.attempt') !== 2) {
    failFixture('The enabled application log was not filtered, redacted, or flushed at termination as expected.');
}

$assertionMode = $argv[3] ?? null;

if ($assertionMode === '--application-logs') {
    fwrite(STDOUT, 'Clean Laravel application-log driver fixture passed.'.PHP_EOL);

    exit(0);
}

$records = array_values(array_filter(
    $records,
    fn (array $record): bool => ! in_array($record['path'] ?? null, [
        '/health/monitoring-pipeline',
        '/api/v1/monitors/sync',
        '/api/v1/application-logs',
    ], true),
));

foreach ($records as $record) {
    if (($record['authorized'] ?? null) !== true) {
        failFixture('A fixture request was sent without the expected bearer token.');
    }
}

if ($assertionMode === '--background-outcomes-pending') {
    assertBackgroundRunsArePending($records, 'latidoflow-consumer-background-success', 1);
    assertBackgroundRunsArePending($records, 'latidoflow-consumer-background-exit-7', 1);
    fwrite(STDOUT, 'Background outcome commands are started without premature terminals.'.PHP_EOL);

    exit(0);
}

if ($assertionMode === '--background-outcomes-complete') {
    assertBackgroundOutcomesComplete($records);
    fwrite(STDOUT, 'Background success and exit-7 commands completed.'.PHP_EOL);

    exit(0);
}

if ($assertionMode === '--background-overlap-pending') {
    assertBackgroundRunsArePending($records, 'latidoflow-consumer-background-overlap', 2);
    fwrite(STDOUT, 'Overlapping background commands are started without premature terminals.'.PHP_EOL);

    exit(0);
}

if ($assertionMode === '--background-overlap-complete') {
    assertBackgroundOverlapComplete($records);
    fwrite(STDOUT, 'Overlapping background commands completed with distinct identities and output.'.PHP_EOL);

    exit(0);
}

if ($assertionMode !== null) {
    failFixture('An unknown fixture assertion mode was provided.');
}

if (count($records) !== 17) {
    failFixture('Expected exactly twelve scheduler requests and five database-queue requests, independently of doctor checks.');
}

$successStart = startRecordFor($records, 'latidoflow-consumer-success');
$successRunUuid = nestedValue($successStart, 'payload.run_uuid');

if (! is_string($successRunUuid)) {
    failFixture('The successful schedule did not send a run UUID.');
}

$success = recordForPath($records, "/api/v1/runs/{$successRunUuid}/success");

if (nestedValue($success, 'payload.output.records_processed') !== 42
    || nestedValue($success, 'payload.output.invoices_failed') !== 0
    || nestedValue($success, 'payload.evidence.report.status') !== 'complete'
    || nestedValue($success, 'payload.evidence.report.records_processed') !== 42) {
    failFixture('The successful schedule did not carry cross-process output and evidence.');
}

$failureStart = startRecordFor($records, 'latidoflow-consumer-before-failure');
$failureRunUuid = nestedValue($failureStart, 'payload.run_uuid');

if (! is_string($failureRunUuid)) {
    failFixture('The before-callback failure did not send a fallback start request.');
}

$failure = recordForPath($records, "/api/v1/runs/{$failureRunUuid}/fail");

if (nestedValue($failure, 'payload.message') !== 'Laravel scheduled task failed: RuntimeException') {
    failFixture('The before-callback failure did not produce the expected safe failure category.');
}

if (str_contains(json_encode($records, JSON_THROW_ON_ERROR), 'The consumer before callback failed.')) {
    failFixture('The raw callback exception text leaked into an outbound request.');
}

if (is_file($unexpectedCommandMarker)) {
    failFixture('The scheduled command body ran after its before callback failed.');
}

$queueQueued = null;

foreach ($records as $record) {
    if (($record['path'] ?? null) === '/api/v1/runtime/runs/queued'
        && nestedValue($record, 'payload.monitor_slug') === 'latidoflow-consumer-database-queue') {
        $queueQueued = $record;
        break;
    }
}

if (! is_array($queueQueued)) {
    failFixture('The database queue dispatch did not send a queued request.');
}

$queueRunUuid = nestedValue($queueQueued, 'payload.run_uuid');
$queueStarts = startRecordsFor($records, 'latidoflow-consumer-database-queue');

if (! is_string($queueRunUuid)
    || count($queueStarts) !== 2
    || nestedValue($queueStarts[0], 'payload.run_uuid') !== $queueRunUuid
    || nestedValue($queueStarts[1], 'payload.run_uuid') !== $queueRunUuid
    || nestedValue($queueStarts[0], 'payload.metadata.attempt') !== 1
    || nestedValue($queueStarts[1], 'payload.metadata.attempt') !== 2) {
    failFixture('The database queue did not preserve one run identity across both attempts.');
}

$queueHeartbeat = recordForPath($records, "/api/v1/runs/{$queueRunUuid}/heartbeat");

if (nestedValue($queueHeartbeat, 'payload.metadata.state') !== 'retrying'
    || nestedValue($queueHeartbeat, 'payload.metadata.attempt') !== 1
    || nestedValue($queueHeartbeat, 'payload.metadata.backoff_seconds') !== 0) {
    failFixture('The first database-queue attempt did not report an automatic retry heartbeat.');
}

$queueSuccess = recordForPath($records, "/api/v1/runs/{$queueRunUuid}/success");

if (nestedValue($queueSuccess, 'payload.output.queue_records_processed') !== 17
    || nestedValue($queueSuccess, 'payload.output.queue_records_failed') !== 0
    || nestedValue($queueSuccess, 'payload.output.stale_first_attempt') !== null
    || nestedValue($queueSuccess, 'payload.evidence.queue_report.status') !== 'complete'
    || nestedValue($queueSuccess, 'payload.evidence.queue_report.records_processed') !== 17
    || nestedValue($queueSuccess, 'payload.metadata.attempt') !== 2) {
    failFixture('The retried database queue job did not send its output and evidence.');
}

if (str_contains(json_encode($records, JSON_THROW_ON_ERROR), 'Private queue retry fixture detail.')) {
    failFixture('The raw database-queue exception text leaked into an outbound request.');
}

assertBackgroundOutcomesComplete($records);
assertBackgroundOverlapComplete($records);

fwrite(STDOUT, 'Clean Laravel doctor, foreground scheduler, background scheduler, and database-queue fixtures passed.'.PHP_EOL);
