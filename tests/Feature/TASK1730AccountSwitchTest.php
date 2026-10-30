<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1730 (A5a) — passer a SON autre compte, dans une autre Organization.
 *
 * ## Ce que A5a est, et ce qu'elle n'est pas
 *
 * Depuis A4 (T1729), une meme adresse porte deux comptes dans deux
 * Organizations. A5a ajoute le GESTE qui manquait. Elle n'ajoute aucune
 * relation : pas de liaison persistante (c'est A5b), pas de fusion
 * d'identites, pas de mode persona avec retour arriere. C'est un vrai login
 * du compte cible (arbitrage MASTER D5).
 *
 * ## Le piege que ces tests existent pour attraper
 *
 * Quatre des cinq situations bloquantes ne sont PAS verifiees a la connexion :
 * elles vivent dans le groupe de middlewares `web`, donc elles ne frappent
 * qu'a la requete SUIVANTE. Une bascule naive aurait donc « reussi », detruit
 * la session d'origine, et laisse la personne echouee dans un compte
 * inutilisable sans chemin de retour. D'ou le pre-controle, et d'ou les tests
 * qui verifient qu'un refus LAISSE LA SESSION D'ORIGINE INTACTE.
 */
class TASK1730AccountSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create([
            'slug' => 'a5-a', 'is_active' => true, 'is_public' => true,
        ]);
        // `requires_account_approval` sur la cible, et ce n'est pas un detail
        // de decor : `attendApprobation()` court-circuite sur
        // `approved_at !== null`, donc SANS ce reglage un compte a `approved_at`
        // nul ne bloquerait rien et le cas « en attente d'approbation » du
        // fournisseur de donnees serait vert sans rien mesurer. Les comptes de
        // fabrique portent `approved_at`, le reglage ne les gene donc pas.
        $this->orgB = Organization::factory()->create([
            'slug' => 'a5-b', 'is_active' => true, 'is_public' => true,
            'requires_account_approval' => true,
        ]);
    }

    // ── critere 1 : le point d'entree, et ce qu'il NE montre pas ────────────

    public function test_l_ecran_de_bascule_exige_une_session(): void
    {
        $this->get(route('account.switch'))->assertRedirect(route('login'));
    }

    public function test_l_ecran_n_enumere_AUCUN_compte(): void
    {
        // Le decor est piegeux a dessein : une troisieme Organization ou cette
        // personne n'a PAS de compte, et une quatrieme ou elle en a un. L'ecran
        // doit les traiter STRICTEMENT pareil — sans quoi sa seule lecture
        // dirait ou l'adresse possede un compte.
        $orgSansCompte = Organization::factory()->create([
            'slug' => 'a5-sans', 'is_active' => true, 'is_public' => true, 'name' => 'Sans Compte',
        ]);

        $moi = $this->compte($this->orgA, 'moi@exemple.test');
        $this->compte($this->orgB, 'moi@exemple.test');

        $reponse = $this->actingAs($moi)->get(route('account.switch'))->assertOk();

        // Les deux apparaissent, donc la presence d'une Organization dans la
        // liste ne prouve rien sur l'existence d'un compte.
        $reponse->assertSee($this->orgB->name);
        $reponse->assertSee($orgSansCompte->name);

        // Et aucune adresse n'est affichee.
        $reponse->assertDontSee('moi@exemple.test');
    }

    // ── criteres 2, 6, 7 : la bascule qui reussit ──────────────────────────

    public function test_la_bascule_reussit_et_atterrit_sur_le_tenant_cible(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test');
        $cible = $this->compte($this->orgB, 'moi@exemple.test');

        $this->actingAs($moi)
            ->post(route('account.switch.store'), [
                'organization' => $this->orgB->slug,
                'password' => 'password',
            ])
            ->assertRedirect(canonicalHome($this->orgB));

        // C'est bien l'AUTRE compte, pas le mien renomme.
        $this->assertAuthenticatedAs($cible);
        $this->assertNotSame($moi->getKey(), auth()->id());
        $this->assertSame($this->orgB->getKey(), auth()->user()->organization_id);
    }

    /**
     * L'isolation ne se prouve pas par la seule regeneration de session.
     *
     * Avertissement MASTER : `regenerate()` change l'identifiant mais CONSERVE
     * le contenu. Deux cles le montrent concretement —
     * `auth.password_confirmed_at` ferait heriter le compte d'arrivee d'une
     * confirmation obtenue sur celui qu'on quitte, et `url.intended`
     * renverrait vers une page du tenant PRECEDENT. D'ou `invalidate()`.
     */
    public function test_aucune_donnee_de_session_du_tenant_precedent_ne_survit(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test');
        $this->compte($this->orgB, 'moi@exemple.test');

        $this->actingAs($moi)
            ->withSession([
                'auth.password_confirmed_at' => time(),
                'url.intended' => '/org/a5-a/dossiers',
            ])
            ->post(route('account.switch.store'), [
                'organization' => $this->orgB->slug,
                'password' => 'password',
            ])
            ->assertRedirect(canonicalHome($this->orgB));

        $this->assertNull(session('auth.password_confirmed_at'),
            'la confirmation de mot de passe du compte quitte ne doit PAS etre heritee');
        $this->assertNull(session('url.intended'),
            'une destination du tenant precedent ne doit PAS survivre a la bascule');
    }

    // ── critere 3 : un seul refus, pour toutes les causes ──────────────────

    /**
     * Les quatre causes rendent le MEME message.
     *
     * Les distinguer ferait de cet ecran l'oracle d'appartenance que T1728 a
     * refuse de faire du login : « mauvais mot de passe » confirmerait qu'un
     * compte existe dans ce tenant.
     */
    public function test_les_quatre_causes_de_refus_rendent_le_MEME_message(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test');
        $this->compte($this->orgB, 'moi@exemple.test');

        $orgSansCompte = Organization::factory()->create([
            'slug' => 'a5-vide', 'is_active' => true, 'is_public' => true,
        ]);

        $cas = [
            'mot de passe faux' => ['organization' => $this->orgB->slug, 'password' => 'mauvais'],
            'organization inconnue' => ['organization' => 'slug-qui-n-existe-pas', 'password' => 'password'],
            'adresse absente de cette organization' => ['organization' => $orgSansCompte->slug, 'password' => 'password'],
        ];

        $messages = [];

        foreach ($cas as $nom => $charge) {
            $reponse = $this->actingAs($moi)->post(route('account.switch.store'), $charge);

            $reponse->assertSessionHasErrors('password');
            $messages[$nom] = session('errors')->get('password')[0];

            // Et la session d'origine tient (critere 5).
            $this->assertAuthenticatedAs($moi);
            $this->assertSame($moi->getKey(), auth()->id(), "cas « {$nom} » : la session d'origine doit survivre");

            session()->forget('errors');
        }

        $this->assertCount(1, array_unique($messages),
            'les causes de refus doivent etre INDISCERNABLES : '.json_encode($messages, JSON_UNESCAPED_UNICODE));
    }

    // ── criteres 4 et 5 : les cinq etats bloquants, AVANT toute mutation ───

    #[DataProvider('etatsBloquants')]
    public function test_un_compte_inutilisable_est_refuse_SANS_detruire_la_session(array $attributs, string $etat): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test');
        $cible = $this->compte($this->orgB, 'moi@exemple.test');
        $cible->forceFill($attributs)->save();

        $this->actingAs($moi)
            ->post(route('account.switch.store'), [
                'organization' => $this->orgB->slug,
                'password' => 'password',
            ])
            ->assertSessionHasErrors('password');

        // LE point de ce test : la personne est toujours sur SON compte, avec
        // une session utilisable. Sans le pre-controle, elle serait authentifiee
        // sur un compte qu'aucun middleware ne laisse servir, et sans retour.
        $this->assertAuthenticatedAs($moi);
        $this->assertSame($moi->getKey(), auth()->id(), "etat « {$etat} » : la session d'origine doit survivre");
        $this->assertSame($this->orgA->getKey(), auth()->user()->organization_id);
    }

    public static function etatsBloquants(): array
    {
        return [
            'banni' => [['banned_at' => '2026-01-01 00:00:00'], 'banni'],
            'ferme volontairement' => [['banned_at' => '2026-01-01 00:00:00', 'self_closed_at' => '2026-01-01 00:00:00'], 'ferme volontairement'],
            'mot de passe d invitation a poser' => [['must_set_password' => true], 'invitation'],
            'email non verifie' => [['email_verified_at' => null], 'email non verifie'],
            'en attente d approbation' => [['approved_at' => null], 'en attente d approbation'],
        ];
    }

    // ── critere 8 : l'Organization par defaut, servie a la racine ──────────

    public function test_la_bascule_fonctionne_dans_les_DEUX_sens_avec_l_organization_par_defaut(): void
    {
        // L'organisation par defaut n'a PAS de prefixe `/org/{slug}` : elle est
        // servie a la racine. C'est le cas limite que `canonicalHome()` traite,
        // et celui qu'une construction d'URL faite a la main raterait.
        $defaut = Organization::factory()->create([
            'slug' => 'a5-defaut', 'is_active' => true, 'is_public' => true, 'is_default' => true,
        ]);

        $dansDefaut = $this->compte($defaut, 'moi@exemple.test');
        $dansB = $this->compte($this->orgB, 'moi@exemple.test');

        // Sens 1 : vers l'organisation par defaut.
        $this->actingAs($dansB)
            ->post(route('account.switch.store'), [
                'organization' => $defaut->slug,
                'password' => 'password',
            ])
            ->assertRedirect(canonicalHome($defaut));

        $this->assertAuthenticatedAs($dansDefaut);

        // Sens 2 : depuis elle.
        $this->post(route('account.switch.store'), [
            'organization' => $this->orgB->slug,
            'password' => 'password',
        ])->assertRedirect(canonicalHome($this->orgB));

        $this->assertAuthenticatedAs($dansB);
    }

    // ── critere 9 : limitation des tentatives ──────────────────────────────

    public function test_les_tentatives_sont_limitees_sans_verrouiller_le_login(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test');
        $this->compte($this->orgB, 'moi@exemple.test');

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($moi)->post(route('account.switch.store'), [
                'organization' => $this->orgB->slug,
                'password' => 'mauvais',
            ])->assertSessionHasErrors('password');
            session()->forget('errors');
        }

        // La sixieme est bornee par le compteur, pas par le mot de passe.
        $this->actingAs($moi)->post(route('account.switch.store'), [
            'organization' => $this->orgB->slug,
            'password' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertAuthenticatedAs($moi);

        // ET le login ordinaire n'a PAS ete verrouille au passage : un compteur
        // partage aurait fait de cet ecran un moyen de bloquer la connexion.
        $this->post(route('logout'));

        $this->post(route('login'), [
            'email' => 'moi@exemple.test',
            'organization' => $this->orgA->slug,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($moi);
    }

    // ── critere 10 : rien n'est deplace, rien n'est migre ──────────────────

    public function test_la_bascule_ne_DEPLACE_aucun_compte(): void
    {
        // T1726 a rendu `organization_id` immuable. La bascule change de
        // COMPTE ; elle ne doit jamais changer l'Organization d'un compte, ni
        // par ecriture directe ni par effet de bord.
        $moi = $this->compte($this->orgA, 'moi@exemple.test');
        $cible = $this->compte($this->orgB, 'moi@exemple.test');

        $this->actingAs($moi)->post(route('account.switch.store'), [
            'organization' => $this->orgB->slug,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertSame($this->orgA->getKey(), $moi->fresh()->organization_id);
        $this->assertSame($this->orgB->getKey(), $cible->fresh()->organization_id);
    }

    public function test_basculer_vers_SON_PROPRE_compte_est_refuse(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test');

        $this->actingAs($moi)->post(route('account.switch.store'), [
            'organization' => $this->orgA->slug,
            'password' => 'password',
        ])->assertSessionHasErrors('organization');

        $this->assertAuthenticatedAs($moi);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function compte(Organization $org, string $email): User
    {
        return User::factory()->create([
            'organization_id' => $org->getKey(),
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }
}
