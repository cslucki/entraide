<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedHistoryReplay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1752 — « tous les contenus des flux sur une organisation donnee ».
 *
 * Ecrit sur le gabarit d'`AdminRoadmapController` (T1718 volet 2), qui fait
 * deja exactement cela pour un autre objet : liste transversale filtree,
 * modification, suppression, 50 par page, `withQueryString()`.
 *
 * ## Aucun modele nouveau, aucune table
 *
 * Tout est lu tel quel. La condition d'arret de la TASK l'exige d'ailleurs :
 * la demande dit « affichera », pas « enregistrera ».
 *
 * ## `/admin` est une surface PLATEFORME
 *
 * `AdminMiddleware` exige `is_admin` — le SuperAdmin plateforme, **jamais** un
 * administrateur d'Organization. C'est ce qui autorise a lire plusieurs
 * tenants ici, et seulement ici. `FeedPost` n'a pas de scope global, il n'y a
 * donc rien a retirer ; `FeedPostComment`, si, et c'est fait nommement.
 *
 * ## Ce que « modifier » veut dire, et ce qu'il ne veut pas dire
 *
 * Un EVENEMENT AUTOMATIQUE n'a pas de `content` a changer : son texte est
 * calcule. Offrir un champ de texte dessus afficherait un formulaire qui ne
 * change rien a l'ecran. Pour lui, « modifier » ne porte que sur son AUDIENCE
 * et son STATUT. La vue distingue les deux cas, et ce controleur refuse le
 * contenu sur un evenement plutot que de l'accepter en silence.
 *
 * ## Masquer plutot que detruire
 *
 * `feed_post_comments.is_approved` existe, est deja filtre en lecture, et
 * n'est ecrit QUE `true` par le produit : rien ne pouvait desapprouver un
 * commentaire. C'est le geste de moderation que la demande reclame, disponible
 * pour le prix d'un `update`. Il ne manquait qu'un bouton.
 */
class AdminFeedController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = $request->string('organization_id')->toString();
        $type = $request->string('type')->toString();

        $query = FeedPost::query()
            ->with(['user', 'organization'])
            ->withCount(['comments', 'reactions']);

        if ($organizationId !== '') {
            $query->where('organization_id', $organizationId);
        }

        // `evenements` / `annonces` plutot que la liste des cinq types : c'est
        // la distinction que la page sert a faire, et elle tient en un clic.
        if ($type === 'evenements') {
            $query->whereIn('type', FeedEventCatalogue::clefs());
        } elseif ($type === 'annonces') {
            $query->whereNotIn('type', FeedEventCatalogue::clefs());
        }

        $posts = $query->orderByDesc('published_at')->orderByDesc('created_at')
            ->paginate(50)->withQueryString();

        // TASK-1752 — l'outil de reprise, par Organization.
        //
        // Le compte a blanc n'est calcule QUE si une Organization est
        // designee : il parcourt cinq tables, et le faire pour les trois
        // tenants a chaque affichage de la page serait du travail pour rien.
        //
        // C'est aussi la regle produit : jamais « toutes » par defaut. Trois
        // tenants, trois decisions.
        $organizationChoisie = $organizationId !== ''
            ? Organization::whereKey($organizationId)->first()
            : null;

        $reprise = null;

        if ($organizationChoisie !== null) {
            $service = app(FeedHistoryReplay::class);

            $reprise = [
                'organization' => $organizationChoisie,
                'comptes' => $service->compter($organizationChoisie),
                'parType' => $service->poseesParType($organizationChoisie),
            ];
        }

        return view('admin.feed.index', [
            'posts' => $posts,
            'organizations' => Organization::orderBy('name')->get(['id', 'name', 'slug']),
            'organizationId' => $organizationId,
            'type' => $type,
            'typesEvenement' => FeedEventCatalogue::clefs(),
            'reprise' => $reprise,
        ]);
    }

    /**
     * Rejouer — ou retirer — l'historique d'UNE Organization.
     *
     * L'Organization est OBLIGATOIRE et validee contre la base : « toutes »
     * n'existe pas ici. La logique vit dans `FeedHistoryReplay`, partagee
     * avec la commande Artisan — deux implementations auraient diverge a la
     * premiere correction de source.
     */
    public function reprendreHistorique(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => 'required|uuid|exists:organizations,id',
            'action' => 'required|in:reprendre,retirer',
            // Les types a retirer. `FeedHistoryReplay::retirer()` refait de
            // toute facon l'intersection avec le registre : une valeur
            // bricolee dans la requete ne peut atteindre aucune annonce
            // humaine. Cette regle valide l'ENTREE, le service garde la
            // FRONTIERE — les deux, pas l'une ou l'autre.
            'types' => 'nullable|array',
            'types.*' => 'string|in:'.implode(',', FeedEventCatalogue::clefs()),
        ]);

        $organization = Organization::whereKey($data['organization_id'])->firstOrFail();
        $service = app(FeedHistoryReplay::class);

        if ($data['action'] === 'retirer') {
            $types = $data['types'] ?? null;

            if ($types === []) {
                return back()->with('error', __('admin.feed_replay_pick_a_type'));
            }

            $n = $service->retirer($organization, $types);

            return back()->with('success', __('admin.feed_replay_removed', ['count' => $n, 'name' => $organization->name]));
        }

        $n = array_sum($service->reprendre($organization));

        return back()->with('success', __('admin.feed_replay_done', ['count' => $n, 'name' => $organization->name]));
    }

    /**
     * TASK-1758 — l'interrupteur des evenements automatiques, par Organization.
     *
     * ## Le trou qu'il ferme
     *
     * `organizations.feed_auto_events_enabled` existe depuis T1752, la
     * migration le pose a `true`, et `FeedEventEmitter::activeSur()` le
     * consulte a chaque emission — mais **aucun ecran ne l'exposait**.
     * Constate en preparant la release 1.753 : la page de reprise savait
     * RETIRER les evenements deja ecrits, rien n'arretait les suivants. Le seul
     * levier etait un changement de code ou une ecriture directe en base.
     *
     * Ce n'est pas un confort : les evenements automatiques ecrivent dans le
     * Flux de toute une communaute. Une fonctionnalite qui ecrit pour tout le
     * monde doit avoir un bouton d'arret.
     *
     * ## Ce que l'interrupteur ne fait PAS
     *
     * Il n'efface rien. Couper n'empeche que les emissions FUTURES — c'est
     * `retirer` qui nettoie l'existant, et les deux gestes sont distincts a
     * dessein : on peut vouloir arreter sans effacer l'historique deja
     * constitue, et l'inverse.
     */
    public function basculerEvenementsAutomatiques(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => 'required|uuid|exists:organizations,id',
            'actif' => 'required|boolean',
        ]);

        $organization = Organization::whereKey($data['organization_id'])->firstOrFail();

        $organization->update(['feed_auto_events_enabled' => $data['actif']]);

        return back()->with('success', __(
            $data['actif'] ? 'admin.feed_auto_events_on' : 'admin.feed_auto_events_off',
            ['name' => $organization->name]
        ));
    }

    public function update(Request $request, string $post): RedirectResponse
    {
        $ligne = $this->resoudre($post);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string|max:20000',
            'status' => 'required|in:'.implode(',', [
                FeedPost::STATUS_PUBLISHED, FeedPost::STATUS_DRAFT, FeedPost::STATUS_SCHEDULED,
            ]),
            'visibility' => 'required|in:'.implode(',', [
                FeedEventCatalogue::VISIBILITE_ORGANIZATION, FeedEventCatalogue::VISIBILITE_BOUCLE,
            ]),
        ]);

        // Le texte d'un evenement est CALCULE : l'accepter ici ecrirait une
        // colonne que l'affichage ignore, et l'ecran mentirait sur ce qu'il
        // vient de faire.
        if (FeedEventCatalogue::connait((string) $ligne->type)) {
            unset($data['title'], $data['content']);
        }

        $ligne->update($data);

        return back()->with('success', __('admin.feed_updated'));
    }

    public function destroy(string $post): RedirectResponse
    {
        $ligne = $this->resoudre($post);

        // `forceDelete()`, decision Cyril (§8.3), et le libelle de l'ecran dit
        // « definitivement ». `FeedPost` porte `SoftDeletes`, donc `delete()`
        // archiverait — et contrairement a la Roadmap, le Flux n'a aucun
        // archivage ailleurs : `MyFeedPosts::delete()` fait un soft-delete que
        // rien ne restaure. Les deux gestes ne sont donc pas en concurrence.
        //
        // La cascade emporte les commentaires (FK `cascadeOnDelete`). Les
        // reactions, elles, sont un morph SANS FK : on les retire a la main,
        // sinon elles deviendraient orphelines.
        $ligne->reactions()->delete();
        $ligne->forceDelete();

        return back()->with('success', __('admin.feed_deleted'));
    }

    /**
     * Masquer un commentaire sans le detruire.
     *
     * La lecture filtre deja `is_approved` : basculer la colonne le retire de
     * la vue de tous, et le geste est reversible. C'est ce qu'une moderation
     * doit offrir avant la destruction.
     */
    public function basculerCommentaire(string $comment): RedirectResponse
    {
        $ligne = FeedPostComment::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($comment)
            ->firstOrFail();

        $ligne->update(['is_approved' => ! $ligne->is_approved]);

        return back()->with('success', __('admin.feed_comment_toggled'));
    }

    /**
     * TASK-1788 correctif 10 — LA page des commentaires du Flux.
     *
     * « Voir uniquement les commentaires classes par date, et par utilisateur.
     * Ceci pour pouvoir les voir / modifier / supprimer. »
     *
     * ## Pourquoi une page a part, et pas une colonne de plus
     *
     * La liste des annonces porte deja six colonnes et deux formulaires par
     * ligne. Y glisser le fil complet de chaque annonce aurait rendu la page
     * illisible — et surtout, moderer un commentaire ne commence pas toujours
     * par l'annonce qui le porte : on part d'une personne, ou d'une date.
     *
     * ## ⚠️ LE scope de tenant n'existe PAS sur ce modele — mesure
     *
     * Le docblock de ce controleur affirmait depuis T1752 que
     * `FeedPostComment` portait `BelongsToOrganizationScope`. **C'est faux.**
     * Mesure : `getGlobalScopes()` ne rend que `SoftDeletingScope`. Le trait
     * `HasOrganizationId` ne fait que REMPLIR la colonne a la creation, il
     * n'ajoute aucun scope.
     *
     * Consequence, et elle compte : cette page lit les trois tenants parce
     * qu'aucun scope ne l'en empeche, **pas** parce qu'on en retire un. La
     * seule garde est `AdminMiddleware` (`is_admin`, le SuperAdmin plateforme,
     * jamais un administrateur d'Organization). Un test la tient.
     *
     * Le `withoutGlobalScope(BelongsToOrganizationScope::class)` est CONSERVE —
     * il est sans effet aujourd'hui et le resterait juste le jour ou le scope
     * serait ajoute. Mais il est posé **par son nom** : `withoutGlobalScopes()`
     * emporterait `SoftDeletingScope` et ferait reapparaitre les lignes
     * detruites par le bouton rouge de cette meme page.
     *
     * ## Les filtres sont ceux de la demande
     *
     * Par date (l'ordre, dans les deux sens), par utilisateur, et par
     * Organization — ce dernier parce que la page est transversale et que
     * « tous les tenants melanges » n'est pas une vue de travail.
     */
    public function commentaires(Request $request): View
    {
        $organizationId = $request->string('organization_id')->toString();
        $userId = $request->string('user_id')->toString();
        $feedPostId = $request->string('feed_post_id')->toString();
        $ordre = $request->string('ordre')->toString() === 'asc' ? 'asc' : 'desc';

        $query = FeedPostComment::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->with(['user', 'organization', 'feedPost']);

        if ($organizationId !== '') {
            $query->where('organization_id', $organizationId);
        }

        if ($userId !== '') {
            $query->where('user_id', $userId);
        }

        // TASK-1788 correctif 10 (recette Cyril) — le filtre par ANNONCE.
        //
        // Defaut signale : cliquer « 3 commentaires » sur une ligne ouvrait la
        // page filtree sur la seule ORGANIZATION, donc tous les commentaires du
        // tenant. Un compteur qui annonce trois doit mener a ces trois-la.
        if ($feedPostId !== '') {
            $query->where('feed_post_id', $feedPostId);
        }

        $commentaires = $query->orderBy('created_at', $ordre)
            ->paginate(50)
            ->withQueryString();

        // Les auteurs proposes au filtre : ceux qui ont REELLEMENT commente,
        // et dans l'Organization choisie s'il y en a une. Lister tous les
        // comptes de la plateforme donnerait un menu de plusieurs centaines
        // d'entrees dont presque aucune ne rend de ligne.
        $auteursQuery = FeedPostComment::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereNotNull('user_id');

        if ($organizationId !== '') {
            $auteursQuery->where('organization_id', $organizationId);
        }

        $auteurs = User::whereIn('id', $auteursQuery->select('user_id'))
            ->orderBy('first_name')->orderBy('name')
            ->get(['id', 'first_name', 'name']);

        // L'annonce choisie, pour que la page DISE sur quoi elle est bornee —
        // un filtre invisible est un filtre qu'on accuse de perdre des lignes.
        $annonceChoisie = $feedPostId !== ''
            ? FeedPost::withTrashed()->whereKey($feedPostId)->first()
            : null;

        return view('admin.feed.comments', [
            'commentaires' => $commentaires,
            'organizations' => Organization::orderBy('name')->get(['id', 'name', 'slug']),
            'auteurs' => $auteurs,
            'organizationId' => $organizationId,
            'userId' => $userId,
            'feedPostId' => $feedPostId,
            'annonceChoisie' => $annonceChoisie,
            'ordre' => $ordre,
        ]);
    }

    /**
     * Modifier le TEXTE d'un commentaire.
     *
     * La meme borne que le produit : 2000 caracteres, et jamais vide. Un
     * commentaire vide n'est pas une correction, c'est une suppression a
     * moitie faite — et la suppression a son propre geste, juste en dessous.
     */
    public function modifierCommentaire(Request $request, string $comment): RedirectResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $ligne = FeedPostComment::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($comment)
            ->firstOrFail();

        $ligne->update(['content' => $data['content']]);

        return back()->with('success', __('admin.feed_comment_updated'));
    }

    /**
     * Supprimer un commentaire, pour de bon.
     *
     * ⚠️ Distinct de la BASCULE juste au-dessus, et c'est l'essentiel : masquer
     * est reversible, ceci ne l'est pas. L'ecran le dit avant de le faire.
     *
     * ⚠️ `forceDelete()` et NON `delete()`. J'avais d'abord ecrit `delete()` en
     * supposant que la table n'avait pas de suppression douce : **elle en a
     * une**. `FeedPostComment` emploie `SoftDeletes` et la colonne
     * `deleted_at` existe. Un `delete()` aurait donc seulement masque la ligne
     * — un second « masquer », sous un bouton rouge qui promet le contraire.
     * Le test l'a montre.
     *
     * C'est aussi ce que fait la suppression d'une ANNONCE dans ce meme
     * controleur : deux boutons de meme couleur doivent detruire pareil.
     *
     * Et c'est la raison pour laquelle le scope est retire PAR SON NOM plus
     * haut : `withoutGlobalScopes()` emporterait `SoftDeletingScope` et ferait
     * reapparaitre dans la liste ce que ce geste vient de detruire.
     */
    public function supprimerCommentaire(string $comment): RedirectResponse
    {
        $ligne = FeedPostComment::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($comment)
            ->firstOrFail();

        $ligne->forceDelete();

        return back()->with('success', __('admin.feed_comment_deleted'));
    }

    private function resoudre(string $id): FeedPost
    {
        return FeedPost::withTrashed()->whereKey($id)->firstOrFail();
    }
}
