#!/bin/sh
set -eu

cd "$(dirname "$0")"

PORT="${JMONITOR_E2E_PORT:-8089}"
export JMONITOR_E2E_PORT="$PORT"
export JMONITOR_FIXTURE_FEATURES=embed,easyadmin,queues
BASE="http://127.0.0.1:$PORT"
ADMIN=admin:test
COMPOSE="docker compose -p jmonitor-bundle-e2e -f compose.yaml"

slow_loops=
cleanup() {
    [ -z "$slow_loops" ] || kill $slow_loops 2>/dev/null || true
    $COMPOSE down --volumes --remove-orphans >/dev/null 2>&1
}
trap cleanup EXIT
trap 'exit 1' INT TERM

fail() {
    echo "FAIL: $1" >&2
    $COMPOSE logs --no-color >&2 || true
    exit 1
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
    page="$(curl -sf "$BASE/jmonitor/requests")" || fail "GET /jmonitor/requests did not return 2xx"
    if printf '%s\n' "$page" | grep -Eq 'data-jm-route="GET app_hello"[^>]*data-jm-requests="30"'; then
        listed=1
        break
    fi
    sleep 1
done
[ "$listed" = 1 ] || fail 'the Requests screen did not list 30 requests for "GET app_hello" within 30 s'

css_path="$(printf '%s\n' "$page" | sed -n 's|.*href="\(/bundles/jmonitor/jmonitor\.css?v=[0-9a-f]*\)".*|\1|p' | head -n 1)"
[ -n "$css_path" ] || fail "the page links no /bundles/jmonitor/jmonitor.css?v=..."
css="$(curl -s -o /dev/null -w '%{http_code}' "$BASE$css_path")"
[ "$css" = 200 ] || fail "$css_path answered $css"

runs_of() {
    printf '%s\n' "$1" | grep -o 'class="jm-runs__name">[^<]*<' | sed 's/.*>\(.*\)</\1/' | sort | tr '\n' ' '
}

flat() {
    printf '%s\n' "$1" | tr -s '\n ' '  '
}

overview_status='<h1 class="jm-heading__title">Overview</h1> <span class="jm-status__pill jm-status__pill--ok"><span class="jm-status__dot" aria-hidden="true"></span>Answers normally</span>'
requests_box='<span class="jm-box__label">Requests</span> </span> <span class="jm-box__value">30</span>'
counted=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/")" || fail "GET /jmonitor/ did not return 2xx"
    if flat "$page" | grep -q "$requests_box"; then
        counted=1
        break
    fi
    sleep 1
done
[ "$counted" = 1 ] || fail 'the Overview did not count 30 requests in its Requests box within 30 s'
flat "$page" | grep -q "$overview_status" || fail "the Overview does not open on its title and the pill \"Answers normally\""
printf '%s\n' "$page" | grep -q 'class="jm-nav__item" href="/jmonitor/?period=1h" aria-current="page">Overview</a>' || fail "the navigation of the Overview has no current Overview entry"
[ "$(runs_of "$page")" = 'PHP Server Symfony ' ] || fail "the \"Runs on\" lines of the Overview are: $(runs_of "$page")"
page="$(curl -sf "$BASE/jmonitor/overview")" || fail "GET /jmonitor/overview did not return 2xx"
flat "$page" | grep -q "$overview_status" || fail "/jmonitor/overview does not answer the Overview"
admin="$(curl -s -u "$ADMIN" -w '\n%{http_code}' "$BASE/admin/jmonitor")" || fail "GET /admin/jmonitor failed"
status="$(printf '%s\n' "$admin" | tail -n 1)"
[ "$status" = 200 ] || fail "GET /admin/jmonitor answered $status instead of 200"
flat "$admin" | grep -q "$overview_status" || fail "/admin/jmonitor does not open on the title and the pill of the Overview"
flat "$admin" | grep -q "$requests_box" || fail "the Overview inside EasyAdmin does not count 30 requests in its Requests box"

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

expect_page /jmonitor/queries 'class="jm-heading__title jm-lead__title">39 SQL queries in the last hour, ' 'the Queries screen did not sum up 39 SQL queries in its lead'
printf '%s\n' "$page" | grep -q 'aria-label="Worth a look"' || fail 'the Queries screen has no "Worth a look" block with a repeated query'
printf '%s\n' "$page" | grep -q 'data-jm-check="queries.repeated" data-jm-severity="warn"' || fail "the Queries screen does not flag the repeated query"
printf '%s\n' "$page" | grep -q '<strong>Repeated query (N+1)</strong> · GET app_n_plus_one runs the same query 12 times on average, 12 at worst.' || fail "the Queries screen does not name GET app_n_plus_one as the route that repeats a query 12 times"

for _ in 1 2 3; do
    curl -sf -o /dev/null "$BASE/cached" || fail "GET /cached did not return 2xx"
done
for _ in 1 2; do
    status="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/boom")"
    [ "$status" = 500 ] || fail "GET /boom answered $status instead of 500"
done

expect_page /jmonitor/requests 'class="jm-heading__title jm-lead__title">38 requests in the last hour, ' 'the Requests screen did not sum up 38 requests in its lead'
printf '%s\n' "$page" | grep -q 'data-jm-check="requests.server_errors" data-jm-severity="bad"' || fail "the Requests screen does not flag the server errors"
printf '%s\n' "$page" | grep -q '<strong>2 server errors</strong> · GET app_boom answered with a 5xx status 2 times.' || fail "the Requests screen does not name GET app_boom as the route that answered 2 server errors"
printf '%s\n' "$page" | grep -q 'data-jm-check="queries.repeated" data-jm-severity="warn"' || fail "the Requests screen does not flag the query that GET app_n_plus_one repeats"
printf '%s\n' "$page" | grep -q 'data-jm-slot="nav-application"><span class="jm-nav__badge jm-nav__badge--bad"' || fail "the Application entry of the Requests screen carries no critical badge with the server errors"
admin="$(curl -s -u "$ADMIN" -w '\n%{http_code}' "$BASE/admin/jmonitor/requests")" || fail "GET /admin/jmonitor/requests failed"
status="$(printf '%s\n' "$admin" | tail -n 1)"
[ "$status" = 200 ] || fail "GET /admin/jmonitor/requests answered $status instead of 200"
printf '%s\n' "$admin" | grep -q 'class="jm-heading__title jm-lead__title">38 requests in the last hour, ' || fail "the Requests screen inside EasyAdmin does not sum up 38 requests in its lead"

expect_page /jmonitor/cache-http 'data-jm-pool="cache.app" data-jm-reads="3"' 'the Cache & HTTP screen did not list 3 reads of cache.app'
printf '%s\n' "$page" | grep -q 'class="jm-heading__title jm-lead__title">3 cache reads (67 % found) in the last hour.<' || fail "the Cache & HTTP screen does not sum up 3 cache reads in its lead"
printf '%s\n' "$page" | grep -q 'class="jm-checks__empty-title">Nothing to flag<' || fail 'the Cache & HTTP screen does not say "Nothing to flag" without a low hit rate, a call without answer or an error response'
printf '%s\n' "$page" | grep -q 'class="jm-checks__empty-text">No pool with a low hit rate in the last hour.<' || fail "the Cache & HTTP screen does not say that no pool has a low hit rate"
if printf '%s\n' "$page" | grep -q 'data-jm-check='; then
    fail "the Cache & HTTP screen flags a check without a low hit rate, a call without answer or an error response"
fi
expect_page /jmonitor/exceptions 'data-jm-exception="RuntimeException" data-jm-count="2"' 'the Exceptions screen did not list 2 RuntimeException'
printf '%s\n' "$page" | grep -q 'class="jm-heading__title jm-lead__title">2 exceptions in the last hour' || fail "the Exceptions screen does not sum up 2 exceptions in its lead"
printf '%s\n' "$page" | grep -q 'data-jm-check="requests.server_errors" data-jm-severity="bad"' || fail "the Exceptions screen does not show the server errors of the route that threw"
printf '%s\n' "$page" | grep -q '<strong>2 server errors</strong> · GET app_boom answered with a 5xx status 2 times.' || fail "the Exceptions screen does not name GET app_boom as the route that answered 2 server errors"

$COMPOSE exec -T -u www-data php php tests/Fixtures/App/bin/console app:work || fail "app:work failed as the PHP-FPM pool user"
expect_page /jmonitor/background 'data-jm-command="app:work" data-jm-runs="1"' 'the Background screen did not list 1 run of app:work'
curl -sf -o /dev/null "$BASE/dispatch?count=1" || fail "GET /dispatch did not return 2xx"
expect_page /jmonitor/background 'data-jm-message="[^"]*\\Ping"[^>]*data-jm-sent="1"' 'the Background screen did not list 1 sent Ping message'
printf '%s\n' "$page" | grep -q 'class="jm-heading__title jm-lead__title">1 message sent and 1 command run in the last hour.<' || fail "the Background screen does not sum up 1 message sent and 1 command run in its lead"
printf '%s\n' "$page" | grep -q 'class="jm-checks__empty-title">Nothing to flag<' || fail 'the Background screen does not say "Nothing to flag" without a failed message, scheduled run or command run'
printf '%s\n' "$page" | grep -q 'class="jm-checks__empty-text">No failed message, no failed command run in the last hour.<' || fail "the Background screen does not say that no message and no command run failed"
if printf '%s\n' "$page" | grep -q 'data-jm-check='; then
    fail "the Background screen flags a check without a failed message, scheduled run or command run"
fi
admin="$(curl -s -u "$ADMIN" -w '\n%{http_code}' "$BASE/admin/jmonitor/background")" || fail "GET /admin/jmonitor/background failed"
status="$(printf '%s\n' "$admin" | tail -n 1)"
[ "$status" = 200 ] || fail "GET /admin/jmonitor/background answered $status instead of 200"
printf '%s\n' "$admin" | grep -q 'class="jm-heading__title jm-lead__title">1 message sent and 1 command run in the last hour.<' || fail "the Background screen inside EasyAdmin does not sum up 1 message sent and 1 command run in its lead"

expect_page /back-office/monitoring/requests 'data-jm-route="GET app_hello"' 'the embedded Requests screen did not list "GET app_hello"' "$ADMIN"

status="$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN" -H "Origin: $BASE" --data 'action=ping' "$BASE/back-office/monitoring-action/probe")"
[ "$status" = 303 ] || fail "POST /back-office/monitoring-action/probe answered $status instead of 303"
expect_page /back-office/monitoring/probe 'data-jm-probe-runs="1"' 'the embedded Probe screen did not count 1 run' "$ADMIN"

for path in /back-office/monitoring/ /back-office/monitoring/requests /back-office/monitoring/queries /back-office/monitoring/cache-http /back-office/monitoring/background /back-office/monitoring/probe /back-office/monitoring-fragment/ '/back-office/monitoring-fragment/requests?menu=3&period=1h' /back-office/monitoring-fragment/queries /back-office/monitoring-fragment/cache-http /back-office/monitoring-fragment/background /back-office/monitoring-fragment/probe; do
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
printf '%s\n' "$page" | grep -q 'class="jm-components__absent-item">Web server: FrankenPHP, Apache and Nginx were not found serving this page<' || fail 'the "Not detected" line of the Stack screen does not say that no web server was found'
printf '%s\n' "$page" | grep -q 'aria-label="Worth a look"' || fail 'the Stack screen has no "Worth a look" block'
printf '%s\n' "$page" | grep -q '<button type="submit" class="jm-button">Read again now</button>' || fail 'the Stack screen has no "Read again now" button'
for space in application background; do
    printf '%s\n' "$page" | grep -q "data-jm-slot=\"nav-$space\"></span>" || fail "the $space entry of the Stack screen is not an empty slot, while the Requests screen badges the server errors"
done

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

for _ in 1 2 3; do
    (for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/slow"; done) &
    slow_loops="$slow_loops $!"
done
sleep 0.2
before="$(date +%s)"
status="$(curl -s -o /dev/null -w '%{http_code}' -H 'Sec-Fetch-Site: same-origin' --data 'action=refresh' "$BASE/jmonitor/_action/stack/php")"
[ "$status" = 303 ] || fail "POST /jmonitor/_action/stack/php answered $status instead of 303"
detail="$(curl -sf "$BASE/jmonitor/stack/php?inflight=e2e")" || fail "GET /jmonitor/stack/php did not return 2xx"
wait
slow_loops=
printf '%s\n' "$detail" | grep -q 'class="jm-panel__title">PHP-FPM<' || fail "the PHP screen has no PHP-FPM section"
read_at="$(printf '%s\n' "$detail" | sed -n 's/.*data-jm-component="php"[^>]*data-jm-read-at="\([0-9]*\)".*/\1/p' | head -n 1)"
[ -n "$read_at" ] && [ "$read_at" -ge "$before" ] || fail "the PHP screen did not read PHP during its own request: read at ${read_at:-never}, refreshed at $before"
for address in inflight /hello/ /slow /n-plus-one /cached /boom /teapot; do
    if printf '%s\n' "$detail" | grep -q "$address"; then
        fail "the PHP screen shows $address, the address of a request"
    fi
done
pools='/tmp/jmonitor-bundle-tests/*/cache/*/pools'
$COMPOSE exec -T php sh -c "grep -rlqF 'jmonitor.stack.' $pools" || fail "no entry of the stack found under $pools: the cache check has nothing to look at"
searched=0
$COMPOSE exec -T php sh -c "grep -rlqF 'inflight=e2e' $pools" || searched=$?
case "$searched" in
    0) fail "the cache of the stack holds the address of a request in flight" ;;
    1) ;;
    *) fail "the search of the cache of the stack under $pools failed with exit code $searched" ;;
esac

admin="$(curl -s -u "$ADMIN" -w '\n%{http_code}' "$BASE/admin/jmonitor/stack")" || fail "GET /admin/jmonitor/stack failed"
status="$(printf '%s\n' "$admin" | tail -n 1)"
[ "$status" = 200 ] || fail "GET /admin/jmonitor/stack answered $status instead of 200"
[ "$(cards_of "$admin")" = 'php symfony system ' ] || fail "the Stack screen inside EasyAdmin shows the cards: $(cards_of "$admin")"

status="$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN" "$BASE/admin/jmonitor/background")"
[ "$status" = 200 ] || fail "GET /admin/jmonitor/background answered $status instead of 200"
for path in /jmonitor/messages /jmonitor/cache /admin/jmonitor/messages; do
    status="$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN" "$BASE$path")"
    [ "$status" = 404 ] || fail "GET $path answered $status instead of 404"
done

status="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/jmonitor/background")"
[ "$status" = 200 ] || fail "GET /jmonitor/background answered $status instead of 200"
counted=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/background?rest=1")" || fail "GET /jmonitor/background?rest=1 did not return 2xx"
    queues="$(printf '%s\n' "$page" | tr '\n' ' ' | sed -n 's|.*aria-label="Queues"\(.*\)|\1|p' | sed 's|</section>.*||')"
    if printf '%s\n' "$queues" | grep -Eq '<dt class="jm-fact__label">counted</dt> *<dd class="jm-fact__value"> *<span>3</span>'; then
        counted=1
        break
    fi
    sleep 1
done
[ "$counted" = 1 ] || fail 'the Background screen did not count 3 messages in the "counted" transport within 30 s'
printf '%s\n' "$queues" | grep -Eq '<dt class="jm-fact__label">hanging</dt> *<dd class="jm-fact__value"> *<span>Uncountable</span> *</dd> *<dd class="jm-fact__hint">did not answer in time</dd>' || fail "the \"hanging\" transport of the Background screen is not Uncountable for not answering in time: $queues"
sleep 10
processes="$($COMPOSE exec -T php sh -c 'for f in /proc/[0-9]*/cmdline; do tr "\000" " " < "$f" 2>/dev/null; echo; done')" || fail "the processes of the php container could not be listed"
printf '%s\n' "$processes" | grep -q 'php-fpm' || fail "the process list of the php container does not show PHP-FPM: $processes"
if printf '%s\n' "$processes" | grep -q 'bin/console'; then
    fail "a console process still runs 10 s after the queues were counted: $(printf '%s\n' "$processes" | grep 'bin/console')"
fi

sleep 2
status="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/teapot")"
[ "$status" = 418 ] || fail "GET /teapot answered $status instead of 418"
expect_page /jmonitor/requests 'data-jm-route="GET app_teapot"[^>]*data-jm-requests="2"' 'the Requests screen did not list a second "GET app_teapot" after the Stack and Background screens'
printf '%s\n' "$page" | grep -Eq 'data-jm-route="GET app_hello"[^>]*data-jm-requests="30"' || fail 'the Requests screen does not count 30 requests for "GET app_hello" after the Stack and Background screens'
if printf '%s\n' "$page" | grep -q 'data-jm-route="[A-Z]* [^"]*jmonitor'; then
    fail "the Requests screen lists a request to a JMonitor screen"
fi
if printf '%s\n' "$page" | grep -q '(unmatched)'; then
    fail "the Requests screen lists a request that matched no route"
fi
commands="$(curl -sf "$BASE/jmonitor/background")" || fail "GET /jmonitor/background did not return 2xx"
printf '%s\n' "$commands" | grep -q 'data-jm-command="app:work"' || fail "the Background screen no longer lists app:work"
if printf '%s\n' "$commands" | grep -q 'jmonitor:stack:queues'; then
    fail "the Background screen lists jmonitor:stack:queues"
fi

echo "OK: 30 requests, an N+1, cache reads, exceptions, a console command and a message recorded under PHP-FPM; the Overview opens /jmonitor, also inside EasyAdmin, with its pill, its count of requests and the components it runs on; the Requests, Queries, Cache & HTTP, Exceptions and Background screens sum up the period, flag the server errors, also on Exceptions, and the repeated query, and have nothing else to flag, also inside EasyAdmin; an embedded page served, its action run, its requests not recorded; the Stack screen read live without a web server it knows, worth a look, without the badges of the application, kept 10 s, refreshed, detailed and shown inside EasyAdmin; the queues counted by the PHP of the command line, in a process that ends, without recording it"
