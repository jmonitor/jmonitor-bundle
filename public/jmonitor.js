(function () {
    'use strict';

    var STORAGE_KEY = 'jmonitor.live';
    var INTERVAL = 10000;
    var PAGE_PARAMETERS = ['period', 'sort', 'dir'];
    var root = document.querySelector('[data-jm-live]');

    revealCurrentScreen();

    function revealCurrentScreen() {
        var nav = document.querySelector('.jm-nav');
        var current = nav ? nav.querySelector('[aria-current="page"]') : null;

        if (current && nav.scrollWidth > nav.clientWidth) {
            current.scrollIntoView({ inline: 'center', block: 'nearest' });
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

    if (!url || !content) {
        return;
    }

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
        toggle.title = failed ? 'Last refresh failed, retrying' : '';
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

    function findInContent(selector, attribute, value) {
        var candidates = content.querySelectorAll(selector);

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

        var element = identity.id ? findInContent('[id]', 'id', identity.id) : null;

        if (!element && identity.key) {
            var details = findInContent('details[data-jm-key]', 'data-jm-key', identity.key);
            element = details ? details.querySelector('summary') : null;
        }

        if (!element && identity.href) {
            element = findInContent('a[href]', 'href', identity.href);
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

        content.innerHTML = html;
        reopen(opened);

        wraps = content.querySelectorAll('.jm-table-wrap');
        for (w = 0; w < wraps.length && w < scrollLefts.length; w++) {
            wraps[w].scrollLeft = scrollLefts[w];
        }

        restoreFocus(focused);
    }

    function refresh() {
        if (!root.isConnected) {
            stop();

            return;
        }

        if (!enabled || inFlight || document.hidden || hasSelectionInside(content)) {
            return;
        }

        var target;

        try {
            target = fragmentUrl();
        } catch (e) {
            markFailed(true);

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
                // a redirect or a denial means the session or the access changed: a reload shows why
                if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 403 || response.status === 404) {
                    stop();
                    window.location.reload();

                    return null;
                }

                markFailed(!response.ok);

                return response.ok ? response.text() : null;
            })
            .then(function (html) {
                if (html !== null && enabled && !hasSelectionInside(content)) {
                    replaceContent(html);
                }
            })
            .catch(function () {
                markFailed(true);
            })
            .then(function () {
                inFlight = false;
            });
    }

    function start() {
        stop();

        if (enabled && !document.hidden) {
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
            writePreference(enabled);
            markFailed(false);
            start();
            refresh();
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stop();
        } else {
            start();
            refresh();
        }
    });

    start();
})();
