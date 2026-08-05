<?php

namespace App\Services\Loops;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\User;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    public function __construct(private LoopTypeRegistry $types) {}

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
                // Held by the Loop, so its confidentiality is the Loop's.
                // syncVisibility() must never run on this Dossier.
                'visibility' => Dossier::VISIBILITY_SHARED,
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
        return DB::transaction(function () use ($loop, $author) {
            $dossier = $this->ensureRootDossier($loop);
            $dossier->refresh();

            if ($dossier->root_blog_post_id && ($post = BlogPost::find($dossier->root_blog_post_id))) {
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
        });
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

            $dossier->forceFill(['root_blog_post_id' => $post->id])->save();

            // Kept in step while the legacy column survives. Its removal is a
            // dedicated task; until then the two must never diverge.
            $loop->forceFill(['manifesto_blog_post_id' => $post->id])->save();

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
