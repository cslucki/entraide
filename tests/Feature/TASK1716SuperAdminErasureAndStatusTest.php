<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1716 — le canal d'effacement, et le statut que le SuperAdmin lit.
 *
 * ## Trois gardes, et pas huit (arbitrage MASTER)
 *
 * J'en proposais huit : le statut sur trois ecrans, la pastille, le lien,
 * l'enregistrement, le retrait, le refus. MASTER a tranche a trois, et le
 * critere est le bon — les cinq ecartees etaient des assertions de LIBELLE sur
 * du balisage que rien ne conditionne. Une garde qui lit un mot dans une page
 * ne rougit que si quelqu'un change le mot, et ce n'est pas un defaut.
 *
 * Les trois retenues portent chacune autre chose :
 *
 *  1. le REFUS d'enregistrer une demande sur un compte actif — une vraie
 *     garde : sans elle, une pastille rouge apparaitrait devant quelqu'un qui
 *     n'a rien demande ;
 *  2. l'aller-retour enregistrer / retirer — le canal doit fonctionner dans
 *     les deux sens, sinon une demande posee par erreur reste ;
 *  3. le statut « Ferme » au lieu de « Banni » dans la liste SuperAdmin —
 *     demande EXPLICITE de MASTER (« afficher le bon statut dans
 *     SuperAdmin »), donc elle ne releve pas du confort.
 *
 * ## Ce que le canal est, et ce qu'il n'est pas
 *
 * Il n'efface RIEN. La suppression reelle reste l'ecran de TASK-1636, avec ses
 * refus metier (ledger, transactions, gouvernance de Boucle). Ces colonnes
 * rendent une demande visible et datee ; elles ne declenchent aucune purge.
 */
class TASK1716SuperAdminErasureAndStatusTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisation;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create([
            'slug' => 'org-1716d', 'is_active' => true, 'is_public' => true, 'is_default' => true,
        ]);
        app()->instance('current_organization', $this->organisation);

        $this->admin = User::factory()->create([
            'organization_id' => $this->organisation->id, 'is_admin' => true,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);
    }

    private function membre(array $surcharges = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(), 'approved_at' => now(),
        ], $surcharges));
    }

    private function ferme(): User
    {
        $user = $this->membre();
        $user->forceFill(['banned_at' => now(), 'self_closed_at' => now()])->save();

        return $user;
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. Le refus — la seule vraie garde du canal
    // ───────────────────────────────────────────────────────────────────────

    public function test_une_demande_d_effacement_ne_s_enregistre_pas_sur_un_compte_actif(): void
    {
        $actif = $this->membre();

        $this->actingAs($this->admin)
            ->patch(route('admin.users.erasure-request', $actif), ['demande' => '1'])
            ->assertSessionHas('error', __('admin.users_erasure_requires_closed'));

        // Sans ce refus, une pastille rouge « effacement demande » pourrait
        // apparaitre devant quelqu'un qui n'a rien demande, et le bon geste
        // pour un compte actif est qu'il la depose depuis son propre profil.
        $this->assertNull($actif->refresh()->erasure_requested_at);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. L'aller-retour : le canal fonctionne dans les deux sens
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_canal_enregistre_puis_retire_une_demande_sur_un_compte_ferme(): void
    {
        // MASTER : « prevoir un canal de demande d'effacement APRES fermeture ».
        // La personne est deconnectee et ne peut plus rien deposer par le
        // produit : sa demande arrive par un autre moyen, et c'est ici qu'elle
        // devient tracable au lieu de vivre dans une boite de reception.
        $ferme = $this->ferme();

        $this->actingAs($this->admin)
            ->patch(route('admin.users.erasure-request', $ferme), ['demande' => '1'])
            ->assertSessionHas('success', __('admin.users_erasure_recorded'));

        $this->assertTrue($ferme->refresh()->aDemandeEffacement());

        // Le retour : une demande posee par erreur doit pouvoir repartir.
        $this->actingAs($this->admin)
            ->patch(route('admin.users.erasure-request', $ferme), ['demande' => '0'])
            ->assertSessionHas('success', __('admin.users_erasure_withdrawn'));

        $this->assertFalse($ferme->refresh()->aDemandeEffacement());
        // Et le compte reste FERME : retirer une demande d'effacement n'est pas
        // une reouverture de contrebande.
        $this->assertTrue($ferme->estFermeVolontairement());
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. Le statut que le SuperAdmin lit — demande explicite de MASTER
    // ───────────────────────────────────────────────────────────────────────

    public function test_la_liste_superadmin_dit_ferme_et_non_banni(): void
    {
        $ferme = $this->ferme();
        $ferme->forceFill(['erasure_requested_at' => now()])->save();
        $banni = $this->membre(['banned_at' => now()]);

        $html = $this->actingAs($this->admin)->get('/admin/users?detail=1')->assertOk()->getContent();

        // La LIGNE du compte, pas la page : une page qui contient « Ferme »
        // quelque part ne prouve pas que c'est CE compte qui le porte. Les
        // marqueurs portent l'identifiant, c'est ce qui rend l'assertion
        // attribuable.
        $this->assertStringContainsString('data-user-self-closed="'.$ferme->id.'"', $html);
        $this->assertStringContainsString('data-user-status-closed="'.$ferme->id.'"', $html);
        // Le canal est un LIEN vers l'ecran de suppression de T1636, pas une
        // pastille muette : c'est par la que la demande s'arbitre.
        $this->assertStringContainsString('data-user-erasure-requested="'.$ferme->id.'"', $html);
        $this->assertStringContainsString(route('admin.users.delete-page', $ferme), $html);
        // « Reouvrir le compte » remplace « Debannir » — le bouton aveugle a
        // disparu de la ligne.
        $this->assertStringContainsString('data-user-reopen="'.$ferme->id.'"', $html);
        $this->assertStringNotContainsString(route('admin.users.unban', $ferme), $html);

        // LA NON-REGRESSION : un vrai banni garde « Debannir » et ne porte
        // aucun marqueur de fermeture. Sans elle, reclasser TOUT LE MONDE en
        // « ferme » passerait les assertions ci-dessus.
        $this->assertStringNotContainsString('data-user-self-closed="'.$banni->id.'"', $html);
        $this->assertStringNotContainsString('data-user-status-closed="'.$banni->id.'"', $html);
        $this->assertStringContainsString(route('admin.users.unban', $banni), $html);
    }
}
