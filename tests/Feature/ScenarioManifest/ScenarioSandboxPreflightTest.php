<?php

namespace Tests\Feature\ScenarioManifest;

use App\Support\ScenarioManager\ScenarioSandboxPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1650 — la garde qui empeche la liste de se perimer EN SILENCE.
 *
 * ## Pourquoi elle existe
 *
 * Le preflight a d'abord juge la personne sur CINQ noms de colonnes ecrits a
 * la main, avec un docblock affirmant qu'une colonne oubliee « se verrait ».
 * Elle ne se voyait pas : le schema en porte 38, et les 33 manquantes
 * incluaient `sender_id` — l'auteur d'un message ChatLoop. Le defaut n'a ete
 * trouve ni par les 27 tests, ni par moi : il a fallu une relecture adverse.
 *
 * Demande MASTER : « compare la decouverte dynamique du schema a ce que le
 * preflight parcourt reellement. Ainsi, une 39e FK ajoutee plus tard doit
 * devenir visible automatiquement. »
 *
 * Ce fichier est donc une garde d'ARCHITECTURE, pas un test de comportement :
 * il echoue quand le schema change et que personne ne s'est pose la question.
 */
class ScenarioSandboxPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // La decouverte est memorisee par processus ; le schema, lui, nait
        // avec chaque base de test.
        ScenarioSandboxPreflight::oublierLaDecouverte();
    }

    public function test_toute_table_qui_reference_users_est_soit_parcourue_soit_declaree_hors_portee(): void
    {
        $parcourues = array_keys(ScenarioSandboxPreflight::colonnesDePersonneParTable());
        $declarees = ScenarioSandboxPreflight::TABLES_HORS_PORTEE;

        $oubliees = [];

        foreach ($this->tablesQuiReferencentUnCompte() as $table => $colonnes) {
            if (in_array($table, $parcourues, true) || in_array($table, $declarees, true)) {
                continue;
            }

            $oubliees[$table] = $colonnes;
        }

        $this->assertSame([], $oubliees, sprintf(
            "Ces tables referencent users et ne sont NI parcourues NI declarees hors portee : %s.\n".
            'Une cle etrangere a ete ajoutee sans decider ce que le preflight en fait. '.
            'Soit la table porte organization_id et sera parcourue toute seule, '.
            'soit il faut l inscrire dans TABLES_HORS_PORTEE avec sa raison.',
            implode(', ', array_keys($oubliees))
        ));
    }

    public function test_aucune_table_declaree_hors_portee_n_est_devenue_rattachable(): void
    {
        // L'autre sens, et c'est lui qui pourrit avec le temps : une table
        // sans `organization_id` peut en gagner un a la faveur d'une
        // migration. Sans ce test, elle resterait ignoree POUR TOUJOURS, et
        // la declaration deviendrait un mensonge tranquille.
        $devenuesRattachables = [];

        foreach (ScenarioSandboxPreflight::TABLES_HORS_PORTEE as $table) {
            if (! Schema::hasTable($table)) {
                $devenuesRattachables[] = $table.' (table disparue)';

                continue;
            }

            if (Schema::hasColumn($table, 'organization_id')) {
                $devenuesRattachables[] = $table.' (porte desormais organization_id)';
            }
        }

        $this->assertSame([], $devenuesRattachables, sprintf(
            'Ces tables ne peuvent plus rester hors portee : %s.',
            implode(', ', $devenuesRattachables)
        ));
    }

    public function test_la_decouverte_ne_contient_aucune_colonne_ecrite_a_la_main(): void
    {
        // La garde de la garde : que la decouverte vienne bien du SCHEMA.
        //
        // Un futur mainteneur presse pourrait « reparer » un faux positif en
        // reintroduisant une liste en dur. Ce test compare colonne par
        // colonne ce que le preflight parcourt a ce que le schema declare.
        $decouvert = ScenarioSandboxPreflight::colonnesDePersonneParTable();
        $attendu = [];

        foreach ($this->tablesQuiReferencentUnCompte() as $table => $colonnes) {
            if (in_array($table, ScenarioSandboxPreflight::TABLES_HORS_PORTEE, true)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            sort($colonnes);
            $attendu[$table] = $colonnes;
        }

        ksort($attendu);

        $this->assertSame($attendu, $decouvert, 'Le preflight doit parcourir EXACTEMENT ce que le schema declare.');
    }

    public function test_les_colonnes_que_l_ancienne_liste_manquait_sont_desormais_parcourues(): void
    {
        // Le defaut nomme, transforme en test.
        //
        // `loop_messages.sender_id` est le chemin par lequel les messages
        // ChatLoop d'une personne reelle etaient detruits en silence.
        $decouvert = ScenarioSandboxPreflight::colonnesDePersonneParTable();

        $this->assertArrayHasKey('loop_messages', $decouvert);
        $this->assertContains('sender_id', $decouvert['loop_messages']);

        $this->assertArrayHasKey('dossier_blog_posts', $decouvert);
        $this->assertContains('added_by', $decouvert['dossier_blog_posts']);
    }

    public function test_les_contraintes_qui_INTERDISENT_de_supprimer_un_persona_sont_connues(): void
    {
        // La mesure qui a fait ECARTER le precheck analytique, gardee en
        // test pour que l'arbitrage repose sur un fait verifiable.
        //
        // 12 cles etrangeres vers `users` sont en ON DELETE RESTRICT. Le
        // purger en resout 5 lui-meme (`organizations.admin_id` et les 4
        // biens transferables). Les 7 restantes sont celles qui peuvent
        // encore faire echouer la purge d'un persona.
        $bloquantes = ScenarioSandboxPreflight::colonnesQuiBloquentLaSuppression();

        $this->assertSame([
            'course_quiz_attempts' => ['user_id'],
            'course_submissions' => ['user_id'],
            'dossiers' => ['owner_id'],
            'loop_poll_votes' => ['user_id'],
            'point_ledger' => ['user_id'],
            'transactions' => ['buyer_id', 'seller_id'],
        ], $bloquantes);

        // Et ce que le purger resout n'y figure PAS : les compter aurait
        // refuse des sandboxes que le moteur sait parfaitement vider.
        $this->assertArrayNotHasKey('organizations', $bloquantes);

        foreach (\App\Services\Users\UserDeletionExecutor::TRANSFERABLE as $bien) {
            $this->assertArrayNotHasKey($bien['table'], $bloquantes);
        }
    }

    /**
     * Decouverte INDEPENDANTE de celle du preflight.
     *
     * Volontairement reecrite ici : un test qui appellerait la methode qu'il
     * verifie prouverait seulement qu'elle est egale a elle-meme.
     *
     * @return array<string, list<string>>
     */
    private function tablesQuiReferencentUnCompte(): array
    {
        $parTable = [];

        foreach (Schema::getTables() as $table) {
            $colonnes = [];

            foreach (Schema::getForeignKeys($table['name']) as $cle) {
                if (($cle['foreign_table'] ?? null) !== 'users') {
                    continue;
                }

                foreach ($cle['columns'] as $colonne) {
                    $colonnes[] = $colonne;
                }
            }

            if ($colonnes !== []) {
                $parTable[$table['name']] = array_values(array_unique($colonnes));
            }
        }

        ksort($parTable);

        return $parTable;
    }
}
