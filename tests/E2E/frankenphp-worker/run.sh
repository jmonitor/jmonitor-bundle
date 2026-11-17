#!/bin/sh
set -eu

cd "$(dirname "$0")"

PORT="${JMONITOR_E2E_PORT:-8090}"
export JMONITOR_E2E_PORT="$PORT"
BASE="http://127.0.0.1:$PORT"
COMPOSE="docker compose -p jmonitor-bundle-e2e-worker -f compose.yaml"

cleanup() {
    $COMPOSE down --volumes --remove-orphans >/dev/null 2>&1
}
trap cleanup EXIT
trap 'exit 1' INT TERM

fail() {
    echo "FAIL: $1" >&2
    $COMPOSE logs --no-color >&2 || true
    exit 1
}

start() {
    counter=0
    JMONITOR_FLUSH_INTERVAL="$1" $COMPOSE up --detach --force-recreate --quiet-pull
    ready=0
    for _ in $(seq 1 60); do
        if curl -sf -o /dev/null "$BASE/jmonitor/"; then
            ready=1
            break
        fi
        sleep 1
    done
    [ "$ready" = 1 ] || fail "the application did not answer on $BASE"
}

counter=0
hit() {
    headers="$(curl -s -D - -o /dev/null "$BASE$1")" || fail "GET $1 failed"
    value="$(printf '%s\n' "$headers" | tr -d '\r' | sed -n 's/^[Xx]-[Ww]orker-[Rr]equests: //p')"
    [ -n "$value" ] || fail "GET $1 carried no X-Worker-Requests header: not served by the worker script"
    [ "$value" -gt "$counter" ] || fail "worker request counter did not increase on GET $1: $value after $counter"
    counter="$value"
}

# A slot of a billion seconds does not complete during the run, so the lines stay in the buffer files.
start 1000000000

sleep 5
for i in 1 2 3 4; do
    hit /hello/idle
    [ "$i" = 4 ] || sleep 3.5
done
hit /slow
hit /teapot
hit /alloc
hit /teapot

echo "worker request counter after the timing phase: $counter"
$COMPOSE exec -T app php /app/tests/E2E/frankenphp-worker/check.php || fail "timing, duration or memory assertion failed"

start 1

for _ in $(seq 1 30); do
    hit /hello/world
done
echo "worker request counter after the page phase: $counter"

listed=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/")" || fail "GET /jmonitor/ did not return 2xx"
    if printf '%s\n' "$page" | grep -Eq 'data-jm-route="GET app_hello"[^>]*data-jm-requests="30"'; then
        listed=1
        break
    fi
    sleep 1
done
[ "$listed" = 1 ] || fail 'the page did not list 30 requests for "GET app_hello" within 30 s'

css="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/bundles/jmonitor/jmonitor.css")"
[ "$css" = 200 ] || fail "/bundles/jmonitor/jmonitor.css answered $css"

echo "OK: worker mode records correct durations, memory and counts"
