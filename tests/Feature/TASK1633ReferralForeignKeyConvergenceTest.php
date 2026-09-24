<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Integrity\SchemaReferenceInspector;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1633 — deux bases nees des memes migrations doivent avoir le meme
 * schema.
 *
 * `create_referrals_table` omettait la cle etrangere sur `organization_id` ;
 * `2026_05_28_000002_drop_community_id_from_tables` la posait, mais seulement
 * `if (Schema::hasColumn('referrals', 'community_id'))`. Sur une base creee
 * apres l'ere Community la condition est fausse, le bloc est saute, et la
 * contrainte n'arrive jamais. Mesure au deploiement de la 1.632 : la PROD les
 * a, une installation fraiche non.
 *
 * ## Ce que ces tests prouvent, et comment
 *
 * Le catalogue du schema ne suffit pas : une contrainte peut y figurer et ne
 * rien empecher si elle est mal formee. Les tests ci-dessous eprouvent donc
 * le COMPORTEMENT — une insertion vers une Organization absente doit etre
 * refusee par la base elle-meme, et la suppression d'une Organization doit
 * emporter ses lignes selon le `ON DELETE CASCADE` releve en PROD.
 *
 * Le releve PROD fait foi pour le contrat :
 *
 *     FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
 *
 * non deferrable, sans ON UPDATE explicite — confirme a l'identique sur
 * `bouclepro_prod_mirror` et `bouclepro_mock_prod`.
 *
 * ## SQLite
 *
 * Le moteur refuse `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY` : il
 * n'accepte une cle etrangere qu'a la creation de la table. La migration est
 * donc pgsql-only, et les tests de comportement DB se skippent ici avec cette
 * raison — jamais pour masquer un echec, et jamais en simulant une contrainte
 * qui n'existe pas. Ce qui est testable sur les deux moteurs l'est.
 */
class TASK1633ReferralForeignKeyConvergenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const TABLES = ['referrals', 'referral_rewards'];

    // ── Convergence du schema ─────────────────────────────────────────────

    /**
     * Apres migration, les deux tables portent la contrainte — quel que soit
     * l'etat d'ou l'on vient.
     *
     * `RefreshDatabase` rejoue toutes les migrations sur une base vide :
     * c'est le scenario SCHEMA FRAIS, celui qui n'avait PAS la cle. Le
     * scenario SCHEMA HISTORIQUE est couvert par
     * `test_the_migration_leaves_an_existing_constraint_untouched`.
     */
    public function test_both_tables_carry_the_organization_foreign_key(): void
    {
        $this->skipUnlessPostgres();

        foreach (self::TABLES as $table) {
            $this->assertNotNull(
                $this->organizationForeignKey($table),
                "{$table}.organization_id devrait porter une cle etrangere apres migration."
            );
        }
    }

    /**
     * Le contrat exact, releve en PROD et non invente.
     */
    public function test_the_constraint_matches_the_production_contract(): void
    {
        $this->skipUnlessPostgres();

        foreach (self::TABLES as $table) {
            $fk = $this->organizationForeignKey($table);

            $this->assertSame(
                'FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE',
                $fk->def,
                "Le contrat de {$table} doit etre celui de la PROD."
            );
            $this->assertFalse((bool) $fk->condeferrable, "{$table} : la contrainte ne doit pas etre deferrable.");
        }
    }

    /**
     * Exactement UNE contrainte par table : la migration ne doit pas en
     * ajouter une seconde, equivalente et invisible, sur une base qui en a
     * deja une.
     */
    public function test_there_is_exactly_one_constraint_per_table(): void
    {
        $this->skipUnlessPostgres();

        foreach (self::TABLES as $table) {
            $this->assertSame(1, $this->organizationForeignKeyCount($table));
        }
    }

    /**
     * SCHEMA HISTORIQUE : la contrainte est deja la, la migration la laisse
     * intacte.
     *
     * On reproduit l'etat PROD en supprimant puis reposant la contrainte, on
     * rejoue le `up()` de la migration, et on verifie qu'il n'a rien change —
     * ni doublon, ni contrainte recreee sous un autre nom. Une recreation
     * prendrait un verrou exclusif sur une table de production pour rien.
     */
    public function test_the_migration_leaves_an_existing_constraint_untouched(): void
    {
        $this->skipUnlessPostgres();

        $avant = [];

        foreach (self::TABLES as $table) {
            $fk = $this->organizationForeignKey($table);
            $avant[$table] = [$fk->conname, $fk->def];
        }

        $this->runConvergenceMigration();

        foreach (self::TABLES as $table) {
            $fk = $this->organizationForeignKey($table);

            $this->assertSame(1, $this->organizationForeignKeyCount($table), "{$table} : aucune contrainte dupliquee.");
            $this->assertSame($avant[$table], [$fk->conname, $fk->def], "{$table} : la contrainte n'a pas bouge.");
        }
    }

    /**
     * SCHEMA FRAIS : contrainte retiree, migration rejouee, contrainte
     * revenue — avec exactement le meme contrat.
     */
    public function test_the_migration_restores_a_missing_constraint(): void
    {
        $this->skipUnlessPostgres();

        foreach (self::TABLES as $table) {
            DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT '.$table.'_organization_id_foreign');
            $this->assertNull($this->organizationForeignKey($table), "{$table} : la contrainte devrait avoir ete retiree.");
        }

        $this->runConvergenceMigration();

        foreach (self::TABLES as $table) {
            $fk = $this->organizationForeignKey($table);

            $this->assertNotNull($fk, "{$table} : la migration devait reposer la contrainte.");
            $this->assertSame(
                'FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE',
                $fk->def
            );
            $this->assertSame(1, $this->organizationForeignKeyCount($table));
        }
    }

    /**
     * Le rollback ne doit JAMAIS casser une base historique.
     *
     * La migration n'a pas cree la contrainte partout : sur une PROD elle
     * l'a trouvee posee et n'a rien fait. Un `down()` qui la supprimerait
     * detruirait donc ce qu'il n'a pas cree. Il est volontairement vide, et
     * ce test garde ce choix.
     */
    public function test_the_rollback_never_drops_a_pre_existing_constraint(): void
    {
        $this->skipUnlessPostgres();

        $this->migrationInstance()->down();

        foreach (self::TABLES as $table) {
            $this->assertNotNull(
                $this->organizationForeignKey($table),
                "{$table} : le rollback ne doit pas retirer une contrainte qu'il n'a pas posee."
            );
        }
    }

    // ── Comportement reel de la contrainte ────────────────────────────────

    /**
     * Le catalogue dit que la contrainte existe ; ceci prouve qu'elle AGIT.
     */
    public function test_the_database_refuses_a_reference_to_a_missing_organization(): void
    {
        $this->skipUnlessPostgres();

        $organization = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $a = User::factory()->create(['organization_id' => $organization->id]);
        $b = User::factory()->create(['organization_id' => $organization->id]);

        $this->expectException(QueryException::class);

        DB::table('referrals')->insert($this->referralRow($a, $b, (string) Str::uuid()));
    }

    public function test_the_database_refuses_an_update_towards_a_missing_organization(): void
    {
        $this->skipUnlessPostgres();

        $organization = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $a = User::factory()->create(['organization_id' => $organization->id]);
        $b = User::factory()->create(['organization_id' => $organization->id]);

        $id = (string) Str::uuid();
        DB::table('referrals')->insert($this->referralRow($a, $b, $organization->id, $id));

        $this->expectException(QueryException::class);

        DB::table('referrals')->where('id', $id)->update(['organization_id' => (string) Str::uuid()]);
    }

    public function test_a_valid_organization_is_accepted(): void
    {
        $organization = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $a = User::factory()->create(['organization_id' => $organization->id]);
        $b = User::factory()->create(['organization_id' => $organization->id]);

        DB::table('referrals')->insert($this->referralRow($a, $b, $organization->id));

        $this->assertSame(1, DB::table('referrals')->where('organization_id', $organization->id)->count());
    }

    /**
     * La colonne reste NULLABLE : la contrainte n'a pas durci ce contrat-la,
     * et une ligne sans Organization doit rester acceptee.
     */
    public function test_a_null_organization_is_still_accepted(): void
    {
        $organization = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $a = User::factory()->create(['organization_id' => $organization->id]);
        $b = User::factory()->create(['organization_id' => $organization->id]);

        DB::table('referrals')->insert($this->referralRow($a, $b, null));

        $this->assertSame(1, DB::table('referrals')->whereNull('organization_id')->count());
    }

    /**
     * `ON DELETE CASCADE`, teste pour ce qu'il FAIT et non pour ce qu'il dit.
     *
     * Le comportement est celui releve en PROD : supprimer l'Organization
     * emporte ses parrainages. C'est coherent avec le metier — un parrainage
     * n'a aucun sens hors de son tenant.
     */
    public function test_deleting_the_organization_cascades_to_referrals(): void
    {
        $this->skipUnlessPostgres();

        $organization = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $autre = Organization::factory()->create(['is_active' => true]);
        $a = User::factory()->create(['organization_id' => $autre->id]);
        $b = User::factory()->create(['organization_id' => $autre->id]);

        $id = (string) Str::uuid();
        DB::table('referrals')->insert($this->referralRow($a, $b, $organization->id, $id));

        DB::table('referral_rewards')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'referral_id' => $id,
            'user_id' => $a->id,
            'event_type' => 'signup',
            'level' => 1,
            'points' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organizations')->where('id', $organization->id)->delete();

        $this->assertSame(0, DB::table('referrals')->where('id', $id)->count());
        $this->assertSame(0, DB::table('referral_rewards')->where('referral_id', $id)->count());
    }

    // ── Le cockpit d'integrite (TASK-1632) ────────────────────────────────

    /**
     * L'effet attendu sur le cockpit : hors exception volontaire, plus
     * aucune table n'echappe a une cle etrangere.
     */
    public function test_the_integrity_cockpit_no_longer_sees_them_as_unprotected(): void
    {
        $this->skipUnlessPostgres();

        $sansContrainte = app(SchemaReferenceInspector::class)->tablesWithoutForeignKey('organization_id');

        $this->assertNotContains('referrals', $sansContrainte);
        $this->assertNotContains('referral_rewards', $sansContrainte);

        // `ai_provider_invocations` reste la seule exception, et elle est
        // volontaire : ledger economique, FK retiree par une migration dediee.
        $this->assertSame(['ai_provider_invocations'], $sansContrainte);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'SQLite refuse `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY` : il n\'accepte une cle '
                .'etrangere qu\'a la creation de la table. La migration est donc pgsql-only, et simuler '
                .'ici une contrainte inexistante rendrait un vert qui ne prouve rien.'
            );
        }
    }

    private function organizationForeignKey(string $table): ?object
    {
        $rows = DB::select(<<<'SQL'
            SELECT con.conname, pg_get_constraintdef(con.oid) AS def, con.condeferrable
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
            JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY (con.conkey)
            WHERE con.contype = 'f'
              AND rel.relname = ?
              AND att.attname = 'organization_id'
              AND nsp.nspname = current_schema()
        SQL, [$table]);

        return $rows[0] ?? null;
    }

    private function organizationForeignKeyCount(string $table): int
    {
        return count(DB::select(<<<'SQL'
            SELECT 1
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
            JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY (con.conkey)
            WHERE con.contype = 'f'
              AND rel.relname = ?
              AND att.attname = 'organization_id'
              AND nsp.nspname = current_schema()
        SQL, [$table]));
    }

    private function runConvergenceMigration(): void
    {
        $this->migrationInstance()->up();
    }

    private function migrationInstance(): object
    {
        return require database_path('migrations/2026_09_24_190000_converge_referral_organization_foreign_keys.php');
    }

    /**
     * @return array<string, mixed>
     */
    private function referralRow(User $referrer, User $referred, ?string $organizationId, ?string $id = null): array
    {
        return [
            'id' => $id ?? (string) Str::uuid(),
            'organization_id' => $organizationId,
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'depth' => 1,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
