// ===== service-worker.js (for offline capability) =====
const CACHE_NAME = 'dk-associates-v1';
const urlsToCache = [
    '/admin.php',
    '/assets/css/tailwind.min.css',
    '/assets/js/alpine.js',
    '/assets/js/htmx.js'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(urlsToCache))
    );
});

self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request)
            .then(response => response || fetch(event.request))
    );
});