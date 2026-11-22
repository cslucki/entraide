<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Email\HelpDigestBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1883 — blocs `{demande_aide;N}` / `{proposition_aide;N}` de la newsletter.
 */
class TASK1883HelpDigestBlocksTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->autre = Organization::factory()->create(['is_active' => true]);
        // Aucun tenant lie : la situation d'un job de la Managed Queue.
        app()->forgetInstance('current_organization');
    }

    private function demande(Organization $org, string $titre, int $ilYaMinutes = 0, string $statut = 'open'): ServiceRequest
    {
        $auteur = User::factory()->create(['organization_id' => $org->id, 'first_name' => 'Léa']);

        return ServiceRequest::withoutGlobalScopes()->forceCreate(ServiceRequest::factory()->raw([
            'organization_id' => $org->id, 'user_id' => $auteur->id, 'title' => $titre, 'status' => $statut,
        ]) + ['created_at' => now()->subMinutes($ilYaMinutes)]);
    }

    private function proposition(Organization $org, string $titre): Service
    {
        $auteur = User::factory()->create(['organization_id' => $org->id]);

        return Service::withoutGlobalScopes()->forceCreate(Service::factory()->raw([
            'organization_id' => $org->id, 'user_id' => $auteur->id, 'title' => $titre,
        ]));
    }

    public function test_les_n_dernieres_demandes_ouvertes_de_l_organisation_seulement(): void
    {
        $this->demande($this->org, 'Ancienne', 30);
        $this->demande($this->org, 'Recente', 1);
        $this->demande($this->org, 'Milieu', 10);
        $this->demande($this->org, 'Fermee', 0, 'closed');
        $this->demande($this->autre, 'Demande d une autre organisation');

        $html = app(HelpDigestBlocks::class)->developper('<h2>Demandes</h2>{demande_aide;2}', $this->org, 'fr');

        $this->assertStringContainsString('Recente', $html);
        $this->assertStringContainsString('Milieu', $html);
        $this->assertStringNotContainsString('Ancienne', $html);
        $this->assertStringNotContainsString('Fermee', $html);
        $this->assertStringNotContainsString('autre organisation', $html);
        $this->assertStringContainsString('Léa', $html);
        $this->assertLessThan(strpos($html, 'Milieu'), strpos($html, 'Recente'));
        $this->assertFalse(app()->bound('current_organization'), 'Le tenant est rendu apres la requete.');
    }

    public function test_les_textes_sont_echappes_et_le_lien_mene_a_la_fiche(): void
    {
        $demande = $this->demande($this->org, '<script>alert(1)</script>');
        $demande->forceFill(['description' => "**Précisez votre besoin** \\*\n\nDeuxième _paragraphe_"])->save();

        $html = app(HelpDigestBlocks::class)->developper('{demande_aide;3}', $this->org, 'fr');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // Le Markdown de la description est rendu en texte, pas recopie.
        $this->assertStringContainsString('Précisez votre besoin * Deuxième paragraphe', $html);
        $this->assertStringNotContainsString('**', $html);
        $this->assertStringContainsString(e(route('organization.requests.show', ['organization' => $this->org->slug, 'request' => $demande->id])), $html);
    }

    public function test_propositions_nombre_impose_par_l_ecran_et_bornes(): void
    {
        foreach (['P1', 'P2', 'P3'] as $titre) {
            $this->proposition($this->org, $titre);
        }

        $blocs = app(HelpDigestBlocks::class);

        $this->assertSame(1, substr_count($blocs->developper('{proposition_aide;3}', $this->org, 'fr', null, 1), '<li'));
        $this->assertSame(3, substr_count($blocs->developper('{proposition_aide;3}', $this->org, 'fr'), '<li'));
        $this->assertSame('{proposition_aide;0} {proposition_aide;11}', $blocs->developper('{proposition_aide;0} {proposition_aide;11}', $this->org, 'fr'));
        $this->assertSame('{demande_aide}', $blocs->developper('{demande_aide}', $this->org, 'fr'));
    }

    public function test_message_vide_en_francais_et_en_anglais_et_detection_du_contenu(): void
    {
        $blocs = app(HelpDigestBlocks::class);

        $this->assertStringContainsString('Aucune demande d’aide en cours.', $blocs->developper('{demande_aide;3}', $this->org, 'fr'));
        $this->assertStringContainsString('No help offer right now.', $blocs->developper('{proposition_aide;3}', $this->org, 'en'));
        $this->assertFalse($blocs->aDuContenu($this->org, 3, 3));

        $this->proposition($this->autre, 'Ailleurs');
        $this->assertFalse(app(HelpDigestBlocks::class)->aDuContenu($this->org, 3, 3));

        $this->proposition($this->org, 'Ici');
        $this->assertTrue(app(HelpDigestBlocks::class)->aDuContenu($this->org, 3, 3));
        $this->assertFalse(app(HelpDigestBlocks::class)->aDuContenu($this->org, 3, 0));
    }
}
