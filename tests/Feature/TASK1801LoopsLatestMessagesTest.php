<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1801 correctif 05 — le bouton « Derniers messages » de `/loops`.
 *
 * Demande de Cyril : un bouton a la gauche de « Nouvelle », qui montre les
 * trois derniers messages des Boucles dont la personne est proprietaire,
 * animateur ou membre — Boucle et horodatage inclus, lien direct vers la
 * Boucle — avec deux etats degrades explicites :
 *
 * - **membre d'aucune Boucle** : un message d'invitation a en rejoindre une,
 *   dont le lien ferme le popup en renvoyant sur CETTE page ;
 * - **membre, mais aucun message nulle part** : rien d'autre ne s'affiche.
 */
class TASK1801LoopsLatestMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create([
            'is_active' => true,
            'loops_enabled' => true,
            // Un membre simple ne peut PAS creer de Boucle : garde le bouton
            // « Nouvelle » absent pour lui, condition dont le bouton
            // « Derniers messages » doit rester independant.
            'members_can_create_loops' => false,
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function page(User $user): string
    {
        return $this->actingAs($user)
            ->get(route('organization.loops.index', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();
    }

    /**
     * `created_at` n'est pas dans `$fillable` (LoopMessage) : passer par
     * `create()` le jetterait ou le laisserait a `now()`, et deux messages
     * crees a la meme microseconde rendraient l'ordre non deterministe
     * (lecon deja consignee du depot). On le pose APRES, hors assignation
     * de masse, avant la sauvegarde.
     */
    private function messageAt(Loop $loop, ?User $sender, string $body, string $type, \Illuminate\Support\Carbon $when): LoopMessage
    {
        $message = new LoopMessage([
            'loop_id' => $loop->id,
            'organization_id' => $this->org->id,
            'sender_id' => $sender?->id,
            'body' => $body,
            'type' => $type,
        ]);
        $message->created_at = $when;
        $message->save();

        return $message;
    }

    public function test_the_button_shows_even_when_the_viewer_cannot_create_a_loop(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $membre = User::factory()->create(['organization_id' => $this->org->id]);

        $service = new LoopService;
        $loop = $service->createLoop($owner, 'Boucle A');
        $service->addMemberByUserId($loop, $membre->id);

        // Boucles cachees par defaut : il en faut une 2e pour que `/loops`
        // affiche la liste au lieu de rediriger vers la Boucle unique.
        $service->createLoop($owner, 'Boucle B');

        $html = $this->page($membre);

        $this->assertStringContainsString('data-loops-latest-messages-trigger', $html);
        // `loop_composition_policy = locked` : seul le proprietaire compose,
        // ce membre ne voit donc pas « Nouvelle » — le bouton n'en depend pas.
        $this->assertStringNotContainsString('data-loops-new-mobile', $html);
    }

    public function test_a_member_sees_up_to_three_latest_messages_with_loop_link_and_timestamp(): void
    {
        $moi = User::factory()->create(['organization_id' => $this->org->id]);
        $autreMembre = User::factory()->create(['organization_id' => $this->org->id]);

        $service = new LoopService;
        $loopA = $service->createLoop($moi, 'Boucle des mentions');
        $loopB = $service->createLoop($moi, 'Boucle du jardin');
        $service->addMemberByUserId($loopB, $autreMembre->id, 'facilitator');
        // Une 3e Boucle a laquelle « moi » n'appartient PAS : visible au
        // catalogue (« decouvrir »), mais son message ne doit JAMAIS sortir
        // dans ce panneau.
        $loopEtrangere = $service->createLoop($autreMembre, 'Boucle etrangere');

        $a1 = $this->messageAt($loopA, $moi, 'Plus ancien message de A', 'user', now()->subMinutes(9));
        $a2 = $this->messageAt($loopA, $moi, 'Second message de A', 'user', now()->subMinutes(8));
        $a3 = $this->messageAt($loopA, $moi, 'Troisieme message de A', 'user', now()->subMinutes(7));
        $a4 = $this->messageAt($loopA, $moi, 'Dernier message de A', 'user', now()->subMinutes(6));
        $dernierB = $this->messageAt($loopB, $autreMembre, 'Dernier mot sur le jardin', 'user', now());

        // Hors perimetre : Boucle etrangere (pas de membre) et message IA
        // (aucun humain derriere) — tous deux plus RECENTS que tout le reste,
        // pour prouver qu'ils sont exclus par la regle et non par la date.
        $this->messageAt($loopEtrangere, $autreMembre, 'Message d une Boucle etrangere', 'user', now()->addMinute());
        $this->messageAt($loopA, null, 'Reponse IA', 'ai', now()->addMinutes(2));

        $html = $this->page($moi);

        // Les 3 plus recents ELIGIBLES : dernierB, a4, a3 — pas a1 ni a2.
        $this->assertStringContainsString($dernierB->body, $html);
        $this->assertStringContainsString($a4->body, $html);
        $this->assertStringContainsString($a3->body, $html);
        $this->assertStringNotContainsString($a2->body, $html);
        $this->assertStringNotContainsString($a1->body, $html);
        $this->assertStringNotContainsString('Message d une Boucle etrangere', $html);
        $this->assertStringNotContainsString('Reponse IA', $html);

        $this->assertStringContainsString($loopA->name, $html);
        $this->assertStringContainsString($loopB->name, $html);
        $this->assertStringContainsString(
            route('organization.loops.show', ['organization' => $this->org->slug, 'loop' => $loopA->slug ?: $loopA->id]),
            $html,
        );
        $this->assertStringContainsString($dernierB->created_at->isoFormat('D MMM, HH:mm'), $html);

        // Exactement 3 lignes de message dans le panneau, pas 5.
        $this->assertSame(3, substr_count($html, 'data-loops-latest-message-row'));
    }

    public function test_a_user_with_no_loop_membership_sees_the_join_prompt(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $service->createLoop($owner, 'Boucle A');
        $service->createLoop($owner, 'Boucle B');

        $sansBoucle = User::factory()->create(['organization_id' => $this->org->id]);

        $html = $this->page($sansBoucle);

        // L'apostrophe de `n'êtes`/`d'aucune` ressort HTML-echappee
        // (`&#039;`) dans le rendu Blade : `e()` reproduit la meme
        // transformation cote attendu.
        $this->assertStringContainsString(e(__('loops.latest_messages_empty_before')), $html);
        $this->assertStringContainsString(
            'href="'.route('organization.loops.index', ['organization' => $this->org->slug]).'"',
            $html,
        );
        $this->assertStringNotContainsString('data-loops-latest-message-row', $html);
    }

    public function test_a_member_whose_loops_have_no_message_sees_nothing_extra(): void
    {
        $moi = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $service->createLoop($moi, 'Boucle silencieuse A');
        $service->createLoop($moi, 'Boucle silencieuse B');

        $html = $this->page($moi);

        $this->assertStringContainsString('data-loops-latest-messages-trigger', $html);
        $this->assertStringNotContainsString(__('loops.latest_messages_empty_before'), $html);
        $this->assertStringNotContainsString('data-loops-latest-message-row', $html);
    }
}
