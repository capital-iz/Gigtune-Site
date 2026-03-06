const GT_STATIC_CACHE = 'gigtune-static-v3';

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

    const networkPromise = fetch(request).then((response) => {
      if (response && response.status === 200) {
        cache.put(request, response.clone());
      }
      return response;
    }).catch(() => null);

    if (cached) {
      event.waitUntil(networkPromise);
      return cached;
    }

    const network = await networkPromise;
    if (network) {
      return network;
    }

    return fetch(request);
  })());
});

self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (!data) {
    return;
  }

  if (data.type === 'GT_SKIP_WAITING') {
    self.skipWaiting();
    return;
  }

  if (data.type !== 'GT_SHOW_NOTIFICATION') {
    return;
  }

  const title = String(data.title || 'GigTune');
  const options = {
    body: String(data.body || ''),
    tag: String(data.tag || 'gigtune-live'),
    icon: String(data.icon || '/wp-content/themes/gigtune-canon/assets/img/gigtune-app-icon-192.png'),
    badge: String(data.badge || '/wp-content/themes/gigtune-canon/assets/img/gigtune-app-icon-192.png'),
    data: {
      url: String(data.url || '/notifications/')
    }
  };

  const show = self.registration.showNotification(title, options);
  if (typeof event.waitUntil === 'function') {
    event.waitUntil(show);
  }
});

self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (error) {
    payload = {
      title: 'GigTune',
      body: event.data ? String(event.data.text()) : 'You have a new notification.',
      url: '/notifications/'
    };
  }

  const title = String(payload.title || 'GigTune');
  const options = {
    body: String(payload.body || 'You have a new notification.'),
    tag: String(payload.tag || 'gigtune-push'),
    icon: String(payload.icon || '/wp-content/themes/gigtune-canon/assets/img/gigtune-app-icon-192.png'),
    badge: String(payload.badge || '/wp-content/themes/gigtune-canon/assets/img/gigtune-app-icon-192.png'),
    data: {
      url: String(payload.url || '/notifications/')
    }
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = (event.notification && event.notification.data && event.notification.data.url)
    ? event.notification.data.url
    : '/notifications/';

  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of clients) {
      if (client.url && client.url.indexOf(self.location.origin) === 0) {
        try {
          await client.focus();
          await client.navigate(targetUrl);
          return;
        } catch (error) {
          // Continue to open a new window if focus/navigation fails.
        }
      }
    }
    await self.clients.openWindow(targetUrl);
  })());
});
