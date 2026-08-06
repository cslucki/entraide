<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ArticleSeries extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'dossier_id',
        'root_blog_post_id',
        'name',
        'created_by',
    ];

    /**
     * Le nom d'une Serie.
     *
     * Une Serie ancree sur un Article racine prend son titre ; une Serie de
     * fichiers, qui n'a pas de racine, porte son propre nom. Deux sources, un
     * seul point de lecture : sans cela chaque vue aurait invente sa propre
     * regle de repli.
     */
    public function displayName(): string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        return (string) ($this->rootBlogPost?->title ?? '');
    }

    /**
     * La Serie ordonnee, chaque contenu portant son numero **calcule**.
     *
     * Le numero vient du rang, jamais d'une colonne et **jamais du titre**.
     * Reordonner n'ecrit que des positions ; retirer un element renumerote tout
     * le reste sans une seule ecriture. C'est la seule facon d'avoir des
     * numeros toujours justes : un numero recopie dans un titre ou un nom de
     * fichier devient faux au premier deplacement, et il faut alors renommer le
     * travail des gens pour le rattraper.
     *
     * La racine, quand il y en a une, est le numero 1 — c'est deja ce que dit
     * la navigation d'un Article a l'autre. Une Serie de fichiers n'en a pas :
     * elle commence a son premier item.
     *
     * @return \Illuminate\Support\Collection<int, array{number: string, rank: int, type: string, name: string, item: ?ArticleSeriesItem, content: ?Model}>
     */
    public function numberedContents(): Collection
    {
        $entries = collect();

        if ($this->rootBlogPost) {
            $entries->push([
                'type' => 'root',
                'name' => (string) $this->rootBlogPost->title,
                'item' => null,
                'content' => $this->rootBlogPost,
            ]);
        }

        foreach ($this->items as $item) {
            $entries->push([
                'type' => $item->contentType(),
                'name' => $item->displayName(),
                'item' => $item,
                'content' => $item->content(),
            ]);
        }

        return $entries->values()->map(function (array $entry, int $index) {
            $rank = $index + 1;

            return [
                // `01`, `02`, … et au-dela de 99 le numero s'allonge plutot que
                // de tronquer : `100` reste juste, `00` ne le serait pas.
                'number' => str_pad((string) $rank, 2, '0', STR_PAD_LEFT),
                'rank' => $rank,
                ...$entry,
            ];
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function rootBlogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'root_blog_post_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ArticleSeriesItem::class)->orderBy('position')->orderBy('created_at');
    }

    public function annexes(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'article_series_items')
            ->withPivot('id', 'organization_id', 'added_by', 'position')
            ->withTimestamps()
            ->orderByPivot('position')
            ->orderBy('blog_posts.created_at');
    }
}
