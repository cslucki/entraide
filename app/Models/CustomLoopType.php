<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un type de Boucle cree depuis l'administration, par opposition a ceux que
 * declare `config/loop_types.php`.
 *
 * Les deux vivent dans le meme catalogue et se lisent par le meme registre.
 * Ce qui les distingue n'est pas leur nature mais leur origine : l'un se change
 * par un deploiement, l'autre par un formulaire.
 *
 * **`organization_id` nullable, `null` signifie Plateforme** — la meme grammaire
 * que `loop_type_settings` et `organizations.loop_permissions`.
 */
class CustomLoopType extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'key', 'label', 'description',
        'based_on', 'icon', 'cards', 'available', 'order', 'created_by',
    ];

    protected $casts = [
        'cards' => 'array',
        'available' => 'boolean',
        'order' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * La cle technique d'un type cree par une Organization.
     *
     * **Prefixee, et definitivement.** Le prefixe rend l'appartenance lisible
     * dans la cle elle-meme — on sait d'un coup d'oeil, dans `loops.type`, d'ou
     * vient un type — et rend toute collision impossible avec une cle du fichier
     * de configuration, presente ou future. Sans lui, une Organization qui
     * creerait « training » masquerait le type Formation pour elle seule, ce
     * qu'aucun ecran ne saurait expliquer.
     *
     * Les six premiers caracteres de l'identifiant suffisent a separer les
     * Organizations entre elles ; le double tiret bas evite toute ambiguite avec
     * un mot compose.
     */
    public static function forgeKey(?Organization $organization, string $nom): string
    {
        $base = Str::slug($nom, '_');
        $base = $base === '' ? 'type' : $base;

        if ($organization === null) {
            return Str::limit($base, 80, '');
        }

        $prefixe = 'org_'.substr(str_replace('-', '', (string) $organization->id), 0, 6).'__';

        return $prefixe.Str::limit($base, 80 - strlen($prefixe), '');
    }
}
