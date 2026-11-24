(function () {
    'use strict';

    var STORAGE_KEY = 'jmonitor.live';
    var INTERVAL = 10000;
    var FAST_DELAY = 2000;
    var FAST_TRIES = 5;
    var PAGE_PARAMETERS = ['period', 'sort', 'dir'];
    var root = document.querySelector('[data-jm-live]');

    revealCurrentScreen(document.querySelector('.jm-nav'));
    revealCurrentScreen(document.querySelector('.jm-subnav'));
    revealCurrentInstances(document);

    function revealCurrentScreen(nav) {
        reveal(nav, nav ? nav.querySelector('[aria-current="page"]') : null);
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
    var input = toggle ? toggle.querySelector('input') : null;
    var enabled = readPreference();
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

    function markFailed(failed) {
        if (!toggle) {
            return;
        }

        toggle.classList.toggle('is-failed', failed);
        toggle.title = !failed ? '' : liveRefused ? 'Refresh stopped: turn Live on again or reload the page' : 'Last refresh failed, retrying';
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

    function replaceContent(html) {
        var wraps = content.querySelectorAll('.jm-table-wrap');
        var scrollLefts = [];
        for (var w = 0; w < wraps.length; w++) {
            scrollLefts.push(wraps[w].scrollLeft);
        }
        var focused = focusedIdentity();
        var opened = openKeys();
        var node = currentNode();

        content.innerHTML = html;
        reopen(opened);

        wraps = content.querySelectorAll('.jm-table-wrap');
        for (w = 0; w < wraps.length && w < scrollLefts.length; w++) {
            wraps[w].scrollLeft = scrollLefts[w];
        }

        revealCurrentScreen(content.querySelector('.jm-subnav'));
        revealCurrentInstances(content);
        restoreFocus(focused);
        fillSlots();
        tellNodeChange(node);

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
            timer = window.setInterval(refresh, INTERVAL);
        }
    }

    function stop() {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    if (toggle && input) {
        input.checked = enabled;
        toggle.hidden = false;

        input.addEventListener('change', function () {
            enabled = input.checked;
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

        scheduleFastTry(0);
    });

    start();
    showRestLink();
    scheduleFastTry(0);
})();
