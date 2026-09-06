<?php

namespace App\Services\Loops;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\User;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;

/**
 * The one place a Loop's root Dossier and root document are created or moved.
 *
 * Everything here is transactional and idempotent. Nothing writes during a
 * read. Controllers, the creation flow and the catch-up command all come
 * through this service, so the invariants below cannot be bypassed by a
 * forgotten caller:
 *
 *   · a Loop has exactly one root Dossier, held by the Loop and by nobody else ;
 *   · that Dossier carries the Loop's own organization_id, never another ;
 *   · the root document belongs to that same Dossier ;
 *   · a root document is published, audience `loop`, absent from the Blog ;
 *   · replacing a root document never leaves the Loop without one, and never
 *     deletes the previous one.
 *
 * The XOR between owner_id and loop_id is enforced by the database on
 * PostgreSQL. SQLite cannot add that constraint to an existing table, so on
 * that driver this service is the only thing holding it — which is why nothing
 * else is allowed to write these columns.
 */
class LoopRootDocumentService
{
    use Localizable;

    public function __construct(
        private LoopTypeRegistry $types,
        private DossierArticleIndexingDispatcher $indexing,
    ) {}

    /**
     * The root Dossier of a Loop, created if it does not exist yet.
     *
     * Idempotent: called twice, the second call returns the first Dossier.
     * The row is locked so two concurrent creations cannot both insert.
     */
    public function ensureRootDossier(Loop $loop): Dossier
    {
        return DB::transaction(function () use ($loop) {
            $existing = Dossier::where('loop_id', $loop->id)->lockForUpdate()->first();

            if ($existing) {
                return $existing;
            }

            return Dossier::create([
                'organization_id' => $loop->organization_id,
                'owner_id' => null,
                'loop_id' => $loop->id,
                'name' => $loop->name,
                // Held by the Loop, so its confidentiality is the Loop's :
                // effectiveVisibility() court-circuite cette colonne des que
                // loop_id est renseigne. La valeur stockee est donc inerte —
                // mais on n'ecrit plus `shared`, valeur historique que la
                // migration a justement normalisee vers `private` sur le stock.
                'visibility' => Dossier::VISIBILITY_PRIVATE,
            ]);
        });
    }

    /**
     * The Loop's root document, created from the type's template if missing.
     *
     * Idempotent, and safe to call on a Loop that already has one.
     */
    public function ensureRootDocument(Loop $loop, ?User $author = null): BlogPost
    {
        // Langue (TASK-1390) : le document racine porte un titre, un slug et
        // des en-tetes de sections PERSISTES depuis `__()`. Ils appartiennent a
        // l'Organization, pas au lecteur qui declenche la creation — sans quoi
        // un membre francais creant une Boucle dans une Organization anglaise
        // y ecrit un Manifeste francais, definitivement.
        //
        // Pose ICI plutot que chez les trois appelants (`LoopService` x2,
        // `LoopManifestoCard`) : c'est le goulot ou toute la fabrication de
        // texte se produit, et un quatrieme appelant ecrit demain en herite
        // sans que personne y pense.
        //
        // `withLocale()` rend la locale d'origine dans un `finally` : la
        // requete en cours continue de repondre dans la langue de son lecteur.
        return $this->withLocale($loop->organization?->locale, fn () => DB::transaction(function () use ($loop, $author) {
            $dossier = $this->ensureRootDossier($loop);
            $dossier->refresh();

            if ($dossier->root_blog_post_id && ($post = BlogPost::find($dossier->root_blog_post_id))) {
                // Rattrapage doux des documents racines d'avant TASK-1121 :
                // designate() ne posait pas le lien Article <-> Boucle, et
                // l'editeur montrait une section « Boucle » vide sur un
                // Manifeste ne pour elle. Idempotent, repare au passage —
                // aucun backfill massif.
                $post->loops()->syncWithoutDetaching([$loop->id]);

                return $post;
            }

            // A Loop may still carry the legacy designation without the Dossier
            // knowing about it. Adopt it rather than creating a second document.
            if ($loop->manifesto_blog_post_id && ($legacy = BlogPost::find($loop->manifesto_blog_post_id))) {
                return $this->designate($loop, $legacy);
            }

            $author ??= $loop->creator ?? $loop->owners()->first()?->user;

            $post = BlogPost::create([
                'user_id' => $author?->id,
                'organization_id' => $loop->organization_id,
                'title' => $this->types->rootDocumentLabel($loop->type).' — '.$loop->name,
                'slug' => $this->uniqueSlug($loop),
                'summary' => Str::limit((string) $loop->description, 200) ?: null,
                'content' => $this->initialContent($loop),
                // Live for its Loop from the start: no second "Publish" step.
                'status' => 'published',
                'published_at' => now(),
                'audience' => BlogPost::AUDIENCE_LOOP,
                'listed_in_blog' => false,
            ]);

            return $this->designate($loop, $post);
        }));
    }

    /**
     * Make an article the Loop's root document.
     *
     * Moves it into the root Dossier, points the Dossier at it, and aligns the
     * legacy field. The previous root document is kept: it stays in the Dossier
     * as an ordinary article, findable and intact.
     */
    public function designate(Loop $loop, BlogPost $post): BlogPost
    {
        if ($post->organization_id !== $loop->organization_id) {
            throw new \RuntimeException('An article of another Organization cannot become a root document.');
        }

        return DB::transaction(function () use ($loop, $post) {
            $dossier = $this->ensureRootDossier($loop);

            // One Dossier per article: move it rather than duplicate it.
            DossierBlogPost::updateOrCreate(
                ['blog_post_id' => $post->id],
                [
                    'organization_id' => $loop->organization_id,
                    'dossier_id' => $dossier->id,
                    'position' => 0,
                ],
            );

            $post->forceFill([
                'audience' => BlogPost::AUDIENCE_LOOP,
                'listed_in_blog' => false,
            ])->save();

            // Le document racine d'une Boucle est lie a sa Boucle — la meme
            // ligne `blog_post_loop` que le panneau Boucle de l'editeur. Sans
            // elle, un Manifeste ne pour une Boucle affichait une section
            // « Boucle » vide. Idempotent : re-designer ne duplique rien.
            $post->loops()->syncWithoutDetaching([$loop->id]);

            $dossier->forceFill(['root_blog_post_id' => $post->id])->save();

            // Kept in step while the legacy column survives. Its removal is a
            // dedicated task; until then the two must never diverge.
            $loop->forceFill(['manifesto_blog_post_id' => $post->id])->save();

            // TASK-1307 : `designate()` est le SEUL endroit qui cree/deplace
            // le lien Article <-> Dossier pour un document racine — contrairement
            // a `DossierArticleController::store()`/`createAndAttach()` (qui
            // dispatchent deja explicitement), rien n'appelait
            // `DossierArticleIndexingDispatcher` ici. `BlogPostObserver::updated()`
            // ne reagit qu'a un changement de `content/status/published_at`
            // ULTERIEUR : un document racine fraichement cree (ou adopte depuis
            // le champ legacy) restait donc indexe jamais, tant que personne ne
            // l'editait. Meme file d'attente et memes garanties d'idempotence
            // que le flux d'attache manuel (`IndexDossierArticleChunks`,
            // `content_hash` par chunk, `WithoutOverlapping`).
            $this->indexing->dispatchForBlogPost($post);

            return $post;
        });
    }

    /**
     * Replace the root document, atomically.
     *
     * The former one is never deleted and never leaves the Dossier — the Loop
     * is never, at any instant, without a root document.
     */
    public function replace(Loop $loop, BlogPost $post): BlogPost
    {
        return $this->designate($loop, $post);
    }

    /** Articles that may become this Loop's root document. */
    public function eligibleArticles(Loop $loop, ?string $search = null, int $limit = 20)
    {
        return BlogPost::query()
            // Tenant first, always: never an article of another Organization.
            ->where('organization_id', $loop->organization_id)
            ->when($search, fn ($q) => $q->where('title', 'like', '%'.$search.'%'))
            // Already the root document of another Loop: taking it would empty
            // that Loop's card.
            ->whereNotIn('id', Dossier::whereNotNull('root_blog_post_id')
                ->where('loop_id', '!=', $loop->id)
                ->whereNotNull('loop_id')
                ->pluck('root_blog_post_id'))
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function uniqueSlug(Loop $loop): string
    {
        $base = Str::slug($loop->name.'-'.$this->types->rootDocumentLabel($loop->type)) ?: 'document';
        $slug = $base;

        while (BlogPost::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    /**
     * The starting text, from the type's template.
     *
     * The Loop's description is copied **once**, into the introduction. It is
     * never synchronised afterwards: the description presents the Loop in the
     * catalogue, the document is its own thing.
     */
    private function initialContent(Loop $loop): string
    {
        $intro = filled($loop->description)
            ? '<p>'.e($loop->description).'</p>'
            : '<p>'.e(__('loops.root_document_intro_placeholder', ['name' => $loop->name])).'</p>';

        $sections = collect($this->types->rootDocumentSections($loop->type))
            ->map(fn ($key) => '<h2>'.e(__($key)).'</h2><p></p>')
            ->implode('');

        return $intro.$sections;
    }
}
