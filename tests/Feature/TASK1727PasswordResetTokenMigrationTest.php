<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1727 (A2) — la reprise des tokens existants, et le refus de les perdre.
 *
 * ## Ce que ce fichier mesure, et pourquoi il ne peut pas rejouer la migration
 *
 * `RefreshDatabase` a deja applique toutes les migrations : la table est donc
 * DEJA dans sa forme cible quand ces tests demarrent. Rejouer `up()` ici ne
 * mesurerait rien d'autre que lui-meme.
 *
 * Ces tests REFABRIQUENT donc la situation : ils recreent la forme ANTERIEURE
 * (`email` PRIMARY KEY), y posent des tokens — dont un orphelin —, puis
 * exercent la migration reelle, celle du fichier, par le migrateur. C'est la
 * seule facon de prouver ce que MASTER a exige : hash, date et expiration
 * inchanges, orphelins conserves mais inutilisables, comptages verifies, et un
 * `down()` qui REFUSE plutot que de perdre.
 *
 * Portabilite : la reconstruction de table est le seul chemin ou SQLite et
 * PostgreSQL divergent (SQLite ne sait pas retirer une PRIMARY KEY en place).
 * Les deux gates de CI executent donc ces tests sur les deux moteurs.
 */
class TASK1727PasswordResetTokenMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_30_090000_identify_password_reset_tokens_by_user.php';

    /** Recree la forme ANTERIEURE a TASK-1727 : `email` en cle primaire. */
    private function revenirALaFormeAnterieure(): void
    {
        Schema::drop('password_reset_tokens');

        DB::statement('CREATE TABLE password_reset_tokens (email VARCHAR(255) NOT NULL PRIMARY KEY, token VARCHAR(255) NOT NULL, created_at TIMESTAMP NULL)');
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    public function test_la_reprise_conserve_hash_date_et_orphelins(): void
    {
        $org = Organization::factory()->create();
        $compte = User::factory()->create(['organization_id' => $org->id, 'email' => 'repris@exemple.test']);

        $this->revenirALaFormeAnterieure();

        // Un hash et une date CHOISIS, pour pouvoir affirmer qu'ils ressortent
        // a l'octet. Un token recalcule invaliderait tous les liens en vol.
        $hash = Hash::make('jeton-en-vol-1727');
        $creeLe = now()->subMinutes(10)->startOfSecond();

        DB::table('password_reset_tokens')->insert([
            ['email' => 'repris@exemple.test', 'token' => $hash, 'created_at' => $creeLe],
            // L'ORPHELIN : aucun compte ne porte cette adresse.
            ['email' => 'disparu@exemple.test', 'token' => Hash::make('jeton-orphelin'), 'created_at' => $creeLe],
        ]);

        $avant = DB::table('password_reset_tokens')->count();
        $this->assertSame(2, $avant);

        $this->migration()->up();

        // 1. AUCUNE perte : c'est l'exigence « aucun token actif perdu
        //    silencieusement », verifiee par comptage et non affirmee.
        $this->assertSame($avant, DB::table('password_reset_tokens')->count());

        // 2. Le token du compte est RATTACHE, et son contenu est intact.
        $repris = DB::table('password_reset_tokens')->where('email', 'repris@exemple.test')->sole();
        $this->assertSame($compte->id, $repris->user_id);
        $this->assertSame($hash, $repris->token, 'le hash ne doit pas etre recalcule');
        $this->assertSame(
            $creeLe->toDateTimeString(),
            \Illuminate\Support\Carbon::parse($repris->created_at)->toDateTimeString(),
            "la date — donc l'expiration — est inchangee"
        );

        // 3. L'ORPHELIN est CONSERVE, avec `user_id` a NULL.
        $orphelin = DB::table('password_reset_tokens')->where('email', 'disparu@exemple.test')->sole();
        $this->assertNull($orphelin->user_id, 'conserve, et rattache a personne');
    }

    public function test_un_lien_deja_envoye_fonctionne_encore_apres_migration(): void
    {
        // La condition de compatibilite posee en D1 : « conserver autant que
        // possible les URL existantes et la compatibilite des liens deja
        // envoyes ». On le mesure de bout en bout, par HTTP, avec un token
        // emis AVANT la migration.
        $org = Organization::factory()->create();
        $compte = User::factory()->create(['organization_id' => $org->id, 'email' => 'lien-en-vol@exemple.test']);

        $this->revenirALaFormeAnterieure();

        $token = 'jeton-emis-avant-la-migration-1727';

        DB::table('password_reset_tokens')->insert([
            'email' => $compte->email,
            'token' => Hash::make($token),
            'created_at' => now()->subMinutes(5),
        ]);

        $this->migration()->up();

        // Le MEME lien, la MEME URL globale, le MEME token.
        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $compte->email,
            'password' => 'nouveau-mot-de-passe-1727',
            'password_confirmation' => 'nouveau-mot-de-passe-1727',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('nouveau-mot-de-passe-1727', $compte->fresh()->password));
    }

    public function test_un_orphelin_repris_n_ouvre_aucun_compte(): void
    {
        // L'autre sens : « conserves MAIS inutilisables pour un autre compte ».
        // Sans ce test, conserver les orphelins serait une regression de
        // securite deguisee en precaution.
        $org = Organization::factory()->create();

        $this->revenirALaFormeAnterieure();

        DB::table('password_reset_tokens')->insert([
            'email' => 'repreneur@exemple.test',
            'token' => Hash::make('jeton-orphelin-1727'),
            'created_at' => now(),
        ]);

        $this->migration()->up();

        // Le compte est cree APRES, avec l'adresse que l'orphelin porte :
        // exactement le scenario dangereux d'avant T1727.
        $nouveau = User::factory()->create(['organization_id' => $org->id, 'email' => 'repreneur@exemple.test']);
        $empreinte = $nouveau->password;

        $this->post(route('password.store'), [
            'token' => 'jeton-orphelin-1727',
            'email' => 'repreneur@exemple.test',
            'password' => 'mot-de-passe-vole-1727',
            'password_confirmation' => 'mot-de-passe-vole-1727',
        ])->assertSessionHasErrors('email');

        $this->assertSame($empreinte, $nouveau->fresh()->password,
            "un token anterieur ne doit JAMAIS ouvrir le compte qui reprend l'adresse");
    }

    public function test_la_suppression_d_un_compte_emporte_son_token(): void
    {
        // La cle etrangere `cascadeOnDelete`. Avant T1727 la table n'avait
        // aucune FK : un compte supprime laissait son token vivre sous une
        // adresse reutilisable. C'est le defaut ci-dessus, a la racine.
        $org = Organization::factory()->create();
        $compte = User::factory()->create(['organization_id' => $org->id]);

        Password::broker()->createToken($compte);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('user_id', $compte->id)->count());

        DB::table('users')->where('id', $compte->id)->delete();

        $this->assertSame(0, DB::table('password_reset_tokens')->count(),
            'le token meurt avec le compte');
    }

    public function test_down_restaure_la_forme_anterieure_sans_perte(): void
    {
        $org = Organization::factory()->create();
        $a = User::factory()->create(['organization_id' => $org->id, 'email' => 'bas-a@exemple.test']);
        $b = User::factory()->create(['organization_id' => $org->id, 'email' => 'bas-b@exemple.test']);

        Password::broker()->createToken($a);
        Password::broker()->createToken($b);

        $avant = DB::table('password_reset_tokens')->count();
        $this->assertSame(2, $avant);

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('password_reset_tokens', 'user_id'),
            'la forme anterieure est restauree');
        $this->assertSame($avant, DB::table('password_reset_tokens')->count(),
            'et aucun token n\'est perdu au passage');
    }

    // ── Concurrence : la fenetre lecture -> remplacement (revue MASTER) ─────

    /**
     * Le verrou est pris AVANT le premier comptage, et la copie est deja
     * ecrite quand il l'est.
     *
     * ## Pourquoi mesurer l'ORDRE, et non seulement le resultat
     *
     * La perte concurrente ne se reproduit pas dans un test a connexion
     * unique : il n'y a personne pour ecrire pendant la fenetre. Ce qui se
     * mesure, en revanche, c'est que la fenetre est FERMEE PAR CONSTRUCTION —
     * l'ordre des instructions. Et cet ordre est exactement ce qu'une
     * relecture distraite defait, en remontant le comptage « pour l'avoir
     * sous les yeux ».
     *
     * Les deux moteurs sont couverts par le meme test, parce que l'ordre les
     * protege tous les deux : la creation de la table temporaire est
     * l'ecriture qui escalade le verrou de fichier sous SQLite, et le
     * `LOCK TABLE` qui la suit est ce qui exclut sous PostgreSQL.
     */
    public function test_le_verrou_precede_toute_lecture_de_la_table_source(): void
    {
        $org = Organization::factory()->create();
        User::factory()->create(['organization_id' => $org->id, 'email' => 'ordre@exemple.test']);

        $this->revenirALaFormeAnterieure();

        DB::table('password_reset_tokens')->insert([
            'email' => 'ordre@exemple.test',
            'token' => Hash::make('jeton-ordre-1727'),
            'created_at' => now(),
        ]);

        $instructions = [];
        DB::listen(function ($requete) use (&$instructions): void {
            $instructions[] = $requete->sql;
        });

        $this->migration()->up();

        $creationTemp = $this->premierIndice($instructions, fn (string $sql): bool => str_contains($sql, 'create table') && str_contains($sql, 'password_reset_tokens_t1727'));
        $premiereLecture = $this->premierIndice($instructions, fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), 'select') && str_contains($sql, 'password_reset_tokens') && ! str_contains($sql, 't1727'));

        $this->assertNotNull($creationTemp, 'la table temporaire doit etre creee');
        $this->assertNotNull($premiereLecture, 'la table source doit etre lue');

        // 1. L'ecriture qui escalade le verrou SQLite precede toute lecture.
        $this->assertLessThan($premiereLecture, $creationTemp,
            'la creation de la table temporaire doit PRECEDER la premiere lecture de la source');

        // 2. Sous PostgreSQL, le verrou explicite est entre les deux.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $verrou = $this->premierIndice($instructions, fn (string $sql): bool => str_contains($sql, 'ACCESS EXCLUSIVE MODE'));

            $this->assertNotNull($verrou, 'LOCK TABLE ... ACCESS EXCLUSIVE doit etre pris');
            $this->assertGreaterThan($creationTemp, $verrou);
            $this->assertLessThan($premiereLecture, $verrou,
                'le verrou doit PRECEDER la premiere lecture, sinon le comptage reste aveugle');
        }
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
     * PostgreSQL : le verrou BLOQUE REELLEMENT une ecriture concurrente.
     *
     * Le test d'ordre ci-dessus prouve que le verrou est pris au bon moment ;
     * celui-ci prouve qu'il SERT a quelque chose. Les deux ensemble ferment la
     * question — un verrou pris trop tard ne protege rien, un verrou qui
     * n'exclut rien ne protege rien non plus.
     *
     * Une SECONDE connexion, donc un second PDO : c'est la seule facon
     * d'eprouver un verrou. Elle recoit un `lock_timeout` court et tente un
     * `INSERT` ; l'echec attendu est un depassement de delai, preuve que
     * `ACCESS EXCLUSIVE` entre bien en conflit avec le `ROW EXCLUSIVE` que
     * prend tout `INSERT`.
     *
     * Ignore sous SQLite, et pas par commodite : la base de test y est
     * `:memory:`, ou chaque connexion ouvre sa PROPRE base. Deux connexions ne
     * partagent donc aucun fichier, et le verrouillage de fichier de SQLite
     * n'a rien a verrouiller. Le raisonnement SQLite est documente dans la
     * migration et couvert par le test d'ordre.
     */
    public function test_pgsql_le_verrou_bloque_une_ecriture_concurrente(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Verrou de table : PostgreSQL seulement (SQLite verrouille le fichier, et la base de test est :memory:).');
        }

        // Une connexion distincte vers la MEME base.
        config(['database.connections.pgsql_concurrent' => config('database.connections.pgsql')]);
        $concurrente = DB::connection('pgsql_concurrent');
        $concurrente->statement("SET lock_timeout = '250ms'");

        // Le verrou que prend la migration, pris ici a la main.
        DB::statement('LOCK TABLE "password_reset_tokens" IN ACCESS EXCLUSIVE MODE');

        try {
            $concurrente->table('password_reset_tokens')->insert([
                'user_id' => null,
                'email' => 'concurrent@exemple.test',
                'token' => Hash::make('jeton-concurrent'),
                'created_at' => now(),
            ]);

            $this->fail("l'ecriture concurrente aurait du etre BLOQUEE par le verrou");
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()),
                "l'echec doit etre un depassement de delai de verrou, et non une autre erreur");
        } finally {
            $concurrente->disconnect();
        }
    }

    public function test_down_REFUSE_quand_deux_tokens_partagent_un_email(): void
    {
        // LE point du `down()` fail-closed exige par MASTER. La forme
        // anterieure indexe par email : deux tokens pour une meme adresse n'y
        // tiennent pas. Redescendre en silence en perdrait un, et personne ne
        // saurait lequel.
        $org = Organization::factory()->create();
        $a = User::factory()->create(['organization_id' => $org->id, 'email' => 'collision-a@exemple.test']);
        $b = User::factory()->create(['organization_id' => $org->id, 'email' => 'collision-b@exemple.test']);

        Password::broker()->createToken($a);
        Password::broker()->createToken($b);

        // L'etat que seul T1727 rend possible : une adresse, deux tokens.
        DB::table('password_reset_tokens')->where('user_id', $b->id)->update(['email' => 'collision-a@exemple.test']);

        try {
            $this->migration()->down();
            $this->fail('down() devait REFUSER plutot que de perdre un token');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('collision-a@exemple.test', $e->getMessage(),
                "le refus doit NOMMER l'adresse en cause");
        }

        // Et il a refuse AVANT de toucher quoi que ce soit.
        $this->assertTrue(Schema::hasColumn('password_reset_tokens', 'user_id'),
            'la table est intacte');
        $this->assertSame(2, DB::table('password_reset_tokens')->count());
    }
}
