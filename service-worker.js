const CACHE_NAME = 'confrerie-static-v3';
const STATIC_ASSETS = [
  './manifest.webmanifest',
  './logo.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.map((key) => (key !== CACHE_NAME ? caches.delete(key) : Promise.resolve()))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') {
    return;
  }

  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  const isStatic = STATIC_ASSETS.some((asset) => url.pathname.endsWith(asset.replace('./', '/')));
  if (!isStatic) {
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(event.request).then((response) => {
        const clone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        return response;
      });
    })
  );
});

self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type !== 'SHOW_NOTIFICATION') return;
  const title = String(data.title || 'La Confrerie');
  const body = String(data.body || '');
  self.registration.showNotification(title, {
    body,
    icon: 'logo.png',
    badge: 'logo.png',
    tag: 'confrerie-live',
    renotify: false
  });
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      if (clientList && clientList.length > 0) {
        const client = clientList[0];
        client.focus();
        return;
      }
      return clients.openWindow('./index.php?page=dashboard');
    })
  );
});
