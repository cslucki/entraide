// BouclePro — Service Worker (PWA shell offline)
// Le bump reste necessaire : `activate` purge les caches des versions
// precedentes, seule facon de retirer aux navigateurs deja installes les
// reponses du Drive stockees sous l'ancienne strategie — sans quoi elles
// resserviraient de repli hors ligne avec un contenu faux.
//
// TASK-1692 / MICRO-09 — v4 -> v5 : le bump n'est plus seulement hygienique,
// il est la SEULE facon de retirer aux navigateurs deja installes les 80
// documents HTML que `bouclepro-v4` avait accumules (voir plus bas).
//
// TASK-1692 / MICRO-10 — v5 -> v6 : meme raison, un cran plus bas. `v5`
// autorisait encore par EXTENSION, donc il a pu ranger des avatars de membres
// (`/storage/avatars/*.jpg`) et des apercus d'televersement Livewire. Le bump
// les retire aux navigateurs deja installes.
const CACHE_NAME = 'bouclepro-v6';

const SHELL_ASSETS = [
  '/site.webmanifest',
  '/web-app-manifest-192x192.png',
  '/web-app-manifest-512x512.png',
  '/favicon.svg',
  '/favicon.ico',
  '/apple-touch-icon.png',
];

// ── TASK-1692 / MICRO-09 : LISTE BLANCHE ────────────────────────────────────
//
// Mesure AVANT correctif, sur un profil Chrome ordinaire : `bouclepro-v4`
// contenait 129 entrees, dont 80 documents `text/html` — 28 chemins PRIVES
// distincts, `/dashboard`, `/org/*/messages`, `/org/*/points`, `/profile/edit`,
// `/org/main/profile/edit` — plus un export `text/csv`
// (`/org/main/transactions/export`) et un `application/json`.
//
// Trois faits rendaient ce stock restituable :
//
//   1. LA CLEF DE CACHE EST L'URL SEULE. Le mode `credentials` n'en fait pas
//      partie : une requete `credentials:'omit'` matche l'entree rangee par une
//      navigation AUTHENTIFIEE. Mesure : 4 URL privees sur 4 rendues 200 avec
//      formulaire de logout, sans cache-busting, `transferSize = 0`.
//   2. UN `<iframe>` SUFFIT. Son `request.destination` vaut `iframe`, pas
//      `document` : il echappait au network-first et tombait dans la branche
//      finale, qui rendait `cached || fetchPromise` — le cache D'ABORD.
//   3. RIEN NE VIDAIT CE CACHE. `activate` ne supprime que les caches d'un
//      AUTRE nom ; la deconnexion est un POST, court-circuite plus bas. Le
//      stock survivait au logout et au changement de compte, donc au tenant.
//
// `Cache-Control: no-cache, private` n'y peut rien — le Cache Storage n'obeit
// pas au cache HTTP, c'est la lecon deja ecrite plus bas pour TASK-1130. Et
// `Vary: Cookie` serait inoperant : `Cookie` est un *forbidden header name*,
// absent de l'objet `Request`.
//
// La regle est donc dite ICI, et elle est dite en LISTE BLANCHE plutot qu'en
// liste noire : interdire `text/html` aurait laisse passer l'export CSV et le
// JSON mesures ci-dessus. Ce qui n'est pas explicitement autorise n'est ni
// stocke ni servi. N'entrent dans le cache que des ressources STATIQUES, sans
// identite : les `SHELL_ASSETS`, les assets versionnes de l'origine, et la
// fonderie publique qui n'a jamais vu de cookie.
const STATIC_ASSET_PATTERN = /\.(js|css|png|jpg|jpeg|gif|svg|ico|webp|woff2?)$/;
const FONT_ORIGIN = 'https://fonts.bunny.net';

// TASK-1692 / MICRO-10 — UNE EXTENSION N'EST PAS UNE PREUVE DE PUBLICITE.
//
// MICRO-09 autorisait « meme origine ET extension statique ». Deux ressources
// PRIVEES passaient cette porte sans rien forcer :
//
//   * `/livewire-<hash>/preview-file/{filename}` — l'apercu d'un televersement
//     en cours. `TemporaryUploadedFile::generateHashName*()` REINJECTE
//     l'extension d'origine, et les types previsualisables declares par
//     Livewire (`png, gif, bmp, svg, jpg, jpeg, webp`) sont tous dans le motif
//     ci-dessus. Le fichier que quelqu'un vient de deposer etait donc mis en
//     cache. C'est aussi pourquoi la liste blanche ne peut PAS etre un prefixe
//     `/livewire-<hash>/` : elle doit nommer le script d'execution, lui seul.
//   * `/storage/avatars/*.jpg|png|webp` — les photos des membres. `bouclepro-v4`
//     en contenait 14. Servies sans session, mais ce sont des donnees de
//     personnes : elles n'ont rien a faire dans un cache partage par profil.
//
// La liste blanche nomme donc des CHEMINS, releves sur ce que l'application
// charge reellement, et l'extension ne reste qu'une seconde condition.
// `/storage/`, `/org/`, `/admin/` et tout le reste sont dehors par defaut.
const PUBLIC_ASSET_PREFIXES = [
  '/build/', // sortie de Vite, versionnee par empreinte
  '/brand/',
  '/css/',
  '/img/',
  '/js/',
];

// Fichiers statiques servis a la RACINE de `public/`. La coquille PWA
// (`SHELL_ASSETS`) en fait partie : `install` la precharge deja, et
// `/site.webmanifest` n'a pas d'extension reconnue — sans cette liste il serait
// stocke puis jamais servi, ce qui viderait la coquille de son sens.
const ROOT_PUBLIC_FILES = [
  ...SHELL_ASSETS,
  '/favicon-16x16.png',
  '/favicon-32x32.png',
  '/favicon-96x96.png',
];

// Le script d'execution de Livewire, et LUI SEUL. Le prefixe `/livewire-<hash>/`
// sert aussi `preview-file/{filename}` : le nommer entier serait rouvrir la
// porte que ce correctif ferme.
const LIVEWIRE_RUNTIME_PATTERN = /^\/livewire-[A-Za-z0-9]+\/livewire(\.min)?\.js$/;

function isCacheableAsset(url) {
  if (url.origin === FONT_ORIGIN) {
    // La feuille de style de la fonderie n'a pas d'extension (`/css?family=…`),
    // d'ou l'autorisation par ORIGINE. Cet hote ne sert que des polices
    // publiques : aucune reponse n'y depend d'une session, aucune ne porte
    // d'identite.
    return true;
  }

  if (url.origin !== self.location.origin) {
    return false;
  }

  if (ROOT_PUBLIC_FILES.includes(url.pathname)) {
    return true;
  }

  if (LIVEWIRE_RUNTIME_PATTERN.test(url.pathname)) {
    return true;
  }

  return PUBLIC_ASSET_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))
    && STATIC_ASSET_PATTERN.test(url.pathname);
}

// Une reponse n'entre dans le cache que si elle a ABOUTI et qu'on peut la lire.
// `ok` ecarte les 3xx/4xx/5xx — `bouclepro-v4` gardait des corps de refus, dont
// un 405 de 866 ko et une dizaine de 404. `opaque`/`opaqueredirect` ecartent ce
// dont on ne peut meme pas verifier le statut.
function isStorableResponse(response) {
  return !!response
    && response.ok
    && response.type !== 'opaque'
    && response.type !== 'opaqueredirect';
}

// Repli hors ligne pour une NAVIGATION. Genere ici, jamais relu d'un cache :
// l'ancien `caches.match(request)` rendait la page d'une session precedente, et
// `caches.match('/')` la homepage telle qu'elle avait ete rendue POUR QUELQU'UN.
// Cette reponse ne porte aucune donnee utilisateur.
function offlineDocumentResponse() {
  return new Response(
    '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
    + '<meta name="viewport" content="width=device-width, initial-scale=1">'
    + '<title>Hors ligne — BouclePro</title>'
    + '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
    + 'font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#eef1f8;color:#1f2937}'
    + 'main{max-width:28rem;padding:2rem;text-align:center}h1{font-size:1.25rem;margin:0 0 .5rem}'
    + 'p{margin:0;color:#4b5563}</style></head><body><main>'
    + '<h1>Vous &ecirc;tes hors ligne</h1>'
    + '<p>Cette page n&eacute;cessite une connexion. Reconnectez-vous au r&eacute;seau, puis rechargez.</p>'
    + '</main></body></html>',
    {
      status: 503,
      statusText: 'Service Unavailable',
      headers: {
        'Content-Type': 'text/html; charset=utf-8',
        'Cache-Control': 'no-store',
      },
    }
  );
}

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

  if (isDocument) {
    // TASK-1692 / MICRO-09 — reseau SEUL. Ni lecture ni ecriture du cache.
    //
    // La strategie etait « network-first, fallback to cache », et son
    // `cache.put` etait INCONDITIONNEL : aucun controle de statut, aucune
    // notion de session. Chaque navigation d'une personne connectee rangeait
    // sa page. En ligne le reseau gagnait — c'est pourquoi la fuite ne se
    // voyait pas en naviguant — mais le stock ainsi constitue etait ensuite
    // restitue aux `fetch`, XHR et `<iframe>` par la branche finale, et aux
    // navigations elles-memes des que le reseau manquait.
    event.respondWith(fetch(request).catch(() => offlineDocumentResponse()));
    return;
  }

  if (isCacheableAsset(url)) {
    // Cache-first pour les assets versionnes — seule strategie de cache
    // conservee, et la seule qui alimente encore `CACHE_NAME`.
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;

        return fetch(request).then((response) => {
          if (isStorableResponse(response)) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          }

          return response;
        });
      })
    );
    return;
  }

  // ── Donnees du module Dossiers/Drive ────────────────────────────────────
  //
  // TASK-1130 avait mis ces listes en network-first avec repli sur le cache :
  // servies depuis le Cache Storage, elles rendaient l'etat d'AVANT l'ecriture
  // qu'on venait de faire — un fichier importe restait invisible, un fichier
  // deplace reapparaissait. La lecon de cette TASK reste vraie et vaut d'etre
  // relue : ni `Cache-Control: no-store` cote serveur ni `cache: 'no-store'`
  // cote fetch n'y peuvent rien, le Cache Storage n'obeit pas au cache HTTP.
  //
  // TASK-1692 / MICRO-10 — LE REPLI SUR LE CACHE EST SUPPRIME. Ce JSON est une
  // donnee de TENANT, et il entrait dans le meme cache partage que le reste :
  // clef = l'URL seule, donc indifferente a la session, et aucune purge a la
  // deconnexion. Le network-first le mettait hors de portee d'un rejeu EN
  // LIGNE, mais deux chemins restaient ouverts — le repli `catch` hors ligne,
  // et la lecture directe du Cache Storage par n'importe quel script de
  // l'origine, qui ne depend d'aucune strategie.
  //
  // Le comportement historique n'est pas un argument pour conserver une fuite :
  // T1130 voulait eviter de servir un etat PERIME, et le reseau seul y repond
  // mieux que le cache. Il ne reste donc aucune branche speciale — ces URL
  // tombent dans le reseau seul ci-dessous, comme tout le reste. La regle est
  // gardee ici en commentaire parce qu'elle a coute une TASK a etablir.

  // TASK-1692 / MICRO-09 — tout le reste : RESEAU SEUL.
  //
  // Ici vivait « Stale-while-revalidate for other requests », qui rendait
  // `cached || fetchPromise`. C'est elle qui restituait les pages privees aux
  // `fetch`, XHR et `<iframe>`, immediatement et sans panne reseau. Les bypass
  // explicites d'annotations, co-auteurs et instantanes du blog n'ont plus
  // lieu d'etre : ils decrivaient une exception a une regle qui n'existe plus.
  event.respondWith(fetch(request));
});
