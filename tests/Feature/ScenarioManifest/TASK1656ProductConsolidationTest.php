<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManager\ScenarioDraftReadiness;
use App\Support\ScenarioManager\ScenarioManifestSkeleton;
use App\Support\ScenarioManager\ScenarioTemplateLibrary;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1656 — le Scenario Manager devient utilisable SANS connaitre le Manifest.
 *
 * ## Ce que ce fichier prouve, et pourquoi chaque preuve existe
 *
 * T1656 n'ecrit presque aucune primitive : `duplicate()`, `destroy()`,
 * `import()` et le lifecycle existaient deja depuis T1646 -> T1653. Le risque
 * n'est donc pas qu'une primitive soit fausse — c'est qu'elle soit **mal
 * exposee** :
 *
 * 1. un modele qui se laisserait modifier par l'usage de sa copie ;
 * 2. une copie qui heriterait de la sandbox de sa source ;
 * 3. un bouton Supprimer qui pretendrait supprimer plus qu'il ne supprime ;
 * 4. un ecran qui offrirait un geste que le backend refuse.
 *
 * Chacun de ces quatre risques a ses tests ci-dessous.
 */
class TASK1656ProductConsolidationTest extends TestCase
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
        ]);
    }

    // =====================================================================
    // Le modele OFSH — source UNIQUE
    // =====================================================================

    public function test_le_modele_OFSH_est_publie_et_lit_le_MEME_fichier_que_le_pilote(): void
    {
        $this->assertTrue(ScenarioTemplateLibrary::has('ofsh'));

        $chemin = ScenarioTemplateLibrary::path('ofsh');

        $this->assertNotNull($chemin);
        $this->assertFileExists($chemin);

        // La source unique : le chemin du modele EST celui que le test du
        // pilote OFSH lit. Si l'un des deux bougeait sans l'autre, deux JSON
        // vivraient en parallele — exactement ce que la source unique interdit.
        $this->assertSame(
            base_path('resources/scenario-manifest/templates/ofsh-1.0.0.json'),
            $chemin
        );

        // Et l'ancien emplacement ne doit PAS avoir survecu a cote.
        $this->assertFileDoesNotExist(base_path('tests/Fixtures/ScenarioManifest/ofsh-1.0.0.json'));
    }

    public function test_le_modele_OFSH_est_un_document_VALIDE(): void
    {
        $json = ScenarioTemplateLibrary::json('ofsh');

        $this->assertNotNull($json);

        $resultat = app(ScenarioManifestValidator::class)->validate($json);

        // Un modele propose en premier rang dans l'interface ne peut pas etre
        // invalide : l'utilisateur qui clique « Utiliser ce modele » recevrait
        // un brouillon rouge sans avoir rien fait de mal.
        $this->assertTrue(
            $resultat->isValid(),
            'Le modele OFSH livre est INVALIDE : '.json_encode($resultat->errorCodes())
        );
    }

    public function test_les_compteurs_affiches_viennent_de_la_MEME_autorite_que_la_validation(): void
    {
        $affiches = ScenarioTemplateLibrary::counters('ofsh');
        $valides = app(ScenarioManifestValidator::class)
            ->validate((string) ScenarioTemplateLibrary::json('ofsh'))
            ->counters();

        // L'ecran ne compte pas autrement que le Validator. Sans cette egalite,
        // la carte du modele pourrait annoncer 16 personnes quand la validation
        // en voit 15, et personne ne saurait lequel croire.
        $this->assertSame($valides, $affiches);

        // Les compteurs canoniques d'OFSH, arbitres par MASTER en T1655.
        $this->assertSame(16, $affiches['users']);
        $this->assertSame(5, $affiches['loops']);
        $this->assertSame(34, $affiches['memberships']);
        $this->assertSame(38, $affiches['messages']);
    }

    public function test_le_nom_affiche_est_LU_dans_le_document_jamais_recopie(): void
    {
        $decrit = ScenarioTemplateLibrary::describe('ofsh');

        $this->assertNotNull($decrit);

        $document = json_decode((string) ScenarioTemplateLibrary::json('ofsh'), true);

        // Si le document changeait de nom, la carte suivrait. Un nom recopie
        // dans le code afficherait l'ancien pour toujours.
        $this->assertSame($document['name'], $decrit['name']);
        $this->assertSame($document['description'], $decrit['description']);
        $this->assertSame('OFSH', $decrit['short']);
    }

    public function test_une_clef_de_modele_inconnue_ne_lit_aucun_fichier(): void
    {
        // La clef est verifiee contre la liste DECLAREE, jamais contre le
        // systeme de fichiers : un nom ne doit pas pouvoir servir de chemin.
        foreach (['../../.env', 'ofsh/../../../etc/passwd', 'OFSH', 'inconnu', ''] as $hostile) {
            $this->assertFalse(ScenarioTemplateLibrary::has($hostile), $hostile);
            $this->assertNull(ScenarioTemplateLibrary::path($hostile), $hostile);
            $this->assertNull(ScenarioTemplateLibrary::json($hostile), $hostile);
        }
    }

    // =====================================================================
    // Creer depuis un modele — et le modele reste INTOUCHE
    // =====================================================================

    public function test_utiliser_un_modele_cree_un_DRAFT_sans_sandbox(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.store'), [
                'scenario_key' => 'ofsh-demo',
                'name' => 'OFSH — Demonstration',
                'locale' => 'fr',
                'usage' => ScenarioManifestVersion::USAGE_DEMO,
                'mode' => 'modele',
                'template' => 'ofsh',
            ])
            ->assertRedirect();

        $copie = ScenarioManifestVersion::query()->where('scenario_key', 'ofsh-demo')->firstOrFail();

        $this->assertSame('1.0.0', $copie->version);
        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $copie->state);
        $this->assertSame(ScenarioManifestVersion::ORIGIN_TEMPLATE, $copie->origin);
        $this->assertSame(ScenarioManifestVersion::USAGE_DEMO, $copie->usage);

        // « Utiliser un modele » ne CHARGE rien : pas d'Organization, pas de
        // sandbox, pas d'approbation.
        $this->assertNull($copie->scenario_pack_load_id);
        $this->assertNull($copie->approved_digest);
        $this->assertNull($copie->parent_id, 'Un modele n est pas une ligne de base : il n y a pas de parent.');

        // L'identite du document suit celle du scenario, pas celle du modele.
        $document = json_decode((string) $copie->json_source, true);
        $this->assertSame('ofsh-demo', $document['id']);
        $this->assertSame('OFSH — Demonstration', $document['name']);
        $this->assertSame('1.0.0', $document['version']);
        $this->assertSame('ofsh-demo', $document['organization']['proposed_slug']);

        // Mais le CONTENU metier est bien celui du modele.
        $this->assertCount(16, $document['users']);
        $this->assertCount(34, $document['memberships']);
    }

    public function test_le_modele_est_IMMUABLE_a_travers_tout_le_cycle_de_sa_copie(): void
    {
        $avant = hash_file('sha256', (string) ScenarioTemplateLibrary::path('ofsh'));

        // Utiliser, modifier la copie, dupliquer la copie, supprimer la copie.
        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'ofsh-demo', 'name' => 'OFSH demo', 'locale' => 'fr',
            'usage' => ScenarioManifestVersion::USAGE_DEMO, 'mode' => 'modele', 'template' => 'ofsh',
        ]);

        $copie = ScenarioManifestVersion::query()->where('scenario_key', 'ofsh-demo')->firstOrFail();

        $document = json_decode((string) $copie->json_source, true);
        $document['description'] = 'Modifiee par le test.';
        $this->actingAs($this->superAdmin)->put(route('admin.outils.scenarios.update', $copie), [
            'json' => json_encode($document),
        ]);

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.duplicate', $copie), [
            'scenario_key' => 'ofsh-demo-2', 'name' => 'Copie de OFSH demo',
        ]);

        $this->actingAs($this->superAdmin)->delete(route('admin.outils.scenarios.destroy', $copie));

        // Le fichier du modele n'a pas bouge d'un octet.
        $this->assertSame(
            $avant,
            hash_file('sha256', (string) ScenarioTemplateLibrary::path('ofsh')),
            'Le modele a ete modifie par le cycle de vie de sa copie.'
        );
    }

    // =====================================================================
    // Duplicate — aucune contamination (§35)
    // =====================================================================

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function etatsDeSource(): array
    {
        return [
            'DRAFT' => [ScenarioManifestVersion::STATE_DRAFT, false],
            'VALID non chargee' => [ScenarioManifestVersion::STATE_VALID, false],
            'LOADED' => [ScenarioManifestVersion::STATE_VALID, true],
        ];
    }

    #[DataProvider('etatsDeSource')]
    public function test_dupliquer_ne_copie_JAMAIS_la_sandbox_ni_l_approbation(string $etat, bool $chargee): void
    {
        $source = $this->poserVersion($etat, $chargee);
        // Instantane EXPLICITE : `replicate()->toArray()` ne rend pas les
        // attributs systeme hors `$fillable`, et un instantane incomplet ne
        // prouve rien sur ce qui n'y figure pas.
        $sourceAvant = [
            'state' => $source->state,
            'scenario_pack_load_id' => $source->scenario_pack_load_id,
            'approved_digest' => $source->approved_digest,
            'json_source' => $source->json_source,
        ];

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.duplicate', $source), [
                'scenario_key' => 'copie-cible',
                'name' => 'La copie',
            ])
            ->assertRedirect();

        $copie = ScenarioManifestVersion::query()->where('scenario_key', 'copie-cible')->firstOrFail();

        $this->assertNotSame($source->id, $copie->id);
        $this->assertSame('1.0.0', $copie->version);
        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $copie->state);
        $this->assertSame(ScenarioManifestVersion::ORIGIN_DUPLICATE, $copie->origin);
        $this->assertSame($source->id, $copie->parent_id, 'La copie doit dire DE QUOI elle est la copie.');

        // Le coeur du §35 : rien de l'etat vivant ne traverse.
        $this->assertNull($copie->scenario_pack_load_id);
        $this->assertNull($copie->approved_digest);
        $this->assertNull($copie->approved_at);
        $this->assertNull($copie->captured_from_organization_id);

        // Et la source est INTACTE — dupliquer n'est pas deplacer.
        $source->refresh();
        $this->assertSame($sourceAvant['state'], $source->state);
        $this->assertSame($sourceAvant['scenario_pack_load_id'], $source->scenario_pack_load_id);
        $this->assertSame($sourceAvant['approved_digest'], $source->approved_digest);
        $this->assertSame($sourceAvant['json_source'], $source->json_source);
    }

    public function test_dupliquer_et_capturer_ne_sont_PAS_le_meme_geste(): void
    {
        $source = $this->poserVersion(ScenarioManifestVersion::STATE_VALID, true);

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.duplicate', $source), [
            'scenario_key' => 'copie-cible', 'name' => 'La copie',
        ]);

        $copie = ScenarioManifestVersion::query()->where('scenario_key', 'copie-cible')->firstOrFail();

        // Duplicate rend un scenario NEUF en 1.0.0 sous une autre clef ;
        // Capture rendrait une version SUIVANTE sous la MEME clef. Confondre
        // les deux ferait croire qu'on a fige l'etat vivant de la sandbox.
        $this->assertNotSame($source->scenario_key, $copie->scenario_key);
        $this->assertSame('1.0.0', $copie->version);
        // Comparaison sur les TABLEAUX : la source porte le fichier brut, la
        // copie a ete re-encodee par le writer. Comparer les chaines aurait
        // compare une mise en forme, pas un contenu.
        $attendu = json_decode((string) $source->json_source, true);
        $obtenu = json_decode((string) $copie->json_source, true);

        // Seule l'identite differe — on la remet pour que le reste se compare.
        $obtenu['id'] = $attendu['id'];
        $obtenu['name'] = $attendu['name'];
        $obtenu['version'] = $attendu['version'];
        $obtenu['organization']['proposed_slug'] = $attendu['organization']['proposed_slug'];

        // Le contenu metier est celui de la VERSION ENREGISTREE, pas de l'etat
        // vivant de la sandbox.
        $this->assertSame($attendu, $obtenu);
    }

    // =====================================================================
    // Delete — la portee du libelle EST la portee du backend (§15)
    // =====================================================================

    public function test_supprimer_un_DRAFT_est_autorise(): void
    {
        $version = $this->poserVersion(ScenarioManifestVersion::STATE_DRAFT, false);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.outils.scenarios.destroy', $version))
            ->assertRedirect(route('admin.outils.scenarios'));

        $this->assertDatabaseMissing('scenario_manifest_versions', ['id' => $version->id]);
    }

    public function test_supprimer_une_VALID_non_chargee_est_autorise(): void
    {
        $version = $this->poserVersion(ScenarioManifestVersion::STATE_VALID, false);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.outils.scenarios.destroy', $version))
            ->assertRedirect(route('admin.outils.scenarios'));

        $this->assertDatabaseMissing('scenario_manifest_versions', ['id' => $version->id]);
    }

    public function test_supprimer_une_version_CHARGEE_est_refuse_par_une_PHRASE(): void
    {
        $version = $this->poserVersion(ScenarioManifestVersion::STATE_VALID, true);

        $this->actingAs($this->superAdmin)
            ->from(route('admin.outils.scenarios.show', $version))
            ->delete(route('admin.outils.scenarios.destroy', $version))
            ->assertRedirect()
            ->assertSessionHasErrors();

        // Refuse, et la ligne est toujours la : un refus n'est pas une
        // suppression partielle.
        $this->assertDatabaseHas('scenario_manifest_versions', ['id' => $version->id]);
    }

    public function test_supprimer_une_version_ne_touche_PAS_les_autres_versions_de_la_clef(): void
    {
        $premiere = $this->poserVersion(ScenarioManifestVersion::STATE_DRAFT, false, 'ofsh', '1.0.0');
        $seconde = $this->poserVersion(ScenarioManifestVersion::STATE_DRAFT, false, 'ofsh', '1.1.0');

        $this->actingAs($this->superAdmin)->delete(route('admin.outils.scenarios.destroy', $seconde));

        // La portee REELLE du backend : UNE version. C'est ce fait qui commande
        // le libelle du bouton, et non l'inverse.
        $this->assertDatabaseMissing('scenario_manifest_versions', ['id' => $seconde->id]);
        $this->assertDatabaseHas('scenario_manifest_versions', ['id' => $premiere->id]);
    }

    // =====================================================================
    // L'INTERFACE expose ce que le backend sait faire
    // =====================================================================

    public function test_la_bibliotheque_presente_une_section_Modeles_avec_OFSH(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-section="templates"', $html);
        $this->assertStringContainsString('data-template="ofsh"', $html);
        $this->assertStringContainsString(e(__('admin.scenario_manager.templates_title')), $html);
        $this->assertStringContainsString(e(__('admin.scenario_manager.template_use')), $html);

        // Le nom DEVELOPPE, et les compteurs reels du modele.
        $this->assertStringContainsString('Organismes de Formation Humanistes', $html);
        $this->assertStringContainsString('16 '.e(__('admin.scenario_manager.counter_users')), $html);
        $this->assertStringContainsString('38 '.e(__('admin.scenario_manager.counter_messages')), $html);
    }

    public function test_le_texte_obsolete_sur_la_capture_a_DISPARU(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->getContent();

        // Capture est livree depuis T1652/T1653 et eprouvee par le pilote OFSH.
        // Un bandeau qui la promet « pour plus tard » decrit l'historique des
        // TASK, pas le produit.
        $this->assertStringNotContainsString('tache suivante', $html);
        $this->assertStringNotContainsString('arrive dans une', $html);
    }

    public function test_chaque_carte_offre_Ouvrir_Dupliquer_et_Supprimer(): void
    {
        $version = $this->poserVersion(ScenarioManifestVersion::STATE_DRAFT, false);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-action="open"', $html);
        $this->assertStringContainsString('data-action="duplicate"', $html);
        $this->assertStringContainsString('data-action="delete"', $html);
        $this->assertStringContainsString(route('admin.outils.scenarios.duplicate', $version), $html);
        $this->assertStringContainsString(route('admin.outils.scenarios.destroy', $version), $html);
    }

    public function test_une_carte_CHARGEE_offre_Capturer_et_EXPLIQUE_le_refus_de_suppression(): void
    {
        $this->poserVersion(ScenarioManifestVersion::STATE_VALID, true);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-action="capture"', $html);

        // §14 : l'action n'est pas masquee en silence — elle devient une phrase
        // qui dit quoi faire d'abord.
        $this->assertStringContainsString('data-delete="blocked"', $html);
        $this->assertStringContainsString(e(__('admin.scenario_manager.delete_blocked_loaded')), $html);
        $this->assertStringNotContainsString('data-action="delete"', $html);
    }

    public function test_le_libelle_de_suppression_dit_la_PORTEE_REELLE_du_backend(): void
    {
        // Une seule version sous la clef : supprimer, c'est supprimer LE scenario.
        $this->poserVersion(ScenarioManifestVersion::STATE_DRAFT, false, 'solo', '1.0.0');

        $html = $this->actingAs($this->superAdmin)->get(route('admin.outils.scenarios'))->getContent();
        $this->assertStringContainsString('data-scope="scenario"', $html);
        $this->assertStringContainsString(e(__('admin.scenario_manager.delete_scenario')), $html);
        $this->assertStringNotContainsString('data-scope="version"', $html);

        // Une seconde version arrive : le MEME bouton doit changer de phrase.
        $this->poserVersion(ScenarioManifestVersion::STATE_DRAFT, false, 'solo', '1.1.0');

        $html = $this->actingAs($this->superAdmin)->get(route('admin.outils.scenarios'))->getContent();
        $this->assertStringContainsString('data-scope="version"', $html);
        $this->assertStringContainsString(e(__('admin.scenario_manager.delete_version')), $html);
        $this->assertStringNotContainsString('data-scope="scenario"', $html);

        // Et le sous-texte annonce combien d'autres survivent.
        $this->assertStringContainsString(
            e(__('admin.scenario_manager.delete_scope_version', ['count' => 1])),
            $html
        );
    }

    public function test_dupliquer_une_version_CHARGEE_annonce_qu_il_ne_capture_PAS(): void
    {
        $this->poserVersion(ScenarioManifestVersion::STATE_VALID, true);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.outils.scenarios'))->getContent();

        // §19 : le malentendu le plus couteux du produit, dit a l'endroit ou il
        // se produirait.
        $this->assertStringContainsString(e(__('admin.scenario_manager.duplicate_not_capture')), $html);
    }

    public function test_le_point_d_entree_propose_le_MODELE_en_premier_et_relegue_le_JSON(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-template-choice="ofsh"', $html);

        // L'ORDRE est le message : le modele avant le vide, le vide avant le JSON.
        $posModele = strpos($html, 'data-template-choice="ofsh"');
        $posVide = strpos($html, 'value="vide"');
        $posAvance = strpos($html, __('admin.scenario_manager.new_advanced'));

        $this->assertNotFalse($posModele);
        $this->assertNotFalse($posVide);
        $this->assertNotFalse($posAvance);
        $this->assertLessThan($posVide, $posModele, 'Le modele doit venir AVANT le scenario vide.');
        $this->assertLessThan($posAvance, $posVide, 'Le JSON doit venir APRES, en mode avance.');

        // Le champ du modele part bien avec le formulaire.
        $this->assertStringContainsString('name="template" value="ofsh"', $html);
    }

    public function test_le_bouton_Utiliser_ce_modele_mene_a_un_formulaire_qui_le_PORTE(): void
    {
        // Le lien de la carte de modele, suivi tel quel.
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.create', ['modele' => 'ofsh']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="template" value="ofsh"', $html);

        // Un parametre inconnu ne doit pas pre-selectionner un modele fantome —
        // il retombe sur le premier modele publie, jamais sur rien.
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.create', ['modele' => 'inexistant']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="template" value="ofsh"', $html);
    }

    // =====================================================================
    // La FICHE : quoi faire, avant comment ca marche
    // =====================================================================

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function etatsEtActionPrincipale(): array
    {
        return [
            'DRAFT -> continuer la construction' => [ScenarioManifestVersion::STATE_DRAFT, false, 'draft'],
            'VALID -> creer la sandbox' => [ScenarioManifestVersion::STATE_VALID, false, 'valid'],
            'LOADED -> ouvrir la sandbox' => [ScenarioManifestVersion::STATE_VALID, true, 'loaded'],
        ];
    }

    #[DataProvider('etatsEtActionPrincipale')]
    public function test_la_fiche_offre_UNE_action_principale_selon_l_etat(string $etat, bool $chargee, string $attendu): void
    {
        $version = $this->poserVersion($etat, $chargee);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-primary="'.$attendu.'"', $html);

        // UNE seule : trois boutons de meme poids ne diraient pas quoi faire.
        foreach (['draft', 'valid', 'loaded'] as $autre) {
            if ($autre !== $attendu) {
                $this->assertStringNotContainsString('data-primary="'.$autre.'"', $html);
            }
        }

        $this->assertStringContainsString(e(__('admin.scenario_manager.primary_'.$attendu)), $html);
    }

    public function test_la_fiche_offre_Dupliquer_et_Supprimer(): void
    {
        $version = $this->poserVersion(ScenarioManifestVersion::STATE_DRAFT, false);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->getContent();

        // Les MEMES composants que la bibliotheque — §18 : une implementation.
        $this->assertStringContainsString('data-action="duplicate"', $html);
        $this->assertStringContainsString('data-action="delete"', $html);
        $this->assertStringContainsString(route('admin.outils.scenarios.duplicate', $version), $html);
        $this->assertStringContainsString(route('admin.outils.scenarios.destroy', $version), $html);
    }

    public function test_le_digest_n_est_plus_la_PREMIERE_chose_que_la_fiche_dit(): void
    {
        $version = $this->poserVersion(ScenarioManifestVersion::STATE_VALID, false);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->getContent();

        // Le CDC 11.2 exige le digest : il reste donc PRESENT, mais replie.
        $this->assertStringContainsString('data-technical', $html);
        $this->assertStringContainsString(e(__('admin.scenario_manager.preview_digest')), $html);

        // Et l'action principale vient AVANT lui.
        $posAction = strpos($html, 'data-actions');
        $posTechnique = strpos($html, 'data-technical');

        $this->assertNotFalse($posAction);
        $this->assertNotFalse($posTechnique);
        $this->assertLessThan($posTechnique, $posAction, "L'action doit preceder les details techniques.");
    }

    public function test_une_version_VALID_annonce_qu_editer_annulera_sa_validation(): void
    {
        $version = $this->poserVersion(ScenarioManifestVersion::STATE_VALID, false);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.valid_edit_warning'));
    }

    public function test_une_sandbox_EN_CORBEILLE_n_offre_AUCUNE_action_principale(): void
    {
        $version = $this->poserVersion(ScenarioManifestVersion::STATE_VALID, true);
        $version->scenarioPackLoad->organization->delete();

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->getContent();

        // `ScenarioPackLoad::organization()` porte `->withTrashed()` (T1650) :
        // la relation n'est pas `null` pour une corbeille. Une garde qui testait
        // la nullite ne gardait donc RIEN, et l'ecran proposait d'ouvrir une
        // sandbox supprimee. Trouve par le test T1654, pas par le mien.
        foreach (['loaded', 'valid', 'draft'] as $etat) {
            $this->assertStringNotContainsString('data-primary="'.$etat.'"', $html);
        }

        $this->assertStringContainsString('data-sandbox="absente"', $html);
        $this->assertStringNotContainsString(route('admin.outils.scenarios.personas', $version), $html);

        // Mais Dupliquer reste offert : la DEFINITION existe toujours.
        $this->assertStringContainsString('data-action="duplicate"', $html);
    }

    // =====================================================================
    // « A completer » n'est pas « invalide » (§20-§21)
    // =====================================================================

    public function test_un_scenario_VIDE_est_a_completer_avec_une_checklist_DERIVEE(): void
    {
        // Le vrai parcours : creer un scenario vide par l'interface.
        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'tout-neuf', 'name' => 'Tout neuf', 'locale' => 'fr',
            'usage' => ScenarioManifestVersion::USAGE_QA, 'mode' => 'vide',
        ]);

        $version = ScenarioManifestVersion::query()->where('scenario_key', 'tout-neuf')->firstOrFail();

        // Valider pour obtenir le verdict reel — c'est ce que fait l'utilisateur.
        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.validate', $version));
        $version->refresh();

        $lecture = ScenarioDraftReadiness::pour($version);

        // Les QUATRE familles fondatrices manquent, et rien d'autre n'est
        // reproche : c'est un debut, pas une panne.
        $this->assertTrue($lecture->estACompleter(), 'Un scenario vide doit etre « a completer ».');
        $this->assertFalse($lecture->estInvalide());
        $this->assertSame(['users', 'loops', 'memberships', 'dossiers'], $lecture->manquantes);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-readiness="a_completer"', $html);
        $this->assertStringNotContainsString('data-readiness="invalide"', $html);

        // La checklist est en langage METIER, dans l'ordre du geste.
        foreach (['users', 'loops', 'memberships', 'dossiers'] as $famille) {
            $this->assertStringContainsString('data-todo="'.$famille.'" data-present="non"', $html);
            $this->assertStringContainsString(e(__('admin.scenario_manager.todo_item_'.$famille)), $html);
        }

        // Et AUCUN code technique en premier plan.
        $posChecklist = strpos($html, 'data-readiness="a_completer"');
        $posCode = strpos($html, 'MISSING_FIELD');
        $this->assertNotFalse($posChecklist);

        if ($posCode !== false) {
            $this->assertLessThan($posCode, $posChecklist, 'La checklist metier doit preceder les codes techniques.');
        }
    }

    public function test_une_INCOHERENCE_reelle_bascule_en_invalide_et_non_en_a_completer(): void
    {
        // Un document qui porte des donnees, dont UNE est incoherente : un
        // message dont l'auteur n'existe pas. Le reproche n'est plus « il
        // manque une famille » mais « ce que tu as ecrit ne tient pas ».
        $document = json_decode((string) ScenarioTemplateLibrary::json('ofsh'), true);
        $document['messages'][0]['author'] = 'personne-qui-n-existe-pas';

        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.store'), [
            'scenario_key' => 'incoherent', 'name' => 'Incoherent', 'locale' => 'fr',
            'usage' => ScenarioManifestVersion::USAGE_QA, 'mode' => 'coller',
            'json' => json_encode($document),
        ]);

        $version = ScenarioManifestVersion::query()->where('scenario_key', 'incoherent')->firstOrFail();
        $this->actingAs($this->superAdmin)->post(route('admin.outils.scenarios.validate', $version));
        $version->refresh();

        $lecture = ScenarioDraftReadiness::pour($version);

        $this->assertTrue($lecture->estInvalide(), 'Une reference cassee est une INCOHERENCE, pas un manque.');
        $this->assertFalse($lecture->estACompleter());

        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.invalid_title'))
            ->assertDontSee(__('admin.scenario_manager.todo_title'));
    }

    public function test_un_seul_reproche_hors_fondations_suffit_a_basculer_en_invalide(): void
    {
        // Le cas limite qui compte : les quatre familles manquent ET une autre
        // faute existe. Une checklist rassurante cacherait la seconde.
        $version = $this->poserVersion(ScenarioManifestVersion::STATE_DRAFT, false);
        $version->forceFill(['validation_summary' => [
            'verdict' => 'INVALID',
            'errors' => [
                ['code' => 'MISSING_FIELD', 'path' => '/users', 'message' => ''],
                ['code' => 'MISSING_FIELD', 'path' => '/loops', 'message' => ''],
                ['code' => 'MISSING_FIELD', 'path' => '/memberships', 'message' => ''],
                ['code' => 'MISSING_FIELD', 'path' => '/dossiers', 'message' => ''],
                ['code' => 'INVALID_FORMAT', 'path' => '/organization/proposed_slug', 'message' => ''],
            ],
        ]])->save();

        $this->assertTrue(ScenarioDraftReadiness::pour($version->refresh())->estInvalide());
    }

    public function test_une_version_VALIDE_n_affiche_aucun_bandeau_de_lecture(): void
    {
        $version = $this->poserVersion(ScenarioManifestVersion::STATE_VALID, false);

        $lecture = ScenarioDraftReadiness::pour($version);

        $this->assertSame(ScenarioDraftReadiness::ETAT_PRET, $lecture->etat);
        $this->assertFalse($lecture->estACompleter());
        $this->assertFalse($lecture->estInvalide());

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-readiness=', $html);
    }

    // =====================================================================
    // Outils du test
    // =====================================================================

    private function poserVersion(
        string $etat,
        bool $chargee,
        string $clef = 'source',
        string $numero = '1.0.0'
    ): ScenarioManifestVersion {
        $load = $chargee
            ? ScenarioPackLoad::create([
                'pack_id' => 'manifest-'.$clef,
                'pack_version' => $numero,
                'organization_id' => Organization::factory()->create()->id,
                'loaded_at' => now(),
            ])
            : null;

        $version = new ScenarioManifestVersion([
            'scenario_key' => $clef,
            'name' => 'Source '.$numero,
            'version' => $numero,
            'usage' => ScenarioManifestVersion::USAGE_QA,
            'origin' => ScenarioManifestVersion::ORIGIN_NEW,
            'json_source' => (string) ScenarioTemplateLibrary::json('ofsh'),
            'created_by' => $this->superAdmin->id,
        ]);

        // `state`, l'approbation et le chargement sont ecrits par le SYSTEME :
        // le test les pose comme la production le fera.
        $systeme = ['state' => $etat];

        if ($etat === ScenarioManifestVersion::STATE_VALID) {
            $systeme['approved_digest'] = str_repeat('a', 16);
            $systeme['approved_at'] = now();
            $systeme['approved_by'] = $this->superAdmin->id;
        }

        if ($load !== null) {
            $systeme['scenario_pack_load_id'] = $load->id;
        }

        $version->forceFill($systeme)->save();

        return $version;
    }
}
