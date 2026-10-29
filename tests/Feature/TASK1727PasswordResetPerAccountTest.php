<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Users\PasswordResetAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1727 (A2) — un token de reinitialisation designe UN COMPTE.
 *
 * ## Le defaut
 *
 * `password_reset_tokens.email` etait PRIMARY KEY, et les cinq methodes du
 * depot de Laravel interrogeaient cette colonne. Deux consequences, dont une
 * deja vraie AUJOURD'HUI :
 *
 *  - a venir : deux comptes partageant une adresse ne pouvaient pas avoir deux
 *    tokens. Le second ecrasait le premier, et chaque lien ouvrait le compte
 *    rendu par `->first()` ;
 *  - deja : aucune cle etrangere ne reliait un token a un compte. Supprimer un
 *    compte laissait son token vivre sous une adresse que le compte suivant
 *    pouvait reprendre.
 *
 * ## Ce que ce fichier mesure
 *
 * L'isolation (un token ne vaut que pour son compte), le ciblage explicite des
 * trois parcours, et surtout l'autre sens : ce qui marchait doit continuer de
 * marcher. Une garde qui ne se teste que dans le sens du refus finit par tout
 * refuser.
 *
 * L'unicite globale de `users.email` n'est PAS retiree (hors perimetre, c'est
 * A4). Le test d'isolation du depot contourne donc le modele et ecrit en base
 * directement — il mesure le DEPOT, pas la contrainte.
 */
class TASK1727PasswordResetPerAccountTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'reset-a', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'reset-b', 'is_active' => true]);
    }

    private function depot()
    {
        return Password::broker()->getRepository();
    }

    // ── Le schema ────────────────────────────────────────────────────────────

    public function test_la_table_est_indexee_par_compte_et_conserve_l_email(): void
    {
        $this->assertTrue(Schema::hasColumn('password_reset_tokens', 'user_id'),
            'la cle de resolution');
        $this->assertTrue(Schema::hasColumn('password_reset_tokens', 'email'),
            "l'email est CONSERVE (condition D2) : il n'est plus la cle, il reste la trace");
        $this->assertTrue(Schema::hasColumn('password_reset_tokens', 'token'));
        $this->assertTrue(Schema::hasColumn('password_reset_tokens', 'created_at'));
    }

    public function test_un_compte_n_a_qu_un_token_en_vol(): void
    {
        $compte = User::factory()->create(['organization_id' => $this->orgA->id]);

        $this->depot()->create($compte);
        $this->travel(2)->minutes();   // sortir de la fenetre d'anti-rejeu
        $this->depot()->create($compte);

        $this->assertSame(1, DB::table('password_reset_tokens')->where('user_id', $compte->id)->count(),
            'un nouveau token REMPLACE le precedent, il ne s\'ajoute pas');
    }

    // ── 1. Isolation : le token de A ne vaut jamais pour B ───────────────────

    public function test_deux_comptes_de_meme_email_ont_deux_tokens_independants(): void
    {
        // Test ISOLE DU DEPOT, et l'isolement n'est pas un detail de confort :
        // `UNIQUE(users.email)` est une contrainte de BASE, pas une regle de
        // modele. Elle reste en place — la retirer est l'etape A4, hors du
        // perimetre de T1727 — donc deux comptes ne PEUVENT pas porter la meme
        // adresse en base aujourd'hui.
        //
        // On pose donc la question que A4 posera pour de vrai, au seul objet
        // qui doive y repondre : le depot de tokens sait-il porter DEUX lignes
        // pour une meme adresse, et les garder etanches ?
        //
        // Les deux comptes existent vraiment (la cle etrangere `user_id` l'exige)
        // avec des adresses distinctes ; l'adresse partagee n'est posee QU'EN
        // MEMOIRE, jamais enregistree. Le depot ne lit que `getKey()` et
        // `getEmailForPasswordReset()` : c'est exactement ce qu'il verra en A4.
        $a = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'homonyme-a@exemple.test']);
        $b = User::factory()->create(['organization_id' => $this->orgB->id, 'email' => 'homonyme-b@exemple.test']);

        $a->email = 'homonyme@exemple.test';
        $b->email = 'homonyme@exemple.test';

        $tokenA = $this->depot()->create($a);
        $tokenB = $this->depot()->create($b);

        // LE point de T1727 : deux lignes pour une seule adresse. La cle
        // primaire `email` l'interdisait physiquement — le second `create()`
        // aurait ecrase le premier.
        $this->assertSame(2, DB::table('password_reset_tokens')->where('email', 'homonyme@exemple.test')->count());
        $this->assertNotSame($tokenA, $tokenB);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('user_id', $a->id)->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('user_id', $b->id)->count());

        // Et l'etancheite : chaque token ne vaut QUE pour son compte.
        $this->assertTrue($this->depot()->exists($a, $tokenA));
        $this->assertTrue($this->depot()->exists($b, $tokenB));
        $this->assertFalse($this->depot()->exists($b, $tokenA), 'le token de A ne doit pas ouvrir B');
        $this->assertFalse($this->depot()->exists($a, $tokenB), "ni l'inverse");

        // Consommer celui de A ne touche pas celui de B.
        $this->depot()->delete($a);
        $this->assertFalse($this->depot()->exists($a, $tokenA));
        $this->assertTrue($this->depot()->exists($b, $tokenB), 'le token de B survit intact');
    }

    public function test_le_token_de_A_ne_reinitialise_pas_le_compte_B(): void
    {
        // Le meme invariant, mais par HTTP et de bout en bout. Deux emails
        // distincts suffisent ici : c'est le ciblage qu'on mesure.
        $a = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'cible-a@exemple.test']);
        $b = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'cible-b@exemple.test']);

        $empreinteB = $b->password;
        $tokenA = Password::broker()->createToken($a);

        $this->post(route('password.store'), [
            'token' => $tokenA,
            'email' => $b->email,
            'password' => 'nouveau-mot-de-passe-1727',
            'password_confirmation' => 'nouveau-mot-de-passe-1727',
        ])->assertSessionHasErrors('email');

        $this->assertSame($empreinteB, $b->fresh()->password, 'B ne doit pas avoir change de mot de passe');
        $this->assertTrue(Hash::check('password', $a->fresh()->password), 'et A non plus');
    }

    // ── 2. Les parcours : global, Organization, admin ────────────────────────

    public function test_le_parcours_global_fonctionne_toujours(): void
    {
        $compte = User::factory()->create(['organization_id' => null, 'email' => 'global@exemple.test']);

        $this->post(route('password.email'), ['email' => $compte->email])
            ->assertSessionHas('status');

        $ligne = DB::table('password_reset_tokens')->where('user_id', $compte->id)->first();
        $this->assertNotNull($ligne, 'le token est rattache au COMPTE');
        $this->assertSame($compte->email, $ligne->email);

        $token = Password::broker()->createToken($compte);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $compte->email,
            'password' => 'nouveau-mot-de-passe-1727',
            'password_confirmation' => 'nouveau-mot-de-passe-1727',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('nouveau-mot-de-passe-1727', $compte->fresh()->password));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('user_id', $compte->id)->count(),
            'le token est consomme');
    }

    public function test_le_parcours_organization_conserve_son_contexte(): void
    {
        $compte = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'membre-a@exemple.test']);

        $this->post(route('organization.password.email', $this->orgA), ['email' => $compte->email])
            ->assertSessionHas('status');

        $token = Password::broker()->createToken($compte);

        // Le retour apres succes renvoie vers la connexion DE CE tenant.
        $this->post(route('organization.password.store', $this->orgA), [
            'token' => $token,
            'email' => $compte->email,
            'password' => 'nouveau-mot-de-passe-1727',
            'password_confirmation' => 'nouveau-mot-de-passe-1727',
        ])->assertRedirect(route('organization.login', $this->orgA))->assertSessionHas('status');

        $this->assertTrue(Hash::check('nouveau-mot-de-passe-1727', $compte->fresh()->password));
    }

    public function test_le_lien_envoye_conduit_au_parcours_de_l_organization(): void
    {
        Notification::fake();

        $membre = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'lien-org@exemple.test']);
        $sansTenant = User::factory()->create(['organization_id' => null, 'email' => 'lien-global@exemple.test']);

        $this->post(route('password.email'), ['email' => $membre->email]);
        $this->post(route('password.email'), ['email' => $sansTenant->email]);

        Notification::assertSentTo($membre, \Illuminate\Auth\Notifications\ResetPassword::class,
            function ($notification) use ($membre): bool {
                $url = $notification->toMail($membre)->actionUrl;

                return str_contains($url, '/org/reset-a/reset-password/');
            });

        // Un compte SANS Organization garde le lien global : il n'y a pas de
        // `/org/{slug}` ou l'envoyer (point 6).
        Notification::assertSentTo($sansTenant, \Illuminate\Auth\Notifications\ResetPassword::class,
            function ($notification) use ($sansTenant): bool {
                $url = $notification->toMail($sansTenant)->actionUrl;

                return str_contains($url, '/reset-password/') && ! str_contains($url, '/org/');
            });
    }

    public function test_le_reset_administratif_cible_le_bon_uuid(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => true]);
        $cible = User::factory()->create(['organization_id' => $this->orgB->id, 'email' => 'cible-admin@exemple.test']);
        $autre = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'pas-la-cible@exemple.test']);

        $this->actingAs($admin)
            ->post(route('admin.users.send-password-reset', $cible))
            ->assertSessionHas('success');

        $this->assertSame(1, DB::table('password_reset_tokens')->where('user_id', $cible->id)->count(),
            "le token porte l'uuid de la ligne cliquee");
        $this->assertSame(0, DB::table('password_reset_tokens')->where('user_id', $autre->id)->count());
    }

    // ── 3. L'isolation entre tenants (D3) ───────────────────────────────────

    public function test_un_parcours_d_organization_ne_reinitialise_pas_un_compte_d_un_autre_tenant(): void
    {
        $membreB = User::factory()->create(['organization_id' => $this->orgB->id, 'email' => 'membre-b@exemple.test']);
        $empreinte = $membreB->password;

        $token = Password::broker()->createToken($membreB);

        // Un token PARFAITEMENT valide, mais presente sur le parcours d'une
        // autre Organization : refus.
        $this->post(route('organization.password.store', $this->orgA), [
            'token' => $token,
            'email' => $membreB->email,
            'password' => 'nouveau-mot-de-passe-1727',
            'password_confirmation' => 'nouveau-mot-de-passe-1727',
        ])->assertSessionHasErrors('email');

        $this->assertSame($empreinte, $membreB->fresh()->password);
    }

    public function test_une_demande_depuis_une_organization_ne_revele_pas_un_compte_d_ailleurs(): void
    {
        Notification::fake();

        $membreB = User::factory()->create(['organization_id' => $this->orgB->id, 'email' => 'discret@exemple.test']);

        $reponseAilleurs = $this->post(route('organization.password.email', $this->orgA), ['email' => $membreB->email]);
        $reponseInconnue = $this->post(route('organization.password.email', $this->orgA), ['email' => 'personne@exemple.test']);

        // LE point de D3 : les deux reponses doivent etre INDISCERNABLES.
        $reponseAilleurs->assertSessionHasErrors('email');
        $reponseInconnue->assertSessionHasErrors('email');
        $this->assertSame(
            session('errors')->get('email'),
            $reponseInconnue->baseResponse->getSession()->get('errors')->get('email'),
        );

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count(), 'aucun token ne doit naitre');
    }

    // ── 4. Comptes bannis, orphelins, tokens expires et rejoues ─────────────

    public function test_un_compte_banni_repond_comme_un_succes_sans_rien_envoyer(): void
    {
        Notification::fake();

        $banni = User::factory()->create(['organization_id' => $this->orgA->id, 'banned_at' => now()]);

        $this->post(route('password.email'), ['email' => $banni->email])
            ->assertSessionHas('status');

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_un_compte_banni_ne_peut_pas_consommer_un_token(): void
    {
        $compte = User::factory()->create(['organization_id' => $this->orgA->id]);
        $token = Password::broker()->createToken($compte);

        $compte->forceFill(['banned_at' => now()])->save();
        $empreinte = $compte->fresh()->password;

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $compte->email,
            'password' => 'nouveau-mot-de-passe-1727',
            'password_confirmation' => 'nouveau-mot-de-passe-1727',
        ])->assertSessionHasErrors('email');

        $this->assertSame($empreinte, $compte->fresh()->password);
    }

    public function test_un_compte_sans_organization_reste_servi(): void
    {
        $orphelin = User::factory()->create(['organization_id' => null, 'email' => 'orphelin@exemple.test']);

        $this->post(route('password.email'), ['email' => $orphelin->email])
            ->assertSessionHas('status');

        $this->assertSame(1, DB::table('password_reset_tokens')->where('user_id', $orphelin->id)->count());
    }

    public function test_un_token_expire_est_refuse(): void
    {
        $compte = User::factory()->create(['organization_id' => $this->orgA->id]);
        $token = Password::broker()->createToken($compte);
        $empreinte = $compte->password;

        // `expire` vaut 60 MINUTES dans `config/auth.php`.
        $this->travel(61)->minutes();

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $compte->email,
            'password' => 'nouveau-mot-de-passe-1727',
            'password_confirmation' => 'nouveau-mot-de-passe-1727',
        ])->assertSessionHasErrors('email');

        $this->assertSame($empreinte, $compte->fresh()->password);
    }

    public function test_un_token_rejoue_est_refuse(): void
    {
        $compte = User::factory()->create(['organization_id' => $this->orgA->id, 'organization_id' => null]);
        $token = Password::broker()->createToken($compte);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $compte->email,
            'password' => 'premier-mot-de-passe-1727',
            'password_confirmation' => 'premier-mot-de-passe-1727',
        ])->assertSessionHas('status');

        // Le MEME token, une seconde fois.
        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $compte->email,
            'password' => 'second-mot-de-passe-1727',
            'password_confirmation' => 'second-mot-de-passe-1727',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('premier-mot-de-passe-1727', $compte->fresh()->password),
            'le premier mot de passe doit tenir');
    }

    // ── 5. La resolution elle-meme ──────────────────────────────────────────

    public function test_le_resolveur_ne_choisit_jamais_arbitrairement(): void
    {
        $resolveur = app(PasswordResetAccountResolver::class);
        $membre = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'resolu@exemple.test']);

        // Global : trouve.
        $this->assertTrue($resolveur->comptesCibles('resolu@exemple.test')->contains($membre));
        // Borne a SON tenant : trouve.
        $this->assertTrue($resolveur->comptesCibles('resolu@exemple.test', $this->orgA)->contains($membre));
        // Borne a un AUTRE tenant : vide, pas « le premier venu ».
        $this->assertTrue($resolveur->comptesCibles('resolu@exemple.test', $this->orgB)->isEmpty());
        // Inconnu : vide.
        $this->assertTrue($resolveur->comptesCibles('inconnu@exemple.test')->isEmpty());

        // Et les credentials passes au broker portent la cle primaire.
        $this->assertSame(
            ['id' => (string) $membre->id, 'email' => $membre->email],
            $resolveur->credentials($membre),
        );
    }

    public function test_un_token_orphelin_n_ouvre_aucun_compte(): void
    {
        // Ce que la migration fabrique : une ligne conservee dont `user_id` est
        // NULL. Elle doit etre INUTILISABLE, pour tout compte.
        $compte = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'reprise@exemple.test']);
        $empreinte = $compte->password;

        DB::table('password_reset_tokens')->insert([
            'user_id' => null,
            'email' => 'reprise@exemple.test',
            'token' => Hash::make('jeton-orphelin-1727'),
            'created_at' => now(),
        ]);

        $this->post(route('password.store'), [
            'token' => 'jeton-orphelin-1727',
            'email' => 'reprise@exemple.test',
            'password' => 'nouveau-mot-de-passe-1727',
            'password_confirmation' => 'nouveau-mot-de-passe-1727',
        ])->assertSessionHasErrors('email');

        $this->assertSame($empreinte, $compte->fresh()->password);
        $this->assertSame(1, DB::table('password_reset_tokens')->whereNull('user_id')->count(),
            'conserve, et toujours la');
    }
}
