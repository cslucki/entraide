<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopMarketplaceLink;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1885 C6 (demande Cyril, 22/11) — « Mes demandes d'aide » distingue les
 * demandes posees dans une Boucle (badge + lien vers la Boucle) de celles qui
 * s'adressent a tout le monde.
 *
 * Deux origines : la demande RELAYEE dans la ChatLoop a la creation (message
 * `help_request`) et la demande MISE EN AVANT dans la Card Demande-Offre
 * (`loop_marketplace_links`). Garde : une Boucle archivee ne s'affiche pas.
 */
class TASK1885MyRequestsLoopBadgeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1885-c6', 'is_active' => true, 'is_public' => true, 'loops_enabled' => true]);
        app()->instance('current_organization', $this->org);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->org->id, 'email_verified_at' => now()]);
    }

    public function test_relayed_and_highlighted_requests_carry_their_loop_others_say_everyone(): void
    {
        $service = new LoopService;
        $relais = $service->createLoop($this->membre, 'Boucle relais');
        $vitrine = $service->createLoop($this->membre, 'Boucle vitrine');
        $archivee = $service->createLoop($this->membre, 'Boucle archivee');
        $archivee->forceFill(['status' => 'archived'])->save();

        $relayee = $this->demande('Demande relayee');
        $miseEnAvant = $this->demande('Demande mise en avant');
        $publique = $this->demande('Demande pour tous');
        $dansArchivee = $this->demande('Demande dans une archivee');

        $this->relayer($relais, $relayee);
        $this->relayer($archivee, $dansArchivee);
        LoopMarketplaceLink::create([
            'organization_id' => $this->org->id,
            'loop_id' => $vitrine->id,
            'added_by' => $this->membre->id,
            'service_request_id' => $miseEnAvant->id,
        ]);

        $html = $this->actingAs($this->membre)
            ->get(route('organization.dashboard.requests', ['organization' => $this->org->slug]))
            ->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-request-audience="loop"'));
        $this->assertSame(2, substr_count($html, 'data-request-audience="everyone"'));
        $this->assertStringContainsString('data-request-loop="'.$relais->id.'"', $html);
        $this->assertStringContainsString('data-request-loop="'.$vitrine->id.'"', $html);
        $this->assertStringNotContainsString('data-request-loop="'.$archivee->id.'"', $html);
        $this->assertStringContainsString('href="'.e(route('organization.loops.show', ['organization' => $this->org->slug, 'loop' => $relais->slug ?: $relais->id])).'"', $html);
        $this->assertStringContainsString('Boucle relais', $html);
        $this->assertStringContainsString(__('dashboard.request_audience_everyone'), $html);
    }

    private function demande(string $titre): ServiceRequest
    {
        return ServiceRequest::factory()->forUser($this->membre)->create([
            'organization_id' => $this->org->id,
            'category_id' => Category::factory()->create()->id,
            'status' => 'open',
            'title' => $titre,
        ]);
    }

    private function relayer(Loop $loop, ServiceRequest $demande): void
    {
        LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $this->membre->id,
            'body' => 'Relais',
            'type' => 'help_request',
            'metadata' => ['projection_type' => 'service_request', 'service_request_id' => $demande->id],
            'organization_id' => $this->org->id,
        ]);
    }
}
