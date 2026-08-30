<?php

namespace Tests\Feature;

use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopPollVote;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\User;
use App\Support\ScenarioPacks\Packs\Test20260822DogfoodingDataset;
use App\Support\ScenarioPacks\Packs\Test20260822DogfoodingPack;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1335 — l'activite humaine collective de `HISTORICAL_ACTIVITY`
 * (9 messages, 1 sondage, 1 decision, 1 evenement, 1 element de roadmap) sur
 * les seules Boucles 08-Protocole, 09-UT Dallas, 10-Aria : aucun message IA,
 * aucune `ai_interaction`, ecritures par les seules primitives canoniques du
 * produit, idempotence au rejeu, reset et retrait FK-safe.
 *
 * Fixture minimale (les 10 repertoires declares, aucun fichier requis pour
 * cette activite) : la partie corpus est le contrat de TASK-1269.
 */
#[Group('ai')]
class TASK1335HistoricalActivityTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = Test20260822DogfoodingPack::ORGANIZATION_SLUG;

    private Organization $organization;

    private string $source;

    /** @var array<string, User> */
    private array $personas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake(Test20260822DogfoodingPack::DISK);

        Organization::factory()->create(['is_default' => true, 'is_active' => true, 'slug' => 'plateforme-1335']);
        $this->organization = Organization::factory()->create([
            'slug' => self::ORG,
            'name' => 'test20260822',
            'loops_enabled' => true,
            'transactions_naming' => 'b2c',
            'welcome_points' => 100,
            'membership_enabled' => false,
            'loop_composition_policy' => Organization::COMPOSITION_LOCKED,
        ]);

        foreach (Test20260822DogfoodingPack::PERSONA_EMAILS as $key => $email) {
            $this->personas[$key] = User::factory()->create([
                'email' => $email,
                'organization_id' => $this->organization->id,
                'name' => 'Test '.ucfirst(substr($key, 5)),
                'preferred_locale' => 'fr',
                'points_balance' => 0,
            ]);
        }
        $this->organization->update(['admin_id' => $this->personas['test_cyril']->id]);

        $this->source = sys_get_temp_dir().'/task1335-'.uniqid();
        foreach (Test20260822DogfoodingPack::LOOP_DIRECTORIES as $name) {
            File::makeDirectory($this->source.'/'.$name, 0755, true);
        }

        config([
            'scenario_packs.allowed_organizations' => [self::ORG, 'artscilab-demo'],
            'scenario_packs.definitions' => [Test20260822DogfoodingPack::PACK_ID => Test20260822DogfoodingPack::class],
            Test20260822DogfoodingPack::SOURCE_CONFIG_KEY => $this->source,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function load(): void
    {
        $pack = app(ScenarioPackCatalog::class)->get(Test20260822DogfoodingPack::PACK_ID);
        app(ScenarioPackLoader::class)->load($pack, $this->organization);
    }

    private function reset(): void
    {
        app(ScenarioPackResetter::class)->reset(
            app(ScenarioPackCatalog::class)->get(Test20260822DogfoodingPack::PACK_ID),
            $this->organization,
        );
    }

    private function remove(): void
    {
        app(ScenarioPackRemover::class)->remove(Test20260822DogfoodingPack::PACK_ID, $this->organization);
    }

    private function loop(string $name): Loop
    {
        return Loop::query()->where('organization_id', $this->organization->id)->where('name', $name)->firstOrFail();
    }

    public function test_load_writes_exactly_the_declared_distribution_across_08_09_10_and_nowhere_else(): void
    {
        $this->load();

        $this->assertSame(9, LoopMessage::query()->count());
        $this->assertSame(1, LoopPoll::query()->count());
        $this->assertSame(1, LoopDecision::query()->count());
        $this->assertSame(1, LoopEvent::query()->count());
        $this->assertSame(1, LoopRoadmapItem::query()->count());

        $this->assertSame(3, LoopMessage::query()->where('loop_id', $this->loop("08-Protocole d'emergence")->id)->count());
        $this->assertSame(3, LoopMessage::query()->where('loop_id', $this->loop('09-UT Dallas')->id)->count());
        $this->assertSame(3, LoopMessage::query()->where('loop_id', $this->loop('10-Aria projet européen')->id)->count());

        $this->assertSame(1, LoopPoll::query()->where('loop_id', $this->loop("08-Protocole d'emergence")->id)->count());
        $this->assertSame(1, LoopRoadmapItem::query()->where('loop_id', $this->loop("08-Protocole d'emergence")->id)->count());
        $this->assertSame(1, LoopDecision::query()->where('loop_id', $this->loop('09-UT Dallas')->id)->count());
        $this->assertSame(1, LoopEvent::query()->where('loop_id', $this->loop('09-UT Dallas')->id)->count());

        // Les 7 autres Boucles n'en portent aucune trace.
        foreach (Test20260822DogfoodingDataset::LOOP_SETUP as $name => $entry) {
            if (isset(Test20260822DogfoodingDataset::HISTORICAL_ACTIVITY[$name])) {
                continue;
            }
            $loop = $this->loop($name);
            $this->assertSame(0, LoopMessage::query()->where('loop_id', $loop->id)->count(), $name);
            $this->assertSame(0, LoopPoll::query()->where('loop_id', $loop->id)->count(), $name);
            $this->assertSame(0, LoopDecision::query()->where('loop_id', $loop->id)->count(), $name);
            $this->assertSame(0, LoopEvent::query()->where('loop_id', $loop->id)->count(), $name);
            $this->assertSame(0, LoopRoadmapItem::query()->where('loop_id', $loop->id)->count(), $name);
        }
    }

    public function test_messages_are_all_human_authored_by_their_declared_persona_with_the_declared_body(): void
    {
        Queue::fake();

        $this->load();

        $this->assertSame(0, LoopMessage::query()->where('type', '!=', 'user')->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());

        foreach (Test20260822DogfoodingDataset::HISTORICAL_ACTIVITY as $loopName => $activity) {
            $loop = $this->loop($loopName);

            foreach ($activity['messages'] as $spec) {
                $message = LoopMessage::query()
                    ->where('loop_id', $loop->id)
                    ->where('metadata->scenario_message_key', $spec['key'])
                    ->firstOrFail();

                $this->assertSame($spec['body'], $message->body, $spec['key']);
                $this->assertSame($this->personas[$spec['persona']]->id, $message->sender_id, $spec['key']);
                $this->assertSame('user', $message->type, $spec['key']);
                $this->assertSame(Test20260822DogfoodingPack::PACK_ID, $message->metadata['scenario'] ?? null, $spec['key']);
            }
        }
    }

    public function test_poll_has_the_declared_options_and_only_sana_voted(): void
    {
        $this->load();

        $poll = LoopPoll::query()->with('options')->firstOrFail();
        $spec = Test20260822DogfoodingDataset::HISTORICAL_ACTIVITY["08-Protocole d'emergence"]['poll'];

        $this->assertSame($spec['question'], $poll->question);
        $this->assertSame($this->personas[$spec['author']]->id, $poll->created_by);
        $this->assertSame($spec['selection_type'], $poll->selection_type);
        $this->assertSame($spec['labels'], $poll->options->sortBy('position')->pluck('label')->values()->all());
        $this->assertTrue($poll->isOpen(), 'le sondage reste ouvert, jamais clos par le pack.');

        $enOption = $poll->options->firstWhere('label', 'EN v0.2');
        $vote = LoopPollVote::query()->where('poll_id', $poll->id)->where('user_id', $this->personas['test_sana']->id)->firstOrFail();
        $this->assertTrue($vote->options->pluck('id')->contains($enOption->id));

        $this->assertSame(0, LoopPollVote::query()->where('poll_id', $poll->id)->where('user_id', $this->personas['test_roger']->id)->count(), 'Roger reste non votant.');
        $this->assertSame(1, LoopPollVote::query()->where('poll_id', $poll->id)->count());
    }

    public function test_decision_is_recorded_without_any_automatic_action(): void
    {
        $this->load();

        $decision = LoopDecision::query()->firstOrFail();
        $spec = Test20260822DogfoodingDataset::HISTORICAL_ACTIVITY['09-UT Dallas']['decision'];

        $this->assertSame($spec['title'], $decision->title);
        $this->assertSame($spec['rationale'], $decision->rationale);
        $this->assertSame($this->personas[$spec['author']]->id, $decision->author_id);
        $this->assertNull($decision->loop_message_id, 'consignee directement, jamais promue depuis un message.');
        $this->assertNull($decision->superseded_by_id);

        // "sans action automatique" (brief T1335) : aucun LoopRoadmapItem ne
        // reference cette Decision.
        $this->assertSame(0, LoopRoadmapItem::query()->where('loop_decision_id', $decision->id)->count());
    }

    public function test_event_has_kiran_going_and_roger_without_any_response(): void
    {
        $this->load();

        $event = LoopEvent::query()->firstOrFail();
        $spec = Test20260822DogfoodingDataset::HISTORICAL_ACTIVITY['09-UT Dallas']['event'];

        $this->assertSame($spec['title'], $event->title);
        $this->assertSame($this->personas[$spec['creator']]->id, $event->created_by);
        $this->assertSame('scheduled', $event->status);

        $kiranResponse = LoopEventResponse::query()->where('event_id', $event->id)->where('user_id', $this->personas['test_kiran']->id)->firstOrFail();
        $this->assertSame('going', $kiranResponse->response);

        $this->assertSame(0, LoopEventResponse::query()->where('event_id', $event->id)->where('user_id', $this->personas['test_roger']->id)->count());
        $this->assertSame(1, LoopEventResponse::query()->where('event_id', $event->id)->count());
    }

    public function test_roadmap_item_is_todo_and_assigned_to_roger_only(): void
    {
        $this->load();

        $item = LoopRoadmapItem::query()->firstOrFail();
        $spec = Test20260822DogfoodingDataset::HISTORICAL_ACTIVITY["08-Protocole d'emergence"]['roadmap_item'];

        $this->assertSame($spec['title'], $item->title);
        $this->assertSame('todo', $item->status);
        $this->assertNull($item->completed_at);
        $this->assertSame(
            [$this->personas['test_roger']->id],
            $item->assignees()->pluck('users.id')->all(),
        );
    }

    public function test_replaying_load_duplicates_nothing_votes_nothing_twice_and_responds_nothing_twice(): void
    {
        $this->load();
        $ids = [
            'messages' => LoopMessage::query()->orderBy('id')->pluck('id')->all(),
            'poll' => LoopPoll::query()->pluck('id')->all(),
            'decision' => LoopDecision::query()->pluck('id')->all(),
            'event' => LoopEvent::query()->pluck('id')->all(),
            'roadmap_item' => LoopRoadmapItem::query()->pluck('id')->all(),
        ];

        $this->load();
        $this->load();

        $this->assertSame(9, LoopMessage::query()->count());
        $this->assertSame(1, LoopPoll::query()->count());
        $this->assertSame(1, LoopDecision::query()->count());
        $this->assertSame(1, LoopEvent::query()->count());
        $this->assertSame(1, LoopRoadmapItem::query()->count());
        $this->assertSame(1, LoopPollVote::query()->count(), 'un seul vote apres rejeu, pas un par passage.');
        $this->assertSame(1, LoopEventResponse::query()->count(), 'une seule reponse apres rejeu.');

        $this->assertSame($ids, [
            'messages' => LoopMessage::query()->orderBy('id')->pluck('id')->all(),
            'poll' => LoopPoll::query()->pluck('id')->all(),
            'decision' => LoopDecision::query()->pluck('id')->all(),
            'event' => LoopEvent::query()->pluck('id')->all(),
            'roadmap_item' => LoopRoadmapItem::query()->pluck('id')->all(),
        ], 'memes lignes, pas une seconde copie.');

        $this->reset();
        $this->assertSame(9, LoopMessage::query()->count());
        $this->assertSame(1, LoopPoll::query()->count());
    }

    public function test_registry_tracks_the_five_new_entity_types_as_created_and_removal_purges_them(): void
    {
        $this->load();

        $entities = ScenarioPackEntity::query()->where('organization_id', $this->organization->id)->get();

        foreach (['loop_message' => 9, 'loop_poll' => 1, 'loop_decision' => 1, 'loop_event' => 1, 'loop_roadmap_item' => 1] as $type => $count) {
            $rows = $entities->where('entity_type', $type);
            $this->assertSame($count, $rows->count(), $type);
            $this->assertTrue($rows->every(fn (ScenarioPackEntity $e) => $e->ownership === ScenarioPackEntity::OWNERSHIP_CREATED), "{$type} doit etre created");
        }

        $this->remove();

        $this->assertSame(0, LoopMessage::query()->count());
        $this->assertSame(0, LoopPoll::query()->count());
        $this->assertSame(0, LoopDecision::query()->count());
        $this->assertSame(0, LoopEvent::query()->count());
        $this->assertSame(0, LoopRoadmapItem::query()->count());
        $this->assertSame(0, LoopPollVote::query()->count());
        $this->assertSame(0, LoopEventResponse::query()->count());
        $this->assertSame(0, ScenarioPackEntity::query()->where('organization_id', $this->organization->id)->count());

        foreach ($this->personas as $user) {
            $this->assertNotNull($user->fresh(), 'les comptes ne sont jamais supprimes.');
        }
    }
}
