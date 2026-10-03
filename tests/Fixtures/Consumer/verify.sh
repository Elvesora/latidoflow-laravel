#!/usr/bin/env bash

set -euo pipefail

archive="${1:-}"

if [[ -z "$archive" || ! -f "$archive" ]]; then
    printf '%s\n' 'Usage: verify.sh /path/to/package.zip' >&2
    exit 1
fi

fixture_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
temporary_root="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
package_directory="$(mktemp -d "${temporary_root}/latidoflow-package.XXXXXX")"
consumer_directory="$(mktemp -d "${temporary_root}/latidoflow-consumer.XXXXXX")"
server_pid=''
background_fixture_directory=''
expected_background_children=0

cleanup() {
    local target resolved_target started_count finished_count
    local background_cleanup_complete=true

    if [[ -n "$background_fixture_directory" && -d "$background_fixture_directory" ]]; then
        touch \
            "$background_fixture_directory/release-outcomes" \
            "$background_fixture_directory/release-overlap"

        for _ in $(seq 1 100); do
            started_count="$(find "$background_fixture_directory" -maxdepth 1 -type f -name 'started-*' | wc -l | tr -d '[:space:]')"
            finished_count="$(find "$background_fixture_directory" -maxdepth 1 -type f -name 'finished-*' | wc -l | tr -d '[:space:]')"

            if [[ "$started_count" -eq "$expected_background_children"
                && "$finished_count" -eq "$expected_background_children" ]]; then
                break
            fi

            sleep 0.1
        done

        if [[ "$started_count" -ne "$expected_background_children"
            || "$finished_count" -ne "$expected_background_children" ]]; then
            printf 'Background fixture cleanup timed out with %s expected, %s started, and %s finished command(s).\n' \
                "$expected_background_children" "$started_count" "$finished_count" >&2
            background_cleanup_complete=false
        fi
    fi

    if [[ -n "$server_pid" ]] && kill -0 "$server_pid" 2>/dev/null; then
        kill "$server_pid"
        wait "$server_pid" 2>/dev/null || true
    fi

    if [[ "$background_cleanup_complete" != true ]]; then
        printf 'Retained background fixture paths because a child process may still be active:\n%s\n%s\n' \
            "$package_directory" "$consumer_directory" >&2

        return 0
    fi

    cd "$temporary_root" || return 1

    for target in "$package_directory" "$consumer_directory"; do
        if [[ ! -d "$target" || -L "$target" ]]; then
            printf '%s\n' 'Refusing fixture cleanup: the temporary target is missing or symbolic.' >&2
            return 1
        fi

        resolved_target="$(cd "$target" && pwd -P)" || return 1

        if [[ "$(dirname "$resolved_target")" != "$temporary_root" ]]; then
            printf '%s\n' 'Refusing fixture cleanup: the resolved target is outside the temporary root.' >&2
            return 1
        fi

        case "${resolved_target##*/}" in
            latidoflow-package.*|latidoflow-consumer.*) ;;
            *)
                printf '%s\n' 'Refusing fixture cleanup: the resolved target has an unexpected name.' >&2
                return 1
                ;;
        esac

        rm -rf -- "$resolved_target"
    done
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

printf '\n// Preserve consumer configuration on repeated installation.\n' >> config/latidoflow.php
configuration_before="$(php -r 'echo hash_file("sha256", "config/latidoflow.php");')"
php artisan latidoflow:install --no-interaction
test "$configuration_before" = "$(php -r 'echo hash_file("sha256", "config/latidoflow.php");')"

artisan_commands="$(php artisan list --raw)"

for command_name in latidoflow:install latidoflow:sync latidoflow:verify latidoflow:doctor; do
    grep -Eq "^${command_name}([[:space:]]|$)" <<< "$artisan_commands"
done

cp "$fixture_directory/console.php" routes/console.php
mkdir -p app/Jobs
cp "$fixture_directory/LatidoFlowConsumerJob.php" app/Jobs/LatidoFlowConsumerJob.php

request_log="$consumer_directory/storage/logs/latidoflow-consumer-requests.ndjson"
server_log="$consumer_directory/storage/logs/latidoflow-consumer-server.log"
unexpected_command_marker="$consumer_directory/storage/framework/latidoflow-failure-command-ran"
background_fixture_directory="$consumer_directory/storage/framework/latidoflow-background-fixture"
fixture_token='lf_release_fixture_token'
port="$(php -r '$socket = stream_socket_server("tcp://127.0.0.1:0", $errorCode, $errorMessage); if ($socket === false) { fwrite(STDERR, $errorMessage); exit(1); } $address = stream_socket_get_name($socket, false); fclose($socket); echo substr(strrchr($address, ":"), 1);')"
php_request_log="$request_log"
php_background_fixture_directory="$background_fixture_directory"

mkdir -p "$background_fixture_directory"

if command -v cygpath >/dev/null 2>&1; then
    php_request_log="$(cygpath -m "$request_log")"
    php_background_fixture_directory="$(cygpath -m "$background_fixture_directory")"
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
export LATIDOFLOW_BACKGROUND_FIXTURE_DIRECTORY="$php_background_fixture_directory"

wait_for_fixture_markers() {
    local pattern="$1"
    local expected_count="$2"
    local description="$3"
    local marker_count

    for _ in $(seq 1 300); do
        marker_count="$(find "$background_fixture_directory" -maxdepth 1 -type f -name "$pattern" | wc -l | tr -d '[:space:]')"

        if [[ "$marker_count" -eq "$expected_count" ]]; then
            return 0
        fi

        sleep 0.1
    done

    printf 'Timed out waiting for %s.\n' "$description" >&2
    find "$background_fixture_directory" -maxdepth 1 -type f -print >&2

    return 1
}

wait_for_fixture_assertion() {
    local mode="$1"

    for _ in $(seq 1 300); do
        if php "$fixture_directory/assert-report.php" "$request_log" "$unexpected_command_marker" "$mode" >/dev/null 2>&1; then
            return 0
        fi

        sleep 0.1
    done

    php "$fixture_directory/assert-report.php" "$request_log" "$unexpected_command_marker" "$mode"
}

php artisan latidoflow:doctor --skip-sync --no-interaction
php "$fixture_directory/assert-report.php" "$request_log" "$unexpected_command_marker" --doctor-read-only
php artisan latidoflow:doctor --no-interaction
php artisan latidoflow:fixture-application-logs --no-interaction
php "$fixture_directory/assert-report.php" "$request_log" "$unexpected_command_marker" --application-logs

LATIDOFLOW_FIXTURE_PHASE=foreground php artisan schedule:run --no-interaction
php artisan latidoflow:fixture-dispatch-queue --no-interaction
php artisan queue:work database --queue=latidoflow-release --once --tries=2 --backoff=0 --sleep=0 --no-interaction
php artisan queue:work database --queue=latidoflow-release --once --tries=2 --backoff=0 --sleep=0 --no-interaction

expected_background_children=2
LATIDOFLOW_FIXTURE_PHASE=background-outcomes php artisan schedule:run --no-interaction
wait_for_fixture_markers 'started-success-*' 1 'the successful background command to start'
wait_for_fixture_markers 'started-exit-7-*' 1 'the exit-7 background command to start'
php "$fixture_directory/assert-report.php" "$request_log" "$unexpected_command_marker" --background-outcomes-pending
touch "$background_fixture_directory/release-outcomes"
wait_for_fixture_assertion --background-outcomes-complete

expected_background_children=3
LATIDOFLOW_FIXTURE_PHASE=background-overlap php artisan schedule:run --no-interaction
expected_background_children=4
LATIDOFLOW_FIXTURE_PHASE=background-overlap php artisan schedule:run --no-interaction
wait_for_fixture_markers 'started-overlap-*' 2 'both overlapping background commands to start'
php "$fixture_directory/assert-report.php" "$request_log" "$unexpected_command_marker" --background-overlap-pending
touch "$background_fixture_directory/release-overlap"
wait_for_fixture_assertion --background-overlap-complete

php "$fixture_directory/assert-report.php" "$request_log" "$unexpected_command_marker"

popd >/dev/null
