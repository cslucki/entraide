<?php

namespace Tests\Feature;

use App\Support\Integrity\SchemaReferenceInspector;
use App\Support\Integrity\UnprotectedReferenceRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        foreach ($registry->columns() as $column) {
            $inSchema = $inspector->tablesWithoutForeignKey($column);
            $declared = array_keys($registry->forColumn($column));

            sort($inSchema);
            sort($declared);

            $this->assertSame(
                $inSchema,
                $declared,
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
     * Le jour ou `referrals` recoit sa contrainte — ce serait la bonne
     * correction, et elle appartient a MASTER — ce test le dit, au lieu de
     * laisser une declaration perimee faire croire a une surveillance qui
     * n'a plus lieu d'etre.
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
     */
    public function test_the_inspector_agrees_with_the_documented_state_on_both_engines(): void
    {
        $inspector = app(SchemaReferenceInspector::class);

        $this->assertSame(
            ['ai_provider_invocations', 'referral_rewards', 'referrals'],
            $this->sorted($inspector->tablesWithoutForeignKey('organization_id'))
        );

        // Couverture integrale : 24 tables a `loop_id`, 6 a `dossier_id`,
        // toutes contraintes. Une reference cassee y est impossible.
        $this->assertSame([], $inspector->tablesWithoutForeignKey('loop_id'));
        $this->assertSame([], $inspector->tablesWithoutForeignKey('dossier_id'));
        $this->assertSame(24, count($inspector->tablesWithColumn('loop_id')));
        $this->assertSame(6, count($inspector->tablesWithColumn('dossier_id')));
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
