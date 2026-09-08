// sw.js - Service Worker for Hardware Monitoring PWA
const CACHE_NAME = 'hw-monitor-v1';
const STATIC_CACHE = [
    '/hardware-monitoring/assets/css/app.css',
    '/hardware-monitoring/assets/js/app.js',
    '/hardware-monitoring/assets/icons/icon-192.png',
    '/hardware-monitoring/assets/icons/icon-512.png',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js',
];

// Install - cache static assets
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(STATIC_CACHE).catch(() => {
                // Silently fail if some CDN assets can't be cached offline
            });
        })
    );
    self.skipWaiting();
});

// Activate - clean old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// Fetch - network first for PHP pages, cache first for static assets
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Skip non-GET and API calls
    if (event.request.method !== 'GET') return;
    if (url.pathname.includes('/api/')) return;

    // Static assets → cache first
    if (
        url.pathname.endsWith('.css') ||
        url.pathname.endsWith('.js') ||
        url.pathname.endsWith('.png') ||
        url.pathname.endsWith('.jpg') ||
        url.hostname !== location.hostname
    ) {
        event.respondWith(
            caches.match(event.request).then(cached =>
                cached || fetch(event.request).then(resp => {
                    const clone = resp.clone();
                    caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
                    return resp;
                })
            )
        );
        return;
    }

    // PHP pages → network first, fallback to cache
    event.respondWith(
        fetch(event.request)
            .then(resp => {
                const clone = resp.clone();
                if (resp.ok) {
                    caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
                }
                return resp;
            })
            .catch(() => caches.match(event.request))
    );
});
