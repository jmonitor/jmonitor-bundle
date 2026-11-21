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
    if [ -n "${2:-}" ]; then
        status="$(printf '%s\n' "$headers" | sed -n '1s/^HTTP\/[0-9.]* \([0-9]*\).*/\1/p')"
        [ "$status" = "$2" ] || fail "GET $1 answered $status instead of $2"
    fi
    value="$(printf '%s\n' "$headers" | tr -d '\r' | sed -n 's/^[Xx]-[Ww]orker-[Rr]equests: //p')"
    [ -n "$value" ] || fail "GET $1 carried no X-Worker-Requests header: not served by the worker script"
    [ "$value" -gt "$counter" ] || fail "worker request counter did not increase on GET $1: $value after $counter"
    counter="$value"
}

expect_page() {
    found=0
    for _ in $(seq 1 30); do
        page="$(curl -sf "$BASE$1")" || fail "GET $1 did not return 2xx"
        if printf '%s\n' "$page" | grep -q "$2"; then
            found=1
            break
        fi
        sleep 1
    done
    [ "$found" = 1 ] || fail "$3 within 30 s"
}

captures_round() {
    for _ in 1 2 3; do
        hit /cached 200
    done
    hit /boom 500
    hit /boom 500
    expect_page /jmonitor/cache 'data-jm-pool="cache.app" data-jm-reads="3"' "round $1: the Cache screen did not list 3 reads of cache.app"
    expect_page /jmonitor/exceptions 'data-jm-exception="RuntimeException" data-jm-count="2"' "round $1: the Exceptions screen did not list 2 RuntimeException"
}

# The readiness check flushed, so no flush is due for an hour and the lines stay in the buffer files.
start 3600

sleep 5
for i in 1 2 3 4; do
    hit /hello/idle
    [ "$i" = 4 ] || sleep 3.5
done
hit /slow
hit /teapot
hit /alloc
hit /teapot
hit /n-plus-one
hit /n-plus-one

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

css_path="$(printf '%s\n' "$page" | sed -n 's|.*href="\(/bundles/jmonitor/jmonitor\.css?v=[0-9a-f]*\)".*|\1|p' | head -n 1)"
[ -n "$css_path" ] || fail "the page links no /bundles/jmonitor/jmonitor.css?v=..."
css="$(curl -s -o /dev/null -w '%{http_code}' "$BASE$css_path")"
[ "$css" = 200 ] || fail "$css_path answered $css"

for _ in 1 2 3; do
    hit /n-plus-one
done

repeated=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/queries")" || fail "GET /jmonitor/queries did not return 2xx"
    if printf '%s\n' "$page" | grep -q 'data-jm-nplus1="GET app_n_plus_one"'; then
        repeated=1
        break
    fi
    sleep 1
done
[ "$repeated" = 1 ] || fail 'the Queries screen did not list the N+1 of "GET app_n_plus_one" within 30 s'

# The first round runs in a worker that has already served the requests above, the second in a new one.
captures_round 1
start 1
captures_round 2

$COMPOSE exec -T app php tests/Fixtures/App/bin/console app:work || fail "app:work failed"
expect_page /jmonitor/commands 'data-jm-command="app:work" data-jm-runs="1"' 'the Commands screen did not list 1 run of app:work'

echo "OK: worker mode records correct durations, memory, counts, SQL queries, cache reads, exceptions and a console command"
