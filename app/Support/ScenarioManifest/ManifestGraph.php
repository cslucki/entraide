<?php

namespace App\Support\ScenarioManifest;

/**
 * Index de lecture du document : collections par nom, stable keys, et le
 * registre des memberships.
 *
 * Les phases relationnelles posent toutes la meme question — "cette cle
 * existe-t-elle, et cet auteur est-il membre de cette Boucle ?" — et la spec
 * en fait un invariant global ("Tout auteur d'un objet de Boucle est membre
 * actif de cette Boucle", 7.3). Une seule reponse, calculee une fois ici, evite
 * que deux regles voisines repondent differemment a la meme question.
 *
 * L'index est TOLERANT aux valeurs malformees : une entree qui n'est pas un
 * objet, ou dont la `key` n'est pas une chaine, est simplement absente de
 * l'index. Sa faute de forme a deja ete signalee par la phase precedente, et
 * l'empiler ici ajouterait du bruit sans ajouter d'information.
 */
final class ManifestGraph
{
    /** @var array<string, list<\stdClass>> */
    private array $collections = [];

    /** @var array<string, array<string, int>> */
    private array $keyIndexes = [];

    /** @var array<string, array<string, string>> loop => user => role */
    private array $memberships = [];

    public function __construct(private readonly \stdClass $root)
    {
        foreach (array_keys(ManifestSchema::referencableCollections()) as $name) {
            $this->collections[$name] = $this->readCollection($name);
            $this->keyIndexes[$name] = $this->indexKeys($this->collections[$name]);
        }

        foreach (array_keys(ManifestSchema::keylessCollections()) as $name) {
            $this->collections[$name] = $this->readCollection($name);
        }

        $this->indexMemberships();
    }

    /**
     * @return array<int, \stdClass>
     */
    public function collection(string $name): array
    {
        return $this->collections[$name] ?? [];
    }

    public function pathOf(string $name): string
    {
        $paths = ManifestSchema::referencableCollections() + ManifestSchema::keylessCollections();

        return $paths[$name] ?? '/'.$name;
    }

    public function has(string $collection, string $key): bool
    {
        return array_key_exists($key, $this->keyIndexes[$collection] ?? []);
    }

    public function find(string $collection, string $key): ?\stdClass
    {
        $index = $this->keyIndexes[$collection][$key] ?? null;

        return $index === null ? null : $this->collections[$collection][$index];
    }

    /**
     * Position de l'entree dans sa collection, pour construire un JSON Pointer.
     */
    public function indexOf(string $collection, string $key): ?int
    {
        return $this->keyIndexes[$collection][$key] ?? null;
    }

    /**
     * Role d'un user dans une Boucle, ou `null` s'il n'en est pas membre.
     */
    public function roleIn(string $loopKey, string $userKey): ?string
    {
        return $this->memberships[$loopKey][$userKey] ?? null;
    }

    public function isMember(string $loopKey, string $userKey): bool
    {
        return $this->roleIn($loopKey, $userKey) !== null;
    }

    /**
     * Owner ou facilitator : l'equipe pedagogique d'une Boucle training
     * (spec 12), et l'autorite qui valide, debloque ou relit.
     */
    public function leads(string $loopKey, string $userKey): bool
    {
        return in_array($this->roleIn($loopKey, $userKey), ['owner', 'facilitator'], true);
    }

    /**
     * @return array<string, string> user => role
     */
    public function membersOf(string $loopKey): array
    {
        return $this->memberships[$loopKey] ?? [];
    }

    /**
     * Boucle qui gouverne un Dossier, `null` s'il est hors Boucle.
     */
    public function loopOfDossier(string $dossierKey): ?string
    {
        $dossier = $this->find('dossiers', $dossierKey);
        $loop = $dossier?->loop ?? null;

        return is_string($loop) ? $loop : null;
    }

    public function root(): \stdClass
    {
        return $this->root;
    }

    /**
     * @return array<int, \stdClass>
     */
    private function readCollection(string $name): array
    {
        $node = str_starts_with($name, 'training.')
            ? ($this->root->training ?? null)?->{substr($name, strlen('training.'))} ?? null
            : $this->root->{$name} ?? null;

        if (! is_array($node) || ! array_is_list($node)) {
            return [];
        }

        // Les cles d'origine sont CONSERVEES : elles sont l'index JSON de
        // l'entree, donc le segment de son JSON Pointer. Les renumeroter
        // ferait pointer les erreurs relationnelles a cote des lignes fautives
        // des qu'une entree du tableau n'est pas un objet.
        return array_filter($node, static fn (mixed $item): bool => $item instanceof \stdClass);
    }

    /**
     * @param  array<int, \stdClass>  $items
     * @return array<string, int>
     */
    private function indexKeys(array $items): array
    {
        $index = [];

        foreach ($items as $position => $item) {
            $key = $item->key ?? null;

            // Premiere occurrence gagnante : un doublon est deja signale par
            // DUPLICATE_KEY, et l'index ne doit pas dependre de laquelle des
            // deux entrees a ete lue en dernier.
            if (is_string($key) && ! array_key_exists($key, $index)) {
                $index[$key] = $position;
            }
        }

        return $index;
    }

    private function indexMemberships(): void
    {
        foreach ($this->collections['memberships'] as $membership) {
            $loop = $membership->loop ?? null;
            $user = $membership->user ?? null;
            $role = $membership->role ?? null;

            if (is_string($loop) && is_string($user) && is_string($role) && ! isset($this->memberships[$loop][$user])) {
                $this->memberships[$loop][$user] = $role;
            }
        }
    }
}
