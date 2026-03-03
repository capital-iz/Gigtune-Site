const GT_STATIC_CACHE = 'gigtune-static-v1';

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key !== GT_STATIC_CACHE).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

function isDynamicPath(pathname) {
  const blocked = [
    '/wp-admin',
    '/wp-login.php',
    '/wp-json',
    '/artist-dashboard',
    '/client-dashboard',
    '/admin-dashboard',
    '/messages',
    '/my-account',
    '/kyc',
    '/kyc-status'
  ];
  return blocked.some((entry) => pathname.indexOf(entry) === 0);
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        return await fetch(request, { cache: 'no-store' });
      } catch (error) {
        const fallback = await caches.match(request);
        if (fallback) {
          return fallback;
        }
        throw error;
      }
    })());
    return;
  }

  if (isDynamicPath(url.pathname)) {
    event.respondWith(fetch(request, { cache: 'no-store' }));
    return;
  }

  const isStaticAsset = /\.(?:css|js|png|jpg|jpeg|webp|svg|woff2?|ico)$/i.test(url.pathname);
  if (!isStaticAsset) {
    event.respondWith(fetch(request, { cache: 'no-store' }));
    return;
  }

  event.respondWith((async () => {
    const cache = await caches.open(GT_STATIC_CACHE);
    const cached = await cache.match(request);
    if (cached) {
      return cached;
    }
    const response = await fetch(request);
    if (response && response.status === 200) {
      cache.put(request, response.clone());
    }
    return response;
  })());
});
