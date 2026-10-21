<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopRoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug N — changer un role depuis l'administration, sans chercher.
 *
 * ## Ce qui genait
 *
 * Les actions de gouvernance existaient deja et leurs gardes etaient justes,
 * mais elles vivaient dans un menu deroulant ouvert par un « … » SANS LIBELLE.
 * Sur un ecran ou l'on vient precisement pour agir, le changement de role etait
 * donc introuvable : « il n'est pas commode de gerer qui est proprietaire,
 * animateur ou membre » (recette Cyril, 21/10). Le meme glyphe a d'ailleurs ete
 * pris pour « un caractere etrange ».
 *
 * ## Ce qui a change, et ce qui n'a PAS change
 *
 * Une prop de PRESENTATION, `inlineActions`, sur le meme modele que `sections`
 * qui distinguait deja les ecrans larges du panneau etroit. Les deux ecrans
 * d'administration posent les actions en face du nom ; la Card Membres, etroite,
 * garde son menu.
 *
 * Aucune regle ne bouge : memes gardes calculees par le roster, memes routes,
 * meme formulaire, meme politique. C'est ce que verifient les tests de
 * non-regression ci-dessous — en particulier la garde du DERNIER
 * PROPRIETAIRE, qui est l'etat dont l'absence avait bloque la recette au
 * depart.
 */
class TASK1693AdminLoopRoleAgilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Loop $loop;

    private User $superAdmin;

    private User $proprietaire;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693n',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'access_mode' => Loop::ACCESS_REQUEST,
            'status' => 'active',
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $this->proprietaire = $this->membreDeBoucle(LoopRoleRegistry::OWNER);
        $this->membre = $this->membreDeBoucle(LoopRoleRegistry::MEMBER);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function membreDeBoucle(string $role): User
    {
        $user = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        LoopMember::query()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $user->id,
            'organization_id' => $this->organization->id,
            'role' => $role,
            'status' => 'active',
        ]);

        return $user;
    }

    private function fiche(): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.edit', $this->loop))
            ->assertOk()
            ->getContent();
    }

    /** Les actions sont VISIBLES en face du nom, plus cachees derriere un glyphe. */
    public function test_the_admin_card_shows_the_role_actions_next_to_each_name(): void
    {
        $html = $this->fiche();

        $this->assertStringContainsString(
            'data-governance-inline-actions="'.$this->membre->id.'"',
            $html,
            'les actions de role doivent etre posees en face du membre',
        );

        // Et ce sont bien des VERBES, pas un chevron : le libelle est rendu.
        $this->assertStringContainsString(__('loops.governance_promote_owner'), $html);
        $this->assertStringContainsString(__('loops.governance_promote_facilitator'), $html);
    }

    /** Le changement aboutit, et il est reflete dans la Boucle. */
    public function test_promoting_from_the_admin_is_reflected_in_the_loop(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [
                $this->loop,
                LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->membre->id)->sole(),
            ]), ['role' => LoopRoleRegistry::FACILITATOR])
            ->assertRedirect();

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->membre->id,
            'role' => LoopRoleRegistry::FACILITATOR,
            'status' => 'active',
        ]);

        // « Que les changements faits soient refletes dans la boucle » : on
        // mesure la Boucle elle-meme, pas seulement la colonne.
        $html = $this->actingAs($this->proprietaire)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->membre->publicDisplayName(), $html);
    }

    /**
     * NON-REGRESSION — la garde du DERNIER PROPRIETAIRE tient toujours.
     *
     * C'est l'etat dont l'absence avait bloque la recette au depart : une
     * Boucle sans proprietaire. Rendre les actions visibles ne doit pas rendre
     * ce retrait possible.
     */
    public function test_the_last_owner_is_offered_no_demotion_and_the_server_refuses_one(): void
    {
        $html = $this->fiche();

        $this->assertStringNotContainsString(
            'data-governance-inline-actions="'.$this->proprietaire->id.'"',
            $html,
            'le dernier proprietaire n\'a aucune action de role a lui offrir',
        );

        // Et le serveur refuse, meme en forcant la requete : l'ecran n'est pas
        // la seule garde.
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [
                $this->loop,
                LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->proprietaire->id)->sole(),
            ]), ['role' => LoopRoleRegistry::MEMBER]);

        // `assertDatabaseHas()` prend la CONNEXION en troisieme argument, pas
        // un message : on asserte donc la valeur, ou le message a sa place.
        $this->assertSame(
            LoopRoleRegistry::OWNER,
            LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->proprietaire->id)->sole()->role,
            'une Boucle ne doit jamais se retrouver sans proprietaire',
        );
    }

    /**
     * Un SECOND proprietaire debloque la retrogradation du premier.
     *
     * Sans ce test, le precedent pourrait rester vert parce que la
     * retrogradation est cassee pour tout le monde — un vert obtenu en
     * supprimant la capacite.
     */
    public function test_naming_a_second_owner_unlocks_the_demotion_of_the_first(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [
                $this->loop,
                LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->membre->id)->sole(),
            ]), ['role' => LoopRoleRegistry::OWNER])
            ->assertRedirect();

        $this->assertStringContainsString(
            'data-governance-inline-actions="'.$this->proprietaire->id.'"',
            $this->fiche(),
            'avec un second proprietaire, le premier redevient retrogradable',
        );

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [
                $this->loop,
                LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->proprietaire->id)->sole(),
            ]), ['role' => LoopRoleRegistry::MEMBER])
            ->assertRedirect();

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->proprietaire->id,
            'role' => LoopRoleRegistry::MEMBER,
        ]);
    }

    /**
     * NON-REGRESSION — la Card Membres, etroite, garde son menu.
     *
     * Le composant sert QUATRE ecrans. Changer sa presentation pour tous
     * aurait modifie une surface que personne n'a demandee, et le panneau
     * lateral d'une Boucle n'a pas la largeur de ces pastilles.
     */
    public function test_the_narrow_member_card_keeps_its_menu(): void
    {
        $html = $this->actingAs($this->proprietaire)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-governance-inline-actions=', $html,
            'la Card Membres garde le menu : elle n\'a pas la largeur des pastilles');
    }
}
