<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1801 correctif 02 — un lien partage dans une Boucle doit se voir.
 *
 * `app.css` porte la couleur, le soulignement et le curseur (`.bp-bulle-markdown
 * a`, non mesurables ici) ; ce test garde la PRECONDITION dont ce correctif
 * depend : une URL NUE, collee telle quelle par un humain — pas une syntaxe
 * Markdown `[texte](url)` — doit toujours ressortir en vraie balise `<a
 * href>` A L'INTERIEUR du conteneur `bp-bulle-markdown`. Sans cette balise,
 * la regle CSS ne cible rien.
 */
class TASK1801LoopMessageLinkStyleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->loop = (new LoopService)->createLoop($this->member, 'Test Chat Loop', type: 'project');

        app()->instance('current_organization', $this->organization);
    }

    public function test_a_bare_url_pasted_by_a_human_becomes_a_real_link_inside_the_markdown_bubble(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Regarde ce lien https://example.com/article pour la suite',
            'type' => 'user',
        ]);

        $html = Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('<a href="https://example.com/article"')
            ->html();

        $bulle = preg_match(
            '#<div[^>]*class="[^"]*\bbp-bulle-markdown\b[^"]*"[^>]*>.*?<a href="https://example\.com/article".*?</div>#s',
            $html,
        );

        $this->assertSame(1, $bulle, 'Le lien doit etre rendu DANS le conteneur bp-bulle-markdown : la regle CSS de ce correctif ne cible que ca.');
    }
}
