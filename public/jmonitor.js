(function () {
    'use strict';

    var STORAGE_KEY = 'jmonitor.live';
    var INTERVAL = 10000;
    var FAST_DELAY = 2000;
    var FAST_TRIES = 5;
    var SECOND = 1000;
    var PAGE_PARAMETERS = ['period', 'sort', 'dir'];
    var ROWS = '.jm-subnav, .jm-instances__row';
    var root = document.querySelector('[data-jm-live]');

    revealCurrentScreen(document.querySelector('.jm-nav'));
    revealCurrentTabs(document);
    revealCurrentInstances(document);

    function revealCurrentScreen(nav) {
        reveal(nav, nav ? nav.querySelector('[aria-current="page"]') : null);
    }

    function revealCurrentTabs(scope) {
        var navs = scope.querySelectorAll('.jm-subnav');
        for (var n = 0; n < navs.length; n++) {
            revealCurrentScreen(navs[n]);
        }
    }

    function revealCurrentInstances(scope) {
        var rows = scope.querySelectorAll('.jm-instances__row');
        for (var r = 0; r < rows.length; r++) {
            reveal(rows[r], rows[r].querySelector('[aria-current="true"]'));
        }
    }

    function reveal(nav, current) {
        if (current && nav.scrollWidth > nav.clientWidth) {
            nav.scrollLeft += current.getBoundingClientRect().left - nav.getBoundingClientRect().left - (nav.clientWidth - current.offsetWidth) / 2;
        }
    }

    if (!root) {
        return;
    }

    var url = root.getAttribute('data-jm-fragment-url');
    var content = root.querySelector('[data-jm-content]');
    var toggle = document.querySelector('[data-jm-live-toggle]');
    var enabled = readPreference();
    var failed = false;
    var receivedAt = Date.now();
    var ageTimer = null;
    var timer = null;
    var inFlight = false;
    var fastTimer = null;
    var fastTries = 0;
    var fastRefused = false;
    var liveRefused = false;

    if (!url || !content) {
        return;
    }

    var scope = root.closest('.jm') || document;

    function readPreference() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) !== 'off';
        } catch (e) {
            return true;
        }
    }

    function writePreference(value) {
        try {
            window.localStorage.setItem(STORAGE_KEY, value ? 'on' : 'off');
        } catch (e) {
            // storage blocked: the choice lasts until the page is left
        }
    }

    function markFailed(value) {
        failed = value;
        render();
    }

    function age() {
        return Math.max(0, Date.now() - receivedAt);
    }

    function duration(milliseconds) {
        var seconds = Math.floor(milliseconds / SECOND);

        if (seconds < 60) {
            return seconds + ' s';
        }

        return seconds < 3600 ? Math.floor(seconds / 60) + ' min' : Math.floor(seconds / 3600) + ' h';
    }

    function render() {
        if (!toggle) {
            return;
        }

        var elapsed = age();
        var fresh = elapsed < SECOND;
        var state = liveRefused || (enabled && failed) ? 'failed' : enabled ? 'live' : 'paused';
        var texts = {
            live: ['Live', fresh ? 'updated just now' : 'updated ' + duration(elapsed) + ' ago'],
            paused: ['Paused', fresh ? 'data from just now' : 'data from ' + duration(elapsed) + ' ago'],
            failed: ['Not updated', 'for ' + duration(elapsed) + (liveRefused ? '' : ' · retrying')],
        };

        toggle.setAttribute('data-jm-live-state', state);
        toggle.setAttribute('aria-pressed', enabled && !liveRefused ? 'true' : 'false');
        toggle.title = state !== 'failed' ? '' : liveRefused ? 'Refresh stopped: turn Live on again or reload the page' : 'Last refresh failed, retrying';
        writeText('[data-jm-live-word]', texts[state][0]);
        writeText('[data-jm-live-age]', texts[state][1]);
    }

    function writeText(selector, text) {
        var element = toggle.querySelector(selector);

        if (element && element.textContent !== text) {
            element.textContent = text;
        }
    }

    function tickAge() {
        window.clearTimeout(ageTimer);
        ageTimer = null;
        render();

        if (toggle && !document.hidden && root.isConnected) {
            ageTimer = window.setTimeout(tickAge, SECOND - age() % SECOND);
        }
    }

    function restartProgress() {
        var progress = toggle ? toggle.querySelector('[data-jm-live-progress]') : null;

        if (progress) {
            progress.style.animation = 'none';
            void progress.offsetWidth;
            progress.style.animation = '';
        }
    }

    function hasSelectionInside(element) {
        var selection = window.getSelection ? window.getSelection() : null;

        return selection !== null && !selection.isCollapsed && selection.containsNode(element, true);
    }

    function openKeys() {
        var keys = [];
        var details = content.querySelectorAll('details[data-jm-key]');

        for (var i = 0; i < details.length; i++) {
            if (details[i].open) {
                keys.push(details[i].getAttribute('data-jm-key'));
            }
        }

        return keys;
    }

    function reopen(keys) {
        if (keys.length === 0) {
            return;
        }

        var details = content.querySelectorAll('details[data-jm-key]');

        for (var i = 0; i < details.length; i++) {
            if (keys.indexOf(details[i].getAttribute('data-jm-key')) !== -1) {
                details[i].open = true;
            }
        }
    }

    function fragmentUrl() {
        var fragment = new URL(url, window.location.href);
        var page = new URL(window.location.href).searchParams;

        for (var i = 0; i < PAGE_PARAMETERS.length; i++) {
            if (page.has(PAGE_PARAMETERS[i])) {
                fragment.searchParams.set(PAGE_PARAMETERS[i], page.get(PAGE_PARAMETERS[i]));
            }
        }

        return fragment.toString();
    }

    function findIn(parent, selector, attribute, value) {
        var candidates = parent.querySelectorAll(selector);

        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i].getAttribute(attribute) === value) {
                return candidates[i];
            }
        }

        return null;
    }

    function focusedIdentity() {
        var active = document.activeElement;

        if (!active || !content.contains(active)) {
            return null;
        }

        var details = active.tagName === 'SUMMARY' ? active.parentNode : null;

        return {
            id: active.id || null,
            key: details && details.tagName === 'DETAILS' ? details.getAttribute('data-jm-key') : null,
            href: active.getAttribute('href'),
        };
    }

    function restoreFocus(identity) {
        if (!identity) {
            return;
        }

        var element = identity.id ? findIn(content, '[id]', 'id', identity.id) : null;

        if (!element && identity.key) {
            var details = findIn(content, 'details[data-jm-key]', 'data-jm-key', identity.key);
            element = details ? details.querySelector('summary') : null;
        }

        if (!element && identity.href) {
            element = findIn(content, 'a[href]', 'href', identity.href);
        }

        if (element) {
            element.focus({ preventScroll: true });
        }
    }

    function focusedRow() {
        var active = document.activeElement;
        var row = active && content.contains(active) ? active.closest(ROWS) : null;

        return row ? { index: Array.prototype.indexOf.call(content.querySelectorAll(ROWS), row), scrollLeft: row.scrollLeft } : null;
    }

    function revealCurrentRows(kept) {
        var rows = content.querySelectorAll(ROWS);

        for (var r = 0; r < rows.length; r++) {
            if (!rows[r].contains(document.activeElement)) {
                reveal(rows[r], rows[r].querySelector('[aria-current]'));
            } else if (kept && kept.index === r) {
                rows[r].scrollLeft = kept.scrollLeft;
            }
        }
    }

    function replaceContent(html) {
        var wraps = content.querySelectorAll('.jm-table-wrap');
        var scrollLefts = [];
        for (var w = 0; w < wraps.length; w++) {
            scrollLefts.push(wraps[w].scrollLeft);
        }
        var focused = focusedIdentity();
        var row = focusedRow();
        var opened = openKeys();
        var node = currentNode();

        content.innerHTML = html;
        reopen(opened);

        wraps = content.querySelectorAll('.jm-table-wrap');
        for (w = 0; w < wraps.length && w < scrollLefts.length; w++) {
            wraps[w].scrollLeft = scrollLefts[w];
        }

        restoreFocus(focused);
        revealCurrentRows(row);
        fillSlots();
        tellNodeChange(node);
        receivedAt = Date.now();
        tickAge();

        if (!isReading()) {
            fastTries = 0;
        }
    }

    function fillSlots() {
        var templates = content.querySelectorAll('template[data-jm-slot-content]');

        for (var i = 0; i < templates.length; i++) {
            var slot = findIn(scope, '[data-jm-slot]', 'data-jm-slot', templates[i].getAttribute('data-jm-slot-content'));

            if (slot) {
                slot.innerHTML = templates[i].innerHTML;
            }
        }
    }

    function currentNode() {
        var element = content.querySelector('[data-jm-node]');

        return element ? element.getAttribute('data-jm-node') : '';
    }

    function tellNodeChange(previous) {
        var node = currentNode();
        var mention = content.querySelector('[data-jm-node-changed]');

        if (previous !== '' && node !== '' && previous !== node && mention) {
            mention.hidden = false;
        }
    }

    function isReading() {
        return content.querySelector('[data-jm-reading]') !== null;
    }

    function hasFastTriesLeft() {
        return !fastRefused && !liveRefused && isReading() && fastTries < FAST_TRIES;
    }

    function scheduleFastTry(delay) {
        window.clearTimeout(fastTimer);
        fastTimer = null;

        if (!document.hidden && root.isConnected && hasFastTriesLeft()) {
            fastTimer = window.setTimeout(fastTry, delay);
        }
    }

    function fastTry() {
        fastTimer = null;

        if (!root.isConnected) {
            stop();

            return;
        }

        if (inFlight || document.hidden) {
            return;
        }

        fastTries++;

        if (hasSelectionInside(content)) {
            settle();

            return;
        }

        load(true);
    }

    function settle() {
        scheduleFastTry(FAST_DELAY);
        showRestLink();
    }

    function showRestLink() {
        var link = content.querySelector('[data-jm-rest]');

        if (link) {
            (link.closest('.jm-rest') || link).hidden = enabled || hasFastTriesLeft();
        }
    }

    function refresh() {
        if (!root.isConnected) {
            stop();

            return;
        }

        if (!enabled || liveRefused || inFlight || document.hidden || hasSelectionInside(content)) {
            return;
        }

        load(false);
    }

    function load(forced) {
        var target;

        function mark(failed) {
            if (enabled || !forced) {
                markFailed(failed);
            }
        }

        try {
            target = fragmentUrl();
        } catch (e) {
            mark(true);
            settle();

            return;
        }

        inFlight = true;

        fetch(target, {
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'manual',
            headers: { Accept: 'text/html' },
        })
            .then(function (response) {
                if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 403 || response.status === 404) {
                    if (forced) {
                        fastRefused = true;
                        mark(true);

                        return null;
                    }

                    liveRefused = true;
                    stop();

                    // the page loads but its content is refused: a reload would only start over
                    if (fastRefused) {
                        mark(true);
                    } else {
                        window.location.reload();
                    }

                    return null;
                }

                mark(!response.ok);

                return response.ok ? response.text() : null;
            })
            .then(function (html) {
                if (html !== null && (enabled || forced) && !hasSelectionInside(content)) {
                    replaceContent(html);
                }
            })
            .catch(function () {
                mark(true);
            })
            .then(function () {
                inFlight = false;
                settle();
            });
    }

    function start() {
        stop();

        if (enabled && !liveRefused && !document.hidden) {
            timer = window.setInterval(function () {
                restartProgress();
                refresh();
            }, INTERVAL);
            restartProgress();
        }
    }

    function stop() {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    if (toggle) {
        toggle.hidden = false;

        toggle.addEventListener('click', function () {
            enabled = liveRefused || !enabled;
            liveRefused = false;
            writePreference(enabled);
            markFailed(false);
            start();
            refresh();
            showRestLink();
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stop();
        } else {
            start();
            refresh();
        }

        tickAge();
        scheduleFastTry(0);
    });

    start();
    tickAge();
    showRestLink();
    scheduleFastTry(0);
})();
