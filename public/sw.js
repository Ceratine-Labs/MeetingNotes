/**
 * NoteFiend service worker.
 *
 * Deliberately conservative. This is a session-authenticated Laravel app, so
 * caching pages would serve stale CSRF tokens and stale data; the worker
 * therefore NEVER caches HTML. It does exactly two things:
 *
 *   1. Static assets under /vendor, /css, /js and /icons are served
 *      cache-first. Safe because every asset URL carries mn_asset()'s mtime
 *      cache-buster, so a deploy changes the URL and misses the cache.
 *   2. Failed page navigations (no network) fall back to /offline.html.
 *
 * Everything else passes straight through to the network untouched.
 *
 * Bump VERSION when the caching logic changes; activation then drops every
 * old cache. Asset churn does not need a bump (see point 1).
 */
const VERSION = 'v1';
const STATIC_CACHE = 'nf-static-' + VERSION;
const OFFLINE_URL = '/offline.html';

const STATIC_PATH = /^\/(vendor|css|js|icons)\//;

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(function (cache) {
            return cache.add(OFFLINE_URL);
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (key) {
                    return key !== STATIC_CACHE;
                }).map(function (key) {
                    return caches.delete(key);
                })
            );
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (event) {
    const request = event.request;

    if (request.method !== 'GET') {
        return; // Never interfere with POST/PUT/DELETE.
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return; // Cross-origin requests are none of our business.
    }

    // Page loads: network always wins; the offline page is only for failure.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match(OFFLINE_URL);
            })
        );
        return;
    }

    // Versioned static assets: cache-first, fill on miss.
    if (STATIC_PATH.test(url.pathname)) {
        event.respondWith(
            caches.match(request).then(function (cached) {
                if (cached) {
                    return cached;
                }

                return fetch(request).then(function (response) {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(STATIC_CACHE).then(function (cache) {
                            cache.put(request, copy);
                        });
                    }
                    return response;
                });
            })
        );
    }
});
