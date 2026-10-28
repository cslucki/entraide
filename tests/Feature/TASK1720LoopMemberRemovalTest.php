<?php

namespace Tests\Feature;

use App\Livewire\LoopMembersCard;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1720 — le proprietaire retire un membre depuis la Card Membres.
 *
 * ## Ce qui manquait, et ce qui ne manquait pas
 *
 * La capacite `loop_members.remove` est declaree dans
 * `config/loop_permissions.php`, accordee par defaut au role `owner`, et les
 * deux surfaces front la resolvaient deja dans `$governance['remove']`. Le
 * composant partage `governance-roster` porte l'entree de menu, son
 * formulaire `DELETE` et la garde du dernier proprietaire — les deux ecrans
 * d'administration s'en servent depuis TASK-1079.
 *
 * Il manquait deux choses, et deux seulement : une route FRONT, et le
 * branchement de la Card, qui passait `:can-remove="false"` et
 * `:remove-route="null"` EN DUR. Une permission declaree que l'interface
 * n'offrait a personne.
 *
 * ## Ce que ces gardes mesurent
 *
 * L'ecran d'abord — c'est la demande. Puis le SERVEUR, parce qu'une entree de
 * menu cachee n'est pas une garde : un formulaire rejoue arrive quand meme au
 * controleur. La frontiere de tenant et l'invariant du dernier proprietaire
 * sont verifies la ou ils refusent, pas la ou ils se devinent.
 */
#[Group('sensitive')]
class TASK1720LoopMemberRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $facilitator;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'retrait']);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->facilitator = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $loops = new LoopService;
        $this->loop = $loops->createLoop($this->owner, 'Boucle a nettoyer');
        $loops->addMember($this->loop, $this->facilitator, 'facilitator');
        $loops->addMember($this->loop, $this->member, 'member');
    }

    private function adhesion(User $user): LoopMember
    {
        return LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    // =====================================================================
    // A. L'ECRAN — la demande MASTER
    // =====================================================================

    /**
     * La capacite etait DEJA accordee au proprietaire : la garde le constate
     * au resolveur canonique avant de regarder le moindre pixel.
     *
     * Si cette assertion tombe un jour, le correctif n'est pas dans la vue
     * mais dans `config/loop_permissions.php` — et la distinction vaut la
     * ligne.
     */
    public function test_the_owner_already_holds_the_removal_capability_and_the_facilitator_does_not(): void
    {
        $resolver = app(LoopPermissionResolver::class);

        $this->assertTrue($resolver->can($this->owner, $this->loop, 'loop_members.remove'));
        $this->assertFalse($resolver->can($this->facilitator, $this->loop, 'loop_members.remove'));
        $this->assertFalse($resolver->can($this->member, $this->loop, 'loop_members.remove'));
    }

    /**
     * Le menu 3 points de la Card offre « Retirer un membre » au
     * proprietaire. ROUGIT sur l'etat d'avant : la Card passait
     * `:can-remove="false"` en dur, donc l'entree n'etait jamais rendue.
     *
     * L'assertion porte sur un MARQUEUR du formulaire, pas sur son URL ni sur
     * son libelle. Les deux autres formes ont ete mesurees fausses :
     *
     * - l'URL `/loop-members/{id}` est le PREFIXE de
     *   `/loop-members/{id}/role`, rendue juste a cote pour la meme personne.
     *   La garde restait VERTE avec `:can-remove="false"` : faux vert prouve
     *   par contre-epreuve.
     * - `loops.governance_remove` sert aussi aux deux ecrans
     *   d'administration, et une assertion de libelle passerait par
     *   coincidence de traduction.
     */
    public function test_the_members_card_offers_removal_to_the_owner(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])
            ->assertSee('data-governance-remove="'.$this->member->id.'"', escape: false);
    }

    /**
     * Et ne l'offre pas a un animateur, qui n'a pas la capacite.
     */
    public function test_the_members_card_hides_removal_from_a_facilitator(): void
    {
        $this->actingAs($this->facilitator);

        Livewire::test(LoopMembersCard::class, ['loop' => $this->loop])
            ->assertDontSee('data-governance-remove=', escape: false);
    }

    // =====================================================================
    // B. LE SERVEUR — ce que l'ecran cache, le composant doit le refuser
    // =====================================================================

    /**
     * `/livewire/update` est un point d'entree HTTP comme un autre : le
     * navigateur y poste le nom de la methode et ses parametres. Les gardes
     * sont donc mesurees LA, en appelant la methode directement, et non en
     * cliquant sur un bouton que l'ecran aurait pu ne pas rendre.
     */
    public function test_the_owner_removes_a_member_without_destroying_the_row(): void
    {
        $adhesion = $this->adhesion($this->member);

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('removeMember', $adhesion->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loop_members', ['id' => $adhesion->id, 'status' => 'left']);

        // Et la personne a bel et bien quitte le trombinoscope.
        $this->assertFalse(
            LoopMember::where('loop_id', $this->loop->id)
                ->where('user_id', $this->member->id)
                ->where('status', 'active')
                ->exists()
        );
    }

    /**
     * La Card ne se referme pas : le composant reste MONTE apres le retrait,
     * et son rendu suivant ne contient plus la personne retiree.
     *
     * C'est la demande de MASTER — « faire que la card membre ne se ferme
     * pas » — et c'est ce qu'un transport Livewire achete par rapport au
     * formulaire qui redirigeait. La garde mesure l'etat conserve (`segment`),
     * ce qu'une redirection aurait perdu.
     */
    public function test_the_card_stays_open_and_refreshes_in_place(): void
    {
        $adhesion = $this->adhesion($this->member);

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('selectSegment', 'members')
            ->call('removeMember', $adhesion->id)
            ->assertSet('segment', 'members')
            ->assertSee(__('loops.governance_removed'))
            ->assertDontSee('data-governance-remove="'.$this->member->id.'"', escape: false);
    }

    /**
     * Un animateur n'a pas la capacite : le refus vient du SERVEUR, pas de
     * l'absence d'un bouton.
     */
    public function test_a_facilitator_is_refused_by_the_server(): void
    {
        $adhesion = $this->adhesion($this->member);

        Livewire::actingAs($this->facilitator)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('removeMember', $adhesion->id)
            ->assertForbidden();

        $this->assertDatabaseHas('loop_members', ['id' => $adhesion->id, 'status' => 'active']);
    }

    public function test_a_simple_member_is_refused_by_the_server(): void
    {
        $adhesion = $this->adhesion($this->facilitator);

        Livewire::actingAs($this->member)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('removeMember', $adhesion->id)
            ->assertForbidden();

        $this->assertDatabaseHas('loop_members', ['id' => $adhesion->id, 'status' => 'active']);
    }

    /**
     * L'invariant `loop.last_owner` tient AU SERVEUR, pas seulement dans le
     * composant qui masque l'entree pour cette ligne.
     */
    public function test_the_last_owner_cannot_be_removed(): void
    {
        $adhesion = $this->adhesion($this->owner);

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('removeMember', $adhesion->id)
            ->assertSee(__('loops.governance_refused_last_owner'));

        $this->assertDatabaseHas('loop_members', ['id' => $adhesion->id, 'status' => 'active']);
    }

    /**
     * L'adhesion d'une AUTRE Boucle — donc d'un autre tenant — ne se retire
     * pas depuis cette Card, meme par un proprietaire legitime d'ici.
     *
     * ## Pourquoi cette forme, et pas un etranger qui attaque
     *
     * Le premier jet faisait agir un proprietaire d'une autre Organization.
     * La garde passait, mais un SABOTAGE de la borne de tenant la laissait
     * VERTE : ce n'etait pas elle qui refusait, c'etait la verification
     * d'appartenance en amont. Le test mesurait une autre garde que celle
     * qu'il pretendait couvrir.
     *
     * La vraie porte est ici : `LoopMember` n'est PAS un modele scope par
     * tenant, donc un identifiant d'adhesion etranger poste a
     * `/livewire/update` est bel et bien RESOLVABLE. Ce qui le refuse est le
     * `where loop_id` du composant, et rien d'autre.
     */
    public function test_a_membership_from_another_loop_is_refused(): void
    {
        $autre = Organization::factory()->create(['slug' => 'ailleurs']);
        $etranger = User::factory()->create(['organization_id' => $autre->id]);

        app()->instance('current_organization', $autre);
        $boucleEtrangere = (new LoopService)->createLoop($etranger, 'Sa propre Boucle');
        app()->instance('current_organization', $this->organization);

        $adhesionEtrangere = LoopMember::where('loop_id', $boucleEtrangere->id)
            ->where('user_id', $etranger->id)
            ->firstOrFail();

        // La ligne est bien resolvable : c'est ce qui rend la garde necessaire.
        $this->assertNotNull(LoopMember::find($adhesionEtrangere->id));

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('removeMember', $adhesionEtrangere->id)
            ->assertSee(__('loops.governance_refused'));

        $this->assertDatabaseHas('loop_members', [
            'id' => $adhesionEtrangere->id,
            'status' => 'active',
        ]);
    }
}
