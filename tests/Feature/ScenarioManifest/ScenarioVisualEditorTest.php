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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function famillesQuiReferencentUneBoucle(): array
    {
        // La matrice est DECOUVERTE dans le schema, jamais ecrite a la main :
        // une famille ajoutee demain au Manifest entre d'elle-meme dans ce
        // test, et une famille retiree le fait rougir.
        $cas = [];

        foreach (\App\Support\ScenarioManifest\ManifestSchema::envelope() as $collection => $spec) {
            // Forme reelle d'une collection : `type: array` + `of.fields`.
            if (($spec['type'] ?? null) !== 'array') {
                continue;
            }

            foreach ($spec['of']['fields'] ?? [] as $champ => $regle) {
                if (($regle['type'] ?? null) === 'ref' && ($regle['collection'] ?? null) === 'loops') {
                    $cas[$collection.'.'.$champ] = [$collection, $champ];
                }
            }
        }

        return $cas;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('famillesQuiReferencentUneBoucle')]
    public function test_chaque_famille_qui_reference_une_Boucle_BLOQUE_sa_suppression(string $collection, string $champ): void
    {
        // MASTER : « si certaines familles ne peuvent reellement pas
        // referencer la Loop, ne fabrique pas de test artificiel ». La liste
        // vient donc du schema — messages, sondages, evenements, decisions,
        // feuille de route, modules et devoirs de formation, mises en avant
        // de services. Toutes sont eprouvees, aucune n'est inventee.
        $editeur = $this->editeurAvecUnePersonne($clePersonne);

        $cleBoucle = $editeur->ajouterBoucle($this->boucle($clePersonne));

        // `memberships` et le Dossier RACINE accompagnent la Boucle : ils ne
        // doivent PAS bloquer. Les autres, si.
        $accompagne = $collection === 'memberships';

        $document = $editeur->document();
        $document[$collection][] = ['key' => 'temoin-'.$collection, $champ => $cleBoucle];
        $editeur = ScenarioVisualEditor::pour(json_encode($document));

        $avant = $editeur->json();

        if ($accompagne) {
            $editeur->supprimerBoucle($cleBoucle);
            $this->assertNotSame($avant, $editeur->json(), 'Un membership accompagne la Boucle.');

            return;
        }

        try {
            $editeur->supprimerBoucle($cleBoucle);
            $this->fail("Une reference depuis {$collection}.{$champ} aurait du bloquer la suppression.");
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::STILL_REFERENCED, $refus->reason);
            // Le message DIT ce qui bloque, il ne dit pas « impossible ».
            $this->assertStringContainsString($collection, (string) $refus->parametres['inventaire']);
        }

        $this->assertSame($avant, $editeur->json(), 'Aucune suppression partielle.');
    }

    public function test_un_Dossier_ENFANT_bloque_la_suppression_de_la_Boucle(): void
    {
        // Le Dossier racine accompagne la Boucle ; un sous-Dossier, non. La
        // distinction se joue sur la clef, pas sur la collection.
        $editeur = $this->editeurAvecUnePersonne($clePersonne);
        $cleBoucle = $editeur->ajouterBoucle($this->boucle($clePersonne));

        $document = $editeur->document();
        $cleRacine = $document['loops'][0]['root_dossier'];
        $document['dossiers'][] = [
            'key' => 'sous-dossier',
            'name' => 'Sous-dossier',
            'owner' => $clePersonne,
            'loop' => $cleBoucle,
            'parent' => $cleRacine,
            'visibility' => 'loop',
            'root_document' => null,
        ];
        $editeur = ScenarioVisualEditor::pour(json_encode($document));

        $avant = $editeur->json();

        try {
            $editeur->supprimerBoucle($cleBoucle);
            $this->fail('Un sous-Dossier aurait du bloquer la suppression.');
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::STILL_REFERENCED, $refus->reason);
        }

        $this->assertSame($avant, $editeur->json());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function famillesQuiReferencentUnDossier(): array
    {
        $cas = [];

        foreach (\App\Support\ScenarioManifest\ManifestSchema::envelope() as $collection => $spec) {
            if (($spec['type'] ?? null) !== 'array') {
                continue;
            }

            foreach ($spec['of']['fields'] ?? [] as $champ => $regle) {
                if (($regle['type'] ?? null) === 'ref' && ($regle['collection'] ?? null) === 'dossiers'
                    && $collection !== 'loops' && $collection !== 'dossiers') {
                    $cas[$collection.'.'.$champ] = [$collection, $champ];
                }
            }
        }

        return $cas;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('famillesQuiReferencentUnDossier')]
    public function test_un_objet_dans_l_espace_documents_BLOQUE_la_suppression(string $collection, string $champ): void
    {
        // Articles et fichiers vivent DANS un Dossier. Supprimer la Boucle
        // emporterait son espace documents — donc eux aussi, en silence.
        $editeur = $this->editeurAvecUnePersonne($clePersonne);
        $cleBoucle = $editeur->ajouterBoucle($this->boucle($clePersonne));

        $document = $editeur->document();
        $cleRacine = $document['loops'][0]['root_dossier'];
        $document[$collection][] = ['key' => 'temoin-'.$collection, $champ => $cleRacine];
        $editeur = ScenarioVisualEditor::pour(json_encode($document));

        $avant = $editeur->json();

        try {
            $editeur->supprimerBoucle($cleBoucle);
            $this->fail("Une reference depuis {$collection}.{$champ} aurait du bloquer.");
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::STILL_REFERENCED, $refus->reason);
        }

        $this->assertSame($avant, $editeur->json());
    }

    // =====================================================================
    // Memberships : un seul proprietaire, et il est le bon
    // =====================================================================

    public function test_changer_le_proprietaire_d_une_Boucle_ne_laisse_AUCUN_owner_residuel(): void
    {
        // `loops[].owner` et le membership `owner` decrivent le MEME fait.
        // Les laisser diverger produit un document que le Validator refuse,
        // avec une erreur que personne ne relierait au geste qui l'a causee.
        $editeur = $this->editeurAvecUnePersonne($premier);
        $second = $editeur->ajouterPersonne([
            'first_name' => 'Sonia', 'name' => 'Sonia Meyer', 'email' => 'sonia@atelier.test',
            'organization_role' => 'member', 'available' => true,
        ]);

        $cleBoucle = $editeur->ajouterBoucle($this->boucle($premier));

        $editeur->modifierBoucle($cleBoucle, ['owner' => $second]);

        $document = $editeur->document();

        $owners = array_values(array_filter(
            $document['memberships'],
            static fn (array $m): bool => $m['loop'] === $cleBoucle && $m['role'] === 'owner'
        ));

        $this->assertCount(1, $owners, 'Exactement UN membership owner par Boucle.');
        $this->assertSame($second, $owners[0]['user'], 'Et c est le nouveau proprietaire.');
        $this->assertSame($second, $this->ligne($document, 'loops', $cleBoucle)['owner']);

        // Le Dossier racine et son document suivent : leur proprietaire doit
        // etre membre de la Boucle, sinon OWNER_MEMBERSHIP_MISMATCH.
        $dossier = $this->ligne($document, 'dossiers', (string) $this->ligne($document, 'loops', $cleBoucle)['root_dossier']);
        $this->assertSame($second, $dossier['owner']);
        $this->assertSame($second, $dossier['root_document']['author']);

        // Et le Validator est d accord — c est lui qui tranche, pas moi.
        $this->assertTrue(
            app(ScenarioManifestValidator::class)->validate($editeur->json())->isValid(),
            'Le transfert de propriete doit laisser un document valide.'
        );
    }

    public function test_supprimer_un_persona_encore_PROPRIETAIRE_est_refuse(): void
    {
        $editeur = $this->editeurAvecUnePersonne($clePersonne);
        $editeur->ajouterBoucle($this->boucle($clePersonne));

        $avant = $editeur->json();

        try {
            $editeur->supprimerPersonne($clePersonne);
            $this->fail('Supprimer le proprietaire d une Boucle aurait du etre refuse.');
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::STILL_REFERENCED, $refus->reason);
            // Le refus NOMME les dependances : proprietaire de Boucle, membre,
            // proprietaire du Dossier, auteur du document.
            $inventaire = (string) $refus->parametres['inventaire'];
            $this->assertStringContainsString('loops', $inventaire);
            $this->assertStringContainsString('memberships', $inventaire);
        }

        $this->assertSame($avant, $editeur->json(), 'Aucune cascade, aucune suppression partielle.');
    }

    public function test_definir_puis_retirer_un_role_ne_cree_aucun_doublon(): void
    {
        $editeur = $this->editeurAvecUnePersonne($proprietaire);
        $second = $editeur->ajouterPersonne([
            'first_name' => 'Sonia', 'name' => 'Sonia Meyer', 'email' => 'sonia@atelier.test',
            'organization_role' => 'member', 'available' => true,
        ]);

        $cleBoucle = $editeur->ajouterBoucle($this->boucle($proprietaire));

        $editeur->definirRole($cleBoucle, $second, 'member');
        $editeur->definirRole($cleBoucle, $second, 'facilitator');

        $lignes = array_values(array_filter(
            $editeur->document()['memberships'],
            static fn (array $m): bool => $m['loop'] === $cleBoucle && $m['user'] === $second
        ));

        $this->assertCount(1, $lignes, 'Changer un role MODIFIE la ligne, il n en ajoute pas une seconde.');
        $this->assertSame('facilitator', $lignes[0]['role']);

        $editeur->definirRole($cleBoucle, $second, null);

        $this->assertCount(0, array_filter(
            $editeur->document()['memberships'],
            static fn (array $m): bool => $m['loop'] === $cleBoucle && $m['user'] === $second
        ), 'Retirer le role supprime la ligne.');

        // Le proprietaire, lui, n a pas bouge.
        $this->assertCount(1, array_filter(
            $editeur->document()['memberships'],
            static fn (array $m): bool => $m['loop'] === $cleBoucle && $m['role'] === 'owner'
        ));
    }

    public function test_aucune_mutation_ne_fabrique_un_persona(): void
    {
        // Ni la creation de Boucle, ni un membership, ni un transfert : le
        // SuperAdmin est l auteur de son monde, on n y ajoute personne a sa
        // place.
        $editeur = $this->editeurAvecUnePersonne($clePersonne);
        $avant = count($editeur->document()['users']);

        $cleBoucle = $editeur->ajouterBoucle($this->boucle($clePersonne));
        $editeur->definirRole($cleBoucle, $clePersonne, 'owner');
        $editeur->modifierBoucle($cleBoucle, ['name' => 'Atelier renomme']);

        $this->assertCount($avant, $editeur->document()['users'], 'Aucun persona ne nait d une mutation.');
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

        // LE contrat : le Validator est vert, et l'etat reste DRAFT.
        //
        // VALID signifie « techniquement vert ET confirme par un humain sur un
        // digest precis » (CDC 12.2). Une suite de mutations visuelles ne doit
        // jamais reconstituer cette confirmation — c'est ce que faisait la
        // premiere version de cet ecran.
        $this->assertSame([], $version->validation_summary['errors'] ?? null, 'Le Validator technique est vert.');
        $this->assertNotNull($version->digest, 'Et le digest est a jour.');
        $this->assertSame(
            ScenarioManifestVersion::STATE_DRAFT,
            $version->state,
            'Validator vert ne vaut PAS confirmation humaine : l etat reste DRAFT.'
        );
    }

    public function test_une_version_APPROUVEE_modifiee_repasse_DRAFT_et_exige_une_NOUVELLE_confirmation(): void
    {
        [$superAdmin, $version] = $this->versionValideParLEcran();

        // On amene la version jusqu'au bout du cycle humain : VALID puis
        // approuvee. C'est l'etat depuis lequel un Load est possible.
        app(\App\Support\ScenarioManager\ScenarioVersionWriter::class)->validate($version);
        $version->refresh();
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->state);

        app(\App\Support\ScenarioManager\ScenarioLifecycleService::class)->approve($version, $superAdmin);
        $version->refresh();
        $this->assertTrue($version->approvalMatchesCurrentDigest(), 'Point de depart : approuvee, donc chargeable.');

        // Une seule modification visuelle.
        $this->actingAs($superAdmin)
            ->post(route('admin.outils.scenarios.visual.person.store', $version), $this->personne('Sonia', 'sonia@atelier.test'))
            ->assertRedirect();

        $version->refresh();

        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $version->state, 'VALID -> DRAFT.');
        $this->assertNull($version->approved_digest, 'approved_digest efface.');
        $this->assertNull($version->approved_by, 'approved_by efface.');
        $this->assertNull($version->approved_at, 'approved_at efface.');
        $this->assertNotNull($version->validation_summary, 'validation_summary recalcule.');
        $this->assertFalse($version->approvalMatchesCurrentDigest(), 'La porte du Load est refermee.');

        // Et le document est TOUJOURS techniquement vert : c'est bien la
        // confirmation humaine qui manque, pas la validite.
        $this->assertSame([], $version->validation_summary['errors'] ?? null);
    }

    public function test_corriger_un_DRAFT_invalide_le_laisse_DRAFT_meme_une_fois_vert(): void
    {
        // L'autre sens du meme contrat : partir d'invalide et arriver a vert
        // ne fait pas franchir la porte humaine non plus.
        [$superAdmin, $version] = $this->versionEditable();

        $this->actingAs($superAdmin)->put(route('admin.outils.scenarios.visual.general', $version), $this->general());
        $version->refresh();

        $this->assertNotSame([], $version->validation_summary['errors'] ?? [], 'Point de depart : document incomplet.');
        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $version->state);

        // Les mutations correctives qui rendent le document complet.
        $this->actingAs($superAdmin)->post(route('admin.outils.scenarios.visual.person.store', $version), $this->personne());
        $cle = $this->premiereCle($version->refresh(), 'users');
        $this->actingAs($superAdmin)->post(route('admin.outils.scenarios.visual.loop.store', $version), $this->boucle($cle));

        $version->refresh();

        $this->assertSame([], $version->validation_summary['errors'] ?? null, 'Le Validator est desormais vert.');
        $this->assertSame(ScenarioManifestVersion::STATE_DRAFT, $version->state, 'Et l etat reste DRAFT.');
    }

    public function test_une_version_CHARGEE_n_est_pas_editable_visuellement(): void
    {
        [$superAdmin, $version] = $this->versionValideParLEcran();

        // L'etat LOADED est DERIVE : `isLoaded()` exige l'etat VALID **et** le
        // lien administratif. On reproduit donc les deux, par le vrai chemin
        // humain pour le premier — un load id pose sur un DRAFT ne decrit
        // aucun etat reel, et le test ne prouverait rien.
        app(\App\Support\ScenarioManager\ScenarioVersionWriter::class)->validate($version);
        $version->refresh();
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->state);

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
