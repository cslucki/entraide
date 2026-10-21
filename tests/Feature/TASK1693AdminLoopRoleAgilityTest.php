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

    // ── Bug O — transferer la propriete en un geste ─────────────────────────

    private function membreDeBoucleLigne(User $u): LoopMember
    {
        return LoopMember::where('loop_id', $this->loop->id)->where('user_id', $u->id)->sole();
    }

    /**
     * LE geste manquant : « on ne peut pas facilement supprimer le
     * proprietaire de la boucle ».
     *
     * Il fallait deux actions, dans deux endroits de l'ecran. La ligne du
     * dernier proprietaire offre desormais la question REELLE — « qui reprend
     * la Boucle ? » — plutot qu'une note expliquant qu'il est bloque.
     */
    public function test_the_last_owner_row_offers_a_transfer_rather_than_a_dead_end(): void
    {
        $html = $this->fiche();

        $this->assertStringContainsString(
            'data-governance-transfer="'.$this->proprietaire->id.'"',
            $html,
            'le dernier proprietaire doit pouvoir designer son successeur',
        );
        $this->assertStringContainsString('name="successor_id"', $html);
        $this->assertStringContainsString($this->membre->publicDisplayName(), $html);
    }

    /** Un seul geste, et l'invariant n'est jamais rompu. */
    public function test_transferring_swaps_the_two_roles_in_one_gesture(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.transfer-ownership', [
                $this->loop,
                $this->membreDeBoucleLigne($this->proprietaire),
            ]), ['successor_id' => $this->membreDeBoucleLigne($this->membre)->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(LoopRoleRegistry::OWNER, $this->membreDeBoucleLigne($this->membre)->role);
        $this->assertSame(LoopRoleRegistry::MEMBER, $this->membreDeBoucleLigne($this->proprietaire)->role);

        // L'invariant, apres coup : exactement UN proprietaire actif.
        $this->assertSame(1, LoopMember::where('loop_id', $this->loop->id)
            ->where('role', LoopRoleRegistry::OWNER)->where('status', 'active')->count());
    }

    /**
     * Le sortant RESTE dans la Boucle — « supprimer le proprietaire » est
     * ambigu, et on fait le geste REVERSIBLE.
     *
     * S'il doit aussi en sortir, « Retirer » devient disponible sur sa ligne
     * des l'instant ou il n'est plus le dernier proprietaire : les deux gestes
     * se composent.
     */
    public function test_the_outgoing_owner_stays_in_the_loop_and_becomes_removable(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.transfer-ownership', [
                $this->loop,
                $this->membreDeBoucleLigne($this->proprietaire),
            ]), ['successor_id' => $this->membreDeBoucleLigne($this->membre)->id])
            ->assertRedirect();

        $this->assertSame('active', $this->membreDeBoucleLigne($this->proprietaire)->status,
            'le sortant reste membre : le geste est reversible');

        // Et il est desormais retirable, ce qu'il n'etait pas.
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loops.members.remove', [
                $this->loop,
                $this->membreDeBoucleLigne($this->proprietaire),
            ]))
            ->assertRedirect();

        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->proprietaire->id,
            'status' => 'active',
        ]);
    }

    /**
     * Le successeur doit appartenir a CETTE Boucle.
     *
     * L'identifiant vient d'un `select` que rien n'empeche de forger : sans ce
     * controle, on promouvrait le membre d'une autre Boucle — et on
     * retrograderait le proprietaire de celle-ci au passage.
     */
    public function test_a_successor_from_another_loop_is_refused_and_nothing_moves(): void
    {
        $autreBoucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'status' => 'active',
        ]);
        $etranger = LoopMember::query()->create([
            'loop_id' => $autreBoucle->id,
            'user_id' => User::factory()->complete()->create(['organization_id' => $this->organization->id])->id,
            'organization_id' => $this->organization->id,
            'role' => LoopRoleRegistry::MEMBER,
            'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.transfer-ownership', [
                $this->loop,
                $this->membreDeBoucleLigne($this->proprietaire),
            ]), ['successor_id' => $etranger->id])
            ->assertNotFound();

        $this->assertSame(LoopRoleRegistry::OWNER, $this->membreDeBoucleLigne($this->proprietaire)->role,
            'un successeur invalide ne doit RIEN deplacer');
        $this->assertSame(LoopRoleRegistry::MEMBER, $etranger->fresh()->role);
    }

    /** Pas de transfert a offrir quand il n'y a personne a qui transferer. */
    public function test_no_transfer_is_offered_when_the_owner_is_alone(): void
    {
        $this->membreDeBoucleLigne($this->membre)->delete();

        $this->assertStringNotContainsString(
            'data-governance-transfer=',
            $this->fiche(),
            'un formulaire sans candidat ne peut qu\'echouer : ne pas l\'offrir',
        );
    }

    /**
     * TASK-1693 bug P — « il ne faut pas qu'un bouton Transferer, il faut
     * aussi un bouton qui le repasse comme membre, tout simplement »
     * (arbitrage MASTER du 21/10 : « option B, et on garde A »).
     *
     * Le transfert repond a « qui reprend la Boucle ? ». Mais parfois la
     * reponse est « personne » — on veut simplement sortir quelqu'un de la
     * propriete, et la Boucle restera gouvernee depuis l'administration. Le
     * transfert ne couvrait pas ce cas, et il ne pouvait meme pas etre offert
     * quand la Boucle n'a aucun autre membre.
     */
    public function test_the_last_owner_row_also_offers_a_plain_demotion(): void
    {
        $html = $this->fiche();

        $this->assertStringContainsString(
            'data-governance-demote-last-owner="'.$this->proprietaire->id.'"',
            $html,
        );
        $this->assertStringContainsString('name="allow_last_owner"', $html);
    }

    /** Et il est offert MEME quand il n'y a personne a qui transferer. */
    public function test_the_plain_demotion_is_offered_even_with_nobody_to_transfer_to(): void
    {
        $this->membreDeBoucleLigne($this->membre)->delete();

        $html = $this->fiche();

        $this->assertStringNotContainsString('data-governance-transfer=', $html);
        $this->assertStringContainsString(
            'data-governance-demote-last-owner="'.$this->proprietaire->id.'"',
            $html,
            'c\'est precisement le cas que le transfert ne peut pas servir',
        );
    }

    /** Le geste ABOUTIT : la Boucle se retrouve sans proprietaire, assume. */
    public function test_the_plain_demotion_leaves_the_loop_without_an_owner(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [
                $this->loop,
                $this->membreDeBoucleLigne($this->proprietaire),
            ]), ['role' => LoopRoleRegistry::MEMBER, 'allow_last_owner' => '1'])
            ->assertRedirect();

        $this->assertSame(
            LoopRoleRegistry::MEMBER,
            $this->membreDeBoucleLigne($this->proprietaire)->role,
        );
        $this->assertSame(
            0,
            LoopMember::where('loop_id', $this->loop->id)
                ->where('status', 'active')
                ->where('role', LoopRoleRegistry::OWNER)
                ->count(),
            'la consequence est nommee dans la confirmation : la Boucle devient orpheline',
        );
    }

    /**
     * LA non-regression de ce correctif.
     *
     * La derogation doit etre DEMANDEE. Sans le champ, l'invariant tient
     * exactement comme avant — sinon ce bug P aurait simplement supprime la
     * garde du dernier proprietaire pour tous les appelants, dont la Card
     * Membres cote front, qui ne doit pas pouvoir orpheliner une Boucle.
     */
    public function test_an_ordinary_role_change_is_still_refused_for_the_last_owner(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [
                $this->loop,
                $this->membreDeBoucleLigne($this->proprietaire),
            ]), ['role' => LoopRoleRegistry::MEMBER]);

        $this->assertSame(
            LoopRoleRegistry::OWNER,
            $this->membreDeBoucleLigne($this->proprietaire)->role,
            'sans `allow_last_owner`, le refus est inchange',
        );
    }

    /** Et le refus reste entier sur l'ecran etroit du front, qui ne l'offre pas. */
    public function test_the_front_member_card_cannot_orphan_a_loop_even_by_forcing_the_field(): void
    {
        $this->actingAs($this->proprietaire)
            ->put(route('loops.members.role', $this->membreDeBoucleLigne($this->proprietaire)), [
                'role' => LoopRoleRegistry::MEMBER,
                'allow_last_owner' => '1',
            ]);

        $this->assertSame(
            LoopRoleRegistry::OWNER,
            $this->membreDeBoucleLigne($this->proprietaire)->role,
            'seuls les deux ecrans d\'administration portent la derogation',
        );
    }

    /** Apres retrogradation, « Retirer » devient disponible par composition. */
    public function test_the_demoted_owner_becomes_removable(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [
                $this->loop,
                $this->membreDeBoucleLigne($this->proprietaire),
            ]), ['role' => LoopRoleRegistry::MEMBER, 'allow_last_owner' => '1'])
            ->assertRedirect();

        $this->assertStringContainsString(
            'data-governance-inline-actions="'.$this->proprietaire->id.'"',
            $this->fiche(),
            'une fois simple membre, il a les actions d\'un simple membre',
        );
    }
}
