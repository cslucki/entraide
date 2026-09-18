<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1430 — CRM CORE FIX A : depuis Relations, l'OrgAdmin ajoute au suivi
 * un membre EXISTANT de son Organization.
 *
 * Ce qui est mesure :
 * 1. le panneau est ferme par defaut et ne liste des membres que sur demande,
 *    toujours DANS cette Organization ;
 * 2. un membre deja suivi est signale (lien vers sa fiche), pas re-propose ;
 * 3. « Ajouter au suivi » cree UN Contact relie, avec statut par defaut,
 *    acteur, et le fait « membre ajoute au suivi » une seule fois — idempotent ;
 * 4. un membre dont l'email est deja un Contact non relie est RELIE, pas
 *    duplique ; un Contact supprime est restaure ;
 * 5. cross-tenant : 404 pour un membre d'ailleurs, 403 hors OrgAdmin, conflit
 *    d'email refuse sans ecriture.
 */
class TASK1430CrmFollowExistingMemberTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private User $memberA1;

    private User $memberA2;

    private User $memberB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1430', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1430', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Aline', 'name' => 'Admin']);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id, 'first_name' => 'Boris', 'name' => 'Admin']);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);

        // Noms FIXES (jamais Faker dans une assertion) ; le meme prenom dans
        // les deux Organizations prouve que la recherche ne sort pas du tenant.
        $this->memberA1 = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Brunhilde', 'name' => 'Vasquez-Lorne', 'email' => 'brunhilde.vl@example.com']);
        $this->memberA2 = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Ottoline', 'name' => 'Kaminski', 'email' => 'ottoline.k@example.com']);
        $this->memberB = User::factory()->create(['organization_id' => $this->orgB->id, 'first_name' => 'Brunhilde', 'name' => 'Bartholomew', 'email' => 'brunhilde.b@example.com']);
    }

    private function contacts(Organization $organization, array $query = []): string
    {
        return route('organization.admin.crm.contacts', ['organization' => $organization->slug] + $query);
    }

    private function follow(Organization $organization, User $user): string
    {
        return route('organization.admin.crm.members.follow', ['organization' => $organization->slug, 'user' => $user->id]);
    }

    // ── 1. Panneau ferme par defaut, tenant-scoped sur demande ─────────────

    public function test_the_member_panel_lists_members_only_on_demand_and_only_from_this_organization(): void
    {
        $closed = $this->actingAs($this->adminA)->get($this->contacts($this->orgA))->assertOk();
        $closed->assertSee('data-crm-member-toggle', false)
            ->assertDontSee('data-crm-member-results', false)
            ->assertDontSee('brunhilde.vl@example.com')
            ->assertDontSee('ottoline.k@example.com');

        $searched = $this->actingAs($this->adminA)->get($this->contacts($this->orgA, ['member_search' => 'brunhilde']))->assertOk();
        $searched->assertSee('data-crm-member-results', false)
            ->assertSee('data-crm-member="'.$this->memberA1->id.'"', false)
            ->assertSee('data-crm-follow="'.$this->memberA1->id.'"', false)
            ->assertSee('Brunhilde Vasquez-Lorne')
            ->assertDontSee('data-crm-member="'.$this->memberA2->id.'"', false)
            ->assertDontSee('data-crm-member="'.$this->memberB->id.'"', false)
            ->assertDontSee('brunhilde.b@example.com')
            ->assertDontSee('Bartholomew');

        // Recherche vide : tous les membres de CETTE Organization, aucun d'ailleurs.
        $all = $this->actingAs($this->adminA)->get($this->contacts($this->orgA, ['member_search' => '']))->assertOk();
        $all->assertSee('data-crm-member="'.$this->memberA1->id.'"', false)
            ->assertSee('data-crm-member="'.$this->memberA2->id.'"', false)
            ->assertDontSee('data-crm-member="'.$this->memberB->id.'"', false);

        // Rien ne correspond : le panneau le dit, sans inventer.
        $this->actingAs($this->adminA)->get($this->contacts($this->orgA, ['member_search' => 'zzz-personne']))->assertOk()
            ->assertSee('data-crm-member-empty', false)
            ->assertDontSee('data-crm-follow=', false);
    }

    // ── 2. Deja suivi = signale, pas re-propose ────────────────────────────

    public function test_a_member_already_followed_is_flagged_with_a_link_instead_of_a_button(): void
    {
        $contact = app(CrmContactService::class)->followMember($this->orgA, $this->memberA1, $this->adminA);

        $response = $this->actingAs($this->adminA)->get($this->contacts($this->orgA, ['member_search' => '']))->assertOk();
        $response->assertSee('data-crm-member-followed="'.$this->memberA1->id.'"', false)
            ->assertSee(route('organization.admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => $contact->id]), false)
            ->assertDontSee('data-crm-follow="'.$this->memberA1->id.'"', false)
            ->assertSee('data-crm-follow="'.$this->memberA2->id.'"', false)
            ->assertDontSee('data-crm-member-followed="'.$this->memberA2->id.'"', false);
    }

    // ── 3. Un clic = un Contact relie, idempotent ──────────────────────────

    public function test_following_creates_one_linked_contact_with_default_status_actor_and_the_account_fact_once(): void
    {
        $this->actingAs($this->adminA)->post($this->follow($this->orgA, $this->memberA1))
            ->assertRedirect($this->contacts($this->orgA, ['search' => 'brunhilde.vl@example.com']))
            ->assertSessionHas('success', __('crm.flash.member_followed'));

        $contact = CrmContact::forOrganization($this->orgA)->where('user_id', $this->memberA1->id)->firstOrFail();
        $this->assertSame('brunhilde.vl@example.com', $contact->email);
        $this->assertSame('Brunhilde', $contact->first_name);
        $this->assertSame('Vasquez-Lorne', $contact->last_name);
        $this->assertSame(CrmContact::SOURCE_MANUAL, $contact->source);
        $this->assertSame($this->adminA->id, $contact->created_by_user_id);
        $this->assertSame(app(CrmStatusService::class)->defaultStatus($this->orgA)->id, $contact->status_id);
        // MASTER Q51 : le compte existait — le fait est « membre ajoute au suivi »,
        // signe par l'OrgAdmin, jamais « compte cree ».
        $linkedFacts = $contact->events()->where('type', CrmContactEvent::TYPE_MEMBER_LINKED)->get();
        $this->assertCount(1, $linkedFacts);
        $this->assertSame($this->adminA->id, $linkedFacts->first()->author_user_id);
        $this->assertSame($this->memberA1->id, $linkedFacts->first()->payload['user_id']);
        $this->assertSame(0, $contact->events()->where('type', CrmContactEvent::TYPE_ACCOUNT_CREATED)->count());

        $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => $contact->id]))
            ->assertOk()
            ->assertSee('data-crm-event="member_linked"', false)
            ->assertSee(__('crm.event.member_linked'));

        $this->actingAs($this->adminA)->post($this->follow($this->orgA, $this->memberA1))
            ->assertRedirect()
            ->assertSessionHas('success', __('crm.flash.member_already_followed'));

        $this->assertSame(1, CrmContact::withTrashed()->forOrganization($this->orgA)->where('user_id', $this->memberA1->id)->count());
        $this->assertSame(1, $contact->events()->where('type', CrmContactEvent::TYPE_MEMBER_LINKED)->count());
    }

    // ── 4. Email deja Contact : relier, pas dupliquer ; supprime : restaurer ─

    public function test_an_unlinked_contact_with_the_same_email_is_linked_and_a_deleted_one_is_restored(): void
    {
        $service = app(CrmContactService::class);
        $existing = $service->findOrCreate($this->orgA, ['first_name' => 'Otto', 'email' => 'ottoline.k@example.com'], $this->adminA);

        $this->actingAs($this->adminA)->post($this->follow($this->orgA, $this->memberA2))
            ->assertRedirect()
            ->assertSessionHas('success', __('crm.flash.member_followed'));

        $this->assertSame(1, CrmContact::withTrashed()->forOrganization($this->orgA)->where('email', 'ottoline.k@example.com')->count());
        $existing->refresh();
        $this->assertSame($this->memberA2->id, $existing->user_id);
        // Ce que l'OrgAdmin avait saisi n'est pas ecrase par le profil du membre.
        $this->assertSame('Otto', $existing->first_name);
        $this->assertSame('Kaminski', $existing->last_name);

        $this->actingAs($this->adminA)->get($this->contacts($this->orgA, ['member_search' => 'ottoline']))->assertOk()
            ->assertSee('data-crm-member-followed="'.$this->memberA2->id.'"', false)
            ->assertDontSee('data-crm-follow="'.$this->memberA2->id.'"', false);

        $existing->delete();
        $this->assertTrue($existing->fresh()->trashed());

        $this->actingAs($this->adminA)->post($this->follow($this->orgA, $this->memberA2))->assertRedirect();

        $this->assertSame(1, CrmContact::withTrashed()->forOrganization($this->orgA)->where('email', 'ottoline.k@example.com')->count());
        $this->assertFalse($existing->fresh()->trashed());
    }

    // ── 5. Cross-tenant et conflit : rien n'est ecrit ──────────────────────

    public function test_cross_tenant_and_conflicts_are_refused_without_writing_anything(): void
    {
        // Un membre d'une autre Organization n'existe pas ici : 404.
        $this->actingAs($this->adminA)->post($this->follow($this->orgA, $this->memberB))->assertNotFound();
        // L'OrgAdmin d'ailleurs ne peut ni chercher ni ajouter dans cette Organization : 403.
        $this->actingAs($this->adminB)->get($this->contacts($this->orgA, ['member_search' => '']))->assertForbidden();
        $this->actingAs($this->adminB)->post($this->follow($this->orgA, $this->memberA1))->assertForbidden();
        // Un simple membre non plus.
        $this->actingAs($this->memberA1)->get($this->contacts($this->orgA, ['member_search' => '']))->assertForbidden();
        $this->actingAs($this->memberA1)->post($this->follow($this->orgA, $this->memberA2))->assertForbidden();

        $this->assertSame(0, CrmContact::withTrashed()->count());

        // Le service lui-meme refuse un membre d'ailleurs AVANT toute ecriture :
        // sans cette garde, findOrCreate creerait le Contact puis linkToUser
        // refuserait — l'exception serait la meme, mais une ligne serait nee.
        try {
            app(CrmContactService::class)->followMember($this->orgA, $this->memberB, $this->adminA);
            $this->fail('A member of another Organization must be refused.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Only a member of this Organization', $e->getMessage());
        }

        $this->assertSame(0, CrmContact::withTrashed()->count());
    }

    public function test_an_email_already_linked_to_another_member_is_a_conflict_not_a_theft(): void
    {
        $service = app(CrmContactService::class);
        // Un Contact portant l'email de A1 est deja relie a A2 (meme Organization).
        $contact = $service->findOrCreate($this->orgA, ['email' => 'brunhilde.vl@example.com'], $this->adminA);
        $service->linkToUser($contact, $this->memberA2);

        $this->actingAs($this->adminA)->from($this->contacts($this->orgA))
            ->post($this->follow($this->orgA, $this->memberA1))
            ->assertRedirect($this->contacts($this->orgA))
            ->assertSessionHas('error', __('crm.flash.follow_conflict'));

        $this->assertSame($this->memberA2->id, $contact->fresh()->user_id);
        $this->assertSame(0, CrmContact::withTrashed()->where('user_id', $this->memberA1->id)->count());
    }
}
