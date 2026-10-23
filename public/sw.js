// Home Pantry offline copy: the shopping list opens without a connection.
// Network first, always: the saved copy is used only when the network fails,
// so a working connection never shows anything stale. Ticks made offline wait
// in app.js ("Offline ticks") and sync when the connection is back.

const CACHE = 'pantry-offline';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

const isShoppingList = url => url.pathname === '/shopping-list';
// The page's own styles, script and icons, plus the product photos the list shows.
const isListAsset = (url, request) =>
  /^\/(css|js)\//.test(url.pathname) ||
  /^\/(icon|apple-touch-icon|favicon)/.test(url.pathname) ||
  (url.pathname.startsWith('/storage/') && (request.referrer || '').endsWith('/shopping-list'));

self.addEventListener('fetch', event => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  const page = request.mode === 'navigate' && isShoppingList(url);
  if (!page && !isListAsset(url, request)) return;

  event.respondWith(
    fetch(request)
      .then(response => {
        // A redirect (to the sign-in page, say) is never saved as the list.
        if (response.ok && !response.redirected) {
          const copy = response.clone();
          caches.open(CACHE).then(cache => cache.put(page ? '/shopping-list' : request, copy));
        }
        return response;
      })
      .catch(async () => (await caches.match(page ? '/shopping-list' : request, { ignoreSearch: !page })) || Response.error()),
  );
});
