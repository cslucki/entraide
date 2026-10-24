<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\DossierFileShare;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1695, geste E — le fichier partagé APPARAÎT dans « Partagés ».
 *
 * ## Le maillon qui manquait
 *
 * Après le geste D, le destinataire avait bien le droit de lire le fichier —
 * la policy le lui accordait, c'était testé — mais **rien ne lui donnait
 * l'URL**. Il aurait fallu la lui coller à la main dans la barre d'adresse.
 *
 * Une capacité qu'aucun écran n'expose est une capacité **absente**.
 *
 * ## Pas d'URL nouvelle
 *
 * Décision de Cyril : les fichiers rejoignent `?espace=partages`, dans les
 * **deux vues existantes**. Un libellé les distingue des Dossiers, sans quoi
 * la liste laisserait croire à un accès au dossier porteur.
 */
class TASK1695SharedFilesEspaceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private User $destinataire;

    private Dossier $dossier;

    private DossierFile $fichier;

    protected function setUp(): void
    {
        parent::setUp();

        // Le fichier doit exister SUR LE DISQUE : sans cela, le transport
        // rend 404 et l'on croirait a un refus d'autorisation.
        Storage::fake('local');
        Storage::disk('local')->put('dossiers/1695e/rapport.pdf', 'CONTENU_1695E');

        $this->organization = Organization::factory()->create(['slug' => 'org-1695e', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->destinataire = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);

        $this->fichier = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'original_name' => 'rapport-annuel-1695.pdf',
            // La factory remplit `display_name` d'un nom ALEATOIRE, sans
            // rapport avec `original_name`. Fixture trompeuse : mes tests
            // passaient parce que la vue lisait `original_name`, pas parce
            // qu'elle lisait le bon champ.
            //
            // L'etat REEL d'un fichier jamais renomme est `display_name ==
            // original_name` — la colonne est NOT NULL et un televersement y
            // recopie le nom depose (verifie sur les deux moteurs).
            'display_name' => 'rapport-annuel-1695.pdf',
            'disk' => 'local',
            'path' => 'dossiers/1695e/rapport.pdf',
        ]);
    }

    private function partager(): DossierFileShare
    {
        return DossierFileShare::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_file_id' => $this->fichier->id,
            'user_id' => $this->destinataire->id,
            'shared_by' => $this->proprietaire->id,
        ]);
    }

    private function espace(User $user, string $vue = 'avec-moi'): string
    {
        return $this->actingAs($user)
            ->get(route('organization.dossiers.index', [
                'organization' => $this->organization->slug,
                'espace' => 'partages',
                'vue' => $vue,
            ]))
            ->assertOk()
            ->getContent();
    }

    /** LE geste : le destinataire voit le fichier sans qu'on lui donne l'URL. */
    public function test_the_recipient_sees_the_shared_file_in_his_espace(): void
    {
        $this->partager();

        $this->assertStringContainsString(
            e($this->fichier->original_name),
            $this->espace($this->destinataire),
        );
    }

    /** Et celui qui a partagé le voit sous « Par moi ». */
    public function test_the_sharer_sees_the_file_under_shared_by_me(): void
    {
        $this->partager();

        $this->assertStringContainsString(
            e($this->fichier->original_name),
            $this->espace($this->proprietaire, 'par-moi'),
        );
    }

    /**
     * Le lien vise la route DÉDIÉE, jamais le chemin du Dossier.
     *
     * C'est toute la raison d'être des routes du geste C : le destinataire n'a
     * aucun droit sur le Dossier porteur. Un lien vers lui rendrait 403 — un
     * écran qui propose une porte fermée est pire qu'un écran muet.
     */
    public function test_the_link_points_at_the_dedicated_read_route(): void
    {
        $this->partager();
        $html = $this->espace($this->destinataire);

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

    /** Le lien suivi fonctionne VRAIMENT — pas seulement présent dans la page. */
    public function test_following_that_link_actually_serves_the_file(): void
    {
        $this->partager();

        $this->actingAs($this->destinataire)
            ->get(route('organization.dossiers.shared.preview', [
                'organization' => $this->organization->slug,
                'file' => $this->fichier->id,
            ]))
            ->assertSuccessful();
    }

    /** Sans partage, rien. L'écran ne révèle pas l'existence du fichier. */
    public function test_a_file_not_shared_with_me_never_appears(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->partager();

        $this->assertStringNotContainsString(
            e($this->fichier->original_name),
            $this->espace($etranger),
        );
    }

    /**
     * Un fichier mis à la corbeille disparaît de la liste.
     *
     * La ligne de partage lui SURVIT : la cascade SQL n'agit qu'à la
     * suppression dure. L'assertion sur `dossier_file_shares` le prouve — sans
     * elle, ce test pourrait passer parce que le partage a disparu, pas parce
     * que l'écran filtre.
     *
     * **Ce test ne prouve PAS la clause `withoutTrashed()` du contrôleur.**
     * Mesuré le 24/10 : la retirer laisse ce test vert, parce que
     * `DossierFile` porte `SoftDeletes` et que la corbeille est déjà hors
     * requête. Ce qu'il épingle est le RÉSULTAT — un fichier en corbeille ne
     * se propose pas — quelle que soit la mécanique qui l'obtient. C'est ce
     * qu'on veut tenir : la policy le refuse déjà, l'écran ne doit pas
     * proposer ce qu'elle refusera.
     */
    public function test_a_soft_deleted_file_leaves_the_list(): void
    {
        $this->partager();
        $this->fichier->delete();

        $this->assertDatabaseCount('dossier_file_shares', 1);

        $this->assertStringNotContainsString(
            e($this->fichier->original_name),
            $this->espace($this->destinataire),
        );
    }

    /**
     * La liste est un TABLEAU, à en-têtes triables.
     *
     * Ce test REMPLACE celui qui épinglait la mention « Un fichier partagé
     * s'ouvre seul… ». Cyril l'a jugée trop technique, comme celle du modal,
     * et a demandé aussi le retrait du titre « Fichiers partagés » : l'espace
     * s'appelle déjà « Partagés » et l'onglet dit « Par moi » / « Avec moi ».
     *
     * Le bouton de téléchargement part également : cliquer le **nom** ouvre
     * déjà le fichier. Deux chemins pour le même geste encombraient une
     * colonne qui sert désormais à arrêter le partage.
     *
     * Les trois assertions négatives comptent autant que les positives — sans
     * elles, ce test resterait vert sur un écran qui aurait gardé l'ancien
     * balisage à côté du nouveau.
     */
    public function test_the_list_is_a_sortable_table(): void
    {
        $this->partager();
        $html = $this->espace($this->destinataire);

        foreach (['nom', 'type', 'taille', 'qui', 'date'] as $colonne) {
            $this->assertStringContainsString(
                'data-shared-sort="'.$colonne.'"',
                $html,
                "La colonne $colonne n'est pas triable.",
            );
        }

        $this->assertStringContainsString(e(__('dossiers.col_file_type')), $html);
        $this->assertStringContainsString(e(__('dossiers.col_file_size')), $html);

        $this->assertStringNotContainsString('data-shared-file-download', $html);
        $this->assertStringNotContainsString(e(__('dossiers.shared_files_section')), $html);
        $this->assertStringNotContainsString(e(__('dossiers.shared_files_hint')), $html);
    }

    /**
     * Le tri RÉORDONNE vraiment les lignes.
     *
     * Asserter la présence des en-têtes ne prouverait que le balisage. Deux
     * fichiers, et l'ordre des deux noms dans la page est comparé — en
     * croissant puis en décroissant.
     */
    public function test_sorting_actually_reorders_the_rows(): void
    {
        $this->partager();

        $second = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'original_name' => 'zzz-dernier-1695.pdf',
            'display_name' => 'zzz-dernier-1695.pdf',
            'disk' => 'local',
            'path' => 'dossiers/1695e/rapport.pdf',
        ]);
        DossierFileShare::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_file_id' => $second->id,
            'user_id' => $this->destinataire->id,
            'shared_by' => $this->proprietaire->id,
        ]);

        $croissant = $this->actingAs($this->destinataire)
            ->get(route('organization.dossiers.index', [
                'organization' => $this->organization->slug,
                'espace' => 'partages',
                'ftri' => 'nom',
                'fsens' => 'asc',
            ]))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($croissant, 'zzz-dernier-1695.pdf'),
            strpos($croissant, 'rapport-annuel-1695.pdf'),
            'En croissant, « rapport » doit précéder « zzz ».',
        );

        $decroissant = $this->actingAs($this->destinataire)
            ->get(route('organization.dossiers.index', [
                'organization' => $this->organization->slug,
                'espace' => 'partages',
                'ftri' => 'nom',
                'fsens' => 'desc',
            ]))->assertOk()->getContent();

        $this->assertGreaterThan(
            strpos($decroissant, 'zzz-dernier-1695.pdf'),
            strpos($decroissant, 'rapport-annuel-1695.pdf'),
            'En décroissant, l\'ordre doit s\'inverser.',
        );
    }

    /**
     * Une clé de tri inconnue retombe sur le nom, sans lever.
     *
     * `?ftri=` est écrit par le client. Sans la liste blanche, il choisirait
     * la fonction de tri appliquée — une valeur venue de l'URL deviendrait du
     * code exécuté.
     */
    public function test_an_unknown_sort_key_falls_back_instead_of_breaking(): void
    {
        $this->partager();

        $this->actingAs($this->destinataire)
            ->get(route('organization.dossiers.index', [
                'organization' => $this->organization->slug,
                'espace' => 'partages',
                'ftri' => 'n_importe_quoi',
            ]))
            ->assertOk()
            ->assertSee($this->fichier->original_name);
    }

    /**
     * « Par moi » se lit sur `shared_by`, PAS sur `uploaded_by`.
     *
     * Sans cette distinction, un partage posé par quelqu'un d'autre sur un
     * fichier que j'ai téléversé apparaîtrait sous « Par moi » — en me
     * présentant comme l'auteur d'un geste que je n'ai pas fait.
     */
    public function test_shared_by_me_reads_the_author_of_the_share(): void
    {
        $autreGestionnaire = User::factory()->create(['organization_id' => $this->organization->id]);

        DossierFileShare::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_file_id' => $this->fichier->id,
            'user_id' => $this->destinataire->id,
            'shared_by' => $autreGestionnaire->id,
        ]);

        // Le téléverseur, lui, n'a rien partagé.
        $this->assertStringNotContainsString(
            e($this->fichier->original_name),
            $this->espace($this->proprietaire, 'par-moi'),
        );

        $this->assertStringContainsString(
            e($this->fichier->original_name),
            $this->espace($autreGestionnaire, 'par-moi'),
        );
    }

    /** Un espace qui ne contient QUE des fichiers ne s'annonce pas vide. */
    public function test_an_espace_holding_only_files_is_not_announced_empty(): void
    {
        $this->partager();

        $this->assertStringNotContainsString(
            e(__('dossiers.shared_with_me_empty')),
            $this->espace($this->destinataire),
        );
    }

    /**
     * Un fichier RENOMMÉ montre son nouveau nom, des deux côtés.
     *
     * Bug signalé par Cyril : « Boost Patriotique du 22.png » renommé en
     * « Boost.png » gardait son ancien nom dans « Partagés », chez le
     * partageur **comme** chez le destinataire.
     *
     * La cause était dans ma vue : le renommage écrit `display_name`, et je
     * lisais `original_name` — la trace immuable du téléversement. Les deux
     * symptômes n'en faisaient qu'un.
     *
     * Le test assert aussi l'ABSENCE de l'ancien nom : sans cela, il passerait
     * sur un écran qui afficherait les deux.
     */
    public function test_a_renamed_file_shows_its_new_name_on_both_sides(): void
    {
        $this->partager();
        $this->fichier->update(['display_name' => 'Boost.png']);

        foreach ([[$this->destinataire, 'avec-moi'], [$this->proprietaire, 'par-moi']] as [$qui, $vue]) {
            $html = $this->espace($qui, $vue);

            $this->assertStringContainsString('Boost.png', $html, "Vue $vue : le nouveau nom manque.");
            $this->assertStringNotContainsString(
                'rapport-annuel-1695.pdf',
                $html,
                "Vue $vue : l'ancien nom est encore là.",
            );
        }
    }

    /**
     * Et un fichier JAMAIS renommé garde le sien.
     *
     * Un téléversement recopie `original_name` dans `display_name` — la
     * colonne est NOT NULL. Ce test tient le cas de LOIN le plus courant :
     * 12 des 13 fichiers de la base locale sont dans cet état.
     */
    public function test_a_file_never_renamed_still_shows_its_name(): void
    {
        $this->partager();
        $this->assertSame(
            $this->fichier->original_name,
            $this->fichier->fresh()->display_name,
        );

        $this->assertStringContainsString(
            'rapport-annuel-1695.pdf',
            $this->espace($this->destinataire),
        );
    }

    /** Le partage d'une AUTRE Organization n'entre pas dans ma liste. */
    public function test_a_share_from_another_organization_never_appears(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1695e-bis', 'is_active' => true]);
        $autreFichier = DossierFile::factory()->create([
            'organization_id' => $autreOrg->id,
            'dossier_id' => Dossier::factory()->create(['organization_id' => $autreOrg->id])->id,
            'original_name' => 'etranger-1695.pdf',
        ]);

        DossierFileShare::query()->create([
            'organization_id' => $autreOrg->id,
            'dossier_file_id' => $autreFichier->id,
            'user_id' => $this->destinataire->id,
            'shared_by' => $this->proprietaire->id,
        ]);

        $this->assertStringNotContainsString(
            e($autreFichier->original_name),
            $this->espace($this->destinataire),
        );
    }

    /**
     * « Arrêter le partage » retire ce que J'AI posé, et referme l'accès.
     */
    public function test_stopping_sharing_closes_the_access(): void
    {
        $this->partager();
        $this->assertTrue($this->destinataire->fresh()->can('viewFiles', $this->fichier));

        $this->actingAs($this->proprietaire)
            ->delete(route('organization.dossiers.shared.stop', [
                'organization' => $this->organization->slug,
                'file' => $this->fichier->id,
            ]))
            ->assertRedirect();

        $this->assertFalse($this->destinataire->fresh()->can('viewFiles', $this->fichier));
    }

    /**
     * On ne retire PAS le partage de quelqu'un d'autre.
     *
     * La borne est `shared_by`, et c'est la seule. Sans elle, n'importe quel
     * membre pourrait révoquer les partages de tous les autres en connaissant
     * un identifiant de fichier.
     */
    public function test_nobody_can_stop_a_share_they_did_not_create(): void
    {
        $this->partager();
        $intrus = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($intrus)
            ->delete(route('organization.dossiers.shared.stop', [
                'organization' => $this->organization->slug,
                'file' => $this->fichier->id,
            ]))
            ->assertNotFound();

        $this->assertTrue($this->destinataire->fresh()->can('viewFiles', $this->fichier));
    }

    /**
     * Arrêter le mien ne touche pas celui d'un autre.
     *
     * Un même fichier peut avoir été partagé par deux personnes. Retirer
     * « tous les partages de ce fichier » plutôt que « les miens » couperait
     * l'accès ouvert par quelqu'un d'autre — un dégât invisible, puisque
     * l'écran de celui qui agit ne montre que ses propres lignes.
     */
    public function test_stopping_mine_leaves_someone_elses_share_alone(): void
    {
        $this->partager();

        $autreGestionnaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $autreDestinataire = User::factory()->create(['organization_id' => $this->organization->id]);
        DossierFileShare::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_file_id' => $this->fichier->id,
            'user_id' => $autreDestinataire->id,
            'shared_by' => $autreGestionnaire->id,
        ]);

        $this->actingAs($this->proprietaire)
            ->delete(route('organization.dossiers.shared.stop', [
                'organization' => $this->organization->slug,
                'file' => $this->fichier->id,
            ]))
            ->assertRedirect();

        $this->assertFalse($this->destinataire->fresh()->can('viewFiles', $this->fichier));
        $this->assertTrue($autreDestinataire->fresh()->can('viewFiles', $this->fichier));
    }

    /** Le bouton n'apparaît que sous « Par moi ». */
    public function test_the_stop_button_is_only_under_shared_by_me(): void
    {
        $this->partager();

        $this->assertStringContainsString(
            'data-shared-file-stop',
            $this->espace($this->proprietaire, 'par-moi'),
        );

        $this->assertStringNotContainsString(
            'data-shared-file-stop',
            $this->espace($this->destinataire, 'avec-moi'),
        );
    }

    /**
     * On peut ajouter des personnes DEPUIS « Partagés ».
     *
     * Dernier point de Cyril : éviter de revenir sur la page du Dossier. Le
     * bouton et la fenêtre doivent donc être présents **sur cet écran**.
     */
    public function test_one_can_share_with_more_people_without_leaving_this_screen(): void
    {
        $this->partager();
        $html = $this->espace($this->proprietaire, 'par-moi');

        $this->assertStringContainsString('data-shared-file-add', $html);
        $this->assertStringContainsString('data-file-share-modal', $html);
        $this->assertStringContainsString('fileSharePanel(', $html);
    }

    /**
     * Le bouton porte le Dossier DU FICHIER.
     *
     * Les lignes de cet écran viennent de Dossiers **différents**. Une valeur
     * unique au niveau de l'écran viserait le mauvais dès la deuxième ligne —
     * et le partage atterrirait ailleurs, ou échouerait sans dire pourquoi.
     */
    public function test_the_add_button_carries_the_dossier_of_its_own_file(): void
    {
        $autreDossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);
        $autreFichier = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $autreDossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'original_name' => 'second-dossier-1695.pdf',
            'display_name' => 'second-dossier-1695.pdf',
            'disk' => 'local',
            'path' => 'dossiers/1695e/rapport.pdf',
        ]);

        $this->partager();
        DossierFileShare::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_file_id' => $autreFichier->id,
            'user_id' => $this->destinataire->id,
            'shared_by' => $this->proprietaire->id,
        ]);

        $html = $this->espace($this->proprietaire, 'par-moi');

        // Les DEUX Dossiers doivent apparaître : un seul signifierait qu'une
        // valeur unique a été posée pour tout l'écran.
        foreach ([$this->dossier->id, $autreDossier->id] as $attendu) {
            $this->assertStringContainsString(
                $attendu,
                $html,
                "Le Dossier $attendu manque : le bouton vise le mauvais.",
            );
        }
    }

    /**
     * La fenêtre est le MÊME partiel des deux côtés.
     *
     * Elle sert dans la page du Dossier et dans « Partagés ». Une seconde
     * copie du balisage aurait dérivé — c'est déjà arrivé à l'idiome
     * `display_name || original_name`, recopié à six endroits.
     *
     * Le test compare un marqueur présent dans les deux rendus : s'il
     * disparaît d'un côté, c'est qu'on a cessé de partager le partiel.
     */
    public function test_the_share_dialog_is_the_same_partial_on_both_screens(): void
    {
        $this->partager();

        $dansLeDossier = $this->actingAs($this->proprietaire)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
            ]))->assertOk()->getContent();

        $dansPartages = $this->espace($this->proprietaire, 'par-moi');

        foreach (['data-file-share-modal', 'data-file-share-close'] as $marqueur) {
            $this->assertStringContainsString($marqueur, $dansLeDossier, "Manque dans le Dossier : $marqueur");
            $this->assertStringContainsString($marqueur, $dansPartages, "Manque dans Partages : $marqueur");
        }
    }

    /**
     * Le NOM des destinataires ouvre l'aperçu « Partagé avec ».
     *
     * Demande de Cyril. Sur la page du Dossier, c'est le mot « Partagé » qui
     * porte ce lien ; ici la colonne montre déjà les personnes, donc c'est
     * leur nom qui le porte. Le geste est le même, l'ancre suit ce que l'écran
     * affiche.
     */
    public function test_the_recipient_names_open_the_share_preview(): void
    {
        $this->partager();
        $html = $this->espace($this->proprietaire, 'par-moi');

        $this->assertStringContainsString('data-shared-file-who', $html);
        $this->assertStringContainsString('data-file-share-peek-modal', $html);
    }

    /**
     * Sous « Avec moi », le nom n'est PAS un lien.
     *
     * Le destinataire ne gère pas ce partage : un panneau où il ne pourrait
     * rien faire serait une promesse vide. Sans cette assertion, poser le lien
     * des deux côtés passerait inaperçu.
     */
    public function test_under_shared_with_me_the_name_is_not_a_link(): void
    {
        $this->partager();

        $this->assertStringNotContainsString(
            'data-shared-file-who',
            $this->espace($this->destinataire, 'avec-moi'),
        );
    }

    /**
     * Les en-têtes et les lignes partagent la MÊME définition de grille.
     *
     * Défaut signalé par Cyril : les colonnes étaient décalées. La dernière
     * était en `auto`, qui se dimensionne sur le **contenu** — vide dans
     * l'en-tête, deux liens dans la ligne. Les deux grilles obtenaient donc
     * des largeurs différentes et la colonne `fr` absorbait l'écart.
     *
     * Ce test ne juge pas le rendu CSS, qu'un test serveur ne voit pas : il
     * tient que les deux rangées déclarent la même grille, et qu'aucune
     * colonne n'est laissée en `auto` sur le grand écran.
     */
    public function test_headers_and_rows_declare_the_same_grid(): void
    {
        $this->partager();
        $html = $this->espace($this->proprietaire, 'par-moi');

        preg_match_all('/lg:grid-cols-\[[^\]]+\]/', $html, $trouvees);
        $grilles = array_values(array_unique(array_filter(
            $trouvees[0],
            fn (string $g) => str_contains($g, '2.2fr)_5rem_7rem'),
        )));

        $this->assertCount(1, $grilles, 'En-tête et lignes doivent déclarer la même grille.');
        $this->assertStringNotContainsString(
            '_auto]',
            $grilles[0],
            'Une colonne en `auto` se dimensionne sur son contenu : en-tête et ligne divergent.',
        );
    }
}
