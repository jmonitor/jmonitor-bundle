#!/bin/sh
set -eu

cd "$(dirname "$0")"

PORT="${JMONITOR_E2E_PORT:-8090}"
export JMONITOR_E2E_PORT="$PORT"
export JMONITOR_FIXTURE_FEATURES=embed,easyadmin,queues
BASE="http://127.0.0.1:$PORT"
ADMIN=admin:test
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
    headers="$(curl -s ${3:+-u "$3"} -D - -o /dev/null "$BASE$1")" || fail "GET $1 failed"
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
        page="$(curl -sf ${4:+-u "$4"} "$BASE$1")" || fail "GET $1 did not return 2xx"
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

cards_of() {
    printf '%s\n' "$1" | grep -o 'data-jm-component="[a-z]*"' | sed 's/.*="\(.*\)"/\1/' | sort | tr '\n' ' '
}

card() {
    printf '%s\n' "$1" | tr '\n' ' ' | sed 's/<article /\n<article /g' | grep "data-jm-component=\"$2\"" | sed 's|</article>.*||'
}

php_read_at() {
    page="$(curl -sf "$BASE/jmonitor/stack?rest=1")" || fail "GET /jmonitor/stack?rest=1 did not return 2xx"
    value="$(printf '%s\n' "$page" | sed -n 's/.*data-jm-component="php"[^>]*data-jm-read-at="\([0-9]*\)".*/\1/p' | head -n 1)"
    [ -n "$value" ] || fail "the PHP card of the Stack screen carries no data-jm-read-at"
    echo "$value"
}

hit /jmonitor/stack 200

complete=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/stack?rest=1")" || fail "GET /jmonitor/stack?rest=1 did not return 2xx"
    if [ "$(cards_of "$page")" = 'frankenphp php symfony system ' ] && ! printf '%s\n' "$page" | grep -q 'data-jm-reading'; then
        complete=1
        break
    fi
    sleep 1
done
[ "$complete" = 1 ] || fail "the Stack screen did not show the frankenphp, system, php and symfony cards without data-jm-reading within 30 s: $(cards_of "$page")"
frankenphp="$(card "$page" frankenphp)"
if printf '%s\n' "$frankenphp" | grep -q 'data-jm-verdict="unknown"'; then
    fail "the FrankenPHP card is unknown: $frankenphp"
fi
printf '%s\n' "$frankenphp" | grep -Eq 'class="jm-component__version">[0-9]+\.[0-9]+\.[0-9]+<' || fail "the FrankenPHP card shows no version: $frankenphp"

first="$(php_read_at)"
sleep 1
second="$(php_read_at)"
[ "$first" = "$second" ] || fail "the PHP card was read again within a second: read at $first, then $second"
sleep 11
renewed="$(php_read_at)"
[ "$renewed" -gt "$second" ] || fail "the PHP card was still read at $second in the worker after 11 s"

sleep 1
status="$(curl -s -o /dev/null -w '%{http_code}' -H 'Sec-Fetch-Site: same-origin' --data 'action=refresh' "$BASE/jmonitor/_action/stack")"
[ "$status" = 303 ] || fail "POST /jmonitor/_action/stack answered $status instead of 303"
refreshed="$(php_read_at)"
[ "$refreshed" -gt "$renewed" ] || fail "the PHP card was still read at $renewed after a refresh"

hit /jmonitor/stack/php 200
hit /jmonitor/stack/frankenphp 200
detail="$(curl -sf "$BASE/jmonitor/stack/frankenphp")" || fail "GET /jmonitor/stack/frankenphp did not return 2xx"
printf '%s\n' "$detail" | grep -q 'class="jm-panel__title"[^>]*>Workers<' || fail "the FrankenPHP screen has no Workers table"
printf '%s\n' "$detail" | grep -q '/app/tests/E2E/frankenphp-worker/worker.php' || fail "the Workers table of the FrankenPHP screen does not name the worker script"

admin="$(curl -s -u "$ADMIN" -w '\n%{http_code}' "$BASE/admin/jmonitor/stack")" || fail "GET /admin/jmonitor/stack failed"
status="$(printf '%s\n' "$admin" | tail -n 1)"
[ "$status" = 200 ] || fail "GET /admin/jmonitor/stack answered $status instead of 200"
[ "$(cards_of "$admin")" = 'frankenphp php symfony system ' ] || fail "the Stack screen inside EasyAdmin shows the cards: $(cards_of "$admin")"

hit /jmonitor/messages 200
counted=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/messages?rest=1")" || fail "GET /jmonitor/messages?rest=1 did not return 2xx"
    queues="$(printf '%s\n' "$page" | tr '\n' ' ' | sed -n 's|.*aria-label="Queues"\(.*\)|\1|p' | sed 's|</section>.*||')"
    if printf '%s\n' "$queues" | grep -Eq '<dt class="jm-fact__label">counted</dt> *<dd class="jm-fact__value"> *<span>3</span>'; then
        counted=1
        break
    fi
    sleep 1
done
[ "$counted" = 1 ] || fail 'the Messages screen did not count 3 messages in the "counted" transport within 30 s'
printf '%s\n' "$queues" | grep -Eq '<dt class="jm-fact__label">hanging</dt> *<dd class="jm-fact__value"> *<span>Uncountable</span> *</dd> *<dd class="jm-fact__hint">did not answer in time</dd>' || fail "the \"hanging\" transport of the Messages screen is not Uncountable for not answering in time: $queues"
sleep 10
processes="$($COMPOSE exec -T app sh -c 'for f in /proc/[0-9]*/cmdline; do tr "\000" " " < "$f" 2>/dev/null; echo; done')" || fail "the processes of the container could not be listed"
printf '%s\n' "$processes" | grep -q 'frankenphp' || fail "the process list of the container does not show FrankenPHP: $processes"
if printf '%s\n' "$processes" | grep -q 'bin/console'; then
    fail "a console process still runs 10 s after the queues were counted: $(printf '%s\n' "$processes" | grep 'bin/console')"
fi

sleep 2
hit /teapot 418
expect_page /jmonitor/requests 'data-jm-route="GET app_teapot"' 'the Requests screen did not list "GET app_teapot" after the Stack and Messages screens'
printf '%s\n' "$page" | grep -Eq 'data-jm-route="GET app_hello"[^>]*data-jm-requests="30"' || fail 'the Requests screen does not count 30 requests for "GET app_hello" after the Stack and Messages screens'
if printf '%s\n' "$page" | grep -q 'data-jm-route="[A-Z]* [^"]*jmonitor'; then
    fail "the Requests screen lists a request to a JMonitor screen"
fi
if printf '%s\n' "$page" | grep -q '(unmatched)'; then
    fail "the Requests screen lists a request that matched no route"
fi
$COMPOSE exec -T app php tests/Fixtures/App/bin/console app:work || fail "app:work failed"
expect_page /jmonitor/commands 'data-jm-command="app:work"' 'the Commands screen did not list app:work, run after the queues were counted'
if printf '%s\n' "$page" | grep -q 'jmonitor:stack:queues'; then
    fail "the Commands screen lists jmonitor:stack:queues"
fi

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

hit /hello/host
expect_page /back-office/monitoring/requests 'data-jm-route="GET app_hello"' 'the embedded Requests screen did not list "GET app_hello"' "$ADMIN"

status="$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN" -H "Origin: $BASE" --data 'action=ping' "$BASE/back-office/monitoring-action/probe")"
[ "$status" = 303 ] || fail "POST /back-office/monitoring-action/probe answered $status instead of 303"
expect_page /back-office/monitoring/probe 'data-jm-probe-runs="1"' 'the embedded Probe screen did not count 1 run' "$ADMIN"

for path in /back-office/monitoring/ /back-office/monitoring/requests /back-office/monitoring/queries /back-office/monitoring/cache /back-office/monitoring/probe /back-office/monitoring-fragment/ '/back-office/monitoring-fragment/requests?menu=3&period=1h' /back-office/monitoring-fragment/queries /back-office/monitoring-fragment/cache /back-office/monitoring-fragment/probe; do
    hit "$path" 200 "$ADMIN"
done
hit /teapot 418
expect_page /jmonitor/requests 'data-jm-route="GET app_teapot"' 'the Requests screen did not list "GET app_teapot", requested after the embedded pages'
if printf '%s\n' "$page" | grep -q 'back_office_'; then
    fail "the Requests screen lists a request to an embedded page"
fi

# Two workers, so that the probe of the status page is not left waiting for the worker that sends it.
export JMONITOR_E2E_WORKERS=2 JMONITOR_FIXTURE_FRANKENPHP_ENDPOINT=http://localhost/no-status-page
start 1
status="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/jmonitor/stack")"
[ "$status" = 200 ] || fail "GET /jmonitor/stack answered $status instead of 200 with a status page that is an address of the application"
probed=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/stack?rest=1")" || fail "GET /jmonitor/stack?rest=1 did not return 2xx"
    frankenphp="$(card "$page" frankenphp)"
    if [ -n "$frankenphp" ] && ! printf '%s\n' "$frankenphp" | grep -q 'data-jm-reading'; then
        probed=1
        break
    fi
    sleep 1
done
[ "$probed" = 1 ] || fail "the FrankenPHP card was still read 30 s after a status page that is an address of the application: $frankenphp"
printf '%s\n' "$frankenphp" | grep -q 'data-jm-verdict="unknown"' || fail "the FrankenPHP card is not unknown when its status page is an address of the application: $frankenphp"
printf '%s\n' "$frankenphp" | grep -q 'http://localhost/no-status-page answered HTTP 404' || fail "the FrankenPHP card does not say that the application answered HTTP 404: $frankenphp"
status="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/teapot")"
[ "$status" = 418 ] || fail "GET /teapot answered $status instead of 418"
expect_page /jmonitor/requests 'data-jm-route="GET app_teapot"' 'the Requests screen did not list "GET app_teapot" after the probe of an address of the application'
if printf '%s\n' "$page" | grep -q '(unmatched)'; then
    fail "the Requests screen lists the probe of an address of the application"
fi

echo "OK: worker mode records correct durations, memory, counts, SQL queries, cache reads, exceptions and a console command; it serves an embedded page and runs its action without recording them; it reads the Stack screen live with FrankenPHP, keeps it 10 s, refreshes it and shows it inside EasyAdmin; it counts the queues in a process that ends, without recording it; a status page that is an address of the application leaves the card unknown and the Requests screen untouched"
