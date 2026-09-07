<?php

namespace Tests\Feature;

use App\Listeners\LinkCrmContactOnRegistration;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1413 — CRM-1, fondation du Contact d'Organization.
 *
 * Ce que cette tranche garantit, et que tout le Mini-CRM heritera :
 *
 * - un Contact appartient a UNE Organization, toujours, sans defaut implicite ;
 * - la deduplication est tenant-scoped (email normalise ; telephone seulement
 *   s'il est explicitement international) et ne regarde JAMAIS ailleurs ;
 * - un Contact ne devient pas un faux User, et un User ne devient pas
 *   automatiquement un Contact : a l'inscription on RELIE seulement (MASTER Q1) ;
 * - un echec cote CRM ne casse jamais une inscription ;
 * - « ne pas contacter » ferme la porte, des maintenant.
 */
class TASK1413CrmContactFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private CrmContactService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1413', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1413', 'is_active' => true]);
        $this->service = app(CrmContactService::class);
    }

    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    // ── 1. Tenant : pas de Contact sans Organization ────────────────────────

    public function test_a_contact_cannot_exist_without_an_organization_even_with_a_current_organization_bound(): void
    {
        // Un contexte tenant EST lie : si le modele le devinait, l'insertion
        // passerait. Elle doit echouer — l'Organization se declare, toujours.
        app()->instance('current_organization', $this->orgA);

        $this->expectException(QueryException::class);

        CrmContact::create(['first_name' => 'Sans', 'last_name' => 'Tenant', 'email' => 'sans@tenant.test']);
    }

    // ── 2. Deduplication tenant-scoped ──────────────────────────────────────

    public function test_find_or_create_deduplicates_on_the_normalized_email_within_the_organization(): void
    {
        $first = $this->service->findOrCreate($this->orgA, ['email' => 'Alice@Example.COM', 'first_name' => 'Alice']);
        $second = $this->service->findOrCreate($this->orgA, ['email' => '  alice@example.com ', 'company' => 'ACME']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('alice@example.com', $second->email);
        $this->assertSame('Alice', $second->first_name, 'la premiere saisie n est pas ecrasee');
        $this->assertSame('ACME', $second->company, 'un champ vide est complete');
        $this->assertSame(1, CrmContact::forOrganization($this->orgA)->count());
    }

    public function test_the_same_email_in_two_organizations_gives_two_contacts_that_never_see_each_other(): void
    {
        $a = $this->service->findOrCreate($this->orgA, ['email' => 'shared@example.com', 'first_name' => 'Vu par A']);
        $b = $this->service->findOrCreate($this->orgB, ['email' => 'shared@example.com', 'first_name' => 'Vu par B']);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame('Vu par A', $this->service->findByEmail($this->orgA, 'shared@example.com')?->first_name);
        $this->assertSame('Vu par B', $this->service->findByEmail($this->orgB, 'shared@example.com')?->first_name);
        $this->assertSame(1, CrmContact::forOrganization($this->orgA)->count());
        $this->assertSame(1, CrmContact::forOrganization($this->orgB)->count());
    }

    public function test_a_soft_deleted_contact_is_restored_instead_of_duplicated_or_crashing(): void
    {
        $contact = $this->service->findOrCreate($this->orgA, ['email' => 'revenant@example.com', 'first_name' => 'Ancien']);
        $contact->delete();
        $this->assertSame(0, CrmContact::forOrganization($this->orgA)->count());

        $again = $this->service->findOrCreate($this->orgA, ['email' => 'revenant@example.com']);

        $this->assertSame($contact->id, $again->id);
        $this->assertFalse($again->trashed());
        $this->assertSame('Ancien', $again->first_name, 'l historique n a pas disparu');
    }

    // ── 3. Telephone : syntaxe seulement, dedup seulement si international ──

    public function test_phone_normalization_is_strictly_syntactic_and_keeps_the_raw_value(): void
    {
        $this->assertSame('+33612345678', CrmContact::normalizePhone('+33 6 12-34 56 78'));
        $this->assertSame('0612345678', CrmContact::normalizePhone('06 12 34 56 78'), 'pas de pays devine : jamais +33 ajoute');
        $this->assertSame('0033612345678', CrmContact::normalizePhone('00 33 6 12 34 56 78'), 'le 00 n est pas converti en +');
        $this->assertNull(CrmContact::normalizePhone('abc'));
        $this->assertNull(CrmContact::normalizePhone('   '));

        $contact = $this->service->findOrCreate($this->orgA, ['phone' => '+33 6 12-34 56 78']);
        $this->assertSame('+33 6 12-34 56 78', $contact->phone, 'le brut est conserve');
        $this->assertSame('+33612345678', $contact->phone_normalized);
        $this->assertTrue($contact->hasInternationalPhone());
    }

    public function test_only_an_explicitly_international_phone_deduplicates(): void
    {
        $intl1 = $this->service->findOrCreate($this->orgA, ['phone' => '+33 6 11 22 33 44']);
        $intl2 = $this->service->findOrCreate($this->orgA, ['phone' => '+33611223344']);
        $this->assertSame($intl1->id, $intl2->id, 'meme numero international = meme Contact');

        $nat1 = $this->service->findOrCreate($this->orgA, ['phone' => '06 55 66 77 88']);
        $nat2 = $this->service->findOrCreate($this->orgA, ['phone' => '0655667788']);
        $this->assertNotSame($nat1->id, $nat2->id, 'sans pays d autorite, on ne fusionne pas');

        // Et l'international ne traverse pas non plus les Organizations.
        $elsewhere = $this->service->findOrCreate($this->orgB, ['phone' => '+33611223344']);
        $this->assertNotSame($intl1->id, $elsewhere->id);
    }

    // ── 4. Lien au compte : meme Organization, jamais de vol ────────────────

    public function test_link_to_user_refuses_a_member_of_another_organization(): void
    {
        $contact = CrmContact::factory()->create(['organization_id' => $this->orgA->id]);
        $memberOfB = User::factory()->create(['organization_id' => $this->orgB->id]);

        try {
            $this->service->linkToUser($contact, $memberOfB);
            $this->fail('un membre d une autre Organization ne peut pas etre relie');
        } catch (LogicException) {
        }

        $this->assertNull($contact->fresh()->user_id);
    }

    public function test_link_to_user_links_a_same_organization_member_fills_missing_names_and_is_idempotent(): void
    {
        $contact = CrmContact::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => null, 'last_name' => null]);
        $member = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Camille', 'name' => 'Dupont']);
        $other = User::factory()->create(['organization_id' => $this->orgA->id]);

        $this->service->linkToUser($contact, $member);
        $this->service->linkToUser($contact->fresh(), $member);

        $fresh = $contact->fresh();
        $this->assertSame($member->id, $fresh->user_id);
        $this->assertTrue($fresh->isLinkedToAccount());
        $this->assertSame('Camille', $fresh->first_name);
        $this->assertSame('Dupont', $fresh->last_name);

        $this->expectException(LogicException::class);
        $this->service->linkToUser($fresh, $other);
    }

    // ── 5. Inscription : RELIER seulement ───────────────────────────────────

    public function test_the_listener_is_discovered_on_registered(): void
    {
        Event::fake();

        Event::assertListening(Registered::class, LinkCrmContactOnRegistration::class);
    }

    public function test_registration_links_the_pre_existing_contact_of_the_same_organization(): void
    {
        Notification::fake();
        $this->makeDefault($this->orgA);
        $contact = CrmContact::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'prospect@example.com', 'user_id' => null]);

        // L'inscription exige un email en minuscules (regle `lowercase`) :
        // la normalisation cote CRM est prouvee au test 2, pas ici.
        $this->post(route('register'), $this->payload('prospect@example.com'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $user = User::where('email', 'prospect@example.com')->firstOrFail();
        $this->assertSame($this->orgA->id, $user->organization_id);
        $this->assertSame($user->id, $contact->fresh()->user_id);
    }

    public function test_registration_creates_no_contact_when_none_exists(): void
    {
        Notification::fake();
        $this->makeDefault($this->orgA);

        $this->post(route('register'), $this->payload('nouveau@example.com'))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'nouveau@example.com']);
        $this->assertSame(0, CrmContact::withTrashed()->count(), 'le CRM n est pas un annuaire bis');
    }

    public function test_registration_never_links_a_contact_of_another_organization(): void
    {
        Notification::fake();
        $this->makeDefault($this->orgA);
        $foreign = CrmContact::factory()->create(['organization_id' => $this->orgB->id, 'email' => 'ailleurs@example.com', 'user_id' => null]);

        $this->post(route('register'), $this->payload('ailleurs@example.com'))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($foreign->fresh()->user_id);
        $this->assertSame(0, CrmContact::forOrganization($this->orgA)->count());
    }

    /**
     * Garde propre a `linkOnRegistration()` : elle ne CHERCHE que dans
     * l'Organization du membre. Sans ce test, retirer ce filtre resterait
     * invisible — `linkToUser()` refuserait ensuite le membre d'ailleurs, et
     * `rescue()` avalerait l'exception : le resultat serait identique, mais
     * par un accident de la seconde garde, pas par la premiere.
     */
    public function test_link_on_registration_looks_only_inside_the_member_organization(): void
    {
        CrmContact::factory()->create(['organization_id' => $this->orgB->id, 'email' => 'ailleurs@example.com', 'user_id' => null]);
        $member = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'ailleurs@example.com']);

        $this->assertNull($this->service->linkOnRegistration($member), 'ni lien, ni exception : le Contact d ailleurs n est jamais vu');
    }

    public function test_a_crm_failure_never_breaks_a_registration(): void
    {
        Notification::fake();
        $this->makeDefault($this->orgA);
        $this->mock(CrmContactService::class)
            ->shouldReceive('linkOnRegistration')
            ->andThrow(new RuntimeException('CRM down'));

        $this->post(route('register'), $this->payload('resilient@example.com'))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'resilient@example.com']);
        $this->assertAuthenticated();
    }

    // ── 6. Contactabilite : fail closed ─────────────────────────────────────

    public function test_do_not_contact_closes_the_door(): void
    {
        $open = CrmContact::factory()->create(['organization_id' => $this->orgA->id]);
        $closed = CrmContact::factory()->doNotContact()->create(['organization_id' => $this->orgA->id]);

        $this->assertTrue($open->isContactable());
        $this->assertFalse($closed->isContactable());
        $this->assertSame([$open->id], CrmContact::forOrganization($this->orgA)->contactable()->pluck('id')->all());
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    /**
     * Sans lui, une garde « jamais relie » resterait verte si le lien n'etait
     * tout simplement jamais pose. On prouve ici que la sonde voit un lien
     * quand il existe.
     */
    public function test_the_probe_sees_a_link_when_one_exists(): void
    {
        $member = User::factory()->create(['organization_id' => $this->orgA->id]);
        $linked = CrmContact::factory()->linkedTo($member)->create();
        $free = CrmContact::factory()->create(['organization_id' => $this->orgA->id]);

        $this->assertSame($member->id, $linked->fresh()->user_id);
        $this->assertNull($free->fresh()->user_id);
        $this->assertTrue($linked->isLinkedToAccount());
        $this->assertFalse($free->isLinkedToAccount());
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
            'password' => 'Un-mot-de-passe-solide-1413!',
            'password_confirmation' => 'Un-mot-de-passe-solide-1413!',
        ];
    }
}
