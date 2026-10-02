<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManager\ScenarioManifestSkeleton;
use App\Support\ScenarioManager\ScenarioVersionRefused;
use App\Support\ScenarioManager\ScenarioVersionWriter;
use App\Support\ScenarioManager\ScenarioVisualEditor;
use App\Support\ScenarioManifest\ManifestSchema;
use App\Support\ScenarioManifest\ManifestShapeValidator;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\DataProvider;
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
        //
        // AUCUNE personne au document : la premiere garde.
        $editeur = ScenarioVisualEditor::pour(ScenarioManifestSkeleton::json('atelier', 'Atelier', 'fr'));

        $this->assertSame([], $editeur->document()['users'] ?? []);
        $this->refuseLaCreation($editeur, 'nora');
    }

    public function test_creer_une_boucle_pour_une_personne_INCONNUE_est_refuse(): void
    {
        // La SECONDE garde, isolee. Elles rendent toutes deux NO_PERSONA, et
        // le test d'origine partait d'un squelette SANS personne ET avec un
        // proprietaire inconnu : les deux conditions etaient vraies ensemble,
        // donc retirer l'une OU l'autre laissait le test vert. C'est le piege
        // « deux gardes au meme message » deja paye en T1644.
        //
        // Ici une personne EXISTE, et c'est le proprietaire propose qui est
        // inconnu — le cas reellement atteignable par une requete forgee.
        $editeur = $this->editeurAvecUnePersonne($clePersonne);

        $this->assertNotSame([], $editeur->document()['users']);
        $this->refuseLaCreation($editeur, 'personne-inexistante');
    }

    private function refuseLaCreation(ScenarioVisualEditor $editeur, string $proprietaire): void
    {
        $avant = $editeur->json();

        try {
            $editeur->ajouterBoucle([
                'name' => 'Atelier IA',
                'description' => 'Une boucle.',
                'type' => 'general',
                'owner' => $proprietaire,
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
        return self::famillesQuiReferencent('loops');
    }

    /**
     * Toute collection du Manifest qui porte un champ pointant vers `$cible`.
     *
     * ## Pourquoi ce parcours descend, et pourquoi c'est le coeur du test
     *
     * La premiere version de ce provider ne regardait que les collections de
     * PREMIER niveau (`type === 'array'`). Elle sautait donc `training`, qui
     * est un OBJET dans l'enveloppe — et avec lui cinq collections reelles,
     * dont quatre designent une Boucle ou une personne. Le provider rendait 9
     * cas et le commentaire du test affirmait couvrir « modules et devoirs de
     * formation » : c'etait faux, et le code de production avait exactement le
     * meme angle mort.
     *
     * Le chemin rendu est POINTE (`training.modules`) : le test l'ecrit avec
     * `data_set`, et l'inventaire du refus se lit sur son premier segment,
     * puisque c'est ainsi que la production attribue ses comptes.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, array{0: string, 1: string}>
     */
    private static function famillesQuiReferencent(string $cible, ?array $spec = null, string $prefixe = ''): array
    {
        $spec ??= ['type' => 'object', 'fields' => ManifestSchema::envelope()];
        $cas = [];

        foreach ($spec['fields'] ?? [] as $nom => $sous) {
            if (! is_array($sous)) {
                continue;
            }

            $chemin = $prefixe === '' ? $nom : $prefixe.'.'.$nom;

            if (($sous['type'] ?? null) === 'object') {
                // Un conteneur — `training` — n'est pas une collection : on
                // descend, on ne le compte pas.
                $cas += self::famillesQuiReferencent($cible, $sous, $chemin);

                continue;
            }

            if (($sous['type'] ?? null) !== 'array') {
                continue;
            }

            foreach ($sous['of']['fields'] ?? [] as $champ => $regle) {
                if (($regle['type'] ?? null) === 'ref' && ($regle['collection'] ?? null) === $cible) {
                    $cas[$chemin.'.'.$champ] = [$chemin, $champ];
                }
            }
        }

        return $cas;
    }

    /**
     * Le provider DECOUVRE : il doit donc lui-meme etre garde.
     *
     * Un provider qui tombe a vide ne fait pas rougir PHPUnit — il rend
     * « No tests found », c'est-a-dire un SUCCES silencieux. Ce test est le
     * seul endroit qui constate que la matrice a bien la forme attendue, et le
     * seul qui rougirait si un refactor du schema la vidait.
     */
    public function test_la_matrice_de_dependances_ATTEINT_les_familles_imbriquees(): void
    {
        $boucles = self::famillesQuiReferencentUneBoucle();

        // Les collections de premier niveau ET les collections de `training`.
        $this->assertArrayHasKey('messages.loop', $boucles);
        $this->assertArrayHasKey('training.modules.loop', $boucles);
        $this->assertArrayHasKey('training.assignments.loop', $boucles);

        // `training` lui-meme n'est pas une collection : c'est un conteneur.
        foreach (array_keys($boucles) as $cle) {
            $this->assertNotSame('training.loop', $cle);
        }

        $this->assertGreaterThanOrEqual(10, count($boucles));
        $this->assertNotSame([], self::famillesQuiReferencentUnDossier());
    }

    #[DataProvider('famillesQuiReferencentUneBoucle')]
    public function test_chaque_famille_qui_reference_une_Boucle_BLOQUE_sa_suppression(string $chemin, string $champ): void
    {
        // MASTER : « si certaines familles ne peuvent reellement pas
        // referencer la Loop, ne fabrique pas de test artificiel ». La liste
        // vient donc du schema, et le provider DESCEND : messages, sondages,
        // evenements, decisions, feuille de route, mises en avant de services,
        // ET les collections de `training` — modules et devoirs — qu'un
        // parcours de premier niveau sautait en entier.
        $editeur = $this->editeurAvecUnePersonne($clePersonne);

        $cleBoucle = $editeur->ajouterBoucle($this->boucle($clePersonne));

        // `memberships` et le Dossier RACINE accompagnent la Boucle : ils ne
        // doivent PAS bloquer. Les autres, si.
        $accompagne = $chemin === 'memberships';

        $document = $editeur->document();
        $lignes = data_get($document, $chemin, []);
        $lignes[] = ['key' => 'temoin', $champ => $cleBoucle];
        data_set($document, $chemin, $lignes);
        $editeur = ScenarioVisualEditor::pour(json_encode($document));

        $avant = $editeur->json();

        if ($accompagne) {
            $editeur->supprimerBoucle($cleBoucle);
            $this->assertNotSame($avant, $editeur->json(), 'Un membership accompagne la Boucle.');

            return;
        }

        try {
            $editeur->supprimerBoucle($cleBoucle);
            $this->fail("Une reference depuis {$chemin}.{$champ} aurait du bloquer la suppression.");
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::STILL_REFERENCED, $refus->reason);
            // Le message DIT ce qui bloque, il ne dit pas « impossible ».
            // L'inventaire est attribue au conteneur de premier niveau.
            $this->assertStringContainsString(
                explode('.', $chemin)[0],
                (string) $refus->parametres['inventaire']
            );
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
        // `loops` et `dossiers` designent leur PROPRE racine : elle part avec
        // la Boucle, donc elles ne bloquent pas — c'est l'exclusion mesuree
        // par ailleurs.
        return array_filter(
            self::famillesQuiReferencent('dossiers'),
            static fn (array $cas): bool => $cas[0] !== 'loops' && $cas[0] !== 'dossiers'
        );
    }

    #[DataProvider('famillesQuiReferencentUnDossier')]
    public function test_un_objet_dans_l_espace_documents_BLOQUE_la_suppression(string $chemin, string $champ): void
    {
        // Articles et fichiers vivent DANS un Dossier. Supprimer la Boucle
        // emporterait son espace documents — donc eux aussi, en silence.
        $editeur = $this->editeurAvecUnePersonne($clePersonne);
        $cleBoucle = $editeur->ajouterBoucle($this->boucle($clePersonne));

        $document = $editeur->document();
        $cleRacine = $document['loops'][0]['root_dossier'];
        $lignes = data_get($document, $chemin, []);
        $lignes[] = ['key' => 'temoin', $champ => $cleRacine];
        data_set($document, $chemin, $lignes);
        $editeur = ScenarioVisualEditor::pour(json_encode($document));

        $avant = $editeur->json();

        try {
            $editeur->supprimerBoucle($cleBoucle);
            $this->fail("Une reference depuis {$chemin}.{$champ} aurait du bloquer.");
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
        app(ScenarioVersionWriter::class)->validate($version);
        $version->refresh();
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->state);

        app(ScenarioLifecycleService::class)->approve($version, $superAdmin);
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
        app(ScenarioVersionWriter::class)->validate($version);
        $version->refresh();
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->state);

        $chargement = ScenarioPackLoad::query()->create([
            'pack_id' => 'manifest:test',
            'pack_version' => '1.0.0',
            'organization_id' => Organization::factory()->create()->id,
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

        $organizations = Organization::query()->count();
        $comptes = User::query()->count();
        $boucles = Loop::query()->count();
        $adminsPlateforme = User::query()->where('is_admin', true)->count();

        $this->actingAs($superAdmin)->put(route('admin.outils.scenarios.visual.general', $version), $this->general());
        $this->actingAs($superAdmin)->post(route('admin.outils.scenarios.visual.person.store', $version), $this->personne());

        $cle = $this->premiereCle($version->refresh(), 'users');
        $this->actingAs($superAdmin)->post(route('admin.outils.scenarios.visual.loop.store', $version), $this->boucle($cle));

        $this->assertSame($organizations, Organization::query()->count(), 'Aucune Organization reelle.');
        $this->assertSame($comptes, User::query()->count(), 'Aucun compte reel.');
        $this->assertSame($boucles, Loop::query()->count(), 'Aucune Boucle reelle.');
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
        //
        // On EXTRAIT le contenu du textarea et on compare les DOCUMENTS
        // DECODES. Chercher « nora » dans la page etait satisfait par du
        // mobilier : `nora` est un fragment de `nora@atelier.test`, et
        // `atelier-ia` est aussi le slug propose de l'Organization. Les deux
        // assertions passaient donc meme si `users` et `loops` avaient disparu
        // du texte rendu — exactement la divergence que ce test doit exclure.
        $json = $this->actingAs($superAdmin)->get(route('admin.outils.scenarios.edit', $version))->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('#<textarea[^>]*name="json"[^>]*>(.*?)</textarea>#s', $json, $trouve),
            'Le mode JSON doit exposer le document dans un textarea nomme « json ».'
        );

        $documentAffiche = json_decode(html_entity_decode($trouve[1], ENT_QUOTES, 'UTF-8'), true);

        $this->assertIsArray($documentAffiche);
        $this->assertSame($document, $documentAffiche, 'Un seul document, pas deux representations.');

        // Et les deux ecrans nomment bien les MEMES objets.
        $this->assertSame($clePersonne, $documentAffiche['users'][0]['key']);
        $this->assertSame($cleBoucle, $documentAffiche['loops'][0]['key']);
    }

    // =====================================================================
    // Les defauts trouves en relecture adverse
    // =====================================================================

    /**
     * Une clef generee reste VALIDE meme au bord de la longueur maximale.
     *
     * La clef est passee par la regex du VALIDATEUR, lue par reflexion : une
     * copie de la regex dans le test ne prouverait que la coherence du test
     * avec lui-meme. Le docblock de `slug()` affirmait la conformite ; la
     * troncature au caractere pres pouvait laisser un tiret final, que le
     * contrat refuse.
     */
    public function test_une_clef_generee_respecte_le_contrat_MEME_au_bord_de_la_longueur(): void
    {
        $motif = (new \ReflectionClass(ManifestShapeValidator::class))
            ->getConstant('STABLE_KEY_PATTERN');

        $editeur = $this->editeurAvecUnePersonne($premier);

        // 65 caracteres, dont un espace en 64e position : `max:80` l'accepte.
        $longPrenom = str_repeat('a', 63).' b';
        $this->assertSame(65, mb_strlen($longPrenom));

        $cas = [
            'prenom de 65 caracteres' => $editeur->ajouterPersonne([
                'first_name' => $longPrenom, 'name' => 'Long Nom', 'email' => 'long@atelier.test',
                'organization_role' => 'member', 'available' => true,
            ]),
            'tirets en fin de troncature' => $editeur->ajouterPersonne([
                'first_name' => str_repeat('b', 62).' - - c', 'name' => 'Autre', 'email' => 'autre@atelier.test',
                'organization_role' => 'member', 'available' => true,
            ]),
            'tout numerique' => $editeur->ajouterPersonne([
                'first_name' => '2026', 'name' => 'Annee', 'email' => 'annee@atelier.test',
                'organization_role' => 'member', 'available' => true,
            ]),
            'accents et ponctuation' => $editeur->ajouterPersonne([
                'first_name' => "Zoé--D'Éon !", 'name' => 'Zoe', 'email' => 'zoe@atelier.test',
                'organization_role' => 'member', 'available' => true,
            ]),
        ];

        // Et les clefs de COLLISION : la boucle de deduplication reservait la
        // place du suffixe en la SUPPOSANT, ce qui donnait 65 caracteres a
        // partir de « -1000 » et « base--2 » quand la coupe tombait sur un
        // tiret.
        for ($i = 0; $i < 12; $i++) {
            $cas['collision '.$i] = $editeur->ajouterPersonne([
                'first_name' => $longPrenom, 'name' => 'Homonyme', 'email' => 'h'.$i.'@atelier.test',
                'organization_role' => 'member', 'available' => true,
            ]);
        }

        foreach ($cas as $etiquette => $cle) {
            $this->assertMatchesRegularExpression($motif, $cle, "Clef invalide pour « {$etiquette} » : {$cle}");
            $this->assertLessThanOrEqual(64, mb_strlen($cle), "Clef trop longue pour « {$etiquette} » : {$cle}");
        }

        // Les clefs restent DISTINCTES : une troncature trop zelee les
        // ferait converger, et deux personnes porteraient la meme identite.
        $this->assertCount(count($cas), array_unique($cas));
    }

    /**
     * Une stable key n'est unique QUE dans sa collection.
     *
     * Une personne « alice » et une Boucle « alice » coexistent legitimement :
     * `cleLibre()` ne cherche la liberte que dans SA collection, et le
     * validateur n'impose l'unicite que par collection. Comparer des chaines
     * sans savoir vers quelle collection pointe le champ fabriquait donc de
     * FAUX refus, et rendait l'objet indelogeable en mode visuel.
     */
    public function test_supprimer_une_personne_dont_une_BOUCLE_porte_la_clef_passe(): void
    {
        [$editeur, $alice, $boucleAlice] = $this->documentAvecHomonymes();

        // La personne Alice n'est designee par RIEN : Bob possede tout.
        // `memberships[].loop` et `dossiers[].loop` valent bien « alice », mais
        // ils pointent vers la BOUCLE homonyme.
        $editeur->supprimerPersonne($alice);

        $this->assertNull($this->ligneOuNull($editeur->document(), 'users', $alice));
        $this->assertNotNull($this->ligneOuNull($editeur->document(), 'loops', $boucleAlice));
    }

    public function test_supprimer_une_Boucle_dont_une_PERSONNE_porte_la_clef_passe(): void
    {
        // L'autre moitie du piege. Ici c'est une personne « alice » qui possede
        // une AUTRE Boucle : `loops[projet].owner` vaut « alice » et pointe
        // vers une PERSONNE. Cela ne doit pas retenir la Boucle homonyme.
        $editeur = $this->editeurAvecUnePersonne($bob);

        $alice = $editeur->ajouterPersonne([
            'first_name' => 'Alice', 'name' => 'Alice Roux', 'email' => 'alice@atelier.test',
            'organization_role' => 'member', 'available' => true,
        ]);

        $boucleAlice = $editeur->ajouterBoucle(['name' => 'Alice'] + $this->boucle($bob));
        $this->assertSame($alice, $boucleAlice, 'Le cas ne vaut que si les deux clefs sont IDENTIQUES.');

        $projet = $editeur->ajouterBoucle(['name' => 'Projet'] + $this->boucle($alice));

        $editeur->supprimerBoucle($boucleAlice);

        $this->assertNull($this->ligneOuNull($editeur->document(), 'loops', $boucleAlice));
        $this->assertNotNull($this->ligneOuNull($editeur->document(), 'loops', $projet));
        // Et la PERSONNE homonyme est intacte.
        $this->assertNotNull($this->ligneOuNull($editeur->document(), 'users', $alice));
    }

    /**
     * Un document ou une personne et une Boucle portent la MEME clef.
     *
     * Legitime : `cleLibre()` ne cherche la liberte que dans sa collection, et
     * le validateur n'impose l'unicite que par collection.
     *
     * @return array{0: ScenarioVisualEditor, 1: string, 2: string}
     */
    private function documentAvecHomonymes(): array
    {
        $editeur = $this->editeurAvecUnePersonne($bob);

        $alice = $editeur->ajouterPersonne([
            'first_name' => 'Alice', 'name' => 'Alice Roux', 'email' => 'alice@atelier.test',
            'organization_role' => 'member', 'available' => true,
        ]);

        // La Boucle s'appelle « Alice » et appartient a BOB : sa clef tombe
        // donc sur la meme chaine que celle de la personne Alice.
        $boucleAlice = $editeur->ajouterBoucle(['name' => 'Alice'] + $this->boucle($bob));
        $this->assertSame($alice, $boucleAlice, 'Le cas ne vaut que si les deux clefs sont IDENTIQUES.');

        // Une seconde Boucle, possedee par Bob elle aussi.
        $editeur->ajouterBoucle(['name' => 'Projet'] + $this->boucle($bob));

        return [$editeur, $alice, $boucleAlice];
    }

    /**
     * Le sens INVERSE du test precedent : une vraie reference bloque toujours.
     *
     * Sans lui, rendre `refuserSiReference()` completement muet resterait vert
     * sur l'homonymie — et c'est le pire des deux defauts.
     */
    public function test_une_VRAIE_reference_vers_une_personne_bloque_toujours(): void
    {
        $editeur = $this->editeurAvecUnePersonne($premier);
        $editeur->ajouterBoucle($this->boucle($premier));

        try {
            $editeur->supprimerPersonne($premier);
            $this->fail('Le proprietaire d une Boucle ne se supprime pas.');
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::STILL_REFERENCED, $refus->reason);
            $this->assertStringContainsString('loops', (string) $refus->parametres['inventaire']);
        }
    }

    /**
     * Modifier une Boucle refuse un proprietaire inexistant, comme la creation.
     *
     * La creation refusait, la modification acceptait : `owner`, le membership,
     * le proprietaire du Dossier racine ET l'auteur de son document racine
     * partaient tous vers une personne qui n'existe pas.
     */
    public function test_modifier_une_Boucle_refuse_un_proprietaire_INEXISTANT(): void
    {
        $editeur = $this->editeurAvecUnePersonne($premier);
        $cleBoucle = $editeur->ajouterBoucle($this->boucle($premier));

        $avant = $editeur->json();

        try {
            $editeur->modifierBoucle($cleBoucle, ['name' => 'Renommee', 'owner' => 'fantome']);
            $this->fail('Un proprietaire inexistant aurait du etre refuse.');
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::NO_PERSONA, $refus->reason);
        }

        // Et RIEN n'a bouge : pas meme le nom, qui precede `owner` dans la
        // boucle d'ecriture.
        $this->assertSame($avant, $editeur->json());
    }

    public function test_modifier_une_Boucle_vers_un_proprietaire_EXISTANT_passe(): void
    {
        // L'autre sens : refuser tout changement de proprietaire resterait
        // vert sur le test precedent.
        $editeur = $this->editeurAvecUnePersonne($premier);
        $second = $editeur->ajouterPersonne([
            'first_name' => 'Sonia', 'name' => 'Sonia Meyer', 'email' => 'sonia@atelier.test',
            'organization_role' => 'member', 'available' => true,
        ]);
        $cleBoucle = $editeur->ajouterBoucle($this->boucle($premier));

        $editeur->modifierBoucle($cleBoucle, ['owner' => $second]);

        $document = $editeur->document();
        $this->assertSame($second, $document['loops'][0]['owner']);
    }

    /**
     * De l'UTF-8 invalide rend un REFUS lisible, jamais une exception nue.
     *
     * `ScenarioVersionWriter::encoder()` avait ferme ce trou ; appeler
     * `json()` en ARGUMENT de `updateDocument()` le reouvrait, car l'exception
     * partait avant que la garde du writer soit atteinte.
     */
    public function test_un_octet_UTF8_invalide_rend_un_refus_et_non_une_exception(): void
    {
        $editeur = $this->editeurAvecUnePersonne($premier);
        $editeur->modifierPersonne($premier, ['name' => "Nora \xB1 Benali"]);

        try {
            $editeur->json();
            $this->fail('Un octet invalide aurait du etre refuse.');
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame(ScenarioVersionRefused::BINARY_CONTENT, $refus->reason);
        }
    }

    /**
     * L'editeur visuel est ATTEIGNABLE par un lien, pas seulement par son URL.
     *
     * ## Le defaut que ce test existe pour attraper
     *
     * A la relecture adverse, `route('admin.outils.scenarios.visual')` ne
     * figurait dans AUCUNE vue : l'ecran etait livre, teste, et joignable
     * uniquement en tapant son adresse. `scenarios.edit` non plus — l'editeur
     * JSON etait orphelin depuis T1649 et le mode visuel heritait du meme
     * sort.
     *
     * Aucun test ne pouvait le voir, parce qu'ils appellent tous `route()` en
     * direct : ils prouvent que la porte s'ouvre, jamais qu'une poignee y mene.
     * Ce test part donc de la BIBLIOTHEQUE et suit des liens reels.
     */
    public function test_on_atteint_les_deux_editeurs_en_SUIVANT_des_liens(): void
    {
        [$superAdmin, $version] = $this->versionEditable();

        // Bibliotheque -> fiche.
        $this->actingAs($superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->assertSee(route('admin.outils.scenarios.show', $version), escape: false);

        // Fiche -> les DEUX editeurs.
        $fiche = $this->actingAs($superAdmin)->get(route('admin.outils.scenarios.show', $version))->assertOk();
        $fiche->assertSee(route('admin.outils.scenarios.visual', $version), escape: false);
        $fiche->assertSee(route('admin.outils.scenarios.edit', $version), escape: false);
        $fiche->assertSee('data-open-visual', escape: false);
        $fiche->assertSee('data-open-json', escape: false);

        // Et l'aller-retour entre les deux modes, dans les DEUX sens.
        $this->actingAs($superAdmin)
            ->get(route('admin.outils.scenarios.visual', $version))
            ->assertOk()
            ->assertSee(route('admin.outils.scenarios.edit', $version), escape: false);

        $this->actingAs($superAdmin)
            ->get(route('admin.outils.scenarios.edit', $version))
            ->assertOk()
            ->assertSee(route('admin.outils.scenarios.visual', $version), escape: false);
    }

    /**
     * Un document LISIBLE mais incomplet s'ouvre quand meme.
     *
     * C'est l'etat que le produit revendique : `updateDocument()` repasse en
     * DRAFT precisement pour qu'un document imparfait reste reparable, et « un
     * brouillon qu'on ne peut plus ouvrir serait un brouillon perdu ».
     *
     * La vue accedait pourtant ses clefs en direct. Avec `error_reporting(-1)`,
     * « Undefined array key 'name' » devient une `ErrorException` : coller
     * `{"loops":[{"key":"a"}]}` dans l'onglet JSON avancé rendait donc 500 sur
     * l'ecran visuel, et le seul geste de reparation partait avec.
     */
    public function test_un_document_PARSABLE_mais_incomplet_s_ouvre_sans_erreur(): void
    {
        [$superAdmin, $version] = $this->versionEditable();

        $incomplets = [
            'une Boucle sans rien' => '{"loops":[{"key":"a"}]}',
            'une personne sans nom' => '{"users":[{"key":"b"}]}',
            'un membership nu' => '{"loops":[{"key":"a"}],"users":[{"key":"b"}],"memberships":[{}]}',
            'des listes vides' => '{"users":[],"loops":[],"memberships":[]}',
            'un objet sans aucune cle' => '{}',
        ];

        foreach ($incomplets as $etiquette => $json) {
            $version->forceFill(['json_source' => $json])->save();

            $reponse = $this->actingAs($superAdmin)->get(route('admin.outils.scenarios.visual', $version));

            $reponse->assertOk("L ecran visuel doit s ouvrir sur « {$etiquette} ».");
            // Et il s'ouvre en mode EDITABLE : le bandeau « illisible » ne
            // couvre que l'illisible, pas l'incomplet.
            $reponse->assertDontSee('data-visual-unavailable', escape: false);
        }
    }

    /**
     * L'autre sens : un document VRAIMENT illisible desactive le mode visuel.
     *
     * Sans ce test, rendre le bandeau inconditionnel resterait vert au-dessus.
     */
    public function test_un_document_ILLISIBLE_desactive_le_mode_visuel(): void
    {
        [$superAdmin, $version] = $this->versionEditable();

        foreach (['{ pas du json', '"une chaine"', '[1,2,3]', ''] as $json) {
            $version->forceFill(['json_source' => $json])->save();

            $this->actingAs($superAdmin)
                ->get(route('admin.outils.scenarios.visual', $version))
                ->assertOk()
                ->assertSee('data-visual-unavailable', escape: false)
                // Et il renvoie vers le seul ecran qui sache reparer.
                ->assertSee(route('admin.outils.scenarios.edit', $version), escape: false);
        }
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
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);

        $version = app(ScenarioVersionWriter::class)
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
        return $this->compterEnDescendant($editeur->document());
    }

    /**
     * Compte les collections a TOUTE profondeur.
     *
     * Le premier comptage ne regardait que les listes de premier niveau, donc
     * il ne voyait rien sous `training`. Une ecriture parasite dans
     * `training.modules` restait invisible — y compris pour le test du
     * « exactement », celui dont c'est precisement le role.
     *
     * @param  array<string, mixed>  $noeud
     * @return array<string, int>
     */
    private function compterEnDescendant(array $noeud, string $prefixe = ''): array
    {
        $comptage = [];

        foreach ($noeud as $nom => $contenu) {
            if (! is_array($contenu)) {
                continue;
            }

            $chemin = $prefixe === '' ? (string) $nom : $prefixe.'.'.$nom;

            if (array_is_list($contenu)) {
                $comptage[$chemin] = count($contenu);

                continue;
            }

            $comptage += $this->compterEnDescendant($contenu, $chemin);
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
