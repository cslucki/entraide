<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1534 — une connaissance DERIVEE de l'activite humaine.
 *
 * Elle ne pretend pas etre une Interaction, elle n'a pas d'auteur humain, et
 * elle sait d'ou elle vient. Ce qu'elle n'est pas non plus : une verite. Une
 * note est revisable — c'est la memoire de BouclePro, pas l'histoire des
 * humains, qui elle ne se reecrit jamais (CDC CORE section 12).
 *
 * ## La regle de visibilite, et pourquoi elle ne vit pas ici
 *
 * Une note issue d'une Boucle privee ne doit jamais devenir lisible parce
 * qu'elle est rangee dans un Dossier plus ouvert. La regle est donc :
 *
 *     visibilite(note) ⊆ visibilite(Boucle source) ∩ visibilite(Dossier)
 *
 * Cette intersection n'est PAS une colonne : une visibilite copiee se
 * desynchronise au premier changement de droits. Elle est evaluee a la
 * LECTURE, par `DerivedChunkEligibility`, comme `DossierPolicy::view` evalue
 * deja la sienne a chaque requete.
 */
class DerivedKnowledgeNote extends Model
{
    use HasFactory;
    use HasOrganizationId;
    use HasUuids;

    /** La conversation humaine d'une Boucle. Premiere famille, pas la seule prevue. */
    public const SOURCE_LOOP_CONVERSATION = 'loop_conversation';

    /**
     * TASK-1540 — deux natures de memoire cohabitent.
     *
     * `KIND_DIGEST` : le paragraphe conversationnel, un par Boucle. Il reste
     * conteneur, repli et provenance agregee, mais n'est plus indexe des lors
     * que des claims existent — son vecteur, moyenne de huit sujets, se faisait
     * devancer par un Article generique (mesure T1537).
     *
     * `KIND_CLAIM` : un enonce adressable. Il a sa propre identite durable
     * (`subject_key`), sa propre version, son propre `observed_at`, ses propres
     * preuves — et son propre chunk, topiquement homogene.
     */
    public const KIND_DIGEST = 'digest';

    public const KIND_CLAIM = 'claim';

    /** Le `subject_key` reserve au digest conversationnel. */
    public const SUBJECT_DIGEST = 'conversation_digest';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * TASK-1541 — `kind` a une valeur DES LA CONSTRUCTION, pas seulement en base.
     *
     * La colonne porte bien un defaut SQL, mais il ne s'applique qu'a la ligne
     * ecrite : l'instance rendue par `create()` gardait, elle, un `kind` nul.
     * L'indexeur — qui recoit cette instance-la — ne reconnaissait donc pas un
     * digest fraichement compile, et l'indexait a cote des enonces. Le banc de
     * dilution l'a montre : un chunk de paragraphe servi en meme temps que les
     * enonces, portant les memes faits.
     *
     * Un defaut qui ne vaut qu'en base est un defaut qu'on oublie une requete
     * sur deux.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => self::KIND_DIGEST,
    ];

    protected $fillable = [
        'organization_id',
        'source_type',
        'kind',
        'source_loop_id',
        'dossier_id',
        'subject_key',
        'content',
        'source_fingerprint',
        'provenance',
        'observed_at',
        'derived_at',
        'version',
        'status',
        'superseded_by_id',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'provenance' => 'array',
            'observed_at' => 'datetime',
            'derived_at' => 'datetime',
            'version' => 'integer',
            'superseded_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sourceLoop(): BelongsTo
    {
        return $this->belongsTo(Loop::class, 'source_loop_id');
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeClaims(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_CLAIM);
    }

    public function isClaim(): bool
    {
        return $this->kind === self::KIND_CLAIM;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Les identifiants des messages dont cette note est tiree.
     *
     * @return list<string>
     */
    public function sourceMessageIds(): array
    {
        $ids = $this->provenance['source_loop_message_ids'] ?? [];

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }
}
