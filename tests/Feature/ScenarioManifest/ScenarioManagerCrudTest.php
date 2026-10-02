<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManifest\ManifestSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1649 — les premieres ECRITURES du Scenario Manager.
 *
 * Quatre promesses se prouvent ici, et aucune ne se prouve en regardant le
 * code :
 *
 * 1. **Aucune donnee metier n'est creee.** Le CRUD ecrit des lignes
 *    administratives et rien d'autre. Une empreinte du nombre de lignes de
 *    TOUTES les tables, prise avant et apres chaque geste, le dit mieux qu'un
 *    commentaire.
 * 2. **Les huit attributs systeme restent hors de portee d'une requete.** T1646
 *    les avait sortis de `$fillable` ; T1649 est la premiere TASK ou une
 *    requete existe pour essayer de les poser. Le test essaie VRAIMENT.
 * 3. **Toute modification repasse DRAFT** et efface l'approbation (CDC 12.3).
 * 4. **Une version chargee ne se modifie ni ne se supprime** (CDC 8.6, 14.3),
 *    et le refus arrive comme une phrase, pas comme une 500.
 */
class ScenarioManagerCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);

        App::setLocale('fr');
    }

    // =====================================================================
    // Acces
    // =====================================================================

    public function test_un_membre_ordinaire_ne_peut_declencher_aucune_ecriture(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => false]);
        $version = $this->version();

        $this->actingAs($membre);

        $this->get(route('admin.outils.scenarios.create'))->assertForbidden();
        $this->post(route('admin.outils.scenarios.store'), [])->assertForbidden();
        $this->get(route('admin.outils.scenarios.edit', $version))->assertForbidden();
        $this->put(route('admin.outils.scenarios.update', $version), [])->assertForbidden();
        $this->post(route('admin.outils.scenarios.duplicate', $version), [])->assertForbidden();
        $this->post(route('admin.outils.scenarios.validate', $version))->assertForbidden();
        $this->get(route('admin.outils.scenarios.export', $version))->assertForbidden();
        $this->delete(route('admin.outils.scenarios.destroy', $version))->assertForbidden();

        $this->assertSame(1, ScenarioManifestVersion::query()->count());
    }

    // =====================================================================
    // Aucune donnee metier — le point de vigilance de la campagne
    // =====================================================================

    public function test_aucun_geste_du_crud_ne_cree_de_donnee_metier(): void
    {
        // Un COMPTE de lignes est aveugle a trois choses, et le nom de ce test
        // promet plus que cela. On ferme donc les trois :
        //
        // - les fichiers : `Storage::fake()` + assertion sur les disques ;
        // - les files : `Bus` et `Queue` espionnes ;
        // - les UPDATE : les lignes d'`organizations` et d'`users` sont
        //   comparees ligne a ligne, parce qu'estampiller
        //   `organizations.scenario_sandbox_created_at` laisserait tous les
        //   comptes identiques.
        Storage::fake('local');
        Storage::fake('public');
        Bus::fake();
        Queue::fake();
        Http::preventStrayRequests();

        $source = $this->version();
        $avant = $this->empreinteComplete();

        $this->actingAs($this->superAdmin);

        // Creer, importer, dupliquer, editer, valider : cinq ecritures.
        $this->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'monde-vide', 'name' => 'Monde vide', 'locale' => 'fr', 'usage' => 'qa', 'mode' => 'vide',
        ])->assertRedirect();

        $this->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'monde-colle', 'name' => 'Monde colle', 'locale' => 'fr', 'usage' => 'demo',
            'mode' => 'coller', 'json' => '{"schema_version":"1.0"}',
        ])->assertRedirect();

        $this->post(route('admin.outils.scenarios.duplicate', $source), [
            'scenario_key' => 'monde-copie', 'name' => 'Monde copie',
        ])->assertRedirect();

        $this->put(route('admin.outils.scenarios.update', $source), ['json' => '{"schema_version":"1.0"}'])->assertRedirect();
        $this->post(route('admin.outils.scenarios.validate', $source))->assertRedirect();

        // Import par FICHIER, exporter, supprimer : le nom dit « aucun geste »,
        // le corps doit donc les exercer tous.
        $this->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'monde-fichier', 'name' => 'Monde fichier', 'locale' => 'fr', 'usage' => 'qa',
            'mode' => 'fichier',
            'fichier' => UploadedFile::fake()->createWithContent('monde.json', '{"schema_version":"1.0"}'),
        ])->assertRedirect();

        $this->get(route('admin.outils.scenarios.export', $source))->assertOk();
        $this->delete(route('admin.outils.scenarios.destroy', $source))->assertRedirect();

        $apres = $this->empreinteComplete();

        // Quatre creations, une suppression : +3 nettes.
        $this->assertSame(
            $avant['comptes']['scenario_manifest_versions'] + 3,
            $apres['comptes']['scenario_manifest_versions'],
            'Quatre versions creees, une supprimee.'
        );

        unset($avant['comptes']['scenario_manifest_versions'], $apres['comptes']['scenario_manifest_versions']);

        $this->assertSame(
            $avant,
            $apres,
            'Le CRUD ecrit des definitions, pas un monde : aucune autre table ne doit bouger, '
            .'et aucune ligne d Organization ou d User ne doit etre REECRITE. '
            .'Le monde d un scenario ne nait qu au Load.'
        );

        // Ni fichier, ni job, ni appel sortant.
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Le CRUD n ecrit aucun fichier.');
        $this->assertSame([], Storage::disk('public')->allFiles());
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
    }

    // =====================================================================
    // Les huit attributs systeme, essayes pour de vrai
    // =====================================================================

    public function test_une_requete_ne_peut_poser_aucun_attribut_systeme(): void
    {
        // T1646 avait sorti ces huit attributs de `$fillable` en prevoyant ce
        // moment. C'est ici que la garde sert : une requete existe enfin pour
        // tenter de les poser, et poser `digest` et `approved_digest` a la
        // meme valeur suffirait a s auto-approuver.
        $load = $this->load();
        $usurpation = str_repeat('a', 64);

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'tentative', 'name' => 'Tentative', 'locale' => 'fr', 'usage' => 'qa', 'mode' => 'vide',
        ] + [
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => $usurpation,
            'approved_digest' => $usurpation,
            'approved_by' => $this->superAdmin->id,
            'approved_at' => now()->toDateTimeString(),
            'validation_summary' => ['verdict' => 'VALID'],
            'scenario_pack_load_id' => $load->id,
            'captured_from_organization_id' => $this->organization->id,
        ])->assertRedirect();

        $version = ScenarioManifestVersion::query()->where('scenario_key', 'tentative')->sole();

        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $version->state);

        // TASK-1656 : `digest` et `validation_summary` ne sont plus nuls apres
        // une creation — le systeme les remplit lui-meme par
        // `revalidateAsDraft()`, pour que la fiche puisse dire des l'abord ce
        // que le scenario contient et ce qui lui manque.
        //
        // La garde de T1649 n'en est pas affaiblie, parce que ce n'est pas la
        // NULLITE qui protege : c'est le fait que ces valeurs viennent du
        // systeme et JAMAIS de la requete. On l'assert donc directement, ce qui
        // est plus fort que l'ancienne assertion — celle-ci passait aussi si le
        // code ne posait simplement rien.
        $this->assertNotSame($usurpation, $version->digest, 'Le digest soumis a ete retenu.');
        $this->assertSame('INVALID', $version->validation_summary['verdict'] ?? null);
        $this->assertNull($version->approved_digest);
        $this->assertNull($version->approved_by);
        $this->assertNull($version->approved_at);
        $this->assertNull($version->scenario_pack_load_id);
        $this->assertNull($version->captured_from_organization_id);
        $this->assertFalse($version->approvalMatchesCurrentDigest());
    }

    // =====================================================================
    // Creation
    // =====================================================================

    public function test_le_squelette_porte_toutes_les_familles_declarees_par_le_schema(): void
    {
        // La liste n'est pas recopiee ici : elle est confrontee au SCHEMA,
        // autorite independante. Une famille ajoutee au schema et absente du
        // squelette rougit, et un scenario neuf ne perd pas une collection en
        // silence.
        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'monde-vide', 'name' => 'Monde vide', 'locale' => 'fr', 'usage' => 'qa', 'mode' => 'vide',
        ])->assertRedirect();

        $version = ScenarioManifestVersion::query()->where('scenario_key', 'monde-vide')->sole();
        $document = json_decode($version->json_source, true);

        $this->assertIsArray($document);
        $this->assertSame(
            array_keys(ManifestSchema::envelope()),
            array_keys($document),
            'Le squelette doit porter exactement les cles racine du schema, dans le meme ordre.'
        );

        // Les VINGT familles, derivees de l'enveloppe elle-meme.
        //
        // `referencableCollections()` n'en liste que 17 : les collections sans
        // stable key (`memberships`, `training.progress`,
        // `training.submissions`) en sont absentes PAR CONSTRUCTION. S'en
        // servir comme autorite laissait trois familles sans aucune garde —
        // on pouvait les supprimer du squelette sans qu'un test rougisse.
        $familles = self::famillesDuSchema();

        $this->assertCount(20, $familles, 'Le schema V1 declare vingt familles.');

        foreach ($familles as $famille) {
            $this->assertSame([], data_get($document, $famille), "La famille {$famille} doit exister et etre vide.");
        }

        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $version->state);
        $this->assertSame('monde-vide', $document['id']);
        $this->assertSame('monde-vide', $document['organization']['proposed_slug']);
        $this->assertSame('1.0.0', $document['version']);
    }

    public function test_un_scenario_vide_est_reellement_invalide_et_l_ecran_le_dit(): void
    {
        // Un manifeste chargeable declare au moins une personne, une boucle,
        // un membership et un dossier. Un squelette n'en a aucun : il EST
        // invalide, et c'est le propre d'un brouillon.
        //
        // La version precedente de ce test se contentait de verifier qu'un
        // paragraphe s'affichait sur le FORMULAIRE — elle serait restee verte
        // si le squelette avait ete parfaitement valide, et prenait sa valeur
        // attendue dans la cle de langue que la vue rend. On cree donc le
        // scenario et on le VALIDE pour de bon.
        $this->actingAs($this->superAdmin);

        $this->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'monde-vide', 'name' => 'Monde vide', 'locale' => 'fr', 'usage' => 'qa', 'mode' => 'vide',
        ])->assertRedirect();

        $version = ScenarioManifestVersion::query()->where('scenario_key', 'monde-vide')->sole();

        $this->post(route('admin.outils.scenarios.validate', $version))->assertRedirect();

        $version->refresh();

        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $version->state);
        $this->assertSame('INVALID', $version->validation_summary['verdict']);

        $chemins = array_column($version->validation_summary['errors'], 'path');
        sort($chemins);
        $this->assertSame(['/dossiers', '/loops', '/memberships', '/users'], $chemins);

        // Et l'ecran l'annonce AVANT, pour que ce ne soit pas une surprise.
        $this->get(route('admin.outils.scenarios.create'))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.create_blank_note'));
    }

    public function test_un_import_conserve_le_texte_meme_invalide(): void
    {
        $casse = '{"schema_version":"1.0", "organization": ';

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'brouillon-casse', 'name' => 'Brouillon casse', 'locale' => 'fr', 'usage' => 'demo',
            'mode' => 'coller', 'json' => $casse,
        ])->assertRedirect();

        $version = ScenarioManifestVersion::query()->where('scenario_key', 'brouillon-casse')->sole();

        $this->assertSame($casse, $version->json_source, 'CDC 9.2 : le texte est conserve TEL QUEL, meme invalide.');
        $this->assertSame(ScenarioManifestVersion::ORIGIN_IMPORT, $version->origin);
        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $version->state);
    }

    public function test_une_cle_deja_prise_est_refusee_par_une_phrase(): void
    {
        $this->version(['scenario_key' => 'deja-pris', 'version' => '1.0.0']);

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'deja-pris', 'name' => 'Encore', 'locale' => 'fr', 'usage' => 'qa', 'mode' => 'vide',
        ])->assertSessionHasErrors(['scenario' => __('admin.scenario_manager.refus_key_already_used', ['scenario' => 'deja-pris', 'version' => '1.0.0'])]);

        $this->assertSame(1, ScenarioManifestVersion::query()->where('scenario_key', 'deja-pris')->count());
    }

    // =====================================================================
    // Duplication
    // =====================================================================

    public function test_dupliquer_reecrit_l_identite_dans_le_document_lui_meme(): void
    {
        // Le texte JSON est l'unique source de verite (CDC 10.1). Une copie
        // qui continuerait a declarer l `id` de son original serait une copie
        // qui ment sur ce qu'elle est — et seule la ligne administrative
        // saurait la verite.
        $source = $this->version(['scenario_key' => 'original', 'version' => '2.4.0'], [
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => str_repeat('b', 64),
            'approved_digest' => str_repeat('b', 64),
            'approved_by' => $this->superAdmin->id,
            'approved_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.duplicate', $source), [
            'scenario_key' => 'la-copie', 'name' => 'La copie',
        ])->assertRedirect();

        $copie = ScenarioManifestVersion::query()->where('scenario_key', 'la-copie')->sole();
        $document = json_decode($copie->json_source, true);

        $this->assertSame('la-copie', $document['id']);
        $this->assertSame('La copie', $document['name']);
        $this->assertSame('la-copie', $document['organization']['proposed_slug']);
        $this->assertSame('1.0.0', $document['version'], 'CDC 9.4 : la copie repart de 1.0.0.');

        // Rien de l'etat de sa source n'est copie.
        $this->assertSame('1.0.0', $copie->version);
        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $copie->state);
        $this->assertSame(ScenarioManifestVersion::ORIGIN_DUPLICATE, $copie->origin);
        $this->assertNull($copie->digest);
        $this->assertNull($copie->approved_digest);
        $this->assertNull($copie->approved_by);
        $this->assertNull($copie->approved_at);
        $this->assertNull($copie->scenario_pack_load_id);

        // Et la source n'a pas bouge.
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $source->fresh()->state);
    }

    public function test_dupliquer_un_document_illisible_est_refuse_avec_sa_raison(): void
    {
        // On ne reecrit pas l'identite d'un texte qu'on ne sait pas relire.
        // Produire une copie portant l'identite de sa source serait pire qu'un
        // refus.
        $source = $this->version(['json_source' => '{"schema_version":"1.0", ']);

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.duplicate', $source), [
            'scenario_key' => 'la-copie', 'name' => 'La copie',
        ])->assertSessionHasErrors(['scenario' => __('admin.scenario_manager.refus_unparsable_source')]);

        $this->assertSame(0, ScenarioManifestVersion::query()->where('scenario_key', 'la-copie')->count());
    }

    // =====================================================================
    // CDC 12.3 — toute modification repasse DRAFT
    // =====================================================================

    public function test_modifier_le_document_repasse_draft_et_annule_l_approbation(): void
    {
        $digest = str_repeat('c', 64);

        $version = $this->version([], [
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => $digest,
            'validation_summary' => ['verdict' => 'VALID', 'counters' => ['users' => 22], 'errors' => []],
            'approved_digest' => $digest,
            'approved_by' => $this->superAdmin->id,
            'approved_at' => now(),
        ]);

        $this->assertTrue($version->approvalMatchesCurrentDigest());

        $this->actingAs($this->superAdmin)
            ->put(route('admin.outils.scenarios.update', $version), ['json' => '{"schema_version":"1.0"}'])
            ->assertRedirect();

        $version->refresh();

        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $version->state);
        $this->assertNull($version->approved_digest);
        $this->assertNull($version->approved_by);
        $this->assertNull($version->approved_at);
        $this->assertNull($version->digest);

        // Le resume aussi : des compteurs calcules sur un texte qui a change
        // decriraient un document qui n'existe plus, et le Preview de T1648
        // les affiche tels quels.
        $this->assertNull($version->validation_summary);
        $this->assertFalse($version->approvalMatchesCurrentDigest());
    }

    // =====================================================================
    // CDC 8.6 et 14.3 — une version chargee
    // =====================================================================

    public function test_une_version_chargee_ne_se_modifie_pas(): void
    {
        $version = $this->versionChargee();
        $avant = $version->json_source;

        $this->actingAs($this->superAdmin)
            ->put(route('admin.outils.scenarios.update', $version), ['json' => '{"pirate":true}'])
            ->assertSessionHasErrors(['scenario' => __('admin.scenario_manager.refus_loaded_version')]);

        $this->assertSame($avant, $version->fresh()->json_source);
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->fresh()->state);
    }

    public function test_une_version_chargee_ne_se_supprime_pas(): void
    {
        // CDC 14.3. La supprimer laisserait une sandbox vivante sans
        // definition qui la decrive.
        $version = $this->versionChargee();

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.outils.scenarios.destroy', $version))
            ->assertSessionHasErrors(['scenario' => __('admin.scenario_manager.refus_loaded_version')]);

        $this->assertDatabaseHas('scenario_manifest_versions', ['id' => $version->id]);
    }

    public function test_l_editeur_d_une_version_chargee_s_ouvre_et_dit_pourquoi_il_est_ferme(): void
    {
        // Un champ grise sans explication passe pour une panne. L'ecran doit
        // dire la raison ET vers quoi se tourner (CDC 8.6).
        $version = $this->versionChargee();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.edit', $version))
            ->assertOk()
            ->assertSee(e(__('admin.scenario_manager.editor_locked')), false);
    }

    public function test_un_brouillon_se_supprime(): void
    {
        $version = $this->version();

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.outils.scenarios.destroy', $version))
            ->assertRedirect(route('admin.outils.scenarios'));

        $this->assertDatabaseMissing('scenario_manifest_versions', ['id' => $version->id]);
    }

    // =====================================================================
    // Validation TECHNIQUE — et la frontiere avec T1650
    // =====================================================================

    public function test_valider_un_document_valide_le_fait_passer_valid_sans_l_approuver(): void
    {
        // C'est la frontiere entre T1649 et T1650, et elle se prouve par ce
        // qui reste NUL : l'approbation humaine est la seule porte vers un
        // Load, et aucune etape technique ne doit pouvoir l'ouvrir.
        $version = $this->version(['json_source' => file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'))]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.validate', $version))
            ->assertRedirect();

        $version->refresh();

        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->state);
        $this->assertNotNull($version->digest);
        $this->assertSame('VALID', $version->validation_summary['verdict']);
        $this->assertSame(22, $version->validation_summary['counters']['users']);

        $this->assertNull($version->approved_digest, 'Valider n est PAS approuver.');
        $this->assertNull($version->approved_by);
        $this->assertNull($version->approved_at);
        $this->assertFalse($version->approvalMatchesCurrentDigest());
        $this->assertFalse($version->isLoaded());
    }

    public function test_valider_un_document_invalide_le_laisse_en_brouillon_avec_ses_erreurs(): void
    {
        $version = $this->version();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.validate', $version))
            ->assertRedirect();

        $version->refresh();

        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $version->state);
        $this->assertSame('INVALID', $version->validation_summary['verdict']);
        $this->assertNotEmpty($version->validation_summary['errors']);

        // Les erreurs sont LOCALISEES (CDC 10.10) : un message et un chemin.
        $premiere = $version->validation_summary['errors'][0];
        $this->assertArrayHasKey('message', $premiere);
        $this->assertArrayHasKey('path', $premiere);
        $this->assertArrayHasKey('code', $premiere);
    }

    // =====================================================================
    // Export
    // =====================================================================

    public function test_l_export_rend_le_document_tel_qu_il_est_enregistre(): void
    {
        // Sans reformatage : exporter un texte different de celui qu'on edite
        // ferait deux verites.
        $texte = '{"schema_version":"1.0",   "id":"espace-preserve"}';
        $version = $this->version(['scenario_key' => 'exporte', 'json_source' => $texte]);

        $reponse = $this->actingAs($this->superAdmin)->get(route('admin.outils.scenarios.export', $version));

        $reponse->assertOk();
        $this->assertSame($texte, $reponse->getContent());
        $this->assertStringContainsString('attachment', $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('exporte-1.0.0.json', $reponse->headers->get('Content-Disposition'));
    }

    // =====================================================================
    // Ce que PostgreSQL refuse et que SQLite avalerait
    // =====================================================================

    public function test_importer_un_fichier_binaire_est_refuse_par_une_phrase(): void
    {
        // `json_source` est une colonne `text` : PostgreSQL refuse net un octet
        // NUL et une sequence UTF-8 invalide. Sans garde applicative, importer
        // une image produirait une 500 en PRODUCTION pendant que SQLite
        // enregistrerait les octets binaires sans broncher — le defaut ne se
        // verrait donc jamais dans la suite de tests.
        foreach ([
            'image' => "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR",
            'latin1' => "Nom\xC3\x28 casse",
        ] as $cas => $octets) {
            $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
                'scenario_key' => 'binaire-'.$cas, 'name' => 'Binaire', 'locale' => 'fr', 'usage' => 'qa',
                'mode' => 'fichier',
                'fichier' => UploadedFile::fake()->createWithContent('fichier.json', $octets),
            ])->assertSessionHasErrors(['scenario' => __('admin.scenario_manager.refus_binary_content')]);

            $this->assertSame(0, ScenarioManifestVersion::query()->where('scenario_key', 'binaire-'.$cas)->count());
        }
    }

    public function test_un_nom_non_utf8_ne_produit_jamais_un_document_vide(): void
    {
        // `json_encode` rend `false` sur de l'UTF-8 mal forme, et un type de
        // retour `string` coerce ce `false` en chaine VIDE : le scenario
        // serait cree avec un document de zero octet, sans erreur ni
        // avertissement. C'est un silence, pas une panne — donc le pire cas.
        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'nom-casse', 'name' => "Nom\xC3\x28casse", 'locale' => 'fr', 'usage' => 'qa',
            'mode' => 'vide',
        ])->assertSessionHasErrors(['scenario' => __('admin.scenario_manager.refus_binary_content')]);

        $this->assertSame(0, ScenarioManifestVersion::query()->where('scenario_key', 'nom-casse')->count());
    }

    public function test_un_document_trop_gros_est_refuse_avec_ses_chiffres(): void
    {
        // Cette garde existait et ne rougissait nulle part : aucun test ne
        // l'avait jamais declenchee.
        $version = $this->version();
        $trop = str_repeat('a', ScenarioManifestVersion::MAX_JSON_BYTES + 8);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.outils.scenarios.update', $version), ['json' => $trop])
            ->assertSessionHasErrors(['scenario' => __('admin.scenario_manager.refus_document_too_large', [
                'octets' => ScenarioManifestVersion::MAX_JSON_BYTES + 8,
                'maximum' => ScenarioManifestVersion::MAX_JSON_BYTES,
            ])]);

        $this->assertNotSame($trop, $version->fresh()->json_source);
    }

    public function test_une_version_chargee_ne_se_revalide_pas(): void
    {
        $version = $this->versionChargee();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.validate', $version))
            ->assertSessionHasErrors(['scenario' => __('admin.scenario_manager.refus_loaded_version')]);

        $this->assertNull($version->fresh()->digest);
    }

    public function test_une_version_chargee_PEUT_etre_dupliquee(): void
    {
        // C'est l'echappatoire que le CDC 8.6 designe : « l'UI oriente vers
        // Capturer l'etat actuel ou Dupliquer ». Sans ce test, ajouter un
        // refus de duplication sur une version chargee fermerait la seule
        // issue sans qu'aucun test ne rougisse.
        $version = $this->versionChargee();

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.duplicate', $version), [
            'scenario_key' => 'issue-de-secours', 'name' => 'Issue de secours',
        ])->assertRedirect();

        $copie = ScenarioManifestVersion::query()->where('scenario_key', 'issue-de-secours')->sole();

        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $copie->state);
        $this->assertNull($copie->scenario_pack_load_id, 'La copie n herite pas du chargement de sa source.');
    }

    public function test_une_copie_dit_DE_QUOI_elle_est_la_copie(): void
    {
        // CDC 3.3 : `parent_id` porte la provenance d'une duplication ou d'une
        // Capture. Sans lui, la copie declare qu'elle EST une copie en perdant
        // la seule information que cette colonne existe pour dire.
        $source = $this->version(['scenario_key' => 'la-source']);

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.duplicate', $source), [
            'scenario_key' => 'la-fille', 'name' => 'La fille',
        ])->assertRedirect();

        $copie = ScenarioManifestVersion::query()->where('scenario_key', 'la-fille')->sole();

        $this->assertSame($source->id, $copie->parent_id);
        $this->assertTrue($copie->parent->is($source));
    }

    public function test_une_cle_trop_courte_ou_reservee_est_refusee_a_la_saisie(): void
    {
        // La cle devient le `proposed_slug` du document. Le Validator refuse
        // un slug de moins de trois caracteres et les slugs reserves : les
        // accepter ici fabriquerait des scenarios qui n'atteindront JAMAIS
        // VALID, sans un mot au moment de la saisie.
        foreach (['ab', 'admin', 'main', 'api', 'ADMIN-MAJUSCULES', 'avec espace'] as $cle) {
            $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
                'scenario_key' => $cle, 'name' => 'Peu importe', 'locale' => 'fr', 'usage' => 'qa', 'mode' => 'vide',
            ])->assertSessionHasErrors('scenario_key');

            $this->assertSame(0, ScenarioManifestVersion::query()->count(), "La cle « {$cle} » ne doit rien creer.");
        }

        $this->assertSame(0, ScenarioManifestVersion::query()->count());
    }

    public function test_la_regex_de_cle_ne_depend_pas_du_middleware_qui_rogne(): void
    {
        // `$` accepte un saut de ligne final : `/^[a-z0-9-]+$/` laisse passer
        // « ma-cle\n ». Aujourd'hui c'est inoffensif UNIQUEMENT parce que
        // `TrimStrings` rogne avant — et la cle est interpolee dans l'en-tete
        // `Content-Disposition` de l'export.
        //
        // Le cas ne peut donc pas s'observer a travers HTTP : c'est la REGLE
        // elle-meme qu'on interroge. Une garde ne doit pas dependre de l'ordre
        // des middlewares pour tenir.
        $regles = (new \ReflectionClass(\App\Http\Controllers\Admin\AdminScenarioManagerController::class))
            ->getMethod('reglesDeCle');
        $regles->setAccessible(true);

        $regex = null;

        foreach ($regles->invoke(null) as $regle) {
            if (is_string($regle) && str_starts_with($regle, 'regex:')) {
                $regex = substr($regle, 6);
            }
        }

        $this->assertNotNull($regex, 'La regle de cle doit porter une regex.');
        $this->assertSame(1, preg_match($regex, 'ma-cle'));
        $this->assertSame(0, preg_match($regex, "ma-cle\n"), 'Un saut de ligne final ne doit pas passer.');
    }

    public function test_le_document_n_est_PAS_rogne_a_ses_extremites(): void
    {
        // Garde EXPLICITE de l'exemption `trimStrings(except: ['json'])`.
        //
        // Elle etait couverte par accident — une fixture d'un autre test se
        // terminait par une espace, et rien ne disait que c'etait la ce qui la
        // protegeait. « Nettoyer » cette chaine aurait supprime la seule
        // preuve. Ici l'espace est l'objet du test, et son nom le dit.
        $avecEspaces = "  {\"schema_version\":\"1.0\"}  \n";

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'texte-intact', 'name' => 'Texte intact', 'locale' => 'fr', 'usage' => 'qa',
            'mode' => 'coller', 'json' => $avecEspaces,
        ])->assertRedirect();

        $this->assertSame(
            $avecEspaces,
            ScenarioManifestVersion::query()->where('scenario_key', 'texte-intact')->sole()->json_source,
            'CDC 9.2 : le texte est conserve TEL QUEL. Le digest se calcule sur ce qui est stocke.'
        );

        // Et la cle, elle, reste rognee : l'exemption est etroite.
        $this->assertSame('texte-intact', ScenarioManifestVersion::query()->sole()->scenario_key);
    }

    public function test_le_choix_d_usage_est_respecte(): void
    {
        // La bibliotheque filtre sur quatre usages : en figer un seul rendrait
        // trois valeurs du filtre inatteignables.
        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'pour-la-demo', 'name' => 'Pour la demo', 'locale' => 'fr',
            'usage' => ScenarioManifestVersion::USAGE_DEMO, 'mode' => 'vide',
        ])->assertRedirect();

        $this->assertSame(
            ScenarioManifestVersion::USAGE_DEMO,
            ScenarioManifestVersion::query()->where('scenario_key', 'pour-la-demo')->sole()->usage
        );
    }

    // =====================================================================
    // Outillage
    // =====================================================================

    /**
     * @param  array<string, mixed>  $declares
     * @param  array<string, mixed>  $systeme
     */
    private function version(array $declares = [], array $systeme = []): ScenarioManifestVersion
    {
        $version = new ScenarioManifestVersion(array_merge([
            'scenario_key' => 'ofsh',
            'name' => 'Organisation fictive',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_QA,
            'origin' => ScenarioManifestVersion::ORIGIN_NEW,
            'json_source' => '{"schema_version":"1.0","id":"ofsh","name":"Organisation fictive","version":"1.0.0","organization":{"proposed_slug":"ofsh"}}',
            'created_by' => $this->superAdmin->id,
        ], $declares));

        if ($systeme !== []) {
            $version->forceFill($systeme);
        }

        $version->save();

        return $version;
    }

    private function load(): ScenarioPackLoad
    {
        return ScenarioPackLoad::create([
            'pack_id' => 'manifest-ofsh',
            'pack_version' => '1.0.0',
            'organization_id' => $this->organization->id,
            'loaded_at' => now(),
        ]);
    }

    private function versionChargee(): ScenarioManifestVersion
    {
        return $this->version([], [
            'state' => ScenarioManifestVersion::STATE_VALID,
            'scenario_pack_load_id' => $this->load()->id,
        ]);
    }

    /**
     * Ce qu'un geste du CRUD ne doit pas deplacer.
     *
     * Le nombre de lignes de CHAQUE table repond a « une donnee metier
     * est-elle APPARUE ». Les lignes d'`organizations` et d'`users` sont en
     * plus comparees une a une, parce qu'un compte ne repond pas a « une
     * donnee metier a-t-elle ete REECRITE » — et T1642 a justement ajoute
     * `organizations.scenario_sandbox_created_at`, qu'une estampille
     * laisserait passer.
     *
     * @return array<string, mixed>
     */
    private function empreinteComplete(): array
    {
        $comptes = [];

        foreach (Schema::getTables() as $table) {
            $comptes[$table['name']] = DB::table($table['name'])->count();
        }

        ksort($comptes);

        return [
            'comptes' => $comptes,
            // En TABLEAUX, pas en `stdClass` : `assertSame` compare deux
            // objets par IDENTITE, donc deux lignes au contenu rigoureusement
            // identique echoueraient.
            'organizations' => DB::table('organizations')->orderBy('id')->get()
                ->map(static fn (object $ligne): array => (array) $ligne)->all(),
            'users' => DB::table('users')->orderBy('id')->get()
                ->map(static fn (object $ligne): array => (array) $ligne)->all(),
        ];
    }

    /**
     * Les VINGT familles du schema, derivees de l'enveloppe.
     *
     * On marche les noeuds `array` de la racine, puis un niveau dans les
     * `object` — ce qui attrape `training.*`. Recopier une liste, ou se fier a
     * `referencableCollections()` qui n'en connait que 17, laisse des familles
     * sans garde.
     *
     * @return list<string>
     */
    private static function famillesDuSchema(): array
    {
        $familles = [];

        foreach (ManifestSchema::envelope() as $cle => $noeud) {
            if (($noeud['type'] ?? null) === 'array') {
                $familles[] = $cle;

                continue;
            }

            if (($noeud['type'] ?? null) === 'object') {
                foreach ($noeud['fields'] ?? [] as $sousCle => $sousNoeud) {
                    if (($sousNoeud['type'] ?? null) === 'array') {
                        $familles[] = $cle.'.'.$sousCle;
                    }
                }
            }
        }

        sort($familles);

        return $familles;
    }
}
