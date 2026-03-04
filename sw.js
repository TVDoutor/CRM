/**
 * Service Worker — TV Doutor CRM
 * Cache básico para funcionamento offline de assets estáticos.
 */
const CACHE_NAME = 'tvdcrm-v1';
const STATIC_ASSETS = [
    '/',
    '/index.php',
    '/pages/dashboard.php',
    '/pages/kanban.php',
];

// Instala e faz cache dos assets principais
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(STATIC_ASSETS).catch(() => {});
        })
    );
    self.skipWaiting();
});

// Ativa e limpa caches antigos
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// Estratégia Network First: tenta rede, cai no cache se offline
self.addEventListener('fetch', event => {
    // Ignora requisições não-GET e APIs
    if (event.request.method !== 'GET') return;
    if (event.request.url.includes('/pages/api/')) return;
    if (event.request.url.includes('/uploads/')) return;

    event.respondWith(
        fetch(event.request)
            .then(response => {
                // Armazena cópia no cache apenas para páginas HTML
                if (response.ok && event.request.headers.get('Accept')?.includes('text/html')) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                }
                return response;
            })
            .catch(() => caches.match(event.request))
    );
});
