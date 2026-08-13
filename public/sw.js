// BouclePro — Service Worker (PWA shell offline)
// La version doit changer a chaque modification des regles : `activate` purge
// les caches des versions precedentes, seule facon de retirer aux navigateurs
// deja installes les reponses stockees sous l'ancienne strategie.
const CACHE_NAME = 'bouclepro-v4';

const SHELL_ASSETS = [
  '/site.webmanifest',
  '/web-app-manifest-192x192.png',
  '/web-app-manifest-512x512.png',
  '/favicon.svg',
  '/favicon.ico',
  '/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/livewire/')) {
    event.respondWith(fetch(request));
    return;
  }

  if (request.method !== 'GET') {
    event.respondWith(fetch(request));
    return;
  }

  const isDocument = request.destination === 'document';
  const isAsset = url.pathname.match(/\.(js|css|png|jpg|jpeg|gif|svg|ico|webp|woff2?)$/);

  if (isDocument) {
    // Network-first for HTML — always serve fresh, fallback to cache
    event.respondWith(
      fetch(request).then((response) => {
        const clone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        return response;
      }).catch(() => caches.match(request).then((cached) => cached || caches.match('/')))
    );
    return;
  }

  if (isAsset) {
    // Cache-first for versioned assets
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((response) => {
          if (response.status === 200) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          }
          return response;
        });
      })
    );
    return;
  }

  // Network-first for everything else — served from cache ONLY when the
  // network fails.
  //
  // Cette regle etait « stale-while-revalidate » : la copie en cache repartait
  // immediatement et le reseau ne rafraichissait que la fois suivante. Sur des
  // donnees applicatives, c'est un mensonge a retardement — un fichier importe
  // restait invisible jusqu'au rafraichissement, et un fichier deplace
  // reapparaissait a la lecture suivante (TASK-1130). Ni `Cache-Control:
  // no-store` cote serveur ni `cache: 'no-store'` cote fetch n'y pouvaient
  // rien : le Cache Storage d'un service worker n'obeit pas au cache HTTP.
  //
  // La liste d'exceptions par chemin (`/annotations`, `/co-authors`,
  // `/snapshots`) essayait de nommer un a un les endpoints qui ne supportent
  // pas d'etre perimes. Elle ne pouvait que rester incomplete : c'est
  // l'inverse qui est vrai — aucune donnee applicative ne gagne a etre servie
  // perimee tant que le reseau repond. Le repli hors ligne, lui, est conserve.
  event.respondWith(
    fetch(request).then((response) => {
      if (response.status === 200) {
        const clone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
      }
      return response;
    }).catch(() => caches.match(request))
  );
});
