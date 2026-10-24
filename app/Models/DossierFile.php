<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class DossierFile extends Model
{
    use HasFactory;
    use HasOrganizationId;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'dossier_id',
        'uploaded_by',
        'disk',
        'path',
        'original_name',
        'display_name',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    /**
     * TASK-1695 — les autorisations de lecture posees sur CE fichier.
     *
     * Distinctes de `dossier_members`, qui porte sur le Dossier : ici l'acces
     * ne vient ni du contenant ni d'un heritage, mais du fichier lui-meme.
     */
    public function shares(): HasMany
    {
        return $this->hasMany(DossierFileShare::class, 'dossier_file_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Le nom que la personne LIT.
     *
     * TASK-1695 — bug signale par Cyril : un fichier renomme gardait son
     * ancien nom dans « Partages », des deux cotes. Le renommage ecrit
     * `display_name` ; `original_name` reste la trace de ce qui a ete depose
     * et ne bouge jamais. Ma vue du geste E lisait `original_name` seul.
     *
     * L'idiome `display_name || original_name` etait deja repete a SIX
     * endroits en Blade et en JS. Une septieme copie ecrite a la main aurait
     * derive comme les autres finiront par le faire : la regle vit ici, et
     * les rendus serveur la demandent au lieu de la reecrire.
     *
     * Le repli `?:` est DEFENSIF, et rien de plus — je l'avais d'abord
     * justifie par « `display_name` est NULL pour la majorite des fichiers » :
     * **c'etait faux**. La colonne est NOT NULL (migration du 18/07, verifie
     * sur SQLite ET PostgreSQL), et un televersement y recopie
     * `original_name`. Mesure du 24/10 sur la base locale : 13 fichiers,
     * **0 NULL**, 12 identiques a `original_name` — le treizieme etant celui
     * que Cyril venait de renommer.
     *
     * Il reste parce qu'une chaine vide passerait la contrainte NOT NULL et
     * afficherait une ligne sans nom ; il ne protege pas de ce que je croyais.
     */
    public function nomLisible(): string
    {
        return $this->display_name ?: $this->original_name;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Apercu inline possible dans la modale existante du drive : la MEME
     * allowlist que `ouvrirFichier()` et `canPreviewResult()` cote JS
     * (resources/js/app.js) — elargir l'une sans les autres rendrait
     * l'affordance mensongere (TASK-1296).
     */
    public static function isPreviewableMime(?string $mimeType): bool
    {
        return $mimeType !== null && (
            str_starts_with($mimeType, 'image/')
            || in_array($mimeType, ['application/pdf', 'text/plain', 'text/markdown'], true)
        );
    }

    public function temporaryUrl(int $minutes = 30): string
    {
        return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($minutes));
    }
}
