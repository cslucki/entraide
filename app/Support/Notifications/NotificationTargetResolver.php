<?php

namespace App\Support\Notifications;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

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
            NotificationCatalogue::OBJECT_BLOG_COMMENT => $this->blogComment($notification),
            NotificationCatalogue::OBJECT_BLOG_POST => $this->blogPost($notification),
            default => null,
        };
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

        $invitation = LoopInvitation::query()
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
    private function loopJoinRequest(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        $demande = LoopJoinRequest::query()
            ->whereKey($notification->object_id)
            ->where('organization_id', $notification->organization_id)
            ->first();

        if ($demande === null) {
            return null;
        }

        $boucle = Loop::query()
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

        return route('organization.loops.show', [
            'organization' => $organisation,
            'loop' => $boucle,
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

        $url = $organization !== null && \Illuminate\Support\Facades\Route::has('organization.blog.show')
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

        if ($organization === null || ! \Illuminate\Support\Facades\Route::has('organization.blog.edit')) {
            return null;
        }

        return route('organization.blog.edit', [
            'organization' => $organization->slug,
            'post' => $post->slug,
        ]);
    }
}
