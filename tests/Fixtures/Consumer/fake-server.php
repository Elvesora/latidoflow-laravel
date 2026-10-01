<?php

declare(strict_types=1);

/**
 * @param  array<string, mixed>  $payload
 */
function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);

    exit;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $path === '/health') {
    respond(200, ['status' => 'ok']);
}

$requestLog = getenv('LATIDOFLOW_REQUEST_LOG');
$fixtureToken = getenv('LATIDOFLOW_FIXTURE_TOKEN');

if (! is_string($requestLog) || $requestLog === '' || ! is_string($fixtureToken) || $fixtureToken === '') {
    respond(500, ['message' => 'Fixture server configuration is incomplete.']);
}

$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$authorized = hash_equals('Bearer '.$fixtureToken, $authorization);
$rawPayload = file_get_contents('php://input');

try {
    $payload = is_string($rawPayload) && $rawPayload !== ''
        ? json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR)
        : [];
} catch (JsonException) {
    respond(400, ['message' => 'Invalid JSON.']);
}

if (! is_array($payload)) {
    respond(400, ['message' => 'The request body must be a JSON object.']);
}

$record = json_encode([
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    'path' => $path,
    'authorized' => $authorized,
    'authorization_present' => $authorization !== '',
    'payload' => $payload,
], JSON_THROW_ON_ERROR).PHP_EOL;

if (file_put_contents($requestLog, $record, FILE_APPEND | LOCK_EX) === false) {
    respond(500, ['message' => 'The fixture request could not be recorded.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $path === '/health/monitoring-pipeline') {
    if ($authorization !== '') {
        respond(400, ['message' => 'Public health requests must not contain authorization.']);
    }

    respond(200, ['ok' => true, 'status' => 'healthy']);
}

if (! $authorized) {
    respond(401, ['message' => 'Unauthenticated.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $path === '/api/v1/monitors/sync') {
    $monitors = $payload['monitors'] ?? null;

    if (! is_array($monitors) || $monitors === []) {
        respond(422, ['message' => 'Monitor definitions are required.']);
    }

    $syncedMonitors = [];

    foreach ($monitors as $index => $monitor) {
        if (! is_array($monitor) || ! is_string($monitor['slug'] ?? null) || ($monitor['type'] ?? null) !== 'heartbeat') {
            respond(422, ['message' => 'A valid heartbeat definition is required.']);
        }

        $syncedMonitors[] = [
            'uuid' => sprintf('a6b771c2-13d5-47ad-93ec-%012d', $index + 1),
            'slug' => $monitor['slug'],
            'type' => 'heartbeat',
        ];
    }

    respond(200, [
        'project_uuid' => 'ad469be1-8131-4d03-8d22-d8384aec5605',
        'environment_uuid' => '55f96f65-ee75-439c-8e28-dd215b2472fb',
        'monitors' => $syncedMonitors,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $path === '/api/v1/runtime/runs/start') {
    $runUuid = $payload['run_uuid'] ?? null;

    if (! is_string($runUuid) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $runUuid) !== 1) {
        respond(422, ['message' => 'A valid run UUID is required.']);
    }

    respond(201, ['run_uuid' => $runUuid]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $path === '/api/v1/runtime/runs/queued') {
    respond(202, ['status' => 'accepted']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && is_string($path)
    && preg_match('#\A/api/v1/runs/[0-9a-f-]+/(heartbeat|success|fail)\z#i', $path) === 1) {
    respond(200, ['status' => 'accepted']);
}

respond(404, ['message' => 'Not found.']);
