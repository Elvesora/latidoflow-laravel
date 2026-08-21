#!/usr/bin/env bash

set -euo pipefail

archive="${1:-}"

if [[ -z "$archive" || ! -f "$archive" ]]; then
    printf '%s\n' 'Usage: verify.sh /path/to/package.zip' >&2
    exit 1
fi

fixture_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
package_directory="$(mktemp -d)"
consumer_directory="$(mktemp -d)"
server_pid=''

cleanup() {
    if [[ -n "$server_pid" ]] && kill -0 "$server_pid" 2>/dev/null; then
        kill "$server_pid"
        wait "$server_pid" 2>/dev/null || true
    fi

    rm -rf "$package_directory" "$consumer_directory"
}

trap cleanup EXIT

unzip -q "$archive" -d "$package_directory"

composer create-project laravel/laravel:^13.0 "$consumer_directory" --no-interaction --prefer-dist --no-progress

composer_package_directory="$package_directory"

if command -v cygpath >/dev/null 2>&1; then
    composer_package_directory="$(cygpath -m "$package_directory")"
fi

pushd "$consumer_directory" >/dev/null

composer config repositories.latidoflow "{\"type\":\"path\",\"url\":\"${composer_package_directory}\",\"options\":{\"symlink\":false,\"versions\":{\"latidoflow/laravel\":\"dev-main\"}}}"
composer require latidoflow/laravel:@dev --no-interaction --prefer-dist --no-progress

test ! -L vendor/latidoflow/laravel
test ! -e vendor/latidoflow/laravel/tests

php artisan package:discover --ansi
php artisan latidoflow:install --no-interaction
test -f config/latidoflow.php

artisan_commands="$(php artisan list --raw)"

for command_name in latidoflow:install latidoflow:sync latidoflow:verify; do
    grep -Eq "^${command_name}([[:space:]]|$)" <<< "$artisan_commands"
done

cp "$fixture_directory/console.php" routes/console.php
mkdir -p app/Jobs
cp "$fixture_directory/LatidoFlowConsumerJob.php" app/Jobs/LatidoFlowConsumerJob.php

request_log="$consumer_directory/storage/logs/latidoflow-consumer-requests.ndjson"
server_log="$consumer_directory/storage/logs/latidoflow-consumer-server.log"
unexpected_command_marker="$consumer_directory/storage/framework/latidoflow-failure-command-ran"
fixture_token='lf_release_fixture_token'
port="$(php -r '$socket = stream_socket_server("tcp://127.0.0.1:0", $errorCode, $errorMessage); if ($socket === false) { fwrite(STDERR, $errorMessage); exit(1); } $address = stream_socket_get_name($socket, false); fclose($socket); echo substr(strrchr($address, ":"), 1);')"
php_request_log="$request_log"

if command -v cygpath >/dev/null 2>&1; then
    php_request_log="$(cygpath -m "$request_log")"
fi

LATIDOFLOW_REQUEST_LOG="$php_request_log" \
LATIDOFLOW_FIXTURE_TOKEN="$fixture_token" \
php -S "127.0.0.1:${port}" "$fixture_directory/fake-server.php" >"$server_log" 2>&1 &
server_pid=$!

server_ready=false

for _ in $(seq 1 50); do
    if curl --fail --silent "http://127.0.0.1:${port}/health" >/dev/null; then
        server_ready=true
        break
    fi

    if ! kill -0 "$server_pid" 2>/dev/null; then
        break
    fi

    sleep 0.1
done

if [[ "$server_ready" != true ]]; then
    cat "$server_log" >&2
    exit 1
fi

export APP_NAME='LatidoFlow Consumer'
export APP_ENV=ci
export CACHE_STORE=file
export LATIDOFLOW_CACHE_STORE=file
export LATIDOFLOW_TOKEN="$fixture_token"
export LATIDOFLOW_ENDPOINT="http://127.0.0.1:${port}"
export LATIDOFLOW_ALLOW_INSECURE_HTTP=true
export LATIDOFLOW_PROJECT_SLUG=latidoflow-consumer

php artisan schedule:run --no-interaction
php artisan latidoflow:fixture-dispatch-queue --no-interaction
php artisan queue:work database --queue=latidoflow-release --once --tries=2 --backoff=0 --sleep=0 --no-interaction
php artisan queue:work database --queue=latidoflow-release --once --tries=2 --backoff=0 --sleep=0 --no-interaction

php "$fixture_directory/assert-report.php" "$request_log" "$unexpected_command_marker"

popd >/dev/null
