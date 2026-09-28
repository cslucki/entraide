<?php

namespace App\Support\ScenarioManager\Capture;

use App\Support\ScenarioManifest\ManifestCanonicalJson;
use App\Support\ScenarioManifest\ManifestSchema;

/**
 * TASK-1653 — ce qui a change dans la sandbox depuis son chargement.
 *
 * Compare le **Manifest source effectivement charge** au **snapshot produit par
 * le serializer T1652**. Jamais la base brute : comparer des lignes SQL
 * reviendrait a comparer des rouages, alors que la question porte sur le monde
 * DECLARABLE.
 *
 * ## L'identite, et pourquoi elle n'est jamais un UUID
 *
 * Deux objets sont « le meme » quand ils portent la meme identite MANIFEST :
 *
 * - une **stable key** pour les familles qui en declarent une ;
 * - une **identite COMPOSEE** pour celles que le langage adresse par leurs
 *   composants — `memberships (loop,user)`, `progress (sequence,user)`,
 *   `submissions (assignment,user)`.
 *
 * Un UUID de base ne dirait rien : il change d'un monde a l'autre, et le
 * Manifest n'en transporte aucun. C'est precisement ce qui permet de comparer
 * une version a la sandbox qu'elle a produite.
 *
 * ## `changed` se decide sur le CONTENU canonique
 *
 * Deux objets de meme identite different quand leur contenu declaratif differe
 * apres canonicalisation — le meme encodage a clefs triees qui sert deja au
 * digest. Un `order` ou une `position` qui bouge COMPTE : c'est un fait du
 * monde, pas un detail de serialisation.
 */
final class ScenarioCaptureDiff
{
    public const ADDED = 'added';

    public const REMOVED = 'removed';

    public const CHANGED = 'changed';

    public const UNCHANGED = 'unchanged';

    /**
     * Les familles adressees par une identite COMPOSEE, et leurs composants.
     *
     * Le CDC les nomme : on ne leur fabrique pas de stable key, et on ne les
     * compare donc pas par une clef qui n'existe pas.
     */
    private const IDENTITES_COMPOSEES = [
        'memberships' => ['loop', 'user'],
        'training.progress' => ['sequence', 'user'],
        'training.submissions' => ['assignment', 'user'],
    ];

    /**
     * Les champs qui portent un LIBELLE lisible, par ordre de preference.
     *
     * L'ecran parle en noms, jamais en clefs techniques.
     */
    private const LIBELLES = ['title', 'name', 'question', 'first_name', 'body'];

    /** @var array<string, array{added: int, removed: int, changed: int, unchanged: int, objets: list<array<string, mixed>>}> */
    private array $familles = [];

    /**
     * @param  array<string, mixed>  $source   le Manifest qui a ete charge
     * @param  array<string, mixed>  $courant  le snapshot du serializer
     */
    public function __construct(array $source, array $courant)
    {
        foreach (self::famillesComparables() as $chemin) {
            $this->comparer($chemin, self::lire($source, $chemin), self::lire($courant, $chemin));
        }
    }

    /**
     * Les familles comparables, DECOUVERTES dans le schema.
     *
     * Aucune liste ecrite a la main : une famille ajoutee au Manifest entre
     * d'elle-meme dans le Diff, et une famille retiree en sort.
     *
     * @return list<string>
     */
    public static function famillesComparables(): array
    {
        $chemins = [];

        foreach (ManifestSchema::envelope() as $nom => $spec) {
            if (($spec['type'] ?? null) === 'array') {
                $chemins[] = $nom;

                continue;
            }

            if (($spec['type'] ?? null) === 'object') {
                foreach ($spec['fields'] ?? [] as $sous => $sousSpec) {
                    if (($sousSpec['type'] ?? null) === 'array') {
                        $chemins[] = $nom.'.'.$sous;
                    }
                }
            }
        }

        return $chemins;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private static function lire(array $document, string $chemin): array
    {
        $valeur = $document;

        foreach (explode('.', $chemin) as $segment) {
            $valeur = is_array($valeur) ? ($valeur[$segment] ?? null) : null;
        }

        return is_array($valeur) ? array_values(array_filter($valeur, 'is_array')) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $source
     * @param  list<array<string, mixed>>  $courant
     */
    private function comparer(string $chemin, array $source, array $courant): void
    {
        $avant = $this->indexer($chemin, $source);
        $apres = $this->indexer($chemin, $courant);

        $compte = [self::ADDED => 0, self::REMOVED => 0, self::CHANGED => 0, self::UNCHANGED => 0];
        $objets = [];

        foreach ($apres as $identite => $objet) {
            if (! array_key_exists($identite, $avant)) {
                $compte[self::ADDED]++;
                $objets[] = $this->ligne($chemin, $identite, $objet, self::ADDED, []);

                continue;
            }

            $champs = $this->champsChanges($avant[$identite], $objet);

            if ($champs === []) {
                $compte[self::UNCHANGED]++;

                continue;
            }

            $compte[self::CHANGED]++;
            $objets[] = $this->ligne($chemin, $identite, $objet, self::CHANGED, $champs);
        }

        foreach ($avant as $identite => $objet) {
            if (! array_key_exists($identite, $apres)) {
                $compte[self::REMOVED]++;
                $objets[] = $this->ligne($chemin, $identite, $objet, self::REMOVED, []);
            }
        }

        $this->familles[$chemin] = $compte + ['objets' => $objets];
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return array<string, array<string, mixed>>
     */
    private function indexer(string $chemin, array $lignes): array
    {
        $index = [];

        foreach ($lignes as $ligne) {
            $identite = $this->identite($chemin, $ligne);

            if ($identite !== null) {
                $index[$identite] = $ligne;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $ligne
     */
    private function identite(string $chemin, array $ligne): ?string
    {
        if (isset(self::IDENTITES_COMPOSEES[$chemin])) {
            $parts = [];

            foreach (self::IDENTITES_COMPOSEES[$chemin] as $composant) {
                $valeur = $ligne[$composant] ?? null;

                if (! is_string($valeur) || $valeur === '') {
                    // Une identite incomplete ne s'invente pas : l'objet sort
                    // de la comparaison plutot que d'etre confondu avec un
                    // autre.
                    return null;
                }

                $parts[] = $valeur;
            }

            return implode('|', $parts);
        }

        $clef = $ligne['key'] ?? null;

        return is_string($clef) && $clef !== '' ? $clef : null;
    }

    /**
     * Les champs qui different, apres canonicalisation.
     *
     * @param  array<string, mixed>  $avant
     * @param  array<string, mixed>  $apres
     * @return list<string>
     */
    private function champsChanges(array $avant, array $apres): array
    {
        $champs = [];

        foreach (array_unique([...array_keys($avant), ...array_keys($apres)]) as $champ) {
            $a = $avant[$champ] ?? null;
            $b = $apres[$champ] ?? null;

            // Le MEME encodage canonique que le digest : clefs triees, donc
            // insensible a l'ordre des proprietes mais sensible a l'ordre
            // d'une liste — ce qui est voulu, `order` et `position` sont des
            // faits du monde.
            if (ManifestCanonicalJson::encode($a) !== ManifestCanonicalJson::encode($b)) {
                $champs[] = (string) $champ;
            }
        }

        sort($champs);

        return $champs;
    }

    /**
     * @param  array<string, mixed>  $objet
     * @param  list<string>  $champs
     * @return array<string, mixed>
     */
    private function ligne(string $chemin, string $identite, array $objet, string $statut, array $champs): array
    {
        return [
            'identite' => $identite,
            'libelle' => $this->libelle($objet) ?? $identite,
            'statut' => $statut,
            'champs' => $champs,
        ];
    }

    /**
     * @param  array<string, mixed>  $objet
     */
    private function libelle(array $objet): ?string
    {
        foreach (self::LIBELLES as $champ) {
            $valeur = $objet[$champ] ?? null;

            if (is_string($valeur) && $valeur !== '') {
                return mb_substr(trim($valeur), 0, 90);
            }
        }

        return null;
    }

    // =====================================================================
    // Lecture
    // =====================================================================

    /**
     * Les familles qui ont REELLEMENT bouge, dans l'ordre du schema.
     *
     * @return array<string, array{added: int, removed: int, changed: int, unchanged: int, objets: list<array<string, mixed>>}>
     */
    public function famillesModifiees(): array
    {
        return array_filter(
            $this->familles,
            static fn (array $f): bool => $f[self::ADDED] > 0 || $f[self::REMOVED] > 0 || $f[self::CHANGED] > 0
        );
    }

    /**
     * @return array<string, array{added: int, removed: int, changed: int, unchanged: int, objets: list<array<string, mixed>>}>
     */
    public function toutesLesFamilles(): array
    {
        return $this->familles;
    }

    /**
     * Rien n'a bouge : c'est le cas ou il ne faut PAS creer de version.
     *
     * Une Capture identique a sa source produirait le meme digest canonique et
     * ne serait meme pas rechargeable — mais surtout, incrementer un numero
     * pour un monde inchange raconterait une histoire qui n'a pas eu lieu.
     */
    public function estVide(): bool
    {
        return $this->famillesModifiees() === [];
    }

    /**
     * @return array{added: int, removed: int, changed: int}
     */
    public function totaux(): array
    {
        $totaux = [self::ADDED => 0, self::REMOVED => 0, self::CHANGED => 0];

        foreach ($this->familles as $famille) {
            foreach (array_keys($totaux) as $statut) {
                $totaux[$statut] += $famille[$statut];
            }
        }

        return $totaux;
    }
}
