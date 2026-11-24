#!/bin/sh
set -eu

cd "$(dirname "$0")"

PORT="${JMONITOR_E2E_PORT:-8090}"
export JMONITOR_E2E_PORT="$PORT"
export JMONITOR_FIXTURE_FEATURES=embed,easyadmin,queues,mysql,postgresql,redis
BASE="http://127.0.0.1:$PORT"
ADMIN=admin:test
COMPOSE="docker compose -p jmonitor-bundle-e2e-worker -f compose.yaml"
REDIS_CARD="redis.database:${JMONITOR_E2E_REDIS_PORT:-36380}"

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
    JMONITOR_FLUSH_INTERVAL="$1" $COMPOSE up --detach --force-recreate --quiet-pull app
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

$COMPOSE build --quiet app

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
    printf '%s\n' "$1" | grep -o 'data-jm-component="[^"]*"' | sed 's/.*="\([a-z]*\).*/\1/' | sort | tr '\n' ' '
}

read_at() {
    printf '%s\n' "$1" | sed -n 's/.*data-jm-read-at="\([0-9]*\)".*/\1/p' | head -n 1
}

timed() {
    answer="$(curl -s ${2:+-u "$2"} -w '\n%{http_code} %{time_total}' "$BASE$1")" || fail "GET $1 failed"
    took="$(printf '%s\n' "$answer" | tail -n 1)"
    page="$(printf '%s\n' "$answer" | sed '$d')"
    [ "${took%% *}" = 200 ] || fail "GET $1 answered ${took%% *} instead of 200"
    took="${took#* }"
}

faster_than() {
    awk -v took="$took" -v limit="$1" 'BEGIN { exit !(took < limit) }'
}

mysql_sessions() {
    $COMPOSE exec -T -e MYSQL_PWD=root mysql mysql -uroot -N -B -e "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER = 'jmonitor'" || fail "the sessions of the MySQL server could not be counted"
}

postgresql_sessions() {
    $COMPOSE exec -T postgres psql -U jmonitor -d jmonitor -tAc "SELECT COUNT(*) FROM pg_stat_activity WHERE usename = 'jmonitor' AND backend_type = 'client backend' AND pid <> pg_backend_pid()" || fail "the sessions of the PostgreSQL server could not be counted"
}

redis_clients() {
    $COMPOSE exec -T redis redis-cli CLIENT LIST | grep -v ' cmd=client|list ' || true
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
    if [ "$(cards_of "$page")" = 'frankenphp mysql php postgresql postgresql redis symfony system ' ] && ! printf '%s\n' "$page" | grep -q 'data-jm-reading'; then
        complete=1
        break
    fi
    sleep 1
done
[ "$complete" = 1 ] || fail "the Stack screen did not show the frankenphp, mysql, two postgresql, redis, system, php and symfony cards without data-jm-reading within 30 s: $(cards_of "$page")"
mysql="$(card "$page" mysql.main)"
[ "$(printf '%s\n' "$page" | grep -c 'data-jm-component="mysql\.')" = 1 ] || fail "the two connections of one MySQL server do not share one card: $(cards_of "$page")"
printf '%s\n' "$mysql" | grep -Eq 'class="jm-component__version">MySQL [0-9]+\.[0-9]+\.[0-9]+<' || fail "the MySQL card shows no version: $mysql"
if printf '%s\n' "$mysql" | grep -q 'data-jm-verdict="unknown"'; then
    fail "the MySQL card is unknown: $mysql"
fi
for connection in pg_main pg_reports; do
    postgresql="$(card "$page" "postgresql.$connection")"
    printf '%s\n' "$postgresql" | grep -Eq 'class="jm-component__version">PostgreSQL [0-9]+\.[0-9]+<' || fail "the PostgreSQL card of $connection shows no version: $postgresql"
    if printf '%s\n' "$postgresql" | grep -q 'data-jm-verdict="unknown"'; then
        fail "the PostgreSQL card of $connection is unknown: $postgresql"
    fi
done
redis="$(card "$page" "$REDIS_CARD")"
printf '%s\n' "$redis" | grep -Eq 'class="jm-component__version">Redis [0-9]+\.[0-9]+\.[0-9]+<' || fail "the Redis card shows no version: $redis"
if printf '%s\n' "$redis" | grep -q 'data-jm-verdict="unknown"'; then
    fail "the Redis card is unknown: $redis"
fi
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
[ "$(cards_of "$admin")" = 'frankenphp mysql php postgresql postgresql redis symfony system ' ] || fail "the Stack screen inside EasyAdmin shows the cards: $(cards_of "$admin")"

hit '/jmonitor/stack/mysql?connection=reports&rest=1' 200
detail="$(curl -sf "$BASE/jmonitor/stack/mysql?connection=reports&rest=1")" || fail "GET /jmonitor/stack/mysql?connection=reports&rest=1 did not return 2xx"
instances="$(printf '%s\n' "$detail" | tr '\n' ' ' | sed -n 's|.*\(<div class="jm-instances".*\)|\1|p' | sed 's|</div>.*||')"
printf '%s\n' "$instances" | grep -q 'href="/jmonitor/stack/mysql?connection=main"' || fail "the selector of the MySQL detail does not link to main: $instances"
printf '%s\n' "$instances" | grep -q 'href="/jmonitor/stack/mysql?connection=reports" aria-current="true"' || fail "the selector of the MySQL detail does not show reports as the current connection: $instances"
printf '%s\n' "$detail" | grep -q 'aria-label="Database · jmonitor"' || fail "the MySQL detail of reports has no Database section"
status="$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN" "$BASE/admin/jmonitor/stack/mysql?connection=reports")"
[ "$status" = 200 ] || fail "GET /admin/jmonitor/stack/mysql?connection=reports answered $status instead of 200"

hit '/jmonitor/stack/postgresql?connection=pg_reports&rest=1' 200
detail="$(curl -sf "$BASE/jmonitor/stack/postgresql?connection=pg_reports&rest=1")" || fail "GET /jmonitor/stack/postgresql?connection=pg_reports&rest=1 did not return 2xx"
instances="$(printf '%s\n' "$detail" | tr '\n' ' ' | sed -n 's|.*\(<div class="jm-instances".*\)|\1|p' | sed 's|</div>.*||')"
printf '%s\n' "$instances" | grep -q 'href="/jmonitor/stack/postgresql?connection=pg_main"' || fail "the selector of the PostgreSQL detail does not link to pg_main: $instances"
printf '%s\n' "$instances" | grep -q 'href="/jmonitor/stack/postgresql?connection=pg_reports" aria-current="true"' || fail "the selector of the PostgreSQL detail does not show pg_reports as the current connection: $instances"
for section in Usage Traffic Sessions Tables Settings; do
    printf '%s\n' "$detail" | grep -q "aria-label=\"$section\"" || fail "the PostgreSQL detail of pg_reports has no $section section"
done
status="$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN" "$BASE/admin/jmonitor/stack/postgresql?connection=pg_reports")"
[ "$status" = 200 ] || fail "GET /admin/jmonitor/stack/postgresql?connection=pg_reports answered $status instead of 200"

hit /jmonitor/stack/redis 200
detail="$(curl -sf "$BASE/jmonitor/stack/redis")" || fail "GET /jmonitor/stack/redis did not return 2xx"
for section in Usage Traffic Memory Persistence Server; do
    printf '%s\n' "$detail" | grep -q "aria-label=\"$section\"" || fail "the Redis detail has no $section section"
done
printf '%s\n' "$detail" | grep -q 'id="jm-redis-keyspace-title">Keyspace<' || fail "the Redis detail has no Keyspace table"
status="$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN" "$BASE/admin/jmonitor/stack/redis")"
[ "$status" = 200 ] || fail "GET /admin/jmonitor/stack/redis answered $status instead of 200"

sleep 10
sessions="$(mysql_sessions)"
[ "$sessions" = 0 ] || fail "the MySQL server still holds $sessions sessions of the application 10 s after the last display"
sessions="$(postgresql_sessions)"
[ "$sessions" = 0 ] || fail "the PostgreSQL server still holds $sessions sessions of the application 10 s after the last display"
clients="$(redis_clients)"
[ "$(printf '%s\n' "$clients" | grep -c ' cmd=')" = 1 ] || fail "the Redis server holds other connections than the one of the cache of the application 10 s after the last display: $clients"
if printf '%s\n' "$clients" | grep -q ' cmd=info '; then
    fail "the connection that read the Redis server is still open 10 s after the last display: $clients"
fi

$COMPOSE stop mysql >/dev/null 2>&1 || fail "the MySQL server could not be stopped"
timed '/jmonitor/stack?rest=1'
faster_than 3 || fail "the Stack screen took $took s with a stopped MySQL server"
echo "the Stack screen with a stopped MySQL server: $took s"
mysql="$(card "$page" mysql.main)"
before="$(read_at "$mysql")"
printf '%s\n' "$mysql" | grep -q 'data-jm-verdict="bad"' || fail "the MySQL card is not critical with a stopped server: $mysql"
printf '%s\n' "$mysql" | grep -q '>Critical<' || fail "the MySQL card does not say Critical with a stopped server: $mysql"
printf '%s\n' "$page" | grep -q 'MySQL cannot be reached' || fail "the Stack screen does not say that MySQL cannot be reached"
timed '/jmonitor/stack?rest=1'
faster_than 1 || fail "the display after a stopped MySQL server took $took s"
echo "the next display: $took s"
printf '%s\n' "$(card "$page" mysql.main)" | grep -q 'data-jm-verdict="bad"' || fail "the MySQL card is no longer critical on the next display"
hit /teapot 418
expect_page /jmonitor/requests 'data-jm-route="GET app_teapot"[^>]*data-jm-requests="1"' 'the Requests screen did not list 1 "GET app_teapot" after the stopped MySQL server'
printf '%s\n' "$page" | grep -Eq 'data-jm-route="GET app_hello"[^>]*data-jm-requests="30"' || fail 'the Requests screen does not count 30 requests for "GET app_hello" after the stopped MySQL server'
if printf '%s\n' "$page" | grep -q 'data-jm-route="[A-Z]* [^"]*jmonitor'; then
    fail "the Requests screen lists a request to a JMonitor screen after the stopped MySQL server"
fi

$COMPOSE up --detach --wait mysql >/dev/null 2>&1 || fail "the MySQL server could not be started again"
status="$(curl -s -o /dev/null -w '%{http_code}' -H 'Sec-Fetch-Site: same-origin' --data 'action=refresh' "$BASE/jmonitor/_action/stack")"
[ "$status" = 303 ] || fail "POST /jmonitor/_action/stack answered $status instead of 303"
readable=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/stack?rest=1")" || fail "GET /jmonitor/stack?rest=1 did not return 2xx"
    mysql="$(card "$page" mysql.main)"
    after="$(read_at "$mysql")"
    if [ -n "$after" ] && [ "$after" -gt "$before" ] && ! printf '%s\n' "$mysql" | grep -Eq 'data-jm-verdict="(bad|unknown)"'; then
        readable=1
        break
    fi
    sleep 1
done
[ "$readable" = 1 ] || fail "the MySQL card was not read again within 30 s of a refresh, once the server was back: $mysql"

$COMPOSE stop postgres >/dev/null 2>&1 || fail "the PostgreSQL server could not be stopped"
sleep 11
timed '/jmonitor/stack?rest=1'
faster_than 3 || fail "the Stack screen took $took s with a stopped PostgreSQL server"
echo "the Stack screen with a stopped PostgreSQL server: $took s"
for connection in pg_main pg_reports; do
    postgresql="$(card "$page" "postgresql.$connection")"
    printf '%s\n' "$postgresql" | grep -q 'data-jm-verdict="bad"' || fail "the PostgreSQL card of $connection is not critical with a stopped server: $postgresql"
    printf '%s\n' "$postgresql" | grep -q '>Critical<' || fail "the PostgreSQL card of $connection does not say Critical with a stopped server: $postgresql"
done
[ "$(printf '%s\n' "$page" | grep -c 'PostgreSQL cannot be reached')" = 2 ] || fail "the Stack screen does not say twice that PostgreSQL cannot be reached"
before="$(read_at "$(card "$page" postgresql.pg_main)")"
timed '/jmonitor/stack?rest=1'
faster_than 1 || fail "the display after a stopped PostgreSQL server took $took s"
echo "the next display: $took s"
for connection in pg_main pg_reports; do
    printf '%s\n' "$(card "$page" "postgresql.$connection")" | grep -q 'data-jm-verdict="bad"' || fail "the PostgreSQL card of $connection is no longer critical on the next display"
done

$COMPOSE up --detach --wait postgres >/dev/null 2>&1 || fail "the PostgreSQL server could not be started again"
status="$(curl -s -o /dev/null -w '%{http_code}' -H 'Sec-Fetch-Site: same-origin' --data 'action=refresh' "$BASE/jmonitor/_action/stack")"
[ "$status" = 303 ] || fail "POST /jmonitor/_action/stack answered $status instead of 303"
readable=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/stack?rest=1")" || fail "GET /jmonitor/stack?rest=1 did not return 2xx"
    readable=1
    for connection in pg_main pg_reports; do
        postgresql="$(card "$page" "postgresql.$connection")"
        after="$(read_at "$postgresql")"
        if [ -z "$after" ] || [ "$after" -le "$before" ] || printf '%s\n' "$postgresql" | grep -Eq 'data-jm-verdict="(bad|unknown)"'; then
            readable=0
        fi
    done
    [ "$readable" = 1 ] && break
    sleep 1
done
[ "$readable" = 1 ] || fail "the PostgreSQL cards were not read again within 30 s of a refresh, once the server was back: $postgresql"

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
sessions="$(mysql_sessions)"
[ "$sessions" = 0 ] || fail "the MySQL server still holds $sessions sessions of the application once it was read again"

sleep 2
hit /teapot 418
expect_page /jmonitor/requests 'data-jm-route="GET app_teapot"[^>]*data-jm-requests="2"' 'the Requests screen did not list 2 "GET app_teapot" after the Stack and Messages screens'
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

# The Redis server is also the one of cache.app, so of the cache of the Stack screen.
$COMPOSE stop redis >/dev/null 2>&1 || fail "the Redis server could not be stopped"
sleep 11
timed /jmonitor/stack
faster_than 3 || fail "the Stack screen took $took s with a stopped Redis server"
echo "the Stack screen with a stopped Redis server: $took s"
printf '%s\n' "$page" | grep -q 'The live stack needs a working cache' || fail "the Stack screen does not say that its cache is out of order with a stopped Redis server"
[ -n "$(card "$page" "$REDIS_CARD")" ] || fail "the Stack screen shows no Redis card with a stopped Redis server: $(cards_of "$page")"

$COMPOSE up --detach --wait redis >/dev/null 2>&1 || fail "the Redis server could not be started again"
# A phpredis connection that lost its server does not open again: cache.app comes back with a new worker.
start 1
readable=0
for _ in $(seq 1 30); do
    page="$(curl -sf "$BASE/jmonitor/stack?rest=1")" || fail "GET /jmonitor/stack?rest=1 did not return 2xx"
    redis="$(card "$page" "$REDIS_CARD")"
    if [ -n "$redis" ] && ! printf '%s\n' "$page" | grep -q 'The live stack needs a working cache' && ! printf '%s\n' "$redis" | grep -Eq 'data-jm-verdict="(bad|unknown)"|data-jm-reading'; then
        readable=1
        break
    fi
    sleep 1
done
[ "$readable" = 1 ] || fail "the Redis card was not read again within 30 s, once the server was back: $redis"

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

echo "OK: worker mode records correct durations, memory, counts, SQL queries, cache reads, exceptions and a console command; it serves an embedded page and runs its action without recording them; it reads the Stack screen live with FrankenPHP, keeps it 10 s, refreshes it and shows it inside EasyAdmin; it reads one card for two connections of a MySQL server, one card per PostgreSQL connection, one card for a Redis server and their details, closes what it opened, says at once and once only that a stopped MySQL or PostgreSQL server cannot be reached and reads it again once it is back, answers at once with a stopped Redis server that holds its cache, and reads it in a new worker once it is back; it counts the queues in a process that ends, without recording it; a status page that is an address of the application leaves the card unknown and the Requests screen untouched"
