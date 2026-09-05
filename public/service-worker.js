const CACHE_NAME = 'homeledger-shell-v18';
const STATIC_ASSETS = [
  './assets/app.css',
  './assets/app.js',
  './assets/icons/sprite.svg',
  './assets/brand/logo-dark.png',
  './assets/brand/logo-light.png',
  './assets/brand/favicon.ico',
  './assets/brand/favicon-16.png',
  './assets/brand/favicon-32.png',
  './assets/brand/apple-touch-icon.png',
  './assets/brand/icon-192.png',
  './assets/brand/icon-512.png',
  './manifest.webmanifest'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET' || event.request.mode === 'navigate') return;
  try {
    const url = new URL(event.request.url);
    if (url.searchParams.get('page') === 'household_sync') return;
  } catch (error) {
    return;
  }
  event.respondWith(
    caches.match(event.request).then((cached) => cached || fetch(event.request).then((response) => {
      const copy = response.clone();
      caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
      return response;
    }))
  );
});
