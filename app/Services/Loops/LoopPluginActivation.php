<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopPlugin;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopPluginRegistry;

/**
 * Le seul endroit qui dit — et decide — si un plugin est actif dans une Boucle.
 *
 * TASK-1616 / SLICE B. Trois questions distinctes, et les confondre serait la
 * faute :
 *
 *   - le plugin EXISTE-t-il ?              -> le catalogue (LoopPluginRegistry)
 *   - est-il DISPONIBLE ici ?              -> l'Organization (SLICE A)
 *   - est-il ACTIF dans cette Boucle ?     -> ce service
 *
 * **Le hard gate Organization est PORTE ICI, pas par l'ecran.** Cacher un
 * bouton n'a jamais empeche un POST : chaque lecture et chaque ecriture
 * reconfronte la disponibilite de l'Organization. Une Boucle d'une
 * Organization non autorisee ne peut ni voir, ni activer, ni configurer — et
 * retirer l'autorisation a une Organization eteint instantanement toutes ses
 * Boucles, sans qu'aucune ligne ne soit reecrite.
 *
 * FERME PAR DEFAUT a chaque etage.
 */
class LoopPluginActivation
{
    public function __construct(
        private LoopPluginRegistry $plugins,
        private LoopPluginAvailabilityService $availability,
        private LoopPermissionResolver $permissions,
    ) {}

    // ── Le gate ─────────────────────────────────────────────────────────────

    /**
     * Ce plugin peut-il seulement exister dans cette Boucle ?
     *
     * Deux conditions, et aucune n'est negociable : la cle doit etre au
     * catalogue, et l'Organization de la BOUCLE doit l'avoir autorisee.
     * L'Organization est celle de la Boucle, jamais celle de l'utilisateur —
     * un SuperAdmin agit depuis `/admin`, hors du tenant concerne.
     */
    public function isAvailableFor(string $pluginKey, Loop $loop): bool
    {
        if (! $this->plugins->exists($pluginKey)) {
            return false;
        }

        $organization = $loop->organization;

        if (! $organization instanceof Organization) {
            return false;
        }

        return $this->availability->isAvailable($pluginKey, $organization);
    }

    /**
     * Qui peut activer et regler.
     *
     * `loop_plugins.configure` et rien d'autre. Le resolveur fait le reste :
     * SuperAdmin et Admin d'Organization par ses etapes 3 et 4, owner et
     * facilitator par le socle de role, membre en refus.
     *
     * Le droit ne s'evalue QUE si le gate Organization est franchi : sans
     * disponibilite, il n'y a rien a autoriser.
     */
    public function canConfigure(?User $user, string $pluginKey, Loop $loop): bool
    {
        if (! $user instanceof User || ! $this->isAvailableFor($pluginKey, $loop)) {
            return false;
        }

        return $this->permissions->can($user, $loop, 'loop_plugins.configure');
    }

    // ── Lecture ─────────────────────────────────────────────────────────────

    /** Le plugin est-il ACTIF dans cette Boucle ? Ferme par defaut. */
    public function isEnabled(string $pluginKey, Loop $loop): bool
    {
        if (! $this->isAvailableFor($pluginKey, $loop)) {
            return false;
        }

        return (bool) LoopPlugin::query()
            ->where('loop_id', $loop->id)
            ->where('plugin_key', $pluginKey)
            ->value('enabled');
    }

    /**
     * Ce que l'ecran doit rendre : un plugin par ligne, ou RIEN.
     *
     * Une Organization non autorisee rend un tableau vide — le plugin est
     * alors absent de l'ecran, il n'y apparait pas eteint. « Pas autorise » et
     * « eteint » ne sont pas le meme etat, et les confondre ferait croire a un
     * geste possible.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeFor(Loop $loop): array
    {
        $lignes = [];

        foreach ($this->plugins->keys() as $key) {
            if (! $this->isAvailableFor($key, $loop)) {
                continue;
            }

            $decision = LoopPlugin::query()
                ->where('loop_id', $loop->id)
                ->where('plugin_key', $key)
                ->with('updatedBy:id,name')
                ->first();

            $lignes[] = [
                'key' => $key,
                'label' => $this->plugins->label($key),
                'description' => $this->plugins->description($key),
                'status' => $this->plugins->status($key),
                'experimental' => $this->plugins->isExperimental($key),
                'enabled' => (bool) $decision?->enabled,
                'decision' => $decision,
            ];
        }

        return $lignes;
    }

    // ── Ecriture ────────────────────────────────────────────────────────────

    /**
     * Allumer ou eteindre dans CETTE Boucle.
     *
     * Le gate est reverifie ici, apres l'avoir deja ete par l'appelant. Ce
     * n'est pas une redondance : ce service est la derniere porte avant la
     * base, et c'est la seule que ne franchit pas une requete forgee.
     *
     * `organization_id` est pris sur la BOUCLE, jamais sur la session ni sur
     * la requete.
     */
    public function setEnabled(string $pluginKey, Loop $loop, bool $enabled, ?User $actor = null): LoopPlugin
    {
        if (! $this->isAvailableFor($pluginKey, $loop)) {
            throw new \InvalidArgumentException(
                "Plugin indisponible pour l'Organization de cette Boucle : {$pluginKey}"
            );
        }

        return LoopPlugin::query()->updateOrCreate(
            [
                'loop_id' => $loop->id,
                'plugin_key' => $pluginKey,
            ],
            [
                'organization_id' => $loop->organization_id,
                'enabled' => $enabled,
                'updated_by' => $actor?->id,
            ],
        );
    }
}
