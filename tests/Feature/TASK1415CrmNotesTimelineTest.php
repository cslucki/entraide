<?php

namespace Tests\Feature;

use App\Listeners\RecordCrmEmailVerified;
use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmStatusService;
use App\Services\Crm\CrmTimelineService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1415 — CRM-3, notes et timeline.
 *
 * - une note est ecrite par un humain de l'Organization du Contact, une fois
 *   pour toutes (append-first strict : ni edition ni suppression) ;
 * - « dernier contact » n'avance que sur une interaction REELLE (note a canal),
 *   jamais sur un fait systeme ;
 * - compte cree / email verifie entrent d'eux-memes dans la timeline du Contact
 *   relie, une seule fois, sans jamais inventer de contexte tenant ;
 * - la timeline se lit dans l'ordre, et ne se lit que par l'Organization.
 */
class TASK1415CrmNotesTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private CrmTimelineService $timeline;

    private CrmContactService $contacts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1415', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1415', 'is_active' => true, 'locale' => 'fr']);
        $this->timeline = app(CrmTimelineService::class);
        $this->contacts = app(CrmContactService::class);
    }

    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    // ── 1. Notes ────────────────────────────────────────────────────────────

    public function test_a_note_is_written_once_with_its_author_and_time_and_never_changes(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->orgA->id]);
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'note@example.com']);

        $before = now()->subSecond();
        $note = $this->timeline->addNote($contact, "  Appel de 10 min, interesse par l'atelier.  ", $admin);

        $this->assertSame(CrmContactEvent::TYPE_NOTE, $note->type);
        $this->assertSame("Appel de 10 min, interesse par l'atelier.", $note->payload['body']);
        $this->assertNull($note->payload['channel']);
        $this->assertSame($admin->id, $note->author_user_id);
        $this->assertSame($this->orgA->id, $note->organization_id);
        $this->assertTrue($note->occurred_at->between($before, now()->addSecond()));

        try {
            $note->update(['payload' => ['body' => 'reecrit']]);
            $this->fail('une note ne s edite pas');
        } catch (LogicException) {
        }
        try {
            $note->delete();
            $this->fail('une note ne se supprime pas');
        } catch (LogicException) {
        }
        $this->assertSame("Appel de 10 min, interesse par l'atelier.", $note->fresh()->payload['body']);
    }

    public function test_an_empty_note_and_an_unknown_channel_are_refused(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->orgA->id]);
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'vide@example.com']);

        try {
            $this->timeline->addNote($contact, '   ', $admin);
            $this->fail('note vide refusee');
        } catch (LogicException) {
        }
        try {
            $this->timeline->addNote($contact, 'Bonjour', $admin, 'pigeon');
            $this->fail('canal inconnu refuse');
        } catch (LogicException) {
        }

        $this->assertSame(0, $contact->events()->count());
    }

    // ── 2. Tenant : l'auteur est de l'Organization du Contact ───────────────

    public function test_a_member_of_another_organization_cannot_write_a_note_and_nothing_is_written(): void
    {
        $outsider = User::factory()->create(['organization_id' => $this->orgB->id]);
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'tenant@example.com']);

        try {
            $this->timeline->addNote($contact, 'Je ne devrais pas pouvoir', $outsider, 'call');
            $this->fail('auteur d une autre Organization refuse');
        } catch (LogicException) {
        }

        $this->assertSame(0, $contact->events()->count());
        $this->assertNull($contact->fresh()->last_interaction_at, 'rien n a bouge, pas meme le dernier contact');
    }

    public function test_a_platform_admin_can_write_a_note_on_any_organization_contact(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => $this->orgB->id, 'is_admin' => true]);
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'global@example.com']);

        $note = $this->timeline->addNote($contact, 'Vu en global', $superAdmin);

        $this->assertSame($superAdmin->id, $note->author_user_id);
        $this->assertSame($this->orgA->id, $note->organization_id, 'l evenement reste dans l Organization du Contact');
    }

    // ── 3. « Dernier contact » = interaction reelle seulement ───────────────

    public function test_last_interaction_moves_only_on_a_note_with_a_channel(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->orgA->id]);
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'dernier@example.com']);
        $this->assertNull($contact->last_interaction_at);

        // Un fait systeme et un changement de statut ne sont pas un contact.
        $client = app(CrmStatusService::class)->ensureDefaultPipeline($this->orgA)->firstWhere('label', 'Client');
        app(CrmStatusService::class)->changeStatus($contact, $client, $admin);
        $this->timeline->recordOnce($contact, CrmContactEvent::TYPE_ACCOUNT_CREATED);
        $this->timeline->addNote($contact, 'Memo interne sans contact', $admin);
        $this->assertNull($contact->fresh()->last_interaction_at);

        $before = now()->subSecond();
        $this->timeline->addNote($contact, 'Appel telephonique', $admin, 'call');

        $last = $contact->fresh()->last_interaction_at;
        $this->assertNotNull($last);
        $this->assertTrue($last->between($before, now()->addSecond()));
    }

    // ── 4. Faits systeme : compte cree, email verifie ───────────────────────

    public function test_registration_records_account_created_once_on_the_linked_contact(): void
    {
        Notification::fake();
        $this->makeDefault($this->orgA);
        $contact = CrmContact::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'prospect@example.com', 'user_id' => null]);

        $this->post(route('register'), $this->payload('prospect@example.com'))->assertSessionHasNoErrors()->assertRedirect();

        $user = User::where('email', 'prospect@example.com')->firstOrFail();
        $events = $contact->fresh()->events()->chronological()->get();
        $this->assertSame([CrmContactEvent::TYPE_ACCOUNT_CREATED], $events->pluck('type')->all());
        $this->assertSame($user->id, $events->first()->payload['user_id']);
        $this->assertNull($events->first()->author_user_id, 'un fait systeme n a pas d auteur humain');
        $this->assertNull($contact->fresh()->last_interaction_at);

        // Rejouer la liaison n'ecrit pas un second fait.
        $this->contacts->linkOnRegistration($user);
        $this->assertSame(1, $contact->fresh()->events()->count());
    }

    public function test_the_verified_listener_is_discovered(): void
    {
        Event::fake();

        Event::assertListening(Verified::class, RecordCrmEmailVerified::class);
    }

    public function test_verifying_the_email_records_email_verified_on_the_linked_contact_only(): void
    {
        $member = User::factory()->unverified()->create(['organization_id' => $this->orgA->id]);
        $contact = CrmContact::factory()->linkedTo($member)->create();
        $unrelated = CrmContact::factory()->create(['organization_id' => $this->orgA->id]);

        $lien = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $member->id,
            'hash' => sha1($member->email),
        ]);
        $this->actingAs($member)->get($lien)->assertRedirect();

        $this->assertTrue($member->fresh()->hasVerifiedEmail());
        $this->assertSame([CrmContactEvent::TYPE_EMAIL_VERIFIED], $contact->events()->pluck('type')->all());
        $this->assertSame(0, $unrelated->events()->count());
        $this->assertNull($contact->fresh()->last_interaction_at);
    }

    public function test_a_verified_member_without_a_contact_writes_nothing_and_creates_none(): void
    {
        $member = User::factory()->unverified()->create(['organization_id' => $this->orgA->id]);

        $lien = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $member->id,
            'hash' => sha1($member->email),
        ]);
        $this->actingAs($member)->get($lien)->assertRedirect();

        $this->assertTrue($member->fresh()->hasVerifiedEmail());
        $this->assertSame(0, CrmContactEvent::count());
        $this->assertSame(0, CrmContact::withTrashed()->count(), 'Verified ne cree JAMAIS de Contact');
    }

    /**
     * La panne doit etre EXERCEE : sans Contact relie, `recordOnce` n'est
     * jamais appele et un mock qui leve ne prouve rien (sabotage S5 reste
     * vert). Ici le Contact existe, le CRM leve, la verification tient.
     */
    public function test_a_crm_failure_never_breaks_verification(): void
    {
        $member = User::factory()->unverified()->create(['organization_id' => $this->orgA->id]);
        CrmContact::factory()->linkedTo($member)->create();
        $this->mock(CrmTimelineService::class)->shouldReceive('recordOnce')->once()->andThrow(new \RuntimeException('CRM down'));

        $lien = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $member->id,
            'hash' => sha1($member->email),
        ]);
        $this->actingAs($member)->get($lien)->assertRedirect();

        $this->assertTrue($member->fresh()->hasVerifiedEmail());
        $this->assertSame(0, CrmContactEvent::count());
    }

    public function test_a_contact_linked_to_an_already_verified_member_gets_both_facts_once(): void
    {
        $member = User::factory()->create(['organization_id' => $this->orgA->id]);
        $contact = CrmContact::factory()->create(['organization_id' => $this->orgA->id, 'email' => $member->email, 'user_id' => null]);

        $this->contacts->linkOnRegistration($member);
        $this->contacts->linkOnRegistration($member);

        $this->assertSame(
            [CrmContactEvent::TYPE_ACCOUNT_CREATED, CrmContactEvent::TYPE_EMAIL_VERIFIED],
            $contact->fresh()->events()->chronological()->pluck('type')->all()
        );
    }

    // ── 5. Lecture ──────────────────────────────────────────────────────────

    public function test_the_timeline_reads_in_order_and_only_through_the_organization(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->orgA->id]);
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'fil@example.com']);
        $statuses = app(CrmStatusService::class);
        $pipeline = $statuses->ensureDefaultPipeline($this->orgA);

        $this->travel(-3)->minutes();
        $this->timeline->addNote($contact, 'Premiere note', $admin);
        $this->travel(2)->minutes();
        $statuses->changeStatus($contact, $pipeline->firstWhere('label', 'À contacter'), $admin);
        $this->travelBack();
        $this->timeline->addNote($contact, 'Derniere note', $admin, 'email');

        $types = $this->timeline->timeline($contact)->pluck('type')->all();
        $this->assertSame([CrmContactEvent::TYPE_NOTE, CrmContactEvent::TYPE_STATUS_CHANGED, CrmContactEvent::TYPE_NOTE], $types);

        $this->assertSame(3, CrmContactEvent::forOrganization($this->orgA)->count());
        $this->assertSame(0, CrmContactEvent::forOrganization($this->orgB)->count());
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    public function test_the_probe_sees_last_interaction_when_it_is_set(): void
    {
        $contact = CrmContact::factory()->create(['organization_id' => $this->orgA->id, 'last_interaction_at' => now()]);
        $blank = CrmContact::factory()->create(['organization_id' => $this->orgA->id]);

        $this->assertNotNull($contact->fresh()->last_interaction_at);
        $this->assertNull($blank->fresh()->last_interaction_at);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function makeDefault(Organization $organization): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);
        $organization->update(['is_default' => true]);
        app()->instance('current_organization', $organization);
    }

    private function payload(string $email): array
    {
        return [
            'name' => 'Dupont',
            'first_name' => 'Camille',
            'email' => $email,
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'Un-mot-de-passe-solide-1415!',
            'password_confirmation' => 'Un-mot-de-passe-solide-1415!',
        ];
    }
}
