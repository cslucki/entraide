<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\People\DTO\EligiblePeopleResult;
use App\Services\People\EligiblePeopleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1323 (People-1) — l'ensemble eligible de personnes est calcule cote
 * serveur, deterministe, fonde sur les permissions existantes. Le modele ne
 * cree JAMAIS cette liste — aucun LLM n'est present dans la primitive.
 *
 * Criteres V1 (spec fille WOW People) : meme Organization, membre actif de
 * la Loop cible, `MemberAiProfile::STATUS_PUBLISHED`, demandeur exclu,
 * donnees exposees limitees a ce qui est deja visible/autorise.
 */
class TASK1323EligiblePeopleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $requester;

    private Loop $loop;

    private EligiblePeopleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['ai_profiles_enabled' => true]);
        $this->otherOrganization = Organization::factory()->create(['ai_profiles_enabled' => true]);

        $this->requester = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->requester->id,
        ]);

        LoopMember::factory()->owner()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->requester->id,
        ]);

        $this->service = new EligiblePeopleService;
    }

    /**
     * Un membre de la Loop cible, dans l'etat demande : appartenance
     * `$memberStatus`, profil IA `$profileStatus` (null = aucun profil)
     * rattache a `$profileOrganization` (defaut : l'Organization du banc).
     */
    private function makeMember(
        string $memberStatus = 'active',
        ?string $profileStatus = MemberAiProfile::STATUS_PUBLISHED,
        ?Organization $profileOrganization = null,
        array $userAttributes = [],
        ?Loop $loop = null,
    ): User {
        $user = User::factory()->create(array_merge(
            ['organization_id' => $this->organization->id],
            $userAttributes,
        ));

        LoopMember::factory()->create([
            'loop_id' => ($loop ?? $this->loop)->id,
            'user_id' => $user->id,
            'status' => $memberStatus,
            'joined_at' => $memberStatus === 'active' ? now() : null,
        ]);

        if ($profileStatus !== null) {
            MemberAiProfile::factory()->create([
                'organization_id' => ($profileOrganization ?? $this->organization)->id,
                'user_id' => $user->id,
                'status' => $profileStatus,
                'published_at' => $profileStatus === MemberAiProfile::STATUS_PUBLISHED ? now() : null,
            ]);
        }

        return $user;
    }

    private function eligible(): EligiblePeopleResult
    {
        return $this->service->eligibleFor($this->organization, $this->loop, $this->requester);
    }

    /**
     * @return list<string>
     */
    private function eligibleUserIds(EligiblePeopleResult $result): array
    {
        return array_map(static fn ($person) => $person->userId, $result->people);
    }

    // -----------------------------------------------------------------
    // A. Candidat valide, 0/1/N
    // -----------------------------------------------------------------

    public function test_a_valid_candidate_is_present_with_verified_facts(): void
    {
        $candidate = $this->makeMember();

        $result = $this->eligible();

        $this->assertTrue($result->authorized);
        $this->assertNull($result->refusalReason);
        $this->assertCount(1, $result->people);

        $person = $result->people[0];
        $this->assertSame((string) $candidate->id, $person->userId);
        $this->assertSame($candidate->publicDisplayName(), $person->displayName);

        $profile = MemberAiProfile::query()->where('user_id', $candidate->id)->firstOrFail();
        $this->assertSame((string) $profile->id, $person->memberAiProfileId);

        $factTypes = array_column($person->verifiedFacts, 'type');
        $this->assertSame(['active_loop_membership', 'member_ai_profile_published'], $factTypes);
        $this->assertSame((string) $this->loop->id, $person->verifiedFacts[0]['loop_id']);
        $this->assertNotNull($person->verifiedFacts[0]['joined_at']);
        $this->assertSame((string) $profile->id, $person->verifiedFacts[1]['member_ai_profile_id']);
        $this->assertNotNull($person->verifiedFacts[1]['published_at']);
    }

    public function test_zero_eligible_people_is_a_valid_authorized_result(): void
    {
        $result = $this->eligible();

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_only_the_eligible_subset_is_returned_in_deterministic_order(): void
    {
        $eligibleB = $this->makeMember(userAttributes: ['first_name' => 'Bea', 'name' => 'Zed']);
        $eligibleA = $this->makeMember(userAttributes: ['first_name' => 'Ana', 'name' => 'Alba']);
        $this->makeMember(profileStatus: MemberAiProfile::STATUS_DRAFT);
        $this->makeMember(profileStatus: null);
        $this->makeMember(memberStatus: 'invited');
        $this->makeMember(userAttributes: ['banned_at' => now()]);
        $this->makeMember(profileOrganization: $this->otherOrganization);

        $result = $this->eligible();

        $this->assertTrue($result->authorized);
        $this->assertSame(
            [(string) $eligibleA->id, (string) $eligibleB->id],
            $this->eligibleUserIds($result),
        );
    }

    // -----------------------------------------------------------------
    // B. Refus de contexte — explicites, jamais un vide silencieux
    // -----------------------------------------------------------------

    public function test_a_loop_from_another_organization_is_refused(): void
    {
        $foreignLoop = Loop::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $result = $this->service->eligibleFor($this->organization, $foreignLoop, $this->requester);

        $this->assertFalse($result->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_CROSS_ORGANIZATION, $result->refusalReason);
        $this->assertSame([], $result->people);
    }

    public function test_a_requester_from_another_organization_is_refused(): void
    {
        $foreignRequester = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $this->makeMember();

        $result = $this->service->eligibleFor($this->organization, $this->loop, $foreignRequester);

        $this->assertFalse($result->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_REQUESTER_NOT_AUTHORIZED, $result->refusalReason);
        $this->assertSame([], $result->people);
    }

    public function test_a_requester_who_is_not_a_loop_member_is_refused(): void
    {
        $nonMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->makeMember();

        $result = $this->service->eligibleFor($this->organization, $this->loop, $nonMember);

        $this->assertFalse($result->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_REQUESTER_NOT_AUTHORIZED, $result->refusalReason);
    }

    public function test_a_requester_with_inactive_membership_is_refused(): void
    {
        $invited = User::factory()->create(['organization_id' => $this->organization->id]);
        LoopMember::factory()->invited()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $invited->id,
        ]);

        $result = $this->service->eligibleFor($this->organization, $this->loop, $invited);

        $this->assertFalse($result->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_REQUESTER_NOT_AUTHORIZED, $result->refusalReason);
    }

    public function test_a_banned_requester_is_refused(): void
    {
        $this->requester->forceFill(['banned_at' => now()])->saveQuietly();

        $result = $this->eligible();

        $this->assertFalse($result->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_REQUESTER_NOT_AUTHORIZED, $result->refusalReason);
    }

    public function test_an_archived_loop_is_refused(): void
    {
        $this->loop->forceFill(['status' => 'archived'])->saveQuietly();
        $this->makeMember();

        $result = $this->eligible();

        $this->assertFalse($result->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_LOOP_NOT_ACTIVE, $result->refusalReason);
    }

    public function test_ai_profiles_disabled_on_the_organization_is_refused(): void
    {
        $this->organization->forceFill(['ai_profiles_enabled' => false])->saveQuietly();
        $this->organization->refresh();
        $this->makeMember();

        $result = $this->eligible();

        $this->assertFalse($result->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_AI_PROFILES_DISABLED, $result->refusalReason);
        $this->assertSame([], $result->people);
    }

    // -----------------------------------------------------------------
    // C. Exclusions de candidats — chaque critere V1, isolement
    // -----------------------------------------------------------------

    public function test_every_unpublished_profile_status_is_excluded(): void
    {
        foreach ([
            MemberAiProfile::STATUS_DRAFT,
            MemberAiProfile::STATUS_READY_FOR_GENERATION,
            MemberAiProfile::STATUS_GENERATED,
            MemberAiProfile::STATUS_PENDING_VALIDATION,
            MemberAiProfile::STATUS_DISABLED,
        ] as $status) {
            $this->makeMember(profileStatus: $status);
        }

        $result = $this->eligible();

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_a_published_profile_belonging_to_another_organization_is_excluded(): void
    {
        // Defense en profondeur : meme si l'utilisateur est bien membre actif
        // de la Loop, un profil publie rattache a une AUTRE Organization ne
        // rend pas la personne visible ICI.
        $this->makeMember(profileOrganization: $this->otherOrganization);

        $result = $this->eligible();

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_an_inactive_loop_member_is_excluded_even_with_a_published_profile(): void
    {
        $this->makeMember(memberStatus: 'invited');
        $this->makeMember(memberStatus: 'left');

        $result = $this->eligible();

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_a_member_of_another_loop_is_not_eligible_for_this_loop(): void
    {
        $otherLoop = Loop::factory()->create(['organization_id' => $this->organization->id]);
        $this->makeMember(loop: $otherLoop);

        $result = $this->eligible();

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_a_banned_user_is_excluded_even_with_membership_and_published_profile(): void
    {
        $this->makeMember(userAttributes: ['banned_at' => now()]);

        $result = $this->eligible();

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_the_requester_is_excluded_even_with_a_published_profile(): void
    {
        MemberAiProfile::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->requester->id,
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $candidate = $this->makeMember();

        $result = $this->eligible();

        $this->assertTrue($result->authorized);
        $this->assertSame([(string) $candidate->id], $this->eligibleUserIds($result));
    }

    // -----------------------------------------------------------------
    // D. Contrat d'exposition — aucun champ prive
    // -----------------------------------------------------------------

    public function test_no_private_field_is_exposed(): void
    {
        $candidate = $this->makeMember();

        $payload = $this->eligible()->toArray();

        $this->assertSame(['authorized', 'refusal_reason', 'people'], array_keys($payload));
        $this->assertSame(
            ['user_id', 'display_name', 'avatar_url', 'member_ai_profile_id', 'verified_facts'],
            array_keys($payload['people'][0]),
        );

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($candidate->email, $json);
        $this->assertStringNotContainsString('phone', $json);

        // Le CONTENU du profil publie (materiau People-2) n'est pas expose :
        // seulement sa reference et son fait de publication.
        $profile = MemberAiProfile::query()->where('user_id', $candidate->id)->firstOrFail();
        foreach (['skills', 'help_types', 'problems_helped', 'boundaries', 'member_profile_summary'] as $contentField) {
            $this->assertArrayNotHasKey($contentField, $payload['people'][0]);
        }
        $this->assertStringNotContainsString((string) $profile->member_profile_summary, $json);
    }

    // -----------------------------------------------------------------
    // E. Pas de N+1 massif — nombre de requetes constant
    // -----------------------------------------------------------------

    public function test_the_query_count_does_not_grow_with_the_number_of_candidates(): void
    {
        $this->makeMember();
        $countForOne = $this->countQueries(fn () => $this->eligible());

        for ($i = 0; $i < 9; $i++) {
            $this->makeMember();
        }
        $countForTen = $this->countQueries(fn () => $this->eligible());

        $this->assertSame($countForOne, $countForTen);
        $this->assertLessThanOrEqual(6, $countForTen);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
