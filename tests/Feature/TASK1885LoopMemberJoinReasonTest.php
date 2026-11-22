<?php

namespace Tests\Feature;

use App\Livewire\LoopMembersCard;
use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1885 C4 (demande Cyril, 22/11) — la liste des membres d'une Boucle montre
 * « pourquoi ils ont souhaite rejoindre » (message de la demande ACCEPTEE) et
 * mene au profil public : tiroir de la grille / volet de la liste (meme
 * apercu `loops.preview`) et Card Membres.
 *
 * Gardes de sens : une demande refusee ou en attente ne s'affiche jamais ; un
 * membre sans demande n'a pas de phrase ; la DERNIERE demande acceptee l'emporte.
 */
class TASK1885LoopMemberJoinReasonTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private LoopService $service;

    private User $proprietaire;

    private User $motive;

    private User $silencieux;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create(['slug' => 'org-1885-c4', 'is_active' => true, 'loops_enabled' => true]);
        app()->instance('current_organization', $this->org);
        $this->service = new LoopService;

        $this->proprietaire = $this->membre();
        $this->motive = $this->membre();
        $this->silencieux = $this->membre();
        $this->boucle = $this->service->createLoop($this->proprietaire, 'Boucle motivee');
        $this->service->addMemberByUserId($this->boucle, $this->motive->id);
        $this->service->addMemberByUserId($this->boucle, $this->silencieux->id);

        $this->demande($this->motive, 'Ancienne raison.', LoopJoinRequest::STATUS_ACCEPTED, now()->subDays(3));
        $this->demande($this->motive, 'Je veux apprendre la permaculture.', LoopJoinRequest::STATUS_ACCEPTED, now()->subDay());
        $this->demande($this->silencieux, 'Raison refusee.', LoopJoinRequest::STATUS_REJECTED, now()->subDays(2));
        $this->demande($this->silencieux, 'Raison en attente.', LoopJoinRequest::STATUS_PENDING, now());
    }

    public function test_the_preview_shows_the_latest_accepted_reason_and_a_profile_link(): void
    {
        $html = $this->actingAs($this->proprietaire)
            ->get(route('organization.loops.preview', ['organization' => $this->org->slug, 'loop' => $this->boucle->id]))
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-loop-preview-member-reason'));
        $this->assertStringContainsString('Je veux apprendre la permaculture.', $html);
        $this->assertStringNotContainsString('Ancienne raison.', $html);
        $this->assertStringNotContainsString('Raison refusee.', $html);
        $this->assertStringNotContainsString('Raison en attente.', $html);

        foreach ([$this->proprietaire, $this->motive, $this->silencieux] as $membre) {
            $this->assertStringContainsString('href="'.$this->profil($membre).'"', $html);
        }
    }

    public function test_the_members_card_shows_the_reason_and_a_profile_link(): void
    {
        $html = Livewire::actingAs($this->proprietaire)
            ->test(LoopMembersCard::class, ['loop' => $this->boucle])
            ->html();

        $this->assertStringContainsString('data-loop-member-reason="'.$this->motive->id.'"', $html);
        $this->assertStringContainsString('Je veux apprendre la permaculture.', $html);
        $this->assertStringNotContainsString('data-loop-member-reason="'.$this->silencieux->id.'"', $html);
        $this->assertStringNotContainsString('Raison refusee.', $html);
        $this->assertStringContainsString('href="'.$this->profil($this->silencieux).'"', $html);
    }

    public function test_join_reasons_stay_inside_the_loop(): void
    {
        $autre = $this->service->createLoop($this->proprietaire, 'Autre Boucle');

        $this->assertSame([], LoopJoinRequest::joinReasonsFor($autre, [$this->motive->id]));
        $this->assertSame([], LoopJoinRequest::joinReasonsFor($this->boucle, []));
    }

    private function membre(): User
    {
        return User::factory()->complete()->create(['organization_id' => $this->org->id, 'email_verified_at' => now()]);
    }

    private function profil(User $user): string
    {
        return e(route('organization.profile.show', ['organization' => $this->org->slug, 'user' => $user->slug ?: $user->id]));
    }

    private function demande(User $user, string $message, string $status, $quand): void
    {
        $demande = LoopJoinRequest::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $user->id,
            'message' => $message,
            'status' => $status,
        ]);
        $demande->forceFill(['created_at' => $quand])->save();
    }
}
