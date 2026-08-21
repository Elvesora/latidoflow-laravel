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

if (count($records) !== 9) {
    failFixture('Expected exactly four scheduler requests and five database-queue requests.');
}

foreach ($records as $record) {
    if (($record['authorized'] ?? null) !== true) {
        failFixture('A fixture request was sent without the expected bearer token.');
    }
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

fwrite(STDOUT, 'Clean Laravel scheduler and database-queue fixtures passed.'.PHP_EOL);
