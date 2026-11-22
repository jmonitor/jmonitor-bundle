#!/bin/sh
set -eu

cd "$(dirname "$0")"

PORT="${JMONITOR_E2E_PORT:-8089}"
export JMONITOR_E2E_PORT="$PORT"
export JMONITOR_FIXTURE_FEATURES=embed,easyadmin
BASE="http://127.0.0.1:$PORT"
ADMIN=admin:test
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

css_path="$(printf '%s\n' "$page" | sed -n 's|.*href="\(/bundles/jmonitor/jmonitor\.css?v=[0-9a-f]*\)".*|\1|p' | head -n 1)"
[ -n "$css_path" ] || fail "the page links no /bundles/jmonitor/jmonitor.css?v=..."
css="$(curl -s -o /dev/null -w '%{http_code}' "$BASE$css_path")"
[ "$css" = 200 ] || fail "$css_path answered $css"

for _ in 1 2 3; do
    curl -sf -o /dev/null "$BASE/n-plus-one" || fail "GET /n-plus-one did not return 2xx"
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

for _ in 1 2 3; do
    curl -sf -o /dev/null "$BASE/cached" || fail "GET /cached did not return 2xx"
done
for _ in 1 2; do
    status="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/boom")"
    [ "$status" = 500 ] || fail "GET /boom answered $status instead of 500"
done

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

expect_page /jmonitor/cache 'data-jm-pool="cache.app" data-jm-reads="3"' 'the Cache screen did not list 3 reads of cache.app'
expect_page /jmonitor/exceptions 'data-jm-exception="RuntimeException" data-jm-count="2"' 'the Exceptions screen did not list 2 RuntimeException'

$COMPOSE exec -T -u www-data php php tests/Fixtures/App/bin/console app:work || fail "app:work failed as the PHP-FPM pool user"
expect_page /jmonitor/commands 'data-jm-command="app:work" data-jm-runs="1"' 'the Commands screen did not list 1 run of app:work'

expect_page /back-office/monitoring/requests 'data-jm-route="GET app_hello"' 'the embedded Requests screen did not list "GET app_hello"' "$ADMIN"

status="$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN" -H "Origin: $BASE" --data 'action=ping' "$BASE/back-office/monitoring-action/probe")"
[ "$status" = 303 ] || fail "POST /back-office/monitoring-action/probe answered $status instead of 303"
expect_page /back-office/monitoring/probe 'data-jm-probe-runs="1"' 'the embedded Probe screen did not count 1 run' "$ADMIN"

for path in /back-office/monitoring/ /back-office/monitoring/requests /back-office/monitoring/queries /back-office/monitoring/cache /back-office/monitoring/probe /back-office/monitoring-fragment/ '/back-office/monitoring-fragment/requests?menu=3&period=1h' /back-office/monitoring-fragment/queries /back-office/monitoring-fragment/cache /back-office/monitoring-fragment/probe; do
    curl -sf -o /dev/null -u "$ADMIN" "$BASE$path" || fail "GET $path did not return 2xx"
done
status="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/teapot")"
[ "$status" = 418 ] || fail "GET /teapot answered $status instead of 418"
expect_page /jmonitor/requests 'data-jm-route="GET app_teapot"' 'the Requests screen did not list "GET app_teapot", requested after the embedded pages'
if printf '%s\n' "$page" | grep -q 'back_office_'; then
    fail "the Requests screen lists a request to an embedded page"
fi

cards_of() {
    printf '%s\n' "$1" | grep -o 'data-jm-component="[a-z]*"' | sed 's/.*="\(.*\)"/\1/' | sort | tr '\n' ' '
}

php_read_at() {
    page="$(curl -sf "$BASE/jmonitor/stack?rest=1")" || fail "GET /jmonitor/stack?rest=1 did not return 2xx"
    value="$(printf '%s\n' "$page" | sed -n 's/.*data-jm-component="php"[^>]*data-jm-read-at="\([0-9]*\)".*/\1/p' | head -n 1)"
    [ -n "$value" ] || fail "the PHP card of the Stack screen carries no data-jm-read-at"
    echo "$value"
}

status="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/jmonitor/stack")"
[ "$status" = 200 ] || fail "GET /jmonitor/stack answered $status instead of 200"

complete=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/stack?rest=1")" || fail "GET /jmonitor/stack?rest=1 did not return 2xx"
    if [ "$(cards_of "$page")" = 'php symfony system ' ] && ! printf '%s\n' "$page" | grep -q 'data-jm-reading'; then
        complete=1
        break
    fi
    sleep 1
done
[ "$complete" = 1 ] || fail 'the Stack screen did not show the system, php and symfony cards without data-jm-reading within 30 s'

first="$(php_read_at)"
sleep 1
second="$(php_read_at)"
[ "$first" = "$second" ] || fail "the PHP card was read again within a second: read at $first, then $second"
sleep 11
renewed="$(php_read_at)"
[ "$renewed" -gt "$second" ] || fail "the PHP card was still read at $second after 11 s"

sleep 1
status="$(curl -s -o /dev/null -w '%{http_code}' -H 'Sec-Fetch-Site: same-origin' --data 'action=refresh' "$BASE/jmonitor/_action/stack")"
[ "$status" = 303 ] || fail "POST /jmonitor/_action/stack answered $status instead of 303"
refreshed="$(php_read_at)"
[ "$refreshed" -gt "$renewed" ] || fail "the PHP card was still read at $renewed after a refresh"

detail="$(curl -sf "$BASE/jmonitor/stack/php?inflight=e2e" &
    pid=$!
    for _ in 1 2 3 4 5; do curl -s -o /dev/null "$BASE/slow"; done
    wait "$pid")" || fail "GET /jmonitor/stack/php did not return 2xx"
printf '%s\n' "$detail" | grep -q 'class="jm-panel__title">PHP-FPM<' || fail "the PHP screen has no PHP-FPM section"
for address in inflight /hello/ /slow /n-plus-one /cached /boom /teapot; do
    if printf '%s\n' "$detail" | grep -q "$address"; then
        fail "the PHP screen shows $address, the address of a request"
    fi
done

admin="$(curl -sf -u "$ADMIN" "$BASE/admin/jmonitor/stack")" || fail "GET /admin/jmonitor/stack did not return 2xx"
[ "$(cards_of "$admin")" = 'php symfony system ' ] || fail "the Stack screen inside EasyAdmin shows the cards: $(cards_of "$admin")"

sleep 2
status="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/teapot")"
[ "$status" = 418 ] || fail "GET /teapot answered $status instead of 418"
expect_page /jmonitor/requests 'data-jm-route="GET app_teapot"[^>]*data-jm-requests="2"' 'the Requests screen did not list a second "GET app_teapot" after the Stack screens'
printf '%s\n' "$page" | grep -Eq 'data-jm-route="GET app_hello"[^>]*data-jm-requests="30"' || fail 'the Requests screen does not count 30 requests for "GET app_hello" after the Stack screens'
if printf '%s\n' "$page" | grep -q 'data-jm-route="[A-Z]* [^"]*jmonitor'; then
    fail "the Requests screen lists a request to a JMonitor screen"
fi

echo "OK: 30 requests, an N+1, cache reads, exceptions and a console command recorded under PHP-FPM; an embedded page served, its action run, its requests not recorded; the Stack screen read live, kept 10 s, refreshed, detailed and shown inside EasyAdmin"
