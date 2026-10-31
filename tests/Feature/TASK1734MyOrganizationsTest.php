<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AccountLinkConfirmationRequested;
use App\Notifications\AccountLinkEstablished;
use App\Services\Users\AccountLinkEmailProof;
use App\Services\Users\AccountLinkService;
use App\Services\Users\AccountSwitchPreflight;
use App\Services\Users\SiblingAccountsPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * TASK-1734 — « Mes organisations » : bascule en un clic, premiere
 * association par la preuve de la boite.
 *
 * ## L'invariant que TOUTE la TASK sert, mesure en premier
 *
 * **Jamais d'acces sur la seule egalite d'email.** Un frere verifie mais NON
 * lie qui clique « passer » ne bascule pas : il recoit un email, et sa
 * session ne bouge pas. Le lien `account_links` reste l'unique autorisation
 * de bascule sans mot de passe — la preuve par la boite ne fait que le creer.
 *
 * ## Ce que le jeton doit tenir
 *
 * Usage unique (atomique), quinze minutes, brule s'il est presente par la
 * mauvaise session, INTACT face aux GET repetes — les scanners de boite
 * prechargent les liens, seul le POST du bouton consomme.
 */
class TASK1734MyOrganizationsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        // `is_public` n'est PAS force : la fabrique rend des Organizations
        // PRIVEES, et c'est voulu — le panneau doit les montrer a leurs
        // propres comptes, la ou le selecteur public de T1728 les tairait.
        $this->orgA = Organization::factory()->create(['slug' => 'myorg-a']);
        $this->orgB = Organization::factory()->create(['slug' => 'myorg-b']);
    }

    // ── L'invariant central ────────────────────────────────────────────────

    public function test_un_frere_verifie_NON_lie_ne_bascule_pas_il_recoit_un_email(): void
    {
        Notification::fake();

        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $frere = $this->compte($this->orgB, 'marie@exemple.test');

        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
            ->assertRedirect(route('my-organizations.sent'));

        // La session n'a PAS bouge, aucun lien n'est ne : l'egalite d'email
        // n'a rien ouvert.
        $this->assertAuthenticatedAs($moi);
        $this->assertSame(0, AccountLink::query()->count());

        Notification::assertSentTo($moi, AccountLinkConfirmationRequested::class);
    }

    public function test_l_ecran_email_envoye_nomme_l_organisation_et_refuse_l_acces_direct(): void
    {
        Notification::fake();

        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $this->compte($this->orgB, 'marie@exemple.test');

        $this->actingAs($moi)
            ->followingRedirects()
            ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
            ->assertOk()
            ->assertSee('data-myorg-sent', false)
            ->assertSee($this->orgB->name);

        // L'URL tapee a la main, sans envoi qui precede : rien a montrer.
        $this->actingAs($moi)
            ->get(route('my-organizations.sent'))
            ->assertRedirect(route('profile.edit'));
    }

    // ── La bascule par lien : un clic, rien a saisir ───────────────────────

    public function test_la_bascule_par_lien_se_fait_en_un_clic_sans_mot_de_passe(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');
        $this->lienDirect($moi, $cible);

        // AUCUN champ poste : le clic du menu, et rien d'autre.
        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
            ->assertRedirect(canonicalHome($this->orgB));

        $this->assertAuthenticatedAs($cible);

        // La session d'arrivee n'herite d'AUCUNE confirmation de mot de
        // passe : l'invalidation de T1730 a fait son travail.
        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_la_bascule_fonctionne_meme_quand_les_adresses_ont_diverge_depuis_la_liaison(): void
    {
        // Un lien cree par l'ancien formulaire entre adresses DIFFERENTES :
        // il doit vivre dans le panneau et basculer en un clic — le lien est
        // l'autorisation, pas l'adresse.
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'autre@exemple.test');
        $this->lienDirect($moi, $cible);

        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
            ->assertRedirect(canonicalHome($this->orgB));

        $this->assertAuthenticatedAs($cible);
    }

    public function test_un_lien_revoque_avant_le_clic_refuse_sans_rien_dire(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');
        $lien = $this->lienDirect($moi, $cible);

        $lien->delete();

        // Sans lien et sans jeton emis, le POST retombe sur la voie « email »
        // UNIQUEMENT si le frere est eligible — ici il l'est, donc l'envoi
        // part. Pour mesurer le refus de la voie « lien », on bloque aussi la
        // voie email en retirant la verification du frere.
        $cible->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
            ->assertStatus(422)
            ->assertSee(trans('auth.myorg_refused'));

        $this->assertAuthenticatedAs($moi);
    }

    public function test_la_decision_de_bascule_est_prise_SOUS_transaction(): void
    {
        // Lecon T1731 : un test a deux connexions ne prouve RIEN sous
        // RefreshDatabase. On mesure a la place que le preflight — la
        // decision — s'execute pendant que la transaction (donc le verrou de
        // `lienVerrouilleEntre`) est encore detenue.
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');
        $this->lienDirect($moi, $cible);

        $espion = new class extends AccountSwitchPreflight
        {
            public static ?int $niveauALaDecision = null;

            public function motifDeRefus(User $cible): ?string
            {
                self::$niveauALaDecision = DB::transactionLevel();

                return parent::motifDeRefus($cible);
            }
        };

        $this->app->instance(AccountSwitchPreflight::class, $espion);

        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
            ->assertRedirect(canonicalHome($this->orgB));

        $this->assertNotNull($espion::$niveauALaDecision);
        $this->assertGreaterThan(0, $espion::$niveauALaDecision,
            'le preflight doit etre interroge PENDANT la transaction qui detient le verrou');
    }

    // ── Le jeton : usage unique, 15 minutes, lie a la session source ──────

    public function test_le_parcours_complet_cree_le_lien_et_bascule(): void
    {
        Notification::fake();

        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');

        $jeton = app(AccountLinkEmailProof::class)->emettre($moi, $cible);

        // Le GET montre le bouton et nomme l'organisation cible.
        $this->actingAs($moi)
            ->get(route('my-organizations.confirm', ['token' => $jeton]))
            ->assertOk()
            ->assertSee('data-myorg-confirm', false)
            ->assertSee($this->orgB->name);

        // Le POST consomme, cree, bascule.
        $this->actingAs($moi)
            ->post(route('my-organizations.confirm.store', ['token' => $jeton]))
            ->assertRedirect(canonicalHome($this->orgB));

        $this->assertAuthenticatedAs($cible);
        $this->assertNotNull(app(AccountLinkService::class)->lienEntre($moi, $cible));
        Notification::assertSentTo($moi, AccountLinkEstablished::class);
    }

    public function test_le_GET_du_lien_ne_consomme_JAMAIS_le_jeton(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');

        $jeton = app(AccountLinkEmailProof::class)->emettre($moi, $cible);

        // Un scanner de boite precharge le lien — trois fois plutot qu'une.
        foreach (range(1, 3) as $passage) {
            $this->actingAs($moi)
                ->get(route('my-organizations.confirm', ['token' => $jeton]))
                ->assertOk();
        }

        // Le bouton vit toujours.
        $this->actingAs($moi)
            ->post(route('my-organizations.confirm.store', ['token' => $jeton]))
            ->assertRedirect(canonicalHome($this->orgB));

        $this->assertAuthenticatedAs($cible);
    }

    public function test_le_jeton_ne_sert_qu_une_fois(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');

        $jeton = app(AccountLinkEmailProof::class)->emettre($moi, $cible);

        $this->actingAs($moi)
            ->post(route('my-organizations.confirm.store', ['token' => $jeton]))
            ->assertRedirect(canonicalHome($this->orgB));

        // Rejoue — par la session d'arrivee, comme le ferait un historique de
        // navigateur. Refus, et toujours UN seul lien.
        $this->actingAs($cible)
            ->post(route('my-organizations.confirm.store', ['token' => $jeton]))
            ->assertStatus(422);

        $this->assertSame(1, AccountLink::query()->count());
    }

    public function test_le_jeton_expire_apres_quinze_minutes(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');

        $jeton = app(AccountLinkEmailProof::class)->emettre($moi, $cible);

        $this->travel(AccountLinkEmailProof::TTL_SECONDES + 1)->seconds();

        $this->actingAs($moi)
            ->get(route('my-organizations.confirm', ['token' => $jeton]))
            ->assertOk()
            ->assertSee('data-myorg-invalid', false);

        $this->actingAs($moi)
            ->post(route('my-organizations.confirm.store', ['token' => $jeton]))
            ->assertStatus(422);

        $this->assertSame(0, AccountLink::query()->count());
    }

    public function test_un_jeton_presente_par_la_mauvaise_session_est_refuse_ET_brule(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');
        $tiers = $this->compte($this->orgA, 'tiers@exemple.test');

        $jeton = app(AccountLinkEmailProof::class)->emettre($moi, $cible);

        // Le GET previent la mauvaise session sans rien bruler...
        $this->actingAs($tiers)
            ->get(route('my-organizations.confirm', ['token' => $jeton]))
            ->assertOk()
            ->assertSee('data-myorg-wrong-account', false);

        // ...mais le POST d'une mauvaise session BRULE le jeton : il a ete
        // expose a une session qui n'avait pas a le tenir.
        $this->actingAs($tiers)
            ->post(route('my-organizations.confirm.store', ['token' => $jeton]))
            ->assertStatus(422);

        $this->assertSame(0, AccountLink::query()->count());

        // La bonne session arrive ensuite : trop tard, et c'est voulu.
        $this->actingAs($moi)
            ->post(route('my-organizations.confirm.store', ['token' => $jeton]))
            ->assertStatus(422);

        $this->assertSame(0, AccountLink::query()->count());
    }

    public function test_une_emission_remplace_la_precedente_pour_la_meme_paire(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');

        $preuve = app(AccountLinkEmailProof::class);

        $premier = $preuve->emettre($moi, $cible);
        $second = $preuve->emettre($moi, $cible);

        $this->assertNull($preuve->lire($premier), 'le premier jeton doit etre ecrase');
        $this->assertNotNull($preuve->lire($second));
    }

    public function test_des_adresses_qui_divergent_entre_emission_et_confirmation_refusent(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');

        $jeton = app(AccountLinkEmailProof::class)->emettre($moi, $cible);

        // L'adresse du compte cible change entre l'email et le clic : la
        // preuve de la boite ne vaut plus — cette boite ne couvre plus les
        // deux comptes.
        $cible->forceFill(['email' => 'nouvelle@exemple.test'])->save();

        $this->actingAs($moi)
            ->post(route('my-organizations.confirm.store', ['token' => $jeton]))
            ->assertStatus(422);

        $this->assertSame(0, AccountLink::query()->count());
        $this->assertAuthenticatedAs($moi);
    }

    public function test_une_cible_devenue_orpheline_ne_fait_pas_atterrir_nulle_part(): void
    {
        // Theorique depuis A1 (Organization immuable), mais ce chemin ne
        // passe pas par le panneau : sans sa propre garde, `canonicalHome()`
        // exploserait sur une Organization nulle. Ecriture BRUTE : la garde
        // de modele de T1726 protege les ecrans, pas la base.
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');

        $jeton = app(AccountLinkEmailProof::class)->emettre($moi, $cible);

        DB::table('users')->where('id', $cible->getKey())->update(['organization_id' => null]);

        $this->actingAs($moi)
            ->post(route('my-organizations.confirm.store', ['token' => $jeton]))
            ->assertStatus(422);

        $this->assertAuthenticatedAs($moi);
    }

    // ── Eligibilite : verifie, preflight, panneau ──────────────────────────

    public function test_un_frere_non_verifie_n_est_ni_liste_ni_atteignable(): void
    {
        Notification::fake();

        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        User::factory()->unverified()->create([
            'organization_id' => $this->orgB->getKey(),
            'email' => 'marie@exemple.test',
        ]);

        $this->assertCount(0, app(SiblingAccountsPanel::class)->entrees($moi),
            'un frere non verifie et non lie n\'apparait pas du tout');

        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
            ->assertStatus(422);

        Notification::assertNothingSent();
        $this->assertAuthenticatedAs($moi);
    }

    public function test_un_frere_bloque_par_le_preflight_est_indisponible_et_inatteignable(): void
    {
        Notification::fake();

        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $banni = $this->compte($this->orgB, 'marie@exemple.test');
        $banni->forceFill(['banned_at' => now()])->save();

        $entrees = app(SiblingAccountsPanel::class)->entrees($moi->fresh());

        $this->assertCount(1, $entrees);
        $this->assertSame(SiblingAccountsPanel::ETAT_INDISPONIBLE, $entrees->first()->etat);

        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
            ->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_un_slug_etranger_rend_le_meme_refus_qu_un_slug_inexistant(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');

        // Une Organization REELLE ou personne ne porte mon adresse...
        $etrangere = Organization::factory()->create([
            'slug' => 'myorg-etrangere',
            'name' => 'Etrangere Reelle',
        ]);

        // On mesure l'ELEMENT — meme statut, meme message, aucun nom — et
        // JAMAIS l'egalite des deux documents entiers : Livewire injecte ses
        // styles UNE fois par processus, deux rendus successifs different
        // donc par construction (lecon T1732).
        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => $etrangere->slug]))
            ->assertStatus(422)
            ->assertSee(trans('auth.myorg_refused'))
            ->assertDontSee('Etrangere Reelle');

        // ...et un slug qui n'existe nulle part : INDISCERNABLES.
        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => 'myorg-fantome']))
            ->assertStatus(422)
            ->assertSee(trans('auth.myorg_refused'));
    }

    public function test_la_casse_de_l_adresse_ne_cache_aucun_frere(): void
    {
        // A7 : l'ecriture et la lecture posent la question en LOWER(). Le
        // panneau doit faire pareil, sans quoi la casse deciderait de ce que
        // le menu montre.
        $moi = $this->compte($this->orgA, 'Majuscule@Exemple.test');
        $frere = $this->compte($this->orgB, 'majuscule@exemple.test');

        $entrees = app(SiblingAccountsPanel::class)->entrees($moi);

        $this->assertCount(1, $entrees);
        $this->assertTrue($entrees->first()->compte->is($frere));

        // Et la preuve par la boite accepte ces deux casses.
        $jeton = app(AccountLinkEmailProof::class)->emettre($moi, $frere);

        $this->actingAs($moi)
            ->post(route('my-organizations.confirm.store', ['token' => $jeton]))
            ->assertRedirect(canonicalHome($this->orgB));

        $this->assertSame(1, AccountLink::query()->count());
    }

    public function test_une_organization_privee_est_visible_pour_son_propre_compte_frere(): void
    {
        $this->assertFalse((bool) $this->orgB->is_public, 'le decor exige une Organization privee');

        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $this->compte($this->orgB, 'marie@exemple.test');

        $entrees = app(SiblingAccountsPanel::class)->entrees($moi);

        $this->assertCount(1, $entrees);
        $this->assertSame($this->orgB->slug, $entrees->first()->organization->slug);
    }

    // ── Isolation et session ───────────────────────────────────────────────

    public function test_apres_bascule_le_tenant_d_origine_est_ferme(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');
        $this->lienDirect($moi, $cible);

        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
            ->assertRedirect(canonicalHome($this->orgB));

        $reponse = $this->get(route('organization.loops.index', ['organization' => $this->orgA->slug]));

        $this->assertContains($reponse->getStatusCode(), [302, 403, 404],
            'le compte d\'arrivee ne lit pas le tenant de depart');
        $reponse->assertDontSee('data-nav-my-organizations');
    }

    // ── Limitations : des clefs PROPRES ────────────────────────────────────

    public function test_l_envoi_d_email_est_borne_par_paire(): void
    {
        Notification::fake();

        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $this->compte($this->orgB, 'marie@exemple.test');

        foreach (range(1, 3) as $envoi) {
            $this->actingAs($moi)
                ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
                ->assertRedirect(route('my-organizations.sent'));
        }

        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => $this->orgB->slug]))
            ->assertStatus(422);

        Notification::assertCount(3);
    }

    public function test_les_echecs_ici_ne_verrouillent_pas_la_connexion_ordinaire(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test', 'secret-a');

        foreach (range(1, 10) as $tentative) {
            $this->actingAs($moi)
                ->post(route('my-organizations.switch', ['org' => 'myorg-fantome']))
                ->assertStatus(422);
        }

        // La onzieme se heurte a la limitation PROPRE de Mes organisations...
        $this->actingAs($moi)
            ->post(route('my-organizations.switch', ['org' => 'myorg-fantome']))
            ->assertStatus(422)
            ->assertDontSee(trans('auth.myorg_refused'));

        // ...et le login, lui, respire toujours : sa clef est distincte.
        $this->post('/logout');

        $this->post('/login', [
            'email' => 'marie@exemple.test',
            'password' => 'secret-a',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($moi);
    }

    // ── Les surfaces du menu ───────────────────────────────────────────────

    public function test_le_composant_rend_la_section_sur_ses_trois_variantes(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $cible = $this->compte($this->orgB, 'marie@exemple.test');
        $this->lienDirect($moi, $cible);

        $this->actingAs($moi);

        foreach (['dropdown', 'responsive', 'side'] as $variante) {
            $rendu = (string) $this->blade(
                '<x-my-organizations-menu :variant="$variant" />',
                ['variant' => $variante],
            );

            $this->assertStringContainsString('data-nav-my-organizations', $rendu, "variante {$variante}");
            $this->assertStringContainsString('data-my-org-switch="'.$this->orgB->slug.'"', $rendu, "variante {$variante}");
            $this->assertStringContainsString('data-my-org-state="basculable"', $rendu, "variante {$variante}");
            // Le parcours nominal ne rend JAMAIS un champ de mot de passe.
            $this->assertStringNotContainsString('type="password"', $rendu, "variante {$variante}");
        }
    }

    public function test_le_frere_non_lie_porte_l_etat_a_confirmer_et_l_indisponible_est_inerte(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');
        $this->compte($this->orgB, 'marie@exemple.test');

        $banni = $this->compte(
            Organization::factory()->create(['slug' => 'myorg-c']),
            'marie@exemple.test',
        );
        $banni->forceFill(['banned_at' => now()])->save();

        $this->actingAs($moi);

        $rendu = (string) $this->blade('<x-my-organizations-menu variant="dropdown" />');

        $this->assertStringContainsString('data-my-org-state="a_confirmer"', $rendu);
        $this->assertStringContainsString('data-my-org-state="indisponible"', $rendu);
        // L'entree indisponible n'est pas un bouton : rien a poster.
        $this->assertStringNotContainsString('data-my-org-switch="myorg-c"', $rendu);
    }

    public function test_sans_frere_ni_lien_la_section_n_existe_pas(): void
    {
        $moi = $this->compte($this->orgA, 'seule@exemple.test');

        $this->actingAs($moi);

        $rendu = (string) $this->blade('<x-my-organizations-menu variant="dropdown" />');

        $this->assertStringNotContainsString('data-nav-my-organizations', $rendu,
            'le menu de l\'immense majorite des comptes ne porte pas de rubrique vide');
    }

    public function test_les_anciens_parcours_vivent_desormais_dans_le_profil(): void
    {
        $moi = $this->compte($this->orgA, 'marie@exemple.test');

        $this->actingAs($moi)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('data-nav-account-links', false)
            ->assertSee('data-nav-account-switch', false);

        // Et les deux ecrans eux-memes repondent toujours.
        $this->actingAs($moi)->get(route('account.links'))->assertOk();
        $this->actingAs($moi)->get(route('account.switch'))->assertOk();
    }

    // ── L'avis de creation part des DEUX portes ────────────────────────────

    public function test_l_ancienne_porte_a_deux_mots_de_passe_notifie_aussi(): void
    {
        Notification::fake();

        // L'ancien formulaire ne propose que les Organizations PUBLIQUES
        // (arbitrage D2/T1731) : le decor doit s'y conformer.
        $this->orgB->update(['is_public' => true]);

        $moi = $this->compte($this->orgA, 'marie@exemple.test', 'secret-a');
        $cible = $this->compte($this->orgB, 'autre@exemple.test', 'secret-b');

        $this->actingAs($moi)->post(route('account.links.store'), [
            'organization' => $this->orgB->slug,
            'email' => 'autre@exemple.test',
            'target_password' => 'secret-b',
            'password' => 'secret-a',
        ])->assertSessionHas('success');

        // Adresses DIFFERENTES : chacun recoit le sien.
        Notification::assertSentTo($moi, AccountLinkEstablished::class);
        Notification::assertSentTo($cible, AccountLinkEstablished::class);
    }

    // ── Decor ──────────────────────────────────────────────────────────────

    private function compte(Organization $org, string $email, string $motDePasse = 'secret'): User
    {
        return User::factory()->create([
            'organization_id' => $org->getKey(),
            'email' => $email,
            'password' => Hash::make($motDePasse),
        ]);
    }

    /**
     * Un lien pose DIRECTEMENT en base : le decor n'a pas a payer le
     * formulaire a deux mots de passe pour chaque test de bascule.
     */
    private function lienDirect(User $a, User $b): AccountLink
    {
        [$x, $y] = AccountLinkService::paireNormalisee($a, $b);

        return AccountLink::query()->create([
            'user_a_id' => $x,
            'user_b_id' => $y,
            'created_by_user_id' => $a->getKey(),
        ]);
    }
}
