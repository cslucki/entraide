<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\Organization;
use App\Models\User;
use App\Services\Users\LinkSwitchConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1731 (A5b) — la liaison explicite et revocable entre deux comptes.
 *
 * ## Le risque que A5b introduit, et que A5a n'avait pas
 *
 * A5a exigeait le mot de passe du compte CIBLE a chaque bascule : compromettre
 * un compte ne donnait rien sur l'autre. A5b dispense de ce mot de passe —
 * c'est tout son interet — et devient donc un AMPLIFICATEUR DE PRIVILEGE si
 * rien ne le protege.
 *
 * Modele de securite arbitre par MASTER le 30/10 : liaison persistante OUI,
 * authentification transitive sans protection NON. D'ou les trois gardes que
 * ces tests mesurent — mots de passe des DEUX comptes a la creation,
 * confirmation RECENTE (300 s) du compte SOURCE a chaque bascule, revocation
 * ouverte aux deux cotes.
 */
class TASK1731AccountLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'a5b-a', 'is_active' => true, 'is_public' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'a5b-b', 'is_active' => true, 'is_public' => true]);
    }

    // ── creation : les DEUX preuves ────────────────────────────────────────

    public function test_lier_exige_les_DEUX_mots_de_passe(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $cible = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');

        // Le mien est bon, celui de la cible est faux.
        $this->actingAs($moi)->post(route('account.links.store'), [
            'organization' => $this->orgB->slug,
            'email' => 'moi@exemple.test',
            'target_password' => 'mauvais',
            'password' => 'secret-a',
        ])->assertSessionHasErrors('target_password');

        $this->assertSame(0, AccountLink::query()->count());

        // Celui de la cible est bon, le mien est faux.
        $this->actingAs($moi)->post(route('account.links.store'), [
            'organization' => $this->orgB->slug,
            'email' => 'moi@exemple.test',
            'target_password' => 'secret-b',
            'password' => 'mauvais',
        ])->assertSessionHasErrors('target_password');

        $this->assertSame(0, AccountLink::query()->count(), 'aucun lien ne nait avec une seule preuve');

        // Les deux sont bons.
        $this->actingAs($moi)->post(route('account.links.store'), [
            'organization' => $this->orgB->slug,
            'email' => 'moi@exemple.test',
            'target_password' => 'secret-b',
            'password' => 'secret-a',
        ])->assertSessionHas('success');

        $this->assertSame(1, AccountLink::query()->count());
        $this->assertNotNull($this->lienEntre($moi, $cible));
    }

    /**
     * Des adresses DIFFERENTES peuvent etre liees.
     *
     * Arbitrage MASTER. C'est ce qui distingue A5b de A5a : A5a n'atteint que
     * les comptes partageant VOTRE adresse, A5b atteint les comptes LIES
     * quelle que soit la leur. Et comme la preuve est le mot de passe des
     * deux, l'adresse n'a plus a etre le critere.
     */
    public function test_deux_comptes_d_adresses_DIFFERENTES_peuvent_etre_lies(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $autre = $this->compte($this->orgB, 'tout-autre@ailleurs.test', 'secret-b');

        $this->actingAs($moi)->post(route('account.links.store'), [
            'organization' => $this->orgB->slug,
            'email' => 'tout-autre@ailleurs.test',
            'target_password' => 'secret-b',
            'password' => 'secret-a',
        ])->assertSessionHas('success');

        $this->assertNotNull($this->lienEntre($moi, $autre));
    }

    public function test_un_compte_ne_peut_PAS_se_lier_a_lui_meme(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');

        $this->actingAs($moi)->post(route('account.links.store'), [
            'organization' => $this->orgA->slug,
            'email' => 'moi@exemple.test',
            'target_password' => 'secret-a',
            'password' => 'secret-a',
        ])->assertSessionHasErrors('target_password');

        $this->assertSame(0, AccountLink::query()->count());
    }

    /**
     * La paire est unique QUEL QUE SOIT le sens.
     *
     * C'est la raison d'etre de la paire ordonnee : sans normalisation, (A,B)
     * et (B,A) seraient deux lignes acceptables pour un seul fait, et c'est la
     * seconde qu'on oublie de revoquer.
     */
    public function test_la_paire_est_unique_dans_les_DEUX_sens(): void
    {
        $a = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $b = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');

        $this->lier($a, $b);
        $this->assertSame(1, AccountLink::query()->count());

        // Depuis l'AUTRE cote, avec les memes comptes.
        $this->actingAs($b)->post(route('account.links.store'), [
            'organization' => $this->orgA->slug,
            'email' => 'moi@exemple.test',
            'target_password' => 'secret-a',
            'password' => 'secret-b',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, AccountLink::query()->count(), 'la paire inverse ne cree pas une seconde ligne');
    }

    // ── bascule par lien : la confirmation recente ─────────────────────────

    public function test_la_bascule_par_lien_exige_le_mot_de_passe_du_compte_SOURCE(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $cible = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
        $lien = $this->lier($moi, $cible);

        // Sans confirmation : refus, et la session d'origine survit.
        $this->actingAs($moi)->post(route('account.links.switch', $lien), [])
            ->assertSessionHasErrors('password');
        $this->assertAuthenticatedAs($moi);

        // Avec le mot de passe de la CIBLE : refus aussi. C'est le compte
        // SOURCE qu'il faut prouver, pas celui ou l'on va.
        $this->actingAs($moi)->post(route('account.links.switch', $lien), ['password' => 'secret-b'])
            ->assertSessionHasErrors('password');
        $this->assertAuthenticatedAs($moi);

        // Avec le mien : la bascule a lieu.
        $this->actingAs($moi)->post(route('account.links.switch', $lien), ['password' => 'secret-a'])
            ->assertRedirect(canonicalHome($this->orgB));
        $this->assertAuthenticatedAs($cible);
    }

    /**
     * La confirmation est propre au compte SOURCE, jamais heritee.
     *
     * Exigence MASTER. Sans le lien a l'identite, la confirmation serait une
     * propriete de la SESSION — et la session change d'occupant a chaque
     * bascule, donc une confirmation donnee par A autoriserait une bascule
     * depuis B.
     */
    public function test_une_confirmation_donnee_par_UN_compte_n_autorise_pas_l_autre(): void
    {
        $a = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $b = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');

        $confirmation = app(LinkSwitchConfirmation::class);

        $this->actingAs($a);
        $confirmation->poser($a);

        $this->assertTrue($confirmation->estRecente($a));
        $this->assertFalse($confirmation->estRecente($b),
            "la confirmation de A ne doit PAS valoir pour B");
    }

    public function test_la_confirmation_expire_apres_la_fenetre(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $cible = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
        $lien = $this->lier($moi, $cible);

        $this->actingAs($moi);
        app(LinkSwitchConfirmation::class)->poser($moi);

        // Juste apres la fenetre de 300 secondes.
        $this->travel(LinkSwitchConfirmation::FENETRE_SECONDES + 1)->seconds();

        $this->post(route('account.links.switch', $lien), [])
            ->assertSessionHasErrors('password');
        $this->assertAuthenticatedAs($moi);
    }

    /**
     * Une confirmation ne SURVIT PAS a une bascule reussie.
     *
     * `AccountSwitch::versLeCompte()` invalide la session, et le controleur
     * l'oublie en plus explicitement. Sans cela, les cinq minutes
     * permettraient de rebondir de compte en compte sans jamais reprouver quoi
     * que ce soit.
     */
    public function test_la_confirmation_ne_survit_pas_a_une_bascule_reussie(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $cible = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
        $lien = $this->lier($moi, $cible);

        $this->actingAs($moi)->post(route('account.links.switch', $lien), ['password' => 'secret-a'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($cible);

        // Retour : sans redonner le mot de passe du compte ou l'on est
        // maintenant, c'est refuse.
        $this->post(route('account.links.switch', $lien), [])
            ->assertSessionHasErrors('password');
        $this->assertAuthenticatedAs($cible);
    }

    // ── non-transitivite ───────────────────────────────────────────────────

    /**
     * Un lien A-B et un lien B-C n'autorisent PAS A vers C.
     *
     * Rien dans le schema ne l'interdit : c'est une propriete du code, qui
     * tient a ce qu'aucune methode ne parcourt de chemin. Elle ne se garantit
     * donc que par ce test.
     */
    public function test_un_lien_A_B_et_B_C_n_autorise_PAS_A_vers_C(): void
    {
        $orgC = Organization::factory()->create(['slug' => 'a5b-c', 'is_active' => true, 'is_public' => true]);

        $a = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $b = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
        $c = $this->compte($orgC, 'moi@exemple.test', 'secret-c');

        $lienAB = $this->lier($a, $b);
        $lienBC = $this->lier($b, $c, 'secret-b', 'secret-c');

        // A tente d'emprunter le lien B-C, qui ne le concerne pas : 404, et
        // surtout pas un refus qui confirmerait l'existence du lien.
        $this->actingAs($a)->post(route('account.links.switch', $lienBC), ['password' => 'secret-a'])
            ->assertNotFound();

        $this->assertAuthenticatedAs($a);
        $this->assertNull($this->lienEntre($a, $c), 'aucun lien A-C ne doit exister');
    }

    // ── revocation ─────────────────────────────────────────────────────────

    public function test_chacun_des_DEUX_comptes_peut_revoquer(): void
    {
        foreach (['source', 'cible'] as $qui) {
            AccountLink::query()->delete();
            User::query()->whereIn('organization_id', [$this->orgA->getKey(), $this->orgB->getKey()])->delete();

            $a = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
            $b = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
            $lien = $this->lier($a, $b);

            $revoquant = $qui === 'source' ? $a : $b;

            $this->actingAs($revoquant)
                ->delete(route('account.links.destroy', $lien))
                ->assertSessionHas('success');

            $this->assertSame(0, AccountLink::query()->count(), "revocation par le {$qui}");
        }
    }

    public function test_un_TIERS_ne_peut_pas_revoquer_un_lien(): void
    {
        $a = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $b = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
        $lien = $this->lier($a, $b);

        $tiers = $this->compte($this->orgA, 'tiers@exemple.test', 'secret-t');

        $this->actingAs($tiers)->delete(route('account.links.destroy', $lien))->assertNotFound();

        $this->assertSame(1, AccountLink::query()->count());
    }

    public function test_apres_revocation_la_bascule_par_lien_est_refusee(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $cible = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
        $lien = $this->lier($moi, $cible);

        $this->actingAs($moi)->delete(route('account.links.destroy', $lien));

        $this->post(route('account.links.switch', $lien), ['password' => 'secret-a'])
            ->assertNotFound();

        $this->assertAuthenticatedAs($moi);
    }

    // ── reutilisation des controles de T1730 ───────────────────────────────

    /**
     * Les cinq etats bloquants de T1730 s'appliquent AUSSI a la bascule par
     * lien, et sans qu'une seule regle ait ete reecrite.
     */
    public function test_un_compte_lie_devenu_inutilisable_est_refuse(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $cible = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
        $lien = $this->lier($moi, $cible);

        $cible->forceFill(['banned_at' => now()])->save();

        $this->actingAs($moi)->post(route('account.links.switch', $lien), ['password' => 'secret-a'])
            ->assertSessionHasErrors('password');

        $this->assertAuthenticatedAs($moi);
        $this->assertSame($this->orgA->getKey(), auth()->user()->organization_id);
    }

    public function test_la_bascule_par_lien_ne_DEPLACE_aucun_compte(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $cible = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
        $lien = $this->lier($moi, $cible);

        $this->actingAs($moi)->post(route('account.links.switch', $lien), ['password' => 'secret-a'])
            ->assertRedirect();

        $this->assertSame($this->orgA->getKey(), $moi->fresh()->organization_id);
        $this->assertSame($this->orgB->getKey(), $cible->fresh()->organization_id);
    }

    // ── cycle de vie ───────────────────────────────────────────────────────

    public function test_supprimer_un_compte_supprime_ses_liens(): void
    {
        $a = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $b = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
        $this->lier($a, $b);

        $this->assertSame(1, AccountLink::query()->count());

        User::query()->whereKey($b->getKey())->delete();

        $this->assertSame(0, AccountLink::query()->count(),
            'la cle etrangere en cascade ne laisse aucun lien orphelin');
    }

    // ── concurrence : le verrou est DETENU jusqu'a la decision ─────────────

    /**
     * Le verrou est pris sur le lien, ET IL EST ENCORE DETENU quand la
     * decision de basculer est prise.
     *
     * ## Pourquoi ce n'est PAS un test a deux connexions, et pourquoi c'est
     * honnete de le dire
     *
     * J'ai d'abord ecrit l'exclusion reelle : une seconde connexion PDO tente
     * de supprimer la ligne de lien pendant que la transaction la tient, avec
     * un `lock_timeout` court. Le test a ECHOUE sur PostgreSQL — et pas a
     * cause du code. Sous `RefreshDatabase`, les fixtures vivent dans une
     * transaction NON COMMITEE : la seconde connexion ne voit tout simplement
     * pas la ligne, son `DELETE` porte sur zero ligne et reussit sans avoir
     * besoin d'aucun verrou. Le test ne mesurait rien, et son echec etait un
     * artefact du harnais.
     *
     * Un test a deux connexions ne peut donc PAS prouver un verrou de LIGNE
     * ici. Il le peut pour un verrou de TABLE (T1729 l'a fait : un `INSERT`
     * concurrent n'a pas besoin de voir les lignes existantes), pas pour une
     * ligne invisible a l'autre connexion.
     *
     * ## Ce qui est mesure a la place, et pourquoi c'est la bonne propriete
     *
     * La precision MASTER dit exactement ceci : « le verrou doit rester detenu
     * jusqu'au point ou la decision de bascule est effectivement prise ». On
     * mesure donc ces deux faits, qui ensemble la constituent :
     *
     *  1. un `SELECT ... FOR UPDATE` est bien emis sur `account_links` ;
     *  2. au moment ou le pre-controle tranche, `DB::transactionLevel()` est
     *     encore superieur a zero — donc la transaction qui detient ce verrou
     *     n'a pas ete relachee avant la decision.
     *
     * Un verrou pris puis relache avant de decider ferait tomber le second
     * point. C'est precisement le defaut contre lequel MASTER mettait en garde.
     */
    public function test_le_verrou_du_lien_est_detenu_jusqu_a_la_decision(): void
    {
        $moi = $this->compte($this->orgA, 'moi@exemple.test', 'secret-a');
        $cible = $this->compte($this->orgB, 'moi@exemple.test', 'secret-b');
        $lien = $this->lier($moi, $cible);

        $niveauAuMomentDeDecider = null;

        // Une doublure qui n'altere RIEN : elle note seulement a quel niveau de
        // transaction le pre-controle est interroge, puis delegue au vrai.
        $this->app->bind(\App\Services\Users\AccountSwitchPreflight::class, function () use (&$niveauAuMomentDeDecider) {
            return new class($niveauAuMomentDeDecider) extends \App\Services\Users\AccountSwitchPreflight
            {
                public function __construct(private &$temoin) {}

                public function motifDeRefus(User $cible): ?string
                {
                    $this->temoin = DB::transactionLevel();

                    return parent::motifDeRefus($cible);
                }
            };
        });

        $verrouPris = false;

        DB::listen(function ($requete) use (&$verrouPris): void {
            $sql = strtolower($requete->sql);

            if (str_contains($sql, 'account_links') && str_contains($sql, 'for update')) {
                $verrouPris = true;
            }
        });

        $this->actingAs($moi)->post(route('account.links.switch', $lien), ['password' => 'secret-a'])
            ->assertRedirect(canonicalHome($this->orgB));

        // Le verrou de LIGNE n'existe que sur PostgreSQL : la grammaire SQLite
        // compile `lockForUpdate()` en RIEN, parce que SQLite serialise deja
        // les ecrivains au niveau du FICHIER. L'assertion est donc
        // conditionnee au moteur — l'affirmer partout l'aurait rendue fausse
        // la ou elle n'a pas de sens, et la retirer partout aurait perdu la
        // seule preuve que le verrou est reellement demande.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->assertTrue($verrouPris,
                "aucun SELECT ... FOR UPDATE n'a ete emis sur `account_links` : le lien n'est pas verrouille");
        }

        $this->assertNotNull($niveauAuMomentDeDecider,
            'le pre-controle n\'a pas ete interroge : ce test n\'a RIEN mesure');

        $this->assertGreaterThan(0, $niveauAuMomentDeDecider,
            'la decision est prise HORS transaction : le verrou a ete relache avant, il ne protege rien');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function compte(Organization $org, string $email, string $motDePasse): User
    {
        return User::factory()->create([
            'organization_id' => $org->getKey(),
            'email' => $email,
            'password' => Hash::make($motDePasse),
        ]);
    }

    private function lier(User $a, User $b, string $mdpA = 'secret-a', string $mdpB = 'secret-b'): AccountLink
    {
        $this->actingAs($a)->post(route('account.links.store'), [
            'organization' => $b->organization->slug,
            'email' => $b->email,
            'target_password' => $mdpB,
            'password' => $mdpA,
        ])->assertSessionHas('success');

        $lien = $this->lienEntre($a, $b);

        $this->assertNotNull($lien, 'le decor doit avoir cree le lien');

        return $lien;
    }

    private function lienEntre(User $x, User $y): ?AccountLink
    {
        $ids = [(string) $x->getKey(), (string) $y->getKey()];
        sort($ids);

        return AccountLink::query()
            ->where('user_a_id', $ids[0])
            ->where('user_b_id', $ids[1])
            ->first();
    }
}
