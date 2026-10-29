<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Rules\UniqueEmailInOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1729 (A4) — l'email est unique PAR ORGANIZATION.
 *
 * ## Le point de non-retour
 *
 * A1 (T1726) a rendu l'Organization d'un compte immuable, A2 (T1727) a fait
 * qu'un token de reinitialisation designe un compte, A3 (T1728) qu'une
 * connexion designe un compte. Les trois ont ete ecrites POUR cet etat sans
 * pouvoir l'atteindre : leurs tests d'homonymie devaient poser l'adresse
 * partagee en memoire (T1727) ou retirer l'index le temps d'un test (T1728).
 *
 * Le premier test de ce fichier est donc celui qu'on attendait depuis trois
 * TASKs : **deux comptes, meme adresse, Organizations differentes, crees
 * normalement**.
 *
 * ## Les trois index, et ce que chacun interdit
 *
 * | index | interdit |
 * |---|---|
 * | `users_organization_email_unique` | deux fois la meme adresse DANS une Organization |
 * | `users_email_orphan_unique` | deux fois la meme adresse parmi les ORPHELINS |
 * | `users_email_lower_index` | rien — il remplace le chemin de LECTURE que l'unique global offrait |
 *
 * Les deux premiers portent sur `LOWER(email)` : l'unicite est LOGIQUE, sans
 * distinction de casse (arbitrage MASTER D3).
 */
class TASK1729UniqueEmailPerOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'a4-a', 'is_active' => true, 'is_public' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'a4-b', 'is_active' => true, 'is_public' => true]);
    }

    private function compte(?Organization $org, string $email): User
    {
        return User::factory()->create([
            'organization_id' => $org?->getKey(),
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    // ── 1. Le schema ────────────────────────────────────────────────────────

    public function test_les_trois_index_sont_en_place_et_l_unique_global_a_disparu(): void
    {
        $noms = collect(Schema::getIndexes('users'))->pluck('name');

        $this->assertTrue($noms->contains('users_organization_email_unique'));
        $this->assertTrue($noms->contains('users_email_orphan_unique'));
        $this->assertTrue($noms->contains('users_email_lower_index'),
            "l'index de LECTURE remplace le chemin d'acces que l'unique global offrait");
        $this->assertFalse($noms->contains('users_email_unique'),
            "l'unicite GLOBALE a disparu : c'est tout l'objet de A4");
    }

    // ── 2. Ce que A4 OUVRE ──────────────────────────────────────────────────

    public function test_deux_comptes_de_meme_email_dans_DEUX_Organizations_sont_acceptes(): void
    {
        // LE test attendu depuis trois TASKs, et il n'a plus besoin d'aucun
        // artifice : ni adresse posee en memoire (T1727), ni index retire le
        // temps du test (T1728). Deux comptes, crees normalement.
        $a = $this->compte($this->orgA, 'homonyme@exemple.test');
        $b = $this->compte($this->orgB, 'homonyme@exemple.test');

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, User::query()->whereRaw('LOWER(email) = ?', ['homonyme@exemple.test'])->count());
        $this->assertSame($this->orgA->id, $a->organization_id);
        $this->assertSame($this->orgB->id, $b->organization_id);
    }

    // ── 3. Ce que A4 continue d'interdire ───────────────────────────────────

    public function test_deux_comptes_de_meme_email_dans_la_MEME_Organization_sont_refuses_par_la_base(): void
    {
        // Par la BASE, pas par la validation : c'est la garantie, et elle tient
        // meme si une surface oublie la regle.
        $this->compte($this->orgA, 'collision@exemple.test');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->compte($this->orgA, 'collision@exemple.test');
    }

    public function test_la_casse_ne_cree_pas_un_second_compte_dans_la_meme_Organization(): void
    {
        // Arbitrage MASTER D3 : unicite LOGIQUE. `/admin/users` enregistrait
        // sans `lowercase`, et un composite sensible a la casse aurait laisse
        // `Alice@x` et `alice@x` coexister DANS un tenant — deux comptes pour
        // une seule boite.
        $this->compte($this->orgA, 'casse@exemple.test');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->compte($this->orgA, 'CASSE@Exemple.TEST');
    }

    public function test_deux_ORPHELINS_de_meme_email_sont_refuses(): void
    {
        // L'index partiel. Sans lui, le composite seul les accepterait : dans
        // un index unique, les NULL sont DISTINCTS — mesure sur les deux
        // moteurs. Et deux orphelins homonymes seraient deux comptes MUETS,
        // le login global refusant l'ambiguite par conception.
        $this->compte(null, 'orphelin@exemple.test');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->compte(null, 'orphelin@exemple.test');
    }

    public function test_un_orphelin_et_un_membre_peuvent_partager_une_adresse(): void
    {
        // L'autre sens : l'index partiel ne vise QUE les orphelins entre eux.
        $orphelin = $this->compte(null, 'mixte@exemple.test');
        $membre = $this->compte($this->orgA, 'mixte@exemple.test');

        $this->assertNull($orphelin->fresh()->organization_id);
        $this->assertSame($this->orgA->id, $membre->fresh()->organization_id);
    }

    // ── 4. Les cinq portes de creation / modification ───────────────────────

    public function test_l_inscription_web_accepte_un_homonyme_d_une_autre_organization(): void
    {
        $this->compte($this->orgB, 'inscrit@exemple.test');

        $this->post('/org/'.$this->orgA->slug.'/register', $this->inscription(['email' => 'inscrit@exemple.test']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, User::query()
            ->where('organization_id', $this->orgA->id)
            ->whereRaw('LOWER(email) = ?', ['inscrit@exemple.test'])
            ->count());
    }

    public function test_l_inscription_web_refuse_un_doublon_dans_la_MEME_organization(): void
    {
        $this->compte($this->orgA, 'deja@exemple.test');

        $this->post('/org/'.$this->orgA->slug.'/register', $this->inscription(['email' => 'deja@exemple.test']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['deja@exemple.test'])->count());
    }

    public function test_l_api_accepte_un_homonyme_et_refuse_un_doublon(): void
    {
        $defaut = Organization::factory()->create(['slug' => 'a4-defaut', 'is_active' => true, 'is_default' => true]);
        $this->compte($this->orgB, 'api-a4@exemple.test');

        // Homonyme d'un AUTRE tenant : accepte (l'API cree dans l'Organization
        // par defaut, donc ailleurs que orgB).
        $this->postJson('/api/auth/register', $this->inscriptionApi(['email' => 'api-a4@exemple.test']))
            ->assertCreated();

        // Second essai : meme Organization d'accueil, donc doublon.
        $this->postJson('/api/auth/register', $this->inscriptionApi([
            'email' => 'api-a4@exemple.test',
            'phone' => '+33 6 00 00 00 02',
        ]))->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(1, User::query()
            ->where('organization_id', $defaut->id)
            ->whereRaw('LOWER(email) = ?', ['api-a4@exemple.test'])
            ->count());
    }

    public function test_admin_users_normalise_la_casse_et_borne_au_tenant(): void
    {
        $defaut = Organization::factory()->create(['slug' => 'a4-admin', 'is_active' => true, 'is_default' => true]);
        $admin = $this->compte($defaut, 'admin-a4@exemple.test');
        $admin->forceFill(['is_admin' => true])->save();

        // D3 : `lowercase` est desormais applique ici aussi. Une adresse en
        // majuscules est donc REFUSEE a la saisie, au lieu d'etre enregistree
        // telle quelle et de contourner le composite par la casse.
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Majuscules',
            'email' => 'MAJUSCULES@Exemple.test',
            'password' => 'motdepasse1729',
            'password_confirmation' => 'motdepasse1729',
            'points' => 0,
        ])->assertSessionHasErrors('email');

        // En minuscules : accepte.
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Minuscules',
            'email' => 'majuscules@exemple.test',
            'password' => 'motdepasse1729',
            'password_confirmation' => 'motdepasse1729',
            'points' => 0,
        ])->assertSessionHas('success');

        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['majuscules@exemple.test'])->count());
    }

    public function test_la_modification_de_profil_est_bornee_a_son_tenant(): void
    {
        $moi = $this->compte($this->orgA, 'moi-a4@exemple.test');
        $voisin = $this->compte($this->orgA, 'voisin-a4@exemple.test');
        $ailleurs = $this->compte($this->orgB, 'ailleurs-a4@exemple.test');

        // Prendre l'adresse d'un VOISIN du meme tenant : refuse.
        $this->actingAs($moi)->put(route('profile.update'), $this->profil($moi, ['email' => $voisin->email]))
            ->assertSessionHasErrors('email');

        // Prendre celle de quelqu'un d'une AUTRE Organization : accepte.
        $this->actingAs($moi)->put(route('profile.update'), $this->profil($moi, ['email' => $ailleurs->email]))
            ->assertSessionHasNoErrors();

        $this->assertSame($ailleurs->email, $moi->fresh()->email);
    }

    // ── 5. D1 — la suppression d'Organization ───────────────────────────────

    public function test_la_suppression_est_REFUSEE_si_elle_rend_un_compte_ambigu(): void
    {
        // Arbitrage MASTER D1, et la garde est plus LARGE que l'index : elle
        // refuse aussi quand l'homonyme est encore RATTACHE ailleurs. Le tort
        // n'est pas la contrainte, c'est qu'un orphelin n'a plus qu'une porte —
        // le login global — et qu'elle refuse l'ambiguite (T1728).
        $superAdmin = $this->superAdmin();
        $membreA = $this->compte($this->orgA, 'partage@exemple.test');
        $this->compte($this->orgB, 'partage@exemple.test');

        $this->actingAs($superAdmin)
            ->delete(route('admin.organizations.destroy', $this->orgA))
            ->assertSessionHas('error');

        // Rien n'a bouge : ni l'Organization, ni le rattachement.
        $this->assertSame(1, Organization::query()->withTrashed()->whereKey($this->orgA->id)->count());
        $this->assertSame($this->orgA->id, $membreA->fresh()->organization_id);
    }

    public function test_le_refus_NOMME_les_adresses_en_cause(): void
    {
        // Un refus fail-closed muet se contourne par depit : l'operateur doit
        // savoir SUR QUOI agir.
        $superAdmin = $this->superAdmin();
        $this->compte($this->orgA, 'nommee@exemple.test');
        $this->compte($this->orgB, 'nommee@exemple.test');

        $this->actingAs($superAdmin)->delete(route('admin.organizations.destroy', $this->orgA));

        $this->assertStringContainsString('nommee@exemple.test', session('error'));
    }

    public function test_une_suppression_SANS_conflit_fonctionne_toujours(): void
    {
        // L'autre sens, sans lequel un refus inconditionnel resterait vert sur
        // les deux tests precedents tout en cassant une fonction de production.
        $superAdmin = $this->superAdmin();
        $membre = $this->compte($this->orgA, 'seul@exemple.test');

        $this->actingAs($superAdmin)
            ->delete(route('admin.organizations.destroy', $this->orgA))
            ->assertSessionHas('success');

        $this->assertNull($membre->fresh()->organization_id, 'le membre est detache, comme avant');
        $this->assertSame(0, Organization::query()->withTrashed()->whereKey($this->orgA->id)->count());
    }

    public function test_le_conflit_de_CASSE_est_vu_lui_aussi(): void
    {
        $superAdmin = $this->superAdmin();
        $this->compte($this->orgA, 'Casse@Exemple.test');
        $this->compte($this->orgB, 'casse@exemple.test');

        $this->actingAs($superAdmin)
            ->delete(route('admin.organizations.destroy', $this->orgA))
            ->assertSessionHas('error');
    }

    // ── 5bis. Le cas SYMETRIQUE : la reparation des orphelins ───────────────

    public function test_le_rattachement_en_masse_est_REFUSE_si_il_creerait_un_doublon(): void
    {
        // Defaut CREE par A4, mesure avant correctif : statut **500**, sans
        // message. L'arbitrage D1 exige « aucune erreur 500 » — il le disait de
        // la suppression d'Organization, mais c'est le meme defaut, cree par la
        // meme migration, sur un ecran voisin.
        $superAdmin = $this->superAdmin();
        $this->compte($this->orgA, 'rattache@exemple.test');
        $orphelin = $this->compte(null, 'rattache@exemple.test');

        $this->actingAs($superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $this->orgA->id,
                'confirm' => '1',
                'confirmation' => 'REASSIGN USERS',
            ])
            ->assertSessionHas('error');

        $this->assertNull($orphelin->fresh()->organization_id, "l'orphelin n'a pas bouge");
    }

    public function test_le_rattachement_en_masse_SANS_conflit_fonctionne_toujours(): void
    {
        // L'autre sens, sans lequel un refus inconditionnel resterait vert.
        $superAdmin = $this->superAdmin();
        $orphelin = $this->compte(null, 'rattache-ok@exemple.test');

        $this->actingAs($superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $this->orgA->id,
                'confirm' => '1',
                'confirmation' => 'REASSIGN USERS',
            ])
            ->assertSessionHas('success');

        $this->assertSame($this->orgA->id, $orphelin->fresh()->organization_id);
    }

    // ── 6. La regle de validation, isolee ───────────────────────────────────

    public function test_la_regle_compare_sans_casse_et_borne_au_tenant(): void
    {
        $this->compte($this->orgA, 'regle@exemple.test');
        $orphelin = $this->compte(null, 'regle-orphelin@exemple.test');

        $refuse = function (UniqueEmailInOrganization $regle, string $valeur): bool {
            $echoue = false;
            $regle->validate('email', $valeur, function () use (&$echoue) {
                $echoue = true;

                return new class
                {
                    public function translate(array $p = []): void {}
                };
            });

            return $echoue;
        };

        // Meme tenant, casse differente : refuse.
        $this->assertTrue($refuse(new UniqueEmailInOrganization($this->orgA->id), 'REGLE@exemple.TEST'));
        // Autre tenant : accepte.
        $this->assertFalse($refuse(new UniqueEmailInOrganization($this->orgB->id), 'regle@exemple.test'));
        // Orphelins entre eux : refuse.
        $this->assertTrue($refuse(new UniqueEmailInOrganization(null), 'REGLE-ORPHELIN@exemple.test'));
        // Son propre compte excepte.
        $this->assertFalse($refuse(new UniqueEmailInOrganization(null, $orphelin->id), 'regle-orphelin@exemple.test'));
    }

    // ── 7. T1727 et T1728 sur de VRAIS homonymes ────────────────────────────

    public function test_la_reinitialisation_cible_le_bon_compte_parmi_des_homonymes_REELS(): void
    {
        // T1727 mesurait ceci en posant l'adresse partagee EN MEMOIRE, faute de
        // pouvoir la poser en base. Elle peut desormais.
        $a = $this->compte($this->orgA, 'reset-a4@exemple.test');
        $b = $this->compte($this->orgB, 'reset-a4@exemple.test');

        $tokenA = \Illuminate\Support\Facades\Password::broker()->createToken($a);

        $this->assertSame(1, DB::table('password_reset_tokens')->where('user_id', $a->id)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('user_id', $b->id)->count());

        $empreinteB = $b->password;

        // Le token de A, presente sur le parcours de B : refus.
        $this->post(route('organization.password.store', $this->orgB), [
            'token' => $tokenA,
            'email' => 'reset-a4@exemple.test',
            'password' => 'nouveau-mot-de-passe-1729',
            'password_confirmation' => 'nouveau-mot-de-passe-1729',
        ])->assertSessionHasErrors('email');

        $this->assertSame($empreinteB, $b->fresh()->password);

        // Sur le sien : accepte.
        $this->post(route('organization.password.store', $this->orgA), [
            'token' => $tokenA,
            'email' => 'reset-a4@exemple.test',
            'password' => 'nouveau-mot-de-passe-1729',
            'password_confirmation' => 'nouveau-mot-de-passe-1729',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('nouveau-mot-de-passe-1729', $a->fresh()->password));
    }

    public function test_la_connexion_refuse_l_ambiguite_et_l_accepte_avec_selection(): void
    {
        // T1728 devait retirer l'index pour mesurer ceci. Plus besoin.
        $a = $this->compte($this->orgA, 'login-a4@exemple.test');
        $b = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'email' => 'login-a4@exemple.test',
            'password' => Hash::make('mot-de-passe-de-b'),
            'email_verified_at' => now(),
        ]);

        // Sans selection : refus generique, meme avec un mot de passe juste.
        $this->post(route('login'), ['email' => 'login-a4@exemple.test', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();

        // Avec selection : chacun chez lui.
        $this->post(route('login'), [
            'email' => 'login-a4@exemple.test',
            'password' => 'mot-de-passe-de-b',
            'organization' => $this->orgB->slug,
        ])->assertRedirect();
        $this->assertAuthenticatedAs($b);

        $this->post(route('logout'));

        $this->post(route('organization.login', $this->orgA), [
            'email' => 'login-a4@exemple.test',
            'password' => 'password',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($a);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function superAdmin(): User
    {
        $org = Organization::factory()->create(['slug' => 'a4-super', 'is_active' => true]);
        $admin = $this->compte($org, 'super-a4@exemple.test');

        $admin->forceFill(['is_admin' => true])->save();

        return $admin->fresh();
    }

    /**
     * Le decor rend DEUX Organizations ouvertes a l'inscription, et
     * `DefaultOrganizationResolver` rend la premiere active : le menu de
     * TASK-1714 est donc propose, et `organisation_confirmee` est REQUIS par
     * conception. On le fournit, au lieu de le contourner — c'est ce que fait
     * le formulaire reel.
     */
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
            'email' => 'api@exemple.test',
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
            // `EnsureProfileComplete` exige le telephone : sans lui la requete
            // est redirigee avant d'atteindre la validation, et le test ne
            // mesure rien.
            'phone' => '+33600000000',
        ], $surcharges);
    }
}
