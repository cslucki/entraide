<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\PersonalDocumentsRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Partages par moi » liste ce que je GOUVERNE et qui porte un partage
 * EXPLICITE.
 *
 * ## Le defaut
 *
 * La requete `par-moi` demandait `owner_id = $userId` ET `parent_id IS NULL`.
 * Or un Dossier range sous « Mes documents » n'a **ni l'un ni l'autre** : son
 * `owner_id` est NULL (seule la racine porte le proprietaire) et son
 * `parent_id` pointe la racine. Les deux filtres l'excluaient donc chacun
 * separement. m3 partageait « Dossier de main member 3 » avec m4, m4 le voyait
 * bien dans « Avec moi », et m3 ne le voyait jamais dans « Par moi ».
 *
 * Ce que la requete decrivait, ce n'etait pas la gouvernance mais « la racine
 * que je possede ». La gouvernance se lit avec `governingDossier()` — la
 * primitive que la policy et la vue consultent deja.
 *
 * ## La doctrine que ces tests gardent
 *
 * - la gouvernance (`governingDossier()`) dit QUI possede, donc qui partage ;
 * - l'ancre de partage reste le Dossier explicitement partage : un descendant
 *   qui ne fait qu'heriter n'a pas de ligne `dossier_members` et n'entre pas
 *   dans « Par moi » ;
 * - deux ancres explicites coexistent sans se masquer ;
 * - une racine `personal_documents` n'est jamais listee comme partagee ;
 * - aucune fuite inter-Organization ;
 * - les Dossiers de Boucle gardent leur comportement.
 */
class TASK1142SharedByMeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    /** m3 — proprietaire, celui qui partage. */
    private User $m3;

    /** m4 — invite, celui qui recoit. */
    private User $m4;

    /** La racine `personal_documents` de m3. */
    private Dossier $racine;

    /** « Dossier de main member 3 » : enfant de la racine, `owner_id` NULL. */
    private Dossier $a;

    /** Un descendant de A, jamais partage explicitement. */
    private Dossier $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->m3 = User::factory()->create(['organization_id' => $this->org->id]);
        $this->m4 = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->racine = app(PersonalDocumentsRoot::class)->resolve($this->org->id, $this->m3->id);
        $this->a = $this->sousDossier($this->racine, 'Dossier de main member 3');
        $this->b = $this->sousDossier($this->a, 'Sous-dossier herite');
    }

    private function sousDossier(Dossier $parent, string $nom): Dossier
    {
        return Dossier::create([
            'organization_id' => $parent->organization_id,
            'parent_id' => $parent->id,
            'name' => $nom,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    /** Le geste reel : le proprietaire partage un Dossier avec quelqu'un. */
    private function partager(Dossier $cible, User $invite, string $role = DossierMember::ROLE_READER): void
    {
        $this->actingAs($this->m3)->postJson(
            route('organization.dossiers.members.store', [
                'organization' => $this->org->slug,
                'dossier' => $cible->getKey(),
            ]),
            ['user_id' => $invite->getKey(), 'role' => $role],
        )->assertSuccessful();
    }

    private function retirer(Dossier $cible, User $invite): void
    {
        $this->actingAs($this->m3)->deleteJson(
            route('organization.dossiers.members.destroy', [
                'organization' => $this->org->slug,
                'dossier' => $cible->getKey(),
                'member' => $invite->getKey(),
            ])
        )->assertSuccessful();
    }

    /**
     * Les identifiants reellement listes dans une des deux sous-vues.
     *
     * On lit la donnee de vue et non le HTML : « absent » doit vouloir dire
     * absent de la liste, pas seulement invisible parce qu'un nom se trouvait
     * tronque ou repris ailleurs dans la page.
     *
     * @return list<string>
     */
    private function lignes(User $acteur, string $vue): array
    {
        $reponse = $this->actingAs($acteur)->get(route('organization.dossiers.index', [
            'organization' => $this->org->slug,
            'espace' => 'partages',
            'vue' => $vue,
        ]));

        $reponse->assertOk()->assertViewHas('espace', 'partages');

        return $reponse->viewData($vue === 'par-moi' ? 'parMoi' : 'avecMoi')
            ->pluck('id')
            ->all();
    }

    // =====================================================================
    // 1 a 3 — le scenario reel du banc m3/m4
    // =====================================================================

    public function test_a_child_dossier_shared_by_its_governing_owner_is_listed_in_shared_by_me(): void
    {
        $this->partager($this->a, $this->m4);

        // Le coeur du defaut : A n'a ni `owner_id` ni `parent_id` NULL, et
        // c'est pourtant bien m3 qui le gouverne donc qui le partage.
        $this->assertNull($this->a->fresh()->owner_id);
        $this->assertNotNull($this->a->fresh()->parent_id);
        $this->assertSame($this->m3->id, $this->a->fresh()->governingDossier()->owner_id);

        $this->assertContains($this->a->id, $this->lignes($this->m3, 'par-moi'));
    }

    public function test_the_invited_member_sees_the_dossier_in_shared_with_me(): void
    {
        $this->partager($this->a, $this->m4);

        $this->assertContains($this->a->id, $this->lignes($this->m4, 'avec-moi'));
    }

    public function test_the_invited_member_never_sees_the_dossier_in_shared_by_me(): void
    {
        $this->partager($this->a, $this->m4);

        // m4 recoit le partage, il ne le donne pas : la gouvernance reste m3.
        $this->assertNotContains($this->a->id, $this->lignes($this->m4, 'par-moi'));
    }

    // =====================================================================
    // 4 et 5 — direct contre herite
    // =====================================================================

    public function test_a_descendant_that_only_inherits_is_not_listed(): void
    {
        $this->partager($this->a, $this->m4);

        // B herite de l'acces via A, sans ligne `dossier_members` a lui.
        $this->assertSame(0, $this->b->dossierMembers()->count());

        $parMoi = $this->lignes($this->m3, 'par-moi');
        $this->assertContains($this->a->id, $parMoi);
        $this->assertNotContains($this->b->id, $parMoi);
    }

    public function test_a_descendant_with_its_own_explicit_share_is_listed_on_its_own(): void
    {
        $autreInvite = User::factory()->create(['organization_id' => $this->org->id]);

        $this->partager($this->a, $this->m4);
        $this->partager($this->b, $autreInvite);

        // Deux ancres explicites coexistent : B ne masque pas A, A n'absorbe
        // pas B.
        $parMoi = $this->lignes($this->m3, 'par-moi');
        $this->assertContains($this->a->id, $parMoi);
        $this->assertContains($this->b->id, $parMoi);
    }

    // =====================================================================
    // 6 — le retrait fait disparaitre la ligne
    // =====================================================================

    public function test_removing_the_last_direct_member_removes_the_dossier_from_shared_by_me(): void
    {
        $this->partager($this->a, $this->m4);
        $this->assertContains($this->a->id, $this->lignes($this->m3, 'par-moi'));

        $this->retirer($this->a, $this->m4);

        $this->assertSame(0, $this->a->dossierMembers()->count());
        $this->assertNotContains($this->a->id, $this->lignes($this->m3, 'par-moi'));
    }

    // =====================================================================
    // 7 — gouvernance en profondeur, `owner_id` NULL a chaque niveau
    // =====================================================================

    public function test_governance_is_resolved_through_the_whole_ancestry(): void
    {
        $c = $this->sousDossier($this->b, 'Petit-fils');
        $this->partager($c, $this->m4);

        // Aucun des trois niveaux ne porte `owner_id` : seule la racine le
        // porte, et c'est elle qui gouverne.
        $this->assertNull($c->fresh()->owner_id);
        $this->assertSame($this->m3->id, $c->fresh()->governingDossier()->owner_id);

        $this->assertContains($c->id, $this->lignes($this->m3, 'par-moi'));
    }

    // =====================================================================
    // 8 — la racine systeme n'est jamais une ligne partagee
    // =====================================================================

    public function test_the_personal_documents_root_is_never_listed_as_shared_by_me(): void
    {
        $this->partager($this->a, $this->m4);

        $parMoi = $this->lignes($this->m3, 'par-moi');
        $this->assertNotContains($this->racine->id, $parMoi);

        // Meme si une ligne `dossier_members` existait sur la racine — etat que
        // la policy refuse d'ecrire — « Mes documents » ne devient pas un
        // Dossier partage : personal_documents n'est jamais une ancre.
        DossierMember::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->racine->id,
            'user_id' => $this->m4->id,
            'role' => DossierMember::ROLE_READER,
            'added_by' => $this->m3->id,
        ]);

        $this->assertNotContains($this->racine->id, $this->lignes($this->m3, 'par-moi'));
    }

    // =====================================================================
    // 9 — aucune fuite inter-Organization
    // =====================================================================

    public function test_a_dossier_from_another_organization_never_leaks(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->autreOrg->id]);
        $racineEtrangere = app(PersonalDocumentsRoot::class)->resolve($this->autreOrg->id, $etranger->id);
        $dossierEtranger = $this->sousDossier($racineEtrangere, 'Dossier etranger');

        // La donnee etrangere est reellement candidate : elle porte un partage
        // explicite, exactement comme A. Seule la garde de tenant l'ecarte.
        DossierMember::create([
            'organization_id' => $this->autreOrg->id,
            'dossier_id' => $dossierEtranger->id,
            'user_id' => $etranger->id,
            'role' => DossierMember::ROLE_READER,
            'added_by' => $etranger->id,
        ]);

        $this->partager($this->a, $this->m4);

        $parMoi = $this->lignes($this->m3, 'par-moi');
        $this->assertContains($this->a->id, $parMoi);
        $this->assertNotContains($dossierEtranger->id, $parMoi);
        $this->assertNotContains($racineEtrangere->id, $parMoi);
    }

    // =====================================================================
    // 10 — les Dossiers de Boucle ne changent pas
    // =====================================================================

    public function test_a_loop_dossier_is_never_listed_as_shared_by_me(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
            'type' => 'general',
            'created_by' => $this->m3->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->m3->id, 'joined_at' => now(),
        ]);

        $racineBoucle = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'loop_id' => $loop->id,
            'name' => 'Documents',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);
        $enfantBoucle = $this->sousDossier($racineBoucle, 'Communication');

        // Une Boucle n'est pas un proprietaire : meme portant un membre
        // explicite, un Dossier gouverne par une Boucle n'est le partage
        // personnel de personne.
        DossierMember::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $enfantBoucle->id,
            'user_id' => $this->m4->id,
            'role' => DossierMember::ROLE_READER,
            'added_by' => $this->m3->id,
        ]);

        $parMoi = $this->lignes($this->m3, 'par-moi');
        $this->assertNotContains($racineBoucle->id, $parMoi);
        $this->assertNotContains($enfantBoucle->id, $parMoi);
    }

    /**
     * CAS B : un Dossier personnel partage avec une Boucle entiere.
     *
     * Il n'a aucune ligne `dossier_members` — le partage vit dans
     * `shared_with_loop_id`. Il doit rester liste.
     */
    public function test_a_dossier_shared_with_a_loop_stays_listed(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
            'type' => 'general',
            'created_by' => $this->m3->id,
        ]);

        $this->a->update(['shared_with_loop_id' => $loop->id]);

        $this->assertSame(0, $this->a->dossierMembers()->count());
        $this->assertContains($this->a->id, $this->lignes($this->m3, 'par-moi'));
    }

    /**
     * Un dossier partage porte le meme marqueur d'icone qu'ailleurs.
     *
     * Il l'avait dans « Mes documents » et pas dans « Partages » : le meme
     * dossier changeait d'apparence selon l'ecran ou on le rencontrait
     * (TASK-1146).
     */
    public function test_a_listed_dossier_carries_the_shared_marker(): void
    {
        $this->partager($this->a, $this->m4);

        foreach (['par-moi' => $this->m3, 'avec-moi' => $this->m4] as $vue => $acteur) {
            $this->actingAs($acteur)
                ->get(route('organization.dossiers.index', [
                    'organization' => $this->org->slug,
                    'espace' => 'partages',
                    'vue' => $vue,
                ]))
                ->assertOk()
                ->assertSee(__('dossiers.share_shared_badge'));
        }
    }

    /**
     * La regression que le correctif ne doit pas introduire : une racine
     * ordinaire possedee (racine heritee d'avant « Mes documents ») reste
     * listee quand elle porte un partage.
     */
    public function test_an_owned_legacy_root_stays_listed(): void
    {
        $racineHeritee = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->m3->id,
            'name' => 'Ancienne racine',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->partager($racineHeritee, $this->m4);

        $this->assertContains($racineHeritee->id, $this->lignes($this->m3, 'par-moi'));
    }
}
