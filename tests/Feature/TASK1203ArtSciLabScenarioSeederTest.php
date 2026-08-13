<?php

namespace Tests\Feature;

use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopPollOption;
use App\Models\LoopPollVote;
use App\Models\LoopPollVoteOption;
use App\Models\LoopRoadmapItem;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\ArtSciLabScenarioSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TASK1203ArtSciLabScenarioSeederTest extends TestCase
{
    private const USER_KEYS = [
        'maya', 'jonas', 'sofia', 'theo', 'amina', 'lucas',
        'ines', 'noah', 'elena', 'samir', 'clara',
    ];

    private const LOOP_TYPES = [
        'artscilab-launchpals' => 'general',
        'artscilab-emergence' => 'project',
        'artscilab-publishing' => 'writing',
        'artscilab-ethics' => 'project',
        'artscilab-europe' => 'project',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');
    }

    public function test_compose_builds_the_complete_tenant_safe_artscilab_scenario(): void
    {
        $organization = $this->composeAndBind();
        $users = User::where('organization_id', $organization->id)->orderBy('email')->get();
        $loops = Loop::where('organization_id', $organization->id)->get()->keyBy('slug');

        $this->assertOrganization($organization, $users, $loops);
        $this->assertUsersLoopsAndMemberships($organization, $users, $loops);
        $this->assertMessages($organization, $users, $loops);
        [$requests, $services] = $this->assertMarketplace($organization, $users);
        $this->assertTransactionsAndBalances($organization, $users, $requests, $services);
        $this->assertArticlesAndDossiers($organization, $users, $loops);
        $this->assertFiles($organization, $users);
        $this->assertAiProfilesWithoutInteractionTraces($organization, $users);
        $this->assertCollaborativeFeatures($organization, $users, $loops);
    }

    public function test_compose_is_idempotent_and_does_not_touch_another_tenant(): void
    {
        $sentinel = Organization::factory()->create([
            'name' => 'Sentinel Organization',
            'slug' => 'task-1203-sentinel',
            'locale' => 'fr',
            'welcome_points' => 777,
        ]);
        $sentinelUser = User::factory()->for($sentinel)->create([
            'name' => 'Sentinel Member',
            'email' => 'sentinel@task-1203.test',
            'points_balance' => 777,
        ]);
        $sentinelLoop = Loop::factory()->for($sentinel)->create([
            'name' => 'Sentinel Loop',
            'slug' => 'task-1203-sentinel-loop',
            'created_by' => $sentinelUser->id,
        ]);
        $sentinelBefore = [
            'organization' => $sentinel->fresh()->getAttributes(),
            'user' => $sentinelUser->fresh()->getAttributes(),
            'loop' => $sentinelLoop->fresh()->getAttributes(),
        ];

        $seeder = new ArtSciLabScenarioSeeder;
        $firstOrganization = $seeder->compose();
        $first = $this->scenarioSnapshot($firstOrganization);

        $secondOrganization = $seeder->compose();
        $second = $this->scenarioSnapshot($secondOrganization);

        $this->assertSame($firstOrganization->id, $secondOrganization->id);
        $this->assertSame($first, $second, 'A second composition must preserve every scenario ID, count, balance, ledger entry, and file byte.');
        $this->assertSame($sentinelBefore, [
            'organization' => $sentinel->fresh()->getAttributes(),
            'user' => $sentinelUser->fresh()->getAttributes(),
            'loop' => $sentinelLoop->fresh()->getAttributes(),
        ]);
        $this->assertSame(1, Organization::whereKey($sentinel->id)->count());
        $this->assertSame(1, User::where('organization_id', $sentinel->id)->count());
        $this->assertSame(1, Loop::where('organization_id', $sentinel->id)->count());
    }

    private function composeAndBind(): Organization
    {
        $organization = (new ArtSciLabScenarioSeeder)->compose();
        app()->instance('current_organization', $organization);

        return $organization;
    }

    private function assertOrganization(Organization $organization, Collection $users, Collection $loops): void
    {
        $this->assertSame('ArtSciLab Demo', $organization->name);
        $this->assertSame('artscilab-demo', $organization->slug);
        $this->assertSame('en', $organization->locale);
        $this->assertSame(500, $organization->welcome_points);
        $this->assertSame('multi', $organization->loop_mode);
        $this->assertTrue($organization->loops_enabled);
        $this->assertTrue($organization->ai_profiles_enabled);
        $this->assertSame(1, Organization::where('slug', 'artscilab-demo')->count());
        $this->assertSame($users->firstWhere('email', 'maya@artscilab-demo.test')->id, $organization->admin_id);
        $this->assertSame($loops['artscilab-launchpals']->id, $organization->primary_loop_id);
    }

    private function assertUsersLoopsAndMemberships(Organization $organization, Collection $users, Collection $loops): void
    {
        $expectedEmails = collect(self::USER_KEYS)->map(fn (string $key) => $key.'@artscilab-demo.test')->sort()->values()->all();

        $this->assertCount(11, $users);
        $this->assertSame($expectedEmails, $users->pluck('email')->all());
        $this->assertSame(0, $users->where('is_admin', true)->count());
        $this->assertFalse($users->firstWhere('email', 'maya@artscilab-demo.test')->is_admin);
        $this->assertSame(0, $users->filter(fn (User $user) => $user->preferred_locale !== 'en')->count());

        $this->assertCount(5, $loops);
        $this->assertSame(self::LOOP_TYPES, collect(self::LOOP_TYPES)->map(
            fn (string $type, string $slug) => $loops[$slug]->type,
        )->all());

        $loopSlugs = array_keys(self::LOOP_TYPES);
        foreach ($loopSlugs as $loopPosition => $slug) {
            $expectedMembers = collect(self::USER_KEYS)
                ->filter(fn (string $key, int $index) => $key === 'maya' || $loopPosition === 0 || ($index + $loopPosition) % 4 !== 0)
                ->map(fn (string $key) => $key.'@artscilab-demo.test')
                ->sort()
                ->values()
                ->all();
            $actualMembers = LoopMember::where('loop_id', $loops[$slug]->id)
                ->join('users', 'users.id', '=', 'loop_members.user_id')
                ->orderBy('users.email')
                ->pluck('users.email')
                ->all();

            $this->assertSame($expectedMembers, $actualMembers, "Unexpected membership for {$slug}.");

            $members = LoopMember::where('loop_id', $loops[$slug]->id)->with('user')->get();
            $this->assertTrue($members->every(fn (LoopMember $member) => $member->organization_id === $organization->id
                && $member->status === 'active'
                && $member->role === ($member->user->email === 'maya@artscilab-demo.test'
                    || ($slug === 'artscilab-publishing' && $member->user->email === 'theo@artscilab-demo.test') ? 'owner' : 'member')));
        }

        $this->assertSame(45, LoopMember::where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('loop_members')
            ->join('loops', 'loops.id', '=', 'loop_members.loop_id')
            ->join('users', 'users.id', '=', 'loop_members.user_id')
            ->where('loop_members.organization_id', $organization->id)
            ->where(fn ($query) => $query
                ->whereColumn('loops.organization_id', '!=', 'loop_members.organization_id')
                ->orWhereColumn('users.organization_id', '!=', 'loop_members.organization_id'))
            ->count());
    }

    private function assertMessages(Organization $organization, Collection $users, Collection $loops): void
    {
        $messages = LoopMessage::where('organization_id', $organization->id)->orderBy('body')->get();

        $this->assertCount(120, $messages);
        $this->assertSame(0, LoopMessage::whereIn('loop_id', $loops->pluck('id'))->whereNull('organization_id')->count());
        $this->assertSame(range(1, 120), $messages->pluck('metadata')->pluck('sequence')->sort()->values()->all());
        $this->assertSame(120, $messages->where('type', 'user')->count());
        $this->assertSame(0, $messages->whereNotIn('loop_id', $loops->pluck('id')->all())->count());
        $this->assertSame(0, $messages->whereNotIn('sender_id', $users->pluck('id')->all())->count());
        $this->assertSame(0, DB::table('loop_messages')
            ->join('loops', 'loops.id', '=', 'loop_messages.loop_id')
            ->join('users', 'users.id', '=', 'loop_messages.sender_id')
            ->where('loop_messages.organization_id', $organization->id)
            ->where(fn ($query) => $query
                ->whereColumn('loops.organization_id', '!=', 'loop_messages.organization_id')
                ->orWhereColumn('users.organization_id', '!=', 'loop_messages.organization_id'))
            ->count());
    }

    /** @return array{0: Collection<int, ServiceRequest>, 1: Collection<int, Service>} */
    private function assertMarketplace(Organization $organization, Collection $users): array
    {
        $requests = ServiceRequest::orderBy('title')->get();
        $services = Service::orderBy('title')->get();
        $rawRequests = DB::table('service_requests')->where('organization_id', $organization->id)->orderBy('title')->get();
        $rawServices = DB::table('services')->where('organization_id', $organization->id)->whereNull('deleted_at')->orderBy('title')->get();

        $this->assertCount(10, $requests);
        $this->assertCount(10, $services);
        $this->assertSame($rawRequests->pluck('id')->all(), $requests->pluck('id')->all());
        $this->assertSame($rawServices->pluck('id')->all(), $services->pluck('id')->all());
        $this->assertSame([$organization->id], $requests->pluck('organization_id')->unique()->values()->all());
        $this->assertSame([$organization->id], $services->pluck('organization_id')->unique()->values()->all());
        $this->assertSame(0, $requests->whereNotIn('user_id', $users->pluck('id')->all())->count());
        $this->assertSame(0, $services->whereNotIn('user_id', $users->pluck('id')->all())->count());
        $this->assertMarketplaceRelationsStayInTenant('service_requests', $organization);
        $this->assertMarketplaceRelationsStayInTenant('services', $organization);

        return [$requests, $services];
    }

    private function assertMarketplaceRelationsStayInTenant(string $table, Organization $organization): void
    {
        $this->assertSame(0, DB::table($table)
            ->join('users', 'users.id', '=', $table.'.user_id')
            ->join('categories', 'categories.id', '=', $table.'.category_id')
            ->where($table.'.organization_id', $organization->id)
            ->where(fn ($query) => $query
                ->whereColumn('users.organization_id', '!=', $table.'.organization_id')
                ->orWhereColumn('categories.organization_id', '!=', $table.'.organization_id'))
            ->count());
    }

    private function assertTransactionsAndBalances(
        Organization $organization,
        Collection $users,
        Collection $requests,
        Collection $services,
    ): void {
        $transactions = Transaction::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->with('pointLedgerEntries')
            ->get();

        $this->assertCount(8, $transactions);
        $this->assertSame([
            'accepted' => 1,
            'buyer_done' => 1,
            'cancelled' => 1,
            'completed' => 3,
            'pending' => 1,
            'refused' => 1,
        ], $transactions->countBy('status')->sortKeys()->all());
        $this->assertSame(0, $transactions->whereNotIn('service_id', $services->pluck('id')->all())->count());
        $this->assertSame(0, $transactions->whereNotIn('request_id', $requests->pluck('id')->all())->count());

        foreach ($transactions as $transaction) {
            $entries = $transaction->pointLedgerEntries;
            if ($transaction->status === 'completed') {
                $this->assertCount(2, $entries);
                $this->assertNotNull($transaction->completed_at);
                $this->assertNotNull($transaction->seller_confirmed_at);
                $this->assertSame(0, $entries->sum('delta'));
                $this->assertTrue($entries->contains(fn (PointLedger $entry) => $entry->user_id === $transaction->buyer_id
                    && $entry->delta === -$transaction->points_agreed
                    && $entry->reason === 'exchange_spent'));
                $this->assertTrue($entries->contains(fn (PointLedger $entry) => $entry->user_id === $transaction->seller_id
                    && $entry->delta === $transaction->points_agreed
                    && $entry->reason === 'exchange_earned'));
            } else {
                $this->assertCount(0, $entries, "Only completed transaction {$transaction->id} may have ledger entries.");
                $this->assertNull($transaction->completed_at);
            }
        }

        $this->assertSame(6, PointLedger::where('organization_id', $organization->id)->count());
        foreach ($users as $user) {
            $ledgerSum = PointLedger::where('organization_id', $organization->id)->where('user_id', $user->id)->sum('delta');
            $this->assertSame(500 + (int) $ledgerSum, $user->fresh()->points_balance, "Incorrect derived balance for {$user->email}.");
        }

        $this->assertSame(0, DB::table('transactions')
            ->join('services', 'services.id', '=', 'transactions.service_id')
            ->join('service_requests', 'service_requests.id', '=', 'transactions.request_id')
            ->join('users as buyers', 'buyers.id', '=', 'transactions.buyer_id')
            ->join('users as sellers', 'sellers.id', '=', 'transactions.seller_id')
            ->where('transactions.organization_id', $organization->id)
            ->where(fn ($query) => $query
                ->whereColumn('services.organization_id', '!=', 'transactions.organization_id')
                ->orWhereColumn('service_requests.organization_id', '!=', 'transactions.organization_id')
                ->orWhereColumn('buyers.organization_id', '!=', 'transactions.organization_id')
                ->orWhereColumn('sellers.organization_id', '!=', 'transactions.organization_id'))
            ->count());
        $this->assertSame(0, DB::table('point_ledger')
            ->join('transactions', 'transactions.id', '=', 'point_ledger.transaction_id')
            ->join('users', 'users.id', '=', 'point_ledger.user_id')
            ->where('point_ledger.organization_id', $organization->id)
            ->where(fn ($query) => $query
                ->whereColumn('transactions.organization_id', '!=', 'point_ledger.organization_id')
                ->orWhereColumn('users.organization_id', '!=', 'point_ledger.organization_id'))
            ->count());
    }

    private function assertArticlesAndDossiers(Organization $organization, Collection $users, Collection $loops): void
    {
        $categories = Category::where('organization_id', $organization->id)->get();
        $posts = BlogPost::where('organization_id', $organization->id)->orderBy('slug')->get();
        $dossiers = Dossier::where('organization_id', $organization->id)->get();
        $entries = DossierBlogPost::where('organization_id', $organization->id)->get();

        $this->assertCount(4, $categories);
        $this->assertCount(12, $posts);
        $this->assertSame(array_map(fn (int $number) => 'artscilab-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT), range(1, 12)), $posts->pluck('slug')->all());
        $this->assertSame(0, $posts->whereNotIn('user_id', $users->pluck('id')->all())->count());
        $this->assertSame(0, $posts->whereNotIn('category_id', $categories->pluck('id')->all())->count());

        $this->assertCount(15, $dossiers);
        $this->assertSame(15, $dossiers->pluck('name')->unique()->count());
        $this->assertSame(0, $dossiers->whereNull('loop_id')->whereNotIn('owner_id', $users->pluck('id')->all())->count());
        $this->assertSame(5, $dossiers->whereIn('loop_id', $loops->pluck('id')->all())->count());
        $this->assertSame(10, $dossiers->whereNull('loop_id')->count());
        $this->assertSame(0, $dossiers->whereNull('loop_id')->whereNotIn('shared_with_loop_id', $loops->pluck('id')->all())->count());
        $this->assertTrue($dossiers->every(fn (Dossier $dossier) => ($dossier->owner_id === null) !== ($dossier->loop_id === null)));
        $this->assertTrue($dossiers->whereNotNull('loop_id')->every(fn (Dossier $dossier) => $dossier->shared_with_loop_id === null));
        $this->assertTrue($dossiers->every(fn (Dossier $dossier) => $dossier->visibility === Dossier::VISIBILITY_LOOP));
        $maya = $users->firstWhere('email', 'maya@artscilab-demo.test');
        $this->assertSame(10, $dossiers->whereNull('loop_id')->filter(fn (Dossier $dossier) => $dossier->owner_id === $maya->id || $dossier->dossierMembers()->where('user_id', $maya->id)->exists())->count());

        $this->assertCount(12, $entries);
        $this->assertSame($posts->pluck('id')->sort()->values()->all(), $entries->pluck('blog_post_id')->sort()->values()->all());
        $this->assertSame(range(1, 12), $entries->pluck('position')->sort()->values()->all());
        $this->assertSame(0, $entries->whereNotIn('dossier_id', $dossiers->pluck('id')->all())->count());
        $this->assertSame(0, $entries->whereNotIn('added_by', $users->pluck('id')->all())->count());
        $this->assertSame(0, DB::table('dossier_blog_posts')
            ->join('dossiers', 'dossiers.id', '=', 'dossier_blog_posts.dossier_id')
            ->join('blog_posts', 'blog_posts.id', '=', 'dossier_blog_posts.blog_post_id')
            ->join('users', 'users.id', '=', 'dossier_blog_posts.added_by')
            ->where('dossier_blog_posts.organization_id', $organization->id)
            ->where(fn ($query) => $query
                ->whereColumn('dossiers.organization_id', '!=', 'dossier_blog_posts.organization_id')
                ->orWhereColumn('blog_posts.organization_id', '!=', 'dossier_blog_posts.organization_id')
                ->orWhereColumn('users.organization_id', '!=', 'dossier_blog_posts.organization_id'))
            ->count());
    }

    private function assertFiles(Organization $organization, Collection $users): void
    {
        $dossiers = Dossier::where('organization_id', $organization->id)->get();
        $files = DossierFile::where('organization_id', $organization->id)->orderBy('path')->get();

        $this->assertCount(18, $files);
        foreach ($files as $file) {
            Storage::disk('dossier_files')->assertExists($file->path);
            $contents = Storage::disk('dossier_files')->get($file->path);
            $this->assertSame('dossier_files', $file->disk);
            $this->assertSame(strlen($contents), $file->size_bytes);
            $this->assertSame(hash('sha256', $contents), $file->checksum_sha256);
            $this->assertContains($file->dossier_id, $dossiers->pluck('id')->all(), true);
            $this->assertContains($file->uploaded_by, $users->pluck('id')->all(), true);
        }
        $this->assertSame($files->pluck('path')->sort()->values()->all(), collect(Storage::disk('dossier_files')->allFiles('artscilab-demo'))->sort()->values()->all());
    }

    private function assertAiProfilesWithoutInteractionTraces(Organization $organization, Collection $users): void
    {
        $profiles = MemberAiProfile::where('organization_id', $organization->id)->get();
        $expectedOwners = ['amina', 'elena', 'ines', 'jonas', 'maya', 'sofia', 'theo'];

        $this->assertCount(7, $profiles);
        $this->assertSame($expectedOwners, $users->whereIn('id', $profiles->pluck('user_id'))->pluck('email')->map(fn (string $email) => str($email)->before('@')->toString())->sort()->values()->all());
        $this->assertTrue($profiles->every(fn (MemberAiProfile $profile) => $profile->status === MemberAiProfile::STATUS_PUBLISHED
            && $profile->locale === 'en'
            && $profile->generated_summary === null
            && $profile->generated_at === null
            && $profile->metadata['source'] === 'human_declaration'));
        $this->assertSame(0, AiInteraction::where('organization_id', $organization->id)->count());
        $this->assertSame(0, AdminAiInteraction::where('organization_id', $organization->id)->count());
        $this->assertSame(0, MemberAiProfileInteraction::where('organization_id', $organization->id)->count());
    }

    private function assertCollaborativeFeatures(Organization $organization, Collection $users, Collection $loops): void
    {
        $events = LoopEvent::where('organization_id', $organization->id)->get();
        $responses = LoopEventResponse::where('organization_id', $organization->id)->get();
        $polls = LoopPoll::where('organization_id', $organization->id)->get();
        $votes = LoopPollVote::where('organization_id', $organization->id)->get();
        $decisions = LoopDecision::where('organization_id', $organization->id)->get();
        $roadmap = LoopRoadmapItem::where('organization_id', $organization->id)->get();

        $this->assertCount(10, $events);
        $this->assertSame(['hybrid' => 3, 'in_person' => 3, 'online' => 4], $events->countBy('format')->sortKeys()->all());
        $this->assertCount(39, $responses);
        $this->assertSame(0, $responses->whereNotIn('event_id', $events->pluck('id')->all())->count());
        $this->assertSame(0, $responses->whereNotIn('user_id', $users->pluck('id')->all())->count());
        $this->assertSame(0, DB::table('loop_event_responses')
            ->join('loop_events', 'loop_events.id', '=', 'loop_event_responses.event_id')
            ->join('users', 'users.id', '=', 'loop_event_responses.user_id')
            ->where('loop_event_responses.organization_id', $organization->id)
            ->where(fn ($query) => $query
                ->whereColumn('loop_events.organization_id', '!=', 'loop_event_responses.organization_id')
                ->orWhereColumn('users.organization_id', '!=', 'loop_event_responses.organization_id'))
            ->count());

        $this->assertCount(4, $polls);
        $this->assertSame(['closed' => 1, 'open' => 3], $polls->countBy('status')->sortKeys()->all());
        $this->assertSame(12, LoopPollOption::whereIn('poll_id', $polls->pluck('id'))->count());
        $this->assertCount(24, $votes);
        $this->assertSame(27, LoopPollVoteOption::whereIn('vote_id', $votes->pluck('id'))->count());
        $this->assertSame(0, $votes->whereNotIn('user_id', $users->pluck('id')->all())->count());
        $this->assertSame(0, DB::table('loop_poll_votes')
            ->join('loop_polls', 'loop_polls.id', '=', 'loop_poll_votes.poll_id')
            ->join('users', 'users.id', '=', 'loop_poll_votes.user_id')
            ->where('loop_poll_votes.organization_id', $organization->id)
            ->where(fn ($query) => $query
                ->whereColumn('loop_polls.organization_id', '!=', 'loop_poll_votes.organization_id')
                ->orWhereColumn('users.organization_id', '!=', 'loop_poll_votes.organization_id'))
            ->count());

        $this->assertCount(6, $decisions);
        $this->assertTrue($decisions->every(fn (LoopDecision $decision) => $decision->loop_message_id === null));
        $this->assertCount(15, $roadmap);
        $this->assertSame(['done' => 4, 'in_progress' => 5, 'todo' => 6], $roadmap->countBy('status')->sortKeys()->all());
        $this->assertSame(15, DB::table('loop_roadmap_item_user')->whereIn('loop_roadmap_item_id', $roadmap->pluck('id'))->count());
        $this->assertSame(0, $roadmap->whereNotIn('loop_id', $loops->pluck('id')->all())->count());
        $this->assertTrue($roadmap->every(fn (LoopRoadmapItem $item) => $item->loop_decision_id === null
            || $decisions->contains(fn (LoopDecision $decision) => $decision->id === $item->loop_decision_id && $decision->loop_id === $item->loop_id)));
        $this->assertSame(0, DB::table('loop_roadmap_item_user')
            ->join('loop_roadmap_items', 'loop_roadmap_items.id', '=', 'loop_roadmap_item_user.loop_roadmap_item_id')
            ->join('users', 'users.id', '=', 'loop_roadmap_item_user.user_id')
            ->where('loop_roadmap_items.organization_id', $organization->id)
            ->whereColumn('users.organization_id', '!=', 'loop_roadmap_items.organization_id')
            ->count());
    }

    /** @return array<string, mixed> */
    private function scenarioSnapshot(Organization $organization): array
    {
        $models = [
            'users' => User::class,
            'categories' => Category::class,
            'loops' => Loop::class,
            'loop_members' => LoopMember::class,
            'loop_messages' => LoopMessage::class,
            'requests' => ServiceRequest::class,
            'services' => Service::class,
            'transactions' => Transaction::class,
            'ledger' => PointLedger::class,
            'posts' => BlogPost::class,
            'dossiers' => Dossier::class,
            'dossier_posts' => DossierBlogPost::class,
            'files' => DossierFile::class,
            'profiles' => MemberAiProfile::class,
            'events' => LoopEvent::class,
            'event_responses' => LoopEventResponse::class,
            'polls' => LoopPoll::class,
            'poll_votes' => LoopPollVote::class,
            'decisions' => LoopDecision::class,
            'roadmap' => LoopRoadmapItem::class,
        ];
        $snapshot = [];

        foreach ($models as $name => $modelClass) {
            $snapshot[$name] = $modelClass::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->orderBy('id')
                ->pluck('id')
                ->all();
        }

        $pollIds = $snapshot['polls'];
        $voteIds = $snapshot['poll_votes'];
        $roadmapIds = $snapshot['roadmap'];
        $snapshot['poll_options'] = LoopPollOption::whereIn('poll_id', $pollIds)->orderBy('id')->pluck('id')->all();
        $snapshot['poll_vote_options'] = LoopPollVoteOption::whereIn('vote_id', $voteIds)->orderBy('id')->pluck('id')->all();
        $snapshot['roadmap_assignees'] = DB::table('loop_roadmap_item_user')->whereIn('loop_roadmap_item_id', $roadmapIds)->orderBy('id')->get(['id', 'loop_roadmap_item_id', 'user_id'])->map(fn ($row) => (array) $row)->all();
        $snapshot['ledger_rows'] = PointLedger::where('organization_id', $organization->id)->orderBy('id')->get(['id', 'transaction_id', 'user_id', 'delta', 'reason'])->map->getAttributes()->all();
        $snapshot['balances'] = User::where('organization_id', $organization->id)->orderBy('id')->pluck('points_balance', 'id')->all();
        $snapshot['file_bytes'] = DossierFile::where('organization_id', $organization->id)->orderBy('path')->get()->mapWithKeys(fn (DossierFile $file) => [$file->path => Storage::disk($file->disk)->get($file->path)])->all();

        return $snapshot;
    }
}
