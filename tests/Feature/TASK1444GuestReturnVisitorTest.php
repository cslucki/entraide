<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1444 — SW-9 : return visitor / expiration / retention (V3 §21, MASTER
 * Q72). Preuves HTTP de bout en bout, aucune nouvelle feature : meme cookie +
 * meme Organization = meme visiteur et derniere conversation lisible ;
 * visiteur expire = jamais ressuscite, GET n'ecrit rien, nouveau visiteur au
 * prochain geste ; aucun melange cross-tenant ; nouvelle retention appliquee
 * au prochain geste seulement ; purge en cascade sans toucher un User claime ;
 * contexte reconstruit a chaque tour.
 */
class TASK1444GuestReturnVisitorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        $policies = app(GuestShellPolicyService::class);
        foreach (['a', 'b'] as $key) {
            $org = Organization::factory()->create(['slug' => "org-{$key}-14xx", 'name' => strtoupper($key).' Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'hero_title' => "ACCROCHE-{$key}-V1"]);
            $policies->update($org, ['enabled' => true, 'max_messages' => 5, 'retention_days' => 30]);
            OrganizationAiSetting::create(['organization_id' => $org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
            $this->{$key} = $org;
        }
    }

    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    private function asBrowser(string $raw): static
    {
        return $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $this->encryptedCookie($raw));
    }

    private function endpoint(Organization $organization): string
    {
        return route('organization.shell.message', ['organization' => $organization->slug]);
    }

    private function read(Organization $organization): string
    {
        return route('organization.shell.show', ['organization' => $organization->slug]);
    }

    /** Un visiteur connu de l'Organization, avec une conversation et un message, tel que SW-3/SW-4 le produisent. */
    private function knownVisitor(Organization $organization, string $raw, int $expiresInDays = 30): GuestVisitor
    {
        GuestShellAgent::fake([new TextResponse('Bienvenue.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->asBrowser($raw)->postJson($this->endpoint($organization), ['message' => 'Premier message'])->assertOk()->assertJsonPath('turn', 'answered');
        $visitor = GuestVisitor::forOrganization($organization)->where('visitor_key_hash', GuestVisitorResolver::hash($raw))->firstOrFail();
        $visitor->forceFill(['expires_at' => now()->addDays($expiresInDays)])->save();

        return $visitor->fresh();
    }

    // ── 1. Visiteur valide ─────────────────────────────────────────────────

    public function test_a_valid_return_finds_the_same_visitor_and_resumes_the_last_readable_conversation_without_extending_retention_on_get(): void
    {
        $raw = Str::random(64);
        $visitor = $this->knownVisitor($this->a, $raw);
        $expires = $visitor->expires_at;

        $this->travel(2)->days();
        $json = $this->asBrowser($raw)->getJson($this->read($this->a))->assertOk()->json();
        $this->assertSame(1, $json['conversation']['message_count'], 'la derniere conversation lisible est reprise');
        $this->assertSame(1, GuestVisitor::count(), 'aucun visiteur cree par un GET');
        $this->assertTrue($visitor->fresh()->expires_at->equalTo($expires), 'un simple GET ne prolonge pas la retention');

        GuestShellAgent::fake([new TextResponse('Encore.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->asBrowser($raw)->postJson($this->endpoint($this->a), ['message' => 'Je reviens'])->assertOk()->assertJsonPath('conversation.message_count', 2);
        $this->assertSame(1, GuestVisitor::count(), 'meme visiteur');
        $this->assertSame(1, GuestConversation::count(), 'meme conversation');
        $this->assertTrue($visitor->fresh()->expires_at->greaterThan($expires), 'le geste rafraichit la retention');
        $this->assertTrue($visitor->fresh()->expires_at->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
    }

    // ── 2. Visiteur expire ─────────────────────────────────────────────────

    public function test_an_expired_visitor_is_never_resurrected_a_get_writes_nothing_and_the_next_gesture_starts_fresh(): void
    {
        $raw = Str::random(64);
        $old = $this->knownVisitor($this->a, $raw, expiresInDays: -1);
        $oldConversation = GuestConversation::firstOrFail();
        $this->assertTrue($old->isExpired());

        $json = $this->asBrowser($raw)->getJson($this->read($this->a))->assertOk()->assertCookieMissing(GuestVisitorResolver::COOKIE)->json();
        $this->assertNull($json['conversation'], 'un cookie expire ne relit rien');
        $this->assertNotNull(GuestVisitor::find($old->id), 'un GET ne supprime ni ne ressuscite');
        $this->assertTrue(GuestVisitor::find($old->id)->isExpired());

        GuestShellAgent::fake([new TextResponse('Nouveau depart.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $response = $this->asBrowser($raw)->postJson($this->endpoint($this->a), ['message' => 'Re-bonjour'])->assertOk();
        $response->assertJsonPath('turn', 'answered')->assertJsonPath('conversation.message_count', 1);
        $this->assertNull(GuestVisitor::find($old->id), 'la ligne expiree n\'est pas ressuscitee : elle est remplacee');
        $this->assertNull(GuestConversation::find($oldConversation->id), 'l\'ancienne conversation suit son visiteur (cascade)');
        $this->assertSame(1, GuestVisitor::count());
        $fresh = GuestVisitor::firstOrFail();
        $this->assertNotSame($old->id, $fresh->id);
        $this->assertSame(GuestVisitorResolver::hash($raw), $fresh->visitor_key_hash, 'le meme cookie, un nouveau visiteur');
        $this->assertSame(1, GuestConversation::count(), 'nouvelle conversation, jamais l\'ancienne');
    }

    // ── 3. Autre Organization ──────────────────────────────────────────────

    public function test_the_same_opaque_cookie_never_resumes_across_organizations(): void
    {
        $raw = Str::random(64);
        $this->knownVisitor($this->a, $raw);

        $json = $this->asBrowser($raw)->getJson($this->read($this->b))->assertOk()->json();
        $this->assertNull($json['conversation']);
        $this->assertSame(1, GuestVisitor::count());

        GuestShellAgent::fake([new TextResponse('Chez B.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->asBrowser($raw)->postJson($this->endpoint($this->b), ['message' => 'Bonjour B'])->assertOk()->assertJsonPath('conversation.message_count', 1);
        $this->assertSame(2, GuestVisitor::count(), 'un visiteur par Organization');
        $this->assertSame(1, GuestVisitor::forOrganization($this->a)->count());
        $this->assertSame(1, GuestConversation::forOrganization($this->a->id)->firstOrFail()->message_count, 'la conversation de A est intacte');
    }

    // ── 4. Changement de retention ─────────────────────────────────────────

    public function test_a_new_retention_applies_at_the_next_gesture_only_and_never_recomputes_other_visitors(): void
    {
        $raw = Str::random(64);
        $visitor = $this->knownVisitor($this->a, $raw);
        $other = $this->knownVisitor($this->a, Str::random(64));
        $otherExpires = $other->expires_at;

        app(GuestShellPolicyService::class)->update($this->a, ['retention_days' => 7]);
        $this->assertTrue($visitor->fresh()->expires_at->equalTo($visitor->expires_at), 'aucun recalcul massif');
        $this->asBrowser($raw)->getJson($this->read($this->a))->assertOk();
        $this->assertTrue($visitor->fresh()->expires_at->equalTo($visitor->expires_at), 'ni au GET');

        GuestShellAgent::fake([new TextResponse('Ok.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->asBrowser($raw)->postJson($this->endpoint($this->a), ['message' => 'Encore'])->assertOk();
        $this->assertTrue($visitor->fresh()->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()), 'la nouvelle duree s\'applique au geste');
        $this->assertTrue($other->fresh()->expires_at->equalTo($otherExpires), 'les autres visiteurs ne bougent pas');
    }

    // ── 5. Purge ───────────────────────────────────────────────────────────

    public function test_the_purge_cascades_conversations_and_messages_and_never_touches_a_claimed_user(): void
    {
        $expiredRaw = Str::random(64);
        $expired = $this->knownVisitor($this->a, $expiredRaw, expiresInDays: -3);
        $alive = $this->knownVisitor($this->a, Str::random(64));
        $user = User::factory()->create(['organization_id' => $this->a->id]);
        $expired->forceFill(['claimed_user_id' => $user->id, 'claimed_at' => now()])->save();
        $this->assertSame(2, GuestConversation::count());
        $this->assertSame(4, GuestMessage::count());

        Artisan::call('guest:purge-expired');

        $this->assertNull(GuestVisitor::find($expired->id));
        $this->assertNotNull(GuestVisitor::find($alive->id));
        $this->assertSame(1, GuestConversation::count(), 'cascade conversations');
        $this->assertSame(2, GuestMessage::count(), 'cascade messages');
        $this->assertNotNull(User::find($user->id), 'jamais le User claime');
        $this->assertSame(2, AiProviderInvocation::count(), 'le ledger economique reste (autorite comptable)');

        // Le cookie de l'ancien visiteur ne relit rien et ne cree rien au GET.
        $this->assertNull($this->asBrowser($expiredRaw)->getJson($this->read($this->a))->assertOk()->json('conversation'));
        $this->assertSame(1, GuestVisitor::count());
    }

    // ── 6. Contexte reconstruit a chaque tour ──────────────────────────────

    public function test_the_context_is_rebuilt_at_every_turn_and_nothing_public_is_frozen_in_the_visitor(): void
    {
        foreach (['page_context', 'usage_reference', 'context', 'prompt', 'transcript', 'ip', 'ip_address', 'user_agent'] as $column) {
            $this->assertFalse(Schema::hasColumn('guest_visitors', $column), "guest_visitors.{$column} ne doit pas exister");
        }

        $raw = Str::random(64);
        $this->knownVisitor($this->a, $raw);
        $this->a->update(['hero_title' => 'ACCROCHE-a-V2']);

        GuestShellAgent::fake([new TextResponse('Recalcule.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->asBrowser($raw)->postJson($this->endpoint($this->a), ['message' => 'Et maintenant ?'])->assertOk()->assertJsonPath('turn', 'answered');
        // La fausse IA garde TOUS les appels (dont celui de knownVisitor(), compose avec V1) : on cherche le tour qui porte V2 sans V1.
        GuestShellAgent::assertPrompted(function (AgentPrompt $prompt) {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'ACCROCHE-a-V2') && ! str_contains($instructions, 'ACCROCHE-a-V1');
        });
    }
}
