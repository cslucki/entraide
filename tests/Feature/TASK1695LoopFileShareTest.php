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

    /**
     * L'entrée « Partager dans une boucle » existe — pour qui GÈRE.
     *
     * Elle ouvre la même fenêtre que « Partager » : une seule fenêtre porte
     * les deux natures, deux fenêtres auraient divergé. L'entrée distincte
     * existe parce que c'est elle qu'on cherche des yeux quand on pense
     * « Boucle » (demande de Cyril).
     */
    public function test_the_menu_entry_exists_for_managers_only(): void
    {
        $page = fn (User $qui) => $this->actingAs($qui)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-file-share-loop-open', $page($this->proprietaire));
        $this->assertStringContainsString('data-file-share-loops', $page($this->proprietaire));
    }

    /**
     * La charge utile de la liste porte le compte des partages de BOUCLE.
     *
     * La colonne « Partagé » doit s'allumer pour un fichier partagé SEULEMENT
     * vers une Boucle — sans ce champ, elle dirait « Aucun » sur un fichier
     * que toute une Boucle peut lire.
     */
    public function test_the_file_list_carries_the_loop_share_count(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $json = $this->actingAs($this->proprietaire)
            ->getJson(route('organization.dossiers.files.index', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
            ]))
            ->assertOk()
            ->json();

        $ligne = collect(data_get($json, 'files.data', data_get($json, 'data', [])))
            ->firstWhere('id', $this->fichier->id);

        $this->assertSame(1, $ligne['loop_shares_count']);
        $this->assertSame(0, $ligne['shares_count']);
    }

    /** La fenêtre reçoit les partages de Boucle EN PLACE, avec le nom. */
    public function test_the_dialog_payload_carries_current_loop_shares(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $json = $this->actingAs($this->proprietaire)
            ->getJson($this->url('index'))
            ->assertOk()
            ->json();

        $this->assertSame('Boucle partage 1695', $json['loop_shares'][0]['name']);
        // Et la Boucle déjà servie quitte la liste des cibles proposées.
        $this->assertSame([], $json['loops']);
    }

    private function espace(User $qui, string $vue = 'avec-moi'): string
    {
        return $this->actingAs($qui)
            ->get(route('organization.dossiers.index', [
                'organization' => $this->organization->slug,
                'espace' => 'partages',
                'vue' => $vue,
            ]))
            ->assertOk()
            ->getContent();
    }

    /**
     * Le membre de la Boucle TROUVE le fichier dans « Partagés ».
     *
     * Même leçon qu'au geste E : une capacité qu'aucun écran n'expose est une
     * capacité absente. Sans cette ligne, le membre a le droit de lire — et
     * aucun chemin vers le fichier.
     */
    public function test_a_loop_member_finds_the_file_in_his_espace(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $html = $this->espace($this->membre);

        $this->assertStringContainsString(e($this->fichier->nomLisible()), $html);
        // Et la colonne nomme la BOUCLE comme chemin d'accès — pas un
        // « Utilisateur désactivé » faute de ligne nominative.
        $this->assertStringContainsString('Boucle partage 1695', $html);
    }

    /** Celui qui a partagé le voit sous « Par moi », au nom de la Boucle. */
    public function test_the_sharer_sees_it_under_shared_by_me(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $html = $this->espace($this->proprietaire, 'par-moi');

        $this->assertStringContainsString(e($this->fichier->nomLisible()), $html);
        $this->assertStringContainsString('Boucle partage 1695', $html);
    }

    /** Un NON-membre de la Boucle ne voit rien dans son espace. */
    public function test_a_non_member_sees_nothing(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->assertStringNotContainsString(
            e($this->fichier->nomLisible()),
            $this->espace($etranger),
        );
    }

    /** Et le lien du membre FONCTIONNE : la route dédiée sert le fichier. */
    public function test_the_member_link_actually_serves_the_file(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('dossiers/1695l/f.pdf', 'CONTENU_1695L');
        $this->fichier->update(['disk' => 'local', 'path' => 'dossiers/1695l/f.pdf']);

        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $this->actingAs($this->membre)
            ->get(route('organization.dossiers.shared.preview', [
                'organization' => $this->organization->slug,
                'file' => $this->fichier->id,
            ]))
            ->assertSuccessful();
    }

    /**
     * « Arrêter le partage » emporte AUSSI les partages de Boucle.
     *
     * L'écran montre une ligne par fichier, toutes natures confondues :
     * n'arrêter que les nominatifs rendrait 404 sur un fichier partagé
     * seulement vers une Boucle — un bouton visible qui refuse son geste.
     */
    public function test_stop_sharing_also_removes_loop_shares(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);
        $this->assertTrue($this->membre->fresh()->can('viewFiles', $this->fichier));

        $this->actingAs($this->proprietaire)
            ->delete(route('organization.dossiers.shared.stop', [
                'organization' => $this->organization->slug,
                'file' => $this->fichier->id,
            ]))
            ->assertRedirect();

        $this->assertSame(0, DossierFileLoopShare::query()->count());
        $this->assertFalse($this->membre->fresh()->can('viewFiles', $this->fichier));
    }

    /**
     * On ne REÇOIT pas ce qu'on a soi-même partagé.
     *
     * Vu au navigateur (24/10, recette de Cyril) : user2, membre de la Boucle
     * cible, « recevait » son propre LevantinDoc.pdf sous « Reçus ». Le
     * partageur est destinataire de son propre geste dès qu'il est membre —
     * c'est mécaniquement vrai et humainement absurde.
     */
    public function test_the_sharer_does_not_receive_their_own_loop_share(): void
    {
        // Le proprietaire est AUSSI membre de la Boucle (il l'a creee).
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $this->assertStringNotContainsString(
            e($this->fichier->nomLisible()),
            $this->espace($this->proprietaire, 'avec-moi'),
        );

        // Et le MEMBRE, lui, le recoit toujours — l'exclusion vise le geste,
        // pas la Boucle.
        $this->assertStringContainsString(
            e($this->fichier->nomLisible()),
            $this->espace($this->membre, 'avec-moi'),
        );
    }

    /**
     * La date de partage s'affiche AUSSI pour un partage de Boucle.
     *
     * Vu au navigateur : la colonne restait vide — elle ne lisait que les
     * partages nominatifs.
     */
    public function test_the_share_date_shows_for_loop_shares(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $date = \App\Models\DossierFileLoopShare::query()->sole()
            ->created_at->translatedFormat('j M Y');

        $this->assertStringContainsString($date, $this->espace($this->membre, 'avec-moi'));
    }

    /**
     * LE point de Cyril : le fichier apparaît DANS la Boucle.
     *
     * « Tu es d'accord que le document partagé dans la boucle n'est pas
     * partagé dans la boucle ? » — il l'était : le partage ouvrait la lecture
     * aux MEMBRES sans que la Boucle elle-même en montre rien. Pour qui
     * ouvrait l'espace de la Boucle, le partage n'existait pas.
     */
    public function test_the_file_appears_in_the_loop_dossier(): void
    {
        $racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();

        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $html = $this->actingAs($this->membre)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $racine->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-loop-shared-files', $html);
        $this->assertStringContainsString(e($this->fichier->nomLisible()), $html);
    }

    /**
     * La section dit que ces fichiers ne s'y modifient PAS.
     *
     * Ils vivent chez leur propriétaire, qui peut retirer le partage à tout
     * moment. Les mêler aux fichiers déposés laisserait croire qu'on peut les
     * renommer, les déplacer ou les supprimer ici.
     */
    public function test_the_section_states_these_files_are_read_only(): void
    {
        $racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $html = $this->actingAs($this->membre)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $racine->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('dossiers.loop_shared_files_hint')), $html);
    }

    /**
     * Et le lien vise la route DÉDIÉE, jamais le Dossier d'origine.
     *
     * Le membre n'a aucun droit sur le Dossier personnel du partageur : un
     * lien vers lui rendrait 403.
     */
    public function test_the_loop_section_links_to_the_dedicated_route(): void
    {
        $racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();
        $this->actingAs($this->proprietaire)->postJson($this->url('loops.store'), ['loop_id' => $this->loop->id]);

        $html = $this->actingAs($this->membre)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $racine->id,
            ]))
            ->assertOk()
            ->getContent();

        $attendu = route('organization.dossiers.shared.preview', [
            'organization' => $this->organization->slug,
            'file' => $this->fichier->id,
        ]);
        $this->assertStringContainsString(e($attendu), $html);

        $interdit = route('organization.dossiers.show', [
            'organization' => $this->organization->slug,
            'dossier' => $this->dossier->id,
        ]);
        $this->assertStringNotContainsString(e($interdit), $html);
    }

    /** Sans partage, la section n'existe pas. */
    public function test_no_section_when_nothing_is_shared_with_the_loop(): void
    {
        $racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();

        $html = $this->actingAs($this->membre)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $racine->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-loop-shared-files', $html);
    }

    /**
     * Le partage d'une AUTRE Boucle n'apparaît pas dans celle-ci.
     *
     * Sans la borne `loop_id`, la section afficherait tous les partages de
     * l'Organization — chaque Boucle verrait ceux des autres.
     */
    public function test_another_loops_share_never_appears_here(): void
    {
        $racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();

        $autreLoop = (new LoopService)->createLoop($this->proprietaire, 'Autre boucle 1695', type: 'project');
        $this->actingAs($this->proprietaire)
            ->postJson($this->url('loops.store'), ['loop_id' => $autreLoop->id])
            ->assertStatus(201);

        $html = $this->actingAs($this->membre)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $racine->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(e($this->fichier->nomLisible()), $html);
    }
}
