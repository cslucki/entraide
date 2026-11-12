# TASK-1809 — Micro-sequences A/B/C livrees + proposition NewMessageReceived

**12/11/2026, 09h47 Europe/Paris** · branche
`TASK-1809-refonte-premium-des-emails-et-notifications-bouclepro`

## 1. Micro-sequences A, B, C — livrees, testees, commitees

**A — socle visuel premium.** `config('mail.markdown.theme') => 'bouclepro'`
+ `resources/views/vendor/mail/html/themes/bouclepro.css` (palette indigo
deja en usage ailleurs dans l'app, structure en tableaux INCHANGEE — celle,
deja robuste, livree par Laravel). Un seul levier, rebrande d'un coup les 7
notifications natives et tout gabarit passant par `emails.system-email`.

**B — NotificationEmailDeliverer habille.** Le theme seul ne suffisait pas :
ce pipeline (boucles, echanges, entraide, blog) livre le HTML administre
par `Mail::html()` direct, mesure NU en base reelle. Deux pieges trouves et
ecartes **sur preuve, pas par choix de depart** :
1. `Markdown::parse()` casse un HTML multi-lignes avec lignes vides (repli
   Blade `loop-invitation.blade.php` et semblables) — le marqueur
   anti-jeton `[action-link-redacted]` disparaissait, cassant un test
   existant.
2. `CssToInlineStyles` (DOMDocument) re-encode les caracteres juges
   invalides dans un `href` — le meme marqueur en ressortait
   `%5Baction-link-redacted%5D`.

Solution retenue : `emails.notification-shell.blade.php`, theme en
`<style>` plutot qu'inline (meme choix deja accepte par
`blog-invitation.blade.php`/`referral-invitation.blade.php`) — pure
concatenation Blade, aucun parseur ni serialiseur DOM entre le texte
administre et le transport.

**C — apercu fidele.** `/admin/system-email-templates/{id}/edit` affichait
le HTML brut du textarea, variables non interpolees. Nouveau point
`system-email-templates.preview` : rendu REEL (variables de demonstration,
theme reel), jamais un envoi, reflete le brouillon du formulaire. Bascule
desktop/mobile, locale-aware. Verifie visuellement dans le navigateur
(captures non jointes a ce rapport, verification directe faite en session).

**Decisions produit respectees** : Global uniquement (architecture du
resolveur preservee, non reactivee) ; les deux systemes `SystemEmailTemplate`/
`EmailTemplate` restent distincts ; aucun nouveau moteur de notification ou
de rendu — reutilisation de `Illuminate\Mail\Markdown`, `GabaritEmail`,
`SystemEmailTemplateResolver` tels quels.

**Correctif additionnel (demande Cyril, 12/11, hors A/B/C)** : le gabarit
`entraide_evaluation_invite` nomme desormais l'aidant par son PRENOM
(`{{ aidant_first_name }}`) dans la phrase d'invitation, et dit toujours
explicitement que l'echange est termine (deja le cas, confirme). Migration
idempotente, qui ne touche une ligne QUE si son contenu correspond encore
exactement au texte canonique precedent — aucun ecrasement d'une
personnalisation SuperAdmin existante, meme garde que pour `new_message`
en PROD.

**Tests** : 16 tests cibles neufs (A: 3, B: 6, C: 6, correctif: 1), tous
verifies sur SQLite ET PostgreSQL reel. Suite ciblee elargie (470 tests,
toutes les suites emails/notifications existantes) verte sur les deux
moteurs. Suite complete locale en cours au moment de ce rapport.

Quatre commits fonctionnels separes, pousses sur la branche existante
(pas de nouvelle PR) : socle visuel, habillage du HTML brut, apercu fidele,
correctif prenom de l'aidant.

---

## 2. Point d'arret — NewMessageReceived : proposition, PAS d'implementation

**Le fait, verifie dans le code** (`app/Livewire/MessageThread.php:538`) :
chaque message envoye, dans **toute** conversation (chat direct ou
entraide), declenche sans condition `$recipient->notify(new
NewMessageReceived($transaction, $msg))`. Cette classe est une
`Notification` Laravel classique, canal `mail` cable en dur, **hors du
catalogue** (`NotificationCatalogue`/`NotificationEmitter`/
`NotificationDeliveryPlanner`) : aucune preference, aucune idempotence
`event_id`, aucun throttle, aucun digest. Dette deja identifiee et
documentee le 03/11, jamais traitee depuis.

**Signal deja disponible, sans rien construire** : `messages.read_at` existe
deja et se pose quand le destinataire OUVRE la conversation
(`MessageThread::markRead()`). C'est une approximation de presence (pas du
temps reel), mais c'est un fait deja mesure, pas une hypothese.

### Scenario 1 — Notification immediate in-app, email limite par conversation

**Principe** : l'in-app (deja immediat aujourd'hui via le badge non-lu) ne
change pas. L'EMAIL, lui, ne part que si le destinataire n'a PAS encore lu
le PRECEDENT message de cette conversation au moment ou le nouveau arrive —
c'est-a-dire : jamais un deuxieme email tant que le premier n'a pas ete
« consomme » (lu) ou qu'un delai minimal ne s'est pas ecoule.

- **Destinataire deja en train de lire** : s'il a la conversation ouverte,
  `read_at` du message precedent est deja pose au moment de l'envoi suivant
  → pas d'email, l'in-app suffit (il est deja sous les yeux). C'est le cas
  le plus favorable et le plus frequent dans une conversation active.
- **Risque de notification importante manquee** : FAIBLE — le PREMIER
  message d'une nouvelle serie part toujours (rien n'est lu). Le risque
  existe seulement si quelqu'un enchaine plusieurs messages porteurs
  d'information differente avant toute lecture : seul le premier email part,
  les suivants non, jusqu'a lecture ou expiration d'un delai. Attenuable par
  un delai court (ex. 10-15 min) plutot qu'un blocage indefini.
- **Contrainte `QUEUE_CONNECTION=sync`** : aucune — la decision se prend en
  SQL (`whereNull('read_at')` sur le message precedent) au moment de
  l'emission, dans la meme requete HTTP, pas de planification necessaire.
- **Impact membres existants** : quasi nul. Un membre qui lit ses messages
  normalement verra une baisse du volume d'emails, pas un changement de
  comportement visible — c'est la promesse de `rescue()` qui protege deja
  l'envoi aujourd'hui, inchangee.
- **Cout d'implementation** : LE PLUS FAIBLE des trois — une condition
  ajoutee avant l'appel `notify()` existant, aucune nouvelle table, aucun
  job planifie.

### Scenario 2 — Regroupement des messages par conversation (digest)

**Principe** : chaque message cree toujours sa trace in-app immediatement,
mais l'email est DIFFERE et REGROUPE — un seul envoi recapitulant tous les
messages non lus d'une conversation apres une fenetre d'inactivite (ex.
15 minutes sans nouveau message), via une commande planifiee (meme patron
que `entraide:invite-to-evaluate`, T1808).

- **Destinataire deja en train de lire** : le digest planifie se base sur
  `read_at` au moment de son passage — si tout est deja lu, rien n'est
  envoye, peu importe le nombre de messages entre-temps.
- **Risque de notification manquee** : LE PLUS FAIBLE des trois scenarios —
  aucun message n'est jamais perdu, seulement regroupe ; un echange tres
  actif produit UN email au lieu de dix.
- **Contrainte `QUEUE_CONNECTION=sync`** : structurante ici. Pas de vraie
  file asynchrone : le regroupement doit etre une commande PLANIFIEE
  (`Schedule::command(...)->everyMinutes(...)`), pas une consequence d'un
  job differe. Le planificateur devient alors responsable d'ecrire sa
  propre idempotence (ne jamais compter deux fois le meme message, un
  "digest_sent_at" ou equivalent par message ou par fenetre) — c'est
  EXACTEMENT le patron deja prouve par `entraide:invite-to-evaluate`.
- **Impact membres existants** : le plus visible des trois — le DELAI entre
  un message et son email change de nature (immediat aujourd'hui, differe
  demain). A annoncer si retenu, pas a faire en silence.
- **Cout d'implementation** : LE PLUS ELEVE — une nouvelle commande
  planifiee, une nouvelle marque d'idempotence, une logique de fenetre a
  calibrer et a tester contre la concurrence (deux messages presque
  simultanes ne doivent pas produire deux digests).

### Scenario 3 — Integration dans le catalogue de notifications existant

**Principe** : `new_message` devient une clé du catalogue
(`NotificationCatalogue`), au meme titre que les 13 deja gouvernees —
passe par `NotificationEmitter`/`NotificationDeliveryPlanner`, herite de
l'idempotence `UNIQUE(event_id, recipient_id)`, du job asynchrone
`SendNotificationEmail` (deja prouve anti-doublon), et devient
**reglable** par le membre depuis l'ecran de preferences existant —
aujourd'hui impossible, ce canal n'existe nulle part dans ses reglages.

- **Destinataire deja en train de lire** : ne change RIEN par defaut — le
  catalogue ne connait pas de notion de presence aujourd'hui (mesure dans
  l'audit : « aucun throttle, digest, agregation, ni detection de presence »).
  Il faudrait COMBINER ce scenario avec la condition du Scenario 1 pour
  obtenir le meme comportement — les deux ne s'excluent pas.
- **Risque de notification manquee** : nul de plus qu'aujourd'hui pour le
  fait lui-meme (chaque message reste notifie) — le vrai gain est que le
  membre peut desormais COUPER ce canal explicitement, ce qu'il ne peut pas
  faire actuellement.
- **Contrainte `QUEUE_CONNECTION=sync`** : deja geree par le pipeline
  existant (le job s'execute en synchrone immediat avec ce pilote, comme
  tous les autres canaux catalogues).
- **Impact membres existants** : un membre qui ne connaissait pas ce canal
  (puisqu'il etait invisible) le decouvre dans ses reglages, active par
  defaut (meme comportement qu'aujourd'hui) — transition neutre, mais
  **augmente le nombre de lignes `member_notification_deliveries`/
  `member_notifications`** de maniere potentiellement significative (chaque
  message cree une ligne), a mesurer avant implementation (meme vigilance
  que T1778, qui avait mesure un cout SQL de fan-out).
- **Cout d'implementation** : MOYEN — pas de nouvelle mecanique a inventer,
  mais un point d'emission a brancher au bon endroit (`MessageThread`) et
  une migration de donnees a considerer pour les messages deja en cours.

### Recommandation implicite, pas une decision

Le Scenario 1 (le moins couteux, le risque le plus faible, aucune
contrainte de planification) et le Scenario 3 (le seul qui rend ce canal
REGLABLE par le membre) ne s'excluent pas — ils pourraient se combiner
en une seule TASK. Le Scenario 2 est le plus transformant pour
l'experience mais le plus couteux et le plus visible pour les membres
existants. **Aucun code n'a ete modifie pour ce point — en attente de la
decision produit de MASTER avant toute implementation.**
