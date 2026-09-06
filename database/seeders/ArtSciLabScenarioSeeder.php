<?php

namespace Database\Seeders;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\DossierMember;
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
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Loops\LoopTypeRegistry;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/**
 * Deterministic ArtSciLab/LaunchPals product-validation scenario (TASK-1203).
 *
 * This seeder is deliberately tenant-local and idempotent: every row has a
 * stable natural key and every tenant-owned row carries organization_id. A
 * future production import command may call compose() after applying its own
 * explicit target-tenant authorization and safety boundary; it must not reset
 * the database, remove other tenants, or manufacture AI interaction traces.
 * Files are real objects on the dossier_files disk and completed exchanges use
 * the same atomic double-ledger then balance-update sequence as the product.
 *
 * TASK-1242: `compose()` accepts an optional `ScenarioPackEntityRegistrar`.
 * When provided, every row this seeder creates or reuses is declared to it
 * (`ArtSciLabDemoPack`, the scenario pack wrapping this seeder), so the
 * scenario pack engine (TASK-1240) can idempotently reload, reset and
 * boundedly remove this exact content. `run()` (direct `db:seed` usage,
 * outside the pack engine) keeps passing no registrar and behaves exactly
 * as before.
 */
class ArtSciLabScenarioSeeder extends Seeder
{
    public const SLUG = 'artscilab-demo';

    private CarbonImmutable $base;

    public function run(): void
    {
        $organization = $this->compose();

        $this->command?->info("ArtSciLab scenario: {$organization->id} ({$organization->slug})");
    }

    public function compose(?ScenarioPackEntityRegistrar $registrar = null): Organization
    {
        $this->base = CarbonImmutable::parse('2026-05-04 09:00:00', 'UTC');

        $organization = $this->organization();
        $users = $this->users($organization, $registrar);
        $categories = $this->categories($organization, $registrar);
        $loops = $this->loops($organization, $users, $registrar);

        $this->membersAndMessages($organization, $users, $loops, $registrar);
        [$requests, $services] = $this->marketplace($organization, $users, $categories, $registrar);
        $this->transactions($organization, $users, $requests, $services, $registrar);
        $posts = $this->blogPosts($organization, $users, $categories, $registrar);
        $dossiers = $this->dossiers($organization, $users, $loops, $posts, $registrar);
        $this->dossierFiles($organization, $users, $dossiers, $registrar);
        $this->memberProfiles($organization, $users, $registrar);
        $this->events($organization, $users, $loops, $registrar);
        $this->polls($organization, $users, $loops, $registrar);
        $this->decisionsAndRoadmap($organization, $users, $loops, $registrar);

        return $organization;
    }

    private function organization(): Organization
    {
        $organization = Organization::withTrashed()->updateOrCreate(
            ['slug' => self::SLUG],
            [
                'name' => 'ArtSciLab Demo',
                'description' => 'A European laboratory where artists, researchers and technologists turn experiments into shared projects.',
                'is_active' => true,
                'is_public' => false,
                'is_default' => false,
                'hero_title' => 'Make ambitious experiments possible together',
                'hero_description' => 'LaunchPals connects practical help, editorial work and European collaborations.',
                'accent_color' => '#7c3aed',
                'welcome_points' => 500,
                'loops_enabled' => true,
                'members_can_create_loops' => true,
                'ai_profiles_enabled' => true,
                'loop_mode' => 'multi',
                'locale' => 'en',
                'default_country_code' => 'FR',
                'show_country' => true,
                'blog_naming' => 'b2c',
                'transactions_naming' => 'b2b',
                'loops_naming' => 'b2c',
                'loop_composition_policy' => Organization::COMPOSITION_OWNER_ALLOWED,
            ],
        );

        if ($organization->trashed()) {
            $organization->restore();
        }

        return $organization;
    }

    /** @return array<string, User> */
    private function users(Organization $organization, ?ScenarioPackEntityRegistrar $registrar): array
    {
        $people = [
            'maya' => ['Maya Chen', 'Maya', 'Paris', 'France', 'FR', 'Artistic director translating research into participatory installations.'],
            'jonas' => ['Jonas Weber', 'Jonas', 'Berlin', 'Germany', 'DE', 'Creative technologist building dependable prototypes and open tools.'],
            'sofia' => ['Sofia Rossi', 'Sofia', 'Milan', 'Italy', 'IT', 'European partnerships lead focused on fair, workable consortiums.'],
            'theo' => ['Theo Martin', 'Theo', 'Lyon', 'France', 'FR', 'Editor and facilitator helping experimental work find a clear voice.'],
            'amina' => ['Amina Diallo', 'Amina', 'Brussels', 'Belgium', 'BE', 'Community producer creating inclusive gatherings across disciplines.'],
            'lucas' => ['Lucas Novak', 'Lucas', 'Prague', 'Czechia', 'DE', 'Interaction designer testing humane interfaces for collective systems.'],
            'ines' => ['Ines Costa', 'Ines', 'Lisbon', 'Portugal', 'ES', 'Researcher working on situated ethics and accountable AI practice.'],
            'noah' => ['Noah Williams', 'Noah', 'London', 'United Kingdom', 'GB', 'Sound artist and producer experienced in touring hybrid performances.'],
            'elena' => ['Elena Petrov', 'Elena', 'Sofia', 'Bulgaria', 'DE', 'Project manager turning complex grants into readable plans and budgets.'],
            'samir' => ['Samir Haddad', 'Samir', 'Marseille', 'France', 'FR', 'Fabrication specialist for low-impact exhibits and public prototypes.'],
            'clara' => ['Clara Jensen', 'Clara', 'Copenhagen', 'Denmark', 'DE', 'Evaluator documenting learning without flattening creative practice.'],
        ];

        $users = [];
        foreach ($people as $key => [$name, $firstName, $city, $country, $countryCode, $bio]) {
            $email = $key.'@artscilab-demo.test';
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'organization_id' => $organization->id,
                    'name' => $name,
                    'first_name' => $firstName,
                    'password' => Hash::make('password'),
                    'email_verified_at' => $this->base,
                    'bio' => $bio,
                    'city' => $city,
                    'location' => $city.', '.$country,
                    'country_code' => $countryCode,
                    'phone' => '+33 6 00 00 00 '.str_pad((string) (count($users) + 1), 2, '0', STR_PAD_LEFT),
                    'preferred_locale' => 'en',
                    'is_available' => true,
                    'is_admin' => false,
                    'banned_at' => null,
                ],
            );

            if ($user->wasRecentlyCreated) {
                $user->update(['points_balance' => 500]);
            }
            $registrar?->track('persona', $key, $user);
            $users[$key] = $user;
        }

        $organization->update(['admin_id' => $users['maya']->id]);

        return $users;
    }

    /** @return array<int, Category> */
    private function categories(Organization $organization, ?ScenarioPackEntityRegistrar $registrar): array
    {
        $definitions = [
            ['Creative Technology', 'creative-technology', '#7c3aed'],
            ['Editorial & Facilitation', 'editorial-facilitation', '#0f766e'],
            ['European Projects', 'european-projects', '#b45309'],
            ['Production & Events', 'production-events', '#be123c'],
        ];

        // TASK-1245 : trackees (elles ne l'etaient pas en T1242, ou le
        // remover soft-supprimait Service et aurait bute sur la FK RESTRICT
        // de `services.category_id`). Le remover purge desormais
        // physiquement (forceDelete borne) dans l'ordre inverse
        // d'inscription : `categories()` s'execute AVANT `marketplace()`,
        // donc les Category ont une sequence plus basse que Service et
        // ServiceRequest et sont supprimees APRES eux — FK-safe sans rien
        // d'autre. Ownership : `created` si ce chargement cree la ligne,
        // `reused` (jamais supprimee) si un slug `artscilab-*` preexistait.
        return array_map(function (array $item) use ($organization, $registrar) {
            $category = Category::updateOrCreate(
                ['slug' => 'artscilab-'.$item[1]],
                ['organization_id' => $organization->id, 'name_b2c' => $item[0], 'name_b2b' => $item[0], 'color' => $item[2]],
            );
            $registrar?->track('category', $item[1], $category);

            return $category;
        }, $definitions);
    }

    /** @param array<string, User> $users @return array<string, Loop> */
    private function loops(Organization $organization, array $users, ?ScenarioPackEntityRegistrar $registrar): array
    {
        $definitions = [
            'launchpals' => ['LaunchPals', 'general', 'The shared home for introductions, mutual help and monthly gatherings.', 'A practical community for ambitious experiments.'],
            'emergence' => ['Emergence', 'project', 'A workshop for fragile ideas that need critique, partners and a first test.', 'Move promising signals toward a responsible prototype.'],
            'publishing' => ['Experimental Publishing', 'writing', 'An editorial studio for field notes, essays and unusual publication formats.', 'Publish the process, not only the polished result.'],
            'ethics' => ['AI Shepherd & Ethics', 'project', 'A working group for accountable choices around generative and adaptive systems.', 'Make durable human decisions about AI practice.'],
            'europe' => ['European Projects', 'project', 'A delivery room for consortium building, budgets, milestones and evidence.', 'Turn cross-border intent into credible commitments.'],
        ];

        $loops = [];
        foreach ($definitions as $key => [$name, $type, $description, $tagline]) {
            $loop = Loop::updateOrCreate(
                ['organization_id' => $organization->id, 'slug' => 'artscilab-'.$key],
                [
                    'name' => $name,
                    'description' => $description,
                    'tagline' => $tagline,
                    'type' => $type,
                    'status' => 'active',
                    'visibility' => $key === 'ethics' ? 'private' : 'public',
                    'access_mode' => $key === 'launchpals' ? Loop::ACCESS_OPEN : Loop::ACCESS_REQUEST,
                    'created_by' => $users['maya']->id,
                ],
            );
            app(LoopTypeRegistry::class)->applyPreset($loop);
            $registrar?->track('loop', $key, $loop);
            $loops[$key] = $loop;
        }

        $organization->update(['primary_loop_id' => $loops['launchpals']->id]);

        return $loops;
    }

    /** @param array<string, User> $users @param array<string, Loop> $loops */
    private function membersAndMessages(Organization $organization, array $users, array $loops, ?ScenarioPackEntityRegistrar $registrar): void
    {
        $memberKeys = array_keys($users);
        foreach ($loops as $loopIndex => $loop) {
            foreach ($memberKeys as $index => $userKey) {
                if ($userKey !== 'maya' && $loopIndex !== 'launchpals' && (($index + array_search($loopIndex, array_keys($loops), true)) % 4 === 0)) {
                    continue;
                }
                $member = LoopMember::updateOrCreate(
                    ['loop_id' => $loop->id, 'user_id' => $users[$userKey]->id],
                    [
                        'organization_id' => $organization->id,
                        'role' => $userKey === 'maya' || ($loopIndex === 'publishing' && $userKey === 'theo') ? 'owner' : 'member',
                        'status' => 'active',
                        'joined_at' => $this->base->addDays($index),
                    ],
                );
                $registrar?->track('loop_member', "{$loopIndex}:{$userKey}", $member);
            }
        }

        $themes = [
            'launchpals' => ['introductions', 'the next open studio', 'skills we can exchange', 'making newcomers feel welcome'],
            'emergence' => ['the tactile climate prototype', 'a two-week field test', 'who should be invited', 'what evidence would change our minds'],
            'publishing' => ['the field-notes issue', 'editing without erasing voice', 'image permissions', 'a public reading draft'],
            'ethics' => ['consent in generated material', 'our human review checkpoint', 'documenting model limits', 'a red-team session'],
            'europe' => ['the Horizon call', 'partner responsibilities', 'the travel budget', 'a credible delivery calendar'],
        ];
        $verbs = ['I can draft', 'Could we test', 'A useful constraint is', 'I volunteer to review', 'The open question remains', 'From the last session, I learned'];
        $sequence = 1;
        foreach ($loops as $key => $loop) {
            for ($i = 0; $i < 24; $i++) {
                $user = $users[$memberKeys[($i + array_search($key, array_keys($loops), true)) % count($memberKeys)]];
                $messageSequence = $sequence++;
                $body = sprintf('[ASL-%03d] %s %s. %s.', $messageSequence, $verbs[$i % count($verbs)], $themes[$key][$i % 4], $this->messageDetail($key, $i));
                $message = LoopMessage::updateOrCreate(
                    ['loop_id' => $loop->id, 'body' => $body],
                    [
                        'organization_id' => $organization->id,
                        'sender_id' => $user->id,
                        'type' => 'user',
                        'metadata' => ['scenario' => 'artscilab', 'sequence' => $messageSequence],
                        'created_at' => $this->base->addDays(20 + intdiv($i, 4))->addMinutes($i * 7),
                        'updated_at' => $this->base->addDays(20 + intdiv($i, 4))->addMinutes($i * 7),
                    ],
                );
                $registrar?->track('interaction', "msg-{$messageSequence}", $message);
            }
        }
    }

    private function messageDetail(string $loop, int $index): string
    {
        $details = [
            'launchpals' => ['Let us pair one concrete offer with each request', 'The invitation should explain what participation costs', 'A small host rotation will keep this sustainable'],
            'emergence' => ['The prototype should fit in one travelling case', 'We need observations from people outside our usual circle', 'A failed test is useful if its conditions are recorded'],
            'publishing' => ['Authors keep final approval over every edit', 'The web edition needs a printable companion', 'Captions should carry context rather than decoration'],
            'ethics' => ['No generated output is published without a named reviewer', 'Participants need a plain-language opt-out', 'We should record uncertainty beside every recommendation'],
            'europe' => ['Each work package needs one accountable lead', 'The budget should fund access needs from the start', 'The pilot calendar must include partner review time'],
        ];

        return $details[$loop][$index % 3];
    }

    /** @param array<string, User> $users @param array<int, Category> $categories @return array{0: array<int, ServiceRequest>, 1: array<int, Service>} */
    private function marketplace(Organization $organization, array $users, array $categories, ?ScenarioPackEntityRegistrar $registrar): array
    {
        $requestsData = [
            ['Review our Creative Europe concept note', 'We need a candid two-hour review of objectives, audiences and partner roles.', 45, 70],
            ['Prototype a low-bandwidth exhibition guide', 'Help us test an accessible mobile guide that works in weak connectivity.', 70, 110],
            ['Facilitate a consortium risk workshop', 'Design and host a remote session that surfaces delivery and ethics risks.', 55, 85],
            ['Edit field notes into a long-form essay', 'Shape six voices into one readable piece while preserving disagreement.', 60, 90],
            ['Advise on participant consent language', 'Review consent copy for recordings, generated media and later reuse.', 35, 60],
            ['Build a touring equipment checklist', 'Create a practical checklist for a compact hybrid installation.', 30, 50],
            ['Document an open studio session', 'Capture decisions, unresolved questions and useful quotes without a transcript dump.', 35, 55],
            ['Stress-test an AI recommendation flow', 'Run a human-centred red-team session and report concrete failure modes.', 65, 100],
            ['Translate a project budget into a narrative', 'Connect costs to activities and outcomes for non-finance reviewers.', 40, 65],
            ['Design an evaluation reflection kit', 'Create lightweight prompts partners can use after each public pilot.', 45, 75],
        ];
        $servicesData = [
            ['Participatory installation prototyping', 'Rapid concept-to-prototype support with clear material and access constraints.', 80],
            ['European proposal architecture', 'Work-package logic, partner roles, milestones and risk review.', 75],
            ['Developmental editing for collective texts', 'Structural editing that protects each contributor’s intent and voice.', 55],
            ['Hybrid event production clinic', 'A focused production review covering run-of-show, access and contingency.', 45],
            ['Responsible AI facilitation', 'Human-led workshops for consent, accountability and model-boundary decisions.', 70],
            ['Interaction design critique', 'A practical usability and accessibility critique for early prototypes.', 50],
            ['Sustainable fabrication planning', 'Materials, transport and assembly planning for touring installations.', 60],
            ['Sound design consultation', 'Narrative sound, recording plans and playback choices for public work.', 50],
            ['Learning and evaluation design', 'Qualitative evidence plans that remain useful to creative teams.', 55],
            ['Community hosting playbook', 'Welcoming formats, host rotation and follow-up practices for peer communities.', 40],
        ];

        $userValues = array_values($users);
        $requests = [];
        foreach ($requestsData as $i => [$title, $description, $min, $max]) {
            $request = ServiceRequest::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $organization->id, 'title' => '[ArtSciLab] '.$title],
                [
                    'user_id' => $userValues[$i % count($userValues)]->id,
                    'description' => $description,
                    'category_id' => $categories[$i % count($categories)]->id,
                    'delivery_mode' => $i % 3 === 0 ? 'both' : 'remote',
                    'budget_min' => $min,
                    'budget_max' => $max,
                    'deadline' => $this->base->addDays(90 + $i * 5)->toDateString(),
                    'status' => 'open',
                ],
            );
            $registrar?->track('marketplace_request', "request-{$i}", $request);
            $requests[] = $request;
        }

        $services = [];
        foreach ($servicesData as $i => [$title, $description, $cost]) {
            $service = Service::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $organization->id, 'title' => '[ArtSciLab] '.$title],
                [
                    'user_id' => $userValues[($i + 3) % count($userValues)]->id,
                    'description' => $description,
                    'category_id' => $categories[$i % count($categories)]->id,
                    'delivery_mode' => $i % 4 === 0 ? 'both' : 'remote',
                    'points_cost' => $cost,
                    'status' => 'active',
                ],
            );
            if ($service->trashed()) {
                $service->restore();
            }
            $registrar?->track('marketplace_service', "service-{$i}", $service);
            $services[] = $service;
        }

        return [$requests, $services];
    }

    /** @param array<string, User> $users @param array<int, ServiceRequest> $requests @param array<int, Service> $services */
    private function transactions(Organization $organization, array $users, array $requests, array $services, ?ScenarioPackEntityRegistrar $registrar): void
    {
        $statuses = ['completed', 'completed', 'completed', 'buyer_done', 'accepted', 'pending', 'refused', 'cancelled'];
        $userValues = array_values($users);

        foreach ($statuses as $i => $status) {
            $service = $services[$i];
            $request = $requests[$i];
            $seller = $service->user_id;
            $buyer = $request->user_id === $seller ? $userValues[($i + 5) % count($userValues)]->id : $request->user_id;
            $points = $service->points_cost;
            $transaction = Transaction::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $organization->id, 'service_id' => $service->id, 'request_id' => $request->id],
                [
                    'buyer_id' => $buyer,
                    'seller_id' => $seller,
                    'points_proposed' => $points,
                    'points_agreed' => in_array($status, ['pending', 'refused', 'cancelled'], true) ? null : $points,
                    'status' => $status === 'completed' ? 'buyer_done' : $status,
                    'buyer_confirmed_at' => in_array($status, ['buyer_done', 'completed'], true) ? $this->base->addDays(60 + $i) : null,
                    'seller_confirmed_at' => null,
                    'completed_at' => null,
                ],
            );
            $registrar?->track('transaction', "tx-{$i}", $transaction);

            if ($status === 'completed') {
                $this->completeTransaction($transaction, $request, $points, $i, $registrar);
            } elseif (in_array($status, ['accepted', 'buyer_done'], true)) {
                $request->update(['status' => 'in_progress']);
            }
        }
    }

    private function completeTransaction(Transaction $transaction, ServiceRequest $request, int $points, int $offset, ?ScenarioPackEntityRegistrar $registrar): void
    {
        DB::transaction(function () use ($transaction, $request, $points, $offset, $registrar) {
            $tx = Transaction::withoutGlobalScopes()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $existingEntries = PointLedger::where('transaction_id', $tx->id)->lockForUpdate()->get();

            if ($existingEntries->isNotEmpty()) {
                if ($existingEntries->count() !== 2
                    || ! $existingEntries->contains(fn (PointLedger $entry) => $entry->user_id === $tx->buyer_id && $entry->delta === -$points && $entry->reason === 'exchange_spent')
                    || ! $existingEntries->contains(fn (PointLedger $entry) => $entry->user_id === $tx->seller_id && $entry->delta === $points && $entry->reason === 'exchange_earned')) {
                    throw new LogicException("Incomplete ArtSciLab ledger for transaction {$tx->id}.");
                }
                $completedAt = $this->base->addDays(62 + $offset);
                $tx->update(['status' => 'completed', 'seller_confirmed_at' => $completedAt, 'completed_at' => $completedAt]);
                $request->update(['status' => 'closed']);

                $registrar?->track('point_ledger', "tx-{$offset}:buyer", $existingEntries->firstWhere('reason', 'exchange_spent'));
                $registrar?->track('point_ledger', "tx-{$offset}:seller", $existingEntries->firstWhere('reason', 'exchange_earned'));

                return;
            }

            $buyerEntry = PointLedger::create([
                'user_id' => $tx->buyer_id,
                'transaction_id' => $tx->id,
                'delta' => -$points,
                'organization_id' => $tx->organization_id,
                'reason' => 'exchange_spent',
            ]);
            $sellerEntry = PointLedger::create([
                'user_id' => $tx->seller_id,
                'transaction_id' => $tx->id,
                'delta' => $points,
                'organization_id' => $tx->organization_id,
                'reason' => 'exchange_earned',
            ]);
            $registrar?->track('point_ledger', "tx-{$offset}:buyer", $buyerEntry);
            $registrar?->track('point_ledger', "tx-{$offset}:seller", $sellerEntry);
            User::whereKey($tx->buyer_id)->update(['points_balance' => DB::raw('points_balance - '.$points)]);
            User::whereKey($tx->seller_id)->update(['points_balance' => DB::raw('points_balance + '.$points)]);
            $completedAt = $this->base->addDays(62 + $offset);
            $tx->update(['status' => 'completed', 'seller_confirmed_at' => $completedAt, 'completed_at' => $completedAt]);
            $request->update(['status' => 'closed']);
        });
    }

    /** @param array<string, User> $users @param array<int, Category> $categories @return array<int, BlogPost> */
    private function blogPosts(Organization $organization, array $users, array $categories, ?ScenarioPackEntityRegistrar $registrar): array
    {
        $titles = [
            'Why LaunchPals starts with useful offers', 'Field notes from a low-bandwidth exhibition',
            'A consent checklist for generative media', 'What an honest prototype review sounds like',
            'Editing collective essays without one institutional voice', 'The hidden work in a European consortium',
            'Three ways to host a better open studio', 'When evaluation becomes part of the artwork',
            'A touring installation that fits in one case', 'Human checkpoints for adaptive systems',
            'Budget narratives are design documents', 'Emergence log: what we decided not to build',
        ];
        $topicParagraphs = [
            'Mutual aid works when each member pairs a concrete offer with a real request. LaunchPals keeps human relationships, consent and reciprocity ahead of automation.',
            'The low-bandwidth exhibition guide supports collaborative publishing across unstable connections. Editors preserve contributor voice and always leave final publication approval with people.',
            'Generated media requires plain-language consent, a withdrawal route and a named human reviewer before publication. Participants can refuse derivatives without losing access.',
            'An honest prototype review records uncertainty, invites disagreement and identifies who has authority to stop deployment. Evidence matters more than confident recommendations.',
            'Collaborative essays need developmental editing that protects distinct voices. The newsletter and printable edition publish the process without flattening disagreement.',
            'A European project succeeds when every partner owns a work package, dissemination has accountable resources and the consortium budgets access needs from the start.',
            'A better open studio welcomes newcomers through mutual help: one useful offer, one concrete request and a rotating human host make LaunchPals sustainable.',
            'Evaluation belongs inside the artwork when participants can contest interpretations. Reflection prompts capture changed assumptions without turning experience into a score.',
            'A touring installation should fit one case, include a repair kit and document its material constraints. Local partners need enough information to adapt it responsibly.',
            'Adaptive systems need cognitive autonomy for participants and durable human oversight. A named reviewer owns the final decision, records model limits and can stop publication.',
            'A budget narrative connects partner responsibilities, travel, access support and dissemination to credible European project outcomes rather than hiding delivery work.',
            'The Emergence group rejected an automated recommendation layer because affected people could not meaningfully contest it. The draft records what evidence would reopen that decision.',
        ];
        $userValues = array_values($users);
        $posts = [];
        foreach ($titles as $i => $title) {
            $slug = 'artscilab-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $post = BlogPost::withTrashed()->updateOrCreate(
                ['slug' => $slug],
                [
                    'organization_id' => $organization->id,
                    'user_id' => $userValues[$i % count($userValues)]->id,
                    'category_id' => $categories[$i % count($categories)]->id,
                    'title' => $title,
                    'summary' => 'A practical ArtSciLab note grounded in current collaborative work.',
                    'content' => '<h2>'.$title.'</h2><p>'.$topicParagraphs[$i].'</p><p>At ArtSciLab we document the people involved, the constraint we accepted, the evidence still needed, one review date and a clear route to disagree.</p>',
                    'status' => $i === 11 ? 'draft' : 'published',
                    'published_at' => $i === 11 ? null : $this->base->addDays(35 + $i),
                    'audience' => $i % 5 === 0 ? 'organization' : 'public',
                    'listed_in_blog' => true,
                    'views_count' => 18 + $i * 7,
                    'show_toc' => true,
                ],
            );
            if ($post->trashed()) {
                $post->restore();
            }
            $registrar?->track('blog_post', $slug, $post);
            $posts[] = $post;
        }

        return $posts;
    }

    /** @param array<string, User> $users @param array<string, Loop> $loops @param array<int, BlogPost> $posts @return array<int, Dossier> */
    private function dossiers(Organization $organization, array $users, array $loops, array $posts, ?ScenarioPackEntityRegistrar $registrar): array
    {
        $dossiers = [];
        $definitions = [
            ['LaunchPals — Briefs', 'launchpals'],
            ['LaunchPals — Member Needs', 'launchpals'],
            ['LaunchPals — Pilot Results', 'launchpals'],
            ['Emergence — Session 01', 'emergence'],
            ['Emergence — Session 02', 'emergence'],
            ['Emergence — Session 03', 'emergence'],
            ['Emergence — References', 'emergence'],
            ['Experimental Publishing — Drafts', 'publishing'],
            ['Experimental Publishing — Sources', 'publishing'],
            ['Experimental Publishing — Published', 'publishing'],
            ['AI Ethics — Papers', 'ethics'],
            ['AI Ethics — Working Notes', 'ethics'],
            ['European Projects — Proposal', 'europe'],
            ['European Projects — Budget', 'europe'],
            ['European Projects — Partners', 'europe'],
        ];
        $userValues = array_values($users);
        $rootLoops = [];
        foreach ($definitions as $i => [$name, $loopKey]) {
            $isLoopRoot = ! isset($rootLoops[$loopKey]);
            $dossierKey = Str::slug($name);
            $dossier = Dossier::withTrashed()->updateOrCreate(
                ['organization_id' => $organization->id, 'name' => $name],
                [
                    'owner_id' => $isLoopRoot ? null : $userValues[$i % count($userValues)]->id,
                    'loop_id' => $isLoopRoot ? $loops[$loopKey]->id : null,
                    'shared_with_loop_id' => $isLoopRoot ? null : $loops[$loopKey]->id,
                    'visibility' => Dossier::VISIBILITY_LOOP,
                ],
            );
            $rootLoops[$loopKey] = true;
            if ($dossier->trashed()) {
                $dossier->restore();
            }
            $registrar?->track('folder', $dossierKey, $dossier);
            if (! $isLoopRoot && $dossier->owner_id !== $users['maya']->id) {
                $member = DossierMember::updateOrCreate(
                    ['dossier_id' => $dossier->id, 'user_id' => $users['maya']->id],
                    [
                        'organization_id' => $organization->id,
                        'role' => DossierMember::ROLE_READER,
                        'added_by' => $dossier->owner_id,
                    ],
                );
                $registrar?->track('folder_member', "{$dossierKey}:maya", $member);
            }
            $dossiers[] = $dossier;
        }

        $dossierIndexesByPost = [0, 7, 9, 3, 8, 12, 1, 4, 5, 10, 13, 11];

        foreach ($posts as $i => $post) {
            $link = DossierBlogPost::updateOrCreate(
                ['blog_post_id' => $post->id],
                ['organization_id' => $organization->id, 'dossier_id' => $dossiers[$dossierIndexesByPost[$i]]->id, 'added_by' => $post->user_id, 'position' => $i + 1],
            );
            $registrar?->track('folder_article_link', "article-{$i}", $link);
        }

        return $dossiers;
    }

    /** @param array<string, User> $users @param array<int, Dossier> $dossiers */
    private function dossierFiles(Organization $organization, array $users, array $dossiers, ?ScenarioPackEntityRegistrar $registrar): void
    {
        $files = [
            ['launchpals-charter.md', "# LaunchPals charter\n\nOffer concrete help, name constraints, and leave decisions with people.\n"],
            ['open-studio-runbook.md', "# Open studio runbook\n\nWelcome, context, two demos, peer questions, commitments, close.\n"],
            ['emergence-test-plan.md', "# Emergence test plan\n\nQuestion: can the tactile climate prototype travel and invite useful discussion?\n"],
            ['prototype-observations.txt', "Observed: visitors asked who selected the data and how uncertainty was shown.\n"],
            ['editorial-principles.md', "# Editorial principles\n\nPreserve disagreement. Ask before restructuring a contributor's argument.\n"],
            ['field-notes-outline.md', "# Field notes issue\n\nArrival, situated observation, friction, changed assumption, next question.\n"],
            ['image-permissions.txt', "Record creator, subject consent, territory, channel, duration, and withdrawal route.\n"],
            ['ai-review-checkpoint.md', "# Human review checkpoint\n\nName reviewer, intended use, known limits, affected people, and final decision.\n"],
            ['consent-language.txt', "You may participate without allowing recordings or generated derivatives of your contribution.\n"],
            ['red-team-prompts.md', "# Red-team prompts\n\nWho is absent? What confident claim lacks evidence? Who can contest the output?\n"],
            ['europe-work-packages.md', "# Work packages\n\nWP1 coordination; WP2 co-design; WP3 pilots; WP4 learning; WP5 communication.\n"],
            ['partner-roles.txt', "ArtSciLab: co-design. City lab: pilots. University: evaluation. Museum: public programme.\n"],
            ['budget-assumptions.md', "# Budget assumptions\n\nAccess support is direct delivery cost. Partner review time is not invisible labour.\n"],
            ['delivery-calendar.md', "# Delivery calendar\n\nMonths 1-3 align; 4-7 prototype; 8-13 pilot; 14-16 reflect; 17-18 publish.\n"],
            ['fabrication-checklist.txt', "Power, weight, packed dimensions, repair kit, material passport, setup time, accessibility.\n"],
            ['evaluation-prompts.md', "# Reflection prompts\n\nWhat surprised you? What felt unclear? What should change before another public test?\n"],
            ['access-travel-plan.txt', "Book flexible travel, caption remote sessions, fund assistants, and publish quiet-space details.\n"],
            ['unresolved-questions.md', "# Unresolved questions\n\nHow do we credit maintenance? When should a prototype stop? What evidence is enough?\n"],
        ];
        $userValues = array_values($users);
        foreach ($files as $i => [$name, $contents]) {
            $path = 'artscilab-demo/'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT).'-'.$name;
            // TASK-1245 : un fichier deja present a ce chemin qui n'est pas
            // celui de ce chargement (rejeu) est un fichier preexistant ->
            // refus explicite, jamais ecrase (le remover ne le supprimerait
            // pas non plus). Sans registrar (`db:seed` direct, hors moteur
            // de pack) : comportement historique conserve.
            $registrar?->assertStoragePathAvailable('folder_file', Str::slug($name), 'dossier_files', $path);
            if (! Storage::disk('dossier_files')->put($path, $contents)) {
                throw new RuntimeException("Unable to write ArtSciLab dossier file {$path}.");
            }
            $dossier = $dossiers[$i % count($dossiers)];
            // L'uploader ne doit jamais etre le proprietaire du Dossier :
            // PostgreSQL echoue sur dossier_files_dossier_id_foreign quand un
            // meme utilisateur est a la fois owner_id (cascade Dossier) et
            // uploaded_by (set null direct) de la meme ligne dossier_files,
            // lors de la suppression de cet utilisateur (cascade a deux
            // niveaux + set null direct sur la meme ligne). SQLite ne
            // detecte pas cette contrainte. Trouve en validant TASK-1242 sur
            // PostgreSQL — signale a Cyril comme limite pre-existante du
            // schema, hors perimetre de cette tache.
            $uploaderIndex = $i % count($userValues);
            if ($dossier->owner_id !== null && $userValues[$uploaderIndex]->id === $dossier->owner_id) {
                $uploaderIndex = ($uploaderIndex + 1) % count($userValues);
            }
            $file = DossierFile::withTrashed()->updateOrCreate(
                ['organization_id' => $organization->id, 'path' => $path],
                [
                    'dossier_id' => $dossier->id,
                    'uploaded_by' => $userValues[$uploaderIndex]->id,
                    'disk' => 'dossier_files',
                    'original_name' => $name,
                    'display_name' => ucwords(str_replace(['-', '.md', '.txt'], [' ', '', ''], $name)),
                    'mime_type' => str_ends_with($name, '.md') ? 'text/markdown' : 'text/plain',
                    'size_bytes' => strlen($contents),
                    'checksum_sha256' => hash('sha256', $contents),
                    'source' => 'upload',
                ],
            );
            if ($file->trashed()) {
                $file->restore();
            }
            $registrar?->track('folder_file', Str::slug($name), $file);
        }
    }

    /** @param array<string, User> $users */
    private function memberProfiles(Organization $organization, array $users, ?ScenarioPackEntityRegistrar $registrar): void
    {
        $profiles = [
            'maya' => ['artistic direction and participatory installations', ['creative direction', 'co-design', 'curation']],
            'jonas' => ['creative technology prototypes and technical reality checks', ['prototyping', 'creative coding', 'documentation']],
            'sofia' => ['European partnerships, work packages and proposal structure', ['consortium building', 'grants', 'facilitation']],
            'theo' => ['developmental editing and collaborative publishing', ['editing', 'publishing', 'workshops']],
            'amina' => ['inclusive community formats and hybrid event production', ['community hosting', 'access', 'production']],
            'ines' => ['responsible AI practice, consent and accountability', ['AI ethics', 'research', 'red teaming']],
            'elena' => ['delivery planning, budgets and cross-partner coordination', ['project management', 'budgeting', 'risk']],
        ];

        foreach ($profiles as $key => [$scope, $skills]) {
            $profile = MemberAiProfile::updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $users[$key]->id],
                [
                    'status' => MemberAiProfile::STATUS_PUBLISHED,
                    'locale' => 'en',
                    'member_profile_summary' => $users[$key]->first_name.' helps ArtSciLab collaborators with '.$scope.'.',
                    'service_scope' => ucfirst($scope).'.',
                    'experience_context' => 'Declared from the member’s current ArtSciLab role and reviewed by the member.',
                    'preferred_contact_action' => 'send_message',
                    'tone' => 'clear',
                    'generated_summary' => null,
                    'target_audience' => ['ArtSciLab members', 'European project partners'],
                    'problems_helped' => ['turning an uncertain idea into a practical next step'],
                    'skills' => $skills,
                    'help_types' => ['review', 'working session', 'practical guidance'],
                    'boundaries' => ['No legal advice', 'No automated decisions on behalf of a project'],
                    'good_request_examples' => ['Could you review this draft and name the two largest risks?'],
                    'bad_request_examples' => ['Can you guarantee our proposal will be funded?'],
                    'metadata' => ['source' => 'human_declaration', 'scenario' => 'artscilab'],
                    'validated_at' => $this->base->addDays(40),
                    'published_at' => $this->base->addDays(41),
                    'generated_at' => null,
                    'last_saved_at' => $this->base->addDays(41),
                ],
            );
            $registrar?->track('ai_profile', $key, $profile);
        }
    }

    /** @param array<string, User> $users @param array<string, Loop> $loops */
    private function events(Organization $organization, array $users, array $loops, ?ScenarioPackEntityRegistrar $registrar): void
    {
        $definitions = [
            ['LaunchPals welcome table', 'launchpals', 'online'], ['Open studio: portable climate prototype', 'launchpals', 'hybrid'],
            ['Emergence critique circle', 'emergence', 'in_person'], ['Prototype field test', 'emergence', 'in_person'],
            ['Collective editing room', 'publishing', 'online'], ['Field notes public reading', 'publishing', 'hybrid'],
            ['Consent language clinic', 'ethics', 'online'], ['Responsible AI red-team', 'ethics', 'hybrid'],
            ['Consortium partner assembly', 'europe', 'online'], ['European pilots planning day', 'europe', 'in_person'],
        ];
        $userValues = array_values($users);
        foreach ($definitions as $i => [$title, $loopKey, $format]) {
            $event = LoopEvent::updateOrCreate(
                ['organization_id' => $organization->id, 'loop_id' => $loops[$loopKey]->id, 'title' => '[ArtSciLab] '.$title],
                [
                    'created_by' => $userValues[$i % count($userValues)]->id,
                    'description' => 'A working session with a clear outcome, named host and documented follow-up.',
                    'format' => $format,
                    'starts_at' => $this->base->addDays(75 + $i * 8)->setTime(13, 0),
                    'ends_at' => $this->base->addDays(75 + $i * 8)->setTime(15, 0),
                    'timezone' => 'Europe/Paris',
                    'location' => $format === 'online' ? null : 'ArtSciLab project space',
                    'meeting_url' => $format === 'in_person' ? null : 'https://meet.example.test/artscilab-'.$i,
                    'visibility' => $loopKey === 'ethics' ? 'loop' : 'organization',
                    'status' => 'scheduled',
                ],
            );
            $registrar?->track('event', "event-{$i}", $event);
            foreach (array_slice($userValues, 0, 3 + ($i % 3)) as $j => $user) {
                $response = LoopEventResponse::updateOrCreate(
                    ['event_id' => $event->id, 'user_id' => $user->id],
                    ['organization_id' => $organization->id, 'response' => $j === 3 ? 'maybe' : 'going'],
                );
                $registrar?->track('event_response', "event-{$i}:{$j}", $response);
            }
        }
    }

    /** @param array<string, User> $users @param array<string, Loop> $loops */
    private function polls(Organization $organization, array $users, array $loops, ?ScenarioPackEntityRegistrar $registrar): void
    {
        $definitions = [
            ['launchpals', 'Which skill-swap format should we test next?', ['Two-person clinic', 'Open help desk', 'Small peer circle']],
            ['emergence', 'Where should the portable prototype be tested first?', ['Library foyer', 'Neighbourhood festival', 'Partner museum']],
            ['ethics', 'Which review checkpoint needs documentation first?', ['Consent', 'Source evidence', 'Human override']],
            ['europe', 'Which shared template saves partners the most time?', ['Risk log', 'Budget narrative', 'Pilot report']],
        ];
        $userValues = array_values($users);
        foreach ($definitions as $i => [$loopKey, $question, $labels]) {
            $poll = LoopPoll::updateOrCreate(
                ['organization_id' => $organization->id, 'loop_id' => $loops[$loopKey]->id, 'question' => $question],
                [
                    'created_by' => $users['maya']->id,
                    'description' => 'A named, practical preference poll for the next working cycle.',
                    'selection_type' => $i === 0 ? 'multiple' : 'single',
                    'status' => $i === 1 ? 'closed' : 'open',
                    'closed_at' => $i === 1 ? $this->base->addDays(70) : null,
                    'closed_by' => $i === 1 ? $users['maya']->id : null,
                ],
            );
            $registrar?->track('poll', "poll-{$i}", $poll);
            // loop_poll_options ne porte pas organization_id (atteignable
            // uniquement via son Sondage, deja track "poll" ci-dessus) : pas
            // trackable par le registrar, nettoye par cascade sur poll_id.
            $options = [];
            foreach ($labels as $position => $label) {
                $options[] = LoopPollOption::updateOrCreate(
                    ['poll_id' => $poll->id, 'label' => $label],
                    ['position' => $position],
                );
            }
            foreach (array_slice($userValues, 0, 6) as $voterIndex => $user) {
                $vote = LoopPollVote::updateOrCreate(
                    ['poll_id' => $poll->id, 'user_id' => $user->id],
                    ['organization_id' => $organization->id],
                );
                $registrar?->track('poll_vote', "poll-{$i}:{$voterIndex}", $vote);
                $selected = [$options[($voterIndex + $i) % count($options)]];
                if ($poll->selection_type === 'multiple' && $voterIndex % 2 === 0) {
                    $selected[] = $options[($voterIndex + 1) % count($options)];
                }
                // loop_poll_vote_options ne porte pas organization_id non plus
                // (atteignable via son vote, deja track "poll_vote" ci-dessus) :
                // nettoye par cascade sur vote_id/option_id.
                foreach ($selected as $option) {
                    LoopPollVoteOption::firstOrCreate(['vote_id' => $vote->id, 'option_id' => $option->id]);
                }
            }
        }
    }

    /** @param array<string, User> $users @param array<string, Loop> $loops */
    private function decisionsAndRoadmap(Organization $organization, array $users, array $loops, ?ScenarioPackEntityRegistrar $registrar): void
    {
        $decisionData = [
            ['emergence', 'Keep the first prototype portable', 'One case makes field tests affordable and repeatable.'],
            ['emergence', 'Test outside the existing arts network', 'The concept needs critique from people without project context.'],
            ['ethics', 'Require a named human reviewer', 'Generated material cannot publish itself.'],
            ['ethics', 'Offer participation without derivative reuse', 'Access to the activity must not depend on broad media consent.'],
            ['europe', 'Fund access as delivery work', 'Access support belongs in work packages and budgets from the start.'],
            ['europe', 'Use one accountable lead per work package', 'Shared delivery still needs a clear first point of responsibility.'],
        ];
        $decisions = [];
        foreach ($decisionData as $i => [$loopKey, $title, $rationale]) {
            $decision = LoopDecision::updateOrCreate(
                ['organization_id' => $organization->id, 'loop_id' => $loops[$loopKey]->id, 'title' => $title],
                [
                    'author_id' => array_values($users)[$i % count($users)]->id,
                    'rationale' => $rationale,
                    'decided_on' => $this->base->addDays(50 + $i)->toDateString(),
                    'loop_message_id' => null,
                ],
            );
            $registrar?->track('decision', "decision-{$i}", $decision);
            $decisions[] = $decision;
        }

        $items = [
            ['emergence', 'Measure the packed prototype', 'done'], ['emergence', 'Recruit three outside testers', 'in_progress'], ['emergence', 'Write the field observation guide', 'todo'],
            ['publishing', 'Confirm image permissions', 'in_progress'], ['publishing', 'Edit the field-notes issue', 'todo'], ['publishing', 'Rehearse the public reading', 'todo'],
            ['ethics', 'Publish the human review checklist', 'done'], ['ethics', 'Run consent language clinic', 'in_progress'], ['ethics', 'Document red-team findings', 'todo'],
            ['europe', 'Confirm work-package leads', 'done'], ['europe', 'Draft partner budget narratives', 'in_progress'], ['europe', 'Schedule pilot review gates', 'todo'],
            ['launchpals', 'Host the next skill-swap clinic', 'in_progress'], ['launchpals', 'Pair open requests with offers', 'todo'], ['launchpals', 'Review newcomer welcome copy', 'done'],
        ];
        foreach ($items as $i => [$loopKey, $title, $status]) {
            $decision = collect($decisions)->first(fn (LoopDecision $item) => $item->loop_id === $loops[$loopKey]->id);
            $item = LoopRoadmapItem::withTrashed()->updateOrCreate(
                ['organization_id' => $organization->id, 'loop_id' => $loops[$loopKey]->id, 'title' => $title],
                [
                    'loop_decision_id' => $decision?->id,
                    'description' => '<p>A concrete ArtSciLab action with a named owner and visible review point.</p>',
                    'status' => $status,
                    'position' => $i,
                    'due_at' => $this->base->addDays(85 + $i * 3),
                    'created_by' => $users['maya']->id,
                    'completed_at' => $status === 'done' ? $this->base->addDays(72 + $i) : null,
                ],
            );
            if ($item->trashed()) {
                $item->restore();
            }
            $registrar?->track('roadmap_item', "roadmap-{$i}", $item);
            $item->assignees()->syncWithoutDetaching([array_values($users)[$i % count($users)]->id]);
        }
    }
}
