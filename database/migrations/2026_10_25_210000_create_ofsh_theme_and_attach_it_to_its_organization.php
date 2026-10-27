<?php

use App\Models\Organization;
use App\Models\Theme;
use Illuminate\Database\Migrations\Migration;

/**
 * TASK-1699 — la ligne `themes` du theme OFSH, et son rattachement.
 *
 * ## Ce que cette migration ne fait PAS
 *
 * Elle ne DEFINIT pas la palette. Celle-ci vit dans
 * `config/bouclepro_themes.php`, ou elle est lisible dans l'historique git et
 * ou `Theme::regenerateCache()` va la chercher. Cette migration ne cree qu'une
 * ligne PORTEUSE : `organizations.theme_id` est une cle etrangere vers
 * `themes.id`, et une palette qui n'existe qu'en configuration n'a pas d'`id`
 * auquel se rattacher. C'est son seul role.
 *
 * La ligne est donc volontairement VIDE de jetons (`tokens` et `dark_tokens` a
 * `[]`) : `regenerateCache()` fusionne la base PAR-DESSUS la configuration,
 * token par token. Y recopier la palette la figerait dans la base, et toute
 * correction ulterieure du fichier de configuration serait silencieusement
 * ignoree — exactement le genre de divergence qui ne se voit qu'a l'ecran.
 *
 * ## Idempotence, et le cas ou l'Organization n'existe pas
 *
 * Sur une base de test fraiche, l'Organization `ofsh` n'existe pas : la
 * migration cree alors la ligne de theme sans proprietaire et ne rattache
 * rien. C'est le comportement voulu — la palette reste disponible (elle vient
 * de la configuration de toute facon), et aucune Organization n'est inventee.
 *
 * Le `down()` detache avant de supprimer : l'ordre inverse violerait la cle
 * etrangere.
 */
return new class extends Migration
{
    private const KEY = 'ofsh';

    public function up(): void
    {
        $ofsh = Organization::where('slug', 'ofsh')->first();

        $theme = Theme::firstOrNew(['key' => self::KEY]);
        $theme->label ??= 'OFSH';
        $theme->description ??= config('bouclepro_themes.themes.'.self::KEY.'.description');
        $theme->tokens ??= [];
        $theme->dark_tokens ??= [];

        // Un theme appartenant a une Organization n'est proposable que chez
        // elle (`Theme::scopeAccessibleBy`). Sans Organization OFSH, la ligne
        // reste sans proprietaire plutot que d'etre rattachee au hasard.
        if ($ofsh) {
            $theme->organization_id = $ofsh->id;
        }

        $theme->save();

        if ($ofsh && $ofsh->theme_id === null) {
            $ofsh->forceFill(['theme_id' => $theme->id])->save();
        }

        Theme::regenerateCache();
    }

    public function down(): void
    {
        $theme = Theme::where('key', self::KEY)->first();

        if (! $theme) {
            return;
        }

        Organization::where('theme_id', $theme->id)
            ->update(['theme_id' => null]);

        $theme->delete();

        Theme::regenerateCache();
    }
};
