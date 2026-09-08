<?php

namespace Tests\Feature;

use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\CrmContact;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\Crm\CrmAttributionService;
use App\Services\Crm\CrmContactService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopInterestService;
use App\Services\Workshops\WorkshopRegistrationService;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1459 — Attribution CRM (Mini-CRM V2 §11, Growth V3 §5) : la fiche
 * Contact lit Journey → lien court → visiteur → compte → atelier depuis les
 * autorites existantes, FIRST TOUCH (audit F7), bornee a l'Organization ;
 * `converted` du listener Verified = contrat Journey + User (audit F5).
 */
class TASK1459CrmAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private AcquisitionJourney $journey;

    private Workshop $wa;

    private WorkshopSession $s1;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');
        $this->a = Organization::factory()->create(['slug' => 'org-a-1459', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->b = Organization::factory()->create(['slug' => 'org-b-1459', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);

        $journeys = app(AcquisitionJourneyService::class);
        $this->journey = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_ACCOUNT, 'campaign' => 'sept-2026'], $this->adminA);
        $journeys->publish($this->journey, $this->adminA);
        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $this->wa = $workshops->create($this->a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($this->wa, $this->adminA);
        $this->s1 = $sessions->create($this->wa, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        $sessions->publish($this->s1, $this->adminA);
    }

    /** Un visiteur acquis a `$firstSeenAt` (la veille par defaut), rattache au compte a `$claimedAt` — les deux dates sont independantes (MASTER #52). */
    private function claimedVisitor(Organization $organization, User $user, array $attribution, string $claimedAt, ?string $firstSeenAt = null): GuestVisitor
    {
        $visitor = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $organization, $attribution);
        $visitor->forceFill(['claimed_user_id' => $user->id, 'claimed_at' => Carbon::parse($claimedAt), 'first_seen_at' => Carbon::parse($firstSeenAt ?? $claimedAt)->subDay()])->save();

        return $visitor;
    }

    public function test_the_contact_page_reads_the_first_touch_attribution_from_the_journey_shortcut_visitor_and_workshop_authorities(): void
    {
        $member = User::factory()->create(['organization_id' => $this->a->id, 'email' => 'attr@example.test', 'email_verified_at' => now()]);
        // First touch = premier ACQUIS (first_seen 31/08), rattache le 05/09 ; le second navigateur, acquis le 03/09, est rattache AVANT (02/09) :
        // ni « dernier rattache » ni « premier rattache » — le premier acquis (MASTER #52).
        $first = $this->claimedVisitor($this->a, $member, ['acquisition_journey_id' => $this->journey->id, 'utm_campaign' => 'sept-2026', 'utm_source' => 'newsletter', 'shortcut' => 'demo'], '2026-09-05 09:00:00', '2026-09-01 09:00:00');
        $late = $this->claimedVisitor($this->a, $member, ['utm_campaign' => 'tardif', 'shortcut' => 'late'], '2026-09-02 09:00:00', '2026-09-04 09:00:00');
        // Un troisieme navigateur, acquis ET rattache en dernier : ni « dernier rattache » (lui) ni « premier rattache » ($late) ne sont la verite.
        $last = $this->claimedVisitor($this->a, $member, ['utm_campaign' => 'dernier', 'shortcut' => 'last'], '2026-09-07 09:00:00', '2026-09-06 09:00:00');
        $registration = app(WorkshopRegistrationService::class)->register($this->s1, $member);
        $this->assertSame($first->id, $registration->guest_visitor_id, 'F7 : sans Interet, l\'inscription porte le PREMIER visiteur acquis');
        // Priorite 1 : le visiteur qui PORTE L'INTERET de la session gagne, meme s'il n'est pas le first touch.
        $s2 = app(WorkshopSessionService::class)->create($this->wa, ['starts_at' => '2026-10-08 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        app(WorkshopSessionService::class)->publish($s2, $this->adminA);
        app(WorkshopInterestService::class)->select($s2, $late);
        $this->assertSame($late->id, app(WorkshopRegistrationService::class)->register($s2, $member)->guest_visitor_id, 'F7 : l\'Interet exact de la session prime sur le first touch');
        $contact = app(CrmContactService::class)->findOrCreate($this->a, ['email' => 'attr@example.test', 'source' => CrmContact::SOURCE_WORKSHOP, 'source_ref' => $this->s1->id], $this->adminA);
        app(CrmContactService::class)->linkToUser($contact, $member);

        $attribution = app(CrmAttributionService::class)->for($contact->fresh());
        $this->assertSame(['Rentrée v1', 'sept-2026', 'demo', 'newsletter'], [$attribution['journey'], $attribution['campaign'], $attribution['shortcut'], $attribution['utm_source']], 'first touch, jamais le dernier navigateur');
        $this->assertSame('2026-09-05 09:00:00', $attribution['claimed_at']->format('Y-m-d H:i:s'));
        $this->assertSame([['title' => 'Découvrir l\'IA', 'starts_at' => '01/10/2026 18:30', 'status' => 'registered'], ['title' => 'Découvrir l\'IA', 'starts_at' => '08/10/2026 18:30', 'status' => 'registered']], $attribution['workshops']);
        $this->assertSame('Découvrir l\'IA · 01/10/2026 18:30', $attribution['source_label']);

        $page = $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts.show', [$this->a, $contact]))->assertOk()->getContent();
        $this->assertStringContainsString('data-crm-attribution>', $page);
        $this->assertStringContainsString('data-crm-attribution-journey>Rentrée v1<', $page);
        $this->assertStringContainsString('data-crm-attribution-shortcut>/s/demo<', $page);
        $this->assertStringContainsString('data-crm-attribution-campaign>sept-2026<', $page);
        $this->assertStringContainsString('Découvrir l&#039;IA · 01/10/2026 18:30', $page);
        $this->assertStringNotContainsString('data-crm-attribution-shortcut>/s/late<', $page, 'le bloc Provenance = first touch');
        $this->assertStringContainsString('tardif /s/late', $page, 'la timeline de la 2e inscription garde SA causalite (Interet exact)');
        $this->assertStringNotContainsString($first->visitor_key_hash, $page, 'jamais la cle du visiteur');
        $this->assertStringNotContainsString($late->visitor_key_hash, $page);
        $this->assertStringNotContainsString('/s/last', $page, 'le dernier navigateur n\'apparait nulle part');
    }

    public function test_attribution_is_tenant_bound_and_a_contact_without_member_only_shows_its_source(): void
    {
        // Le meme email vit dans B avec un visiteur et un atelier : jamais montres dans A.
        $memberB = User::factory()->create(['organization_id' => $this->b->id, 'email' => 'same@example.test', 'email_verified_at' => now()]);
        $this->claimedVisitor($this->b, $memberB, ['utm_campaign' => 'campagne-b', 'shortcut' => 'bee'], '2026-09-01 09:00:00');
        $contactA = app(CrmContactService::class)->findOrCreate($this->a, ['email' => 'same@example.test', 'source' => CrmContact::SOURCE_MANUAL], $this->adminA);

        $attribution = app(CrmAttributionService::class)->for($contactA);
        $this->assertSame([null, null, null, []], [$attribution['journey'], $attribution['campaign'], $attribution['shortcut'], $attribution['workshops']]);
        $page = $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts.show', [$this->a, $contactA]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-crm-attribution>', $page, 'rien a montrer : pas de bloc');
        $this->assertStringNotContainsString('campagne-b', $page);
        $this->assertStringNotContainsString('/s/bee', $page);

        // Un Contact lie a un membre d'A mais dont le visiteur claime est dans B (donnee incoherente) : ignore.
        $memberA = User::factory()->create(['organization_id' => $this->a->id, 'email' => 'mixed@example.test', 'email_verified_at' => now()]);
        $this->claimedVisitor($this->b, $memberA, ['utm_campaign' => 'campagne-b2', 'shortcut' => 'bee2'], '2026-09-01 09:00:00');
        $contactMixed = app(CrmContactService::class)->findOrCreate($this->a, ['email' => 'mixed@example.test', 'source' => CrmContact::SOURCE_MANUAL], $this->adminA);
        app(CrmContactService::class)->linkToUser($contactMixed, $memberA);
        $this->assertNull(app(CrmAttributionService::class)->for($contactMixed->fresh())['shortcut']);

        // Donnees incoherentes forcees (jamais produites par les services) : un Contact d'A lie a un membre de B qui aurait un
        // visiteur rattache DANS A (S3) ; une inscription de ce membre d'A forcee dans B (S4) — rien de tout cela n'est montre.
        $foreignLinked = CrmContact::query()->forceCreate(['organization_id' => $this->a->id, 'email' => 'foreign@example.test', 'user_id' => $memberB->id, 'source' => CrmContact::SOURCE_MANUAL]);
        $this->claimedVisitor($this->a, $memberB, ['utm_campaign' => 'campagne-a-volee', 'shortcut' => 'bee3'], '2026-09-01 09:00:00');
        $this->assertSame([null, null, []], array_values(array_intersect_key(app(CrmAttributionService::class)->for($foreignLinked->fresh()), ['shortcut' => 1, 'campaign' => 1, 'workshops' => 1])), 'membre d\'une autre Organization : aucune attribution');
        $adminB = User::factory()->create(['organization_id' => $this->b->id]);
        $this->b->update(['admin_id' => $adminB->id]);
        $wb = app(WorkshopService::class)->create($this->b, ['title' => 'Atelier de B', 'slug' => 'ia-b', 'format' => 'online', 'locale' => 'fr'], $adminB);
        $sb = app(WorkshopSessionService::class)->create($wb, ['starts_at' => '2026-11-01 18:30', 'timezone' => 'Europe/Paris'], $adminB);
        WorkshopRegistration::query()->forceCreate(['organization_id' => $this->b->id, 'workshop_id' => $wb->id, 'workshop_session_id' => $sb->id, 'user_id' => $memberA->id, 'status' => 'registered', 'registered_at' => now()]);
        $this->assertSame([], app(CrmAttributionService::class)->for($contactMixed->fresh())['workshops'], 'inscription d\'une autre Organization : jamais montree');
    }

    public function test_f5_the_verified_conversion_is_one_per_journey_and_user_never_one_per_visitor(): void
    {
        // Deux navigateurs (deux visiteurs du meme parcours) rattaches au meme compte : le contrat Journey + User n'est atteint qu'UNE fois.
        $member = User::factory()->create(['organization_id' => $this->a->id, 'email' => 'conv@example.test', 'email_verified_at' => now()]);
        foreach ([Str::random(64), Str::random(64)] as $raw) {
            app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $raw]), $this->a, ['acquisition_journey_id' => $this->journey->id]);
            // Le listener lit le cookie de LA requete de verification : on lui presente ce navigateur.
            $this->app->instance('request', Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $raw]));
            event(new Verified($member));
        }
        $this->assertSame(2, GuestVisitor::query()->where('claimed_user_id', $member->id)->count(), 'les deux navigateurs sont rattaches');
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERTED)->where('user_id', $member->id)->count(), 'un contrat Journey + User = une conversion (F5)');
    }
}
