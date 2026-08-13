// BouclePro — Service Worker (PWA shell offline)
// Le bump reste necessaire : `activate` purge les caches des versions
// precedentes, seule facon de retirer aux navigateurs deja installes les
// reponses du Drive stockees sous l'ancienne strategie — sans quoi elles
// resserviraient de repli hors ligne avec un contenu faux.
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

  // ── Donnees du module Dossiers/Drive : network-first, repli sur le cache ──
  //
  // Ces listes changent a chaque import, deplacement ou suppression. Servies
  // depuis le Cache Storage, elles rendaient l'etat d'AVANT l'ecriture qu'on
  // venait de faire : un fichier importe restait invisible jusqu'au
  // rafraichissement, un fichier deplace reapparaissait (TASK-1130). Ni
  // `Cache-Control: no-store` cote serveur ni `cache: 'no-store'` cote fetch
  // n'y peuvent rien — le Cache Storage d'un service worker n'obeit pas au
  // cache HTTP, il faut le dire ICI.
  //
  // La regle est volontairement bornee a ce module : le reste de BouclePro
  // garde sa strategie. Les navigations vers ces memes URLs sont deja traitees
  // plus haut (`destination === 'document'`) : seules les requetes de donnees
  // arrivent ici. `/org/{x}/blog/dossiers` n'est pas concerne, le motif exige
  // `dossiers` juste apres l'organisation.
  if (/^\/org\/[^/]+\/dossiers(\/|$)/.test(url.pathname)) {
    event.respondWith(
      fetch(request).then((response) => {
        // Seul le JSON est conserve : un telechargement de fichier n'a rien a
        // faire dans le Cache Storage, et un blob perime encore moins.
        if (response.ok && (response.headers.get('content-type') || '').includes('application/json')) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        return response;
      }).catch(() => caches.match(request))
    );
    return;
  }

  // Bypass cache for blog API endpoints (annotations, co-authors, snapshots)
  if (url.pathname.includes('/annotations') ||
      url.pathname.includes('/co-authors') ||
      url.pathname.includes('/snapshots')) {
    event.respondWith(fetch(request));
    return;
  }

  // Stale-while-revalidate for other requests
  event.respondWith(
    caches.match(request).then((cached) => {
      const fetchPromise = fetch(request).then((response) => {
        if (response.status === 200) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        return response;
      }).catch(() => cached);
      return cached || fetchPromise;
    })
  );
});
