<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1749 (demande Cyril, 04/11) — le tableau de bord nomme LA PERSONNE.
 *
 * ## Ce que cela corrige
 *
 * Le titre disait « Tableau de bord - BouclePro » : le nom du locataire, que le
 * rail gauche, le menu Avatar et la barre de marque annoncent deja trois fois
 * sur la meme page. Il dit desormais a qui ce tableau appartient.
 *
 * Le sous-titre « Voici un resume de votre activite » est retire : il decrivait
 * la page a qui la regarde deja.
 *
 * ## Le cas a ne pas rater
 *
 * `User::getFullNameAttribute()` retombe sur `$this->name ?? ''` : sans prenom
 * NI nom, il rend une chaine VIDE. Le titre aurait affiche « Tableau de bord
 * de  », une phrase tronquee — pire qu'un repli technique. D'ou l'adresse.
 */
class TASK1749DashboardTitleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-titre-1749',
            'is_active' => true,
            'is_public' => true,
            'name' => 'BouclePro',
        ]);
    }

    private function tableauDeBord(User $membre): string
    {
        return $this->actingAs($membre)
            ->get(route('organization.dashboard', ['organization' => $this->organization]))
            ->assertOk()
            ->getContent();
    }

    public function test_le_titre_nomme_la_personne(): void
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Camille',
            'name' => 'Berger',
            'email_verified_at' => now(),
        ]);

        $html = $this->tableauDeBord($membre);

        $this->assertStringContainsString(
            e(__('dashboard.title_of', ['name' => 'Camille Berger'])),
            $html,
        );
    }

    /**
     * LE cas du repli. Sans lui, le titre serait « Tableau de bord de  ».
     *
     * `forceFill` : `name` est `required` au formulaire de profil, cet etat ne
     * s'obtient donc pas par l'interface — mais rien en base ne l'interdit, et
     * un import ou une reprise de donnees peut le produire.
     */
    public function test_sans_prenom_NI_nom_le_titre_retombe_sur_l_adresse(): void
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email' => 'sans-nom-1749@exemple.test',
            'email_verified_at' => now(),
        ]);

        $membre->forceFill(['first_name' => null, 'name' => ''])->save();

        $html = $this->tableauDeBord($membre->fresh());

        $this->assertStringContainsString(
            e(__('dashboard.title_of', ['name' => 'sans-nom-1749@exemple.test'])),
            $html,
        );

        // Et surtout PAS la phrase tronquee.
        $this->assertStringNotContainsString('Tableau de bord de </span>', $html);
    }

    /** Le nom de l'Organization ne tient plus lieu de titre. */
    public function test_le_titre_ne_nomme_plus_l_organization(): void
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Camille',
            'name' => 'Berger',
            'email_verified_at' => now(),
        ]);

        $html = $this->tableauDeBord($membre);

        $this->assertStringNotContainsString(
            e(__('dashboard.title_with_tenant', ['name' => 'BouclePro'])),
            $html,
        );
    }

    public function test_le_sous_titre_de_resume_a_disparu(): void
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->assertStringNotContainsString(
            e(__('dashboard.summary')),
            $this->tableauDeBord($membre),
        );
    }

    /** La card des echanges porte le qualificatif demande. */
    public function test_la_card_dit_entraide_EN_COURS(): void
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->assertSame('Entraide en cours', __('dashboard.exchanges'));

        app()->setLocale('en');
        $this->assertSame('Mutual aid in progress', __('dashboard.exchanges'));
    }
}
