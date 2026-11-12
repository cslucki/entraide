<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMessage;
use App\Models\EntraideComment;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Entraide\TempsPartage;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationTargetResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * TASK-1373 — le Centre de notifications d'un membre.
 *
 * ## Un controleur, pas un composant Livewire
 *
 * Le choix n'est pas stylistique. Une action Livewire passe par
 * `/livewire-{hash}/update`, et ce chemin resout l'Organization **par defaut de
 * la plateforme** (`ResolveUrlOrganization::isFeatureRoute()` renvoie vrai pour
 * tout segment commencant par `livewire-`) : le tenant devrait alors etre fige
 * depuis l'acteur et re-verifie a chaque hydratation, puisqu'un instantane
 * Livewire est une capacite durable, sans nonce ni expiration.
 *
 * Un POST classique suivi d'une redirection n'a aucun de ces problemes, et le
 * badge du rail — rendu serveur — se recalcule de lui-meme a la page suivante.
 * Le probleme le plus simple a resoudre est celui qu'on n'a pas.
 *
 * ## L'Organization vient du MEMBRE, pas du contexte
 *
 * `$user->organization ?? currentOrganization()`, et dans cet ordre. Sur la
 * route non prefixee, l'« Organization courante » est celle par defaut de la
 * plateforme : un membre y verrait le compteur d'un tenant qui n'est pas le
 * sien. C'est la regle deja ecrite dans `UserAiUsageController::index()`, et le
 * rail utilise la MEME expression — sinon le badge et la page se
 * contrediraient.
 *
 * ## Les objets SONT resolus, au clic
 *
 * Une notification ne porte que des references (`object_type` + `object_id`).
 * `open()` les resout via `NotificationTargetResolver`, sous les permissions du
 * moment, et affiche un etat honnete quand la cible a disparu.
 *
 * TASK-1382 — ce paragraphe disait l'inverse (« cette tranche ne resout pas
 * encore les objets »), ce qui a cesse d'etre vrai des TASK-1374. Il decrivait
 * l'etat du jour ou il a ete ecrit, pas celui du code qu'il documente. Un
 * commentaire perime ne vieillit pas : il devient faux, et il oriente la
 * relecture suivante contre le code.
 */
class NotificationCenterController extends Controller
{
    public const FILTRE_TOUTES = 'toutes';

    public const FILTRE_NON_LUES = 'non-lues';

    private const PAR_PAGE = 25;

    public function index(Request $request): View|RedirectResponse
    {
        [$user, $organization] = $this->acteur($request);

        $filtre = $this->filtre($request);

        $query = MemberNotification::query()
            ->forRecipient((string) $organization->id, (string) $user->id)
            // Depart sur `created_at` a la seconde : trois precedents dans ce
            // depot montrent que deux lignes creees dans le meme test partagent
            // la seconde, et qu'un SQLite vert ne prouve alors rien.
            ->latest('created_at')
            ->latest('id');

        if ($filtre === self::FILTRE_NON_LUES) {
            $query->unread();
        }

        $notifications = $query->paginate(self::PAR_PAGE)->withQueryString();

        // TASK-1775 — distinguer « rien n'est jamais arrive » de « tout est
        // deja lu » exige desormais une mesure separee du filtre affiche.
        //
        // Avant cette TASK, le defaut etait « toutes » : une boite vide s'y
        // voyait directement, sans requete de plus. Le defaut etant devenu
        // « non-lues », une boite jamais alimentee ET une boite entierement
        // lue produisent exactement la MEME liste filtree vide — seule une
        // question posee EN DEHORS du filtre les distingue. Sans elle, un
        // membre qui n'a jamais rien recu lirait « Tout est lu », qui laisse
        // croire a un historique qu'il n'a pas.
        //
        // La requete ne part QUE quand elle peut changer la reponse : sur le
        // filtre « toutes », la liste vide EST deja la preuve qu'il n'y a
        // rien eu.
        $dejaUneNotification = $filtre === self::FILTRE_NON_LUES && $notifications->isEmpty()
            ? MemberNotification::query()
                ->forRecipient((string) $organization->id, (string) $user->id)
                ->exists()
            : false;

        // TASK-1382 — une page hors borne ne doit pas se faire passer pour un
        // etat vide.
        //
        // Le scenario est ordinaire : un membre a 30 non-lues, va page 2, en
        // ouvre six. Il en reste 24, donc UNE page — et la page 2 devient hors
        // borne. `paginate()` rend alors une collection vide sans rien
        // signaler, et la vue conclut « Tout est lu » pendant que le badge du
        // rail affiche 24.
        //
        // Les deux lisent pourtant la meme base : ce n'est pas un desaccord de
        // donnees, c'est la page vide qui est interpretee comme un vide reel.
        // Et un badge contredit par l'ecran qu'il pointe est un badge qu'on
        // cesse de regarder.
        //
        // La redirection preserve la chaine de requete — donc le filtre —
        // sinon corriger la page changerait aussi ce que le membre regardait.
        if ($notifications->isEmpty() && $notifications->total() > 0) {
            return redirect()->to($notifications->url($notifications->lastPage()));
        }

        // Les noms de route sont resolus ICI. Les laisser a la vue l'obligerait
        // a re-deviner le contexte org-scope a chaque bouton, et une vue qui
        // devine est une vue qui se trompera.
        $slug = $request->route('organization');

        return view('notifications.index', [
            // TASK-1725 — le NOM de la Boucle concernee, par notification.
            // TASK-1725 — le NOM de la Boucle concernee, par notification.
            // TASK-1757 — et, pour une entraide, le prenom + le titre de
            // l'annonce. Les deux cartes sont indexees par identifiant de
            // notification et portent sur des `object_type` DISJOINTS : leur
            // union ne peut pas se recouvrir.
            // TASK-1772 — une TROISIEME carte, et l'ordre de l'union compte.
            //
            // `+` garde la valeur de GAUCHE. Les trois cartes portent donc des
            // `object_type` DISJOINTS, et c'est la prémisse qui les autorise a
            // s'unir : `OBJECT_LOOP*` pour la premiere, `OBJECT_TRANSACTION`
            // pour la deuxieme, `OBJECT_LOOP_MESSAGE` pour la troisieme.
            //
            // C'est exactement pour preserver cette prémisse que la mention a
            // recu son PROPRE type d'objet au lieu de reutiliser `OBJECT_LOOP` :
            // avec `OBJECT_LOOP`, la premiere carte aurait gagne et la
            // troisieme aurait ete ignoree EN SILENCE.
            'sujets' => $this->sujetsDesBoucles($notifications->getCollection(), (string) $organization->id)
                + $this->sujetsDEntraide($notifications->getCollection(), (string) $organization->id, $user)
                + $this->sujetsDesMentions($notifications->getCollection(), (string) $organization->id)
                // TASK-1786 — quatrieme carte, et la prémisse tient : son type
                // d'objet est `OBJECT_ENTRAIDE_COMMENT`, DISJOINT des trois
                // autres. C'est precisement pour ne pas la rompre que le
                // commentaire d'entraide a recu son propre type d'objet au lieu
                // de reutiliser `OBJECT_TRANSACTION`.
                + $this->sujetsDesCommentairesDEntraide($notifications->getCollection(), (string) $organization->id, $user),
            'notifications' => $notifications,
            'filtre' => $filtre,
            'dejaUneNotification' => $dejaUneNotification,
            'nonLues' => $user->unreadNotificationsCount((string) $organization->id),
            'routeParams' => $slug ? ['organization' => $slug] : [],
            'routeRead' => $slug ? 'organization.notifications.read' : 'notifications.read',
            'routeUnread' => $slug ? 'organization.notifications.unread' : 'notifications.unread',
            'routeOpen' => $slug ? 'organization.notifications.open' : 'notifications.open',
            'routeReadAll' => $slug ? 'organization.notifications.read-all' : 'notifications.read-all',
            // TASK-1739 — l'acces aux reglages depuis le centre. Il manquait :
            // `notifications.preferences.edit` existait depuis T1375 sans
            // qu'aucune vue ne pointe dessus.
            'routePreferences' => $slug ? 'organization.notifications.preferences.edit' : 'notifications.preferences.edit',
        ]);
    }

    /**
     * TASK-1757 (§7 du CDC entraide) — nommer la personne et l'annonce.
     *
     * ## Pourquoi SEULEMENT sur cette surface
     *
     * Le §7 demande « titre de notif T1751 avec prenom + annonce ». T1751 avait
     * garde le titre generique a dessein, et pour une bonne raison, ecrite dans
     * `lang/fr/notifications.php` : un Push s'affiche sur un ECRAN VERROUILLE
     * (CDC notifications §6.4/6.5). Nommer la personne et l'annonce y exposerait
     * l'entraide de quelqu'un a qui passe devant le telephone.
     *
     * Les deux CDC ne se contredisent qu'en apparence : les SURFACES sont
     * differentes. `push_title`, `push_body` et le sujet d'email restent
     * generiques ; la liste en-appli, elle, n'est lisible qu'une fois
     * authentifie — c'est la que le fait se raconte en entier.
     *
     * ## Meme doctrine que T1725, sans y deroger
     *
     * Resolu AU RENDU (le stockage ne retient aucun texte), EN LOT (une requete
     * pour la page, pas une par ligne), et borne au tenant par un
     * `where('organization_id')` EXPLICITE apres `withoutGlobalScopes()` —
     * jamais l'un sans l'autre, lecon de T1755.
     *
     * ## Et le lecteur doit etre PARTIE a l'echange
     *
     * C'est la version entraide de « le nom n'est montre que si la cible est
     * joignable ». Une notification peut survivre a bien des changements ; si le
     * lecteur n'est plus ni l'aidant ni l'aide, la ligne retombe sur la forme
     * generique, qui reste vraie.
     *
     * @param  Collection<int, MemberNotification>  $notifications
     * @return array<string, array{prenom: string, titre: string, temps: string}>
     */
    private function sujetsDEntraide($notifications, string $organizationId, User $lecteur): array
    {
        $lignes = $notifications->groupBy('object_type')
            ->get(NotificationCatalogue::OBJECT_TRANSACTION);

        if ($lignes === null || $lignes->isEmpty()) {
            return [];
        }

        $identifiants = $lignes->pluck('object_id')->filter()->unique()->all();

        if ($identifiants === []) {
            return [];
        }

        $echanges = Transaction::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $identifiants)
            ->where('organization_id', $organizationId)
            ->with(['buyer', 'seller', 'service', 'serviceRequest'])
            ->get()
            ->keyBy('id');

        $sujets = [];

        foreach ($lignes as $notification) {
            $echange = $echanges[(string) $notification->object_id] ?? null;

            if ($echange === null) {
                continue;
            }

            // Le lecteur doit etre partie a l'echange, et l'echange doit porter
            // une annonce : une discussion directe n'a ni titre ni temps a dire.
            if (! $echange->estLAidant($lecteur) && ! $echange->estLAide($lecteur)) {
                continue;
            }

            if ($echange->isDirectConversation()) {
                continue;
            }

            // Celui qui a PROPOSE, c'est-a-dire celui qui n'a pas recu cette
            // notification. `proposerId()` et non une deduction sur buyer/seller :
            // le sens s'inverse entre une proposition et une demande (T1693 bug S).
            $proposant = $echange->proposerId() === $echange->buyer_id
                ? $echange->buyer
                : $echange->seller;

            $prenom = $proposant?->first_name ?: ($proposant?->name ?: null);

            if ($prenom === null || $prenom === '') {
                continue;
            }

            $sujets[(string) $notification->id] = [
                'prenom' => $prenom,
                'titre' => $echange->subject,
                'temps' => TempsPartage::libelle(
                    $echange->points_agreed ?? $echange->points_proposed
                ),
            ];
        }

        return $sujets;
    }

    /**
     * TASK-1725 (demande Cyril, 29/10) — nommer la Boucle dont il est question.
     *
     * ## Le defaut
     *
     * « Ajout a une Boucle » et « Votre demande d'adhesion a ete acceptee » ne
     * disent pas LAQUELLE. Un membre de plusieurs Boucles lisait donc un fait
     * sans objet, et devait ouvrir pour savoir de quoi on lui parlait — ce que
     * le libelle existe precisement pour lui epargner.
     *
     * ## Resolu au RENDU, et le stockage reste muet
     *
     * La doctrine de cet ecran est qu'une notification ne porte que des
     * references, aucun texte : c'est ce qui fait qu'une notification vieille
     * d'un mois n'ouvre aucune porte fermee depuis, et qu'un nom de Boucle
     * change se repercute partout sans migration. Elle n'est pas enfreinte ici
     * — rien de nouveau n'est ECRIT. Le nom est LU au moment de l'affichage, a
     * la source, exactement comme la destination est lue au clic.
     *
     * ## En LOT, et ce n'est pas une optimisation gratuite
     *
     * La page en affiche vingt-cinq. Resoudre ligne par ligne ferait jusqu'a
     * cinquante requetes — une par demande ou invitation, puis une par Boucle —
     * sur un ecran que le membre ouvre a chaque badge. Deux requetes par type
     * d'objet suffisent, et le cout cesse de dependre du nombre de lignes.
     *
     * ## Le nom n'est montre QUE si la cible est joignable
     *
     * Premiere version de ce correctif : le nom etait affiche des que la Boucle
     * existait dans le tenant, au motif qu'il avait de toute facon deja ete
     * porte a la connaissance de la personne. C'etait FAUX, et
     * `TASK1374...::test_the_unreachable_state_leaks_nothing_about_the_target`
     * l'a montre : une invitation REVOQUEE doit cesser de nommer la Boucle. Ce
     * test ne garde pas une preference de libelle, il garde une regle — l'ecran
     * ne laisse filtrer aucun fragment d'une cible dont la porte s'est refermee.
     *
     * Le verdict est donc demande a `NotificationTargetResolver`, seule autorite
     * sur ce point. Recopier ici ses regles — invitation encore en attente,
     * destinataire toujours dans l'Organization, Boucle non archivee,
     * appartenance active — aurait fabrique une seconde autorite, et les deux
     * copies auraient divergé au premier changement.
     *
     * ## Le cout, assume et borne
     *
     * Le resolveur travaille par notification : jusqu'a vingt-cinq appels sur
     * une page pleine, un a deux acces chacun. C'est le prix de la correction,
     * et il est paye uniquement sur les lignes qui PEUVENT etre nommees — une
     * notification de commentaire ou d'article n'en declenche aucun. La
     * resolution des NOMS, elle, reste en lot : une requete par type d'objet,
     * plus une pour les Boucles, quel que soit le nombre de lignes.
     *
     * ## La borne de tenant est posee a la main
     *
     * Les scopes globaux sont retires — cette lecture doit marcher quel que
     * soit le contexte ambiant — donc `organization_id` est compare
     * explicitement. La notification a deja ete filtree par `forRecipient()` ;
     * la Boucle est reverifiee quand meme, parce qu'une garde qui ne s'applique
     * qu'une fois n'est pas une garde.
     *
     * @param  Collection<int, MemberNotification>  $notifications
     * @return array<string, string> identifiant de notification => nom de la Boucle
     */
    /**
     * TASK-1772 — nommer QUI a mentionne, et DANS QUELLE Boucle.
     *
     * Rend un TABLEAU et non une chaine : le libelle nomme a deux parametres
     * (`:prenom` et `:loop`), la ou les notifications de Boucle n'en ont
     * qu'un. La vue accepte les deux formes.
     *
     * Le nom n'est montre QUE si la cible est encore joignable, et le verdict
     * est DEMANDE au resolveur plutot que re-derive : si la porte s'est
     * refermee, l'ecran dira « plus accessible » au clic, et nommer la Boucle
     * a cote laisserait filtrer ce que le membre n'a plus le droit de voir.
     *
     * @param  Collection<int, MemberNotification>  $notifications
     * @return array<string, array{prenom: string, loop: string}>
     */
    /**
     * TASK-1786 — nommer le commentateur et l'annonce, dans le CENTRE seulement.
     *
     * ## Pourquoi ici et nulle part ailleurs
     *
     * Meme doctrine que `sujetsDEntraide()` juste au-dessus, et pour la meme
     * raison : cette liste n'est lisible qu'une fois AUTHENTIFIE, donc le fait
     * peut s'y raconter en entier. `push_title`, `push_body` et le sujet d'email
     * restent generiques — un Push s'affiche sur un ecran verrouille, un email
     * se previsualise sans deverrouiller.
     *
     * C'est le texte que le CDC T1786 §7 demande : « Marie a commente votre
     * proposition d'aide : Creation d'un site web. »
     *
     * ## `:type` est resolu ICI, et c'est ce qui permet UNE seule cle
     *
     * Le libelle porte `:type`, rempli par « proposition d'entraide » ou
     * « demande d'aide ». Le catalogue n'a donc qu'une cle pour les deux types
     * d'annonce, et la nuance de vocabulaire est traitee a l'affichage — la ou
     * elle appartient.
     *
     * ## Et le lecteur doit etre l'AUTEUR de l'annonce
     *
     * Version annonce de « le nom n'est montre que si la cible est joignable » :
     * une notification survit a bien des changements, et si le lecteur n'est
     * plus l'auteur de l'annonce, la ligne retombe sur la forme generique, qui
     * reste vraie. Le destinataire EST l'auteur par construction ; on ne s'en
     * remet pas a cette construction pour decider d'afficher un nom.
     *
     * Resolu AU RENDU (le stockage ne retient aucun texte), EN LOT (une requete
     * pour la page, pas une par ligne), et borne au tenant par un
     * `where('organization_id')` EXPLICITE apres `withoutGlobalScopes()` —
     * jamais l'un sans l'autre, lecon de T1755.
     *
     * @param  Collection<int, MemberNotification>  $notifications
     * @return array<string, array{prenom: string, titre: string, type: string}>
     */
    private function sujetsDesCommentairesDEntraide($notifications, string $organizationId, User $lecteur): array
    {
        $lignes = $notifications->groupBy('object_type')
            ->get(NotificationCatalogue::OBJECT_ENTRAIDE_COMMENT);

        if ($lignes === null || $lignes->isEmpty()) {
            return [];
        }

        $identifiants = $lignes->pluck('object_id')->filter()->unique()->all();

        if ($identifiants === []) {
            return [];
        }

        $commentaires = EntraideComment::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $identifiants)
            ->where('organization_id', $organizationId)
            ->with('user')
            ->get()
            ->keyBy('id');

        // Les annonces en DEUX requetes groupees, une par type : une relation
        // polymorphique ne se charge pas en un `whereIn` unique, et une requete
        // par ligne ramenerait le probleme N+1 que cette methode existe pour
        // eviter.
        $annonces = [];

        foreach ([Service::class, ServiceRequest::class] as $classe) {
            $cibles = $commentaires
                ->where('commentable_type', $classe)
                ->pluck('commentable_id')
                ->filter()
                ->unique()
                ->all();

            if ($cibles === []) {
                continue;
            }

            $annonces[$classe] = $classe::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $cibles)
                ->where('organization_id', $organizationId)
                ->get()
                ->keyBy('id');
        }

        $sujets = [];

        foreach ($lignes as $notification) {
            $commentaire = $commentaires[(string) $notification->object_id] ?? null;

            if ($commentaire === null) {
                continue;
            }

            $classe = (string) $commentaire->commentable_type;
            $annonce = $annonces[$classe][(string) $commentaire->commentable_id] ?? null;

            if ($annonce === null) {
                continue;
            }

            // Le lecteur doit etre l'auteur de l'annonce commentee.
            if ((string) $annonce->user_id !== (string) $lecteur->id) {
                continue;
            }

            $prenom = $commentaire->user?->first_name ?: ($commentaire->user?->name ?: null);

            if ($prenom === null || $prenom === '') {
                continue;
            }

            $sujets[(string) $notification->id] = [
                'prenom' => $prenom,
                'titre' => (string) $annonce->title,
                'type' => $annonce instanceof Service
                    ? __('entraide.commentaires.type_proposition')
                    : __('entraide.commentaires.type_demande'),
            ];
        }

        return $sujets;
    }

    private function sujetsDesMentions($notifications, string $organizationId): array
    {
        $lignes = $notifications->groupBy('object_type')
            ->get(NotificationCatalogue::OBJECT_LOOP_MESSAGE);

        if ($lignes === null || $lignes->isEmpty()) {
            return [];
        }

        $identifiants = $lignes->pluck('object_id')->filter()->unique()->all();

        if ($identifiants === []) {
            return [];
        }

        $messages = LoopMessage::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $identifiants)
            ->where('organization_id', $organizationId)
            ->get()
            ->keyBy(fn ($m) => (string) $m->id);

        $noms = Loop::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $messages->pluck('loop_id')->filter()->unique()->all())
            ->where('organization_id', $organizationId)
            ->pluck('name', 'id');

        $auteurs = User::query()
            ->whereIn('id', $lignes->pluck('actor_id')->filter()->unique()->all())
            ->get()
            ->keyBy(fn ($u) => (string) $u->id);

        $cibles = app(NotificationTargetResolver::class);
        $sujets = [];

        foreach ($lignes as $notification) {
            $message = $messages->get((string) $notification->object_id);

            if ($message === null) {
                continue;
            }

            $nomBoucle = $noms[(string) $message->loop_id] ?? null;
            $auteur = $notification->actor_id ? $auteurs->get((string) $notification->actor_id) : null;

            if (! is_string($nomBoucle) || $nomBoucle === '' || $auteur === null) {
                continue;
            }

            if ($cibles->resolve($notification) === null) {
                continue;
            }

            $sujets[(string) $notification->id] = [
                'prenom' => $auteur->publicDisplayName(),
                'loop' => $nomBoucle,
                // TASK-1797 — lu sur le MESSAGE, jamais recalcule ici : c'est
                // `LoopChat`/`LoopMessageService` qui decident `mention_all`
                // au moment de l'envoi, et cette ligne ne fait que le
                // transmettre a la vue, qui choisit entre `keys_named.
                // loop_mention_created` et sa variante `_all_created`.
                'tous' => (bool) ($message->metadata['mention_all'] ?? false),
            ];
        }

        return $sujets;
    }

    private function sujetsDesBoucles($notifications, string $organizationId): array
    {
        // notification => identifiant de Boucle, construit en trois passes selon
        // ce que `object_id` designe : la Boucle elle-meme, une demande, ou une
        // invitation.
        $boucleParNotification = [];

        $parType = $notifications->groupBy('object_type');

        $direct = $parType->get(NotificationCatalogue::OBJECT_LOOP);
        foreach ($direct ?? [] as $notification) {
            if ($notification->object_id !== null) {
                $boucleParNotification[(string) $notification->id] = (string) $notification->object_id;
            }
        }

        $indirects = [
            NotificationCatalogue::OBJECT_LOOP_JOIN_REQUEST => LoopJoinRequest::class,
            NotificationCatalogue::OBJECT_LOOP_INVITATION => LoopInvitation::class,
        ];

        foreach ($indirects as $type => $modele) {
            $lignes = $parType->get($type);

            if ($lignes === null || $lignes->isEmpty()) {
                continue;
            }

            $identifiants = $lignes->pluck('object_id')->filter()->unique()->all();

            if ($identifiants === []) {
                continue;
            }

            $boucleParObjet = $modele::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $identifiants)
                ->where('organization_id', $organizationId)
                ->pluck('loop_id', 'id');

            foreach ($lignes as $notification) {
                $boucle = $boucleParObjet[(string) $notification->object_id] ?? null;

                if ($boucle !== null) {
                    $boucleParNotification[(string) $notification->id] = (string) $boucle;
                }
            }
        }

        if ($boucleParNotification === []) {
            return [];
        }

        $noms = Loop::query()
            ->withoutGlobalScopes()
            ->whereIn('id', array_values(array_unique($boucleParNotification)))
            ->where('organization_id', $organizationId)
            ->pluck('name', 'id');

        $cibles = app(NotificationTargetResolver::class);
        $parIdentifiant = $notifications->keyBy(fn ($notification) => (string) $notification->id);

        $sujets = [];

        foreach ($boucleParNotification as $notificationId => $boucleId) {
            $nom = $noms[$boucleId] ?? null;

            // Une Boucle supprimee, ou d'un autre tenant, ne donne PAS de nom :
            // la ligne retombe alors sur le libelle sans objet, qui reste juste.
            if (! is_string($nom) || $nom === '') {
                continue;
            }

            $notification = $parIdentifiant->get($notificationId);

            // La porte s'est-elle refermee ? Si oui, on ne nomme RIEN — l'ecran
            // dira « plus accessible » au clic, et le libelle generique est
            // alors la seule forme honnete.
            if ($notification === null || $cibles->resolve($notification) === null) {
                continue;
            }

            $sujets[$notificationId] = $nom;
        }

        return $sujets;
    }

    public function read(Request $request): RedirectResponse
    {
        [$user, $organization] = $this->acteur($request);

        $this->resoudre($this->identifiant($request), (string) $organization->id, (string) $user->id)
            ->markAsReadFor((string) $organization->id, (string) $user->id);

        return back();
    }

    /**
     * TASK-1775 — l'inverse symmetrique de `read()` : remettre une
     * notification deja lue dans la file des non-lues.
     *
     * Meme forme que `read()` — meme resolution tenant+destinataire, meme
     * redirection — par symetrie avec le reste de l'ecran. `resoudre()` ne
     * distingue pas lu/non lu : le controle « seulement sur une notification
     * deja lue » appartient a la vue (l'action n'est proposee que la ou elle a
     * un sens), et `markAsUnreadFor()` reste idempotent si elle est quand meme
     * appelee sur une ligne deja non lue.
     */
    public function markUnread(Request $request): RedirectResponse
    {
        [$user, $organization] = $this->acteur($request);

        $this->resoudre($this->identifiant($request), (string) $organization->id, (string) $user->id)
            ->markAsUnreadFor((string) $organization->id, (string) $user->id);

        return back();
    }

    /**
     * Ouvrir une notification : la marquer lue, PUIS resoudre sa cible.
     *
     * ## Un POST, pas un lien
     *
     * Ouvrir mute l'etat de lecture. Un GET qui mute est un GET qui se rejoue —
     * par un prefetch, un scanner de liens, un bouton « precedent ». Le clic est
     * donc un formulaire, comme les deux autres actions de cet ecran.
     *
     * ## L'ordre n'est pas negociable
     *
     * 1. verifier que la notification appartient a ce membre, dans ce tenant ;
     * 2. la marquer lue ;
     * 3. resoudre la reference sous les permissions ACTUELLES ;
     * 4. rediriger, ou dire honnetement que la cible n'est plus accessible.
     *
     * **Le marquage vient AVANT la resolution, et il tient meme quand la cible a
     * disparu.** C'est contre-intuitif une seconde, puis evident : le membre a
     * pris connaissance du signal, et l'application lui a repondu. Laisser la
     * notification non lue condamnerait le badge a signaler indefiniment quelque
     * chose de deja traite — et un badge qui ment est pire qu'un badge absent,
     * parce qu'on cesse de le regarder.
     */
    public function open(Request $request, NotificationTargetResolver $resolver): RedirectResponse
    {
        [$user, $organization] = $this->acteur($request);

        $ligne = $this->resoudre($this->identifiant($request), (string) $organization->id, (string) $user->id);

        $ligne->markAsReadFor((string) $organization->id, (string) $user->id);

        $cible = $resolver->resolve($ligne);

        return $cible === null
            ? back()->with('notification_unreachable', __('notifications.target_unreachable'))
            : redirect()->to($cible);
    }

    /**
     * Tout marquer lu, une ligne a la fois et par la porte du modele.
     *
     * Un `update(['read_at' => now()])` de masse serait plus court et faux :
     * `read_at` n'est pas affectable en masse, et surtout le geste doit nommer
     * son proprietaire. `markAsReadFor()` refuse bruyamment sinon, et reste
     * idempotent — la premiere lecture fait foi.
     */
    public function readAll(Request $request): RedirectResponse
    {
        [$user, $organization] = $this->acteur($request);

        MemberNotification::query()
            ->forRecipient((string) $organization->id, (string) $user->id)
            ->unread()
            ->each(fn (MemberNotification $n) => $n->markAsReadFor(
                (string) $organization->id,
                (string) $user->id,
            ));

        return back();
    }

    /**
     * L'identifiant de la notification, lu EXPLICITEMENT sur la route.
     *
     * Le prendre en argument de methode revenait a dependre de la facon dont le
     * framework apparie les parametres — et la variante org-scopee porte un
     * segment `{organization}` avant lui. Le lire par son nom ne laisse aucune
     * place a cette question.
     *
     * Defaut a chaine vide plutot qu'a `null` : la garde de forme qui suit s'en
     * charge, et rend 404 comme pour n'importe quelle valeur invalide.
     */
    private function identifiant(Request $request): string
    {
        return (string) $request->route('notification');
    }

    /**
     * Le couple (membre, Organization), re-resolu a CHAQUE action.
     *
     * @return array{0: User, 1: Organization}
     */
    private function acteur(Request $request): array
    {
        $user = $request->user();
        $organization = $user?->organization ?? currentOrganization();

        abort_unless($user !== null && $organization !== null, 404);

        return [$user, $organization];
    }

    /**
     * Le filtre vient du client : il passe par une liste blanche.
     *
     * TASK-1775 — le defaut (absence de `?filtre`) devient « non-lues » : un
     * membre qui ouvre le centre doit voir ce qui reclame son attention, pas
     * tout son historique. Toute autre valeur, y compris une valeur inconnue,
     * retombe aussi sur « non-lues » plutot que d'atteindre la requete — un
     * filtre inconnu n'est pas une erreur a signaler, c'est une valeur a
     * ignorer. Seule la demande EXPLICITE de « toutes » montre tout.
     */
    private function filtre(Request $request): string
    {
        $demande = (string) $request->query('filtre', self::FILTRE_NON_LUES);

        return $demande === self::FILTRE_TOUTES
            ? self::FILTRE_TOUTES
            : self::FILTRE_NON_LUES;
    }

    /**
     * Resout une notification DANS le tenant et POUR ce membre.
     *
     * Le controle de forme vient AVANT la requete : sous PostgreSQL la colonne
     * est un `uuid` natif, et une chaine qui n'en est pas un rend `SQLSTATE
     * 22P02` — donc un 500 — la ou SQLite se contente de ne rien trouver.
     *
     * Et c'est `404`, jamais `403` : distinguer l'inexistant de l'interdit
     * ferait de cette route un oracle sur les notifications des autres.
     */
    private function resoudre(string $id, string $organizationId, string $recipientId): MemberNotification
    {
        abort_unless(Str::isUuid($id), 404);

        $notification = MemberNotification::query()
            ->forRecipient($organizationId, $recipientId)
            ->whereKey($id)
            ->first();

        abort_unless($notification !== null, 404);

        return $notification;
    }
}
