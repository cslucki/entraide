<?php

namespace Tests\Feature;

use App\Models\Dossier;
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
        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
        ]);
    }

    /** Un fichier depose : une ligne dans le fil, qui NOMME le deposant et le fichier. */
    public function test_un_depot_laisse_un_message_nomme_dans_la_boucle(): void
    {
        $message = app(LoopMessageService::class)->sendDossierFileMessage(
            $this->loop,
            $this->membre,
            ['devis.pdf'],
        );

        $this->assertNotNull($message);
        $this->assertSame('dossier_file', $message->type);
        $this->assertStringContainsString($this->membre->publicDisplayName(), $message->body);
        $this->assertStringContainsString('devis.pdf', $message->body);
        $this->assertSame(['devis.pdf'], $message->metadata['files']);
        $this->assertSame(1, $message->metadata['count']);
    }

    /** Dix pieces d'un coup : UNE ligne, pas dix. */
    public function test_un_depot_multiple_ne_produit_qu_une_ligne(): void
    {
        $noms = array_map(fn ($i) => "piece-{$i}.pdf", range(1, 12));

        $message = app(LoopMessageService::class)->sendDossierFileMessage($this->loop, $this->membre, $noms);

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'dossier_file')->count());
        $this->assertStringContainsString('12', $message->body);
        // `metadata` n'est pas un journal : les noms sont bornes.
        $this->assertCount(10, $message->metadata['files']);
        $this->assertSame(12, $message->metadata['count']);
    }

    /** Rien a annoncer : aucun message. */
    public function test_un_depot_sans_nom_n_annonce_rien(): void
    {
        $this->assertNull(app(LoopMessageService::class)->sendDossierFileMessage($this->loop, $this->membre, ['', '  ']));
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

        $this->assertStringContainsString('raison = donnees?.errors', $vue,
            'le detail d\'un 422 vit dans `errors`, le resume dans `message`');
        $this->assertStringContainsString("raison || @js(__('loops.composer_file_failed'))", $vue,
            'le bandeau affiche la raison du serveur, et retombe sur le message generique');
    }
}
