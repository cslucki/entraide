<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dossier extends Model
{
    use HasFactory;
    use HasOrganizationId;
    use HasUuids;
    use SoftDeletes;

    /** The owner, plus whoever is explicitly listed in dossier_members. */
    public const VISIBILITY_PRIVATE = 'private';

    /** Every active member of the same Organization. Never another one. */
    public const VISIBILITY_ORGANIZATION = 'organization';

    /** Governed by the rules of the Loop it is shared with. */
    public const VISIBILITY_LOOP = 'loop';

    /**
     * Historical value. Never a choice — it was written by syncVisibility()
     * whenever a Dossier had members. Migrated to `private`, kept only so an
     * unmigrated row cannot break a comparison.
     *
     * @deprecated use VISIBILITY_PRIVATE with dossier_members
     */
    public const VISIBILITY_SHARED = 'shared';

    public const VISIBILITIES = [self::VISIBILITY_PRIVATE, self::VISIBILITY_ORGANIZATION, self::VISIBILITY_LOOP];

    /**
     * « Mes documents » : la racine personnelle que le produit possede.
     *
     * Une ligne ordinaire a tous egards — `parent_id IS NULL`, `owner_id` =
     * l'utilisateur, `loop_id IS NULL` — sauf qu'elle porte un role systeme.
     * Ce role est le SEUL moyen de la reconnaitre : jamais le nom, qui est un
     * libelle traduit et donc different d'une langue a l'autre.
     *
     * L'unicite (une par Organization et par utilisateur) est garantie par
     * l'index partiel `dossiers_personal_documents_unique`.
     */
    public const SYSTEM_ROLE_PERSONAL_DOCUMENTS = 'personal_documents';

    /**
     * Combien de niveaux `governingDossier()` et les gardes anti-cycle
     * remontent au plus, avant d'abandonner plutot que de boucler.
     *
     * Une vraie hierarchie de Drive ne descend jamais a cette profondeur ; la
     * borne protege contre un cycle qui aurait echappe a `assertValidParent()`
     * (donnee corrompue, migration future) plutot que de faire tourner le
     * serveur indefiniment.
     */
    public const MAX_DEPTH = 50;

    protected $fillable = [
        'organization_id',
        'owner_id',
        'loop_id',
        'shared_with_loop_id',
        'root_blog_post_id',
        'parent_id',
        'name',
        'system_role',
        'visibility',
    ];

    /**
     * True when this row is the user's « Mes documents » root.
     *
     * Asked of the row itself, never of `governingDossier()`: the question is
     * "is THIS the system root", and a child of it is not.
     */
    public function isPersonalDocumentsRoot(): bool
    {
        return $this->system_role === self::SYSTEM_ROLE_PERSONAL_DOCUMENTS;
    }

    /**
     * The name to show. « Mes documents » is a product word, translated on
     * read — the stored `name` is only a fallback for a row written before the
     * translation existed, and is never used to identify the root.
     */
    public function displayName(): string
    {
        return $this->isPersonalDocumentsRoot() ? __('dossiers.my_documents') : $this->name;
    }

    /**
     * True when this Dossier is held by a Loop rather than by a person.
     *
     * Reads `loop_id` on THIS row only — true for a Loop's root Dossier,
     * always false for a child (TASK-1130 passe 4 : un enfant n'est jamais
     * lui-meme un holder). Use `governingDossier()->isLoopDossier()` to ask
     * "is this Dossier governed by a Loop, root or descendant alike".
     */
    public function isLoopDossier(): bool
    {
        return $this->loop_id !== null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * True when this Dossier holds anything a delete would have to reckon
     * with: an active sub-dossier, a file, or an attached Article.
     *
     * TASK-1130 : la suppression n'est autorisee que sur un Dossier vide —
     * plus de promotion automatique des enfants, plus de detachement
     * silencieux des fichiers ou des Articles. `children()`/`files()`
     * excluent deja les lignes soft-supprimees (SoftDeletes sur les deux
     * modeles), donc un enfant ou un fichier deja retire ne bloque rien.
     */
    public function hasContent(): bool
    {
        return $this->children()->exists()
            || $this->files()->exists()
            || $this->dossierBlogPosts()->exists();
    }

    /**
     * The root that governs this Dossier's permissions and identity.
     *
     * Self, if this row is already a root (has `owner_id` or `loop_id`).
     * Otherwise walks `parent_id` up until it finds one — a child holds
     * neither column itself (TASK-1130 : contrainte `dossiers_holder_xor`),
     * so every permission question ("is this a Loop Dossier", "who owns
     * this") must be asked of the governing root, never of the child row.
     *
     * The name, the files, the Articles of a child stay the child's own —
     * only "who may act here" delegates.
     */
    public function governingDossier(): self
    {
        $current = $this;
        $depth = 0;

        while ($current->parent_id !== null && $depth < self::MAX_DEPTH) {
            $next = $current->relationLoaded('parent') ? $current->parent : $current->parent()->first();

            if ($next === null) {
                break;
            }

            $current = $next;
            $depth++;
        }

        return $current;
    }

    /**
     * The full chain from the root down to this Dossier, root first.
     *
     * Built for the breadcrumb: real navigation from real rows, never a
     * presentation-only path. Bounded the same way as `governingDossier()`.
     */
    public function ancestryChain(): \Illuminate\Support\Collection
    {
        $chain = collect([$this]);
        $current = $this;
        $depth = 0;

        while ($current->parent_id !== null && $depth < self::MAX_DEPTH) {
            $next = $current->parent()->first();

            if ($next === null) {
                break;
            }

            $chain->prepend($next);
            $current = $next;
            $depth++;
        }

        return $chain;
    }

    /**
     * Refuse a parent that would corrupt the tree.
     *
     * Three rules, all of them checked before a single row is written:
     * the parent must exist in the same Organization (Organization = Tenant,
     * a Dossier never reaches across one) ; a Dossier is never its own
     * parent ; and a Dossier may never move under one of its own
     * descendants, which is what a cycle actually is here.
     *
     * @throws \RuntimeException with a message safe to surface to the caller
     */
    public function assertValidParent(?self $parent): void
    {
        if ($parent === null) {
            return;
        }

        // « Mes documents » ne se deplace jamais : lui donner un parent en
        // ferait un enfant, donc — par `dossiers_holder_xor` — une ligne sans
        // `owner_id`, et la racine personnelle de quelqu'un cesserait d'etre
        // la sienne. Refuse ici, au seul endroit que tout chemin d'ecriture
        // traverse, plutot que dans chaque appelant.
        if ($this->isPersonalDocumentsRoot()) {
            throw new \RuntimeException('dossiers.system_root_move_refused');
        }

        if ($parent->organization_id !== $this->organization_id) {
            throw new \RuntimeException('dossiers.move_cross_organization_refused');
        }

        if ($this->id !== null && $parent->id === $this->id) {
            throw new \RuntimeException('dossiers.move_self_refused');
        }

        if ($this->id === null) {
            return;
        }

        $walker = $parent;
        $depth = 0;

        while ($walker !== null && $depth < self::MAX_DEPTH) {
            if ($walker->id === $this->id) {
                throw new \RuntimeException('dossiers.move_into_descendant_refused');
            }

            $walker = $walker->parent_id !== null ? $walker->parent()->first() : null;
            $depth++;
        }
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** The Loop that OWNS this Dossier — its root Dossier. */
    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    /**
     * The Loop this personal Dossier is SHARED with.
     *
     * Not the same thing as loop(): the owner stays the owner, and the Dossier
     * never becomes that Loop's root Dossier.
     */
    public function sharedWithLoop(): BelongsTo
    {
        return $this->belongsTo(Loop::class, 'shared_with_loop_id');
    }

    public function rootBlogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'root_blog_post_id');
    }

    public function dossierBlogPosts(): HasMany
    {
        return $this->hasMany(DossierBlogPost::class)->orderBy('position')->orderBy('created_at');
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'dossier_blog_posts')
            ->withPivot('id', 'organization_id', 'added_by', 'position')
            ->withTimestamps()
            ->orderByPivot('position')
            ->orderBy('blog_posts.created_at');
    }

    public function dossierMembers(): HasMany
    {
        return $this->hasMany(DossierMember::class);
    }

    public function articleSeries(): HasMany
    {
        return $this->hasMany(ArticleSeries::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(DossierFile::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dossier_members')
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    public function isMember(string $userId): bool
    {
        return $this->dossierMembers()->where('user_id', $userId)->exists();
    }

    public function memberRoleFor(string $userId): ?string
    {
        $member = $this->dossierMembers()->where('user_id', $userId)->first();

        return $member?->role;
    }

    /**
     * Visibility derived from membership — for personal Dossiers only.
     *
     * A Loop's Dossier takes its confidentiality from the Loop, and holds no
     * rows in dossier_members at all, so deriving anything from them would
     * flip it to private on every call.
     */
    /**
     * Historical hook, now almost inert — and that is the point.
     *
     * It used to rewrite `visibility` from the mere presence of members, which
     * is why a user could never keep a chosen value: the column looked like a
     * setting and behaved like a computed field. Adding someone to a private
     * Dossier is not a change of audience, so it no longer touches anything.
     *
     * It survives only to normalise the legacy `shared` value on rows that
     * predate the migration.
     */
    public function syncVisibility(): void
    {
        if ($this->isLoopDossier() || $this->visibility !== self::VISIBILITY_SHARED) {
            return;
        }

        $this->update(['visibility' => self::VISIBILITY_PRIVATE]);
    }

    /**
     * The visibility actually in force.
     *
     * A root Dossier has none of its own: it is its Loop's, evaluated on read.
     * Nothing is duplicated and nothing is synchronised, so a Loop that becomes
     * private takes its Dossier with it immediately.
     */
    public function effectiveVisibility(): string
    {
        return $this->isLoopDossier() ? self::VISIBILITY_LOOP : $this->visibility;
    }

    /** True when the user may not choose this Dossier's audience. */
    public function visibilityIsInherited(): bool
    {
        return $this->isLoopDossier();
    }
}
