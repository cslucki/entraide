<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TASK-1865 — apercu d'une Boucle (`loops.preview` et `organization.loops.preview`),
 * fragment partage par le tiroir de la grille et les volets de la liste.
 *
 * L'enjeu est la NON-DIVULGATION : messages et membres nominatifs pour qui
 * `viewWorkspace` l'autorise, et pour personne d'autre — ni non-membre, ni
 * ancien membre, ni autre Organization, ni SuperAdmin hors tenant, ni Boucle
 * archivee —, par l'une comme par l'autre route.
 */
class TASK1865LoopPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        app()->instance('current_organization', $this->org);
        $this->service = new LoopService;
    }

    private function user(?Organization $org = null, array $attributes = []): User
    {
        return User::factory()->create(['organization_id' => ($org ?? $this->org)->id] + $attributes);
    }

    private function preview(User $user, Loop $loop, ?Organization $org = null): TestResponse
    {
        return $this->actingAs($user)->get(route('organization.loops.preview', [
            'organization' => ($org ?? $this->org)->slug,
            'loop' => $loop->id,
        ]));
    }

    private function shortPreview(User $user, Loop $loop): TestResponse
    {
        return $this->actingAs($user)->get(route('loops.preview', $loop));
    }

    private function messageAt(Loop $loop, ?User $sender, string $body, Carbon $when, array $extra = []): LoopMessage
    {
        $message = new LoopMessage(array_merge([
            'loop_id' => $loop->id,
            'organization_id' => $loop->organization_id,
            'sender_id' => $sender?->id,
            'body' => $body,
            'type' => $sender ? 'user' : 'ai',
        ], $extra));
        $message->created_at = $when;
        $message->save();

        return $message;
    }

    /** Aucune trace du contenu reserve dans une reponse, quel que soit son statut. */
    private function assertNothingReserved(TestResponse $response, array $secrets): void
    {
        $content = (string) $response->getContent();

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }

        $this->assertStringNotContainsString('data-loop-preview-message=', $content);
        $this->assertStringNotContainsString('data-loop-preview-member=', $content);
    }

    public function test_a_member_reads_the_five_latest_human_messages_and_twelve_members_at_most(): void
    {
        $moi = $this->user();
        $loop = $this->service->createLoop($moi, 'Boucle riche');
        $autres = collect(range(1, 13))->map(fn () => $this->user());
        $autres->each(fn (User $u) => $this->service->addMemberByUserId($loop, $u->id));

        foreach (range(1, 6) as $i) {
            $this->messageAt($loop, $autres[$i], "Message humain {$i}", now()->subMinutes(20 - $i));
        }
        $this->messageAt($loop, null, 'Reponse IA recente', now()->subMinutes(2));
        $this->messageAt($loop, $autres[0], 'Message supprime recent', now()->subMinute(), ['deleted_at' => now()]);

        $response = $this->preview($moi, $loop)->assertOk();
        $html = $response->getContent();

        // Fragment sans layout, jamais mis en cache.
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('data-loop-preview-state="reader"', $html);

        foreach (range(2, 6) as $i) {
            $this->assertStringContainsString("Message humain {$i}", $html);
        }
        $this->assertStringNotContainsString('Message humain 1<', $html);
        $this->assertSame(5, substr_count($html, 'data-loop-preview-message='));
        $this->assertStringNotContainsString('Reponse IA recente', $html);
        $this->assertStringNotContainsString('Message supprime recent', $html);

        // 14 membres : 12 montres, le proprietaire d'abord, puis « Et 2 autres membres ».
        $this->assertSame(12, substr_count($html, 'data-loop-preview-member='));
        $this->assertLessThan(
            strpos($html, 'data-loop-preview-member="'.$autres[0]->id.'"'),
            strpos($html, 'data-loop-preview-member="'.$moi->id.'"'),
        );
        $this->assertStringContainsString('data-loop-preview-members-more', $html);
        $this->assertStringContainsString(e(trans_choice('loops.preview_members_more', 2, ['count' => 2])), $html);
        $this->assertStringContainsString(e(trans_choice('loops.presentation_members_count', 14, ['count' => 14])), $html);
    }

    public function test_a_non_member_of_the_same_organization_gets_the_discovery_state_only(): void
    {
        $moi = $this->user();
        $proprio = $this->user();
        $membre = $this->user(null, ['name' => 'Membre Secret']);
        $loop = $this->service->createLoop($proprio, 'Boucle des autres');
        $this->service->addMemberByUserId($loop, $membre->id);
        $this->messageAt($loop, $membre, 'Conversation reservee', now());

        $response = $this->preview($moi, $loop)->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-loop-preview-state="discover"', $html);
        $this->assertStringContainsString('data-loop-preview-locked', $html);
        $this->assertStringContainsString('Boucle des autres', $html);
        // Action d'adhesion existante (acces sur demande par defaut).
        $this->assertStringContainsString(e(__('loops.cta_request')), $html);
        $this->assertNothingReserved($response, ['Conversation reservee', e($membre->publicDisplayName())]);
    }

    public function test_a_pending_join_request_shows_the_existing_pending_state(): void
    {
        $moi = $this->user();
        $loop = $this->service->createLoop($this->user(), 'Boucle sur demande');
        $this->service->requestToJoin($loop, $moi);

        $html = $this->preview($moi, $loop)->assertOk()->getContent();

        $this->assertStringContainsString(e(__('loops.cta_pending')), $html);
        $this->assertStringContainsString('data-loop-preview-state="discover"', $html);
    }

    public function test_a_revoked_membership_no_longer_reads_anything(): void
    {
        $proprio = $this->user();
        $ancien = $this->user();
        $loop = $this->service->createLoop($proprio, 'Boucle A');
        $this->service->addMemberByUserId($loop, $ancien->id);
        $this->messageAt($loop, $proprio, 'Message apres revocation', now());

        $this->service->removeMember(LoopMember::where('loop_id', $loop->id)->where('user_id', $ancien->id)->firstOrFail());

        $response = $this->preview($ancien, $loop)->assertOk();
        $this->assertStringContainsString('data-loop-preview-state="discover"', $response->getContent());
        $this->assertNothingReserved($response, ['Message apres revocation']);
    }

    public function test_the_direct_preview_url_stops_reading_once_the_right_is_lost(): void
    {
        $proprio = $this->user();
        $membre = $this->user();
        $loop = $this->service->createLoop($proprio, 'Boucle A');
        $this->service->addMemberByUserId($loop, $membre->id);
        $this->messageAt($loop, $proprio, 'Message confidentiel', now());

        // Avant : il lit.
        $this->assertStringContainsString('Message confidentiel', $this->preview($membre, $loop)->assertOk()->getContent());

        // Compte desactive : la meme URL ne rend plus rien de reserve.
        $membre->forceFill(['banned_at' => now()])->save();
        $this->assertNothingReserved($this->preview($membre->fresh(), $loop), ['Message confidentiel']);
    }

    public function test_an_archived_loop_is_not_found_even_for_its_owner(): void
    {
        $proprio = $this->user();
        $loop = $this->service->createLoop($proprio, 'Boucle archivee');
        $this->messageAt($loop, $proprio, 'Message archive', now());
        $this->service->archiveLoop($loop);

        $this->assertNothingReserved($this->preview($proprio, $loop->fresh())->assertNotFound(), ['Message archive']);
    }

    public function test_a_former_member_who_changed_organization_sees_nothing_and_is_not_listed(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $proprio = $this->user();
        $parti = $this->user(null, ['name' => 'Personne Partie']);
        $loop = $this->service->createLoop($proprio, 'Boucle A');
        $this->service->addMemberByUserId($loop, $parti->id);
        $this->messageAt($loop, $proprio, 'Message de la Boucle', now());

        // Change d'Organization ; sa ligne `loop_members` reste `active` (vestige
        // T1802). Le modele interdit ce changement depuis TASK-1726 : l'etat est
        // pose en SQL, tel que les comptes anterieurs l'ont laisse en base.
        DB::table('users')->where('id', $parti->id)->update(['organization_id' => $autreOrg->id]);
        $parti = $parti->fresh();

        // Par son ancienne Organization comme par la nouvelle, et par la route courte : rien.
        $this->assertNothingReserved($this->preview($parti, $loop)->assertNotFound(), ['Message de la Boucle']);
        $this->assertNothingReserved($this->preview($parti, $loop, $autreOrg)->assertNotFound(), ['Message de la Boucle']);
        $this->assertNothingReserved($this->shortPreview($parti, $loop), ['Message de la Boucle']);
        $this->assertNotSame(200, $this->shortPreview($parti, $loop)->getStatusCode());

        // Et le proprietaire ne le voit plus parmi les membres, ni dans le compte.
        $html = $this->preview($proprio, $loop)->assertOk()->getContent();
        $this->assertStringNotContainsString('data-loop-preview-member="'.$parti->id.'"', $html);
        $this->assertStringContainsString(e(trans_choice('loops.presentation_members_count', 1, ['count' => 1])), $html);
    }

    public function test_a_loop_of_another_organization_is_never_disclosed_through_either_route(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $etranger = $this->user($autreOrg);
        $loopEtrangere = $this->service->createLoopForOrg($etranger, $autreOrg->id, 'Boucle etrangere');
        $this->messageAt($loopEtrangere, $etranger, 'Secret etranger', now());

        $moi = $this->user();
        $secrets = ['Secret etranger', 'Boucle etrangere'];

        $this->assertNothingReserved($this->preview($moi, $loopEtrangere)->assertNotFound(), $secrets);
        $short = $this->shortPreview($moi, $loopEtrangere);
        $this->assertSame(404, $short->getStatusCode());
        $this->assertNothingReserved($short, $secrets);
    }

    public function test_a_superadmin_outside_the_tenant_gets_no_preview(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $superAdmin = $this->user($autreOrg, ['is_admin' => true]);
        $loop = $this->service->createLoop($this->user(), 'Boucle A');
        $this->messageAt($loop, null, 'ignore', now());
        $this->messageAt($loop, $loop->owner->user, 'Message interne', now());

        $this->assertNothingReserved($this->preview($superAdmin, $loop)->assertNotFound(), ['Message interne', 'Boucle A']);
        $short = $this->shortPreview($superAdmin, $loop);
        $this->assertNotSame(200, $short->getStatusCode());
        $this->assertNothingReserved($short, ['Message interne']);
    }

    public function test_message_bodies_are_escaped(): void
    {
        $moi = $this->user();
        $loop = $this->service->createLoop($moi, 'Boucle A');
        $this->messageAt($loop, $moi, '<script>alert("x")</script><b>gras</b>', now());

        $html = $this->preview($moi, $loop)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert("x")</script>', $html);
        $this->assertStringNotContainsString('<b>gras</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_query_count_does_not_grow_with_messages_or_members(): void
    {
        $count = function (int $members, int $messages): int {
            $moi = $this->user();
            $loop = $this->service->createLoop($moi, "Boucle {$members}-{$messages}");
            $autres = collect(range(1, $members))->map(fn () => $this->user());
            $autres->each(fn (User $u) => $this->service->addMemberByUserId($loop, $u->id));
            foreach (range(1, $messages) as $i) {
                $this->messageAt($loop, $autres[$i % $members], "M{$i}", now()->subMinutes($messages - $i));
            }

            // Chauffe non mesuree : la 1re requete HTTP paie des chargements memorises.
            $this->preview($moi, $loop)->assertOk();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->preview($moi, $loop)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($count(2, 3), $count(15, 20));
    }
}
