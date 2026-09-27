<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\ScenarioManifestVersion;
use App\Models\User;
use App\Support\ScenarioManager\ScenarioManifestSkeleton;
use App\Support\ScenarioManager\ScenarioVersionRefused;
use App\Support\ScenarioManager\ScenarioVisualEditor;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * TASK-1651 — les mutations visuelles bornees.
 *
 * Ce fichier prouve deux choses qui comptent plus que le reste :
 *
 * 1. creer une Boucle ecrit EXACTEMENT la structure minimale que le Manifest
 *    exige, et RIEN de plus ;
 * 2. on ne repare jamais un graphe en silence — une suppression referencee
 *    refuse, et ne supprime pas a moitie.
 *
 * Le comptage se fait par DIFFERENCE sur toutes les collections du document,
 * jamais sur une liste ecrite a la main : une collection ajoutee demain au
 * Manifest entre donc automatiquement dans la mesure.
 */
class ScenarioVisualEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');
    }

    // =====================================================================
    // La mutation atomique
    // =====================================================================

    public function test_creer_une_boucle_ecrit_EXACTEMENT_la_structure_minimale(): void
    {
        $editeur = $this->editeurAvecUnePersonne($clePersonne);

        $avant = $this->comptageParCollection($editeur);

        $cleBoucle = $editeur->ajouterBoucle([
            'name' => 'Atelier IA',
            'description' => 'Une boucle de demonstration.',
            'type' => 'training',
            'owner' => $clePersonne,
            'visibility' => 'private',
            'access_mode' => 'invitation',
        ]);

        $apres = $this->comptageParCollection($editeur);

        // 1. EXACTEMENT trois objets de plus, et on nomme lesquels.
        $this->assertSame(
            ['dossiers' => 1, 'loops' => 1, 'memberships' => 1],
            $this->difference($avant, $apres),
            'Creer une Boucle ajoute la Boucle, son Dossier racine et le membership owner — rien d autre.'
        );

        $document = $editeur->document();
        $boucle = $this->ligne($document, 'loops', $cleBoucle);
        $dossier = $this->ligne($document, 'dossiers', (string) $boucle['root_dossier']);

        // 2. le membership owner designe le MEME utilisateur que loops[].owner
        $membership = $document['memberships'][0];
        $this->assertSame($cleBoucle, $membership['loop']);
        $this->assertSame($clePersonne, $membership['user']);
        $this->assertSame('owner', $membership['role']);
        $this->assertSame($clePersonne, $boucle['owner']);

        // 3. le Dossier racine : les trois contraintes que les invariants
        //    imposent, et qui ne sont donc PAS des choix.
        $this->assertSame($cleBoucle, $dossier['loop']);
        $this->assertNull($dossier['parent'], 'Un Dossier racine n a pas de parent.');
        $this->assertSame('loop', $dossier['visibility'], 'Un Dossier racine a la visibilite loop.');

        // 4. son document racine, minimal et neutre
        $this->assertNotNull($dossier['root_document'], 'Un Dossier racine exige un document racine.');
        $this->assertSame($clePersonne, $dossier['root_document']['author']);
        $this->assertNotSame('', trim((string) $dossier['root_document']['content']));
    }

    public function test_le_document_produit_est_VALID_pour_le_vrai_Validator(): void
    {
        // La preuve qui compte : ce n'est pas mon idee de la structure
        // minimale qui fait foi, c'est le Validator.
        $editeur = $this->editeurAvecUnePersonne($clePersonne);

        $editeur->ajouterBoucle([
            'name' => 'Atelier IA',
            'description' => 'Une boucle de demonstration.',
            'type' => 'training',
            'owner' => $clePersonne,
            'visibility' => 'private',
            'access_mode' => 'invitation',
        ]);

        $resultat = app(ScenarioManifestValidator::class)->validate($editeur->json());

        $this->assertTrue($resultat->isValid(), 'Le document doit etre VALID : '.json_encode($resultat->toArray()));
    }

    public function test_creer_une_boucle_SANS_aucune_personne_est_refuse(): void
    {
        // On ne fabrique JAMAIS un persona au passage : ce serait creer une
        // personne que le SuperAdmin n'a pas voulue.
        $editeur = ScenarioVisualEditor::pour(ScenarioManifestSkeleton::json('atelier', 'Atelier', 'fr'));

        $avant = $editeur->json();

        try {
            $editeur->ajouterBoucle([
                'name' => 'Atelier IA',
                'description' => 'Une boucle.',
                'type' => 'general',
                'owner' => 'personne-inexistante',
                'visibility' => 'private',
                'access_mode' => 'open',
            ]);
            $this->fail('La creation aurait du etre refusee.');
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::NO_PERSONA, $refus->reason);
        }

        $this->assertSame($avant, $editeur->json(), 'Et le document n a pas bouge.');
    }

    // =====================================================================
    // Les suppressions : jamais de cascade silencieuse
    // =====================================================================

    public function test_supprimer_une_boucle_emporte_sa_structure_VIDE(): void
    {
        $editeur = $this->editeurAvecUnePersonne($clePersonne);
        $avant = $this->comptageParCollection($editeur);

        $cleBoucle = $editeur->ajouterBoucle([
            'name' => 'Atelier IA',
            'description' => 'Une boucle.',
            'type' => 'general',
            'owner' => $clePersonne,
            'visibility' => 'private',
            'access_mode' => 'open',
        ]);

        $editeur->supprimerBoucle($cleBoucle);

        $this->assertSame(
            [],
            $this->difference($avant, $this->comptageParCollection($editeur)),
            'La suppression rend le document a son etat d avant la creation.'
        );
    }

    public function test_supprimer_une_boucle_REFERENCEE_est_refuse_et_ne_supprime_RIEN(): void
    {
        $editeur = $this->editeurAvecUnePersonne($clePersonne);

        $cleBoucle = $editeur->ajouterBoucle([
            'name' => 'Atelier IA',
            'description' => 'Une boucle.',
            'type' => 'general',
            'owner' => $clePersonne,
            'visibility' => 'private',
            'access_mode' => 'open',
        ]);

        // Un objet HORS SCOPE T1651 designe la Boucle. L'editeur visuel ne sait
        // pas le creer — et c'est exactement pour ca qu'il ne doit pas le
        // detruire.
        $document = $editeur->document();
        $document['messages'][] = [
            'key' => 'message-1',
            'loop' => $cleBoucle,
            'sender' => $clePersonne,
            'body' => 'Un message ecrit ailleurs.',
            'order' => 0,
        ];
        $editeur = ScenarioVisualEditor::pour(json_encode($document));

        $avant = $editeur->json();

        try {
            $editeur->supprimerBoucle($cleBoucle);
            $this->fail('La suppression aurait du etre refusee.');
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::STILL_REFERENCED, $refus->reason);
            // Le refus NOMME ce qui bloque, sinon l operateur ne peut rien faire.
            $this->assertStringContainsString('messages', (string) $refus->parametres['inventaire']);
        }

        $this->assertSame($avant, $editeur->json(), 'AUCUNE suppression partielle.');
    }

    public function test_supprimer_une_personne_REFERENCEE_est_refuse(): void
    {
        $editeur = $this->editeurAvecUnePersonne($clePersonne);

        $editeur->ajouterBoucle([
            'name' => 'Atelier IA',
            'description' => 'Une boucle.',
            'type' => 'general',
            'owner' => $clePersonne,
            'visibility' => 'private',
            'access_mode' => 'open',
        ]);

        $avant = $editeur->json();

        try {
            $editeur->supprimerPersonne($clePersonne);
            $this->fail('La suppression aurait du etre refusee.');
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::STILL_REFERENCED, $refus->reason);
        }

        $this->assertSame($avant, $editeur->json());
    }

    public function test_supprimer_une_personne_NON_referencee_passe(): void
    {
        // L'autre sens. Sans lui, refuser TOUTE suppression resterait vert sur
        // le test precedent — et l'ecran serait inutilisable.
        $editeur = $this->editeurAvecUnePersonne($clePersonne);
        $seconde = $editeur->ajouterPersonne([
            'first_name' => 'Sonia', 'name' => 'Sonia Meyer', 'email' => 'sonia@atelier.test',
            'organization_role' => 'member', 'available' => true,
        ]);

        $editeur->supprimerPersonne($seconde);

        $this->assertNull($this->ligneOuNull($editeur->document(), 'users', $seconde));
        $this->assertNotNull($this->ligneOuNull($editeur->document(), 'users', $clePersonne));
    }

    // =====================================================================
    // Les stable keys
    // =====================================================================

    public function test_les_clefs_generees_sont_des_stable_keys_jamais_des_UUID(): void
    {
        $editeur = $this->editeurAvecUnePersonne($clePersonne);

        $cleBoucle = $editeur->ajouterBoucle([
            'name' => 'Atelier IA 2026',
            'description' => 'Une boucle.',
            'type' => 'general',
            'owner' => $clePersonne,
            'visibility' => 'private',
            'access_mode' => 'open',
        ]);

        $boucle = $this->ligne($editeur->document(), 'loops', $cleBoucle);

        foreach ([$clePersonne, $cleBoucle, (string) $boucle['root_dossier']] as $cle) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/',
                $cle,
                "« {$cle} » doit respecter le contrat de stable key."
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-/',
                $cle,
                'Une clef Manifest n est JAMAIS un UUID.'
            );
        }

        // Deux objets du meme nom ne se marchent pas dessus.
        $seconde = $editeur->ajouterBoucle([
            'name' => 'Atelier IA 2026',
            'description' => 'Une autre boucle.',
            'type' => 'general',
            'owner' => $clePersonne,
            'visibility' => 'private',
            'access_mode' => 'open',
        ]);

        $this->assertNotSame($cleBoucle, $seconde);
    }

    // =====================================================================
    // Par l'ECRAN : revalidation, lifecycle, et ce qui ne doit PAS naitre
    // =====================================================================

    public function test_chaque_mutation_de_l_ecran_REVALIDE_le_document(): void
    {
        [$superAdmin, $version] = $this->versionEditable();

        // Le document de depart est un squelette : incomplet, donc DRAFT.
        $this->actingAs($superAdmin)
            ->put(route('admin.outils.scenarios.visual.general', $version), $this->general())
            ->assertRedirect();

        $version->refresh();

        // La revalidation a eu lieu : un resume existe, avec un verdict.
        $this->assertNotNull($version->validation_summary, 'Chaque mutation doit laisser un verdict frais.');
        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $version->state, 'Un squelette incomplet reste DRAFT.');

        // On complete jusqu'a VALID, par l'ecran seulement.
        $this->actingAs($superAdmin)
            ->post(route('admin.outils.scenarios.visual.person.store', $version), $this->personne())
            ->assertRedirect();

        $cle = $this->premiereCle($version->refresh(), 'users');

        $this->actingAs($superAdmin)
            ->post(route('admin.outils.scenarios.visual.loop.store', $version), $this->boucle($cle))
            ->assertRedirect();

        $version->refresh();

        $this->assertSame(
            ScenarioManifestVersion::STATE_VALID,
            $version->state,
            'Le parcours visuel seul doit suffire a produire un document VALID.'
        );
    }

    public function test_modifier_une_version_APPROUVEE_efface_son_approbation(): void
    {
        // CE QUE CE TEST PROUVE, ET LA NUANCE QU'IL ASSUME.
        //
        // Le CDC demande « toute modification repasse DRAFT ». C'est
        // exactement ce que fait `updateDocument()`, et l'approbation part
        // avec. Mais la mutation visuelle REVALIDE ensuite — le CDC l'exige
        // aussi — donc un document qui reste valide redevient VALID dans le
        // meme geste. Le passage en DRAFT est reel, il est simplement
        // TRANSITOIRE.
        //
        // L'etat n'est donc pas la bonne grandeur a asserter : il decrirait le
        // milieu du geste, pas sa garantie. Ce qui protege VRAIMENT, et qui
        // est durable, c'est que l'APPROBATION est effacee — c'est elle, et
        // elle seule, qui ouvre la porte du Load (T1650).
        [$superAdmin, $version] = $this->versionValideParLEcran();

        $approbateur = User::factory()->create(['organization_id' => $superAdmin->organization_id, 'is_admin' => true]);
        app(\App\Support\ScenarioManager\ScenarioLifecycleService::class)->approve($version, $approbateur);

        $version->refresh();
        $this->assertTrue($version->approvalMatchesCurrentDigest(), 'Point de depart : la version est approuvee.');

        $digestApprouve = $version->approved_digest;

        $this->actingAs($superAdmin)
            ->post(route('admin.outils.scenarios.visual.person.store', $version), $this->personne('Sonia', 'sonia@atelier.test'))
            ->assertRedirect();

        $version->refresh();

        $this->assertNull($version->approved_digest, 'L approbation est effacee par la modification.');
        $this->assertFalse($version->approvalMatchesCurrentDigest(), 'Et la porte du Load est donc refermee.');
        $this->assertNotSame($digestApprouve, $version->digest, 'Le document a REELLEMENT change.');
    }

    public function test_une_version_CHARGEE_n_est_pas_editable_visuellement(): void
    {
        [$superAdmin, $version] = $this->versionValideParLEcran();

        // On simule l'etat LOADED sans passer par le moteur : `isLoaded()` est
        // derive du lien administratif.
        $chargement = \App\Models\ScenarioPackLoad::query()->create([
            'pack_id' => 'manifest:test',
            'pack_version' => '1.0.0',
            'organization_id' => \App\Models\Organization::factory()->create()->id,
            'loaded_at' => now(),
        ]);
        $version->forceFill(['scenario_pack_load_id' => $chargement->id])->save();

        $avant = $version->fresh()->json_source;

        $this->actingAs($superAdmin)
            ->post(route('admin.outils.scenarios.visual.person.store', $version), $this->personne('Intrus', 'intrus@atelier.test'))
            ->assertSessionHasErrors('scenario');

        $this->assertSame($avant, $version->fresh()->json_source, 'Le document d une version chargee ne bouge pas.');

        // Et l'ecran le DIT, au lieu d'offrir des formulaires inertes.
        $html = $this->actingAs($superAdmin)
            ->get(route('admin.outils.scenarios.visual', $version))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-visual-readonly', $html);
    }

    public function test_l_editeur_ne_cree_AUCUNE_donnee_metier_reelle(): void
    {
        // LA garantie multi-tenant de cette TASK : l'editeur decrit un monde,
        // il ne le fabrique pas. Aucune Organization, aucun compte, aucune
        // Boucle reelle ne doit naitre d'une saisie.
        [$superAdmin, $version] = $this->versionEditable();

        $organizations = \App\Models\Organization::query()->count();
        $comptes = User::query()->count();
        $boucles = \App\Models\Loop::query()->count();
        $adminsPlateforme = User::query()->where('is_admin', true)->count();

        $this->actingAs($superAdmin)->put(route('admin.outils.scenarios.visual.general', $version), $this->general());
        $this->actingAs($superAdmin)->post(route('admin.outils.scenarios.visual.person.store', $version), $this->personne());

        $cle = $this->premiereCle($version->refresh(), 'users');
        $this->actingAs($superAdmin)->post(route('admin.outils.scenarios.visual.loop.store', $version), $this->boucle($cle));

        $this->assertSame($organizations, \App\Models\Organization::query()->count(), 'Aucune Organization reelle.');
        $this->assertSame($comptes, User::query()->count(), 'Aucun compte reel.');
        $this->assertSame($boucles, \App\Models\Loop::query()->count(), 'Aucune Boucle reelle.');
        $this->assertSame($adminsPlateforme, User::query()->where('is_admin', true)->count(), 'Aucun privilege plateforme touche.');

        // Le document, lui, a REELLEMENT change.
        $document = json_decode((string) $version->fresh()->json_source, true);
        $this->assertCount(1, $document['users']);
        $this->assertCount(1, $document['loops']);
    }

    public function test_le_mode_JSON_et_le_mode_visuel_montrent_le_MEME_document(): void
    {
        [$superAdmin, $version] = $this->versionValideParLEcran();

        $document = json_decode((string) $version->json_source, true);
        $clePersonne = $document['users'][0]['key'];
        $cleBoucle = $document['loops'][0]['key'];

        // L'ecran visuel rend les objets du document, par leurs crochets.
        $visuel = $this->actingAs($superAdmin)->get(route('admin.outils.scenarios.visual', $version))->assertOk()->getContent();
        $this->assertStringContainsString('data-person="'.$clePersonne.'"', $visuel);
        $this->assertStringContainsString('data-loop="'.$cleBoucle.'"', $visuel);

        // Le mode JSON rend le MEME texte : une seule source, pas deux.
        $json = $this->actingAs($superAdmin)->get(route('admin.outils.scenarios.edit', $version))->assertOk()->getContent();
        $this->assertStringContainsString(e($clePersonne), $json);
        $this->assertStringContainsString(e($cleBoucle), $json);
    }

    // =====================================================================
    // Outillage
    // =====================================================================

    /**
     * @return array{0: User, 1: ScenarioManifestVersion}
     */
    private function versionEditable(): array
    {
        $superAdmin = User::factory()->create([
            'organization_id' => \App\Models\Organization::factory()->create()->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);

        $version = app(\App\Support\ScenarioManager\ScenarioVersionWriter::class)
            ->createBlank('atelier', 'Atelier', 'fr', $superAdmin);

        return [$superAdmin, $version];
    }

    /**
     * Un scenario mene jusqu'a VALID par le SEUL parcours visuel.
     *
     * @return array{0: User, 1: ScenarioManifestVersion}
     */
    private function versionValideParLEcran(): array
    {
        [$superAdmin, $version] = $this->versionEditable();

        $this->actingAs($superAdmin)->put(route('admin.outils.scenarios.visual.general', $version), $this->general());
        $this->actingAs($superAdmin)->post(route('admin.outils.scenarios.visual.person.store', $version), $this->personne());

        $cle = $this->premiereCle($version->refresh(), 'users');
        $this->actingAs($superAdmin)->post(route('admin.outils.scenarios.visual.loop.store', $version), $this->boucle($cle));

        return [$superAdmin, $version->refresh()];
    }

    /**
     * @return array<string, string>
     */
    private function general(): array
    {
        return [
            'name' => 'Atelier',
            'version' => '1.0.0',
            'description' => 'Un scenario de demonstration pour la recette.',
            'purpose' => 'Montrer le parcours visuel.',
            'locale' => 'fr',
            'organization_name' => 'Atelier IA',
            'organization_proposed_slug' => 'atelier-ia',
            'organization_description' => 'Organization sandbox de demonstration.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personne(string $prenom = 'Nora', string $email = 'nora@atelier.test'): array
    {
        return [
            'first_name' => $prenom,
            'name' => $prenom.' Benali',
            'email' => $email,
            'organization_role' => 'member',
            'available' => '1',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function boucle(string $proprietaire): array
    {
        return [
            'name' => 'Atelier IA',
            'description' => 'Une boucle de demonstration.',
            'type' => 'training',
            'owner' => $proprietaire,
            'visibility' => 'private',
            'access_mode' => 'invitation',
        ];
    }

    private function premiereCle(ScenarioManifestVersion $version, string $collection): string
    {
        $document = json_decode((string) $version->json_source, true);

        return (string) ($document[$collection][0]['key'] ?? '');
    }

    private function editeurAvecUnePersonne(?string &$clePersonne): ScenarioVisualEditor
    {
        $editeur = ScenarioVisualEditor::pour(ScenarioManifestSkeleton::json('atelier', 'Atelier', 'fr'));

        $editeur->majGeneral([
            'name' => 'Atelier',
            'version' => '1.0.0',
            'description' => 'Un scenario de demonstration pour la recette.',
            'purpose' => 'Montrer le parcours visuel.',
            'locale' => 'fr',
            'organization_name' => 'Atelier IA',
            'organization_proposed_slug' => 'atelier-ia',
            'organization_description' => 'Organization sandbox de demonstration.',
        ]);

        $clePersonne = $editeur->ajouterPersonne([
            'first_name' => 'Nora',
            'name' => 'Nora Benali',
            'email' => 'nora@atelier.test',
            'organization_role' => 'admin',
            'available' => true,
            'bio' => 'Responsable de la communaute.',
        ]);

        return $editeur;
    }

    /**
     * Le nombre d'objets de CHAQUE collection du document.
     *
     * Generique a dessein : aucune liste de collections n'est ecrite ici, donc
     * une collection ajoutee au Manifest entre d'elle-meme dans la mesure.
     *
     * @return array<string, int>
     */
    private function comptageParCollection(ScenarioVisualEditor $editeur): array
    {
        $comptage = [];

        foreach ($editeur->document() as $nom => $contenu) {
            if (is_array($contenu) && array_is_list($contenu)) {
                $comptage[$nom] = count($contenu);
            }
        }

        return $comptage;
    }

    /**
     * @param  array<string, int>  $avant
     * @param  array<string, int>  $apres
     * @return array<string, int>
     */
    private function difference(array $avant, array $apres): array
    {
        $delta = [];

        foreach ($apres as $nom => $nombre) {
            $ecart = $nombre - ($avant[$nom] ?? 0);

            if ($ecart !== 0) {
                $delta[$nom] = $ecart;
            }
        }

        ksort($delta);

        return $delta;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function ligne(array $document, string $collection, string $cle): array
    {
        $ligne = $this->ligneOuNull($document, $collection, $cle);

        $this->assertNotNull($ligne, "« {$cle} » doit exister dans {$collection}.");

        return $ligne;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    private function ligneOuNull(array $document, string $collection, string $cle): ?array
    {
        foreach ($document[$collection] ?? [] as $ligne) {
            if (($ligne['key'] ?? null) === $cle) {
                return $ligne;
            }
        }

        return null;
    }
}
