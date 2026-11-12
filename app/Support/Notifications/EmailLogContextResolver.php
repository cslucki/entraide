<?php

namespace App\Support\Notifications;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\EmailLog;
use App\Models\EntraideComment;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMessage;
use App\Models\MemberNotification;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * TASK-1800 — le « Contexte » d'un email dans `/admin/email-logs`, et son lien.
 *
 * ## Le defaut que cet ecran avait
 *
 * `/admin/email-logs` montre QUI a recu quoi et QUAND, mais jamais DE QUOI
 * ca parlait : une mention dans une Boucle, un message prive d'echange, un
 * commentaire d'entraide et un test d'envoi d'admin se ressemblent tous une
 * fois reduits a une adresse et un sujet. Demande de Cyril (10/11), puis
 * demande d'un LIEN sur chaque libelle (10/11, meme jour).
 *
 * ## Deux familles de lignes dans `email_logs`, et deux sources de verite
 *
 * - les lignes ISSUES du pipeline de notifications portent `notification_id`
 *   (TASK-1377) : la MemberNotification associee porte `notification_key` et
 *   `object_type`/`object_id`, deja la source de verite unique du RESOLVEUR
 *   de cible (`NotificationTargetResolver`). On ne la reecrit pas ici : on
 *   lit `NotificationCatalogue::definition()` pour la CATEGORIE (loop, blog,
 *   transaction, entraide), et l'`object_type` de la ligne elle-meme — pas
 *   une deuxieme copie du contrat — pour savoir QUEL modele charger ;
 * - les lignes qui n'en portent PAS (TASK-1659, `data->source`) viennent
 *   d'un mailer hors pipeline de notifications. Leur `source` est deja ecrit
 *   par CHAQUE appelant ; on ne fait que le TRADUIRE en texte lu, SANS lien :
 *   aucune route admin dediee n'existe pour ces chemins-la.
 *
 * ## Deux liens pour une mention : l'ADMIN (Boucle) et le PUBLIC (message)
 *
 * `admin.loops.show` accepte tout SuperAdmin, quelle que soit son
 * Organization — toujours offert comme lien PRINCIPAL. Mais pour la
 * SOURCE du message lui-meme (demande explicite de Cyril, relecture en
 * situation reelle), ce resolveur reutilise la MEME URL publique que
 * `NotificationTargetResolver::loopMessage()` construit deja pour le lien
 * de l'email (`organization.loops.show` + `?message=`) — pas un ecran
 * admin dedie, qui n'existe que pour les echanges (`admin.messages.show`).
 * Cette route PUBLIQUE exige l'appartenance a l'Organization
 * (`organization.member`) : offerte seulement quand `organization_id` de
 * l'admin correspond a celui de la ligne, exactement la discipline deja
 * suivie par `/admin/messages` (`AdminMessageController`, TASK-1693 bug R) —
 * un lien qui 404rait n'est PAS propose.
 *
 * ## Il n'existe aucun ecran admin pour l'entraide
 *
 * Categorie `entraide` : jamais de lien. Pas une omission — aucune route
 * `admin.*` ne couvre `Service`/`ServiceRequest`, et les routes PUBLIQUES
 * (`services.show`, `requests.show`) exigent l'appartenance a
 * l'Organization, que l'admin n'a quasiment jamais.
 *
 * ## Resolu EN MASSE, jamais ligne par ligne
 *
 * Mesure etablie partout ailleurs dans ce module (TASK-1778, TASK-1799) :
 * une boucle `foreach` qui charge un modele par ligne affichee transformerait
 * une page de 30 lignes en 30+ requetes. `resoudrePour()` groupe les
 * identifiants par `object_type` et charge chaque groupe en UNE requete,
 * quel que soit le nombre de lignes de la page.
 *
 * ## `Transaction`, `Service`, `ServiceRequest` : le scope est retire
 *
 * Ces trois modeles portent `BelongsToOrganizationScope` (contrairement a
 * `Loop`, `LoopMessage`, `LoopInvitation`, `LoopJoinRequest`, `BlogPost`,
 * `BlogComment`, `EntraideComment`, `Message`, qui ne le portent pas). Cet
 * ecran est reserve au SuperAdmin ('auth','admin') et ne lie jamais
 * `current_organization` : sans retrait explicite, ces trois lectures
 * rendraient TOUJOURS vide, en silence — le meme piege que documente le
 * docblock de `LoopMentionMailer`.
 *
 * ## Une ligne peut designer un objet qui n'existe plus
 *
 * `email_logs` est une trace HISTORIQUE (TASK-1377) : `notification_id` ne
 * porte aucune cle etrangere, precisement pour que la preuve d'envoi
 * survive a la suppression de son origine. Le Contexte le dit honnetement
 * (« Boucle supprimee ») plutot que de masquer la ligne, et ne porte
 * evidemment aucun lien dans ce cas.
 */
final class EmailLogContextResolver
{
    /**
     * @var array<string, string>
     */
    private const LABELS_SOURCE = [
        'loop-invitation' => 'Invitation à une Boucle',
        'organization-invitation' => 'Invitation à l’Organization',
        'referral-invitation' => 'Invitation de parrainage',
        'blog-contribution-invitation' => 'Invitation à contribuer au blog',
        'admin-test' => 'Test d’envoi (admin)',
        'admin-password-reset' => 'Réinitialisation de mot de passe (admin)',
        'public-password-reset' => 'Réinitialisation de mot de passe',
        'emailer' => 'Envoi manuel (gabarit)',
        'bulk-email' => 'Envoi en masse (admin)',
        'crm' => 'CRM',
    ];

    /**
     * @param  Collection<int, EmailLog>  $logs
     * @param  User  $admin  l'administrateur qui CONSULTE l'ecran — son
     *                       `organization_id` personnel decide si les liens
     *                       vers `admin.loop-messages.show`/`admin.messages.show`
     *                       sont offerts (voir le docblock de la classe).
     * @return array<string, array{label: string, url: string|null, url_message: string|null, expediteur: User|null}> `email_logs.id` => contexte
     */
    public function resoudrePour(Collection $logs, User $admin): array
    {
        $idsNotification = $logs->pluck('notification_id')->filter()->unique()->values();

        $notifications = $idsNotification->isEmpty()
            ? collect()
            : MemberNotification::query()->whereIn('id', $idsNotification)->get()->keyBy('id');

        $boucles = $this->resoudreBoucles($notifications);
        $articles = $this->resoudreArticles($notifications);
        $transactions = $this->resoudreTransactions($notifications);
        $titresEntraide = $this->resoudreTitresEntraide($notifications);
        $slugsOrganization = $this->resoudreSlugsOrganization($notifications);
        $acteurs = $this->resoudreActeurs($notifications);

        $libelles = [];

        foreach ($logs as $log) {
            $contexte = $this->contextePour(
                $log,
                $admin,
                $notifications,
                $boucles,
                $articles,
                $transactions,
                $titresEntraide,
                $slugsOrganization,
            );

            // TASK-1800 correctif 3 — l'EXPEDITEUR (Cyril : « comme pour
            // Destinataire »). C'est l'ACTEUR de la notification — celui qui
            // a ecrit la mention, repondu a l'annonce, commente — jamais le
            // destinataire. `null` pour une ligne hors pipeline (aucun acteur
            // a y lire) ou une notification sans acteur (ex. systeme).
            $notification = $notifications->get($log->notification_id);
            $contexte['expediteur'] = $notification !== null && $notification->actor_id !== null
                ? ($acteurs[$notification->actor_id] ?? null)
                : null;

            $libelles[(string) $log->id] = $contexte;
        }

        return $libelles;
    }

    /**
     * Les ACTEURS des notifications, en UNE requete — jamais une par ligne.
     * Colonnes completes (pas de `select` restreint) : le partial `se-
     * connecter-sous` lit `banned_at`/`is_admin` pour sa propre garde
     * (TASK-1795 correctif 7), et une selection partielle les rendrait `null`
     * — la garde laisserait alors passer un lien qu'elle doit refuser.
     *
     * @param  Collection<string, MemberNotification>  $notifications
     * @return Collection<string, User>
     */
    private function resoudreActeurs(Collection $notifications): Collection
    {
        $idsActeur = $notifications->pluck('actor_id')->filter()->unique()->values();

        if ($idsActeur->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $idsActeur)->get()->keyBy('id');
    }

    /**
     * @param  Collection<string, MemberNotification>  $notifications
     * @param  array<string, array{nom: string, loop_id: string}>  $boucles
     * @param  array<string, array{titre: string, article_id: string}>  $articles
     * @param  array<string, array{sujet: string, message_id: string|null}>  $transactions
     * @param  array<string, string>  $titresEntraide
     * @param  array<string, string>  $slugsOrganization  `organization_id` => slug
     * @return array{label: string, url: string|null, url_message: string|null}
     */
    private function contextePour(
        EmailLog $log,
        User $admin,
        Collection $notifications,
        array $boucles,
        array $articles,
        array $transactions,
        array $titresEntraide,
        array $slugsOrganization,
    ): array {
        $notificationId = $log->notification_id;

        if ($notificationId !== null) {
            $notification = $notifications->get($notificationId);

            if ($notification === null) {
                return ['label' => 'Notification supprimée', 'url' => null, 'url_message' => null];
            }

            $categorie = NotificationCatalogue::definition((string) $notification->notification_key)['category'] ?? null;
            $objectId = $notification->object_id;

            if ($categorie === 'loop') {
                if ($objectId === null || ! isset($boucles[$objectId])) {
                    return ['label' => 'Boucle supprimée', 'url' => null, 'url_message' => null];
                }

                $boucle = $boucles[$objectId];
                // La Boucle elle-meme est TOUJOURS joignable (`admin.loops.
                // show` accepte tout SuperAdmin). `OBJECT_LOOP_MESSAGE`
                // (mention) porte EN PLUS un second lien, directement vers LA
                // SOURCE du message — demande explicite de Cyril (10/11,
                // relecture en situation reelle) : la page PUBLIQUE de la
                // Boucle avec `?message=`, exactement l'URL que
                // `NotificationTargetResolver::loopMessage()` construit deja
                // pour le lien de l'email lui-meme — pas l'ecran admin.
                $urlMessage = ($notification->object_type === NotificationCatalogue::OBJECT_LOOP_MESSAGE
                    && isset($slugsOrganization[$notification->organization_id]))
                    ? $this->urlSiJoignable($admin, $notification, fn () => route('organization.loops.show', [
                        'organization' => $slugsOrganization[$notification->organization_id],
                        'loop' => $boucle['slug'] ?: $boucle['loop_id'],
                        'message' => $objectId,
                    ]))
                    : null;

                return [
                    'label' => 'Boucle : '.$boucle['nom'],
                    'url' => route('admin.loops.show', $boucle['loop_id']),
                    'url_message' => $urlMessage,
                ];
            }

            if ($categorie === 'blog') {
                if ($objectId === null || ! isset($articles[$objectId])) {
                    return ['label' => 'Article supprimé', 'url' => null, 'url_message' => null];
                }

                $article = $articles[$objectId];

                // `admin.blog.edit` n'exige aucune appartenance (pas de garde
                // `organization_id` dans `AdminBlogController::edit()`) :
                // toujours offert, contrairement aux routes de message ci-dessus.
                return [
                    'label' => 'Article : '.$article['titre'],
                    'url' => route('admin.blog.edit', $article['article_id']),
                    'url_message' => null,
                ];
            }

            if ($categorie === 'transaction') {
                if ($objectId === null || ! isset($transactions[$objectId])) {
                    return ['label' => 'Message privé', 'url' => null, 'url_message' => null];
                }

                $transaction = $transactions[$objectId];
                $url = $transaction['message_id'] === null
                    ? null
                    : $this->urlSiJoignable($admin, $notification, fn () => route('admin.messages.show', $transaction['message_id']));

                return ['label' => 'Message privé : '.$transaction['sujet'], 'url' => $url, 'url_message' => null];
            }

            if ($categorie === 'entraide') {
                if ($objectId === null || ! isset($titresEntraide[$objectId])) {
                    return ['label' => 'Annonce d’entraide supprimée', 'url' => null, 'url_message' => null];
                }

                // Aucune route admin ne couvre Service/ServiceRequest : voir
                // le docblock de la classe.
                return ['label' => 'Entraide : '.$titresEntraide[$objectId], 'url' => null, 'url_message' => null];
            }

            return ['label' => 'Autre évènement', 'url' => null, 'url_message' => null];
        }

        $source = $log->data['source'] ?? null;

        if (! is_string($source) || $source === '') {
            return ['label' => '—', 'url' => null, 'url_message' => null];
        }

        return ['label' => self::LABELS_SOURCE[$source] ?? 'Autre : '.$source, 'url' => null, 'url_message' => null];
    }

    /**
     * `admin.messages.show` rend `404` hors de l'Organization PERSONNELLE de
     * l'admin (`AdminMessageController`, TASK-1693 bug R — une garantie
     * ecrite, pas un detail d'implementation), et `organization.loops.show`
     * exige l'appartenance via le middleware `organization.member`. Offrir
     * le lien quand meme produirait un clic mort ; `null` ici est donc la
     * reponse honnete, exactement ce que fait deja `/admin/messages`.
     */
    private function urlSiJoignable(User $admin, MemberNotification $notification, callable $route): ?string
    {
        if ($admin->organization_id === null || $admin->organization_id !== $notification->organization_id) {
            return null;
        }

        return $route();
    }

    /**
     * @param  Collection<string, MemberNotification>  $notifications
     * @return array<string, array{nom: string, loop_id: string, slug: string|null}> `object_id`
     *                                DE LA NOTIFICATION => Boucle atteinte —
     *                                jamais l'identifiant de Boucle lui-meme,
     *                                qui n'est PAS toujours `object_id`.
     */
    private function resoudreBoucles(Collection $notifications): array
    {
        // `object_id` ne designe directement la Boucle que pour `OBJECT_LOOP`
        // (`loop.member_added`, `loop.help_request.created`). Pour les trois
        // autres cles, `object_id` designe un MESSAGE, une INVITATION ou une
        // DEMANDE — il faut d'abord retrouver LEUR `loop_id`, en UNE requete
        // par modele intermediaire, jamais une par ligne affichee.
        $loopIdParObjectId = [];

        foreach ($notifications as $notification) {
            if ((NotificationCatalogue::definition((string) $notification->notification_key)['category'] ?? null) !== 'loop') {
                continue;
            }

            if ($notification->object_type === NotificationCatalogue::OBJECT_LOOP && $notification->object_id !== null) {
                $loopIdParObjectId[$notification->object_id] = $notification->object_id;
            }
        }

        $loopIdParObjectId = array_merge(
            $loopIdParObjectId,
            $this->idIntermediaireVia($notifications, NotificationCatalogue::OBJECT_LOOP_MESSAGE, LoopMessage::class),
            $this->idIntermediaireVia($notifications, NotificationCatalogue::OBJECT_LOOP_INVITATION, LoopInvitation::class),
            $this->idIntermediaireVia($notifications, NotificationCatalogue::OBJECT_LOOP_JOIN_REQUEST, LoopJoinRequest::class),
        );

        if ($loopIdParObjectId === []) {
            return [];
        }

        // `slug` est repris pour le lien PUBLIC de la mention (voir
        // `contextePour()`) : meme forme que `NotificationTargetResolver::
        // loopMessage()`, qui prefere deja le slug a l'UUID dans cette URL.
        $boucleParLoopId = Loop::withoutGlobalScopes()
            ->whereIn('id', array_values(array_unique($loopIdParObjectId)))
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');

        $resultat = [];

        foreach ($loopIdParObjectId as $objectId => $loopId) {
            $boucle = $boucleParLoopId->get($loopId);

            if ($boucle !== null) {
                $resultat[$objectId] = ['nom' => $boucle->name, 'loop_id' => $loopId, 'slug' => $boucle->slug];
            }
        }

        return $resultat;
    }

    /**
     * @param  Collection<string, MemberNotification>  $notifications
     * @param  class-string<LoopMessage|LoopInvitation|LoopJoinRequest>  $modele
     * @return array<string, string> `object_id` (du modele intermediaire) => `loop_id`
     */
    private function idIntermediaireVia(Collection $notifications, string $objectType, string $modele): array
    {
        $idsObjet = $notifications
            ->filter(fn (MemberNotification $n) => $n->object_type === $objectType && $n->object_id !== null)
            ->pluck('object_id')
            ->unique()
            ->values();

        if ($idsObjet->isEmpty()) {
            return [];
        }

        return $modele::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $idsObjet)
            ->pluck('loop_id', 'id')
            ->filter()
            ->all();
    }

    /**
     * @param  Collection<string, MemberNotification>  $notifications
     * @return array<string, array{titre: string, article_id: string}> `object_id`
     *                                DE LA NOTIFICATION => article atteint —
     *                                meme principe que `resoudreBoucles()` :
     *                                `object_id` ne designe directement
     *                                l'article que pour `OBJECT_BLOG_POST`,
     *                                pas pour `OBJECT_BLOG_COMMENT` (qui
     *                                designe le COMMENTAIRE).
     */
    private function resoudreArticles(Collection $notifications): array
    {
        $articleIdParObjectId = [];

        foreach ($notifications as $notification) {
            if ((NotificationCatalogue::definition((string) $notification->notification_key)['category'] ?? null) !== 'blog') {
                continue;
            }

            if ($notification->object_type === NotificationCatalogue::OBJECT_BLOG_POST && $notification->object_id !== null) {
                $articleIdParObjectId[$notification->object_id] = $notification->object_id;
            }
        }

        $idsCommentaire = $notifications
            ->filter(fn (MemberNotification $n) => $n->object_type === NotificationCatalogue::OBJECT_BLOG_COMMENT && $n->object_id !== null)
            ->pluck('object_id')
            ->unique()
            ->values();

        if ($idsCommentaire->isNotEmpty()) {
            $articleIdParCommentaireId = BlogComment::withoutGlobalScopes()
                ->whereIn('id', $idsCommentaire)
                ->pluck('blog_post_id', 'id')
                ->filter()
                ->all();

            $articleIdParObjectId = array_merge($articleIdParObjectId, $articleIdParCommentaireId);
        }

        if ($articleIdParObjectId === []) {
            return [];
        }

        $titresParArticleId = BlogPost::withoutGlobalScopes()
            ->whereIn('id', array_values(array_unique($articleIdParObjectId)))
            ->pluck('title', 'id');

        $resultat = [];

        foreach ($articleIdParObjectId as $objectId => $articleId) {
            if ($titresParArticleId->has($articleId)) {
                $resultat[$objectId] = ['titre' => $titresParArticleId->get($articleId), 'article_id' => $articleId];
            }
        }

        return $resultat;
    }

    /**
     * @param  Collection<string, MemberNotification>  $notifications
     * @return array<string, array{sujet: string, message_id: string|null}> identifiant de Transaction => echange
     */
    private function resoudreTransactions(Collection $notifications): array
    {
        $idsTransaction = $notifications
            ->filter(fn (MemberNotification $n) => $n->object_type === NotificationCatalogue::OBJECT_TRANSACTION && $n->object_id !== null)
            ->pluck('object_id')
            ->unique()
            ->values();

        if ($idsTransaction->isEmpty()) {
            return [];
        }

        // `getSubjectAttribute()` lit `$this->service`/`$this->serviceRequest`
        // (TASK-1751) : sans les PRECHARGER ici, scope deja retire, l'acces
        // paresseux rouvrirait une requete NEUVE sur `Service`/`ServiceRequest`
        // — qui portent eux aussi `BelongsToOrganizationScope` — et la
        // retrouverait appliquee, rendant `null` en silence (meme piege que
        // pour la Transaction elle-meme).
        $sujets = Transaction::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereIn('id', $idsTransaction)
            ->with([
                'service' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class),
                'serviceRequest' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class),
            ])
            ->get()
            ->mapWithKeys(fn (Transaction $t) => [(string) $t->id => $t->subject]);

        // `admin.messages.show` prend un MESSAGE, pas une Transaction : on
        // lie au PREMIER message de l'echange — celui qui a pose la
        // proposition, et donc toujours present quand la cle a ete emise.
        $premierMessageParTransaction = Message::query()
            ->whereIn('transaction_id', $idsTransaction)
            ->orderBy('created_at')
            ->get(['id', 'transaction_id'])
            ->groupBy('transaction_id')
            ->map(fn (Collection $messages) => (string) $messages->first()->id);

        $resultat = [];

        foreach ($sujets as $transactionId => $sujet) {
            $resultat[$transactionId] = [
                'sujet' => $sujet,
                'message_id' => $premierMessageParTransaction->get($transactionId),
            ];
        }

        return $resultat;
    }

    /**
     * @param  Collection<string, MemberNotification>  $notifications
     * @return array<string, string> identifiant de commentaire d'entraide => titre de l'annonce
     */
    private function resoudreTitresEntraide(Collection $notifications): array
    {
        $idsCommentaire = $notifications
            ->filter(fn (MemberNotification $n) => $n->object_type === NotificationCatalogue::OBJECT_ENTRAIDE_COMMENT && $n->object_id !== null)
            ->pluck('object_id')
            ->unique()
            ->values();

        if ($idsCommentaire->isEmpty()) {
            return [];
        }

        // `Service`/`ServiceRequest` (la cible du `MorphTo`) portent eux aussi
        // `BelongsToOrganizationScope` : meme retrait que pour `Transaction`
        // ci-dessus, pour la meme raison.
        return EntraideComment::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $idsCommentaire)
            ->with(['commentable' => function ($morph) {
                $morph->withoutGlobalScope(BelongsToOrganizationScope::class);
            }])
            ->get()
            ->filter(fn (EntraideComment $c) => $c->commentable instanceof Service || $c->commentable instanceof ServiceRequest)
            ->mapWithKeys(fn (EntraideComment $c) => [(string) $c->id => $c->commentable->title])
            ->all();
    }

    /**
     * Le SLUG de l'Organization de chaque notification `loop` — necessaire
     * pour construire l'URL publique `organization.loops.show` (route-key
     * `slug`, voir `Organization::getRouteKeyName()`), jamais pour une
     * decision de securite : `urlSiJoignable()` compare toujours
     * l'`organization_id`, pas le slug.
     *
     * @param  Collection<string, MemberNotification>  $notifications
     * @return array<string, string> `organization_id` => slug
     */
    private function resoudreSlugsOrganization(Collection $notifications): array
    {
        $idsOrganization = $notifications
            ->filter(fn (MemberNotification $n) => (NotificationCatalogue::definition((string) $n->notification_key)['category'] ?? null) === 'loop')
            ->pluck('organization_id')
            ->filter()
            ->unique()
            ->values();

        if ($idsOrganization->isEmpty()) {
            return [];
        }

        return Organization::query()
            ->whereIn('id', $idsOrganization)
            ->pluck('slug', 'id')
            ->all();
    }
}
