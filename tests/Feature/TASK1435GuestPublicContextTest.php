<?php

namespace Tests\Feature;

use App\Ai\CapabilityRegistry;
use App\Models\Organization;
use App\Models\OrganizationAiConstitution;
use App\Models\PlatformAiConstitution;
use App\Models\User;
use App\Services\GuestShell\GuestPublicContextBuilder;
use App\Support\Ai\AiProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1435 — SW-5 : la capability publique du Shell Welcome et son contexte
 * (Addendum V2 §4, cadre Cyril §8 : whitelist explicite, Public != Global).
 *
 * Ce qui est mesure :
 * 1. la capability `guest_shell_welcome` existe, scope Organization SEULEMENT,
 *    sources = la whitelist, processus `guest_shell`, sans ecriture ;
 * 2. deux Organizations publiques ne recoivent pas le meme contexte ;
 * 3. la Constitution de l'Organization n'entre que si elle est PUBLIEE
 *    (opt-in + version active) ; la Constitution plateforme entre toujours ;
 * 4. une Organization inactive ou non publique n'a AUCUN contexte ;
 * 5. construire le contexte ne touche JAMAIS une table privee (mesure par
 *    les requetes SQL) ; une source hors whitelist est une faute de code ;
 * 6. le budget de la capability borne le texte, identite d'abord.
 */
class TASK1435GuestPublicContextTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_TABLES = [
        'loops', 'loop_messages', 'messages', 'dossiers', 'dossier_chunks', 'dossier_documents',
        'member_ai_profiles', 'member_ai_profile_interactions', 'ai_shell_messages', 'ai_shell_memories',
        'crm_contacts', 'crm_contact_events', 'organization_ai_doctrines', 'organization_ai_settings',
        'guest_visitors', 'guest_conversations', 'guest_messages', 'users',
    ];

    private Organization $main;

    private Organization $launchpals;

    private GuestPublicContextBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = Organization::factory()->create(['slug' => 'main-1435', 'name' => 'CyberWorkers', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'platform_tagline' => 'Le travail en boucles', 'hero_title' => 'Entraide entre freelances', 'hero_description' => 'Des boucles pour avancer ensemble.', 'description' => 'Communaute de freelances tech.']);
        $this->launchpals = Organization::factory()->create(['slug' => 'launchpals-1435', 'name' => 'LaunchPals', 'is_active' => true, 'is_public' => true, 'locale' => 'en', 'platform_tagline' => 'Ship together', 'hero_title' => 'Founders helping founders', 'hero_description' => 'Weekly loops to launch faster.', 'description' => null]);
        $this->builder = app(GuestPublicContextBuilder::class);
    }

    private function builderWithBudget(int $chars): GuestPublicContextBuilder
    {
        config(['ai.guest_shell.max_context_chars' => $chars]);
        $this->app->forgetInstance(CapabilityRegistry::class);

        return $this->app->make(GuestPublicContextBuilder::class);
    }

    // ── 1. La capability ────────────────────────────────────────────────────

    public function test_the_capability_is_organization_scoped_read_only_and_whitelisted(): void
    {
        $registry = app(CapabilityRegistry::class);
        $this->assertTrue($registry->has(CapabilityRegistry::GUEST_SHELL_WELCOME));
        $definition = $registry->get(CapabilityRegistry::GUEST_SHELL_WELCOME);

        $this->assertSame('guest_shell', $definition->process);
        $this->assertSame(AiProcess::GUEST_SHELL, $definition->process);
        $this->assertSame('guest_shell', AiProcess::fromScenarioId('guest_shell_welcome'));
        $this->assertFalse($definition->canWrite);
        $this->assertFalse($definition->requiresHumanConfirmation);
        $this->assertSame([CapabilityRegistry::SCOPE_ORGANIZATION], $definition->allowedScopes);
        $this->assertEqualsCanonicalizing([
            CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY,
            CapabilityRegistry::SOURCE_PLATFORM_CONSTITUTION,
            CapabilityRegistry::SOURCE_ORGANIZATION_CONSTITUTION_PUBLIC,
        ], $definition->allowedSources);
        $this->assertSame('guest_shell_welcome', $definition->promptKey);
        $this->assertGreaterThan(0, $definition->maxOutput);

        foreach ([CapabilityRegistry::SOURCE_LOOP_MESSAGES, CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL, CapabilityRegistry::SOURCE_MEMBER_PROFILE, CapabilityRegistry::SOURCE_USER_LOOPS] as $forbidden) {
            $this->assertFalse($definition->allowsSource($forbidden), $forbidden);
        }
        $this->expectException(LogicException::class);
        $registry->assertScopeAllowed(CapabilityRegistry::GUEST_SHELL_WELCOME, CapabilityRegistry::SCOPE_LOOP);
    }

    // ── 2. Public != Global ─────────────────────────────────────────────────

    public function test_two_public_organizations_do_not_get_the_same_context(): void
    {
        $main = $this->builder->build($this->main);
        $launchpals = $this->builder->build($this->launchpals);

        $this->assertNotNull($main);
        $this->assertNotNull($launchpals);
        $this->assertSame('fr', $main->locale);
        $this->assertSame('en', $launchpals->locale);
        $this->assertNotSame($main->text(), $launchpals->text());

        $this->assertStringContainsString('CyberWorkers', $main->text());
        $this->assertStringContainsString('Entraide entre freelances', $main->text());
        $this->assertStringContainsString('Communaute de freelances tech.', $main->text());
        $this->assertStringNotContainsString('LaunchPals', $main->text());
        $this->assertStringNotContainsString('Founders helping founders', $main->text());

        $this->assertStringContainsString('LaunchPals', $launchpals->text());
        $this->assertStringContainsString('Ship together', $launchpals->text());
        $this->assertStringNotContainsString('CyberWorkers', $launchpals->text());

        // La Constitution plateforme (Mycelium public) est commune — c'est le socle, pas l'identite.
        $this->assertTrue($main->hasSource(CapabilityRegistry::SOURCE_PLATFORM_CONSTITUTION));
        $this->assertTrue($launchpals->hasSource(CapabilityRegistry::SOURCE_PLATFORM_CONSTITUTION));
        $this->assertStringContainsString(PlatformAiConstitution::activeTextOrSeed(), $main->text());
    }

    // ── 3. La Constitution de l'Organization : publiee ou rien ──────────────

    public function test_the_organization_constitution_enters_only_when_published_with_an_active_version(): void
    {
        $author = User::factory()->create(['organization_id' => $this->main->id]);
        $this->main->update(['admin_id' => $author->id]);
        OrganizationAiConstitution::activate($this->main, 'Nous privilegions toujours la reponse honnete a la reponse rapide.', $author);

        // Version active mais opt-in absent : rien.
        $context = $this->builder->build($this->main->fresh());
        $this->assertFalse($context->hasSource(CapabilityRegistry::SOURCE_ORGANIZATION_CONSTITUTION_PUBLIC));
        $this->assertStringNotContainsString('reponse honnete', $context->text());

        // Opt-in coche : la Constitution entre.
        $this->main->update(['ai_constitution_public' => true]);
        $context = $this->builder->build($this->main->fresh());
        $this->assertTrue($context->hasSource(CapabilityRegistry::SOURCE_ORGANIZATION_CONSTITUTION_PUBLIC));
        $this->assertStringContainsString('reponse honnete', $context->text());

        // Opt-in coche mais retiree : plus rien a publier.
        OrganizationAiConstitution::withdraw($this->main);
        $context = $this->builder->build($this->main->fresh());
        $this->assertFalse($context->hasSource(CapabilityRegistry::SOURCE_ORGANIZATION_CONSTITUTION_PUBLIC));
        $this->assertStringNotContainsString('reponse honnete', $context->text());

        // Jamais celle d'une autre Organization.
        $this->assertStringNotContainsString('reponse honnete', $this->builder->build($this->launchpals)->text());
    }

    // ── 4. Inactive ou non publique : aucun contexte ───────────────────────

    public function test_an_inactive_or_non_public_organization_has_no_context_at_all(): void
    {
        $this->main->update(['is_public' => false]);
        $this->assertNull($this->builder->build($this->main->fresh()));

        $this->main->update(['is_public' => true, 'is_active' => false]);
        $this->assertNull($this->builder->build($this->main->fresh()));

        $this->main->update(['is_active' => true]);
        $this->assertNotNull($this->builder->build($this->main->fresh()));
    }

    // ── 5. Aucune table privee n'est touchee ───────────────────────────────

    public function test_building_the_context_never_queries_a_private_table(): void
    {
        $this->main->update(['ai_constitution_public' => true]);
        $author = User::factory()->create(['organization_id' => $this->main->id]);
        OrganizationAiConstitution::activate($this->main, 'Texte publie.', $author);
        $organization = $this->main->fresh();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $context = $this->builder->build($organization);
        $this->assertNotNull($context);
        $this->assertNotEmpty($queries, 'la construction lit au moins les Constitutions');

        foreach ($queries as $sql) {
            foreach (self::FORBIDDEN_TABLES as $table) {
                $this->assertDoesNotMatchRegularExpression('/[`"]'.preg_quote($table, '/').'[`"]/', $sql, "table privee touchee : {$table} — {$sql}");
            }
        }
    }

    // ── 6. Le budget borne, identite d'abord ───────────────────────────────

    public function test_the_capability_budget_is_deterministic_whole_blocks_identity_first(): void
    {
        // MASTER Q58 : blocs entiers dans l'ordre, arret au premier qui ne rentre plus — jamais de coupe.
        $full = $this->builder->build($this->main);
        $identityLength = mb_strlen($full->blocks[0]['text']);
        $this->assertSame(CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY, $full->blocks[0]['source']);

        // Le registre lit le budget a sa construction : un budget change = un registre neuf.
        $context = $this->builderWithBudget($identityLength + 10)->build($this->main);
        $this->assertSame($identityLength + 10, $context->charBudget);
        $this->assertCount(1, $context->blocks, 'la Constitution plateforme ne rentre pas : omise ENTIERE');
        $this->assertSame($full->blocks[0]['text'], $context->blocks[0]['text'], 'l\'identite n\'est jamais coupee');
        $this->assertSame([CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY], $context->sources, 'les sources refletent les blocs gardes');
        $this->assertFalse($context->hasSource(CapabilityRegistry::SOURCE_PLATFORM_CONSTITUTION));

        // Budget plus petit que l'identite elle-meme : aucun bloc — et aucun repli.
        $tiny = $this->builderWithBudget(5)->build($this->main);
        $this->assertTrue($tiny->isEmpty());
        $this->assertSame('', $tiny->text());
    }
}
