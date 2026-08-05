<?php

namespace Tests\Feature;

use App\Livewire\LoopPollsCard;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopPollOption;
use App\Models\LoopPollVote;
use App\Models\LoopPollVoteOption;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopLifecycleService;
use App\Services\Loops\LoopPollService;
use App\Services\Loops\LoopPresetSyncService;
use App\Services\Loops\PollException;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La Card Sondage : poser une question, voter, depouiller.
 *
 * Ce qui est defendu ici plus que le reste : une voix par personne quoi qu'il
 * arrive, un Sondage vote ne se modifie ni ne se supprime plus, et les regles
 * de visibilite des resultats tiennent — sinon le premier resultat affiche
 * oriente tous les votes suivants.
 */
class TASK1087PollCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $orgAdmin;

    private User $owner;

    private User $facilitator;

    private User $member;

    private User $other;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'is_active' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);

        $this->owner = $this->userInOrg();
        $this->facilitator = $this->userInOrg();
        $this->member = $this->userInOrg();
        $this->other = $this->userInOrg();

        // Type « general » : c'est la cle reelle du type Dialogue, celui dont le
        // socle porte desormais la Card Sondage.
        $this->loop = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Boucle Dialogue',
            'slug' => 'boucle-dialogue',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => $this->owner->id,
        ]);

        app(LoopTypeRegistry::class)->applyPreset($this->loop);

        $this->join($this->owner, 'owner');
        $this->join($this->facilitator, 'facilitator');
        $this->join($this->member, 'member');
        $this->join($this->other, 'member');

        app()->instance('current_organization', $this->org);
    }

    private function userInOrg(?Organization $org = null): User
    {
        return User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
    }

    private function join(User $user, string $role): void
    {
        LoopMember::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function service(): LoopPollService
    {
        return app(LoopPollService::class);
    }

    /** @param array<int, string> $labels */
    private function poll(?User $author = null, string $type = LoopPoll::TYPE_SINGLE, array $labels = ['Oui', 'Non']): LoopPoll
    {
        return $this->service()->create(
            $author ?? $this->member, $this->loop,
            'On y va ?', null, $type, $labels,
        );
    }

    // ── Modele et contraintes ───────────────────────────────────────────────

    public function test_a_poll_carries_its_options_in_order(): void
    {
        $poll = $this->poll(labels: ['Lundi', 'Mardi', 'Mercredi']);

        $this->assertSame(['Lundi', 'Mardi', 'Mercredi'], $poll->options->pluck('label')->all());
        $this->assertSame([0, 1, 2], $poll->options->pluck('position')->all());
        $this->assertSame($this->org->id, $poll->organization_id);
    }

    public function test_one_vote_object_per_person_is_enforced_by_the_database(): void
    {
        $poll = $this->poll();

        LoopPollVote::create([
            'organization_id' => $this->org->id, 'poll_id' => $poll->id, 'user_id' => $this->member->id,
        ]);

        // La contrainte, pas le service : c'est la base qui doit tenir la regle.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        LoopPollVote::create([
            'organization_id' => $this->org->id, 'poll_id' => $poll->id, 'user_id' => $this->member->id,
        ]);
    }

    public function test_the_same_option_cannot_be_chosen_twice_in_one_vote(): void
    {
        $poll = $this->poll(type: LoopPoll::TYPE_MULTIPLE);
        $vote = LoopPollVote::create([
            'organization_id' => $this->org->id, 'poll_id' => $poll->id, 'user_id' => $this->member->id,
        ]);
        $option = $poll->options->first();

        LoopPollVoteOption::create(['vote_id' => $vote->id, 'option_id' => $option->id]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        LoopPollVoteOption::create(['vote_id' => $vote->id, 'option_id' => $option->id]);
    }

    // ── Creation ────────────────────────────────────────────────────────────

    public function test_every_active_member_may_ask_a_question(): void
    {
        foreach ([$this->owner, $this->facilitator, $this->member] as $user) {
            $this->assertTrue($this->service()->canCreate($user, $this->loop));
        }
    }

    public function test_a_non_member_may_not_create(): void
    {
        $stranger = $this->userInOrg();

        $this->expectException(PollException::class);
        $this->service()->create($stranger, $this->loop, 'Question', null, LoopPoll::TYPE_SINGLE, ['A', 'B']);
    }

    public function test_someone_from_another_organization_is_refused(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->assertFalse($this->service()->canCreate($stranger, $this->loop));
        $this->assertFalse($this->service()->canView($stranger, $this->loop));
    }

    public function test_a_poll_needs_at_least_two_answers(): void
    {
        $this->expectException(PollException::class);
        $this->poll(labels: ['Seule reponse']);
    }

    public function test_an_empty_answer_is_dropped_not_kept(): void
    {
        // Deux reponses valides restent : le vide est retire, pas compte.
        $poll = $this->poll(labels: ['Oui', '   ', 'Non']);

        $this->assertSame(['Oui', 'Non'], $poll->options->pluck('label')->all());
    }

    public function test_two_identical_answers_are_refused(): void
    {
        $this->expectException(PollException::class);
        // La casse ne fait pas une reponse differente.
        $this->poll(labels: ['Oui', 'OUI']);
    }

    public function test_an_empty_question_is_refused(): void
    {
        $this->expectException(PollException::class);
        $this->service()->create($this->member, $this->loop, '   ', null, LoopPoll::TYPE_SINGLE, ['A', 'B']);
    }

    // ── Modification ────────────────────────────────────────────────────────

    public function test_a_poll_without_votes_can_still_be_changed(): void
    {
        $poll = $this->poll();

        $updated = $this->service()->update(
            $this->member, $poll, $this->loop,
            'On y va vraiment ?', 'Precision', LoopPoll::TYPE_MULTIPLE, ['Oui', 'Non', 'Plus tard'],
        );

        $this->assertSame('On y va vraiment ?', $updated->question);
        $this->assertTrue($updated->allowsMultiple());
        $this->assertCount(3, $updated->options);
    }

    public function test_a_poll_with_a_vote_can_no_longer_be_changed(): void
    {
        $poll = $this->poll();
        $this->service()->vote($this->other, $poll, $this->loop, [$poll->options->first()->id]);

        $this->expectException(PollException::class);
        $this->service()->update(
            $this->member, $poll, $this->loop,
            'Autre question', null, LoopPoll::TYPE_SINGLE, ['X', 'Y'],
        );
    }

    public function test_switching_to_multiple_after_a_vote_is_refused(): void
    {
        $poll = $this->poll();
        $this->service()->vote($this->other, $poll, $this->loop, [$poll->options->first()->id]);

        $this->expectException(PollException::class);
        $this->service()->update(
            $this->member, $poll, $this->loop,
            $poll->question, null, LoopPoll::TYPE_MULTIPLE, ['Oui', 'Non'],
        );
    }

    public function test_another_member_may_not_change_someone_elses_poll(): void
    {
        $poll = $this->poll($this->member);

        $this->assertFalse($this->service()->canManagePoll($this->other, $poll, $this->loop));
        // Le proprietaire et l'animateur, si.
        $this->assertTrue($this->service()->canManagePoll($this->owner, $poll, $this->loop));
        $this->assertTrue($this->service()->canManagePoll($this->facilitator, $poll, $this->loop));
        // Et son auteur, evidemment.
        $this->assertTrue($this->service()->canManagePoll($this->member, $poll, $this->loop));
    }

    // ── Vote ────────────────────────────────────────────────────────────────

    public function test_a_single_choice_poll_accepts_one_answer(): void
    {
        $poll = $this->poll();
        $vote = $this->service()->vote($this->member, $poll, $this->loop, [$poll->options->first()->id]);

        $this->assertCount(1, $vote->options);
    }

    public function test_a_single_choice_poll_refuses_two_answers(): void
    {
        $poll = $this->poll();

        $this->expectException(PollException::class);
        $this->service()->vote($this->member, $poll, $this->loop, $poll->options->pluck('id')->all());
    }

    public function test_a_multiple_choice_poll_accepts_several_answers(): void
    {
        $poll = $this->poll(type: LoopPoll::TYPE_MULTIPLE, labels: ['A', 'B', 'C']);
        $vote = $this->service()->vote($this->member, $poll, $this->loop, $poll->options->take(2)->pluck('id')->all());

        $this->assertCount(2, $vote->options);
    }

    public function test_changing_a_vote_replaces_it_and_never_adds_a_second(): void
    {
        $poll = $this->poll(type: LoopPoll::TYPE_MULTIPLE, labels: ['A', 'B', 'C']);
        $options = $poll->options;

        $this->service()->vote($this->member, $poll, $this->loop, [$options[0]->id, $options[1]->id]);
        $vote = $this->service()->vote($this->member, $poll, $this->loop, [$options[2]->id]);

        $this->assertSame(1, LoopPollVote::where('poll_id', $poll->id)->count());
        $this->assertSame(['C'], $vote->options->pluck('label')->all());
    }

    public function test_a_closed_poll_accepts_no_vote(): void
    {
        $poll = $this->poll();
        $this->service()->close($this->member, $poll, $this->loop);

        $this->expectException(PollException::class);
        $this->service()->vote($this->other, $poll->fresh(), $this->loop, [$poll->options->first()->id]);
    }

    public function test_a_closed_poll_accepts_no_change_of_vote(): void
    {
        $poll = $this->poll();
        $this->service()->vote($this->member, $poll, $this->loop, [$poll->options->first()->id]);
        $this->service()->close($this->member, $poll, $this->loop);

        $this->expectException(PollException::class);
        $this->service()->vote($this->member, $poll->fresh(), $this->loop, [$poll->options->last()->id]);
    }

    public function test_a_non_member_may_not_vote(): void
    {
        $poll = $this->poll();
        $stranger = $this->userInOrg();

        $this->expectException(PollException::class);
        $this->service()->vote($stranger, $poll, $this->loop, [$poll->options->first()->id]);
    }

    public function test_an_option_of_another_poll_is_refused(): void
    {
        $poll = $this->poll();
        $foreign = $this->poll(labels: ['X', 'Y']);

        $this->expectException(PollException::class);
        // Identifiant valide, mais pas celui de ce Sondage.
        $this->service()->vote($this->member, $poll, $this->loop, [$foreign->options->first()->id]);
    }

    public function test_a_forged_option_is_refused(): void
    {
        $poll = $this->poll();

        $this->expectException(PollException::class);
        $this->service()->vote($this->member, $poll, $this->loop, ['019f0000-0000-7000-8000-000000000000']);
    }

    public function test_two_simultaneous_votes_leave_a_single_voice(): void
    {
        $poll = $this->poll();
        $option = $poll->options->first();

        // Deux appels de suite, comme un double clic : le second remplace, il
        // n'ajoute pas.
        $this->service()->vote($this->member, $poll, $this->loop, [$option->id]);
        $this->service()->vote($this->member, $poll->fresh(), $this->loop, [$option->id]);

        $this->assertSame(1, LoopPollVote::where('poll_id', $poll->id)->count());
        $this->assertSame(1, LoopPollVoteOption::whereIn(
            'vote_id', LoopPollVote::where('poll_id', $poll->id)->pluck('id'),
        )->count());
    }

    // ── Depouillement ───────────────────────────────────────────────────────

    public function test_the_participant_count_is_people_not_choices(): void
    {
        $poll = $this->poll(type: LoopPoll::TYPE_MULTIPLE, labels: ['A', 'B', 'C']);
        $options = $poll->options;

        // Une personne, trois choix.
        $this->service()->vote($this->member, $poll, $this->loop, $options->pluck('id')->all());
        // Une autre, un seul.
        $this->service()->vote($this->other, $poll, $this->loop, [$options[0]->id]);

        $results = $this->service()->results($poll);

        // Deux personnes se sont prononcees, pas quatre.
        $this->assertSame(2, $results['participants']);
        $this->assertSame(2, $results['options'][0]['votes']);
        $this->assertSame(100, $results['options'][0]['percentage']);
        $this->assertSame(1, $results['options'][1]['votes']);
        $this->assertSame(50, $results['options'][1]['percentage']);
    }

    public function test_percentages_of_an_unanswered_poll_are_zero_not_a_division_by_zero(): void
    {
        $poll = $this->poll();
        $results = $this->service()->results($poll);

        $this->assertSame(0, $results['participants']);
        $this->assertSame(0, $results['options'][0]['percentage']);
    }

    public function test_the_named_detail_says_who_answered_what(): void
    {
        $poll = $this->poll(type: LoopPoll::TYPE_MULTIPLE, labels: ['A', 'B']);
        $this->service()->vote($this->member, $poll, $this->loop, $poll->options->pluck('id')->all());

        $detail = $this->service()->voterDetail($poll);

        $this->assertCount(1, $detail);
        $this->assertSame($this->member->publicDisplayName(), $detail[0]['name']);
        $this->assertSame(['A', 'B'], $detail[0]['options']);
    }

    // ── Visibilite des resultats ────────────────────────────────────────────

    public function test_the_author_sees_the_results_before_voting(): void
    {
        $poll = $this->poll($this->member);

        $this->assertTrue($this->service()->canSeeResults($this->member, $poll, $this->loop));
    }

    public function test_the_owner_and_facilitator_see_the_results_before_voting(): void
    {
        $poll = $this->poll($this->member);

        $this->assertTrue($this->service()->canSeeResults($this->owner, $poll, $this->loop));
        $this->assertTrue($this->service()->canSeeResults($this->facilitator, $poll, $this->loop));
    }

    public function test_a_member_who_has_not_voted_does_not_see_the_results(): void
    {
        // Sinon le premier resultat affiche oriente tous les votes suivants.
        $poll = $this->poll($this->member);

        $this->assertFalse($this->service()->canSeeResults($this->other, $poll, $this->loop));
    }

    public function test_a_member_sees_the_results_once_they_have_voted(): void
    {
        $poll = $this->poll($this->member);
        $this->service()->vote($this->other, $poll, $this->loop, [$poll->options->first()->id]);

        $this->assertTrue($this->service()->canSeeResults($this->other, $poll, $this->loop));
    }

    public function test_everyone_sees_the_results_once_the_poll_is_closed(): void
    {
        $poll = $this->poll($this->member);
        $this->service()->close($this->member, $poll, $this->loop);

        $this->assertTrue($this->service()->canSeeResults($this->other, $poll->fresh(), $this->loop));
    }

    // ── Cloture et suppression ──────────────────────────────────────────────

    public function test_the_author_the_owner_and_the_facilitator_may_close(): void
    {
        foreach ([$this->member, $this->owner, $this->facilitator] as $user) {
            $poll = $this->poll($this->member);
            $this->assertTrue($this->service()->canManagePoll($user, $poll, $this->loop));
            $this->service()->close($user, $poll, $this->loop);
            $this->assertTrue($poll->fresh()->isClosed());
        }
    }

    public function test_another_member_may_not_close(): void
    {
        $poll = $this->poll($this->member);

        $this->expectException(PollException::class);
        $this->service()->close($this->other, $poll, $this->loop);
    }

    public function test_closing_twice_is_not_an_error(): void
    {
        $poll = $this->poll();
        $this->service()->close($this->member, $poll, $this->loop);
        $again = $this->service()->close($this->member, $poll->fresh(), $this->loop);

        $this->assertTrue($again->isClosed());
    }

    public function test_a_poll_without_votes_can_be_deleted(): void
    {
        $poll = $this->poll();
        $this->service()->delete($this->member, $poll, $this->loop);

        $this->assertDatabaseMissing('loop_polls', ['id' => $poll->id]);
    }

    public function test_a_poll_with_a_vote_is_never_deleted(): void
    {
        $poll = $this->poll();
        $this->service()->vote($this->other, $poll, $this->loop, [$poll->options->first()->id]);

        try {
            $this->service()->delete($this->member, $poll, $this->loop);
            $this->fail('La suppression aurait dû être refusée.');
        } catch (PollException) {
            // Effacer effacerait le vote de quelqu'un d'autre.
        }

        $this->assertDatabaseHas('loop_polls', ['id' => $poll->id]);
    }

    // ── Depart d'un membre ──────────────────────────────────────────────────

    public function test_a_departed_member_keeps_their_recorded_vote(): void
    {
        $poll = $this->poll();
        $this->service()->vote($this->other, $poll, $this->loop, [$poll->options->first()->id]);

        LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $this->other->id)
            ->update(['status' => 'left']);

        // L'historique nominatif reste : il decrit ce qui s'est passe.
        $this->assertSame(1, LoopPollVote::where('poll_id', $poll->id)->count());
        $this->assertSame(1, $this->service()->results($poll)['participants']);
    }

    public function test_a_poll_survives_the_departure_of_its_author(): void
    {
        $poll = $this->poll($this->member);
        $this->member->delete();

        $fresh = LoopPoll::find($poll->id);

        $this->assertNotNull($fresh);
        $this->assertNull($fresh->created_by);
    }

    // ── Card desactivee ─────────────────────────────────────────────────────

    public function test_disabling_the_card_blocks_writing_but_keeps_the_polls(): void
    {
        $poll = $this->poll();

        app(LoopCardCompositionService::class)->disable($this->loop, 'core.polls');
        $loop = $this->loop->fresh();

        $this->assertFalse($this->service()->canView($this->member, $loop));
        $this->assertFalse($this->service()->canCreate($this->member, $loop));
        $this->assertFalse($this->service()->canVote($this->member, $loop));

        // Rien n'est supprime.
        $this->assertDatabaseHas('loop_polls', ['id' => $poll->id]);
    }

    public function test_re_enabling_the_card_finds_the_polls_again(): void
    {
        $poll = $this->poll();
        $composition = app(LoopCardCompositionService::class);

        $composition->disable($this->loop, 'core.polls');
        $composition->enable($this->loop->fresh(), 'core.polls');

        $loop = $this->loop->fresh();

        $this->assertTrue($this->service()->canView($this->member, $loop));
        $this->assertSame($poll->id, LoopPoll::where('loop_id', $loop->id)->first()->id);
    }

    public function test_a_direct_write_is_refused_when_the_card_is_off(): void
    {
        app(LoopCardCompositionService::class)->disable($this->loop, 'core.polls');

        $this->expectException(PollException::class);
        $this->service()->create($this->member, $this->loop->fresh(), 'Question', null, LoopPoll::TYPE_SINGLE, ['A', 'B']);
    }

    // ── Boucle archivee ─────────────────────────────────────────────────────

    public function test_an_archived_loop_keeps_its_polls_readable(): void
    {
        $poll = $this->poll();
        app(LoopLifecycleService::class)->archive($this->owner, $this->loop);
        $loop = $this->loop->fresh();

        // `polls.view` porte `read` : elle survit.
        $this->assertTrue($this->service()->canView($this->member, $loop));
        $this->assertDatabaseHas('loop_polls', ['id' => $poll->id]);
    }

    public function test_an_archived_loop_accepts_no_poll_writing(): void
    {
        $poll = $this->poll();
        app(LoopLifecycleService::class)->archive($this->owner, $this->loop);
        $loop = $this->loop->fresh();

        $this->assertFalse($this->service()->canCreate($this->member, $loop));
        $this->assertFalse($this->service()->canVote($this->member, $loop));

        $this->expectException(PollException::class);
        $this->service()->vote($this->member, $poll, $loop, [$poll->options->first()->id]);
    }

    public function test_reactivating_finds_the_polls_exactly_as_they_were(): void
    {
        $poll = $this->poll();
        $this->service()->vote($this->other, $poll, $this->loop, [$poll->options->first()->id]);

        $lifecycle = app(LoopLifecycleService::class);
        $lifecycle->archive($this->owner, $this->loop);
        $lifecycle->reactivate($this->owner, $this->loop->fresh());

        $loop = $this->loop->fresh();
        $fresh = LoopPoll::find($poll->id);

        $this->assertTrue($fresh->isOpen());
        $this->assertSame(1, $this->service()->results($fresh)['participants']);
        $this->assertTrue($this->service()->canVote($this->member, $loop));
    }

    // ── Registre et socle ───────────────────────────────────────────────────

    public function test_the_card_is_declared_once_and_renders(): void
    {
        $registry = app(LoopCardRegistry::class);

        $this->assertTrue($registry->exists('core.polls'));
        $this->assertTrue($registry->isRenderable('core.polls'));
        $this->assertSame('loop-polls-card', $registry->componentFor('core.polls'));
        $this->assertSame('polls.view', $registry->viewPermissionFor('core.polls'));
        $this->assertFalse($registry->isRequired('core.polls'));
    }

    public function test_the_administration_offers_the_card(): void
    {
        $this->assertContains('core.polls', app(LoopCardRegistry::class)->manageableKeys());
        $this->assertContains('core.polls', app(LoopCardCompositionService::class)->manageableKeys());

        $composition = collect(app(LoopCardCompositionService::class)->compositionFor($this->loop))
            ->firstWhere('key', 'core.polls');

        $this->assertNotNull($composition);
        $this->assertTrue($composition['enabled']);
    }

    public function test_the_composition_screen_counts_the_polls(): void
    {
        $this->poll();
        $this->poll(labels: ['X', 'Y']);

        $composition = collect(app(LoopCardCompositionService::class)->compositionFor($this->loop))
            ->firstWhere('key', 'core.polls');

        $this->assertSame(2, $composition['data_count']);
    }

    public function test_the_dialogue_preset_carries_the_card(): void
    {
        // « general » EST le type Dialogue : sa cle n'est pas « dialogue ».
        $this->assertContains('core.polls', app(LoopTypeRegistry::class)->cardsFor('general'));
        // Et il est seul : les autres types l'activent localement s'ils veulent.
        $this->assertNotContains('core.polls', app(LoopTypeRegistry::class)->cardsFor('project'));
    }

    public function test_the_preset_synchronisation_reaches_dialogue_loops_and_is_idempotent(): void
    {
        $bare = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Sans preset', 'slug' => 'sans-preset',
            'type' => 'general', 'status' => 'active', 'visibility' => 'private',
            'created_by' => $this->owner->id,
        ]);

        $sync = app(LoopPresetSyncService::class);
        $sync->sync('general');

        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $bare->id, 'card_key' => 'core.polls', 'enabled' => true,
        ]);

        $second = $sync->sync('general');
        $this->assertSame(0, $second['loops_affected']);
    }

    public function test_a_locally_disabled_poll_card_is_never_relit_by_the_preset(): void
    {
        app(LoopCardCompositionService::class)->disable($this->loop, 'core.polls');

        app(LoopPresetSyncService::class)->sync('general');

        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $this->loop->id, 'card_key' => 'core.polls', 'enabled' => false,
        ]);
    }

    public function test_the_workspace_offers_the_card_to_a_member(): void
    {
        $keys = app(LoopCardRegistry::class)
            ->workspaceCardsFor($this->loop->fresh(), $this->member)
            ->pluck('key')->all();

        $this->assertContains('core.polls', $keys);
    }

    // ── ChatLoop ────────────────────────────────────────────────────────────

    public function test_publishing_a_poll_announces_it_in_chatloop(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->set('question', 'On se voit quand ?')
            ->set('options', ['Lundi', 'Mardi'])
            ->call('save');

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'poll_event')->first();

        $this->assertNotNull($message);
        $this->assertSame('created', $message->metadata['event']);
        $this->assertStringContainsString('On se voit quand ?', $message->body);
    }

    public function test_closing_a_poll_announces_it_once(): void
    {
        $poll = $this->poll($this->member);

        Livewire::actingAs($this->member)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->call('close', $poll->id);

        $closed = LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'poll_event')
            ->get()
            ->filter(fn ($m) => ($m->metadata['event'] ?? null) === 'closed');

        $this->assertCount(1, $closed);
    }

    public function test_voting_announces_nothing(): void
    {
        $poll = $this->poll();
        $before = LoopMessage::where('loop_id', $this->loop->id)->count();

        $this->service()->vote($this->member, $poll, $this->loop, [$poll->options->first()->id]);

        // Une Boucle qui vote a vingt n'a pas besoin de vingt lignes.
        $this->assertSame($before, LoopMessage::where('loop_id', $this->loop->id)->count());
    }

    // ── Composant ───────────────────────────────────────────────────────────

    public function test_the_card_lists_open_polls_before_closed_ones(): void
    {
        $closed = $this->poll(labels: ['Vieux A', 'Vieux B']);
        $this->service()->close($this->member, $closed, $this->loop);
        $open = $this->poll(labels: ['Neuf A', 'Neuf B']);

        $rendered = Livewire::actingAs($this->member)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->viewData('polls');

        $this->assertSame($open->id, $rendered->first()['id']);
    }

    public function test_a_member_votes_through_the_card(): void
    {
        $poll = $this->poll($this->other);
        $option = $poll->options->first();

        Livewire::actingAs($this->member)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->call('startVote', $poll->id)
            ->call('toggleChoice', $poll->id, $option->id)
            ->call('submitVote', $poll->id)
            ->assertSet('errorMessage', null);

        $this->assertSame(1, LoopPollVote::where('poll_id', $poll->id)->count());
    }

    public function test_the_card_shows_the_refusal_instead_of_failing_silently(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->set('question', 'Une question')
            ->set('options', ['Seule'])
            ->call('save')
            ->assertSet('errorMessage', __('polls.error_min_options'));
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_a_poll_of_another_organization_is_out_of_reach(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $poll = $this->poll();

        $this->assertFalse($this->service()->canView($stranger, $this->loop));
        $this->assertFalse($this->service()->canSeeResults($stranger, $poll, $this->loop));
    }

    public function test_the_card_of_a_stranger_lists_nothing(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->poll();

        $polls = Livewire::actingAs($stranger)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->viewData('polls');

        $this->assertCount(0, $polls);
    }

    public function test_options_never_leak_across_polls(): void
    {
        $mine = $this->poll(labels: ['A', 'B']);
        $theirs = $this->poll(labels: ['C', 'D']);

        $this->assertSame(0, LoopPollOption::where('poll_id', $mine->id)
            ->whereIn('label', ['C', 'D'])->count());
        $this->assertSame(2, LoopPollOption::where('poll_id', $theirs->id)->count());
    }
}
