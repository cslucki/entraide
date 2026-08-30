<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Skill;
use App\Models\User;
use App\Services\People\DTO\EligiblePeopleResult;
use App\Services\People\DTO\RelevantPeopleResult;
use App\Services\People\EligiblePeopleService;
use App\Services\People\RelevantPeopleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1324 (People-2) — la pertinence est explicable, deterministe, et
 * STRICTEMENT contenue dans l'ensemble eligible de People-1 (TASK-1323).
 *
 * Acceptance criteria (spec fille WOW People) : 0 resultat propre ; au moins
 * un fait verifie par resultat ; aucun signal inaccessible ; provider qui
 * propose un non-eligible → rejet ; autre Organization impossible ; pas de
 * ranking opaque ; wording IA distingue du fait serveur.
 */
class TASK1324RelevantPeopleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $requester;

    private Loop $loop;

    private RelevantPeopleService $service;

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

        $this->service = new RelevantPeopleService(new EligiblePeopleService);
    }

    /**
     * Un membre ELIGIBLE de la Loop du banc (People-1 : appartenance active +
     * profil publie ici), dont le profil declare `$profileContent`.
     */
    private function makeEligibleMember(array $profileContent = [], array $userAttributes = []): User
    {
        $user = User::factory()->create(array_merge(
            ['organization_id' => $this->organization->id],
            $userAttributes,
        ));

        LoopMember::factory()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        MemberAiProfile::factory()->create(array_merge(
            [
                'organization_id' => $this->organization->id,
                'user_id' => $user->id,
                'status' => MemberAiProfile::STATUS_PUBLISHED,
                'published_at' => now(),
                // Les valeurs du factory sont aleatoires : un banc de
                // pertinence exige des champs signaux MAITRISES.
                'skills' => [],
                'help_types' => [],
                'problems_helped' => [],
            ],
            $profileContent,
        ));

        return $user;
    }

    /**
     * Un Service `$status` de `$user` portant une Skill `$skillName`, dans
     * `$organization` (defaut : l'Organization du banc).
     */
    private function makeServiceWithSkill(
        User $user,
        string $skillName,
        string $status = 'active',
        ?Organization $organization = null,
        ?Organization $skillOrganization = null,
    ): Service {
        $organization ??= $this->organization;

        $service = Service::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => $status,
        ]);

        $skill = Skill::factory()->create([
            'organization_id' => ($skillOrganization ?? $organization)->id,
            'name' => $skillName,
        ]);

        $service->skills()->attach($skill->id);

        return $service;
    }

    private function relevant(string $need): RelevantPeopleResult
    {
        return $this->service->relevantFor($this->organization, $this->loop, $this->requester, $need);
    }

    /**
     * @return list<string>
     */
    private function userIds(RelevantPeopleResult $result): array
    {
        return array_map(static fn ($person) => $person->person->userId, $result->people);
    }

    // -----------------------------------------------------------------
    // A. Zero resultat propre / resultats avec faits verifies
    // -----------------------------------------------------------------

    public function test_zero_relevant_people_is_a_clean_authorized_result(): void
    {
        $this->makeEligibleMember(['skills' => ['Photographie argentique']]);

        $result = $this->relevant("J'ai besoin d'aide sur les budgets europeens");

        $this->assertTrue($result->authorized);
        $this->assertNull($result->refusalReason);
        $this->assertSame([], $result->people);
    }

    public function test_a_need_without_usable_tokens_yields_zero_results(): void
    {
        $this->makeEligibleMember(['skills' => ['Budgets europeens']]);

        $result = $this->relevant('de la ou et');

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_a_matching_published_profile_skill_yields_a_verified_reason(): void
    {
        $candidate = $this->makeEligibleMember(['skills' => ['Budgets europeens']]);

        $result = $this->relevant("J'ai besoin d'aide sur les budgets europeens");

        $this->assertTrue($result->authorized);
        $this->assertSame([(string) $candidate->id], $this->userIds($result));

        $person = $result->people[0];

        // La personne People-1 traverse telle quelle, faits d'eligibilite compris.
        $this->assertSame(
            ['active_loop_membership', 'member_ai_profile_published'],
            array_column($person->person->verifiedFacts, 'type'),
        );

        $this->assertCount(1, $person->reasons);
        $reason = $person->reasons[0];
        $profile = MemberAiProfile::query()->where('user_id', $candidate->id)->firstOrFail();

        $this->assertSame('profile_skill', $reason['type']);
        $this->assertSame('Budgets europeens', $reason['label']);
        $this->assertTrue($reason['verified']);
        $this->assertSame((string) $profile->id, $reason['source']['member_ai_profile_id']);
        $this->assertEqualsCanonicalizing(['budget', 'europeen'], $reason['matched_terms']);
        $this->assertNull($person->aiWording);
    }

    public function test_help_types_and_problems_helped_are_matchable_signals(): void
    {
        $candidate = $this->makeEligibleMember([
            'help_types' => ['relire_document'],
            'problems_helped' => ['preparer_entretien'],
        ]);

        $result = $this->relevant('Quelqu\'un pour relire un document et preparer un entretien');

        $this->assertSame([(string) $candidate->id], $this->userIds($result));
        $this->assertEqualsCanonicalizing(
            ['profile_help_type', 'profile_problem_helped'],
            array_column($result->people[0]->reasons, 'type'),
        );
    }

    public function test_a_skill_on_an_active_service_is_a_matchable_signal(): void
    {
        $candidate = $this->makeEligibleMember();
        $service = $this->makeServiceWithSkill($candidate, 'Erasmus+');

        $result = $this->relevant('Monter un dossier Erasmus pour notre association');

        $this->assertSame([(string) $candidate->id], $this->userIds($result));

        $reason = $result->people[0]->reasons[0];
        $this->assertSame('service_skill', $reason['type']);
        $this->assertSame('Erasmus+', $reason['label']);
        $this->assertTrue($reason['verified']);
        $this->assertSame((string) $service->id, $reason['source']['service_id']);
        $this->assertSame($service->title, $reason['source']['service_title']);
        $this->assertSame(['erasmu'], $reason['matched_terms']);
    }

    public function test_every_result_carries_at_least_one_verified_reason(): void
    {
        $this->makeEligibleMember(['skills' => ['Budgets europeens']]);
        $this->makeEligibleMember(['skills' => ['Comptabilite associative']]);
        $this->makeEligibleMember(['skills' => ['Jardinage']]);

        $result = $this->relevant('Aide sur les budgets europeens et la comptabilite');

        $this->assertCount(2, $result->people);

        foreach ($result->people as $person) {
            $this->assertNotEmpty($person->reasons);

            foreach ($person->reasons as $reason) {
                $this->assertTrue($reason['verified']);
                $this->assertNotEmpty($reason['matched_terms']);
                $this->assertNotEmpty($reason['source']);
            }
        }
    }

    public function test_results_are_capped_and_ordered_by_matched_facts_then_name(): void
    {
        // Bea : 2 faits apparies — Ana et Zoe : 1 chacun — Jardinier : 0.
        $bea = $this->makeEligibleMember(
            ['skills' => ['Budgets europeens'], 'help_types' => ['relire_document']],
            ['first_name' => 'Bea', 'name' => 'Zed'],
        );
        $zoe = $this->makeEligibleMember(
            ['skills' => ['Budgets europeens']],
            ['first_name' => 'Zoe', 'name' => 'Alba'],
        );
        $ana = $this->makeEligibleMember(
            ['skills' => ['Budgets europeens']],
            ['first_name' => 'Ana', 'name' => 'Alba'],
        );
        $this->makeEligibleMember(['skills' => ['Jardinage']]);
        $extra = $this->makeEligibleMember(
            ['skills' => ['Budgets europeens']],
            ['first_name' => 'Yva', 'name' => 'Omega'],
        );

        $result = $this->relevant('Relire un document sur les budgets europeens');

        // Plafond MAX_RESULTS = 3, Bea d'abord (2 faits), puis Ana et Yva par
        // ordre alphabetique — Zoe (4e a un fait) sort, sans aucun score.
        $this->assertSame(RelevantPeopleService::MAX_RESULTS, count($result->people));
        $this->assertSame(
            [(string) $bea->id, (string) $ana->id, (string) $extra->id],
            $this->userIds($result),
        );
        $this->assertNotContains((string) $zoe->id, $this->userIds($result));
    }

    // -----------------------------------------------------------------
    // B. Aucun signal inaccessible — chaque source interdite, isolement
    // -----------------------------------------------------------------

    public function test_a_non_eligible_member_is_never_consulted_even_with_matching_content(): void
    {
        // Profil en brouillon → PAS eligible (People-1). Son contenu, meme
        // parfaitement appariant, n'existe pas pour la pertinence.
        $user = User::factory()->create(['organization_id' => $this->organization->id]);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        MemberAiProfile::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'status' => MemberAiProfile::STATUS_DRAFT,
            'skills' => ['Budgets europeens'],
        ]);

        $result = $this->relevant('Aide sur les budgets europeens');

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_a_profile_published_in_another_organization_is_never_a_signal(): void
    {
        $user = User::factory()->create(['organization_id' => $this->organization->id]);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        MemberAiProfile::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'user_id' => $user->id,
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'published_at' => now(),
            'skills' => ['Budgets europeens'],
        ]);

        $result = $this->relevant('Aide sur les budgets europeens');

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_an_inactive_or_trashed_service_is_never_a_signal(): void
    {
        $paused = $this->makeEligibleMember();
        $this->makeServiceWithSkill($paused, 'Erasmus+', status: 'paused');

        $trashed = $this->makeEligibleMember();
        $this->makeServiceWithSkill($trashed, 'Erasmus+')->delete();

        $result = $this->relevant('Monter un dossier Erasmus');

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_a_service_from_another_organization_is_never_a_signal(): void
    {
        // Le candidat est bien eligible ICI, mais son Service (et sa Skill)
        // vivent dans une autre Organization : les citer serait une fuite.
        $candidate = $this->makeEligibleMember();
        $this->makeServiceWithSkill($candidate, 'Erasmus+', organization: $this->otherOrganization);

        $result = $this->relevant('Monter un dossier Erasmus');

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_a_skill_from_another_organization_referential_is_never_a_signal(): void
    {
        // Service actif d'ici, mais Skill rattachee au referentiel d'une
        // autre Organization : defense en profondeur sur le pivot.
        $candidate = $this->makeEligibleMember();
        $this->makeServiceWithSkill($candidate, 'Erasmus+', skillOrganization: $this->otherOrganization);

        $result = $this->relevant('Monter un dossier Erasmus');

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    public function test_boundaries_and_free_text_profile_fields_are_never_signals(): void
    {
        // `boundaries` dit ce que la personne NE veut PAS faire ; les resumes
        // et exemples sont du texte libre. Aucun ne peut fonder une raison.
        $this->makeEligibleMember([
            'boundaries' => ['pas_de_fiscalite'],
            'member_profile_summary' => 'Expert fiscalite et subventions',
            'good_request_examples' => ['Une question de fiscalite'],
        ]);

        $result = $this->relevant('Une question de fiscalite');

        $this->assertTrue($result->authorized);
        $this->assertSame([], $result->people);
    }

    // -----------------------------------------------------------------
    // C. Refus People-1 propages — jamais reinterpretes
    // -----------------------------------------------------------------

    public function test_a_loop_from_another_organization_is_refused(): void
    {
        $foreignLoop = Loop::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $result = $this->service->relevantFor($this->organization, $foreignLoop, $this->requester, 'budgets europeens');

        $this->assertFalse($result->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_CROSS_ORGANIZATION, $result->refusalReason);
        $this->assertSame([], $result->people);
    }

    public function test_a_requester_without_workspace_access_is_refused(): void
    {
        $outsider = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->makeEligibleMember(['skills' => ['Budgets europeens']]);

        $result = $this->service->relevantFor($this->organization, $this->loop, $outsider, 'budgets europeens');

        $this->assertFalse($result->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_REQUESTER_NOT_AUTHORIZED, $result->refusalReason);
    }

    public function test_ai_profiles_disabled_is_refused_not_empty(): void
    {
        $this->makeEligibleMember(['skills' => ['Budgets europeens']]);
        $this->organization->forceFill(['ai_profiles_enabled' => false])->saveQuietly();
        $this->organization->refresh();

        $result = $this->relevant('budgets europeens');

        $this->assertFalse($result->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_AI_PROFILES_DISABLED, $result->refusalReason);
        $this->assertSame([], $result->people);
    }

    // -----------------------------------------------------------------
    // D. Couture provider — selectionner, jamais creer ni classer
    // -----------------------------------------------------------------

    public function test_a_provider_proposing_a_non_eligible_person_is_rejected(): void
    {
        $kept = $this->makeEligibleMember(['skills' => ['Budgets europeens']]);
        $stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $eligibleButNotRelevant = $this->makeEligibleMember(['skills' => ['Jardinage']]);

        $server = $this->relevant('budgets europeens');

        $outcome = $this->service->validatedProviderSelection($server, [
            ['user_id' => (string) $stranger->id, 'wording' => 'La meilleure personne pour vous'],
            ['user_id' => (string) $eligibleButNotRelevant->id],
            ['user_id' => (string) $kept->id],
            ['user_id' => 'not-a-real-id'],
        ]);

        // Seule la personne retenue PAR LE SERVEUR survit ; l'etrangere,
        // l'eligible non retenu et l'identifiant invente sont rejetes et traces.
        $this->assertSame([(string) $kept->id], $this->userIds($outcome));
        $this->assertEqualsCanonicalizing(
            [(string) $stranger->id, (string) $eligibleButNotRelevant->id, 'not-a-real-id'],
            $outcome->rejectedProviderUserIds,
        );
    }

    public function test_a_provider_proposing_only_non_eligible_people_yields_zero_kept(): void
    {
        $this->makeEligibleMember(['skills' => ['Budgets europeens']]);
        $server = $this->relevant('budgets europeens');

        $outcome = $this->service->validatedProviderSelection($server, [
            ['user_id' => 'invented-1'],
            ['user_id' => 'invented-2'],
        ]);

        $this->assertTrue($outcome->authorized);
        $this->assertSame([], $outcome->people);
        $this->assertSame(['invented-1', 'invented-2'], $outcome->rejectedProviderUserIds);
    }

    public function test_provider_wording_is_unverified_and_cannot_write_facts(): void
    {
        $kept = $this->makeEligibleMember(['skills' => ['Budgets europeens']]);
        $server = $this->relevant('budgets europeens');
        $serverReasons = $server->people[0]->reasons;

        $outcome = $this->service->validatedProviderSelection($server, [
            [
                'user_id' => (string) $kept->id,
                'wording' => 'Publie un profil oriente budgets europeens',
                // Tentatives d'ecriture de faits par le provider : ignorees.
                'reasons' => [['type' => 'invented', 'label' => 'Expert mondial', 'verified' => true]],
                'verified' => true,
                'score' => '97%',
            ],
        ]);

        $person = $outcome->people[0];

        // Le wording est la, structurellement marque non verifie.
        $this->assertSame(
            ['text' => 'Publie un profil oriente budgets europeens', 'verified' => false],
            $person->aiWording,
        );
        // Les raisons restent EXACTEMENT les faits serveur.
        $this->assertSame($serverReasons, $person->reasons);
        $this->assertStringNotContainsString('Expert mondial', json_encode($outcome->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('97%', json_encode($outcome->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_provider_selection_preserves_server_order_not_proposal_order(): void
    {
        $bea = $this->makeEligibleMember(
            ['skills' => ['Budgets europeens'], 'help_types' => ['relire_document']],
            ['first_name' => 'Bea', 'name' => 'Zed'],
        );
        $ana = $this->makeEligibleMember(
            ['skills' => ['Budgets europeens']],
            ['first_name' => 'Ana', 'name' => 'Alba'],
        );

        $server = $this->relevant('Relire un document sur les budgets europeens');
        $this->assertSame([(string) $bea->id, (string) $ana->id], $this->userIds($server));

        $outcome = $this->service->validatedProviderSelection($server, [
            ['user_id' => (string) $ana->id],
            ['user_id' => (string) $bea->id],
        ]);

        // Selectionner n'est pas classer : l'ordre reste celui du serveur.
        $this->assertSame([(string) $bea->id, (string) $ana->id], $this->userIds($outcome));
    }

    public function test_provider_selection_on_a_refused_result_stays_refused(): void
    {
        $foreignLoop = Loop::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $refused = $this->service->relevantFor($this->organization, $foreignLoop, $this->requester, 'budgets');

        $outcome = $this->service->validatedProviderSelection($refused, [
            ['user_id' => (string) $this->requester->id, 'wording' => 'Peu importe'],
        ]);

        $this->assertFalse($outcome->authorized);
        $this->assertSame(EligiblePeopleResult::REFUSAL_CROSS_ORGANIZATION, $outcome->refusalReason);
        $this->assertSame([], $outcome->people);
    }

    // -----------------------------------------------------------------
    // E. Contrat d'exposition — pas de score opaque, pas de champ prive
    // -----------------------------------------------------------------

    public function test_the_contract_exposes_no_numeric_score_and_no_private_field(): void
    {
        // Noms fixes : les chaines-sondes ci-dessous ne doivent jamais
        // dependre de ce que le faker invente.
        $candidate = $this->makeEligibleMember(
            [
                'skills' => ['Budgets europeens'],
                'member_profile_summary' => 'Resume prive du profil',
                'boundaries' => ['pas_urgence_boundary'],
            ],
            ['first_name' => 'Luc', 'name' => 'Martin', 'email' => 'luc.martin@example.test'],
        );

        $payload = $this->relevant('budgets europeens')->toArray();

        $this->assertSame(
            ['authorized', 'refusal_reason', 'people', 'rejected_provider_user_ids'],
            array_keys($payload),
        );
        $this->assertSame(
            ['user_id', 'display_name', 'avatar_url', 'member_ai_profile_id', 'verified_facts', 'reasons', 'ai_wording'],
            array_keys($payload['people'][0]),
        );
        $this->assertSame(
            ['type', 'label', 'source', 'matched_terms', 'verified'],
            array_keys($payload['people'][0]['reasons'][0]),
        );

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach (['score', 'rank', 'percent', 'confidence', '%'] as $opaque) {
            $this->assertStringNotContainsString($opaque, $json);
        }
        $this->assertStringNotContainsString($candidate->email, $json);
        $this->assertStringNotContainsString('Resume prive du profil', $json);
        $this->assertStringNotContainsString('pas_urgence_boundary', $json);
    }

    // -----------------------------------------------------------------
    // F. Pas de N+1 — nombre de requetes constant
    // -----------------------------------------------------------------

    public function test_the_query_count_does_not_grow_with_the_number_of_candidates(): void
    {
        $first = $this->makeEligibleMember(['skills' => ['Budgets europeens']]);
        $this->makeServiceWithSkill($first, 'Erasmus+');
        $countForOne = $this->countQueries(fn () => $this->relevant('budgets europeens erasmus'));

        for ($i = 0; $i < 9; $i++) {
            $member = $this->makeEligibleMember(['skills' => ['Budgets europeens']]);
            $this->makeServiceWithSkill($member, 'Erasmus+');
        }
        $countForTen = $this->countQueries(fn () => $this->relevant('budgets europeens erasmus'));

        $this->assertSame($countForOne, $countForTen);
        $this->assertLessThanOrEqual(10, $countForTen);
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
