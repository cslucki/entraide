<?php

namespace App\Support\ScenarioManager;

/**
 * TASK-1651 — les mutations BORNEES du document, sans jamais quitter
 * `json_source`.
 *
 * ## Une seule source, et pas de second modele
 *
 * Cette classe ne persiste RIEN et ne connait aucune table. Elle prend le
 * document decode, le modifie, et le rend. C'est l'appelant qui le repasse par
 * {@see ScenarioVersionWriter}, donc par la porte d'ecriture unique et par le
 * Validator complet.
 *
 * Il n'existe donc ni table `scenario_people`, ni table `scenario_loops`, ni
 * JSON secondaire, ni cache metier faisant autorite. Le mode visuel et le mode
 * JSON editent le MEME document : c'est l'exigence structurante de la TASK, et
 * tout le reste en decoule.
 *
 * ## Creer une Boucle est ATOMIQUE
 *
 * Le scope visuel de T1651 s'arrete a quatre familles — General, Personnes,
 * Boucles, Membres — mais le Manifest, lui, refuse une Boucle seule. Mesure
 * faite dans les invariants, pas supposee :
 *
 * - `loopFields()` declare `root_dossier` en `ref('dossiers')` NON nullable ;
 * - `validateLoops()` refuse une Boucle qui ne declare aucun membership de
 *   role `owner`, et exige que ce soit le MEME utilisateur que `loops[].owner` ;
 * - un Dossier racine a `parent` a null, `visibility` a `loop`, et il LUI FAUT
 *   un `root_document` — « A root dossier requires a root document. » Le champ
 *   est pourtant `nullable` dans le schema de FORME : c'est l'invariant qui le
 *   rend obligatoire ici, et je l'avais d'abord rapporte comme facultatif ;
 * - le proprietaire du Dossier et l'auteur du document doivent etre MEMBRES de
 *   la Boucle, sinon `OWNER_MEMBERSHIP_MISMATCH`.
 *
 * Creer une Boucle ecrit donc cinq choses d'un coup, et RIEN d'autre : aucun
 * article, aucun fichier, aucun Dossier supplementaire, aucun message, aucune
 * activite simulee. Le persona choisi tient les trois roles a la fois —
 * proprietaire de la Boucle, proprietaire du Dossier, auteur du document —
 * ce qui satisfait les trois invariants sans inventer personne.
 *
 * ## On ne repare jamais un graphe en silence
 *
 * Les suppressions refusent des qu'une reference subsiste, et elles nomment ce
 * qui bloque. Les references ne sont pas cherchees dans une liste de
 * collections ecrite a la main : le document est PARCOURU. T1650 a coute cher
 * sur exactement ce point — une liste en dur se perime en silence, et une
 * collection ajoutee demain au Manifest serait ignoree sans que personne le
 * voie.
 */
final class ScenarioVisualEditor
{
    /**
     * Les champs que l'ecran edite, par famille.
     *
     * Volontairement explicites : le document porte d'autres clefs — dont des
     * constantes de schema et l'identite du document — qu'aucun formulaire ne
     * doit pouvoir ecrire.
     */
    private const CHAMPS_PERSONNE = [
        'first_name', 'name', 'email', 'bio', 'available', 'location', 'avatar', 'organization_role',
    ];

    private const CHAMPS_BOUCLE = [
        'name', 'description', 'type', 'owner', 'visibility', 'access_mode',
    ];

    /** @var array<string, mixed> */
    private array $document;

    /**
     * @param  array<string, mixed>  $document
     */
    private function __construct(array $document)
    {
        $this->document = $document;
    }

    /**
     * Le document courant d'une version.
     *
     * Un document illisible n'est PAS reconstruit a partir de rien : on refuse.
     * Le mode visuel se desactive, le texte est conserve, et seul le mode JSON
     * peut le reparer.
     */
    public static function pour(string $json): self
    {
        $document = json_decode($json, true);

        if (! is_array($document)) {
            throw ScenarioVersionRefused::unparsableSource();
        }

        return new self($document);
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return $this->document;
    }

    public function json(): string
    {
        return json_encode(
            $this->document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    // =====================================================================
    // General
    // =====================================================================

    /**
     * Les champs d'enveloppe que l'ecran expose.
     *
     * `schema_version`, `assets` et `id` n'en font pas partie : les deux
     * premiers sont des constantes que le Validator impose, et le troisieme
     * est l'identite du document, generee une fois.
     *
     * `organization.locale` suit `locale` sans discussion : les faire diverger
     * est refuse par `ManifestCoreInvariants`, donc l'ecran n'offre qu'une
     * seule langue et l'ecrit aux deux endroits.
     *
     * @param  array<string, mixed>  $valeurs
     */
    public function majGeneral(array $valeurs): void
    {
        foreach (['name', 'version', 'description', 'purpose', 'locale'] as $champ) {
            if (array_key_exists($champ, $valeurs)) {
                $this->document[$champ] = $valeurs[$champ];
            }
        }

        foreach (['name', 'proposed_slug', 'description'] as $champ) {
            if (array_key_exists('organization_'.$champ, $valeurs)) {
                $this->document['organization'][$champ] = $valeurs['organization_'.$champ];
            }
        }

        if (array_key_exists('locale', $valeurs)) {
            $this->document['organization']['locale'] = $valeurs['locale'];
        }
    }

    // =====================================================================
    // Personnes
    // =====================================================================

    /**
     * @param  array<string, mixed>  $valeurs
     * @return string la stable key attribuee
     */
    public function ajouterPersonne(array $valeurs): string
    {
        $cle = $this->cleLibre('users', (string) ($valeurs['first_name'] ?? 'persona'));

        $personne = ['key' => $cle];

        foreach (self::CHAMPS_PERSONNE as $champ) {
            $personne[$champ] = $valeurs[$champ] ?? null;
        }

        $personne['available'] = (bool) ($valeurs['available'] ?? false);

        // Le profil IA n'est pas edite par l'ecran (T1651) : on le pose a null,
        // ce que le schema admet, plutot que d'inventer un profil vide qui
        // passerait pour une intention.
        $personne['member_ai_profile'] = null;

        $this->document['users'][] = $personne;

        return $cle;
    }

    /**
     * @param  array<string, mixed>  $valeurs
     */
    public function modifierPersonne(string $cle, array $valeurs): void
    {
        $index = $this->indexDe('users', $cle);

        foreach (self::CHAMPS_PERSONNE as $champ) {
            if (array_key_exists($champ, $valeurs)) {
                $this->document['users'][$index][$champ] = $valeurs[$champ];
            }
        }

        if (array_key_exists('available', $valeurs)) {
            $this->document['users'][$index]['available'] = (bool) $valeurs['available'];
        }
    }

    public function supprimerPersonne(string $cle): void
    {
        $this->refuserSiReference('users', $cle, exclure: []);

        $index = $this->indexDe('users', $cle);
        array_splice($this->document['users'], $index, 1);
    }

    // =====================================================================
    // Boucles — la mutation atomique
    // =====================================================================

    /**
     * Cree la Boucle ET la structure minimale que le Manifest exige d'elle.
     *
     * Cinq ecritures, dans le meme geste, et pas une de plus. Voir le docblock
     * de classe pour les invariants qui les imposent, tous mesures.
     *
     * @param  array<string, mixed>  $valeurs
     * @return string la stable key de la Boucle
     */
    public function ajouterBoucle(array $valeurs): string
    {
        $proprietaire = (string) ($valeurs['owner'] ?? '');

        // Sans persona, pas de Boucle : `owner` est obligatoire, et l'auteur du
        // document racine doit etre quelqu'un. On REFUSE plutot que de creer
        // un persona au passage — ce serait fabriquer une personne que le
        // SuperAdmin n'a pas voulue.
        if (($this->document['users'] ?? []) === []) {
            throw ScenarioVersionRefused::noPersona();
        }

        if ($proprietaire === '' || $this->chercher('users', $proprietaire) === null) {
            throw ScenarioVersionRefused::noPersona();
        }

        $nom = (string) ($valeurs['name'] ?? 'boucle');
        $cleBoucle = $this->cleLibre('loops', $nom);
        $cleDossier = $this->cleLibre('dossiers', $nom.'-documents');

        $boucle = ['key' => $cleBoucle];

        foreach (self::CHAMPS_BOUCLE as $champ) {
            $boucle[$champ] = $valeurs[$champ] ?? null;
        }

        $boucle['root_dossier'] = $cleDossier;

        $this->document['loops'][] = $boucle;

        // 2. le membership owner — `validateLoops()` exige qu'il existe ET
        //    qu'il designe le MEME utilisateur que `loops[].owner`.
        $this->document['memberships'][] = [
            'loop' => $cleBoucle,
            'user' => $proprietaire,
            'role' => 'owner',
        ];

        // 3 et 4. le Dossier racine et son document. `parent` a null et
        //         `visibility` a `loop` ne sont pas des choix : les invariants
        //         les imposent pour une racine.
        $this->document['dossiers'][] = [
            'key' => $cleDossier,
            'name' => self::NOM_DOSSIER_RACINE,
            'owner' => $proprietaire,
            'loop' => $cleBoucle,
            'parent' => null,
            'visibility' => 'loop',
            'root_document' => [
                'title' => self::NOM_DOSSIER_RACINE,
                'author' => $proprietaire,
                'format' => 'markdown',
                'content' => self::CONTENU_DOCUMENT_RACINE,
            ],
        ];

        return $cleBoucle;
    }

    /**
     * Le nom NEUTRE de l'espace documents d'une Boucle.
     *
     * Neutre au sens propre : il ne raconte rien, il ne simule aucune
     * activite. Le CDC interdit d'inventer du contenu ; un titre et une phrase
     * sont le strict minimum que le schema exige (`content` a au moins un
     * caractere).
     */
    private const NOM_DOSSIER_RACINE = 'Espace documents';

    private const CONTENU_DOCUMENT_RACINE = "Espace documents de la Boucle.\n";

    /**
     * @param  array<string, mixed>  $valeurs
     */
    public function modifierBoucle(string $cle, array $valeurs): void
    {
        $index = $this->indexDe('loops', $cle);
        $ancienProprietaire = (string) ($this->document['loops'][$index]['owner'] ?? '');

        foreach (self::CHAMPS_BOUCLE as $champ) {
            if (array_key_exists($champ, $valeurs)) {
                $this->document['loops'][$index][$champ] = $valeurs[$champ];
            }
        }

        $nouveauProprietaire = (string) ($this->document['loops'][$index]['owner'] ?? '');

        if ($nouveauProprietaire !== $ancienProprietaire && $nouveauProprietaire !== '') {
            // `loops[].owner` et le membership `owner` decrivent le MEME fait.
            // Les laisser diverger produirait un document que le Validator
            // refuse, avec une erreur que personne ne relierait a ce geste.
            //
            // L'ancien proprietaire est RETROGRADE, pas retire : il peut
            // posseder un Dossier ou avoir ecrit dans cette Boucle, et les
            // invariants exigent qu'un proprietaire de Dossier ou un auteur
            // en soit MEMBRE. Le sortir d'office casserait le document a sa
            // place.
            $this->retrograderLeProprietaireSortant($cle, $nouveauProprietaire);

            $this->definirRole($cle, $nouveauProprietaire, 'owner');
            $this->reconcilierProprietaireDeLaRacine($cle, $nouveauProprietaire);
        }
    }

    /**
     * Supprime la Boucle et SA STRUCTURE VIDE, jamais davantage.
     *
     * Partent avec elle : ses memberships et son Dossier racine — c'est la
     * structure que la creation avait posee d'un bloc. Tout le reste fait
     * REFUSER : un sous-Dossier, un article, un fichier, un message, un
     * evenement, ou n'importe quel objet d'une collection hors scope T1651.
     */
    public function supprimerBoucle(string $cle): void
    {
        $index = $this->indexDe('loops', $cle);
        $cleDossier = (string) ($this->document['loops'][$index]['root_dossier'] ?? '');

        // Ce qui accompagne la Boucle est exclu du calcul de references : on
        // ne va pas se refuser a soi-meme la suppression de ce qu'on emporte.
        $accompagne = [
            'memberships' => fn (array $ligne): bool => ($ligne['loop'] ?? null) === $cle,
            'dossiers' => fn (array $ligne): bool => ($ligne['key'] ?? null) === $cleDossier,
            // La Boucle elle-meme designe son Dossier racine. Elle part avec
            // lui : sans cette exclusion, elle se refuserait a elle-meme la
            // suppression de ce qu'elle emporte.
            'loops' => fn (array $ligne): bool => ($ligne['key'] ?? null) === $cle,
        ];

        $this->refuserSiReference('loops', $cle, $accompagne);

        if ($cleDossier !== '') {
            $this->refuserSiReference('dossiers', $cleDossier, $accompagne);
        }

        array_splice($this->document['loops'], $index, 1);

        $this->document['memberships'] = array_values(array_filter(
            $this->document['memberships'] ?? [],
            static fn (array $ligne): bool => ($ligne['loop'] ?? null) !== $cle
        ));

        if ($cleDossier !== '') {
            $this->document['dossiers'] = array_values(array_filter(
                $this->document['dossiers'] ?? [],
                static fn (array $ligne): bool => ($ligne['key'] ?? null) !== $cleDossier
            ));
        }
    }

    // =====================================================================
    // Membres
    // =====================================================================

    /**
     * Pose, change ou retire le role d'une personne dans une Boucle.
     *
     * `$role` a null retire le membership. Retirer celui du proprietaire est
     * refuse par le Validator, pas ici : l'ecran doit l'empecher, mais ce
     * n'est pas a cette classe d'inventer une seconde regle.
     */
    public function definirRole(string $boucle, string $personne, ?string $role): void
    {
        $memberships = $this->document['memberships'] ?? [];

        foreach ($memberships as $index => $ligne) {
            if (($ligne['loop'] ?? null) !== $boucle || ($ligne['user'] ?? null) !== $personne) {
                continue;
            }

            if ($role === null) {
                array_splice($memberships, $index, 1);
                $this->document['memberships'] = array_values($memberships);

                return;
            }

            $this->document['memberships'][$index]['role'] = $role;

            return;
        }

        if ($role !== null) {
            $this->document['memberships'][] = ['loop' => $boucle, 'user' => $personne, 'role' => $role];
        }
    }

    // =====================================================================
    // Outillage
    // =====================================================================

    /**
     * Rend simple membre celui qui detenait le role `owner` de cette Boucle.
     *
     * `validateLoops()` exige UN membership owner, et qu'il designe le meme
     * utilisateur que `loops[].owner`. Deux owners residuels feraient donc
     * echouer la validation — mesure faite : c'est exactement ce que la
     * premiere version de ce transfert produisait.
     */
    private function retrograderLeProprietaireSortant(string $boucle, string $nouveauProprietaire): void
    {
        foreach ($this->document['memberships'] ?? [] as $index => $ligne) {
            if (($ligne['loop'] ?? null) !== $boucle || ($ligne['role'] ?? null) !== 'owner') {
                continue;
            }

            if (($ligne['user'] ?? null) === $nouveauProprietaire) {
                continue;
            }

            $this->document['memberships'][$index]['role'] = 'member';
        }
    }

    /**
     * Le proprietaire du Dossier racine et l'auteur de son document suivent
     * le proprietaire de la Boucle.
     *
     * Les deux doivent etre MEMBRES de la Boucle ; le proprietaire l'est par
     * construction. Laisser l'ancien proprietaire en place apres un transfert
     * produirait un `OWNER_MEMBERSHIP_MISMATCH` des qu'il quitte la Boucle.
     */
    private function reconcilierProprietaireDeLaRacine(string $boucle, string $proprietaire): void
    {
        foreach ($this->document['dossiers'] ?? [] as $index => $dossier) {
            if (($dossier['loop'] ?? null) !== $boucle || ($dossier['parent'] ?? null) !== null) {
                continue;
            }

            $this->document['dossiers'][$index]['owner'] = $proprietaire;

            if (isset($this->document['dossiers'][$index]['root_document'])) {
                $this->document['dossiers'][$index]['root_document']['author'] = $proprietaire;
            }
        }
    }

    /**
     * Refuse si quoi que ce soit, dans TOUT le document, designe encore cette
     * clef.
     *
     * Le document est PARCOURU, collection par collection, a toute profondeur.
     * Aucune liste de champs referents n'est ecrite ici : T1650 a montre
     * qu'une telle liste se perime en silence, et qu'une collection ajoutee
     * plus tard au Manifest serait ignoree sans que rien ne le signale.
     *
     * @param  array<string, \Closure(array<string, mixed>): bool>  $exclure
     *                                                                        lignes qui PARTENT avec l'objet supprime, par collection
     */
    private function refuserSiReference(string $collection, string $cle, array $exclure): void
    {
        $bloquantes = [];

        foreach ($this->document as $nom => $contenu) {
            if (! is_array($contenu) || ! array_is_list($contenu)) {
                continue;
            }

            foreach ($contenu as $ligne) {
                if (! is_array($ligne)) {
                    continue;
                }

                // L'objet lui-meme n'est pas sa propre reference.
                if ($nom === $collection && ($ligne['key'] ?? null) === $cle) {
                    continue;
                }

                if (isset($exclure[$nom]) && $exclure[$nom]($ligne)) {
                    continue;
                }

                if ($this->designe($ligne, $cle)) {
                    $bloquantes[$nom] = ($bloquantes[$nom] ?? 0) + 1;
                }
            }
        }

        if ($bloquantes !== []) {
            ksort($bloquantes);

            throw ScenarioVersionRefused::stillReferenced($cle, $bloquantes);
        }
    }

    /**
     * Cette valeur designe-t-elle la clef, a n'importe quelle profondeur ?
     *
     * On compare des CHAINES a une stable key. Une reference Manifest est
     * toujours une stable key en clair — jamais un UUID, jamais un index.
     */
    private function designe(mixed $valeur, string $cle): bool
    {
        if (is_string($valeur)) {
            return $valeur === $cle;
        }

        if (! is_array($valeur)) {
            return false;
        }

        foreach ($valeur as $champ => $enfant) {
            // `key` est l'identite de la ligne, pas une reference vers autrui.
            if ($champ === 'key') {
                continue;
            }

            if ($this->designe($enfant, $cle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une stable key libre, derivee du nom saisi.
     *
     * Conforme au contrat existant : `^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$`, 64
     * caracteres au plus. Jamais un UUID — une clef Manifest se lit, se cite
     * dans les erreurs de validation, et voyage entre deux versions du
     * document.
     */
    private function cleLibre(string $collection, string $source): string
    {
        $base = self::slug($source);
        $cle = $base;
        $suffixe = 2;

        while ($this->chercher($collection, $cle) !== null) {
            $cle = mb_substr($base, 0, 60).'-'.$suffixe;
            $suffixe++;
        }

        return $cle;
    }

    public static function slug(string $source): string
    {
        $slug = mb_strtolower($source, 'UTF-8');
        $slug = (string) preg_replace('/[^a-z0-9]+/u', '-', self::sansAccents($slug));
        $slug = trim($slug, '-');

        // La clef DOIT commencer par une lettre : un nom entierement numerique
        // — « 2026 » — produirait sinon une clef invalide.
        if ($slug === '' || ! preg_match('/^[a-z]/', $slug)) {
            $slug = 'k'.($slug === '' ? '' : '-'.$slug);
        }

        return mb_substr($slug, 0, 64);
    }

    private static function sansAccents(string $valeur): string
    {
        $translitere = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valeur);

        return is_string($translitere) ? $translitere : $valeur;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function chercher(string $collection, string $cle): ?array
    {
        foreach ($this->document[$collection] ?? [] as $ligne) {
            if (is_array($ligne) && ($ligne['key'] ?? null) === $cle) {
                return $ligne;
            }
        }

        return null;
    }

    private function indexDe(string $collection, string $cle): int
    {
        foreach ($this->document[$collection] ?? [] as $index => $ligne) {
            if (is_array($ligne) && ($ligne['key'] ?? null) === $cle) {
                return (int) $index;
            }
        }

        throw ScenarioVersionRefused::unknownKey($cle);
    }
}
