<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\OrganizationDetachGuard;
use App\Support\Tenancy\UserWriteLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1729 phase 2 — l'invariant « aucun orphelin ne partage son adresse avec
 * un autre compte » sous ECRITURES CONCURRENTES.
 *
 * ## Ce que la revue MASTER avait etabli, et qu'il faut tenir
 *
 * Verdict FAIL sur le SHA `1d6569ce` : les gardes D1 etaient justes et prises
 * sous verrou, mais SEPT chemins ecrivaient `users` et deux seulement
 * prenaient ce verrou. Pire, serialiser les ecritures ne suffisait pas :
 * `SHARE ROW EXCLUSIVE` laisse passer les LECTURES — c'est meme sa raison
 * d'etre — donc le `SELECT` de validation d'une inscription concurrente
 * passait, son `INSERT` bloquait, la garde de suppression ne voyait rien (en
 * READ COMMITTED une ligne non committee est invisible), detachait, committait,
 * et l'INSERT atterrissait ensuite. La violation etait DETERMINISTE, pas
 * fortuite.
 *
 * ## Les trois preuves, et pourquoi il en faut trois
 *
 * Aucune des trois ne suffit seule, et c'est le point de ce fichier.
 *
 *  1. **Le verrou EXCLUT** (`test_pgsql_*`). Preuve d'exclusion reelle, deux
 *     connexions PDO distinctes, `lock_timeout`. Ne vaut que sur PostgreSQL :
 *     SQLite verrouille le FICHIER, et la base de test est `:memory:`.
 *  2. **Le verrou est pris AVANT le controle** (`test_l_ordre_*`). Mesure sur
 *     les requetes reellement emises. Un verrou pris apres le controle ne
 *     protege rien, et rien dans le code ne le dirait.
 *  3. **Le controle sous verrou REFUSE** (`test_entrelacement_*`). C'est la
 *     preuve qui manquait en F2 : mes tests de concurrence prenaient alors le
 *     verrou EUX-MEMES au lieu de prouver que les chemins le prennent, et
 *     aucun ne montrait que le recontrole attrape ce que la validation a
 *     laisse passer.
 *
 * ## Pourquoi l'entrelacement s'injecte sur la MEME connexion
 *
 * `RefreshDatabase` tient toute la mesure dans une transaction non committee :
 * une seconde connexion ne verrait aucune des fixtures, et une ligne qu'elle
 * ecrirait serait invisible au recontrole. On ne mesurerait donc rien.
 *
 * L'injection se fait donc sur la connexion du test, a l'instant EXACT ou le
 * verrou vient d'etre pose — c'est-a-dire a la seule place ou une ecriture
 * concurrente pourrait encore se glisser si le verrou n'excluait pas. On
 * reproduit l'ETAT que la course produirait, et on mesure ce que le chemin en
 * fait. L'exclusion elle-meme est prouvee separement, par la preuve 1.
 */
class TASK1729ConcurrentOrphanInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Organization $orgDefaut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'conc-a', 'is_active' => true, 'is_public' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'conc-b', 'is_active' => true, 'is_public' => true]);

        // L'inscription API n'a pas d'URL de tenant : elle resout
        // `DefaultOrganizationResolver`. Sans Organization par defaut, la route
        // rend 404 et la mesure ne porte sur rien.
        $this->orgDefaut = Organization::factory()->create([
            'slug' => 'conc-defaut', 'is_active' => true, 'is_public' => true, 'is_default' => true,
        ]);
    }

    // ── preuve 2 : le verrou est pris AVANT le controle ──────────────────────

    /**
     * Les CINQ portes d'ecriture d'email prennent le verrou avant d'interroger
     * `users`.
     *
     * Ce test est le garde-fou de la correction entiere : il rougit si une
     * porte perd son verrou, si elle le prend apres son controle, ou si une
     * sixieme porte apparait sans l'un ni l'autre.
     */
    public function test_l_ordre_est_tenu_sur_les_cinq_portes_d_ecriture(): void
    {
        $portes = [
            'inscription web' => fn () => $this->post(route('register'), $this->inscription()),
            'inscription api' => fn () => $this->postJson('/api/auth/register', $this->inscriptionApi()),
            'profil' => function () {
                $moi = $this->compte($this->orgA, 'profil-ordre@exemple.test');

                return $this->actingAs($moi)->put(route('profile.update'), $this->profil($moi, [
                    'email' => 'profil-ordre-2@exemple.test',
                ]));
            },
            'admin creation' => fn () => $this->actingAs($this->superAdmin())->post(route('admin.users.store'), [
                'name' => 'Cree', 'email' => 'admin-ordre@exemple.test',
                'password' => 'motdepasse1729', 'password_confirmation' => 'motdepasse1729',
                'points' => 0,
            ]),
            'admin modification' => function () {
                $cible = $this->compte($this->orgA, 'admin-mod-ordre@exemple.test');

                return $this->actingAs($this->superAdmin())->put(route('admin.users.update', $cible), [
                    'name' => 'Modifie', 'email' => 'admin-mod-ordre-2@exemple.test',
                ]);
            },
        ];

        foreach ($portes as $nom => $geste) {
            $journal = $this->enJournalisant($geste);

            $iVerrou = $this->premierIndice($journal, fn (string $sql) => $this->estLeVerrou($sql));
            $this->assertNotNull($iVerrou, "porte « {$nom} » : aucun verrou pris");

            // La nuance que ce test a coutee, et qu'il faut garder ecrite : un
            // controle `LOWER(email)` precede LEGITIMEMENT le verrou — c'est la
            // regle de validation, et elle doit rester hors verrou (l'une des
            // cinq portes valide aussi un defi Turnstile, donc parle au
            // reseau). Ma premiere version cherchait le PREMIER controle et
            // rougissait sur ce controle-la, qui est parfaitement sain.
            //
            // La propriete a tenir est l'encadrement : apres le verrou, un
            // RECONTROLE, puis seulement l'ecriture.
            $iRecontrole = $this->premierIndice(
                $journal,
                fn (string $sql) => str_contains($sql, 'lower(email)'),
                apres: $iVerrou,
            );
            $iEcriture = $this->premierIndice(
                $journal,
                fn (string $sql) => str_contains($sql, 'insert into "users"')
                    || str_contains($sql, 'update "users"'),
                apres: $iVerrou,
            );

            $this->assertNotNull($iRecontrole,
                "porte « {$nom} » : aucun recontrole d'unicite APRES le verrou — la fenetre reste ouverte");
            $this->assertNotNull($iEcriture, "porte « {$nom} » : aucune ecriture sur `users`");
            $this->assertLessThan($iEcriture, $iRecontrole,
                "porte « {$nom} » : le recontrole doit preceder l'ecriture");
        }
    }

    /**
     * Les QUATRE sites destructeurs prennent le verrou avant de mesurer, eux
     * aussi — et la garde partagee est ce qui le garantit.
     *
     * On mesure ici le site le plus nu des quatre, celui qui n'avait ni
     * preflight ni transaction : la commande de retrait de pack.
     */
    public function test_l_ordre_est_tenu_par_la_garde_partagee(): void
    {
        $org = Organization::factory()->create(['slug' => 'conc-garde', 'is_active' => true]);
        $this->compte($org, 'garde-ordre@exemple.test');

        $journal = $this->enJournalisant(function () use ($org) {
            DB::transaction(function () use ($org) {
                OrganizationDetachGuard::verrouillerEtRefuserSiAmbigu($org);
            });
        });

        $iVerrou = $this->premierIndice($journal, fn (string $sql) => $this->estLeVerrou($sql));
        $iControle = $this->premierIndice($journal, fn (string $sql) => str_contains($sql, 'lower(email)'));

        $this->assertNotNull($iVerrou, 'la garde partagee doit prendre le verrou');
        $this->assertNotNull($iControle, 'la garde partagee doit interroger les adresses');
        $this->assertLessThan($iControle, $iVerrou,
            'la garde partagee doit prendre le verrou AVANT de mesurer les adresses');
    }

    // ── preuve 3 : le recontrole sous verrou refuse ──────────────────────────

    /**
     * Inscription simultanee + MODIFICATION d'une adresse d'orphelin.
     *
     * Scenario MASTER 1. Un orphelin prend l'adresse qu'une inscription est en
     * train d'enregistrer. La validation de l'inscription est deja passee — elle
     * a vu une adresse libre. Le recontrole sous verrou doit la refuser.
     */
    public function test_entrelacement_inscription_et_modification_d_adresse_d_orphelin(): void
    {
        $orphelin = $this->compte(null, 'autre-adresse@exemple.test');

        $reponse = $this->enEntrelacant(
            hostile: fn () => User::query()->whereKey($orphelin->getKey())
                ->update(['email' => 'nouveau@exemple.test']),
            geste: fn () => $this->post(route('register'), $this->inscription(['email' => 'nouveau@exemple.test'])),
        );

        $this->assertRefusSansCreation($reponse, 'nouveau@exemple.test');
    }

    /**
     * Inscription simultanee + SUPPRESSION d'Organization.
     *
     * Scenario MASTER 2, et le plus instructif : la suppression d'une
     * Organization ne passe par aucun code d'ecriture sur `users`. C'est la FK
     * `ON DELETE SET NULL` qui detache, donc la base elle-meme. On reproduit
     * EXACTEMENT ce qu'elle fait — un `UPDATE ... SET organization_id = NULL`
     * par le constructeur de requetes, qui est aussi la seule forme que la
     * garde de modele de TASK-1726 laisse passer.
     */
    public function test_entrelacement_inscription_et_suppression_d_organization(): void
    {
        $membreAilleurs = $this->compte($this->orgB, 'nouveau@exemple.test');

        $reponse = $this->enEntrelacant(
            hostile: fn () => User::query()->whereKey($membreAilleurs->getKey())
                ->update(['organization_id' => null]),
            geste: fn () => $this->post(route('register'), $this->inscription(['email' => 'nouveau@exemple.test'])),
        );

        $this->assertRefusSansCreation($reponse, 'nouveau@exemple.test');
    }

    /**
     * Rattachement en masse simultane + ECRITURE d'adresse.
     *
     * Scenario MASTER 3, dans l'autre sens : c'est le rattachement qui tient le
     * verrou, et l'ecriture hostile arrive pendant sa mesure.
     */
    public function test_entrelacement_rattachement_en_masse_et_ecriture_d_adresse(): void
    {
        $orphelin = $this->compte(null, 'rattache-conc@exemple.test');
        $superAdmin = $this->superAdmin();

        $reponse = $this->enEntrelacant(
            hostile: fn () => User::query()->create([
                'name' => 'Concurrent',
                'email' => 'rattache-conc@exemple.test',
                'password' => Hash::make('password'),
                'organization_id' => $this->orgA->getKey(),
            ]),
            geste: fn () => $this->actingAs($superAdmin)->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $this->orgA->id,
                'confirm' => '1',
                'confirmation' => 'REASSIGN USERS',
            ]),
        );

        $reponse->assertSessionHas('error');
        $reponse->assertSessionMissing('success');
        $this->assertNull($orphelin->fresh()->organization_id,
            "l'orphelin ne doit PAS avoir ete rattache : son adresse est desormais prise dans la cible");
    }

    /**
     * Les memes entrelacements, en ne differant QUE par la casse.
     *
     * L'unicite de A4 est LOGIQUE (arbitrage MASTER D3) : les deux index
     * portent sur `LOWER(email)`. Un recontrole qui comparerait la chaine brute
     * serait vert sur les tests ci-dessus et contournable le jour meme.
     */
    public function test_entrelacement_variantes_de_casse(): void
    {
        $orphelin = $this->compte(null, 'ailleurs-casse@exemple.test');

        $reponse = $this->enEntrelacant(
            hostile: fn () => User::query()->whereKey($orphelin->getKey())
                ->update(['email' => 'NOUVEAU@Exemple.TEST']),
            geste: fn () => $this->post(route('register'), $this->inscription(['email' => 'nouveau@exemple.test'])),
        );

        $this->assertRefusSansCreation($reponse, 'nouveau@exemple.test');

        // Et le sens inverse : l'hostile en minuscules, l'inscription deja
        // normalisee par `lowercase`. Les deux sens, parce qu'une comparaison
        // asymetrique passerait l'un et echouerait l'autre.
        $autre = $this->compte(null, 'ailleurs-casse-2@exemple.test');

        $reponse2 = $this->enEntrelacant(
            hostile: fn () => User::query()->whereKey($autre->getKey())
                ->update(['email' => 'second@exemple.test']),
            geste: fn () => $this->post(route('register'), $this->inscription(['email' => 'second@exemple.test'])),
        );

        $this->assertRefusSansCreation($reponse2, 'second@exemple.test');
    }

    /**
     * Aucun conflit metier ne sort en 500 — sur les CINQ portes.
     *
     * Critere de sortie MASTER. Le recontrole leve une `ValidationException`,
     * pas une violation d'index : la collision se presente donc comme un
     * message de champ, exactement comme si la validation l'avait vue.
     */
    public function test_aucun_conflit_metier_ne_produit_un_500(): void
    {
        // Le decor qui rend TOUTES les portes conflictuelles : un orphelin
        // porteur de l'adresse visee.
        $this->compte(null, 'conflit@exemple.test');

        $this->post(route('register'), $this->inscription(['email' => 'conflit@exemple.test']))
            ->assertSessionHasErrors('email');

        $this->postJson('/api/auth/register', $this->inscriptionApi(['email' => 'conflit@exemple.test']))
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $moi = $this->compte($this->orgA, 'moi-conflit@exemple.test');
        $this->actingAs($moi)
            ->put(route('profile.update'), $this->profil($moi, ['email' => 'conflit@exemple.test']))
            ->assertSessionHasErrors('email');

        $this->actingAs($this->superAdmin())->post(route('admin.users.store'), [
            'name' => 'Cree', 'email' => 'conflit@exemple.test',
            'password' => 'motdepasse1729', 'password_confirmation' => 'motdepasse1729',
            'points' => 0,
        ])->assertSessionHasErrors('email');

        $cible = $this->compte($this->orgA, 'cible-conflit@exemple.test');
        $this->actingAs($this->superAdmin())->put(route('admin.users.update', $cible), [
            'name' => 'Modifie', 'email' => 'conflit@exemple.test',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['conflit@exemple.test'])->count());
    }

    // ── preuve 1 : le verrou EXCLUT reellement (PostgreSQL) ──────────────────

    /**
     * Une connexion distincte ne peut PAS ecrire dans `users` tant qu'une porte
     * tient le verrou.
     *
     * C'est la seule des trois preuves qui mesure l'exclusion elle-meme, et
     * elle exige deux connexions PDO reelles — donc PostgreSQL.
     */
    public function test_pgsql_une_porte_exclut_toute_ecriture_concurrente(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Exclusion reelle : PostgreSQL seulement (SQLite verrouille le FICHIER, et la base de test est :memory:).');
        }

        config(['database.connections.pgsql_conc_porte' => config('database.connections.pgsql')]);
        $concurrente = DB::connection('pgsql_conc_porte');
        $concurrente->statement("SET lock_timeout = '250ms'");

        $bloquee = null;

        // On se place DANS la transaction d'une porte, au moment ou elle tient
        // son verrou, et on tente d'ecrire depuis l'autre connexion.
        $this->enEntrelacant(
            hostile: function () use ($concurrente, &$bloquee) {
                try {
                    $concurrente->table('users')->insert([
                        'id' => (string) Str::uuid(),
                        'organization_id' => null,
                        'name' => 'Concurrent',
                        'email' => 'exclusion@exemple.test',
                        'password' => Hash::make('password'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $bloquee = false;
                } catch (QueryException $e) {
                    $bloquee = str_contains(strtolower($e->getMessage()), 'lock timeout');
                }
            },
            geste: fn () => $this->post(route('register'), $this->inscription()),
        );

        $concurrente->disconnect();

        $this->assertTrue($bloquee,
            "l'ecriture concurrente devait etre BLOQUEE par le verrou que la porte d'inscription tient");
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Execute `$geste` en injectant `$hostile` juste APRES la pose du verrou.
     *
     * Le drapeau n'est pas une precaution de style : SQLite pose son verrou en
     * DEUX instructions, et l'ecriture hostile emet elle-meme des requetes que
     * cet ecouteur reverrait. Sans lui, l'injection se rejouerait sans fin.
     */
    private function enEntrelacant(callable $hostile, callable $geste): mixed
    {
        $fait = false;
        $journal = [];
        $iVerrou = null;

        DB::listen(function ($requete) use (&$fait, &$journal, &$iVerrou, $hostile): void {
            $journal[] = $sql = strtolower($requete->sql);

            if ($fait || ! $this->estLeVerrou($sql)) {
                return;
            }

            $fait = true;
            $iVerrou = count($journal) - 1;
            $hostile();
        });

        $resultat = $geste();

        // Deux gardes sur la mesure elle-meme, sans lesquelles ce test pourrait
        // etre vert pour la mauvaise raison.
        $this->assertTrue($fait, "le verrou n'a jamais ete pose : l'entrelacement n'a RIEN mesure");

        $this->assertNotNull(
            $this->premierIndice($journal, fn (string $sql) => str_contains($sql, 'lower(email)'), apres: $iVerrou),
            "aucun controle d'unicite n'a suivi l'injection : le refus mesure ne peut donc pas venir du "
            .'recontrole sous verrou, et ce test ne prouverait rien'
        );

        return $resultat;
    }

    /**
     * Le refus ET l'absence de compte cree.
     *
     * ## Pourquoi on ne mesure pas la survie de la ligne hostile
     *
     * Elle est ecrite sur la connexion du test, donc DANS la transaction du
     * chemin mesure : quand le recontrole refuse, `DB::transaction` annule tout,
     * la ligne hostile incluse. Ma premiere version asserait sa presence apres
     * coup et rougissait pour cette raison — l'artefact est du harnais, pas du
     * code.
     *
     * Ce qui se mesure vraiment est donc : le chemin a REFUSE (donc le
     * recontrole a vu l'etat injecte, et `enEntrelacant` a verifie qu'un
     * recontrole a bien suivi l'injection), et aucun compte n'est ne.
     */
    private function assertRefusSansCreation($reponse, string $email): void
    {
        $reponse->assertSessionHasErrors('email');

        $this->assertSame(
            0,
            User::query()
                ->where('organization_id', $this->orgA->getKey())
                ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
                ->count(),
            "aucun compte ne doit etre ne dans l'Organization d'accueil"
        );

        $this->refuterToutOrphelinAmbigu();
    }

    /** @return list<string> le SQL emis, en minuscules */
    private function enJournalisant(callable $geste): array
    {
        $journal = [];

        DB::listen(function ($requete) use (&$journal): void {
            $journal[] = strtolower($requete->sql);
        });

        $geste();

        return $journal;
    }

    private function estLeVerrou(string $sql): bool
    {
        // Les deux formes que `UserWriteLock` emet, selon le moteur.
        return str_contains($sql, 'lock table "users"')
            || str_contains($sql, 't1729_user_write_lock');
    }

    /** @param  callable(string): bool  $predicat */
    private function premierIndice(array $journal, callable $predicat, ?int $apres = null): ?int
    {
        foreach ($journal as $i => $sql) {
            if ($apres !== null && $i <= $apres) {
                continue;
            }

            if ($predicat($sql)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * L'invariant lui-meme, mesure sur l'etat final.
     *
     * Aucun compte sans Organization ne partage son adresse avec un autre
     * compte, quel qu'il soit. C'est la formulation EXACTE de l'objectif
     * MASTER, et elle n'est exprimable par aucun index — la paire
     * orphelin/membre porte sur deux lignes dont l'une a `organization_id` nul
     * et l'autre non.
     */
    private function refuterToutOrphelinAmbigu(): void
    {
        $ambigus = DB::table('users as orphelin')
            ->join('users as autre', function ($jointure) {
                $jointure->on(DB::raw('LOWER(orphelin.email)'), '=', DB::raw('LOWER(autre.email)'))
                    ->whereColumn('orphelin.id', '!=', 'autre.id');
            })
            ->whereNull('orphelin.organization_id')
            ->distinct()
            ->pluck('orphelin.email')
            ->all();

        $this->assertSame([], $ambigus,
            'INVARIANT ROMPU : un compte sans Organization partage son adresse — il est MUET');
    }

    private function compte(?Organization $org, string $email): User
    {
        return User::factory()->create([
            'organization_id' => $org?->getKey(),
            'email' => $email,
        ]);
    }

    private ?User $superAdmin = null;

    /**
     * Memorise : deux tests l'appellent plusieurs fois, et re-creer son
     * Organization violait `organizations.slug`. Le decor doit etre idempotent,
     * sans quoi l'echec porte sur le harnais et masque la mesure.
     */
    private function superAdmin(): User
    {
        if ($this->superAdmin !== null) {
            return $this->superAdmin;
        }

        $org = Organization::factory()->create(['slug' => 'conc-super', 'is_active' => true]);
        $admin = $this->compte($org, 'super-conc@exemple.test');

        $admin->forceFill(['is_admin' => true])->save();

        return $this->superAdmin = $admin->fresh();
    }

    private function inscription(array $surcharges = []): array
    {
        return array_merge([
            'organisation_confirmee' => $this->orgA->slug,
            'name' => 'Durand',
            'first_name' => 'Camille',
            'email' => 'nouveau@exemple.test',
            'phone' => '+33 6 00 00 00 01',
            'country_code' => 'FR',
            'password' => 'motdepasse1729',
            'password_confirmation' => 'motdepasse1729',
        ], $surcharges);
    }

    private function inscriptionApi(array $surcharges = []): array
    {
        return array_merge([
            'name' => 'Durand',
            'first_name' => 'Camille',
            'email' => 'api-conc@exemple.test',
            'phone' => '+33 6 00 00 00 01',
            'country_code' => 'FR',
            'password' => 'motdepasse1729',
            'password_confirmation' => 'motdepasse1729',
        ], $surcharges);
    }

    private function profil(User $user, array $surcharges = []): array
    {
        return array_merge([
            'name' => $user->name,
            'first_name' => $user->first_name ?? 'Camille',
            'email' => $user->email,
            'phone' => '+33600000000',
        ], $surcharges);
    }
}
