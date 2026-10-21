<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1693, bug F — ajouter, retirer et remettre a zero des points depuis les
 * deux ecrans ou l'on s'attend a le faire.
 *
 * ## Ce qui manquait, et ce qui existait deja
 *
 * - `/admin/users/{user}/edit` : RIEN. La fiche d'edition ne parlait pas de
 *   points, alors que c'est l'ecran ou l'on va pour agir sur une personne.
 * - `/admin/points` : le panneau de correction EXISTAIT depuis TASK-1667, mais
 *   il ne s'affiche qu'une fois une personne designee, et l'intro de l'ecran
 *   annoncait « en lecture seule » — devenu FAUX, et activement trompeur. Une
 *   capacite introuvable equivaut a une capacite absente.
 *
 * ## Append-only
 *
 * Un grand livre ne s'edite pas : « modifier » et « retirer » passent tous deux
 * par un delta, positif ou negatif. Remettre a zero, c'est l'oppose du solde.
 * Le solde bouge, l'historique reste — et c'est cette immuabilite qui fait
 * qu'un solde bloque la suppression d'un compte.
 */
class TASK1693AdminPointsReachabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693f',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'points_balance' => 50,
        ]);
    }

    // ── 1. La fiche d'edition ───────────────────────────────────────────────

    public function test_the_edit_page_offers_the_balance_the_delta_and_a_reason(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.edit', $this->membre))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-user-points-panel="'.$this->membre->id.'"', $html);
        $this->assertStringContainsString('name="delta"', $html);
        $this->assertStringContainsString('name="reason"', $html);
        $this->assertStringContainsString(route('admin.users.adjust-points', $this->membre), $html);

        // Le solde courant se lit AVANT d'agir : corriger a l'aveugle n'a pas
        // de sens.
        $this->assertStringContainsString('data-user-points-balance', $html);
        $this->assertStringContainsString('>50<', $html);
    }

    /**
     * Le panneau vit HORS du formulaire principal.
     *
     * Imbriquer un `<form>` dans un autre est invalide en HTML : le navigateur
     * recolle l'arbre a sa facon, le formulaire interieur disparait ou avale
     * les champs du premier. Rien ne le signale cote serveur et les tests
     * passent — defaut deja paye en TASK-1668, qu'on ne repaie pas ici.
     */
    public function test_the_points_panel_is_not_nested_inside_the_main_form(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.edit', $this->membre))
            ->assertOk()
            ->getContent();

        $panneau = strpos($html, 'data-user-points-panel=');
        $this->assertNotFalse($panneau);

        $formPrincipal = strpos($html, route('admin.users.update', $this->membre));
        $this->assertNotFalse($formPrincipal);

        // Le `</form>` du formulaire principal doit se trouver AVANT le panneau.
        $fermeture = strpos($html, '</form>', $formPrincipal);
        $this->assertNotFalse($fermeture);
        $this->assertLessThan($panneau, $fermeture,
            'le panneau de points doit etre pose APRES la fermeture du formulaire principal');
    }

    public function test_adding_and_removing_points_both_go_through_a_delta(): void
    {
        foreach ([['delta' => 25, 'attendu' => 75], ['delta' => -40, 'attendu' => 35]] as $cas) {
            $this->actingAs($this->superAdmin)
                ->post(route('admin.users.adjust-points', $this->membre), [
                    'delta' => $cas['delta'],
                    'reason' => 'correction recette',
                ])
                ->assertRedirect()
                ->assertSessionHas('success');

            $this->assertSame($cas['attendu'], $this->membre->fresh()->points_balance);
        }

        // Append-only : DEUX ecritures, aucune modifiee, aucune supprimee.
        $ecritures = PointLedger::query()->where('user_id', $this->membre->id)->get();
        $this->assertCount(2, $ecritures);
        $this->assertSame([25, -40], $ecritures->sortBy('created_at')->pluck('delta')->map(fn ($d) => (int) $d)->all());
        $this->assertSame(['correction recette', 'correction recette'], $ecritures->pluck('reason')->all(),
            'le motif saisi est conserve, jamais ecrase par le defaut');
    }

    /** Remettre a zero est un delta comme un autre : l'oppose du solde. */
    public function test_resetting_the_balance_is_an_opposite_delta_not_a_deletion(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.adjust-points', $this->membre), [
                'delta' => -$this->membre->points_balance,
                'reason' => 'admin_reset',
            ])
            ->assertRedirect();

        $this->assertSame(0, $this->membre->fresh()->points_balance);
        $this->assertSame(1, PointLedger::query()->where('user_id', $this->membre->id)->count(),
            'une ecriture AJOUTEE, pas un historique efface');
    }

    // ── 2. Le grand livre ───────────────────────────────────────────────────

    /**
     * L'intro ne se declare plus « en lecture seule ».
     *
     * C'etait la cause du signalement : TASK-1667 avait ajoute le panneau de
     * correction, et la phrase d'introduction continuait d'annoncer le
     * contraire. Un ecran qui se dit incapable de ce qu'il fait rend sa propre
     * capacite introuvable.
     */
    public function test_the_ledger_no_longer_claims_to_be_read_only(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.points'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('lecture seule', $html);

        // …et il DIT comment corriger, puisque le panneau est encore cache.
        $this->assertStringContainsString('data-points-adjust-hint', $html);
    }

    /** Une personne designee fait apparaitre le panneau, avec son solde. */
    public function test_picking_a_member_reveals_the_correction_panel(): void
    {
        $sans = $this->actingAs($this->superAdmin)
            ->get(route('admin.points'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('admin.users.adjust-points', $this->membre), $sans,
            'sans personne designee, aucun panneau — c\'est le comportement de TASK-1667');

        $avec = $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['user_id' => $this->membre->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.users.adjust-points', $this->membre), $avec);
        $this->assertStringNotContainsString('data-points-adjust-hint', $avec,
            'l\'indice disparait une fois le panneau visible : il n\'a plus rien a annoncer');
    }

    /** Les deux ecrans se rejoignent : la fiche mene au grand livre du membre. */
    public function test_the_edit_page_links_to_the_members_ledger(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.edit', $this->membre))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            e(route('admin.points', ['user_id' => $this->membre->id])),
            $html,
        );
    }

    // ── 3. Le titulaire ─────────────────────────────────────────────────────

    /** La correction reste reservee au SuperAdmin plateforme. */
    public function test_the_correction_stays_with_the_platform_superadmin(): void
    {
        $url = route('admin.users.adjust-points', $this->membre);

        // Anonyme d'abord : `actingAs()` persiste pour le reste du test.
        $this->post($url, ['delta' => 10])->assertRedirect(route('login'));

        $orgAdmin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->organization->update(['admin_id' => $orgAdmin->id]);

        $this->actingAs($orgAdmin)->post($url, ['delta' => 10])->assertForbidden();

        $this->assertSame(50, $this->membre->fresh()->points_balance, 'aucun solde n\'a bouge');
        $this->assertSame(0, PointLedger::query()->where('user_id', $this->membre->id)->count());
    }

    // ── 4. Bug H — la porte vers la correction se VOIT ──────────────────────

    /**
     * Le lien de chaque ecriture porte un VERBE, pas seulement un chevron.
     *
     * Il etait rendu `&rarr;` en gris pale, et sa seule explication vivait dans
     * un attribut `title`. Une infobulle ne se survole pas au doigt, ne
     * s'annonce pas comme une commande a un lecteur d'ecran, et ne se devine
     * pas : la porte principale vers la correction d'un solde etait invisible.
     */
    public function test_the_ledger_row_link_carries_a_visible_verb_not_just_a_glyph(): void
    {
        PointLedger::query()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'delta' => 10,
            'reason' => 'welcome_bonus',
        ]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.points'))
            ->assertOk()
            ->getContent();

        $ancre = 'data-points-focus-member="'.$this->membre->id.'"';
        $position = strpos($html, $ancre);
        $this->assertNotFalse($position, 'le lien de focus est present sur la ligne');

        $lien = substr($html, $position, strpos($html, '</a>', $position) - $position);

        // Le VERBE est dans le corps du lien, pas seulement en infobulle.
        $this->assertStringContainsString(__('admin.points_focus_member_short'), $lien);

        // Le chevron subsiste comme ornement, mais il est masque aux lecteurs
        // d'ecran : il ne porte plus le sens a lui seul.
        $this->assertStringContainsString('aria-hidden="true"', $lien);

        // La phrase complete reste disponible au survol.
        $this->assertStringContainsString(e(__('admin.points_focus_member')), substr($html, $position - 400, 400 + strlen($lien)));
    }

    /** Suivre ce lien mene bien au panneau de correction de cette personne. */
    public function test_following_the_ledger_row_link_opens_the_correction_panel(): void
    {
        PointLedger::query()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'delta' => 10,
            'reason' => 'welcome_bonus',
        ]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['user_id' => $this->membre->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.users.adjust-points', $this->membre), $html);
    }
}
