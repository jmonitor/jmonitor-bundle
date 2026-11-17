#!/bin/sh
set -eu

cd "$(dirname "$0")"

PORT="${JMONITOR_E2E_PORT:-8089}"
export JMONITOR_E2E_PORT="$PORT"
BASE="http://127.0.0.1:$PORT"
COMPOSE="docker compose -p jmonitor-bundle-e2e -f compose.yaml"

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

$COMPOSE up --detach --quiet-pull

ready=0
for _ in $(seq 1 60); do
    if curl -sf -o /dev/null "$BASE/jmonitor/"; then
        ready=1
        break
    fi
    sleep 1
done
[ "$ready" = 1 ] || fail "the application did not answer on $BASE"

for _ in $(seq 1 30); do
    curl -sf -o /dev/null "$BASE/hello/world" || fail "GET /hello/world did not return 2xx"
done

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

echo "OK: 30 requests recorded under PHP-FPM"
