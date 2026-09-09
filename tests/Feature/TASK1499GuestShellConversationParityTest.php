<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\GuestShell\GuestConversationService;
use App\Services\GuestShell\GuestShellGate;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestShellResponder;
use App\Services\GuestShell\GuestVisitorResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1499 — la conversation Guest se comporte comme une conversation.
 *
 * ## Ce que la MESURE a corrige dans le diagnostic
 *
 * Conversation reelle de Cyril (`01a0873b`), relue en base :
 *
 * | Tour | Message | Reponse |
 * |---|---|---|
 * | 1 | « Je m'appelle CYril... » | « **Bonjour Cyril !** Bienvenue... » |
 * | 2 | « Oui » | « Super, Cyril ! L'atelier... » |
 * | 3 | « Oui combien ca coute ? » | « **Bonjour Cyril !** Je ne peux pas... » |
 * | 4 | « C'est quoi le mycelium ? » | « **Bonjour Cyril !** Le mycelium... » |
 *
 * Le tour 2 prouve que l'historique EST transmis : le modele y retrouve le
 * prenom ET le sujet. Les tours 3 et 4 connaissent encore « Cyril », que le
 * visiteur n'a jamais repete.
 *
 * **L'historique ne manquait donc pas.** Deux choses manquaient :
 *
 * 1. le prompt en base dit « Tu l'ACCUEILLES au nom de cette organisation » —
 *    juste au premier tour, faux a tous les suivants, et rien ne disait au
 *    modele qu'il etait deja en conversation ;
 * 2. le transcrit etait aplati en UN seul message utilisateur, sans marqueur :
 *    le tour courant se confondait avec le reste, d'ou l'anaphore ratee sur
 *    « ca ».
 *
 * Un test qui se contenterait de verifier « le contexte contient le tour 1 »
 * serait donc VERT avant comme apres, et ne mesurerait rien. Ces tests portent
 * sur ce qui a reellement change.
 */
class TASK1499GuestShellConversationParityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private GuestVisitor $visitor;

    private GuestConversation $conversation;

    private GuestShellResponder $responder;

    private GuestConversationService $conversations;

    /**
     * La cle BRUTE du cookie visiteur.
     *
     * `GuestVisitorResolver::find()` retrouve un visiteur par
     * `visitor_key_hash` = hash(cle). La cle en clair n'existe donc qu'au
     * moment ou on la pose : la relire depuis le modele est impossible, et
     * c'est voulu. On la conserve ici pour rejouer la MEME identite.
     */
    private string $visitorKey;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.guest_shell.platform_monthly_ceiling_usd' => 5.0,
            'ai.guest_shell.visitor_monthly_max_messages' => 30,
            'ai.guest_shell.rate_limit_per_minute' => 60,
            'ai.guest_shell.max_output_tokens' => 650,
            'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0,
        ]);

        $this->org = Organization::factory()->create(['slug' => 'org-1499', 'name' => 'CyberWorkers', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => true, 'max_messages' => 10]);
        OrganizationAiSetting::create(['organization_id' => $this->org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-not-a-real-key', 'is_enabled' => true]);

        $this->visitorKey = Str::random(64);
        $this->visitor = app(GuestVisitorResolver::class)->ensure(
            Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $this->visitorKey]),
            $this->org,
            ['locale' => 'fr']
        );
        $this->conversations = app(GuestConversationService::class);
        $this->conversation = $this->conversations->start($this->visitor);
        $this->responder = app(GuestShellResponder::class);
        RateLimiter::clear(GuestShellGate::rateKey($this->org, $this->visitor));
    }

    private function reply(string $text): TextResponse
    {
        return new TextResponse($text, new Usage(50, 20), new Meta('openrouter', 'openai/gpt-4o-mini'));
    }

    private function respond(string $message): void
    {
        RateLimiter::clear(GuestShellGate::rateKey($this->org, $this->visitor));
        $this->responder->respond($this->org->fresh(), $this->visitor->fresh(), $this->conversation->fresh(), $message);
    }

    /** Le PREMIER tour ne recoit PAS l'instruction de continuite : l'accueil y est juste. */
    public function test_the_first_turn_is_not_told_it_is_continuing(): void
    {
        GuestShellAgent::fake([$this->reply('Bonjour ! Bienvenue.')]);

        $this->respond('Je m\'appelle Cyril.');

        GuestShellAgent::assertPrompted(function (AgentPrompt $p) {
            $instructions = (string) $p->agent->instructions();

            return ! str_contains($instructions, 'DEJA ENGAGEE')
                && ! str_contains($p->prompt, '[CONVERSATION EN COURS]');
        });
    }

    /**
     * Le second tour porte l'instruction de continuite ET le tour precedent.
     *
     * C'est le test que MASTER a demande — « le contexte envoye au fake doit
     * contenir TURN 1 » — RENFORCE de ce qui manquait vraiment : l'instruction
     * de ne pas re-saluer. Sans cette seconde moitie, le test serait vert avant
     * comme apres le correctif.
     */
    public function test_the_second_turn_carries_the_transcript_and_the_continuity_instruction(): void
    {
        GuestShellAgent::fake([$this->reply('Bonjour Cyril !'), $this->reply('Voici l\'atelier.')]);

        $this->respond('Je m\'appelle Cyril.');
        $this->respond('Quel est mon prenom ?');

        GuestShellAgent::assertPrompted(function (AgentPrompt $p) {
            $instructions = (string) $p->agent->instructions();

            // Seul le SECOND appel nous interesse : il porte le transcrit.
            if (! str_contains($p->prompt, '[CONVERSATION EN COURS]')) {
                return false;
            }

            return str_contains($p->prompt, 'Je m\'appelle Cyril.')
                && str_contains($p->prompt, 'Visiteur : Quel est mon prenom ?')
                && str_contains($p->prompt, '[MESSAGE ACTUEL]')
                && str_contains($instructions, 'DEJA ENGAGEE');
        });
    }

    /**
     * Le tour COURANT est distingue du transcrit. C'est ce marqueur qui
     * manquait quand « Oui combien ca coute ? » etait traite comme une question
     * isolee sur les couts.
     */
    public function test_the_current_turn_is_labelled_apart_from_the_transcript(): void
    {
        GuestShellAgent::fake([$this->reply('Un atelier existe.'), $this->reply('Il est gratuit.')]);

        $this->respond('Quel atelier me conseilles-tu ?');
        $this->respond('Oui combien ca coute ?');

        GuestShellAgent::assertPrompted(function (AgentPrompt $p) {
            if (! str_contains($p->prompt, '[MESSAGE ACTUEL]')) {
                return false;
            }

            $current = substr($p->prompt, strpos($p->prompt, '[MESSAGE ACTUEL]'));

            // Le sujet precedent est dans le transcrit, PAS dans le tour courant.
            return str_contains($current, 'Oui combien ca coute ?')
                && ! str_contains($current, 'Quel atelier me conseilles-tu ?')
                && str_contains($p->prompt, 'Assistant : Un atelier existe.');
        });
    }

    /** L'isolation : la conversation d'un AUTRE visiteur n'entre jamais dans le contexte. */
    public function test_another_visitors_conversation_never_reaches_the_context(): void
    {
        $other = app(GuestVisitorResolver::class)->ensure(
            Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]),
            $this->org,
            ['locale' => 'fr']
        );
        $otherConversation = $this->conversations->start($other);
        $this->conversations->acceptUserMessage($otherConversation, 'SECRET DE L AUTRE VISITEUR T1499');

        GuestShellAgent::fake([$this->reply('Bonjour.'), $this->reply('Suite.')]);
        $this->respond('Mon premier message.');
        $this->respond('Mon second message.');

        GuestShellAgent::assertPrompted(fn (AgentPrompt $p) => ! str_contains($p->prompt, 'SECRET DE L AUTRE VISITEUR T1499'));
    }

    /**
     * WP-D §2 — la fenetre est bornee en CARACTERES, pas seulement en nombre de
     * messages. Dix tours a 2000 caracteres plus les reponses, c'est une charge
     * utile que rien ne plafonnait : un compteur de messages n'est pas un
     * budget.
     *
     * On coupe les plus ANCIENS : la continuite se joue sur les derniers tours,
     * c'est « ca » et « oui » qu'il faut pouvoir resoudre.
     */
    public function test_the_history_window_is_bounded_in_characters(): void
    {
        config(['ai.guest_shell.history_max_chars' => 400]);

        GuestShellAgent::fake([
            $this->reply(str_repeat('A', 300)),
            $this->reply(str_repeat('B', 300)),
            $this->reply('Court.'),
        ]);

        $this->respond('TRES ANCIEN MESSAGE T1499');
        $this->respond('MESSAGE INTERMEDIAIRE T1499');
        $this->respond('MESSAGE RECENT T1499');

        GuestShellAgent::assertPrompted(function (AgentPrompt $p) {
            if (! str_contains($p->prompt, 'MESSAGE RECENT T1499')) {
                return false;
            }

            // Le tour le plus ancien est tombe hors budget ; le plus recent est la.
            return ! str_contains($p->prompt, 'TRES ANCIEN MESSAGE T1499');
        });
    }

    /**
     * Fail-safe : un budget vide ou negatif ne DESACTIVE pas la borne. Une garde
     * qu'une configuration vide peut ouvrir n'est pas une garde.
     */
    public function test_an_empty_character_budget_falls_back_instead_of_disabling_the_bound(): void
    {
        config(['ai.guest_shell.history_max_chars' => 0]);

        GuestShellAgent::fake([$this->reply('Une reponse.'), $this->reply('Une autre.')]);
        $this->respond('Premier message.');
        $this->respond('Second message.');

        GuestShellAgent::assertPrompted(fn (AgentPrompt $p) => str_contains($p->prompt, '[CONVERSATION EN COURS]')
            && str_contains($p->prompt, 'Premier message.'));
    }

    /**
     * Une NOUVELLE conversation Guest ne reprend pas l'ancienne (WP-D §3-C).
     * Le contrat actuel ne la conserve pas, et ce test le fige.
     */
    public function test_a_new_conversation_does_not_carry_the_previous_one(): void
    {
        GuestShellAgent::fake([$this->reply('Reponse 1.'), $this->reply('Reponse 2.')]);
        $this->respond('ANCIENNE CONVERSATION T1499');

        // Une nouvelle conversation pour le MEME visiteur.
        $fresh = $this->conversations->start($this->visitor->fresh());
        RateLimiter::clear(GuestShellGate::rateKey($this->org, $this->visitor));
        $this->responder->respond($this->org->fresh(), $this->visitor->fresh(), $fresh, 'NOUVELLE CONVERSATION T1499');

        GuestShellAgent::assertPrompted(fn (AgentPrompt $p) => str_contains($p->prompt, 'NOUVELLE CONVERSATION T1499')
            && ! str_contains($p->prompt, 'ANCIENNE CONVERSATION T1499'));
    }

    // ── Rendu canonique (§7) ────────────────────────────────────────────────

    /** Un lien Markdown devient cliquable, par le MEME helper que la bulle membre. */
    public function test_a_markdown_link_is_rendered_as_html(): void
    {
        $payload = $this->payloadFor('[Créer un compte](https://test.laravel/org/main/register)');

        $this->assertStringContainsString('<a href="https://test.laravel/org/main/register"', $payload['html']);
        $this->assertStringContainsString('Créer un compte</a>', $payload['html']);
    }

    /** Le HTML brut d'un modele est ECHAPPE, jamais interprete. */
    public function test_raw_html_from_the_model_is_escaped(): void
    {
        $payload = $this->payloadFor('<script>alert(1)</script><b>gras</b>');

        $this->assertStringNotContainsString('<script>', $payload['html']);
        $this->assertStringNotContainsString('<b>gras</b>', $payload['html']);
        $this->assertStringContainsString('&lt;script&gt;', $payload['html']);
    }

    /** Un lien `javascript:` ne survit pas au rendu. */
    public function test_a_javascript_link_is_not_rendered(): void
    {
        $payload = $this->payloadFor('[clique](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $payload['html']);
    }

    /** Le texte simple reste du texte simple. */
    public function test_plain_text_stays_plain(): void
    {
        $payload = $this->payloadFor('Bonjour, comment allez-vous ?');

        $this->assertStringContainsString('Bonjour, comment allez-vous ?', $payload['html']);
        $this->assertStringNotContainsString('<a ', $payload['html']);
    }

    /** §8 — chaque message porte son heure, au format de la ChatLoop canonique. */
    public function test_every_message_carries_a_timestamp_label(): void
    {
        $payload = $this->payloadFor('Bonjour.');

        $this->assertNotSame('', $payload['at'], 'l\'horodatage ISO manque');
        $this->assertNotSame('', $payload['at_label'], 'le libelle d\'heure manque');
        $this->assertSame(
            GuestMessage::orderByDesc('id')->first()->created_at->diffForHumans(),
            $payload['at_label'],
            'le libelle doit etre celui de la ChatLoop canonique (diffForHumans)'
        );
    }

    /** @return array<string, mixed> */
    private function payloadFor(string $assistantBody): array
    {
        GuestShellAgent::fake([$this->reply($assistantBody)]);
        $this->respond('Une question.');

        $surface = app(\App\Services\GuestShell\GuestShellSurface::class);
        $read = $surface->read($this->org->fresh(), Request::create('/', 'GET', cookies: [
            GuestVisitorResolver::COOKIE => $this->visitorKey,
        ]));

        $messages = $read['conversation']['messages'] ?? [];
        $assistant = collect($messages)->firstWhere('role', GuestMessage::ROLE_ASSISTANT);

        $this->assertNotNull($assistant, 'aucun message assistant dans la charge utile — le test ne mesurerait rien');

        return $assistant;
    }
}
