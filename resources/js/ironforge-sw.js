const CACHE_VERSION = '2026-09-10-beheer';
const CACHE_NAME = 'ironforge-' + CACHE_VERSION;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(['/', '/manifest.json']).catch(() => undefined)).then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

function isPassthrough(url) {
    return url.pathname.startsWith('/api/')
        || url.pathname === '/up'
        || url.pathname === '/sw.js'
        || url.pathname.startsWith('/beheer');
}

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) {
        return;
    }
    if (isPassthrough(url)) {
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    if (response && response.ok && url.pathname === '/') {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put('/', copy));
                    }

                    return response;
                })
                .catch(() => caches.match('/') || caches.match(event.request)),
        );

        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response && response.status === 200 && response.type === 'basic') {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
                }

                return response;
            })
            .catch(() => caches.match(event.request)),
    );
});
