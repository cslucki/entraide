<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une Sequence d'un Module.
 *
 * **Ce n'est pas une Card** non plus. Elle dit **une** chose, de trois manieres :
 * un texte ecrit sur place, un Article existant, ou un fichier de Dossier
 * existant. Les deux dernieres sont des **references** : rien n'est recopie,
 * donc rien ne diverge de l'original quand celui-ci change.
 *
 * Aucun second systeme de documents n'est cree ici. Une Sequence qui pointe
 * vers un Article pointe vers l'Article, pas vers une copie de son texte.
 */
class CourseSequence extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_ARTICLE = 'article';

    public const TYPE_FILE = 'file';

    use HasUuids;

    protected $fillable = [
        'organization_id',
        'course_module_id',
        'title',
        'body',
        'requires_validation',
        'archived_at',
        'blog_post_id',
        'dossier_file_id',
        'position',
        'created_by',
    ];

    protected $casts = [
        'position' => 'integer',
        'requires_validation' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }

    public function dossierFile(): BelongsTo
    {
        return $this->belongsTo(DossierFile::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Ce que cette Sequence porte : `article`, `file` ou `text`.
     *
     * La reference l'emporte sur le texte : une Sequence qui pointe vers un
     * Article **est** cet Article, meme si un corps y traine encore d'une
     * edition precedente. Une seule lecture, pour que les vues ne se
     * contredisent pas.
     */
    public function contentType(): string
    {
        return match (true) {
            $this->blog_post_id !== null => self::TYPE_ARTICLE,
            $this->dossier_file_id !== null => self::TYPE_FILE,
            default => self::TYPE_TEXT,
        };
    }

    /**
     * Le nom a afficher : celui de la Sequence, sinon celui de ce qu'elle
     * designe, sinon un libelle de repli.
     *
     * Le repli n'est pas decoratif. `addSequence()` autorise un titre vide
     * quand une reference est fournie — c'est la reference qui nomme la
     * Sequence. Mais les deux cles sont en `ON DELETE SET NULL` : supprimer
     * l'Article laisse une Sequence sans titre **et** sans reference. Sans
     * repli, elle s'affichait comme une ligne vide, impossible a designer et
     * donc impossible a supprimer.
     */
    public function displayName(): string
    {
        if (filled($this->title)) {
            return $this->title;
        }

        $nom = match ($this->contentType()) {
            self::TYPE_ARTICLE => (string) ($this->blogPost?->title ?? ''),
            self::TYPE_FILE => (string) ($this->dossierFile?->display_name ?? $this->dossierFile?->original_name ?? ''),
            default => '',
        };

        return filled($nom) ? $nom : __('loops.cards.course_material.sequence_untitled');
    }
}
