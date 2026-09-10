// Minimal service worker: exists mainly to satisfy PWA installability
// (Chrome/Edge require a registered SW with a fetch handler) and to keep
// the app shell available offline. Everything else is network-first —
// this is a live POS app, so cached/stale data is worse than a failed
// request. Bump CACHE_NAME whenever the shell list changes to bust old caches.
const CACHE_NAME = 'bmspos-shell-v1';
const SHELL_URLS = ['/offline.html'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_URLS)).catch(() => {})
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) return;

    // Only ever serve the offline fallback for full-page navigations that
    // fail — never intercept API/data requests, which must always hit the
    // network so the app never silently shows stale POS data.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => caches.match('/offline.html'))
        );
    }
});
