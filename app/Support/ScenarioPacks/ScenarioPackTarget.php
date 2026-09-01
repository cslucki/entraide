<?php

namespace App\Support\ScenarioPacks;

use App\Models\Organization;
use App\Support\ScenarioPacks\Contracts\ProvisionsItsOrganization;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;

/**
 * TASK-1354 — l'Organization a laquelle un pack est lie, en LECTURE SEULE.
 *
 * ## Le probleme d'interface que ce resolveur ferme
 *
 * L'ecran d'administration presentait deux menus deroulants independants, Pack
 * et Organization, ce qui laissait croire qu'on pouvait charger n'importe quel
 * pack dans n'importe quelle Organization qualifiee. C'est faux : les trois
 * packs sont hard-bound, et `apply()` refuse toute autre cible. L'interface
 * proposait donc des combinaisons que le moteur rejette — par exemple
 * `artscilab-demo-test` dans `artscilab-en`.
 *
 * ## D'ou vient la verite
 *
 * Du pack lui-meme, jamais d'une table ni d'un mapping d'interface : chaque
 * definition expose `ORGANIZATION_SLUG`, la MEME constante que celle que sa
 * garde hard-bound verifie. Un pack qui sait provisionner sa cible se declare
 * en plus par {@see ProvisionsItsOrganization}.
 *
 * Aucune ecriture ici : ce resolveur lit, il ne charge rien, ne cree rien et ne
 * supprime rien. Le moteur (loader, resetter, remover, registrar, purger,
 * guard) n'est pas touche.
 */
class ScenarioPackTarget
{
    /**
     * Le slug de l'Organization cible, ou `null` si le pack n'en nomme aucune.
     *
     * `null` n'est pas un bug : c'est un pack qui n'a pas declare sa cible, et
     * l'interface doit le dire plutot que d'inventer une valeur.
     */
    public function slugFor(ScenarioPackDefinition $pack): ?string
    {
        if ($pack instanceof ProvisionsItsOrganization) {
            return $pack->organizationSlug();
        }

        $constant = $pack::class.'::ORGANIZATION_SLUG';

        if (! defined($constant)) {
            return null;
        }

        $slug = trim((string) constant($constant));

        return $slug === '' ? null : $slug;
    }

    /**
     * Ce pack sait-il creer sa cible quand elle manque ?
     */
    public function provisionsItsOrganization(ScenarioPackDefinition $pack): bool
    {
        return $pack instanceof ProvisionsItsOrganization;
    }

    /**
     * L'Organization cible telle qu'elle existe REELLEMENT, ou `null`.
     *
     * Lue sans scope global : une Organization de demonstration ne doit pas
     * paraitre absente simplement parce que le contexte courant ne la voit pas.
     */
    public function organizationFor(ScenarioPackDefinition $pack): ?Organization
    {
        $slug = $this->slugFor($pack);

        if ($slug === null) {
            return null;
        }

        return Organization::query()->withoutGlobalScopes()->where('slug', $slug)->first();
    }
}
