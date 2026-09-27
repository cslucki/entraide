<?php

namespace App\Support\ScenarioManager;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Services\Users\UserDeletionExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1650 — ce que la sandbox contient et que le pack n'a pas mis.
 *
 * ## Pourquoi ce controle existe
 *
 * Mesure faite en revue, par des ecrans DEJA en production et sans une ligne
 * de SQL : on charge un scenario, on place un vrai prospect dans la sandbox
 * depuis `/admin/users` — qui acceptait n'importe quelle Organization —,
 * cette personne cree du contenu, puis on retire la sandbox. Son contenu est
 * detruit, et plus personne ne sait qu'il a existe.
 *
 * T1650 ferme desormais cette porte A LA SOURCE : `/admin/users` refuse les
 * deux sens. Ce preflight reste necessaire pour les contaminations
 * HISTORIQUES — celles deja en base, creees avant la garde.
 *
 * ## La regle : la PERSONNE, decouverte dans le schema
 *
 * Premiere ecriture : toute ligne non inscrite au registre etait declaree
 * etrangere. MESURE : elle refusait deja sur une sandbox saine, a cause des
 * documents racines que le pack cree sans les inscrire individuellement. Et
 * le CDC est formel — une sandbox est VIVANTE : apres Load, le monde evolue,
 * avec de nouveaux messages, documents et evenements qui ne sont pas au
 * registre initial. « Absent du registre = etranger » est donc FAUX.
 *
 * Deuxieme ecriture : une liste ECRITE A LA MAIN de cinq noms de colonnes
 * (`user_id`, `author_id`, `created_by`, `owner_id`, `uploaded_by`), dont le
 * docblock affirmait qu'une colonne oubliee « se verrait ». MESURE en revue :
 * le schema porte 38 noms de colonnes distincts qui referencent `users.id`.
 * La liste en couvrait 5 et en manquait 33, dont `sender_id` — l'auteur d'un
 * message ChatLoop, le contenu le plus volumineux du produit. Rien ne le
 * rendait visible.
 *
 * ## Ce qui est fait maintenant
 *
 * Le schema est INTERROGE. Aucune constante de colonnes ne vit dans ce
 * fichier : {@see colonnesDePersonneParTable()} decouvre les cles etrangeres
 * reelles vers `users`, et {@see ScenarioSandboxPreflightTest} compare cette
 * decouverte au schema vivant — une 39e cle ajoutee demain devient donc
 * visible toute seule, sans que personne y pense.
 *
 * ## Ce que ce controle ne sait PAS juger, et le dit
 *
 * Une table ne peut etre rattachee a une sandbox que si elle porte
 * `organization_id`. Sur 92 tables qui referencent `users`, 12 ne le portent
 * pas. Elles sont declarees dans {@see TABLES_HORS_PORTEE} avec leur raison,
 * et la meme garde de test interdit qu'une table disparaisse silencieusement
 * entre les deux ensembles. Verdict MASTER : « fail closed ou escalade ;
 * jamais on suppose que ce n'est pas concerne. » Ici, c'est l'escalade : le
 * fait est ecrit, teste, et remonte.
 *
 * ET L'ESCALADE DOIT DIRE VRAI. Trois de ces douze portent du TEXTE redige
 * par une personne — voir la liste. Les decrire comme des tables techniques
 * aurait vide l'escalade de son sens : elle aurait remonte un fait faux.
 */
class ScenarioSandboxPreflight
{
    /**
     * Les tables qui referencent `users` sans porter `organization_id`.
     *
     * Ce controle ne sait pas leur attribuer une sandbox, et il ne le SUPPOSE
     * pas : il le declare. La garde de test verifie que cette liste
     * correspond EXACTEMENT au schema — ni une table oubliee, ni une table
     * devenue rattachable qu'on continuerait d'ignorer.
     *
     * La liste se construit sur les MIGRATIONS, pas sur une base existante :
     * une base de DEV vivante porte des tables heritees qu'aucune migration
     * ne cree plus. En declarer une ici la ferait passer pour un fait du
     * schema, et le test ci-contre echouerait partout ailleurs.
     *
     * Ce que ces douze sont, exactement :
     *
     * - plateforme : `organizations`, `platform_ai_constitutions` ;
     * - administration du Manager lui-meme : `scenario_manifest_versions` ;
     * - demandes inter-tenants : `organization_requests` ;
     * - preferences par personne : `member_notification_preferences` ;
     * - pivots rattaches par leur parent : `blog_post_user`,
     *   `loop_roadmap_item_user`, `loop_plugin_ai_models`, `usage_references` ;
     * - **du CONTENU REDIGE, et c'est le point faible connu de ce controle** :
     *   `blog_annotation_replies` (`content`), `blog_todo_threads` (`body`) et
     *   `blog_snapshots` (`content`, `comment`). Ce sont des textes ecrits par
     *   une personne, rattaches en cascade a un `blog_post`. Une personne
     *   reelle qui repond SOUS l'annotation d'un persona ne laisse aucune
     *   trace dans une table en portee : ce controle declare alors la sandbox
     *   saine, et le geste destructeur emporte son texte.
     *
     * Ce dernier point est un TROU CONNU, pas une omission. Il est nomme ici,
     * il appartient a la dette « Scenario sandbox destructive preflight —
     * RESTRICT/cascade analysis », et la garde a la source — `/admin/users`
     * refuse les deux sens — est ce qui empeche aujourd'hui une personne
     * reelle d'en produire.
     *
     * @var list<string>
     */
    public const TABLES_HORS_PORTEE = [
        'blog_annotation_replies',
        'blog_post_user',
        'blog_snapshots',
        'blog_todo_threads',
        'loop_plugin_ai_models',
        'loop_roadmap_item_user',
        'member_notification_preferences',
        'organization_requests',
        'organizations',
        'platform_ai_constitutions',
        'scenario_manifest_versions',
        'usage_references',
    ];

    /**
     * Memoire de la decouverte, par processus.
     *
     * L'introspection lit 145 tables : la refaire a chaque table de chaque
     * geste couterait plus cher que le controle lui-meme.
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $decouverte = null;

    /**
     * Les colonnes qui DESIGNENT une personne, table par table, telles que le
     * schema les porte aujourd'hui.
     *
     * Seules les tables rattachables a une sandbox — celles qui portent
     * `organization_id` — sont rendues : les autres sont declarees hors
     * portee, et la garde de test le verifie.
     *
     * @return array<string, list<string>>
     */
    public static function colonnesDePersonneParTable(): array
    {
        if (self::$decouverte !== null) {
            return self::$decouverte;
        }

        $tableDesComptes = (new User)->getTable();
        $parTable = [];

        foreach (Schema::getTables() as $table) {
            $nom = $table['name'];

            if (in_array($nom, self::TABLES_HORS_PORTEE, true)) {
                continue;
            }

            $colonnes = [];

            foreach (Schema::getForeignKeys($nom) as $cle) {
                if (($cle['foreign_table'] ?? null) !== $tableDesComptes) {
                    continue;
                }

                foreach ($cle['columns'] as $colonne) {
                    $colonnes[] = $colonne;
                }
            }

            if ($colonnes === [] || ! Schema::hasColumn($nom, 'organization_id')) {
                continue;
            }

            $colonnes = array_values(array_unique($colonnes));
            sort($colonnes);

            $parTable[$nom] = $colonnes;
        }

        ksort($parTable);

        return self::$decouverte = $parTable;
    }

    /**
     * A n'appeler que depuis les tests : le schema ne bouge pas en production,
     * il bouge entre deux migrations d'une suite.
     */
    public static function oublierLaDecouverte(): void
    {
        self::$decouverte = null;
    }

    /**
     * Les cles etrangeres vers `users` qui INTERDISENT la suppression.
     *
     * Decouvertes, elles aussi : la regle `ON DELETE` est portee par le
     * schema, et une contrainte ajoutee demain apparaitra ici sans que
     * personne y pense.
     *
     * ## Cette decouverte n'est PAS cablee en refus, et voici pourquoi
     *
     * MASTER demandait un precheck : « ne transforme pas simplement les
     * exceptions FK en jolies phrases SI TU PEUX les identifier avant la
     * destruction. » MESURE : je ne le peux pas a ce prix. Un inventaire naif
     * — « une ligne RESTRICT qui designe un persona et que le registre
     * n'inscrit pas » — refuse sur une sandbox PARFAITEMENT SAINE : le pack
     * cree lui-meme des votes de sondage qu'il n'inscrit pas un par un, et
     * qui disparaissent en CASCADE avec leur sondage AVANT que le persona ne
     * soit purge. Deux lignes suffisaient a bloquer le geste.
     *
     * Distinguer les deux exige de suivre les cascades depuis les entites
     * inscrites — le moteur de graphe que MASTER a explicitement exclu de
     * cette nuit. La decouverte reste donc ici, mesuree et testee, comme base
     * de l'arbitrage ; le `catch (QueryException)` du service tient le role de
     * ceinture en attendant.
     *
     * Sont ecartees les tables que le purger sait deja resoudre — il detache
     * `organizations.admin_id` et detruit les biens transferables avant le
     * persona. Les compter ici produirait un refus sur une sandbox que le
     * moteur aurait parfaitement su vider.
     *
     * @return array<string, list<string>>
     */
    public static function colonnesQuiBloquentLaSuppression(): array
    {
        $tableDesComptes = (new User)->getTable();

        $resoluesParLePurger = array_merge(
            ['organizations'],
            array_column(UserDeletionExecutor::TRANSFERABLE, 'table')
        );

        $parTable = [];

        foreach (Schema::getTables() as $table) {
            $nom = $table['name'];

            if (in_array($nom, $resoluesParLePurger, true)) {
                continue;
            }

            foreach (Schema::getForeignKeys($nom) as $cle) {
                if (($cle['foreign_table'] ?? null) !== $tableDesComptes) {
                    continue;
                }

                if (strtolower((string) ($cle['on_delete'] ?? '')) !== 'restrict') {
                    continue;
                }

                foreach ($cle['columns'] as $colonne) {
                    $parTable[$nom][] = $colonne;
                }
            }
        }

        foreach ($parTable as $nom => $colonnes) {
            $parTable[$nom] = array_values(array_unique($colonnes));
            sort($parTable[$nom]);
        }

        ksort($parTable);

        return $parTable;
    }

    /**
     * Ce que la sandbox contient et que ce chargement n'a pas inscrit.
     *
     * @return array<string, int> table => nombre de lignes etrangeres
     */
    public function inventaireEtranger(ScenarioPackLoad $load, Organization $organization): array
    {
        $revendique = $this->revendiqueParTable($load);
        $tableDesComptes = (new User)->getTable();

        $comptesDuPack = $revendique[$tableDesComptes] ?? [];

        $comptesPresents = DB::table($tableDesComptes)
            ->where('organization_id', $organization->getKey())
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $comptesEtrangers = array_values(array_diff($comptesPresents, $comptesDuPack));

        $etranger = [];

        if ($comptesEtrangers !== []) {
            $etranger[$tableDesComptes] = count($comptesEtrangers);
        }

        // Ce qu'une personne etrangere a produit — et qui lui SURVIT quand
        // elle repart.
        //
        // Ce controle ne regarde donc PAS les comptes presents : il regarde
        // les lignes qui designent une personne que ce chargement n'a pas
        // inscrite, ou qu'elle soit aujourd'hui. Une version anterieure
        // partait des comptes encore presents, et le contenu cessait d'etre
        // protege a la seconde ou la personne changeait d'Organization.
        //
        // Le parcours n'est plus borne aux tables REVENDIQUEES : une personne
        // reelle ecrit dans le produit entier, pas dans le sous-ensemble que
        // le manifeste sait fabriquer.
        foreach (self::colonnesDePersonneParTable() as $table => $colonnes) {
            if ($table === $tableDesComptes) {
                continue;
            }

            foreach ($colonnes as $colonne) {
                $nombre = DB::table($table)
                    ->where('organization_id', $organization->getKey())
                    ->whereNotNull($colonne)
                    ->when($comptesDuPack !== [], static fn ($requete) => $requete->whereNotIn($colonne, $comptesDuPack))
                    ->count();

                if ($nombre > 0) {
                    $etranger[$table] = ($etranger[$table] ?? 0) + $nombre;
                }
            }
        }

        ksort($etranger);

        return $etranger;
    }

    /**
     * Refuse si la sandbox contient quoi que ce soit d'etranger.
     *
     * Appele AVANT toute mutation, par Reset comme par Remove. Le refus porte
     * l'inventaire : dire « il y a du contenu etranger » sans dire lequel
     * laisserait l'operateur sans moyen d'agir.
     */
    public function refuserSiContenuEtranger(ScenarioPackLoad $load, Organization $organization): void
    {
        $etranger = $this->inventaireEtranger($load, $organization);

        if ($etranger !== []) {
            throw ScenarioVersionRefused::foreignContent($etranger);
        }
    }

    /**
     * Les identifiants que ce chargement revendique, par table.
     *
     * Les lignes a propriete INCONNUE — inscrites avant que le registre ne
     * note l'ownership — sont comptees comme revendiquees : le moteur les
     * traite deja par son propre refus, et les declarer etrangeres ici
     * donnerait deux messages differents pour un seul fait.
     *
     * @return array<string, list<string>>
     */
    private function revendiqueParTable(ScenarioPackLoad $load): array
    {
        $parTable = [];

        ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $load->getKey())
            ->get(['entity_model', 'entity_id'])
            ->each(function (ScenarioPackEntity $entite) use (&$parTable): void {
                $modele = $entite->entity_model;

                if (! is_string($modele) || ! class_exists($modele)) {
                    return;
                }

                $table = (new $modele)->getTable();
                $parTable[$table][] = (string) $entite->entity_id;
            });

        return $parTable;
    }
}
