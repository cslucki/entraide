<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1301 — les sources d'une reponse IA s'affichent dans la bulle du fil.
 *
 * La donnee est la et correcte depuis T1297 : un message `type=ai` du chemin
 * knowledge porte `metadata['sources']` a la forme publique exacte de
 * `KnowledgeAnswer::publicSource()` (ref, title, dossier_name, excerpt, url),
 * l'URL etant ecrite COTE SERVEUR (T1296 : /preview si previewable, /show
 * sinon). Seul l'affichage manquait : message-bubble ignorait ces sources.
 *
 * LE POINT QUI DECIDE DE LA TASK — SECURITE : la bulle rend son corps en
 * `{!! !!}` non echappe, mais title/dossier_name/excerpt des sources sont DU
 * CONTENU DE DOCUMENT UPLOADE par des utilisateurs. Les sources doivent se
 * rendre ECHAPPEES ({{ }}), sinon un simple depot de fichier dans un Dossier
 * devient une injection HTML dans le fil. Le test au balisage le prouve.
 *
 * Invariants tenus ici : la vue ne re-derive JAMAIS l'URL (elle rend celle de
 * la metadata, opaque) ; une bulle sans sources est inchangee (chemins ask et
 * answer, qui n'ecrivent pas la cle) ; les bulles user et member_agent
 * n'affichent JAMAIS de sources, meme si leur metadata en portait.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1301AiSourcesInBubbleTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCES_MARKER = 'data-message-sources';

    private Organization $organization;

    private User $owner;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id, 'first_name' => 'Theo', 'name' => 'Dupont']);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id, 'first_name' => 'Maya', 'name' => 'Martin']);

        $loopService = new LoopService;
        $this->loop = $loopService->createLoop($this->owner, 'Boucle sources dans le fil');
        $loopService->addMember($this->loop, $this->member, 'member');

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // A. Une bulle IA avec sources les affiche — ref, titre, Dossier,
    //    extrait, lien tel qu'ecrit par le serveur.
    // =====================================================================

    public function test_an_ai_bubble_with_sources_displays_ref_title_dossier_excerpt_and_link(): void
    {
        $previewUrl = 'https://test.laravel/org/acme/dossiers/plans/files/01abc/preview';
        $showUrl = 'https://test.laravel/org/acme/dossiers/plans/files/02def/show';

        $this->aiMessage('Reponse fondee sur les documents [S1][S2].', 'knowledge', [
            $this->source('S1', 'Protocole d emergence', 'Dossier Plans', 'Extrait du protocole observe.', $previewUrl),
            $this->source('S2', 'Compte rendu atelier', 'Dossier Plans', 'Extrait du compte rendu.', $showUrl),
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Reponse fondee sur les documents')
            ->assertSee('[S1]')
            ->assertSee('Protocole d emergence')
            ->assertSee('[S2]')
            ->assertSee('Compte rendu atelier')
            ->assertSee('Dossier Plans')
            ->assertSee('Extrait du protocole observe.')
            ->assertSee('Extrait du compte rendu.')
            ->assertSee(__('loops.knowledge_sources_title'));

        // Le lien est CELUI de la metadata, ecrit cote serveur par
        // publicSource() (T1296) : /preview pour le previewable, /show sinon.
        // La vue ne re-derive rien — elle rend l'URL telle quelle.
        $html = $component->html();
        $this->assertStringContainsString('href="'.$previewUrl.'"', $html);
        $this->assertStringContainsString('href="'.$showUrl.'"', $html);
    }

    // =====================================================================
    // B. SECURITE — le balisage contenu dans une source sort ECHAPPE.
    // =====================================================================

    public function test_source_markup_in_title_and_excerpt_renders_escaped(): void
    {
        $title = '<script>alert("titre")</script>';
        $excerpt = '<img src=x onerror=alert(1)> extrait <b>gras</b>';
        $dossier = '<i>Dossier pirate</i>';

        $this->aiMessage('Reponse avec source piegee [S1].', 'knowledge', [
            $this->source('S1', $title, $dossier, $excerpt, 'https://test.laravel/org/acme/dossiers/p/files/03/preview'),
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(LoopChat::class, ['loop' => $this->loop])
            // Present, mais sous forme echappee (assertSee echappe par defaut).
            ->assertSee($title)
            ->assertSee($excerpt)
            ->assertSee($dossier);

        // Et JAMAIS sous forme brute : le balisage d'un document uploade ne
        // devient pas du HTML executable dans le fil.
        $html = $component->html();
        $this->assertStringNotContainsString('<script>alert("titre")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('<b>gras</b>', $html);
        $this->assertStringNotContainsString('<i>Dossier pirate</i>', $html);
        $this->assertStringContainsString(e($title), $html);
        $this->assertStringContainsString(e($excerpt), $html);
    }

    // =====================================================================
    // C. Non-regression par chemin — ask et answer (sans cle sources) rendent
    //    une bulle IA sans bloc sources ; knowledge est le seul a l'afficher.
    // =====================================================================

    public function test_ask_and_answer_bubbles_stay_free_of_sources_block_and_knowledge_shows_exactly_one(): void
    {
        // Les formes metadata REELLES des trois chemins d'ecriture `type=ai`
        // (ChatLoopAiService::ask()/answer(), LoopKnowledgeAnswerService).
        $this->aiMessage('Reponse du chemin ask.', 'ask', sources: null);
        $this->aiMessage('Reponse du chemin answer.', 'answer', sources: null);
        $this->aiMessage('Reponse du chemin knowledge.', 'knowledge', [
            $this->source('S1', 'Document cite', 'Dossier Plans', 'Extrait cite.', 'https://test.laravel/org/acme/dossiers/p/files/04/preview'),
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Reponse du chemin ask.')
            ->assertSee('Reponse du chemin answer.')
            ->assertSee('Reponse du chemin knowledge.');

        // Un seul bloc sources dans tout le fil : celui du message knowledge.
        $this->assertSame(1, substr_count($component->html(), self::SOURCES_MARKER));
    }

    public function test_an_ai_bubble_with_an_empty_sources_array_renders_no_sources_block(): void
    {
        // Chemin knowledge sans document cite : la cle existe, vide.
        $this->aiMessage('Reponse sans document consulte.', 'knowledge', sources: []);

        $component = Livewire::actingAs($this->owner)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Reponse sans document consulte.');

        $this->assertStringNotContainsString(self::SOURCES_MARKER, $component->html());
    }

    // =====================================================================
    // D. Les bulles user et member_agent n'affichent JAMAIS de sources —
    //    meme si leur metadata en portait une (le bloc est reserve a `ai`).
    // =====================================================================

    public function test_user_and_member_agent_bubbles_never_display_sources(): void
    {
        $stray = [$this->source('S9', 'Titre egare', 'Dossier egare', 'Extrait egare.', 'https://test.laravel/org/acme/dossiers/p/files/09/preview')];

        $this->message('user', $this->member, 'Message humain ordinaire.', ['sources' => $stray]);
        $this->message('member_agent', $this->member, 'Reponse de l agent du membre.', ['sources' => $stray, 'ai_generated' => true]);

        $component = Livewire::actingAs($this->owner)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Message humain ordinaire.')
            ->assertSee('Reponse de l agent du membre.')
            ->assertDontSee('Titre egare');

        $this->assertStringNotContainsString(self::SOURCES_MARKER, $component->html());
    }

    // =====================================================================
    // E. Affichage pur — aucune invocation provider, ledger intact.
    // =====================================================================

    public function test_rendering_sources_costs_nothing(): void
    {
        $this->aiMessage('Reponse affichee sans depense.', 'knowledge', [
            $this->source('S1', 'Document', 'Dossier', 'Extrait.', 'https://test.laravel/org/acme/dossiers/p/files/05/preview'),
        ]);

        $before = DB::table('ai_provider_invocations')->count();

        Livewire::actingAs($this->owner)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Reponse affichee sans depense.');

        $this->assertSame($before, DB::table('ai_provider_invocations')->count());
        $this->assertSame(0, $before);
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /** @return array<string, mixed> Forme publique EXACTE de publicSource(). */
    private function source(string $ref, string $title, string $dossier, string $excerpt, string $url): array
    {
        return [
            'ref' => $ref,
            'title' => $title,
            'dossier_name' => $dossier,
            'excerpt' => $excerpt,
            'url' => $url,
        ];
    }

    /** @param list<array<string, mixed>>|null $sources */
    private function aiMessage(string $body, string $action, ?array $sources): LoopMessage
    {
        $metadata = [
            'requested_by' => $this->owner->id,
            'action' => $action,
        ];

        if ($sources !== null) {
            $metadata['sources'] = $sources;
        }

        return $this->message('ai', null, $body, $metadata);
    }

    /** @param array<string, mixed>|null $metadata */
    private function message(string $type, ?User $sender, string $body, ?array $metadata = null): LoopMessage
    {
        return LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $sender?->id,
            'body' => $body,
            'type' => $type,
            'metadata' => $metadata,
            'organization_id' => $this->loop->organization_id,
        ]);
    }
}
