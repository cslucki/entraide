<?php

namespace App\Support\Loops;

/**
 * Autorite unique sur le catalogue PLATEFORME des plugins de Boucle.
 *
 * Tout ce qu'un plugin est vit dans config/loop_plugins.php, et cette classe
 * est la seule a lire ce fichier. Controleurs, vues et services demandent ici
 * plutot que de faire pousser leur propre `config('loop_plugins...')` — c'est
 * ce qui fait qu'un deuxieme plugin restera une entree de configuration.
 *
 * Elle ne sait RIEN de la disponibilite : savoir si une Organization a le
 * droit d'utiliser un plugin est une decision persistee, lue par
 * LoopPluginAvailabilityService. Le catalogue dit ce qui existe ; la
 * disponibilite dit ou.
 *
 * Meme grammaire que LoopTypeRegistry, pour la meme raison : une cle technique
 * stable d'un cote, un mot traduisible de l'autre.
 */
class LoopPluginRegistry
{
    public const STATUS_EXPERIMENTAL = 'experimental';

    /**
     * Le catalogue entier, indexe par cle technique.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $plugins = config('loop_plugins.plugins', []);

        return is_array($plugins) ? $plugins : [];
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function exists(?string $key): bool
    {
        return is_string($key) && $key !== '' && array_key_exists($key, $this->all());
    }

    /** @return array<string, mixed>|null */
    public function definition(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Le mot affiche.
     *
     * Repli sur la cle : un plugin nomme au catalogue sans traduction doit
     * rester LISIBLE dans l'ecran d'administration plutot que d'y apparaitre
     * comme une ligne vide. Un libelle manquant est un oubli de langue, pas
     * une raison de cacher une capacite au SuperAdmin.
     */
    public function label(string $key): string
    {
        $definition = $this->definition($key);

        if ($definition === null) {
            return $key;
        }

        if (isset($definition['label_key'])) {
            $traduit = __($definition['label_key']);

            // `__()` rend la CLE quand rien ne la traduit : on ne veut pas
            // afficher « loops.plugins.x.label » a un humain.
            if ($traduit !== $definition['label_key']) {
                return $traduit;
            }
        }

        return $definition['label'] ?? $key;
    }

    public function description(string $key): ?string
    {
        $definition = $this->definition($key);

        if ($definition === null) {
            return null;
        }

        if (isset($definition['description_key'])) {
            $traduit = __($definition['description_key']);

            if ($traduit !== $definition['description_key']) {
                return $traduit;
            }
        }

        return $definition['description'] ?? null;
    }

    /**
     * L'etat affiche du plugin — `experimental` par defaut.
     *
     * Un plugin qui ne declare pas son etat est traite comme experimental :
     * c'est l'hypothese PRUDENTE. Le contraire ferait passer un oubli de
     * configuration pour une capacite stabilisee.
     */
    public function status(string $key): string
    {
        return $this->definition($key)['status'] ?? self::STATUS_EXPERIMENTAL;
    }

    public function isExperimental(string $key): bool
    {
        return $this->status($key) === self::STATUS_EXPERIMENTAL;
    }

    /**
     * TASK-1879 — le plugin porte-t-il des ASSISTANTS IA ?
     *
     * Une declaration EXPLICITE (`has_assistants => true`), jamais deduite de
     * la presence d'une cle `assistants` : un plugin qui ne le dit pas n'en a
     * pas. C'est cette reponse qui ferme en 404 les routes de reglage des
     * postures et des modeles pour la Veille — et pour tout plugin futur qui
     * n'a rien a regler de ce cote.
     */
    public function hasAssistants(?string $key): bool
    {
        if (! $this->exists($key)) {
            return false;
        }

        return ($this->definition($key)['has_assistants'] ?? false) === true;
    }

    /** L'adresse de l'ecran SuperAdmin du plugin. Repli sur la cle. */
    public function adminSlug(string $key): string
    {
        $slug = $this->definition($key)['admin_slug'] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : $key;
    }

    /**
     * Le slug d'URL -> la cle technique, ou null.
     *
     * Le slug arrive d'une URL : il n'est JAMAIS utilise tel quel comme cle.
     * Il est compare au catalogue, et seule une correspondance exacte rend une
     * cle — un slug inconnu rend null, et l'appelant repond 404.
     */
    public function keyForAdminSlug(?string $slug): ?string
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        foreach ($this->keys() as $key) {
            if ($this->adminSlug($key) === $slug) {
                return $key;
            }
        }

        return null;
    }

    /** La Card que ce plugin rend disponible dans une Boucle, ou null. */
    public function cardFor(string $key): ?string
    {
        $card = $this->definition($key)['card'] ?? null;

        return is_string($card) && $card !== '' ? $card : null;
    }
}
