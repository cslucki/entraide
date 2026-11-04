<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Support\Feed\FeedEventCatalogue;
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

        return view('admin.feed.index', [
            'posts' => $posts,
            'organizations' => Organization::orderBy('name')->get(['id', 'name', 'slug']),
            'organizationId' => $organizationId,
            'type' => $type,
            'typesEvenement' => FeedEventCatalogue::clefs(),
        ]);
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

    private function resoudre(string $id): FeedPost
    {
        return FeedPost::withTrashed()->whereKey($id)->firstOrFail();
    }
}
