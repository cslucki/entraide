<?php

namespace App\Support\ScenarioManager;

use App\Models\ScenarioManifestVersion;
use App\Support\ScenarioManifest\ManifestErrorCode;

/**
 * TASK-1656 §20 et §21 — « a completer » n'est pas « invalide ».
 *
 * ## Le defaut produit que cette classe corrige
 *
 * Un scenario neuf est VIDE par construction : le squelette derive sa structure
 * du schema et rend chaque collection vide. Le Validator le refuse donc — a
 * juste titre — avec quatre `MISSING_FIELD` : `/users`, `/loops`,
 * `/memberships`, `/dossiers`. Mais l'ecran affichait ces quatre refus comme
 * quatre PANNES : gros blocs rouges, codes techniques, et aucune indication de
 * ce qu'il fallait faire. L'utilisateur voyait un scenario casse la ou il
 * venait simplement de commencer.
 *
 * Le Validator reste strict. C'est l'INTERFACE qui distingue deux situations
 * que le verdict `INVALID` confond :
 *
 * - **a completer** : il ne manque que des elements fondateurs. Le scenario est
 *   volontairement incomplet, et la suite est une checklist ;
 * - **invalide** : une donnee DEJA renseignee est incoherente. La, il y a
 *   quelque chose a corriger, pas seulement a ajouter.
 *
 * ## Ce que cette classe ne fait PAS
 *
 * Elle ne valide rien et ne remplace aucun Validator. Elle CLASSE des erreurs
 * deja produites et deja stockees dans `validation_summary`. Aucune regle de
 * validation n'est redeclaree ici — la liste ci-dessous ne dit pas « ces
 * quatre familles sont obligatoires », elle dit « quand l'unique reproche porte
 * sur ces quatre familles-la, c'est un debut, pas une panne ».
 *
 * La checklist est donc DERIVEE des erreurs reelles. Une checklist metier
 * ecrite a la main aurait divergé du Validator au premier changement de regle,
 * et l'ecran aurait demande d'ajouter ce qui n'etait plus exige — ou tu.
 */
final class ScenarioDraftReadiness
{
    /**
     * Les familles FONDATRICES, dans l'ordre ou on les construit.
     *
     * L'ordre n'est pas alphabetique : on cree des personnes, puis des Boucles,
     * puis on y met les personnes, puis on ouvre des Dossiers. Une checklist
     * qui ne suit pas l'ordre du geste fait sauter d'un point a l'autre.
     *
     * @var array<string, string>  chemin JSON -> suffixe de clef de langue
     */
    public const FONDATRICES = [
        '/users' => 'users',
        '/loops' => 'loops',
        '/memberships' => 'memberships',
        '/dossiers' => 'dossiers',
    ];

    public const ETAT_PRET = 'pret';

    public const ETAT_A_COMPLETER = 'a_completer';

    public const ETAT_INVALIDE = 'invalide';

    /** @param list<array{code?: string, path?: string, message?: string}> $erreurs */
    private function __construct(
        public readonly string $etat,
        public readonly array $manquantes,
        public readonly array $erreurs,
    ) {}

    /**
     * Classer une version d'apres sa DERNIERE validation enregistree.
     *
     * Une version jamais validee n'a rien a dire : elle est traitee comme « a
     * completer » sans checklist, parce qu'affirmer qu'il manque quelque chose
     * demanderait de valider — et le Validator ne tourne jamais au rendu.
     */
    public static function pour(ScenarioManifestVersion $version): self
    {
        if ($version->isValid()) {
            return new self(self::ETAT_PRET, [], []);
        }

        $resume = is_array($version->validation_summary) ? $version->validation_summary : [];
        $erreurs = is_array($resume['errors'] ?? null) ? $resume['errors'] : [];

        if ($erreurs === []) {
            // Jamais validee, ou validee sans erreur mais pas encore approuvee.
            return new self(self::ETAT_A_COMPLETER, [], []);
        }

        $reperees = [];
        $autres = 0;

        foreach ($erreurs as $erreur) {
            $code = (string) ($erreur['code'] ?? '');
            $chemin = (string) ($erreur['path'] ?? '');

            if ($code === ManifestErrorCode::MISSING_FIELD->value && isset(self::FONDATRICES[$chemin])) {
                $reperees[self::FONDATRICES[$chemin]] = true;

                continue;
            }

            $autres++;
        }

        // L'ordre est celui de FONDATRICES, PAS celui ou le Validator a
        // rapporte ses erreurs — il les rend par ordre alphabetique de chemin,
        // ce qui donnerait « dossiers, loops, memberships, users » : l'inverse
        // du geste, puisqu'on ouvre un Dossier en dernier. Dependre de l'ordre
        // d'un autre composant pour afficher une suite d'etapes, c'est laisser
        // un detail d'implementation decider de la lecture.
        $manquantes = array_values(array_filter(
            array_values(self::FONDATRICES),
            static fn (string $famille): bool => isset($reperees[$famille])
        ));

        // UN SEUL reproche hors fondations suffit a faire basculer en
        // « invalide » : une incoherence reelle ne doit pas se cacher derriere
        // une checklist rassurante.
        return new self(
            $autres > 0 ? self::ETAT_INVALIDE : self::ETAT_A_COMPLETER,
            $manquantes,
            $erreurs,
        );
    }

    public function estACompleter(): bool
    {
        return $this->etat === self::ETAT_A_COMPLETER;
    }

    public function estInvalide(): bool
    {
        return $this->etat === self::ETAT_INVALIDE;
    }

    /**
     * La checklist affichable : chaque famille fondatrice, et si elle est deja
     * presente.
     *
     * Rendue pour TOUTES les familles, pas seulement les manquantes : voir ce
     * qui est deja fait est ce qui dit ou l'on en est.
     *
     * @return list<array{cle: string, present: bool}>
     */
    public function checklist(): array
    {
        $liste = [];

        foreach (self::FONDATRICES as $suffixe) {
            $liste[] = [
                'cle' => $suffixe,
                'present' => ! in_array($suffixe, $this->manquantes, true),
            ];
        }

        return $liste;
    }
}
