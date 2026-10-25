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

    /**
     * TASK-1695 (point 5a) — les partages vers des BOUCLES, a cote des
     * partages nominatifs. Deux tables, deux relations : voir la migration.
     */
    public function loopShares(): HasMany
    {
        return $this->hasMany(DossierFileLoopShare::class);
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

    /**
     * Le TYPE, en un mot lisible.
     *
     * TASK-1695 (point 2/3 de Cyril) — l'extension, pas le type MIME.
     * « application/vnd.openxmlformats-officedocument.wordprocessingml.document »
     * remplit une colonne de tableau sans rien apprendre a personne ; « DOCX »
     * tient en quatre lettres et se trie.
     *
     * Lue sur `original_name` et non `display_name` : le renommage conserve
     * l'extension d'origine (voir `DossierFileController::rename`), mais c'est
     * `original_name` qui en porte la trace fiable.
     */
    public function typeLisible(): string
    {
        $extension = pathinfo((string) $this->original_name, PATHINFO_EXTENSION);

        return $extension !== '' ? mb_strtoupper($extension) : '—';
    }

    /**
     * La TAILLE, en unites lisibles.
     *
     * Les memes unites et le meme arrondi que `formatBytes()` dans
     * `resources/js/app.js` : les deux rendus coexistent sur le meme fichier —
     * le Drive en JS, « Partages » en Blade — et deux tailles differentes pour
     * un meme fichier passeraient pour un defaut de donnee.
     */
    public function tailleLisible(): string
    {
        $octets = (int) $this->size_bytes;

        if ($octets <= 0) {
            return '0 o';
        }

        $unites = ['o', 'Ko', 'Mo', 'Go'];
        $i = min((int) floor(log($octets, 1024)), count($unites) - 1);

        return number_format($octets / (1024 ** $i), $i > 0 ? 1 : 0, ',', ' ').' '.$unites[$i];
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
