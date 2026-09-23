<?php

namespace App\Services\Loops;

use App\Models\Organization;
use App\Models\OrganizationLoopPlugin;
use App\Models\User;
use App\Support\Loops\LoopPluginRegistry;

/**
 * Le seul endroit qui lit ou ecrit la disponibilite d'un plugin de Boucle.
 *
 * TASK-1614. La question posee est toujours la meme, et elle a toujours DEUX
 * termes : « ce plugin est-il disponible DANS CETTE Organization ? ». Il n'y a
 * pas de reponse globale, et cette classe n'en offre aucune : chaque methode
 * exige l'Organization, aucune ne la devine.
 *
 * FERME PAR DEFAUT. Pas de ligne, `available = false`, cle inconnue du
 * catalogue, Organization sans decision : la reponse est NON. Une capacite
 * experimentale ne s'allume que la ou quelqu'un l'a explicitement allumee.
 *
 * Le CATALOGUE (ce qu'un plugin est) vit dans config/loop_plugins.php et se lit
 * par LoopPluginRegistry. Cette classe ne decrit rien : elle autorise.
 */
class LoopPluginAvailabilityService
{
    public function __construct(private LoopPluginRegistry $plugins) {}

    // ── Lecture ─────────────────────────────────────────────────────────────

    /**
     * Ce plugin est-il disponible dans cette Organization ?
     *
     * La cle est confrontee au CATALOGUE avant la table. Une ligne orpheline —
     * un plugin retire de la configuration mais dont la decision reste en base
     * — ne doit pas continuer d'autoriser quoi que ce soit : le catalogue est
     * l'autorite sur l'existence, la table seulement sur la portee.
     */
    public function isAvailable(string $pluginKey, Organization $organization): bool
    {
        if (! $this->plugins->exists($pluginKey)) {
            return false;
        }

        return (bool) OrganizationLoopPlugin::query()
            ->where('organization_id', $organization->id)
            ->where('plugin_key', $pluginKey)
            ->value('available');
    }

    /**
     * L'etat de CHAQUE plugin du catalogue pour une Organization.
     *
     * Toutes les cles sont rendues, y compris celles sans ligne : l'ecran doit
     * pouvoir afficher un OFF explicite plutot qu'un trou, faute de quoi « pas
     * encore decide » et « eteint » se ressembleraient.
     *
     * @return array<string, bool>
     */
    public function mapFor(Organization $organization): array
    {
        $lignes = OrganizationLoopPlugin::query()
            ->where('organization_id', $organization->id)
            ->pluck('available', 'plugin_key');

        $carte = [];

        foreach ($this->plugins->keys() as $key) {
            $carte[$key] = (bool) ($lignes[$key] ?? false);
        }

        return $carte;
    }

    /**
     * L'etat d'UN plugin pour une liste d'Organizations, en une requete.
     *
     * C'est ce que consomme l'ecran SuperAdmin : une ligne par Organization,
     * sans N+1. Les Organizations sont passees en parametre — ce service ne
     * decide pas lesquelles sont regardees, l'appelant le fait.
     *
     * @param  iterable<Organization>  $organizations
     * @return array<string, bool> indexe par organization_id
     */
    public function mapForPlugin(string $pluginKey, iterable $organizations): array
    {
        $ids = [];

        foreach ($organizations as $organization) {
            $ids[] = $organization->id;
        }

        $lignes = $ids === []
            ? collect()
            : OrganizationLoopPlugin::query()
                ->where('plugin_key', $pluginKey)
                ->whereIn('organization_id', $ids)
                ->pluck('available', 'organization_id');

        $carte = [];

        foreach ($ids as $id) {
            $carte[$id] = $this->plugins->exists($pluginKey) && (bool) ($lignes[$id] ?? false);
        }

        return $carte;
    }

    /**
     * Le porteur de la derniere decision, pour l'afficher.
     *
     * @return array<string, OrganizationLoopPlugin> indexe par organization_id
     */
    public function decisionsForPlugin(string $pluginKey): array
    {
        return OrganizationLoopPlugin::query()
            ->where('plugin_key', $pluginKey)
            ->with('updatedBy:id,name')
            ->get()
            ->keyBy('organization_id')
            ->all();
    }

    // ── Ecriture ────────────────────────────────────────────────────────────

    /**
     * Allumer ou eteindre un plugin pour UNE Organization.
     *
     * L'Organization est un objet deja resolu, jamais un identifiant recu d'un
     * formulaire : la confrontation a la table appartient a l'appelant, et
     * elle a lieu avant d'arriver ici.
     *
     * La cle est refusee si le catalogue ne la connait pas. Sans cela, un
     * champ forge ecrirait des lignes pour des plugins qui n'existent pas, et
     * la table cesserait d'etre lisible.
     *
     * Eteindre n'efface PAS la ligne : `available = false` conserve qui a coupe
     * et quand, ce qu'une suppression perdrait. C'est l'ecart assume avec
     * `loop_type_settings`, qui lui revient au defaut en supprimant.
     */
    public function setAvailability(
        string $pluginKey,
        Organization $organization,
        bool $available,
        ?User $actor = null,
    ): OrganizationLoopPlugin {
        if (! $this->plugins->exists($pluginKey)) {
            throw new \InvalidArgumentException("Plugin inconnu au catalogue : {$pluginKey}");
        }

        return OrganizationLoopPlugin::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'plugin_key' => $pluginKey,
            ],
            [
                'available' => $available,
                'updated_by' => $actor?->id,
            ],
        );
    }
}
