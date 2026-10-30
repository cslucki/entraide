<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\UserWriteLock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1729 (A4) — la bascule d'unicite, ses gardes et son refus.
 *
 * ## Pourquoi ces tests refabriquent l'etat anterieur
 *
 * `RefreshDatabase` a deja applique toutes les migrations : la table est donc
 * DEJA dans sa forme cible. Rejouer `up()` ne mesurerait que lui-meme.
 *
 * Ces tests reviennent donc a la forme ANTERIEURE — `UNIQUE(email)` global —,
 * y fabriquent la situation a mesurer, puis exercent la migration REELLE,
 * celle du fichier. C'est la seule facon de prouver ce que MASTER a exige : la
 * conservation integrale des comptes, le refus nomme sur doublon, et un
 * `down()` qui refuse plutot que de perdre.
 */
class TASK1729EmailUniquenessMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_30_120000_unique_email_per_organization.php';

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    /** Revient a la forme anterieure : unique GLOBAL, pas de composite. */
    private function revenirALaFormeAnterieure(): void
    {
        $connexion = Schema::getConnection();

        foreach (['users_organization_email_unique', 'users_email_orphan_unique', 'users_email_lower_index'] as $index) {
            $connexion->statement('DROP INDEX '.($connexion->getDriverName() === 'pgsql' ? '' : 'IF EXISTS ').$index);
        }

        Schema::table('users', fn (Blueprint $table) => $table->unique('email', 'users_email_unique'));
    }

    /** Ecrit un compte SANS passer par le modele, pour franchir les gardes. */
    private function ligneUtilisateur(?string $organizationId, string $email): string
    {
        $id = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'name' => 'Compte '.substr($id, 0, 8),
            'email' => $email,
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_la_bascule_conserve_tous_les_comptes(): void
    {
        $orgA = Organization::factory()->create(['slug' => 'mig-a', 'is_active' => true]);
        $orgB = Organization::factory()->create(['slug' => 'mig-b', 'is_active' => true]);

        $this->revenirALaFormeAnterieure();

        $a = $this->ligneUtilisateur($orgA->id, 'garde-a@exemple.test');
        $b = $this->ligneUtilisateur($orgB->id, 'garde-b@exemple.test');
        $orphelin = $this->ligneUtilisateur(null, 'garde-orphelin@exemple.test');
        // Une adresse en MAJUSCULES, heritee de `/admin/users` sans `lowercase`.
        $majuscules = $this->ligneUtilisateur($orgA->id, 'Garde-Majuscules@Exemple.test');

        $avant = DB::table('users')->count();

        $this->migration()->up();

        // AUCUNE ligne perdue, AUCUNE adresse reecrite.
        $this->assertSame($avant, DB::table('users')->count());
        $this->assertSame('garde-a@exemple.test', DB::table('users')->where('id', $a)->value('email'));
        $this->assertSame('garde-b@exemple.test', DB::table('users')->where('id', $b)->value('email'));
        $this->assertNull(DB::table('users')->where('id', $orphelin)->value('organization_id'));
        $this->assertSame(
            'Garde-Majuscules@Exemple.test',
            DB::table('users')->where('id', $majuscules)->value('email'),
            "la casse d'origine est CONSERVEE : on change ce qui est un doublon, pas l'adresse de quelqu'un"
        );

        // Et les trois index sont la.
        $noms = collect(Schema::getIndexes('users'))->pluck('name');
        foreach (['users_organization_email_unique', 'users_email_orphan_unique', 'users_email_lower_index'] as $index) {
            $this->assertTrue($noms->contains($index), "index manquant : {$index}");
        }
        $this->assertFalse($noms->contains('users_email_unique'));
    }

    public function test_la_bascule_REFUSE_et_NOMME_un_doublon_de_casse_intra_organization(): void
    {
        // Le cas que l'unique global NE garantissait PAS : il est sensible a la
        // casse, donc `Alice@x` et `alice@x` pouvaient coexister. Apres A4 elles
        // seraient un doublon — la migration doit le dire AVANT d'agir.
        $org = Organization::factory()->create(['slug' => 'mig-casse', 'is_active' => true]);

        $this->revenirALaFormeAnterieure();

        $this->ligneUtilisateur($org->id, 'doublon@exemple.test');
        $this->ligneUtilisateur($org->id, 'DOUBLON@Exemple.TEST');

        try {
            $this->migration()->up();
            $this->fail('la bascule devait REFUSER');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('doublon@exemple.test', $e->getMessage(),
                "le refus doit NOMMER l'adresse en cause");
            $this->assertStringContainsString("Rien n'a ete modifie", $e->getMessage());
        }

        // Et rien n'a bouge : l'unique global est toujours la, les nouveaux non.
        $noms = collect(Schema::getIndexes('users'))->pluck('name');
        $this->assertTrue($noms->contains('users_email_unique'));
        $this->assertFalse($noms->contains('users_organization_email_unique'));
    }

    public function test_la_bascule_REFUSE_un_doublon_entre_ORPHELINS(): void
    {
        $this->revenirALaFormeAnterieure();

        $this->ligneUtilisateur(null, 'orph-dup@exemple.test');
        $this->ligneUtilisateur(null, 'ORPH-DUP@exemple.test');

        $this->expectException(\RuntimeException::class);

        try {
            $this->migration()->up();
        } finally {
            $this->assertTrue(
                collect(Schema::getIndexes('users'))->pluck('name')->contains('users_email_unique'),
                'la table reste protegee par la forme anterieure'
            );
        }
    }

    public function test_la_bascule_REFUSE_un_homonyme_ORPHELIN_CONTRE_MEMBRE(): void
    {
        // REVUE F1 — la paire que les deux index ne couvrent PAS, et la plus
        // nuisible des trois : un orphelin n'a qu'une porte, le login GLOBAL,
        // qui refuse une adresse ambigue (T1728). Laisser passer cette paire,
        // c'est laisser un compte MUET en base.
        //
        // Elle n'est pas exprimable par un index unique — elle porte sur deux
        // lignes dont l'une a `organization_id` nul et l'autre non — donc seul
        // le preflight peut la voir a la bascule.
        $org = Organization::factory()->create(['slug' => 'mig-mixte', 'is_active' => true]);

        $this->revenirALaFormeAnterieure();

        $this->ligneUtilisateur(null, 'mixte-mig@exemple.test');
        // Casse DIFFERENTE : une variante rend tout autant muet.
        $this->ligneUtilisateur($org->id, 'Mixte-Mig@Exemple.TEST');

        try {
            $this->migration()->up();
            $this->fail('la bascule devait REFUSER');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mixte-mig@exemple.test', $e->getMessage(),
                "le refus doit NOMMER l'adresse en cause");
            $this->assertStringContainsString('SANS Organization et un membre', $e->getMessage(),
                'et dire DE QUELLE des trois collisions il parle');
        }

        // Rien n'a bouge.
        $noms = collect(Schema::getIndexes('users'))->pluck('name');
        $this->assertTrue($noms->contains('users_email_unique'));
        $this->assertFalse($noms->contains('users_organization_email_unique'));
    }

    // ── REVUE F2 : le verrou d'ecriture des gardes d'administration ─────────

    /**
     * Les deux gardes prennent le verrou AVANT leur controle.
     *
     * ## Pourquoi mesurer l'ORDRE, en plus du verrou lui-meme
     *
     * Les tests a deux connexions ci-dessous prouvent que le verrou EXCLUT ;
     * ils ne prouvent pas que les CONTROLEURS le prennent, ni qu'ils le
     * prennent avant de verifier — ils l'acquierent eux-memes. Un sabotage
     * retirant `UserWriteLock::acquire()` des deux gardes les laissait
     * entierement verts.
     *
     * Celui-ci capture les instructions reellement emises pendant la requete.
     * Il tourne sur les DEUX moteurs : sous PostgreSQL le verrou est un
     * `LOCK TABLE`, sous SQLite l'ecriture neutre qui escalade le verrou de
     * fichier — les deux sont observables.
     */
    public function test_les_gardes_d_administration_prennent_le_verrou_avant_de_controler(): void
    {
        $orgA = Organization::factory()->create(['slug' => 'f2-a', 'is_active' => true]);
        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $instructions = [];
        DB::listen(function ($requete) use (&$instructions): void {
            $instructions[] = $requete->sql;
        });

        $this->actingAs($superAdmin)->delete(route('admin.organizations.destroy', $orgA));

        $verrou = $this->premierIndice($instructions, fn (string $sql): bool => str_contains($sql, 'SHARE ROW EXCLUSIVE')
            || str_contains($sql, 't1729_user_write_lock'));
        $controle = $this->premierIndice($instructions, fn (string $sql): bool => str_contains(strtolower($sql), 'lower(email)'));

        $this->assertNotNull($verrou, 'la garde doit prendre le verrou d\'ecriture');
        $this->assertNotNull($controle, 'la garde doit controler les adresses');
        $this->assertLessThan($controle, $verrou,
            'le verrou doit PRECEDER le controle, sinon une ecriture concurrente peut le rendre faux');
    }

    public function test_la_garde_de_rattachement_prend_aussi_le_verrou(): void
    {
        $orgA = Organization::factory()->create(['slug' => 'f2-b', 'is_active' => true]);
        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $instructions = [];
        DB::listen(function ($requete) use (&$instructions): void {
            $instructions[] = $requete->sql;
        });

        $this->actingAs($superAdmin)->post(route('admin.outils.assign-data.assign'), [
            'dataset' => 'users',
            'organization_id' => $orgA->id,
            'confirm' => '1',
            'confirmation' => 'REASSIGN USERS',
        ]);

        $verrou = $this->premierIndice($instructions, fn (string $sql): bool => str_contains($sql, 'SHARE ROW EXCLUSIVE')
            || str_contains($sql, 't1729_user_write_lock'));
        $controle = $this->premierIndice($instructions, fn (string $sql): bool => str_contains(strtolower($sql), 'lower(email)'));

        $this->assertNotNull($verrou);
        $this->assertNotNull($controle);
        $this->assertLessThan($controle, $verrou);
    }

    /** @param callable(string): bool $predicat */
    private function premierIndice(array $instructions, callable $predicat): ?int
    {
        foreach ($instructions as $i => $sql) {
            if ($predicat($sql)) {
                return $i;
            }
        }

        return null;
    }


    /**
     * Le verrou est pris DANS la transaction, et il BLOQUE reellement.
     *
     * ## Pourquoi un test a DEUX CONNEXIONS, et pas une sequence
     *
     * Un test sequentiel ne peut pas reproduire la fenetre : il n'y a personne
     * pour ecrire entre le controle et l'ecriture. Il montrerait seulement que
     * le code marche quand rien ne se passe — c'est-a-dire rien.
     *
     * Ici une SECONDE connexion, donc un second PDO, tente une ecriture
     * pendant que le verrou est tenu. L'echec attendu est un depassement de
     * delai : la preuve que `SHARE ROW EXCLUSIVE` entre bien en conflit avec le
     * `ROW EXCLUSIVE` que prend tout `INSERT`.
     *
     * Ignore sous SQLite, et pas par commodite : la base de test y est
     * `:memory:`, ou chaque connexion ouvre sa PROPRE base. Deux connexions ne
     * partagent alors aucun fichier, et le verrouillage de fichier de SQLite
     * n'a rien a verrouiller. Le raisonnement SQLite est documente dans
     * `UserWriteLock`, et le refus hors transaction ci-dessous est mesure sur
     * les deux moteurs.
     */
    public function test_pgsql_le_verrou_des_gardes_bloque_une_ecriture_concurrente(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Verrou de table : PostgreSQL seulement (SQLite verrouille le fichier, et la base de test est :memory:).');
        }

        config(['database.connections.pgsql_concurrent' => config('database.connections.pgsql')]);
        $concurrente = DB::connection('pgsql_concurrent');
        $concurrente->statement("SET lock_timeout = '250ms'");

        // Le verrou exact que prennent les deux gardes. La transaction de
        // `RefreshDatabase` est deja ouverte.
        UserWriteLock::acquire();

        try {
            $concurrente->table('users')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => null,
                'name' => 'Concurrent',
                'email' => 'concurrent-f2@exemple.test',
                'password' => Hash::make('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail("l'ecriture concurrente aurait du etre BLOQUEE par le verrou");
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()),
                "l'echec doit etre un depassement de delai de verrou, et non une autre erreur");
        } finally {
            $concurrente->disconnect();
        }
    }

    /**
     * Le verrou laisse passer les LECTURES — c'est pourquoi `SHARE ROW
     * EXCLUSIVE` et non `ACCESS EXCLUSIVE`.
     *
     * Un verrou qui bloquerait aussi les `SELECT` figerait l'application le
     * temps d'un geste d'administration. La MIGRATION, elle, prend bien
     * `ACCESS EXCLUSIVE` : une bascule de schema peut arreter le monde
     * quelques instants, un ecran d'administration non.
     */
    public function test_pgsql_le_verrou_laisse_passer_les_lectures(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL seulement.');
        }

        config(['database.connections.pgsql_lecture' => config('database.connections.pgsql')]);
        $lectrice = DB::connection('pgsql_lecture');
        $lectrice->statement("SET lock_timeout = '250ms'");

        UserWriteLock::acquire();

        try {
            // Ne doit PAS lever : les lectures restent servies.
            $this->assertIsInt((int) $lectrice->table('users')->count());
        } finally {
            $lectrice->disconnect();
        }
    }

    public function test_le_verrou_REFUSE_d_etre_pris_hors_transaction(): void
    {
        // Fail-closed, et mesure sur les DEUX moteurs : hors transaction, le
        // verrou ne survit pas a l'instruction qui le prend. Un appelant qui
        // oublie la transaction doit le savoir, pas croire etre protege.
        //
        // `RefreshDatabase` ouvre une transaction : on la quitte le temps de la
        // mesure, puis on la rouvre pour que le nettoyage du cas de test
        // retrouve l'etat qu'il attend.
        DB::rollBack();

        try {
            UserWriteLock::acquire();
            $this->fail('acquire() devait REFUSER hors transaction');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('transaction', strtolower($e->getMessage()));
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_down_restaure_l_unicite_globale_sans_perte(): void
    {
        $org = Organization::factory()->create(['slug' => 'mig-down', 'is_active' => true]);
        $this->ligneUtilisateur($org->id, 'down-a@exemple.test');
        $this->ligneUtilisateur(null, 'down-b@exemple.test');

        $avant = DB::table('users')->count();

        $this->migration()->down();

        $noms = collect(Schema::getIndexes('users'))->pluck('name');
        $this->assertTrue($noms->contains('users_email_unique'));
        $this->assertFalse($noms->contains('users_organization_email_unique'));
        $this->assertFalse($noms->contains('users_email_orphan_unique'));
        $this->assertSame($avant, DB::table('users')->count());
    }

    public function test_down_REFUSE_des_qu_un_compte_FRERE_existe(): void
    {
        // LE point de non-retour, mesure. Des qu'une adresse est portee par deux
        // comptes, la forme anterieure ne peut PAS les contenir : redescendre en
        // silence en perdrait un, et on ne saurait pas lequel.
        $orgA = Organization::factory()->create(['slug' => 'mig-nr-a', 'is_active' => true]);
        $orgB = Organization::factory()->create(['slug' => 'mig-nr-b', 'is_active' => true]);

        // Creables NORMALEMENT depuis A4 — c'est tout l'objet de la TASK.
        User::factory()->create(['organization_id' => $orgA->id, 'email' => 'frere@exemple.test']);
        User::factory()->create(['organization_id' => $orgB->id, 'email' => 'frere@exemple.test']);

        try {
            $this->migration()->down();
            $this->fail('down() devait REFUSER');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('frere@exemple.test', $e->getMessage());
            $this->assertStringContainsString("Rien n'a ete modifie", $e->getMessage());
        }

        // La forme A4 est intacte : le refus n'a rien casse en chemin.
        $noms = collect(Schema::getIndexes('users'))->pluck('name');
        $this->assertTrue($noms->contains('users_organization_email_unique'));
        $this->assertFalse($noms->contains('users_email_unique'));
        $this->assertSame(2, User::query()->whereRaw('LOWER(email) = ?', ['frere@exemple.test'])->count());
    }
}
