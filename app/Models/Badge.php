<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Lang;

class Badge extends Model
{
    use HasUuids;

    protected $fillable = ['key', 'name', 'description', 'icon', 'color', 'organization_id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * TASK-1757 (§9.7) — le libelle AFFICHE, et pourquoi il ne vient plus de
     * la colonne.
     *
     * Le catalogue de badges est de la DONNEE : `BadgeSeeder` seme `name` en
     * base et le profil lisait `$badge->name`. La passe de lexique de la TASK A
     * (T1754) s'est donc arretee aux fichiers de langue et a laisse « Prestataire »,
     * « Multi-prestataire », « Evaluateur », « A complete son premier echange de
     * service » sur le profil — exactement le vocabulaire que son critere
     * d'acceptation n°1 bannit de l'UI membre. Et ces libelles n'etaient
     * traduits dans AUCUNE langue : un profil en anglais affichait du francais.
     *
     * Le repli sur la colonne est load-bearing : un badge pose demain par une
     * migration, ou par une Organization, s'affichera quand meme. Meme doctrine
     * que `MessageSysteme::rendre()` en T1754 — cle si connue, texte brut sinon.
     *
     * `Lang::has(..., null, false)` desactive le repli de LOCALE, comme le
     * centre de notifications : une cle presente en francais seulement ne doit
     * pas s'afficher sous une interface anglaise a la place du repli.
     */
    public function libelle(): string
    {
        return $this->traduit('badges.keys.', $this->name);
    }

    /** L'explication affichee en infobulle. Meme discipline de repli. */
    public function explication(): string
    {
        return $this->traduit('badges.descriptions.', (string) $this->description);
    }

    private function traduit(string $prefixe, string $repli): string
    {
        $cle = $prefixe.$this->key;

        return Lang::has($cle, null, false) ? __($cle) : $repli;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'badge_user', 'badge_id', 'user_id')
            ->withPivot('earned_at');
    }
}
