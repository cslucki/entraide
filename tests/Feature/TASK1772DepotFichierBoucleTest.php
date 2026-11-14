<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Files\TypesDeFichiersAcceptes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1772 (demande Cyril, 06/11) — UN DEPOT DANS DOSSIERS LAISSE UNE TRACE.
 *
 * « Depuis smartphone ou desktop, quand on upload un fichier dans Dossiers, il
 * manque un message dans la Boucle disant X vient de deposer le fichier Y. »
 *
 * Les deux surfaces passent par la MEME route (`dossiers.files.store`) : la
 * tuile du composeur y poste en `fetch`, la page Dossiers aussi. La trace est
 * donc posee la, et ces gardes mesurent la promesse, pas le chemin.
 */
class TASK1772DepotFichierBoucleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');

        $this->organization = Organization::factory()->create(['is_public' => true]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'approved_at' => now(),
        ]);
        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->membre->id,
        ]);
        LoopMember::create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        // `dossiers_holder_xor` : un Dossier appartient a UN SEUL porteur —
        // `owner_id` XOR `loop_id`. La fabrique pose un `owner_id` par defaut ;
        // le laisser avec un `loop_id` viole la contrainte. Invisible en
        // SQLite, qui n'applique AUCUN CHECK — et c'est PostgreSQL, en CI, qui
        // l'a dit.
        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => null,
            'loop_id' => $this->loop->id,
        ]);
    }

    private function fichier(array $surcharges = []): DossierFile
    {
        return DossierFile::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->membre->id,
        ], $surcharges));
    }

    /** Un fichier depose : une ligne dans le fil, qui NOMME le deposant et le fichier. */
    public function test_un_depot_laisse_un_message_nomme_dans_la_boucle(): void
    {
        $fichier = $this->fichier(['original_name' => 'devis.pdf', 'display_name' => 'devis.pdf']);

        $message = app(LoopMessageService::class)->sendDossierFileMessage(
            $this->loop,
            $this->membre,
            collect([$fichier]),
        );

        $this->assertNotNull($message);
        $this->assertSame('dossier_file', $message->type);
        $this->assertStringContainsString($this->membre->publicDisplayName(), $message->body);
        $this->assertStringContainsString('devis.pdf', $message->body);
        $this->assertSame(['devis.pdf'], $message->metadata['files']);
        $this->assertSame([$fichier->id], $message->metadata['file_ids']);
        $this->assertSame(1, $message->metadata['count']);
    }

    /** Dix pieces d'un coup : UNE ligne, pas dix. */
    public function test_un_depot_multiple_ne_produit_qu_une_ligne(): void
    {
        $fichiers = collect(range(1, 12))->map(
            fn ($i) => $this->fichier(['original_name' => "piece-{$i}.pdf", 'display_name' => "piece-{$i}.pdf"])
        );

        $message = app(LoopMessageService::class)->sendDossierFileMessage($this->loop, $this->membre, $fichiers);

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'dossier_file')->count());
        $this->assertStringContainsString('12', $message->body);
        // `metadata` n'est pas un journal : les noms ET les identifiants sont bornes.
        $this->assertCount(10, $message->metadata['files']);
        $this->assertCount(10, $message->metadata['file_ids']);
        $this->assertSame(12, $message->metadata['count']);
    }

    /** Rien a annoncer : aucun message. */
    public function test_un_depot_sans_nom_n_annonce_rien(): void
    {
        $this->assertNull(app(LoopMessageService::class)->sendDossierFileMessage($this->loop, $this->membre, collect()));
        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)->count());
    }

    /**
     * Et le chemin REEL : la route d'upload, celle qu'empruntent le composeur
     * et la page Dossiers. C'est elle qui doit poser la trace — la mesurer au
     * seul service laisserait le branchement non teste.
     */
    public function test_la_route_d_upload_pose_la_trace(): void
    {
        $this->actingAs($this->membre);
        app()->instance('current_organization', $this->organization);

        $reponse = $this->postJson(
            route('organization.dossiers.files.store', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
            ]),
            ['files' => [UploadedFile::fake()->create('rapport.pdf', 12, 'application/pdf')]],
        );

        $reponse->assertCreated();

        $trace = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'dossier_file')->first();

        $this->assertNotNull($trace, 'la route d\'upload doit annoncer le depot dans la Boucle');
        $this->assertStringContainsString('rapport.pdf', $trace->body);
    }

    /** Un Dossier sans Boucle n'annonce rien : il n'a pas de fil ou le dire. */
    public function test_un_dossier_personnel_n_annonce_rien(): void
    {
        // Dossier PERSONNEL du membre : `owner_id`, sinon la policy refuse le
        // depot (403) et le test mesurerait le refus, pas l'absence de trace.
        $perso = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->membre->id,
            'loop_id' => null,
        ]);

        $this->actingAs($this->membre);
        app()->instance('current_organization', $this->organization);

        $this->postJson(
            route('organization.dossiers.files.store', [
                'organization' => $this->organization->slug,
                'dossier' => $perso->id,
            ]),
            ['files' => [UploadedFile::fake()->create('note.pdf', 9, 'application/pdf')]],
        )->assertCreated();

        $this->assertSame(0, LoopMessage::where('type', 'dossier_file')->count());
    }

    /**
     * Recette Cyril du 06/11, PWA Android : « on ne peut pas envoyer de PDF
     * depuis le telephone ; sur Desktop, PDF passe. »
     *
     * Le serveur, lui, n'a jamais refuse un PDF — c'est le champ qui n'avait
     * AUCUN `accept`. Sur un ordinateur cela veut dire « tous les fichiers » ;
     * sur Android, le selecteur choisit alors ses fournisseurs et, dans une
     * PWA installee, ouvre volontiers la seule galerie. Les deux moities sont
     * donc mesurees : le champ PROPOSE les documents, et le serveur les
     * ACCEPTE.
     */
    public function test_le_champ_de_depot_propose_les_documents_et_le_serveur_les_accepte(): void
    {
        $vue = file_get_contents(resource_path('views/livewire/loop-chat.blade.php'));

        // Le champ porte un `accept`, et il le tient de la MEME source que la
        // regle serveur : une liste recopiee se perimerait d'un seul cote —
        // c'etait le motif pour lequel il n'y en avait AUCUN.
        $this->assertMatchesRegularExpression(
            '/x-ref="fichierBoucle"[\s\S]{0,200}?accept="\{\{[^"]*pourAttributAccept\(\)[^"]*\}\}"/',
            $vue,
            'sans `accept`, le selecteur Android peut n\'offrir que la galerie'
        );
        $this->assertStringContainsString('application/pdf', TypesDeFichiersAcceptes::pourAttributAccept());

        $this->actingAs($this->membre);
        app()->instance('current_organization', $this->organization);

        $this->postJson(
            route('organization.dossiers.files.store', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
            ]),
            ['files' => [UploadedFile::fake()->create('contrat.pdf', 40, 'application/pdf')]],
        )->assertCreated();
    }

    /** Un refus doit DIRE pourquoi : un echec muet fait chercher du mauvais cote. */
    public function test_un_refus_de_depot_porte_sa_raison(): void
    {
        $vue = file_get_contents(resource_path('views/livewire/loop-chat.blade.php'));

        // TASK-1772 — la lecture du refus a DEMENAGE dans `bpTeleverserFichiers`
        // quand les deux surfaces se sont mises a partager le meme
        // televerseur. La promesse n'a pas bouge : c'est elle qu'on mesure,
        // des deux cotes du deplacement.
        $js = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('donnees.errors', $js,
            'le detail d\'un 422 vit dans `errors`, le resume dans `message`');
        $this->assertStringContainsString('donnees.message', $js);
        $this->assertStringContainsString("raison || @js(__('loops.composer_file_failed'))", $vue,
            'le bandeau affiche la raison du serveur, et retombe sur le message generique');
    }

    /**
     * Recette Cyril du 06/11 : « ajouter une mire de televersement, car sinon
     * on a l'impression que rien ne se passe. Ceci sur desktop et smartphone. »
     *
     * Deux surfaces deposent dans une Boucle — la tuile du composeur et la
     * Card Dossiers — et les deux passaient par `fetch`, qui ne rapporte
     * AUCUNE progression d'envoi. Elles partagent desormais le meme
     * televerseur, et c'est ce partage qu'on mesure : une seule facon de
     * televerser dans le produit, donc une seule a corriger.
     */
    public function test_les_deux_surfaces_de_depot_montrent_la_progression(): void
    {
        $js = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('window.bpTeleverserFichiers', $js);
        $this->assertStringContainsString('xhr.upload.addEventListener(\'progress\'', $js,
            '`fetch` ne rapporte pas la progression d\'un ENVOI : seul XHR le fait');
        $this->assertStringContainsString('Math.min(99,', $js,
            'borner a 99 % tant que le serveur n\'a pas repondu : la validation et l\'ecriture restent a faire');

        $fil = file_get_contents(resource_path('views/livewire/loop-chat.blade.php'));
        $card = file_get_contents(resource_path('views/livewire/loop-dossiers-card.blade.php'));

        foreach (['loop-chat' => $fil, 'loop-dossiers-card' => $card] as $nom => $vue) {
            $this->assertStringContainsString('bpTeleverserFichiers', $vue,
                "{$nom} doit televerser par le chemin partage");
            $this->assertStringNotContainsString("fetch(@js(\$urls['upload'])", $vue,
                "{$nom} ne doit plus televerser par `fetch` : aucune progression");
        }

        // La mire du fil vit HORS de la feuille : celle-ci se referme des
        // l'appui, son propre indicateur ne serait jamais visible.
        $this->assertStringContainsString('bp-depot-progression', $fil);
        $this->assertStringContainsString('data-depot-progression', $card);
    }

    /**
     * Recette Cyril du 06/11 : « la mire affiche 0 %, puis d'un coup cela se
     * termine. Est-ce possible de montrer la progression ? »
     *
     * MESURE AU NAVIGATEUR, et c'est elle qui gouverne : pour un POST de 8 Mo
     * sur ce reseau, Chrome n'emet QUE `upload:loadstart`. Ni `progress`, ni
     * `load` — le corps part d'un bloc. Il n'existe aucun pourcentage a
     * afficher, et en figer un a 0 % fait croire que rien ne part.
     *
     * La mire dit donc la verite a chaque instant : anneau INDETERMINE tant
     * qu'aucune progression mesurable n'arrive, pourcentage des qu'il en
     * arrive une (liaison lente, 4G), « Traitement » quand les octets sont
     * partis mais que le serveur travaille encore.
     */
    public function test_la_mire_ne_fige_pas_un_pourcentage_qu_elle_ignore(): void
    {
        $mire = file_get_contents(resource_path('views/components/conversation/depot-mire.blade.php'));

        $this->assertStringContainsString('mesurable', $mire,
            'le chiffre ne s\'affiche que si le navigateur en rapporte un');
        $this->assertStringContainsString('animate-spin', $mire,
            'a defaut de pourcentage, une attente indeterminee — jamais un 0 % fige');
        $this->assertStringContainsString("x-if=\"! termine && mesurable\"", $mire);
        $this->assertStringContainsString("composer_file_processing", $mire,
            'la seconde phase : les octets sont partis, le serveur travaille encore');

        $js = file_get_contents(base_path('resources/js/app.js'));
        $this->assertStringContainsString("'traitement'", $js);
        $this->assertStringContainsString("xhr.upload.addEventListener('load'", $js,
            'c\'est lui qui marque la fin de la montee, quand le navigateur daigne l\'emettre');
    }

    /** Une mire qu'on ne peut pas voir n'est pas une mire. */
    public function test_la_mire_tient_un_plancher_d_affichage(): void
    {
        $mire = file_get_contents(resource_path('views/components/conversation/depot-mire.blade.php'));

        $this->assertMatchesRegularExpression('/Math\.max\(0, 900 - \(Date\.now\(\) - this\.debut\)\)/', $mire,
            'un envoi local dure 300 ms : sans plancher, le retour passe entre deux images');
        $this->assertStringContainsString('x-teleport="body"', $mire);
        $this->assertStringContainsString('md:hidden', $mire,
            'au bureau, la Card et la page Dossiers ont deja leur progression la ou le geste a eu lieu');
    }
}
