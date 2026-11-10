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
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * TASK-1800 — le « Contexte » d'un email dans `/admin/email-logs`.
 *
 * ## Le defaut que cet ecran avait
 *
 * `/admin/email-logs` montre QUI a recu quoi et QUAND, mais jamais DE QUOI
 * ca parlait : une mention dans une Boucle, un message prive d'echange, un
 * commentaire d'entraide et un test d'envoi d'admin se ressemblent tous une
 * fois reduits a une adresse et un sujet. Demande de Cyril (10/11).
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
 *   d'un mailer hors pipeline de notifications (invitation de Boucle ou
 *   d'Organization, parrainage, contribution au blog, test d'admin,
 *   reinitialisation de mot de passe, CRM, envoi manuel). Leur `source` est
 *   deja ecrit par CHAQUE appelant ; on ne fait que le TRADUIRE en texte lu.
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
 * `BlogComment`, `EntraideComment`, qui ne le portent pas). Cet ecran est
 * reserve au SuperAdmin ('auth','admin' — `is_admin` est un attribut de
 * PLATEFORME, pas une appartenance a une Organization) et ne lie jamais
 * `current_organization` : sans retrait explicite, ces trois lectures
 * rendraient TOUJOURS vide, en silence — le meme piege que documente le
 * docblock de `LoopMentionMailer`.
 *
 * ## Une ligne peut designer un objet qui n'existe plus
 *
 * `email_logs` est une trace HISTORIQUE (TASK-1377) : `notification_id` ne
 * porte aucune cle etrangere, precisement pour que la preuve d'envoi
 * survive a la suppression de son origine. Le Contexte le dit honnetement
 * (« Boucle supprimee ») plutot que de masquer la ligne.
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
        'crm' => 'CRM',
    ];

    /**
     * @param  Collection<int, EmailLog>  $logs
     * @return array<string, string> `email_logs.id` => libelle de contexte
     */
    public function resoudrePour(Collection $logs): array
    {
        $idsNotification = $logs->pluck('notification_id')->filter()->unique()->values();

        $notifications = $idsNotification->isEmpty()
            ? collect()
            : MemberNotification::query()->whereIn('id', $idsNotification)->get()->keyBy('id');

        $nomsBoucle = $this->resoudreNomsBoucle($notifications);
        $titresArticle = $this->resoudreTitresArticle($notifications);
        $sujetsTransaction = $this->resoudreSujetsTransaction($notifications);
        $titresEntraide = $this->resoudreTitresEntraide($notifications);

        $libelles = [];

        foreach ($logs as $log) {
            $libelles[(string) $log->id] = $this->libellePour(
                $log,
                $notifications,
                $nomsBoucle,
                $titresArticle,
                $sujetsTransaction,
                $titresEntraide,
            );
        }

        return $libelles;
    }

    /**
     * @param  Collection<string, MemberNotification>  $notifications
     * @param  array<string, string>  $nomsBoucle
     * @param  array<string, string>  $titresArticle
     * @param  array<string, string>  $sujetsTransaction
     * @param  array<string, string>  $titresEntraide
     */
    private function libellePour(
        EmailLog $log,
        Collection $notifications,
        array $nomsBoucle,
        array $titresArticle,
        array $sujetsTransaction,
        array $titresEntraide,
    ): string {
        $notificationId = $log->notification_id;

        if ($notificationId !== null) {
            $notification = $notifications->get($notificationId);

            if ($notification === null) {
                return 'Notification supprimée';
            }

            $categorie = NotificationCatalogue::definition((string) $notification->notification_key)['category'] ?? null;
            $objectId = $notification->object_id;

            return match ($categorie) {
                'loop' => $objectId !== null && isset($nomsBoucle[$objectId])
                    ? 'Boucle : '.$nomsBoucle[$objectId]
                    : 'Boucle supprimée',
                'blog' => $objectId !== null && isset($titresArticle[$objectId])
                    ? 'Article : '.$titresArticle[$objectId]
                    : 'Article supprimé',
                'transaction' => $objectId !== null && isset($sujetsTransaction[$objectId])
                    ? 'Message privé : '.$sujetsTransaction[$objectId]
                    : 'Message privé',
                'entraide' => $objectId !== null && isset($titresEntraide[$objectId])
                    ? 'Entraide : '.$titresEntraide[$objectId]
                    : 'Annonce d’entraide supprimée',
                default => 'Autre évènement',
            };
        }

        $source = $log->data['source'] ?? null;

        if (! is_string($source) || $source === '') {
            return '—';
        }

        return self::LABELS_SOURCE[$source] ?? 'Autre : '.$source;
    }

    /**
     * @param  Collection<string, MemberNotification>  $notifications
     * @return array<string, string> `object_id` DE LA NOTIFICATION => nom de
     *                                la Boucle — jamais l'identifiant de
     *                                Boucle lui-meme, qui n'est PAS toujours
     *                                `object_id` (voir plus bas).
     */
    private function resoudreNomsBoucle(Collection $notifications): array
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
            $this->loopIdParObjectIdVia($notifications, NotificationCatalogue::OBJECT_LOOP_MESSAGE, LoopMessage::class),
            $this->loopIdParObjectIdVia($notifications, NotificationCatalogue::OBJECT_LOOP_INVITATION, LoopInvitation::class),
            $this->loopIdParObjectIdVia($notifications, NotificationCatalogue::OBJECT_LOOP_JOIN_REQUEST, LoopJoinRequest::class),
        );

        if ($loopIdParObjectId === []) {
            return [];
        }

        $nomsParLoopId = Loop::withoutGlobalScopes()
            ->whereIn('id', array_values(array_unique($loopIdParObjectId)))
            ->pluck('name', 'id');

        $resultat = [];

        foreach ($loopIdParObjectId as $objectId => $loopId) {
            if ($nomsParLoopId->has($loopId)) {
                $resultat[$objectId] = $nomsParLoopId->get($loopId);
            }
        }

        return $resultat;
    }

    /**
     * @param  Collection<string, MemberNotification>  $notifications
     * @param  class-string<LoopMessage|LoopInvitation|LoopJoinRequest>  $modele
     * @return array<string, string> `object_id` (du modele intermediaire) => `loop_id`
     */
    private function loopIdParObjectIdVia(Collection $notifications, string $objectType, string $modele): array
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
     * @return array<string, string> `object_id` DE LA NOTIFICATION => titre
     *                                de l'article — meme principe que
     *                                `resoudreNomsBoucle()` : `object_id` ne
     *                                designe directement l'article que pour
     *                                `OBJECT_BLOG_POST`, pas pour
     *                                `OBJECT_BLOG_COMMENT` (qui designe le
     *                                COMMENTAIRE).
     */
    private function resoudreTitresArticle(Collection $notifications): array
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
                $resultat[$objectId] = $titresParArticleId->get($articleId);
            }
        }

        return $resultat;
    }

    /**
     * @param  Collection<string, MemberNotification>  $notifications
     * @return array<string, string> identifiant de Transaction => sujet
     */
    private function resoudreSujetsTransaction(Collection $notifications): array
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
        return Transaction::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereIn('id', $idsTransaction)
            ->with([
                'service' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class),
                'serviceRequest' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class),
            ])
            ->get()
            ->mapWithKeys(fn (Transaction $t) => [(string) $t->id => $t->subject])
            ->all();
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
}
