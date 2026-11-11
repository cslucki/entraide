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
// TASK-1737 — v6 -> v7 : la coquille prechargee ne designait pas les icones du
// manifest.
//
// `SHELL_ASSETS` prechargeait `/web-app-manifest-192x192.png` et
// `/web-app-manifest-512x512.png` — 340 ko a eux deux. Mesure du 31/10/2026 :
// ces deux fichiers ne sont references NULLE PART ailleurs que dans ce
// tableau. Ni le manifest, ni une vue, ni un controleur. C'etaient des
// orphelins, prechargres pour personne.
//
// Pendant ce temps, les icones que le manifest declare reellement — et dont le
// navigateur a besoin pour l'invite d'installation et pour poser l'icone sur
// l'ecran d'accueil — n'etaient PAS prechargees.
//
// Le bump est, comme toujours ici, la seule facon de retirer les deux
// orphelins aux navigateurs qui les ont deja stockes.
//
// RIEN D'AUTRE NE CHANGE dans ce fichier. Les strategies de TASK-1692 restent
// intactes : documents en reseau seul, liste blanche de chemins pour les
// assets, reseau seul pour tout le reste.
//
// TASK-1805 — v7 -> v8 : `/site.webmanifest` n'est plus un fichier statique,
// son `name`/`short_name` varie desormais par environnement (BoucleDev en
// local, BouclePro en production — voir `PwaManifestController`). Ce fichier
// fait partie de `SHELL_ASSETS` et est servi CACHE-FIRST (voir
// `isCacheableAsset()` plus bas) : sans ce bump, un navigateur ayant deja
// installe la PWA locale AVANT ce correctif garderait indefiniment en cache
// l'ancien manifest fige sur "BouclePro".
const CACHE_NAME = 'bouclepro-v8';

// TASK-1740 — la version du Service Worker, pour la RENDRE OBSERVABLE.
//
// Sans elle, « le telephone execute-t-il le SW qui sait recevoir un push ? »
// est une question sans reponse depuis le telephone. Et c'est precisement la
// question qui restait ouverte apres que la chaine serveur a ete prouvee :
// abonnement eligible, requete sortie, FCM accepte, charge dechiffrable — et
// pourtant rien ne s'affiche.
//
// La page des reglages interroge cette valeur et l'affiche. Une version
// absente ou ancienne dit alors, sans ambiguite, que le SW doit etre mis a
// jour.
const SW_VERSION = 'T1740-diag-3';

// `install` fait `cache.addAll(SHELL_ASSETS)`, et `addAll` est ATOMIQUE : une
// seule URL en 404 fait echouer l'installation ENTIERE du Service Worker, donc
// l'installabilite de l'application. Chaque entree de ce tableau est verifiee
// sur le disque par `TASK1737PwaInstallationTest`.
const SHELL_ASSETS = [
  '/site.webmanifest',
  // Les quatre icones du manifest, `any` et `maskable`.
  '/brand/icon-192.png',
  '/brand/icon-512.png',
  '/brand/maskable-192.png',
  '/brand/maskable-512.png',
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

// ---------------------------------------------------------------------------
// TASK-1740 — reception d'une notification Push, et clic dessus.
//
// ## Pourquoi ces deux ecouteurs n'affaiblissent PAS le durcissement T1692
//
// Ils vivent HORS de `fetch`. Aucune des cinq branches ci-dessus n'est
// touchee, et surtout : AUCUNE lecture ni ecriture du Cache Storage
// n'apparait ici. C'est la seule facon d'affaiblir T1692 depuis un ecouteur
// `push` — la cle de cache etant l'URL SEULE, sans identite, tout ce qu'on y
// deposerait serait restituable a un autre profil.
//
// Le texte de ce commentaire evite DELIBEREMENT d'ecrire les appels de cache
// sous leur forme litterale : `TASK1737PwaInstallationTest` les compte par
// motif dans le fichier, et ne distingue pas un appel d'une citation. Une
// garde qui compte du TEXTE se trompe sur un commentaire ; plutot que de
// l'assouplir — elle protege un invariant reel — on ne lui donne pas matiere
// a se tromper.
//
// `notificationclick` ouvre une URL par `clients.openWindow()`, donc par une
// navigation ordinaire : elle repasse par la branche « document », qui est en
// RESEAU SEUL. Le serveur re-autorise la page, et un droit revoque entre-temps
// rend un refus — rien n'est servi depuis un cache.
//
// ## La charge utile n'est jamais crue sur parole
//
// Elle vient du reseau. On la lit defensivement, on borne ce qu'on affiche, et
// surtout on REFUSE d'ouvrir une URL qui ne serait pas de notre origine : un
// `notificationclick` est un geste utilisateur, et l'envoyer vers un tiers
// serait une redirection ouverte declenchee par une donnee distante.
// ---------------------------------------------------------------------------

/**
 * Transformer une charge utile en notification visible.
 *
 * TASK-1740 — cette fonction est volontairement PARTAGEE entre l'ecouteur
 * `push` (charge venue du reseau) et la simulation de diagnostic (charge
 * venue de la page). C'est tout l'interet : si la simulation affiche, alors
 * l'exemplaire ACTIF du Service Worker sait traiter une charge et appeler
 * `showNotification` — et la panne est en AMONT, dans la livraison. Si elle
 * n'affiche pas, la panne est ICI ou dans la permission.
 *
 * Deux chemins distincts n'auraient rien prouve : on aurait teste du code que
 * le push n'emprunte pas.
 */
function afficherDepuisCharge(charge) {
  const titre = typeof charge.title === 'string' && charge.title !== '' ? charge.title : 'BouclePro';
  const corps = typeof charge.body === 'string' ? charge.body : '';

  // L'URL n'est retenue que si elle est de NOTRE origine.
  let cible = '/';

  if (typeof charge.url === 'string' && charge.url !== '') {
    try {
      const resolue = new URL(charge.url, self.location.origin);
      if (resolue.origin === self.location.origin) {
        cible = resolue.href;
      }
    } catch (e) {
      cible = '/';
    }
  }

  return self.registration.showNotification(titre, {
    body: corps,
    icon: '/brand/icon-192.png',
    // Pas de `badge` : Android attend ici une silhouette MONOCHROME sur fond
    // transparent, qu'il peint en blanc dans la barre d'etat. Lui donner le
    // logo couleur 192 px produisait une barre d'etat VIDE — recette du 01/11 :
    // l'essai sans badge montrait la cloche par defaut, celui avec badge ne
    // montrait rien. Sans badge, Chrome met sa propre cloche, qui s'affiche.
    // `tag` : un second message de la meme famille REMPLACE le precedent au
    // lieu de s'empiler (anti-bruit, CDC 9.3). `renotify` previent a nouveau,
    // sinon le remplacement serait silencieux.
    tag: typeof charge.tag === 'string' && charge.tag !== '' ? charge.tag : 'bouclepro',
    renotify: true,
    data: { url: cible },
  });
}

self.addEventListener('push', (event) => {
  // `userVisibleOnly: true` a ete impose a l'abonnement (T1739) : le
  // navigateur EXIGE donc qu'un push produise une notification visible. Ne
  // rien afficher ferait perdre l'abonnement, pas gagner de la discretion.
  let charge = {};

  try {
    charge = event.data ? event.data.json() : {};
  } catch (e) {
    charge = {};
  }

  event.waitUntil(afficherDepuisCharge(charge));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const cible = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((fenetres) => {
      // Reutiliser une fenetre DEJA ouverte plutot que d'en empiler une
      // nouvelle a chaque clic : sur un telephone, trois notifications
      // ouvriraient trois instances de l'application.
      for (const fenetre of fenetres) {
        if (fenetre.url === cible && 'focus' in fenetre) {
          return fenetre.focus();
        }
      }

      if (fenetres.length > 0 && 'navigate' in fenetres[0] && 'focus' in fenetres[0]) {
        return fenetres[0].navigate(cible).then((f) => (f ? f.focus() : undefined));
      }

      return self.clients.openWindow(cible);
    })
  );
});

// ---------------------------------------------------------------------------
// TASK-1740 — le canal de DIAGNOSTIC.
//
// Additif : ne touche ni a `fetch`, ni au cache, ni a aucune des cinq branches
// du durcissement T1692. Il ne rend que des constantes et ne declenche aucune
// sortie reseau.
//
// Pourquoi par message et non par une variable lue depuis la page : seul
// l'exemplaire REELLEMENT ACTIF du Service Worker repond. Un `/sw.js`
// telechargeable a jour ne dit rien de l'exemplaire qui tourne — c'etait
// precisement l'inconnue qui restait apres un FCM a 201.
// ---------------------------------------------------------------------------

self.addEventListener('message', (event) => {
  const message = event.data;
  const port = event.ports && event.ports[0];

  if (!message || !port) {
    return;
  }

  if (message.type === 'bouclepro:version') {
    port.postMessage({
      version: SW_VERSION,
      // Les preuves qui comptent : cet exemplaire sait-il recevoir un push, et
      // sur quel perimetre regne-t-il ?
      push: true,
      scope: self.registration.scope,
      origine: self.location.origin,
    });

    return;
  }

  // Simuler l'arrivee d'une charge, SANS reseau et SANS FCM : on emprunte
  // exactement le chemin d'affichage du push reel.
  if (message.type === 'bouclepro:simuler-push') {
    event.waitUntil(
      afficherDepuisCharge(message.charge || {})
        .then(() => port.postMessage({ ok: true }))
        .catch((e) => port.postMessage({ ok: false, erreur: String(e && e.message ? e.message : e) }))
    );
  }
});
