<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopService;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiRefusedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1231 — lot 0 : « Demander a l'IA » (`ChatLoopAiService::ask` /
 * `::answer`, chemin herite) passe sous `AiEconomicGuard`, comme `summarize()`
 * depuis TASK-1229.
 *
 * Contrats verifies :
 *  - le refus vit AVANT l'appel provider : zero requete HTTP, zero ligne
 *    `ai_interactions`, zero ligne de ledger, aucun message de Boucle ;
 *  - trois etats de refus, memes codes qu'ailleurs (credit utilisateur,
 *    budget Organization) — aucun nouveau code ;
 *  - ABSENCE DE DOUBLE COMPTAGE : un appel reussi ecrit exactement UNE ligne
 *    `ai_interactions` et ZERO ligne de ledger, comme avant le lot 0, et le
 *    credit de l'utilisateur avance d'exactement 1 ;
 *  - REGRESSION : un utilisateur sans plafond dans une Organization sous son
 *    budget ne voit strictement aucun changement (message IA publie, trace
 *    identique) ;
 *  - le controleur `askAi` restitue le refus tel quel (flash error), sans
 *    appel.
 */
class TASK1231ChatLoopLegacyGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $member;

    private User $superAdmin;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1231', 'name' => 'Org Lot Zero']);
        // Le chemin herite resout provider/modele via AiConfig plateforme ;
        // l'OrganizationAiSetting ne sert ici qu'au budget Organization.
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'monthly_budget_usd' => null,
        ]);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Owner', 'first_name' => 'Lot']);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Member', 'first_name' => 'Lot']);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Super', 'first_name' => 'Lot', 'is_admin' => true]);

        app()->instance('current_organization', $this->organization);
        $loops = new LoopService;
        $this->loop = $loops->createLoop($this->owner, 'Boucle lot zero');
        $loops->addMember($this->loop, $this->member, 'member');

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Bonjour, je prepare le lancement de mon activite et je cherche des retours '
                .'d\'experience pour etablir ma grille de prix et ma strategie de premiers clients.',
            'type' => 'user',
            'organization_id' => $this->organization->id,
        ]);

        config([
            'ai.openai.api_key' => 'test-key',
            'ai.chatloop.min_summary_words' => 0,
            'ai.chatloop.enabled' => true,
        ]);
        AiConfig::set('default_provider', 'openai');
        AiConfig::set('default_model', 'gpt-4o-mini');

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Reponse de l\'IA.']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 18],
            ]),
        ]);
    }

    // =====================================================================
    // Refus AVANT l'appel — credit utilisateur epuise
    // =====================================================================

    public function test_ask_is_refused_with_the_credit_code_before_any_provider_call(): void
    {
        $this->platformQuota(1);
        $this->uses($this->member, 1);
        $before = $this->counters();

        try {
            $this->service()->ask($this->loop, $this->member, 'Quel prix pratiquer ?');
            $this->fail('Expected a credit refusal.');
        } catch (AiRefusedException $e) {
            $this->assertSame(AiRefusedException::CODE_USER_CREDIT_EXHAUSTED, $e->refusalCode);
            $this->assertNotNull($e->offersUrl($this->organization));
        }

        Http::assertNothingSent();
        $this->assertSame($before, $this->counters(), 'Un refus n\'ecrit rien : ni trace, ni ledger, ni message.');
        $this->assertSame(1, $this->guard()->userCreditStatus($this->organization, $this->member)->used);
    }

    public function test_answer_is_refused_with_the_credit_code_before_any_provider_call(): void
    {
        $this->platformQuota(1);
        $this->uses($this->member, 1);
        $before = $this->counters();

        try {
            $this->service()->answer($this->loop, $this->member);
            $this->fail('Expected a credit refusal.');
        } catch (AiRefusedException $e) {
            $this->assertSame(AiRefusedException::CODE_USER_CREDIT_EXHAUSTED, $e->refusalCode);
        }

        Http::assertNothingSent();
        $this->assertSame($before, $this->counters());
    }

    // =====================================================================
    // Refus AVANT l'appel — budget Organization atteint (credit intact)
    // =====================================================================

    public function test_ask_is_refused_with_the_organization_budget_code_when_the_budget_is_reached(): void
    {
        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->update(['monthly_budget_usd' => 0.0005]);
        // Une generation connue d'un AUTRE membre consomme le budget de l'Organization.
        $this->generation($this->owner, 0.001);
        $before = $this->counters();

        try {
            $this->service()->ask($this->loop, $this->member, 'Quel prix pratiquer ?');
            $this->fail('Expected an organization budget refusal.');
        } catch (AiRefusedException $e) {
            $this->assertSame(AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED, $e->refusalCode);
            $this->assertNull($e->offersUrl($this->organization), 'Le budget Organization n\'offre jamais un abonnement utilisateur.');
        }

        Http::assertNothingSent();
        $this->assertSame($before, $this->counters());
        // Le credit du membre est intact : ce n'est pas lui qui bloque.
        $this->assertSame(0, $this->guard()->userCreditStatus($this->organization, $this->member)->used);
    }

    // =====================================================================
    // Regression + absence de double comptage
    // =====================================================================

    public function test_a_user_without_cap_in_an_organization_under_budget_sees_no_change_and_is_counted_once(): void
    {
        $this->platformQuota(null); // illimite
        $before = $this->counters();
        $usedBefore = $this->guard()->userCreditStatus($this->organization, $this->member)->used;

        $message = $this->service()->ask($this->loop, $this->member, 'Quel prix pratiquer ?');

        Http::assertSentCount(1);
        $this->assertSame('ai', $message->type);
        $this->assertSame('Reponse de l\'IA.', $message->body);
        $this->assertSame('ask', $message->metadata['action']);

        $after = $this->counters();
        // Exactement UNE trace ai_interactions de plus, ZERO ligne de ledger de
        // plus (comme avant le lot 0 : callAi() ne passe pas par le ledger),
        // deux messages de Boucle (question + reponse).
        $this->assertSame($before['interactions'] + 1, $after['interactions']);
        $this->assertSame($before['ledger'], $after['ledger']);
        $this->assertSame($before['messages'] + 2, $after['messages']);

        $interaction = AiInteraction::query()->latest('id')->first();
        $this->assertSame('chatloop_ai_ask', $interaction->feature);
        $this->assertSame('chatloop.ask', $interaction->process);
        $this->assertSame($this->member->id, $interaction->user_id);
        $this->assertSame($this->organization->id, $interaction->organization_id);

        // Le credit avance d'exactement 1 : la garde n'a rien ajoute au compte.
        $this->assertSame($usedBefore + 1, $this->guard()->userCreditStatus($this->organization, $this->member)->used);
    }

    public function test_answer_for_a_user_without_cap_is_unchanged_and_counted_once(): void
    {
        $this->platformQuota(null);
        $before = $this->counters();

        $message = $this->service()->answer($this->loop, $this->member);

        Http::assertSentCount(1);
        $this->assertSame('answer', $message->metadata['action']);
        $after = $this->counters();
        $this->assertSame($before['interactions'] + 1, $after['interactions']);
        $this->assertSame($before['ledger'], $after['ledger']);
        $this->assertSame($before['messages'] + 1, $after['messages']);
        $this->assertSame(1, $this->guard()->userCreditStatus($this->organization, $this->member)->used);
    }

    public function test_a_finite_credit_under_its_quota_still_lets_the_call_through_and_counts_once(): void
    {
        $this->platformQuota(3);
        $this->uses($this->member, 2);

        $this->service()->ask($this->loop, $this->member, 'Encore une question.');

        Http::assertSentCount(1);
        $status = $this->guard()->userCreditStatus($this->organization, $this->member);
        $this->assertSame(3, $status->used);
        $this->assertTrue($status->isExhausted(), 'La troisieme utilisation consomme le credit ; la suivante sera refusee.');

        // Et la suivante est refusee, sans appel supplementaire.
        try {
            $this->service()->ask($this->loop, $this->member, 'Une de trop.');
            $this->fail('Expected a credit refusal.');
        } catch (AiRefusedException $e) {
            $this->assertSame(AiRefusedException::CODE_USER_CREDIT_EXHAUSTED, $e->refusalCode);
        }
        Http::assertSentCount(1);
    }

    // =====================================================================
    // Controleur : le refus est restitue tel quel, sans appel
    // =====================================================================

    public function test_the_ask_ai_endpoint_flashes_the_refusal_and_calls_nothing(): void
    {
        $this->platformQuota(1);
        $this->uses($this->member, 1);
        $before = $this->counters();

        $response = $this->actingAs($this->member)
            ->post(route('organization.loops.ai', ['organization' => $this->organization, 'loop' => $this->loop]), [
                'action' => 'ask',
                'question' => 'Quel prix pratiquer ?',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            trans_choice('ai.credit_refusal_user_exhausted', 1, ['used' => 1, 'quota' => 1, 'date' => now()->startOfMonth()->addMonth()->format('d/m/Y')]),
            (string) session('error'),
        );
        Http::assertNothingSent();
        $this->assertSame($before, $this->counters());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function service(): ChatLoopAiService
    {
        return app(ChatLoopAiService::class);
    }

    private function guard(): AiEconomicGuard
    {
        return app(AiEconomicGuard::class);
    }

    private function platformQuota(?int $monthlyUses): void
    {
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true,
            'monthly_uses' => $monthlyUses,
            'alert_percent' => 80,
            'offer_subscription' => true,
        ], $this->superAdmin);
    }

    /**
     * @return array{interactions: int, ledger: int, messages: int}
     */
    private function counters(): array
    {
        return [
            'interactions' => AiInteraction::query()->count(),
            'ledger' => AiProviderInvocation::query()->count(),
            'messages' => LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
        ];
    }

    private function uses(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->generation($user, 0.001);
        }
    }

    /**
     * Une generation deja emise (credit + budget), hors de tout appel :
     * meme forme que la trace ecrite par callAi().
     */
    private function generation(User $user, float $cost): void
    {
        AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $this->organization->id,
            'correlation_id' => (string) Str::uuid(),
            'process' => 'chatloop.ask',
            'feature' => 'chatloop_ai_ask',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => $cost,
            'cost_unknown' => false,
            'metadata' => ['provider' => 'openai', 'status' => 'success'],
        ]);
    }
}
