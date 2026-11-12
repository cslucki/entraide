<?php

namespace App\Support\Notifications;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\EntraideComment;
use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Support\ScopedReadCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1374 — resoudre la CIBLE d'une notification, au moment du clic.
 *
 * ## Rien n'est stocke, tout est resolu
 *
 * Une notification ne porte qu'`object_type` + `object_id`. Aucune URL, aucun
 * titre, aucun contenu. La destination est donc reconstruite ici, cote serveur,
 * **sous les permissions du moment** — jamais lue dans la ligne.
 *
 * C'est ce qui fait qu'une notification vieille d'un mois n'ouvre aucune porte
 * fermee depuis : il n'y a rien d'ancien a reafficher.
 *
 * ## `null` est une reponse, pas un echec
 *
 * Objet supprime, invitation expiree, revoquee, deja acceptee, acces perdu :
 * le resolver rend `null`, et l'ecran le dit honnetement — « Cet element n'est
 * plus accessible. » Il ne devine pas, il ne se rabat pas sur une page
 * approchante, et surtout il ne laisse filtrer aucun fragment de ce que la
 * cible contenait.
 *
 * ## Un type inconnu ne mene nulle part
 *
 * Comme le catalogue, ce resolver est fail-closed : un `object_type` qu'il ne
 * connait pas rend `null`. Ajouter un producteur, c'est ajouter sa branche ici —
 * pas esperer qu'une convention de nommage suffise.
 */
class NotificationTargetResolver
{
    /**
     * L'adresse ou envoyer le membre, ou `null` si la cible ne lui est plus
     * accessible.
     */
    public function resolve(MemberNotification $notification): ?string
    {
        return match ($notification->object_type) {
            NotificationCatalogue::OBJECT_LOOP_INVITATION => $this->loopInvitation($notification),
            NotificationCatalogue::OBJECT_LOOP_JOIN_REQUEST => $this->loopJoinRequest($notification),
            // TASK-1719 — l'ajout direct mene a la Boucle elle-meme.
            NotificationCatalogue::OBJECT_LOOP => $this->loop($notification),
            // TASK-1772 — une mention mene a LA PHRASE ou l'on a ete nomme.
            NotificationCatalogue::OBJECT_LOOP_MESSAGE => $this->loopMessage($notification),
            NotificationCatalogue::OBJECT_BLOG_COMMENT => $this->blogComment($notification),
            NotificationCatalogue::OBJECT_BLOG_POST => $this->blogPost($notification),
            // TASK-1751 — un echange mene a SA conversation.
            NotificationCatalogue::OBJECT_TRANSACTION => $this->transaction($notification),
            NotificationCatalogue::OBJECT_ENTRAIDE_COMMENT => $this->entraideComment($notification),
            // TASK-1810 — un commentaire de Flux mene A LA LIGNE commentee ;
            // une reaction mene a l'ITEM lui-meme (il n'existe pas de fiche
            // dediee a une reaction).
            NotificationCatalogue::OBJECT_FEED_POST_COMMENT => $this->feedPostComment($notification),
            NotificationCatalogue::OBJECT_FEED_POST => $this->feedPost($notification),
            default => null,
        };
    }

    /**
     * TASK-1772 — une mention mene au MESSAGE, pas seulement a la Boucle.
     *
     * L'URL porte `?message={uuid}` : `LoopChat::mount()` le lit et appelle la
     * mecanique DEJA ecrite (`showMessageInThread()`), qui charge les messages
     * AUTOUR de la cible — donc meme si elle est hors de la fenetre de trente —
     * puis emet `scroll-to-message`, que la liste ecoute deja en surlignant la
     * bulle.
     *
     * Quatre cas rendent `null`, et ils sont tous ORDINAIRES. Rendre une URL
     * dans ces cas-la enverrait le membre sur une page qui ne lui montrerait
     * rien, ou pire, sur une Boucle dont il n'est plus :
     *
     *   1. le message a ete supprime — attention, `loop_messages` n'a PAS de
     *      trait `SoftDeletes`, c'est un drapeau `deleted_at` manuel, donc
     *      aucun scope ne le filtre pour nous ;
     *   2. le destinataire n'est plus dans l'Organization (`organization_id`
     *      est mutable, et il se lit hors Eloquent pour ne dependre d'aucun
     *      scope) ;
     *   3. il n'est plus membre ACTIF de la Boucle ;
     *   4. la Boucle ou l'Organization a disparu.
     *
     * Et comme partout sur ce chemin : scope global RETIRE puis borne de
     * tenant posee a la main. Ce code tourne sous worker, ou le fail-closed du
     * scope rendrait l'objet introuvable EN SILENCE (T1720 volet A).
     */
    private function loopMessage(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        // TASK-1799 (B1) — le MESSAGE n'est volontairement PAS memorise,
        // a la difference de la Boucle/Organization/acteur juste en dessous.
        //
        // Tentative faite, puis RETIREE : `TASK1772MentionEmailEtCibleTest::
        // test_un_message_supprime_ou_un_membre_sorti_ne_mene_plus_nulle_part`
        // et `::test_un_message_sans_texte_affiche_une_mention_neutre`
        // rougissaient — tous deux relisent CE MEME message apres l'avoir
        // modifie (`deleted_at`, `body`) DANS LA MEME portee d'execution, et
        // exigent que la lecture suivante voie le changement. Contrairement
        // a la Boucle ou l'Organization (dont seule l'IDENTITE/le TENANT
        // comptent ici), le contenu et l'etat du message SONT ce que cette
        // methode existe pour verifier a jour — le memoriser aurait remplace
        // un controle courant par une donnee potentiellement perimee,
        // exactement ce que ce correctif s'interdit.
        $message = LoopMessage::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($notification->object_id)
            ->whereNull('deleted_at')
            ->first();

        if ($message === null) {
            return null;
        }

        $boucle = app(ScopedReadCache::class)->souviens(
            'cible-boucle:'.$notification->organization_id.':'.$message->loop_id,
            fn (): ?Loop => Loop::query()
                ->withoutGlobalScope(BelongsToOrganizationScope::class)
                ->whereKey($message->loop_id)
                ->where('organization_id', $notification->organization_id)
                ->first(),
        );

        if ($boucle === null) {
            return null;
        }

        $tenantDuDestinataire = DB::table('users')
            ->where('id', $notification->recipient_id)
            ->value('organization_id');

        if ($tenantDuDestinataire !== $notification->organization_id) {
            return null;
        }

        $estMembreActif = LoopMember::query()
            ->where('loop_id', $boucle->id)
            ->where('user_id', $notification->recipient_id)
            ->where('status', 'active')
            ->exists();

        if (! $estMembreActif) {
            return null;
        }

        // Meme cle que celle de `loop()` ci-dessus : l'Organization est une
        // donnee de reference partagee par TOUTE notification de ce tenant,
        // quel que soit l'`object_type` qui la demande.
        $organisation = app(ScopedReadCache::class)->souviens(
            'cible-organization:'.$notification->organization_id,
            fn (): ?Organization => Organization::query()
                ->withoutGlobalScope(BelongsToOrganizationScope::class)
                ->whereKey($notification->organization_id)
                ->first(),
        );

        if ($organisation === null) {
            return null;
        }

        // Le SLUG dans l'URL, l'identifiant en repli : cette adresse part dans
        // un COURRIEL, on la lit et on la colle (meme raison qu'en T1720).
        return route('organization.loops.show', [
            'organization' => $organisation,
            'loop' => $boucle->slug ?: $boucle->getKey(),
            'message' => $message->getKey(),
        ]);
    }

    /**
     * Une invitation mene la ou l'on peut y REPONDRE.
     *
     * La Boucle elle-meme ne suffirait pas : un non-membre y verrait une page de
     * presentation, sans pouvoir accepter — on ferait cliquer pour rien.
     *
     * Le jeton n'est jamais stocke dans la notification. Il est relu **ici**,
     * sur la ligne d'invitation, apres verification du tenant : la notification
     * ne detient aucune autorite, elle designe seulement un objet.
     */
    private function loopInvitation(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        // TASK-1720 volet A — meme retrait de scope, meme raison : ce code
        // tourne aussi sous worker, ou `current_organization` n'existe pas et
        // ou le fail-closed du scope rendrait cet objet introuvable. La borne
        // de tenant est posee explicitement juste en dessous.
        $invitation = LoopInvitation::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($notification->object_id)
            // La frontiere de tenant est reverifiee ICI aussi. Elle l'etait deja
            // a l'ecriture, mais une notification peut survivre a bien des
            // changements, et une garde qui ne s'applique qu'une fois n'est pas
            // une garde.
            ->where('organization_id', $notification->organization_id)
            ->first();

        if ($invitation === null || ! $invitation->isPending()) {
            // Expiree, revoquee, deja acceptee, supprimee : la porte est fermee,
            // et on le dit plutot que d'envoyer vers une page qui refusera.
            return null;
        }

        return route('loop-invitations.show', ['token' => $invitation->token]);
    }

    /**
     * TASK-1381 — une decision sur une demande d'adhesion mene a la Boucle.
     *
     * ## La MEME adresse pour « accepte » et pour « refuse »
     *
     * Ce n'est pas un raccourci. La page de Boucle sait deja repondre
     * differemment selon qui frappe : un membre actif entre dans l'espace de
     * travail, un non-membre recoit la carte de presentation (TASK-1075 —
     * « privee » ne veut pas dire cachee). Fabriquer ici deux destinations
     * dupliquerait cette regle, et les deux copies divergeraient.
     *
     * Ce qui distingue les deux cles est le FAIT, dit par le libelle dans le
     * Centre. La destination, elle, est le meme endroit : la Boucle concernee.
     *
     * ## L'Organization est EXPLICITE, jamais ambiante
     *
     * Trois routes portent le nom `loops.show` : la courte, qui resout
     * `main` ; celle de l'administration ; et l'org-scopee. Ce resolveur tourne
     * SOUS WORKER pour le rendu des emails — sans session, sans
     * `current_organization`, sans `request()->route()`. La seule autorite
     * disponible est celle de la notification elle-meme, et c'est la bonne :
     * `organization_id` y est immuable.
     *
     * ## Trois portes peuvent s'etre fermees depuis
     *
     * La demande peut avoir disparu, le destinataire avoir quitte
     * l'Organization, la Boucle avoir ete archivee. Dans les trois cas l'ecran
     * repondrait 404 ; on rend `null` et le Centre le dit honnetement, plutot
     * que d'envoyer quelqu'un sur une porte close.
     */
    /**
     * TASK-1719 — la cible d'un ajout direct : la Boucle.
     *
     * Les memes verifications que `loopJoinRequest()`, et pour les memes
     * raisons — le tenant du destinataire est relu HORS Eloquent parce que ce
     * code tourne aussi sous worker, ou aucune portee globale ne s'applique.
     *
     * Une difference, et elle compte : la personne vient d'etre AJOUTEE, donc
     * son appartenance active est la condition normale et non une faveur. Si
     * elle ne l'est plus — retiree entre l'ajout et l'envoi —, la cible devient
     * nulle et la livraison est sautee plutot que d'annoncer un lien qui
     * refusera.
     */
    private function loop(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        // TASK-1720 volet A — le scope de tenant est RETIRE, et la borne
        // ecrite a la main juste en dessous.
        //
        // Ce code tourne dans un WORKER. Il n'y a alors aucune
        // `current_organization`, donc `BelongsToOrganizationScope` applique
        // son fail-closed `whereRaw('0 = 1')` et la Boucle ressort
        // INTROUVABLE — en silence. La livraison etait sautee avec le
        // diagnostic « cible plus joignable », qui est vrai du point de vue du
        // resolveur et trompeur pour qui le lit.
        //
        // Meme doctrine que `NotificationInvariants`, dont le docblock la
        // formule : « une garde d'integrite ne doit dependre d'aucun scope
        // global [...] elle doit rendre le meme verdict dans une requete HTTP,
        // dans un job de file d'attente et dans une commande artisan. »
        //
        // La frontiere de tenant n'est pas affaiblie : le `where
        // organization_id` ci-dessous la pose explicitement, et il est le seul
        // a decider.
        // TASK-1778 — la Boucle est memorisee pour la duree de cette requete
        // (ou de ce job), et c'est le SEUL des quatre controles de cette
        // methode qui le soit.
        //
        // Le fan-out d'une demande d'aide appelle cette methode 2 fois par
        // destinataire (un livreur par canal asynchrone) : 200 lectures de LA
        // MEME ligne pour une Boucle de 100 membres. La Boucle est une donnee
        // de reference pour l'evenement ; elle ne peut changer ni d'identite
        // ni de tenant pendant le traitement d'une seule unite de travail.
        //
        // Les trois controles qui suivent — le tenant du DESTINATAIRE, son
        // appartenance ACTIVE a la Boucle, l'etat de l'objet — restent lus a
        // chaque appel, sans memoire : ils varient par destinataire, et ce
        // sont eux qui portent la frontiere de tenant. Memoriser la Boucle
        // n'en affaiblit aucun, et la cle inclut le tenant attendu.
        $boucle = app(ScopedReadCache::class)->souviens(
            'cible-boucle:'.$notification->organization_id.':'.$notification->object_id,
            fn (): ?Loop => Loop::query()
                ->withoutGlobalScope(BelongsToOrganizationScope::class)
                ->whereKey($notification->object_id)
                ->where('organization_id', $notification->organization_id)
                ->first(),
        );

        if ($boucle === null) {
            return null;
        }

        $tenantDuDestinataire = DB::table('users')
            ->where('id', $notification->recipient_id)
            ->value('organization_id');

        if ($tenantDuDestinataire !== $notification->organization_id) {
            return null;
        }

        $estMembreActif = LoopMember::query()
            ->where('loop_id', $boucle->id)
            ->where('user_id', $notification->recipient_id)
            ->where('status', 'active')
            ->exists();

        if (! $estMembreActif) {
            return null;
        }

        // TASK-1778 — meme raison que pour la Boucle ci-dessus : elle ne sert
        // qu'a construire l'URL, elle est identique pour tous les
        // destinataires de l'evenement, et elle etait relue 200 fois pour une
        // Boucle de 100 membres.
        $organisation = app(ScopedReadCache::class)->souviens(
            'cible-organization:'.$notification->organization_id,
            fn (): ?Organization => Organization::query()
                ->whereKey($notification->organization_id)
                ->first(),
        );

        if ($organisation === null) {
            return null;
        }

        // TASK-1720 — le SLUG dans l'URL, l'identifiant en repli.
        //
        // Cette URL part dans un COURRIEL : c'est elle qu'on lit, qu'on copie
        // et qu'on colle. Passer le modele rendait `getRouteKey()`, donc
        // l'UUID — `/org/ofsh/loops/01a16eb5-347c-…` dans le corps du message.
        //
        // Meme idiome que les vues depuis TASK-1717, et seulement sur la forme
        // ORG-SCOPEE : `Route::bind('loop', ...)` refuse un slug quand la
        // route ne porte pas d'`{organization}`.
        return route('organization.loops.show', [
            'organization' => $organisation,
            'loop' => $boucle->slug ?: $boucle->getKey(),
        ]);
    }

    private function loopJoinRequest(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        // TASK-1720 volet A — meme retrait de scope, meme raison : ce code
        // tourne aussi sous worker, ou `current_organization` n'existe pas et
        // ou le fail-closed du scope rendrait cet objet introuvable. La borne
        // de tenant est posee explicitement juste en dessous.
        $demande = LoopJoinRequest::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($notification->object_id)
            ->where('organization_id', $notification->organization_id)
            ->first();

        if ($demande === null) {
            return null;
        }

        // TASK-1720 volet A — le scope de tenant est RETIRE, et la borne
        // ecrite a la main juste en dessous.
        //
        // Ce code tourne dans un WORKER. Il n'y a alors aucune
        // `current_organization`, donc `BelongsToOrganizationScope` applique
        // son fail-closed `whereRaw('0 = 1')` et la Boucle ressort
        // INTROUVABLE — en silence. La livraison etait sautee avec le
        // diagnostic « cible plus joignable », qui est vrai du point de vue du
        // resolveur et trompeur pour qui le lit.
        //
        // Meme doctrine que `NotificationInvariants`, dont le docblock la
        // formule : « une garde d'integrite ne doit dependre d'aucun scope
        // global [...] elle doit rendre le meme verdict dans une requete HTTP,
        // dans un job de file d'attente et dans une commande artisan. »
        //
        // La frontiere de tenant n'est pas affaiblie : le `where
        // organization_id` ci-dessous la pose explicitement, et il est le seul
        // a decider.
        $boucle = Loop::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($demande->loop_id)
            ->where('organization_id', $notification->organization_id)
            ->first();

        if ($boucle === null) {
            return null;
        }

        // `users.organization_id` est mutable — une Organization supprimee
        // detache ses membres. L'ecran verifie cette appartenance et repond 404
        // sinon ; la relire ici evite d'annoncer un lien qui refusera.
        //
        // Lecture hors Eloquent, comme dans les invariants : ce code s'execute
        // aussi sous worker, ou aucune portee globale ne s'appliquerait.
        $tenantDuDestinataire = DB::table('users')
            ->where('id', $notification->recipient_id)
            ->value('organization_id');

        if ($tenantDuDestinataire !== $notification->organization_id) {
            return null;
        }

        $estMembreActif = LoopMember::query()
            ->where('loop_id', $boucle->id)
            ->where('user_id', $notification->recipient_id)
            ->where('status', 'active')
            ->exists();

        // Une Boucle archivee n'est plus decouvrable independamment : un
        // non-membre y recoit 404, la ou un membre garde son acces.
        if (! $estMembreActif && ! $boucle->isActive()) {
            return null;
        }

        $organisation = Organization::query()
            ->whereKey($notification->organization_id)
            ->first();

        if ($organisation === null) {
            return null;
        }

        // TASK-1720 — le SLUG dans l'URL, l'identifiant en repli.
        //
        // Cette URL part dans un COURRIEL : c'est elle qu'on lit, qu'on copie
        // et qu'on colle. Passer le modele rendait `getRouteKey()`, donc
        // l'UUID — `/org/ofsh/loops/01a16eb5-347c-…` dans le corps du message.
        //
        // Meme idiome que les vues depuis TASK-1717, et seulement sur la forme
        // ORG-SCOPEE : `Route::bind('loop', ...)` refuse un slug quand la
        // route ne porte pas d'`{organization}`.
        return route('organization.loops.show', [
            'organization' => $organisation,
            'loop' => $boucle->slug ?: $boucle->getKey(),
        ]);
    }

    /**
     * TASK-1697 — un commentaire mene au FIL de l'article, a l'ancre.
     *
     * Trois raisons de rendre `null`, et chacune est un cas ordinaire :
     *
     * - le commentaire a ete supprime (`SoftDeletes`) — il n'y a plus rien a
     *   lire, et l'ecran le dira honnetement ;
     * - l'article n'est plus publie — un auteur qui repasse son article en
     *   brouillon ne doit pas voir une notification ouvrir une page que le
     *   produit ne publie plus ;
     * - la frontiere de tenant ne tient plus.
     *
     * La frontiere est reverifiee ICI, comme pour les autres branches : elle
     * l'etait a l'ecriture, mais une notification survit a bien des
     * changements, et une garde qui ne s'applique qu'une fois n'est pas une
     * garde.
     *
     * L'URL est PREFIXEE par l'Organization quand la route scopee existe. Sans
     * prefixe, `ResolveUrlOrganization` lierait l'Organization par defaut et
     * la notification ferait changer de locataire — meme defaut que celui
     * corrige trois fois sur la fiche profil.
     */
    /**
     * TASK-1786 — un commentaire d'entraide mene a SON annonce, ancre sur lui.
     *
     * ## C'est ici que « une seule cle pour deux types » se paie, et se tient
     *
     * Le commentaire porte `commentable_type` : c'est lui qu'on lit pour choisir
     * entre `services.show` et `requests.show`. Une cle unique au catalogue n'a
     * donc rien coute en precision de destination — elle a seulement deplace le
     * branchement la ou l'information existe.
     *
     * ## L'URL est SCOPEE sur l'Organization de la notification
     *
     * Exigence explicite du CDC §7 : « une notification ne doit jamais rediriger
     * vers `main` lorsque l'annonce appartient a OFSH ». Le slug vient donc de
     * l'Organization de la NOTIFICATION, relue en base, et non d'un contexte de
     * requete — ce code tourne au clic, parfois sous worker.
     *
     * ## Fail-closed, comme tout ce resolver
     *
     * `null` des que quelque chose manque : commentaire supprime (`SoftDeletes`,
     * donc `find()` ne le rend plus), moderne (`is_approved` faux), annonce
     * disparue — une `ServiceRequest` est supprimee DUREMENT, elle n'a pas
     * `SoftDeletes` —, annonce passee hors tenant, ou type inconnu. L'ecran dit
     * alors honnetement « cet element n'est plus accessible » plutot que de se
     * rabattre sur une page approchante.
     *
     * Le statut de l'annonce n'est volontairement PAS reinterroge ici : la
     * politique de visibilite appartient aux controleurs `show()`, qui rendront
     * 404 a qui n'y a pas droit. La dupliquer ici installerait une seconde
     * verite, et c'est exactement ce que `blogComment()` juste en dessous evite
     * en ne jugeant que ce qui le concerne.
     */
    private function entraideComment(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        // PAS de `withoutGlobalScopes()` ici, et c'est une correction : ce
        // modele ne porte AUCUNE portee d'Organization (comme `BlogComment`),
        // donc il n'y a rien a retirer — tandis que `withoutGlobalScopes()`
        // retire AUSSI `SoftDeletingScope`, et un commentaire supprime se
        // remettait a resoudre une cible. Defaut trouve par
        // `test_un_commentaire_supprime_ne_mene_nulle_part`.
        $commentaire = EntraideComment::query()
            ->whereKey($notification->object_id)
            ->where('organization_id', $notification->organization_id)
            ->where('is_approved', true)
            ->first();

        if ($commentaire === null) {
            return null;
        }

        $type = (string) $commentaire->commentable_type;

        if (! in_array($type, EntraideComment::TYPES_COMMENTABLES, true)) {
            return null;
        }

        // Portee d'Organization retiree NOMMEMENT, et non en bloc : `Service`
        // porte `SoftDeletes`, et `withoutGlobalScopes()` le retirerait aussi —
        // une annonce supprimee redeviendrait une destination valide.
        /** @var class-string<Service|ServiceRequest> $type */
        $annonce = $type::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($commentaire->commentable_id)
            ->where('organization_id', $notification->organization_id)
            ->first();

        if ($annonce === null) {
            return null;
        }

        $organization = Organization::query()
            ->whereKey($notification->organization_id)
            ->first();

        $scopee = $annonce instanceof Service ? 'organization.services.show' : 'organization.requests.show';
        $nue = $annonce instanceof Service ? 'services.show' : 'requests.show';
        $parametre = $annonce instanceof Service ? 'service' : 'request';

        $url = $organization !== null && Route::has($scopee)
            ? route($scopee, ['organization' => $organization->slug, $parametre => $annonce->getKey()])
            : route($nue, [$parametre => $annonce->getKey()]);

        // L'ancre vise LE commentaire, pas la section : le CDC §7 demande « un
        // lien d'ancrage vers le commentaire concerne ».
        return $url.'#commentaire-'.$commentaire->id;
    }

    /**
     * TASK-1810 — le commentaire depose sous un item du Flux.
     *
     * Ni `FeedPost` ni `FeedPostComment` ne portent `BelongsToOrganizationScope`
     * (confirme par grep avant d'ecrire cette methode) : rien a retirer, la
     * frontiere de tenant est posee a la main par le `where('organization_id', …)`
     * ci-dessous, comme pour `entraideComment()`.
     */
    private function feedPostComment(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        $commentaire = FeedPostComment::query()
            ->whereKey($notification->object_id)
            ->where('organization_id', $notification->organization_id)
            ->where('is_approved', true)
            ->first();

        if ($commentaire === null) {
            return null;
        }

        $post = FeedPost::query()
            ->whereKey($commentaire->feed_post_id)
            ->where('organization_id', $notification->organization_id)
            ->first();

        if ($post === null) {
            return null;
        }

        // L'ancre vise LE commentaire, meme idiome que `entraideComment()`.
        return $post->announcementUrl().'#commentaire-'.$commentaire->id;
    }

    /**
     * TASK-1810 — une reaction mene a l'ITEM lui-meme : il n'existe aucune
     * fiche dediee a une reaction, et `object_id` designe directement
     * `feed_posts.id` (voir `OBJECT_FEED_POST`).
     */
    private function feedPost(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        $post = FeedPost::query()
            ->whereKey($notification->object_id)
            ->where('organization_id', $notification->organization_id)
            ->first();

        return $post?->announcementUrl();
    }

    private function blogComment(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        $comment = BlogComment::query()
            ->whereKey($notification->object_id)
            ->where('organization_id', $notification->organization_id)
            ->first();

        if ($comment === null) {
            return null;
        }

        $post = $comment->post;

        if ($post === null
            || $post->organization_id !== $notification->organization_id
            || $post->status !== 'published'
            || $post->published_at === null
            || $post->published_at->isFuture()) {
            return null;
        }

        $organization = Organization::query()
            ->whereKey($notification->organization_id)
            ->first();

        $url = $organization !== null && Route::has('organization.blog.show')
            ? route('organization.blog.show', ['organization' => $organization->slug, 'post' => $post->slug])
            : route('blog.show', $post->slug);

        return $url.'#commentaires';
    }

    /**
     * TASK-1702 — une co-ecriture mene a l'EDITEUR de l'article.
     *
     * Et pas a sa page publique, contrairement a un commentaire : un article
     * co-ecrit est le plus souvent un BROUILLON. `blogComment()` juste au-dessus
     * exige `status === 'published'` — appliquer la meme regle ici rendrait la
     * notification morte dans le cas le plus courant.
     *
     * ## La cible est reevaluee au CLIC, et elle est fail-closed
     *
     * C'est la doctrine de ce resolveur : la notification ne detient aucune
     * autorite, elle designe seulement un objet. On verifie donc ici, au moment
     * ou le membre clique, qu'il est TOUJOURS co-auteur — on peut l'avoir
     * retire entre-temps. S'il ne l'est plus, la notification ne mene nulle
     * part plutot que vers un 403.
     *
     * Le proprietaire de l'article n'est pas traite a part : il ne recoit
     * jamais cette notification, personne ne l'ajoutant comme co-auteur de son
     * propre article.
     */
    private function blogPost(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        $post = BlogPost::query()
            ->whereKey($notification->object_id)
            ->where('organization_id', $notification->organization_id)
            ->first();

        if ($post === null) {
            return null;
        }

        $estEncoreCoAuteur = $post->coAuthors()
            ->where('users.id', $notification->recipient_id)
            ->exists();

        if (! $estEncoreCoAuteur) {
            return null;
        }

        $organization = Organization::query()
            ->whereKey($notification->organization_id)
            ->first();

        if ($organization === null || ! Route::has('organization.blog.edit')) {
            return null;
        }

        return route('organization.blog.edit', [
            'organization' => $organization->slug,
            'post' => $post->slug,
        ]);
    }

    /**
     * TASK-1751 — un echange mene a SA conversation, pas a l'annonce.
     *
     * C'est dans la conversation que la decision se prend : `MessageController`
     * y rend deja « Accepter » et « Refuser » pour une Transaction `pending`.
     * Renvoyer vers la fiche du service ou de la demande ferait relire au
     * destinataire sa propre annonce, sans action possible.
     *
     * ## La garde d'appartenance, et pourquoi elle n'est pas decorative
     *
     * Le destinataire doit TOUJOURS etre partie a l'echange — c'est ce
     * qu'exige `TransactionPolicy::view`. Le lien est resolu au moment du
     * CLIC, pas de l'emission : entre les deux, la situation peut avoir
     * change. Sans ce controle, on fabriquerait un lien vers une conversation
     * ou la personne se ferait refuser a l'arrivee.
     *
     * `withoutGlobalScope` : le resolveur tourne en file, hors d'une requete
     * HTTP, donc sans Organization courante — le scope ne trouverait rien. La
     * portee est retablie explicitement par le `where` sur
     * `organization_id`, qui est la vraie garde de locataire.
     */
    private function transaction(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        $transaction = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($notification->object_id)
            ->where('organization_id', $notification->organization_id)
            ->first();

        if ($transaction === null) {
            return null;
        }

        if ($notification->recipient_id !== $transaction->buyer_id
            && $notification->recipient_id !== $transaction->seller_id) {
            return null;
        }

        $organization = Organization::query()
            ->whereKey($notification->organization_id)
            ->first();

        if ($organization === null) {
            return null;
        }

        return Route::has('organization.messages.show')
            ? route('organization.messages.show', [
                'organization' => $organization->slug,
                'transaction' => $transaction->id,
            ])
            : route('messages.show', ['transaction' => $transaction->id]);
    }
}
