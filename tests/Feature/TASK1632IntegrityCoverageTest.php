<?php

namespace Tests\Feature;

use App\Support\Integrity\SchemaReferenceInspector;
use App\Support\Integrity\UnprotectedReferenceRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1632 — la garde de couverture, et le compromis qu'elle incarne.
 *
 * Exiger une classification metier des 137 tables portant `organization_id`,
 * `loop_id` ou `dossier_id` produirait environ 140 regles dont la quasi-
 * totalite dirait la meme chose : « cle etrangere presente, rien a
 * verifier ». Ce serait le symetrique de l'erreur de TASK-1631 — un registre
 * que personne ne relit, cette fois par exces.
 *
 * La garde porte donc uniquement la ou une reference cassee est POSSIBLE :
 * les tables SANS cle etrangere. Elles sont 3 aujourd'hui pour
 * `organization_id`, et **aucune** pour `loop_id` et `dossier_id`.
 */
class TASK1632IntegrityCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le volet MORDANT : une table sans cle etrangere et non declaree fait
     * rougir, avec son nom.
     */
    public function test_every_unprotected_reference_is_declared(): void
    {
        $inspector = app(SchemaReferenceInspector::class);
        $registry = app(UnprotectedReferenceRegistry::class);

        $sqlite = DB::connection()->getDriverName() !== 'pgsql';

        foreach ($registry->columns() as $column) {
            $inSchema = $inspector->tablesWithoutForeignKey($column);
            $declared = array_keys($registry->forColumn($column));

            if ($sqlite) {
                // TASK-1633 : la contrainte de `referrals` / `referral_rewards`
                // est posee par une migration pgsql-only — SQLite refuse
                // `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY` (mesure).
                // Ces tables restent donc sans cle etrangere ici, et c'est
                // attendu.
                //
                // La tolerance est NOMMEE, pas globale : une troisieme table
                // sans contrainte ferait toujours rougir, sur les deux
                // moteurs.
                $declared = array_merge($declared, $registry->enginePendingTables($column));
            }

            sort($inSchema);
            sort($declared);

            $this->assertSame(
                $declared,
                $inSchema,
                "Les tables portant `{$column}` SANS cle etrangere ne correspondent pas a ce qui est declare.\n"
                .'Une table ajoutee sans contrainte doit etre classee `PROTECTED_HISTORY` (la reference survit '
                .'volontairement a son parent) ou `UNGUARDED` (une reference cassee y serait un vrai defaut).'
            );
        }
    }

    /**
     * Le volet COHERENCE : une table qui GAGNE une cle etrangere doit sortir
     * de la liste.
     *
     * C'est exactement ce qui est arrive a `referrals` et `referral_rewards`
     * en TASK-1633 : leur contrainte posee, leur declaration est devenue
     * perimee et a ete retiree. Sans ce volet, elle serait restee, faisant
     * croire a une surveillance qui n'avait plus lieu d'etre.
     */
    public function test_a_declared_table_that_gains_a_foreign_key_is_reported(): void
    {
        $inspector = app(SchemaReferenceInspector::class);
        $registry = app(UnprotectedReferenceRegistry::class);

        foreach ($registry->columns() as $column) {
            $sansContrainte = $inspector->tablesWithoutForeignKey($column);

            foreach (array_keys($registry->forColumn($column)) as $table) {
                $this->assertContains(
                    $table,
                    $sansContrainte,
                    "`{$table}.{$column}` est declaree non protegee mais porte desormais une cle etrangere : "
                    .'la declaration doit etre retiree.'
                );
            }
        }
    }

    /**
     * La preuve que la garde DECOUVRE, au lieu de comparer deux constantes.
     *
     * On cree une table pour de vrai, sans contrainte, et on verifie qu'elle
     * remonte. Sans ce test, la garde pourrait ne comparer qu'un tableau a
     * lui-meme et rester verte pour toujours.
     */
    public function test_the_guard_discovers_a_new_unprotected_table(): void
    {
        Schema::create('task1632_sans_contrainte', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
        });

        try {
            $this->assertContains(
                'task1632_sans_contrainte',
                app(SchemaReferenceInspector::class)->tablesWithoutForeignKey('organization_id')
            );
        } finally {
            Schema::dropIfExists('task1632_sans_contrainte');
        }
    }

    /**
     * Et symetriquement : une table AVEC contrainte ne demande aucun
     * arbitrage. C'est ce qui borne la garde a un perimetre utile.
     */
    public function test_a_new_table_with_a_foreign_key_creates_no_friction(): void
    {
        Schema::create('task1632_avec_contrainte', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
        });

        try {
            $this->assertNotContains(
                'task1632_avec_contrainte',
                app(SchemaReferenceInspector::class)->tablesWithoutForeignKey('organization_id')
            );
        } finally {
            Schema::dropIfExists('task1632_avec_contrainte');
        }
    }

    /**
     * L'inspecteur doit rendre la MEME reponse sur les deux moteurs.
     *
     * `information_schema` en PostgreSQL, `PRAGMA foreign_key_list` en
     * SQLite : deux chemins distincts, un seul contrat. Ce test tourne sur
     * les deux, et c'est ce qui autorise a ne pas skipper SQLite.
     *
     * TASK-1633 : les assertions sont STRUCTURELLES, plus chiffrees. Figer
     * « 24 tables a loop_id » ou la liste des tables sans contrainte aurait
     * transforme ce test en compteur a maintenir — et il a effectivement
     * fallu le corriger quand la migration de convergence en a retire deux.
     * Ce qui compte est le CONTRAT : hors exceptions declarees, toute colonne
     * de reference est protegee.
     */
    public function test_the_inspector_agrees_with_the_documented_state_on_both_engines(): void
    {
        $inspector = app(SchemaReferenceInspector::class);
        $registry = app(UnprotectedReferenceRegistry::class);

        $attendu = array_keys($registry->forColumn('organization_id'));

        if (DB::connection()->getDriverName() !== 'pgsql') {
            // La migration de convergence est pgsql-only : SQLite n'accepte
            // une cle etrangere qu'a la creation de la table.
            $attendu = array_merge($attendu, $registry->enginePendingTables('organization_id'));
        }

        $this->assertSame($this->sorted($attendu), $this->sorted($inspector->tablesWithoutForeignKey('organization_id')));

        // `ai_provider_invocations` reste l'exception volontaire, sur les deux
        // moteurs : ledger economique, FK retiree par une migration dediee.
        $this->assertContains('ai_provider_invocations', $inspector->tablesWithoutForeignKey('organization_id'));

        // Couverture INTEGRALE : aucune table a `loop_id` ou `dossier_id`
        // n'echappe a une cle etrangere. Ce fait ne depend d'aucun compte.
        $this->assertSame([], $inspector->tablesWithoutForeignKey('loop_id'));
        $this->assertSame([], $inspector->tablesWithoutForeignKey('dossier_id'));
        $this->assertNotEmpty($inspector->tablesWithColumn('loop_id'));
        $this->assertNotEmpty($inspector->tablesWithColumn('dossier_id'));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
