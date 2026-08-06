<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LoopMembersCard;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La Card Membres.
 *
 * Ce fichier existe pour deux raisons. La premiere est le re-cadrage serveur :
 * ce que le navigateur poste ne decide de rien, seuls les candidats reels de
 * l'Organization entrent. La seconde est la fraicheur de la liste — le defaut
 * signale a l'origine — qui tient a un detail : la fenetre est fermee par le
 * serveur, donc le trombinoscope est re-rendu dans la meme reponse.
 */
class LoopMembersCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $candidate;

    private User $plainMember;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Owner Person']);
        $this->candidate = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Candidate Person']);
        $this->plainMember = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Plain Person']);

        $this->loop = (new LoopService)->createLoop($this->owner, 'Members Card Loop');

        LoopMember::factory()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->plainMember->id,
            'status' => 'active',
            'role' => 'member',
        ]);

        app()->instance('current_organization', $this->organization);
    }

    // ── Ce que la Card montre ───────────────────────────────────────────────

    public function test_the_roster_lists_the_active_members(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->assertSee('Owner Person')
            ->assertSee('Plain Person')
            ->assertDontSee('Candidate Person');
    }

    public function test_the_segments_filter_the_roster(): void
    {
        $facilitator = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Facilitator Person']);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $facilitator->id,
            'status' => 'active',
            'role' => 'facilitator',
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop]);

        // « Membres » ne montre que le role member : ni le proprietaire, ni
        // l'animateur, qui ont leur propre segment ou aucun.
        $component->call('selectSegment', 'members')
            ->assertSee('Plain Person')
            ->assertDontSee('Facilitator Person')
            ->assertDontSee('Owner Person');

        $component->call('selectSegment', 'facilitators')
            ->assertSee('Facilitator Person')
            ->assertDontSee('Plain Person');

        $component->call('selectSegment', 'all')
            ->assertSee('Plain Person')
            ->assertSee('Facilitator Person')
            ->assertSee('Owner Person');
    }

    public function test_an_unknown_segment_falls_back_to_all(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('selectSegment', 'owners')
            ->assertSet('segment', LoopMembersCard::SEGMENT_ALL);
    }

    // ── Ajouter quelqu'un ───────────────────────────────────────────────────

    public function test_adding_someone_closes_the_window_and_the_roster_shows_them(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->assertSet('showAddModal', true)
            ->assertSee('Candidate Person')
            ->set('selected', [$this->candidate->id])
            ->call('add');

        // Le geste fait, la fenetre se referme et la liste re-rendue dans la
        // meme reponse porte deja l'arrivant : c'est tout l'objet du passage
        // par le serveur.
        $component->assertSet('showAddModal', false)
            ->assertSee('Candidate Person');

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->candidate->id,
            'status' => 'active',
        ]);
    }

    public function test_the_confirmation_names_the_people_added(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('selected', [$this->candidate->id])
            ->call('add')
            ->assertSet('justAdded', [[
                'id' => $this->candidate->id,
                'name' => $this->candidate->publicDisplayName(),
            ]]);
    }

    public function test_a_user_of_another_organization_is_never_added(): void
    {
        $foreigner = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('selected', [$foreigner->id])
            ->call('add');

        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $foreigner->id,
        ]);
    }

    public function test_a_deactivated_user_is_never_added(): void
    {
        $banned = User::factory()->create([
            'organization_id' => $this->organization->id,
            'banned_at' => now(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('selected', [$banned->id])
            ->call('add');

        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $banned->id,
        ]);
    }

    public function test_adding_someone_already_in_the_loop_reports_nothing_added(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('selected', [$this->plainMember->id])
            ->call('add')
            ->assertSet('justAdded', [])
            ->assertSet('errorMessage', __('loops.members_add_none'));
    }

    public function test_a_standard_member_cannot_add_anyone(): void
    {
        Livewire::actingAs($this->plainMember)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->set('selected', [$this->candidate->id])
            ->call('add')
            ->assertForbidden();
    }

    public function test_a_ticked_box_does_not_survive_the_search_that_hid_it(): void
    {
        // Sans cela, cocher quelqu'un puis retaper la recherche laisse une
        // selection que plus rien a l'ecran ne designe.
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('selected', [$this->candidate->id])
            ->set('search', 'quelqu-un-d-autre')
            ->assertSet('selected', []);
    }

    public function test_closing_the_window_clears_what_was_typed(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('search', 'Candidate')
            ->set('selected', [$this->candidate->id])
            ->call('closeAdd')
            ->assertSet('showAddModal', false)
            ->assertSet('search', '')
            ->assertSet('selected', []);
    }

    // ── Le champ unique ─────────────────────────────────────────────────────

    public function test_a_name_filters_the_candidates(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('search', 'Candidate')
            ->assertSee('Candidate Person');
    }

    public function test_an_unknown_address_offers_the_invitation(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('search', 'inconnu@exemple.fr')
            ->assertSee(__('loops.members_invite_typed', ['email' => 'inconnu@exemple.fr']));
    }

    public function test_a_known_person_is_never_offered_as_an_email_invitation(): void
    {
        // Sans ce garde-fou, on proposerait d'inviter par courriel quelqu'un
        // qui figure dans la liste juste en dessous.
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('search', $this->candidate->email)
            ->assertDontSee(__('loops.members_invite_typed', ['email' => $this->candidate->email]));
    }

    public function test_the_typed_address_carries_over_into_the_invitation_form(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('search', 'inconnu@exemple.fr')
            ->call('inviteTyped')
            ->assertSet('inviteEmail', 'inconnu@exemple.fr')
            ->assertSet('openEmail', true)
            ->assertSet('search', '');
    }

    // ── Inviter par courriel ────────────────────────────────────────────────

    public function test_an_invitation_is_created_and_the_window_closes(): void
    {
        Mail::fake();

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('inviteEmail', 'nouvelle@exemple.fr')
            ->set('inviteName', 'Nouvelle Personne')
            ->call('sendInvitation')
            ->assertSet('showAddModal', false)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loop_invitations', [
            'loop_id' => $this->loop->id,
            'recipient_email' => 'nouvelle@exemple.fr',
        ]);
    }

    public function test_an_invalid_address_is_refused(): void
    {
        Mail::fake();

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('inviteEmail', 'pas-une-adresse')
            ->call('sendInvitation')
            ->assertHasErrors('inviteEmail');
    }

    public function test_a_standard_member_cannot_invite(): void
    {
        Livewire::actingAs($this->plainMember)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->set('inviteEmail', 'nouvelle@exemple.fr')
            ->call('sendInvitation')
            ->assertForbidden();
    }

    // ── Ce que la fenetre « Invitations » suit ──────────────────────────────

    public function test_only_email_invitations_are_listed(): void
    {
        // Rejoindre depuis l'Organization est immediat : il n'y a rien a
        // accepter, donc rien a suivre.
        LoopInvitation::factory()->create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'externe@exemple.fr',
            'invitation_type' => LoopInvitation::TYPE_EXTERNAL,
        ]);

        LoopInvitation::factory()->create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'interne@exemple.fr',
            'invitation_type' => LoopInvitation::TYPE_EXISTING_MEMBER,
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openInvitations')
            ->assertSee('externe@exemple.fr')
            ->assertDontSee('interne@exemple.fr');
    }

    // ── Une Boucle archivee ne recrute plus ─────────────────────────────────

    public function test_an_archived_loop_offers_neither_gesture(): void
    {
        $this->loop->update(['status' => 'archived', 'archived_at' => now()]);

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop->fresh()])
            ->assertDontSee(__('loops.members_add_title'));
    }

    public function test_an_archived_loop_refuses_an_add_even_if_asked(): void
    {
        $this->loop->update(['status' => 'archived', 'archived_at' => now()]);

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop->fresh()])
            ->set('selected', [$this->candidate->id])
            ->call('add');

        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->candidate->id,
        ]);
    }

    // ── Le lien partageable ─────────────────────────────────────────────────

    public function test_the_share_url_is_frozen_at_mount(): void
    {
        // Les requetes suivantes de Livewire passent par /livewire/update, ou
        // le parametre d'Organization n'existe plus : le reconstruire a chaque
        // rendu produirait une adresse hors tenant.
        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->assertSet('shareUrl', route('loops.show', $this->loop));
    }
}
