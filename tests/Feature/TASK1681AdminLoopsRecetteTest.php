<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1681 — recette corrective OFSH, parcours /admin/loops et
 * /admin/loops/{loop}/edit. Quatre defauts de la meme surface :
 *
 * BUG 1 — lien direct vers la Boucle depuis la liste : livre par T1680
 *         (workspaceUrl), VERROUILLE ici par ligne et par Organization.
 * BUG 2 — le prenom manquait au selecteur : full_name retombe en silence
 *         sur `name` quand `first_name` n'est pas SELECTIONNE.
 * BUG 3 — le selecteur proposait le parc entier ; desormais seuls les
 *         utilisateurs de l'Organization de la Boucle, et une requete
 *         forgee recoit un message explicite (garde serveur inchangee).
 * BUG 4 — Organization sans utilisateur assignable : etat vide nomme +
 *         lien vers le workflow canonique d'invitation (bulk-create,
 *         Organization pre-selectionnee par la query string).
 */
class TASK1681AdminLoopsRecetteTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->orgA->id,
        ]);

        $this->loop = app(LoopService::class)->createLoopForOrg(
            null, $this->orgB->id, 'Boucle OFSH recette', null, 'private',
            null, Loop::ACCESS_REQUEST, null, $this->superAdmin,
        );
    }

    public function test_the_list_links_each_loop_to_its_own_organization_url(): void
    {
        $loopA = app(LoopService::class)->createLoopForOrg(
            null, $this->orgA->id, 'Boucle locale', null, 'private',
            null, Loop::ACCESS_REQUEST, null, $this->superAdmin,
        );

        // Deux Boucles, deux Organizations : chaque ligne porte SON URL
        // org-scopee — jamais celle d'une voisine, jamais la route courte.
        // Et le lien « Inviter » part vers bulk-create pre-scope sur
        // l'Organization de CETTE Boucle.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => 'all']))
            ->assertOk()
            ->assertSee(route('organization.loops.show', ['organization' => $this->orgB->slug, 'loop' => $this->loop->id]), false)
            ->assertSee(route('organization.loops.show', ['organization' => $this->orgA->slug, 'loop' => $loopA->id]), false)
            ->assertSee(route('admin.users.bulk-create', ['organization_id' => $this->orgB->id]), false)
            ->assertSee(route('admin.users.bulk-create', ['organization_id' => $this->orgA->id]), false);
    }

    public function test_the_member_selector_shows_the_full_identity(): void
    {
        User::factory()->create([
            'organization_id' => $this->orgB->id,
            'first_name' => 'Julien',
            'name' => 'Perrin',
            'email' => 'julien.perrin@example.test',
        ]);

        // « Prenom NOM — email · Organization » : le prenom manquait parce
        // que la colonne first_name n'etait pas chargee par le controller.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.edit', $this->loop))
            ->assertOk()
            ->assertSee('Julien Perrin — julien.perrin@example.test · '.$this->orgB->name);
    }

    public function test_the_member_selector_offers_only_users_of_the_loops_organization(): void
    {
        User::factory()->create([
            'organization_id' => $this->orgB->id,
            'email' => 'membre.ofsh@example.test',
        ]);
        User::factory()->create([
            'organization_id' => $this->orgA->id,
            'email' => 'etranger.orga@example.test',
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.edit', $this->loop))
            ->assertOk()
            ->assertSee('membre.ofsh@example.test')
            ->assertDontSee('etranger.orga@example.test');
    }

    public function test_a_forged_cross_org_member_request_is_refused_with_an_explicit_message(): void
    {
        $stranger = User::factory()->create(['organization_id' => $this->orgA->id]);

        // La garde serveur n'a pas bouge (Rule::exists same-org) ; seul le
        // message devient parlant. followingRedirects : le message doit etre
        // VISIBLE sur l'ecran, pas seulement en session.
        $this->followingRedirects()
            ->actingAs($this->superAdmin)
            ->from(route('admin.loops.edit', $this->loop))
            ->post(route('admin.loops.members.add', $this->loop), [
                'user_id' => $stranger->id,
                'role' => 'member',
            ])
            ->assertOk()
            ->assertSee(__('admin.loop_member_must_belong_to_org'));

        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $stranger->id,
        ]);
    }

    public function test_an_organization_without_assignable_users_shows_the_empty_state_and_the_invite_link(): void
    {
        // orgB n'a aucun utilisateur : l'etat vide remplace un selecteur
        // muet, et le lien part vers le workflow CANONIQUE d'invitation,
        // Organization deja pre-selectionnee.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.edit', $this->loop))
            ->assertOk()
            ->assertSee(__('admin.loop_no_assignable_users'))
            ->assertSee(route('admin.users.bulk-create', ['organization_id' => $this->orgB->id]), false);
    }

    public function test_the_template_screen_explains_an_absent_override_instead_of_a_mute_empty_list(): void
    {
        // Etat PROD reel : aucune surcharge organization_invitation n'existe
        // (le mailer retombe sur le Blade — comportement normal, audit T1681).
        // La page filtree doit le DIRE, pas afficher une liste vide muette.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', ['slug' => 'organization_invitation']))
            ->assertOk()
            ->assertSee(__('admin.system_email_no_override'))
            ->assertSee(__('admin.system_email_desc'));
    }

    public function test_the_template_screen_still_lists_existing_overrides(): void
    {
        \App\Models\SystemEmailTemplate::create([
            'organization_id' => $this->orgB->id,
            'slug' => 'organization_invitation',
            'locale' => 'fr',
            'name' => 'Invitation directe',
            'subject' => 'Votre accès à {{ organization_name }}',
            'content_html' => '<p>{{ invitation_url }}</p>',
            'variables' => ['invitation_url'],
            'enabled' => true,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', ['slug' => 'organization_invitation']))
            ->assertOk()
            ->assertSee('Invitation directe')
            ->assertDontSee(__('admin.system_email_no_override'));
    }

    public function test_bulk_create_links_to_the_filtered_template_screen_with_an_honest_label(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.users.bulk-create', ['organization_id' => $this->orgB->id]))
            ->assertOk()
            // Texte ecrit EN DUR dans la vue (hors {{ }}) : l'apostrophe y est
            // brute, alors qu'assertSee echappe l'aiguille — chercher la forme
            // brute (reciproque de la lecon T1672 sur les formes echappees).
            ->assertSee("Personnaliser le modèle d'email", false)
            ->assertSee(route('admin.system-email-templates', ['slug' => 'organization_invitation']), false);
    }

    public function test_the_default_system_template_is_visible_and_marked_in_use(): void
    {
        // Aucune surcharge DB : l'ecran montre quand meme LE modele (carte
        // « Modele systeme par defaut », « Utilise actuellement ») — l'admin
        // ne doit plus croire que le modele n'existe pas.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', ['slug' => 'organization_invitation']))
            ->assertOk()
            ->assertSee(__('admin.system_email_default_badge'))
            ->assertSee(__('admin.system_email_default_in_use'))
            ->assertSee(__('admin.system_email_create_customization'));
    }

    public function test_creating_a_customization_is_safe_disabled_and_prefilled(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.system-email-templates.store'), [
                'slug' => 'organization_invitation',
                'organization_id' => $this->orgB->id,
                'locale' => 'fr',
            ])
            ->assertRedirect();

        $row = DB::table('system_email_templates')
            ->where('slug', 'organization_invitation')
            ->where('organization_id', $this->orgB->id)
            ->where('locale', 'fr')
            ->first();

        $this->assertNotNull($row);
        // DESACTIVEE par defaut : la regression du 01/10 (HTML nu prenant le
        // pas d'office sur le Blade style) ne peut pas se reproduire.
        $this->assertFalse((bool) $row->enabled);
        // Pre-remplie depuis le catalogue canonique, pas vide.
        $this->assertStringContainsString('{{ organization_name }}', $row->subject);
        $this->assertNotSame('', trim($row->content_html));
    }

    public function test_creating_twice_never_duplicates(): void
    {
        $payload = [
            'slug' => 'organization_invitation',
            'organization_id' => $this->orgB->id,
            'locale' => 'fr',
        ];

        $this->actingAs($this->superAdmin)->post(route('admin.system-email-templates.store'), $payload)
            ->assertSessionHas('success');
        $this->actingAs($this->superAdmin)->post(route('admin.system-email-templates.store'), $payload)
            ->assertSessionHas('info');

        $this->assertSame(1, DB::table('system_email_templates')
            ->where('slug', 'organization_invitation')
            ->where('organization_id', $this->orgB->id)
            ->where('locale', 'fr')
            ->count());
    }

    public function test_a_disabled_customization_leaves_the_blade_fallback_in_charge(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $this->actingAs($this->superAdmin)->post(route('admin.system-email-templates.store'), [
            'slug' => 'organization_invitation',
            'organization_id' => $this->orgB->id,
            'locale' => 'fr',
        ]);

        // L'invitation part par le circuit reel : tant que la surcharge est
        // desactivee, le mailer reste sur le Blade — et le dit (email_logs).
        $this->actingAs($this->superAdmin)->post(route('admin.users.bulk-create.invitations.store'), [
            'organization_id' => $this->orgB->id,
            'locale' => 'fr',
            'people' => [
                ['first_name' => 'Nadia', 'last_name' => 'Renard', 'email' => 'nadia@example.test'],
            ],
        ]);

        $log = \App\Models\EmailLog::where('to_email', 'nadia@example.test')->firstOrFail();
        $this->assertSame('blade_fallback', $log->data['template_used']);
        $this->assertSame('template_disabled', $log->data['fallback_reason']);
    }

    public function test_an_enabled_override_flips_the_badge_and_keeps_the_existing_behavior(): void
    {
        \App\Models\SystemEmailTemplate::create([
            'organization_id' => $this->orgB->id,
            'slug' => 'organization_invitation',
            'locale' => 'fr',
            'name' => 'Invitation directe',
            'subject' => 'Sujet actif',
            'content_html' => '<p>{{ invitation_url }}</p>',
            'variables' => [],
            'enabled' => true,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', ['slug' => 'organization_invitation']))
            ->assertOk()
            ->assertSee(__('admin.system_email_default_in_use_partial'))
            ->assertSee('Invitation directe');
    }

    public function test_the_template_edit_screen_carries_a_sandboxed_wysiwyg_preview(): void
    {
        $template = \App\Models\SystemEmailTemplate::create([
            'organization_id' => $this->orgB->id,
            'slug' => 'organization_invitation',
            'locale' => 'fr',
            'name' => 'Invitation directe',
            'subject' => 'Sujet',
            'content_html' => '<h1>Bonjour</h1>',
            'variables' => [],
            'enabled' => false,
        ]);

        // L'apercu est une iframe SANDBOX sans allow-scripts : rien ne s'y
        // execute, le HTML brut devient seulement lisible en mise en forme.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates.edit', $template))
            ->assertOk()
            ->assertSee(__('admin.html_preview_title'))
            ->assertSee('data-html-preview', false)
            ->assertSee('sandbox=""', false);
    }

    public function test_nothing_implicit_happened_to_the_superadmin(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.loops.edit', $this->loop))->assertOk();

        $this->assertSame(0, DB::table('loop_members')->where('user_id', $this->superAdmin->id)->count());
        $this->assertSame($this->orgA->id, $this->superAdmin->fresh()->organization_id);
    }
}
