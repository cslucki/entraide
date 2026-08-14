<?php

namespace Tests\Unit\Ai;

use App\Ai\CapabilityRegistry;
use App\Ai\Constitution;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TASK1206AiFoundationTest extends TestCase
{
    private const ORGANIZATION_A = '11111111-1111-4111-8111-111111111111';

    private const ORGANIZATION_B = '22222222-2222-4222-8222-222222222222';

    private const USER = '33333333-3333-4333-8333-333333333333';

    private const LOOP = '44444444-4444-4444-8444-444444444444';

    public function test_context_requires_an_organization(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->context(organizationId: '');
    }

    /**
     * TASK-1207 : `Organization`, `User` et `Loop` utilisent tous `HasUuids`.
     * Un identifiant qui n'est pas un UUID ne vient d'aucun de ces modeles.
     */
    public function test_context_refuses_a_non_uuid_organization(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->context(organizationId: '17');
    }

    public function test_context_keeps_organization_user_and_loop_as_distinct_ids(): void
    {
        $context = $this->context(
            organizationId: self::ORGANIZATION_A,
            userId: self::USER,
            loopId: self::LOOP,
        );

        $this->assertSame(self::ORGANIZATION_A, $context->organizationId);
        $this->assertSame(self::USER, $context->userId);
        $this->assertSame(self::LOOP, $context->loopId);
        $this->assertNotSame($context->organizationId, $context->loopId);
    }

    public function test_two_organizations_remain_distinct_contexts(): void
    {
        $organizationA = $this->context(organizationId: self::ORGANIZATION_A, loopId: self::LOOP);
        $organizationB = $this->context(organizationId: self::ORGANIZATION_B, loopId: self::LOOP);

        $this->assertNotSame($organizationA->organizationId, $organizationB->organizationId);
        $this->assertSame($organizationA->loopId, $organizationB->loopId);
    }

    public function test_context_has_no_community_dependency(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(ContexteIa::class))->getProperties(),
        );

        $this->assertNotContains('communityId', $properties);
        $this->assertNotContains('community_id', $properties);
    }

    public function test_constitution_v1_is_stable_and_human_centered(): void
    {
        $constitution = new Constitution;
        $text = $constitution->text();

        $this->assertSame('v1', Constitution::VERSION);
        $this->assertStringStartsWith('Constitution BouclePro IA — v1', $text);
        $this->assertStringContainsString("L'humain décide avant toute publication ou action durable.", $text);
        $this->assertStringContainsString('Ne jamais présenter une inférence comme un fait certain.', $text);
        $this->assertStringContainsString("périmètre de l'Organization courante", $text);
    }

    public function test_registry_declares_only_the_read_only_loop_summary_capability(): void
    {
        $definition = (new CapabilityRegistry)->get(CapabilityRegistry::LOOP_SUMMARY);

        $this->assertSame('loop_summary', $definition->id);
        $this->assertSame('chatloop.summarize', $definition->process);
        $this->assertFalse($definition->requiresHumanConfirmation);
        $this->assertFalse($definition->canWrite);
        $this->assertSame(['organization', 'loop'], $definition->allowedScopes);
        $this->assertSame(['loop.messages'], $definition->allowedSources);
        $this->assertSame(8000, $definition->maxOutput);
        $this->assertSame('chatloop_ai_summarize', $definition->promptKey);
    }

    public function test_unknown_capability_is_denied(): void
    {
        $registry = new CapabilityRegistry;

        $this->assertFalse($registry->has('unknown_capability'));
        $this->expectException(DomainException::class);
        $registry->get('unknown_capability');
    }

    public function test_unknown_scope_is_denied(): void
    {
        $this->expectException(DomainException::class);

        (new CapabilityRegistry)->assertScopeAllowed('loop_summary', 'community');
    }

    public function test_prompt_composition_is_deterministic_and_orders_constitution_first(): void
    {
        $repository = new PromptRepository(new Constitution, new CapabilityRegistry);
        $first = $repository->compose('loop_summary', 'Resume fidelement les messages autorises.');
        $second = $repository->compose('loop_summary', 'Resume fidelement les messages autorises.');

        $this->assertSame($first, $second);
        $this->assertLessThan(
            strpos($first, 'Instructions capability'),
            strpos($first, 'Constitution BouclePro IA — v1'),
        );
        $this->assertStringContainsString('chatloop_ai_summarize', $first);
    }

    public function test_prompt_repository_rejects_missing_instructions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PromptRepository(new Constitution, new CapabilityRegistry))->compose('loop_summary', '  ');
    }

    private function context(
        string $organizationId = self::ORGANIZATION_A,
        ?string $userId = self::USER,
        ?string $loopId = self::LOOP,
    ): ContexteIa {
        return new ContexteIa(
            organizationId: $organizationId,
            userId: $userId,
            loopId: $loopId,
            locale: 'fr',
            capability: 'loop_summary',
            correlationId: '550e8400-e29b-41d4-a716-446655440000',
            source: 'loop_summary_card',
        );
    }
}
