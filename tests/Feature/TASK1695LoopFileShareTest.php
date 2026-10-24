<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\DossierFileLoopShare;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1695 (point 5a de Cyril) — partager un fichier dans une BOUCLE.
 *
 * ## Le destinataire est la Boucle, pas ses membres du moment
 *
 * Aucune ligne par membre : l'accès se résout à la LECTURE (membre actif,
 * policy). Un arrivant voit le fichier dès son entrée, un partant le perd en
 * sortant — sans qu'aucune ligne ne soit écrite ni effacée.
 *
 * ## Trois bornes à l'écriture
 *
 * La Boucle appartient à l'Organization DU FICHIER ; le partageur en est
 * membre ACTIF (« la liste des boucles dont on est membre » — Cyril) ; la
 * Boucle est vivante.
 */
class TASK1695LoopFileShareTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private User $membre;

    private $loop;

    private Dossier $dossier;

    private DossierFile $fichier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1695l', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->proprietaire, 'Boucle partage 1695', type: 'project');
        $service->addMember($this->loop, $this->membre, 'member');

        // Le fichier vit dans un Dossier PERSONNEL du proprietaire : c'est le
        // cas nominal — on partage vers la Boucle un document qui n'y est pas.
        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);
        $this->fichier = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
        ]);
    }

    private function url(string $quoi, array $extra = []): string
    {
        return route('organization.dossiers.files.shares.'.$quoi, array_merge([
            'organization' => $this->organization->slug,
            'dossier' => $this->dossier->id,
            'file' => $this->fichier->id,
        ], $extra));
    }

    /** LE geste : partager vers la Boucle ouvre la lecture a ses membres. */
    public function test_sharing_into_a_loop_grants_read_to_its_members(): void
    {
        $this->assertFalse($this->membre->can('viewFiles', $this->fichier));

        $this->actingAs($this->proprietaire)
            ->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id])
            ->assertStatus(201);

        $this->assertTrue($this->membre->fresh()->can('viewFiles', $this->fichier));
    }

    /** Un membre QUITTE la Boucle : l'acces part avec lui, sans ecriture. */
    public function test_a_member_who_leaves_the_loop_loses_the_access(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);
        $this->assertTrue($this->membre->fresh()->can('viewFiles', $this->fichier));

        \DB::table('loop_members')
            ->where('loop_id', $this->loop->id)
            ->where('user_id', $this->membre->id)
            ->update(['status' => 'inactive']);

        $this->assertFalse($this->membre->fresh()->can('viewFiles', $this->fichier));
    }

    /** Et un ARRIVANT la gagne, sans qu'on retouche le partage. */
    public function test_a_newcomer_gains_the_access_without_any_new_share(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $arrivant = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->assertFalse($arrivant->can('viewFiles', $this->fichier));

        (new LoopService)->addMember($this->loop->fresh(), $arrivant, 'member');

        $this->assertSame(1, DossierFileLoopShare::query()->count());
        $this->assertTrue($arrivant->fresh()->can('viewFiles', $this->fichier));
    }

    /**
     * On ne partage pas vers une Boucle dont on n'est PAS membre.
     *
     * Gérer le fichier ne suffit pas : on n'expose pas un document à une
     * assemblée dont on ne fait pas partie.
     */
    public function test_one_cannot_share_into_a_loop_they_do_not_belong_to(): void
    {
        $autreOwner = User::factory()->create(['organization_id' => $this->organization->id]);
        $autreLoop = (new LoopService)->createLoop($autreOwner, 'Boucle etrangere 1695', type: 'project');

        $this->actingAs($this->proprietaire)
            ->postJson($this->url('loops.store'), ['loop_id' => $autreLoop->id])
            ->assertStatus(422);

        $this->assertSame(0, DossierFileLoopShare::query()->count());
    }

    /** La Boucle d'une AUTRE Organization n'est jamais une cible valide. */
    public function test_a_loop_from_another_organization_is_never_a_target(): void
    {
        $autreOrg = Organization::factory()->create();
        $ownerAilleurs = User::factory()->create(['organization_id' => $autreOrg->id]);
        app()->instance('current_organization', $autreOrg);
        $loopAilleurs = (new LoopService)->createLoop($ownerAilleurs, 'Boucle hors tenant', type: 'project');
        app()->instance('current_organization', $this->organization);

        $this->actingAs($this->proprietaire)
            ->postJson($this->url('loops.store'), ['loop_id' => $loopAilleurs->id])
            ->assertStatus(422);
    }

    /**
     * Une ligne FORGÉE vers une Boucle d'un autre tenant n'ouvre rien.
     *
     * La borne d'écriture ci-dessus ne protège que le chemin HTTP. Celle-ci
     * tient la LECTURE : même posée en base, la ligne ne traverse pas la
     * cloison — même preuve par forçage que pour les partages nominatifs.
     */
    public function test_a_forged_cross_tenant_loop_share_opens_nothing(): void
    {
        $autreOrg = Organization::factory()->create();
        $ownerAilleurs = User::factory()->create(['organization_id' => $autreOrg->id]);
        app()->instance('current_organization', $autreOrg);
        $loopAilleurs = (new LoopService)->createLoop($ownerAilleurs, 'Boucle forgee', type: 'project');
        app()->instance('current_organization', $this->organization);

        DossierFileLoopShare::query()->create([
            'organization_id' => $this->fichier->organization_id,
            'dossier_file_id' => $this->fichier->id,
            'loop_id' => $loopAilleurs->id,
            'shared_by' => $this->proprietaire->id,
        ]);

        // L'etranger au tenant : refuse des `tenantOk()`, AVANT la clause.
        $this->assertFalse($ownerAilleurs->fresh()->can('viewFiles', $this->fichier));

        // Le cas que la clause defend VRAIMENT — ma premiere version du test
        // s'arretait a l'etranger ci-dessus, que `tenantOk()` refuse deja :
        // retirer la clause laissait le test vert, le sabotage l'a montre.
        //
        // Ici : un lecteur DU BON tenant, dont la ligne d'adhesion pointe vers
        // la Boucle d'un AUTRE tenant (ligne forgee elle aussi). Sans la
        // clause `loop.organization_id = file.organization_id`, ces deux
        // falsifications combinees ouvriraient la lecture.
        $lecteurIci = User::factory()->create(['organization_id' => $this->organization->id]);
        \DB::table('loop_members')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'loop_id' => $loopAilleurs->id,
            'user_id' => $lecteurIci->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse($lecteurIci->fresh()->can('viewFiles', $this->fichier));
    }

    /** Un simple LECTEUR du fichier ne le partage pas vers une Boucle. */
    public function test_a_mere_reader_cannot_share_into_a_loop(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('store'), ['user_id' => $this->membre->id]);

        $this->actingAs($this->membre)
            ->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id])
            ->assertForbidden();
    }

    /** Retirer le partage referme la lecture pour TOUS les membres. */
    public function test_removing_the_loop_share_closes_the_access(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);
        $share = DossierFileLoopShare::query()->sole();

        $this->actingAs($this->proprietaire)
            ->deleteJson($this->url('loops.destroy', ['share' => $share->id]))
            ->assertOk();

        $this->assertFalse($this->membre->fresh()->can('viewFiles', $this->fichier));
    }

    /** La fenetre recoit mes Boucles — et pas celles ou je ne suis pas. */
    public function test_the_dialog_lists_only_my_loops(): void
    {
        $autreOwner = User::factory()->create(['organization_id' => $this->organization->id]);
        (new LoopService)->createLoop($autreOwner, 'Boucle sans moi 1695', type: 'project');

        $json = $this->actingAs($this->proprietaire)
            ->getJson($this->url('index'))
            ->assertOk()
            ->json();

        $noms = collect($json['loops'])->pluck('name');
        $this->assertTrue($noms->contains('Boucle partage 1695'));
        $this->assertFalse($noms->contains('Boucle sans moi 1695'));
    }

    /** Partager deux fois ne cree qu'une ligne. */
    public function test_sharing_twice_into_the_same_loop_is_idempotent(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id])->assertStatus(201);
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id])->assertStatus(200);

        $this->assertSame(1, DossierFileLoopShare::query()->count());
    }
}
