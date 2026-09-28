<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\User;
use App\Support\ScenarioManager\Capture\ScenarioCaptureDiff;
use App\Support\ScenarioManager\Capture\ScenarioCaptureService;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * TASK-1653 — comprendre AVANT de creer.
 *
 * Le contrat de cet ecran tient en une phrase : **le premier clic n'ecrit
 * rien**. Ce fichier verifie surtout cela, puis que le Diff dit la verite et
 * que la nouvelle version ne nait que d'un geste humain.
 */
class ScenarioCapturePreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');

        $this->superAdmin = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);
    }

    // =====================================================================
    // Le contrat : ouvrir n'ecrit RIEN
    // =====================================================================

    public function test_ouvrir_le_Preview_ne_cree_AUCUNE_version(): void
    {
        $version = $this->versionChargee();
        $avant = ScenarioManifestVersion::query()->count();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.capture', $version))
            ->assertOk();

        $this->assertSame($avant, ScenarioManifestVersion::query()->count());
        // Et la source n'a pas bouge d'un octet.
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->fresh()->state);
        $this->assertTrue($version->fresh()->isLoaded());
    }

    public function test_on_atteint_le_Preview_en_SUIVANT_un_lien(): void
    {
        // Lecon T1651 : un ecran livre et teste peut n'etre lie de nulle part.
        // Les tests appellent `route()` en direct — ils prouvent que la porte
        // s ouvre, jamais qu une poignee y mene.
        $version = $this->versionChargee();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->assertSee(route('admin.outils.scenarios.capture', $version), escape: false)
            ->assertSee('data-capture-open', escape: false);
    }

    public function test_le_Preview_est_refuse_a_un_membre_ordinaire(): void
    {
        $version = $this->versionChargee();
        $membre = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => false,
        ]);

        $this->actingAs($membre)->get(route('admin.outils.scenarios.capture', $version))->assertForbidden();
        $this->actingAs($membre)->post(route('admin.outils.scenarios.capture.store', $version), ['version' => '1.1.0'])->assertForbidden();
    }

    // =====================================================================
    // Le Diff dit la verite
    // =====================================================================

    public function test_un_monde_INCHANGE_ne_montre_aucun_changement(): void
    {
        $version = $this->versionChargee();

        $diff = app(ScenarioCaptureService::class)->comparer($version);

        $this->assertTrue($diff->estVide(), 'Une sandbox fraichement chargee n a rien change : '.json_encode($diff->famillesModifiees()));
        $this->assertSame(['added' => 0, 'changed' => 0, 'removed' => 0], $diff->totaux());

        // L ecran le DIT, et n offre aucun bouton de creation.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.capture', $version))
            ->assertOk()
            ->assertSee('data-capture-empty', escape: false)
            ->assertDontSee('data-form="capture-create"', escape: false);
    }

    public function test_un_objet_AJOUTE_apparait_en_added(): void
    {
        $version = $this->versionChargee();
        [$loop, $auteur] = $this->uneBoucleEtUnMembre($version);

        \App\Models\LoopMessage::create([
            'loop_id' => $loop->id, 'sender_id' => $auteur->id,
            'organization_id' => $loop->organization_id,
            'body' => 'Un message ne de l activite.', 'type' => 'user',
        ]);

        $diff = app(ScenarioCaptureService::class)->comparer($version);
        $messages = $diff->toutesLesFamilles()['messages'];

        $this->assertSame(1, $messages[ScenarioCaptureDiff::ADDED]);
        $this->assertSame(0, $messages[ScenarioCaptureDiff::REMOVED]);
        $this->assertSame(4, $messages[ScenarioCaptureDiff::UNCHANGED]);

        $ajoute = collect($messages['objets'])->firstWhere('statut', ScenarioCaptureDiff::ADDED);
        // L ecran parle en NOMS : le libelle est le contenu, pas la clef.
        $this->assertSame('Un message ne de l activite.', $ajoute['libelle']);
    }

    public function test_un_objet_SUPPRIME_apparait_en_removed_sans_tombstone(): void
    {
        // Capture reste un snapshot : un objet disparu est simplement ABSENT.
        // Aucun marqueur de suppression n entre dans Manifest V1.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        \App\Models\Service::query()->withoutGlobalScope(\App\Models\Scopes\BelongsToOrganizationScope::class)
            ->where('organization_id', $sandbox->id)->firstOrFail()->delete();

        $diff = app(ScenarioCaptureService::class)->comparer($version);
        $services = $diff->toutesLesFamilles()['services'];

        $this->assertSame(1, $services[ScenarioCaptureDiff::REMOVED]);

        // Et le document capture ne porte AUCUN marqueur de suppression.
        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;
        $this->assertSame([], $document['services']);
        $this->assertStringNotContainsString('deleted', json_encode($document['services']) ?: '');
    }

    public function test_un_objet_MODIFIE_nomme_les_champs_qui_ont_change(): void
    {
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $persona = User::query()->where('organization_id', $sandbox->id)
            ->where('email', 'like', 'student-02@%')->firstOrFail();
        $persona->forceFill(['bio' => 'Une biographie reecrite dans la sandbox.'])->save();

        $diff = app(ScenarioCaptureService::class)->comparer($version);
        $users = $diff->toutesLesFamilles()['users'];

        $this->assertSame(1, $users[ScenarioCaptureDiff::CHANGED]);

        $modifie = collect($users['objets'])->firstWhere('statut', ScenarioCaptureDiff::CHANGED);
        $this->assertSame(['bio'], $modifie['champs'], 'Seul le champ reellement change doit etre nomme.');
    }

    public function test_un_changement_d_ORDRE_compte_comme_une_modification(): void
    {
        // `order` et `position` sont des faits du monde, pas des details de
        // serialisation : les ignorer ferait passer un reordonnancement pour
        // « rien n a change ».
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $item = \App\Models\LoopRoadmapItem::query()->withoutGlobalScopes()
            ->where('organization_id', $sandbox->id)->whereNull('deleted_at')->firstOrFail();
        $item->forceFill(['position' => (int) $item->position + 7])->save();

        $diff = app(ScenarioCaptureService::class)->comparer($version);

        $this->assertSame(1, $diff->toutesLesFamilles()['roadmap_items'][ScenarioCaptureDiff::CHANGED]);
        $modifie = collect($diff->toutesLesFamilles()['roadmap_items']['objets'])->firstWhere('statut', ScenarioCaptureDiff::CHANGED);
        $this->assertContains('position', $modifie['champs']);
    }

    public function test_une_identite_COMPOSEE_se_compare_par_ses_composants(): void
    {
        // `memberships (loop,user)` n a pas de stable key : lui en fabriquer
        // une serait inventer une identite que le langage n a pas.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $membre = \App\Models\LoopMember::query()
            ->where('organization_id', $sandbox->id)->where('role', 'member')->firstOrFail();
        $membre->forceFill(['status' => 'left'])->save();

        $diff = app(ScenarioCaptureService::class)->comparer($version);
        $memberships = $diff->toutesLesFamilles()['memberships'];

        $this->assertSame(1, $memberships[ScenarioCaptureDiff::REMOVED]);
        $this->assertSame(0, $memberships[ScenarioCaptureDiff::ADDED], 'Un depart n est pas un ajout.');

        $retire = collect($memberships['objets'])->firstWhere('statut', ScenarioCaptureDiff::REMOVED);
        $this->assertStringContainsString('|', $retire['identite'], 'L identite composee porte ses deux composants.');
    }

    public function test_la_carte_des_familles_comparees_vient_du_SCHEMA(): void
    {
        // Aucune liste ecrite a la main : une famille ajoutee au Manifest entre
        // d elle-meme dans le Diff. Et un provider qui tomberait a vide rendrait
        // « rien n a change » pour TOUT — un faux negatif silencieux.
        $familles = ScenarioCaptureDiff::famillesComparables();

        $this->assertContains('users', $familles);
        $this->assertContains('training.progress', $familles, 'Les collections imbriquees de training doivent etre comparees.');
        $this->assertNotContains('training', $familles, 'Le conteneur n est pas une famille.');
        $this->assertCount(20, $familles);
    }

    // =====================================================================
    // Versioning : un geste HUMAIN
    // =====================================================================

    public function test_la_version_proposee_est_un_bump_MINEUR(): void
    {
        $version = $this->versionChargee();

        $this->assertSame('1.1.0', app(ScenarioCaptureService::class)->versionSuggeree($version));

        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.capture', $version))
            ->assertOk()
            ->assertSee('data-suggestion', escape: false);
    }

    public function test_creer_la_version_est_un_geste_EXPLICITE_et_pose_la_provenance(): void
    {
        $version = $this->versionChargee();
        [$loop, $auteur] = $this->uneBoucleEtUnMembre($version);

        \App\Models\LoopMessage::create([
            'loop_id' => $loop->id, 'sender_id' => $auteur->id,
            'organization_id' => $loop->organization_id,
            'body' => 'De quoi rendre le Diff non vide.', 'type' => 'user',
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.capture.store', $version), ['version' => '2.0.0'])
            ->assertRedirect();

        $capturee = ScenarioManifestVersion::query()
            ->where('scenario_key', $version->scenario_key)
            ->where('origin', ScenarioManifestVersion::ORIGIN_CAPTURE)
            ->firstOrFail();

        // Le numero CONFIRME par l humain, pas la suggestion.
        $this->assertSame('2.0.0', $capturee->version);
        $this->assertSame('2.0.0', json_decode((string) $capturee->json_source, true)['version']);

        // Meme chaine de scenario — une Capture n est pas un Duplicate.
        $this->assertSame($version->scenario_key, $capturee->scenario_key);
        $this->assertSame($version->id, $capturee->parent_id);
        $this->assertSame($this->sandboxDe($version)->id, $capturee->captured_from_organization_id);

        // DRAFT, et rien d herite.
        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $capturee->state);
        $this->assertNull($capturee->approved_digest);
        $this->assertNull($capturee->approved_by);
        $this->assertNull($capturee->approved_at);
        $this->assertNull($capturee->scenario_pack_load_id);

        // La source est intacte et TOUJOURS chargee.
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->fresh()->state);
        $this->assertTrue($version->fresh()->isLoaded());
    }

    public function test_creer_une_version_sans_changement_est_REFUSE(): void
    {
        // Le garde-fou est aussi cote serveur : un formulaire se rejoue.
        $version = $this->versionChargee();
        $avant = ScenarioManifestVersion::query()->count();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.capture.store', $version), ['version' => '1.1.0'])
            ->assertSessionHasErrors('scenario');

        $this->assertSame($avant, ScenarioManifestVersion::query()->count());
    }

    public function test_un_numero_de_version_INVALIDE_est_refuse(): void
    {
        $version = $this->versionChargee();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.capture.store', $version), ['version' => 'pas-un-numero'])
            ->assertSessionHasErrors('version');
    }

    // =====================================================================
    // Les blockers, rendus LISIBLES
    // =====================================================================

    public function test_un_blocker_est_affiche_en_francais_et_sans_bouton_de_creation(): void
    {
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        User::factory()->create([
            'organization_id' => $sandbox->id,
            'email' => 'personne.reelle@gmail.com',
        ]);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.capture', $version))
            ->assertOk();

        $reponse->assertSee('data-capture-blocked', escape: false);
        $reponse->assertSee('data-blocker', escape: false);
        // Le libelle de famille est TRADUIT, pas le nom technique brut.
        $reponse->assertSee('Personnes', escape: false);
        // Et aucun bouton ne propose de creer quoi que ce soit.
        $reponse->assertDontSee('data-form="capture-create"', escape: false);
    }

    public function test_une_ancre_inconnue_s_affiche_comme_un_blocker_et_non_comme_une_erreur(): void
    {
        $version = $this->versionChargee();
        $load = \App\Models\ScenarioPackLoad::query()->findOrFail($version->scenario_pack_load_id);
        $load->forceFill(['world_anchored_at' => null])->save();

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.capture', $version))
            ->assertOk();

        $reponse->assertSee('data-capture-blocked', escape: false);
        $reponse->assertSee('ancre_inconnue', escape: false);
        $reponse->assertDontSee('data-form="capture-create"', escape: false);
    }

    // =====================================================================
    // Outillage
    // =====================================================================

    private function sandboxDe(ScenarioManifestVersion $version): Organization
    {
        $load = \App\Models\ScenarioPackLoad::query()->findOrFail($version->scenario_pack_load_id);

        return Organization::query()->withTrashed()->findOrFail($load->organization_id);
    }

    /**
     * @return array{0: \App\Models\Loop, 1: User}
     */
    private function uneBoucleEtUnMembre(ScenarioManifestVersion $version): array
    {
        $sandbox = $this->sandboxDe($version);
        $loop = \App\Models\Loop::query()->where('organization_id', $sandbox->id)->firstOrFail();
        $membre = \App\Models\LoopMember::query()->where('loop_id', $loop->id)->firstOrFail();

        return [$loop, User::query()->findOrFail($membre->user_id)];
    }

    private function versionChargee(): ScenarioManifestVersion
    {
        $json = file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
        $resultat = app(ScenarioManifestValidator::class)->validate($json);
        $this->assertTrue($resultat->isValid(), 'La fixture AMT doit etre valide.');

        $version = new ScenarioManifestVersion([
            'scenario_key' => 'amt-formation-ia',
            'name' => 'AMT — Formation IA',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_DOGFOODING,
            'origin' => ScenarioManifestVersion::ORIGIN_IMPORT,
            'json_source' => $json,
            'created_by' => $this->superAdmin->id,
        ]);

        $version->forceFill([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => $resultat->digest(),
            'validation_summary' => $resultat->toArray(),
        ])->save();

        $service = app(ScenarioLifecycleService::class);
        $service->approve($version, $this->superAdmin);
        $service->load($version->fresh());

        return $version->fresh();
    }
}
