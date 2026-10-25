<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1704 correctif 1 — envoyer un FICHIER depuis la messagerie.
 *
 * Cyril : « dans messagerie sur smartphone il faudrait que le bouton qui
 * permet d'envoyer des photos permettent d'envoyer des photos ET DES FICHIERS
 * depuis un smartphone. »
 *
 * Et, sur la facon de les conserver : « disque prive + route gardee ».
 *
 * ## Ce que ce fichier mesure, et pourquoi chaque volet existe
 *
 * Le correctif touche a la fois des DONNEES (une piece jointe arrive en base),
 * des PERMISSIONS (qui peut la relire) et une MIGRATION. Il releve donc du
 * protocole complet : gardes ciblees plus sabotages, et non la verification
 * legere reservee aux libelles et aux liens.
 *
 * Deux choses sont mesurees separement et ne doivent jamais fusionner :
 *
 * 1. **Le depot** — un fichier devient une ligne, sur le bon disque, avec son
 *    nom, son type devine et son poids.
 * 2. **La relecture** — et la, c'est la garde qui compte. Un fichier prive
 *    sans garde serait pire qu'un fichier public : il donnerait l'illusion
 *    d'etre protege.
 */
class TASK1704MessagerieFileAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $acheteur;

    private User $vendeur;

    private Transaction $echange;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1704', 'is_active' => true]);
        $this->acheteur = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 200]);
        $this->vendeur = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->echange = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->acheteur->id,
            'seller_id' => $this->vendeur->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);

        app()->instance('current_organization', $this->organization);
    }

    /**
     * Un fichier de test qui satisfait DEUX exigences contradictoires.
     *
     * `mimetypes:` devine le type sur le CONTENU : un fichier vide ne serait
     * jamais un PDF. Mais le banc de test Livewire attend un fichier issu de
     * sa propre fabrique (il lit une propriete `name` que `UploadedFile` n'a
     * pas). On prend donc la fabrique, et on ecrase ses octets.
     *
     * `create()` sans taille laisse `getSize()` rendre la taille REELLE : le
     * poids enregistre est donc celui du fichier, pas une valeur declaree.
     */
    private function fichier(string $nom, string $contenu): UploadedFile
    {
        $fichier = UploadedFile::fake()->create($nom);
        file_put_contents($fichier->getPathname(), $contenu);

        return $fichier;
    }

    /** Un vrai PDF : l'en-tete `%PDF-` est ce que finfo reconnait. */
    private function pdf(string $nom = 'devis.pdf'): UploadedFile
    {
        return $this->fichier($nom, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    private function envoyer(UploadedFile $fichier, ?User $qui = null): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($qui ?? $this->acheteur)
            ->test(MessageThread::class, ['transaction' => $this->echange])
            ->set('photo', $fichier)
            ->set('newMessage', 'Voici le document.')
            ->call('sendMessage');
    }

    // ── 1. Le depot ─────────────────────────────────────────────────────────

    public function test_un_fichier_pdf_peut_etre_envoye(): void
    {
        $this->envoyer($this->pdf())->assertHasNoErrors();

        $message = Message::where('transaction_id', $this->echange->id)->whereNotNull('file_path')->first();

        $this->assertNotNull($message, 'Le fichier n\'a pas ete enregistre.');
        $this->assertSame('devis.pdf', $message->file_name);
        $this->assertSame('application/pdf', $message->file_mime);
        $this->assertGreaterThan(0, $message->file_size);
        $this->assertSame('message_files', $message->file_disk);
    }

    /**
     * Il atterrit sur le disque PRIVE, et l'octet y est intact.
     *
     * Le disque est interroge directement : une ligne en base qui designerait
     * un fichier absent passerait le test precedent.
     */
    public function test_le_fichier_est_ecrit_sur_le_disque_prive(): void
    {
        $this->envoyer($this->pdf())->assertHasNoErrors();

        $message = Message::whereNotNull('file_path')->first();

        $this->assertTrue(
            Storage::disk('message_files')->exists($message->file_path),
            'Le fichier n\'est pas sur le disque prive.'
        );
        $this->assertStringStartsWith('%PDF-', Storage::disk('message_files')->get($message->file_path));
    }

    /**
     * Le disque prive n'expose AUCUNE URL.
     *
     * C'est la moitie silencieuse de la decision de Cyril. `dossier_files`
     * porte `serve` et `url`, ce qui enregistre une route de service
     * automatique. Si ce disque en heritait un jour, la route gardee cesserait
     * d'etre la seule porte — sans qu'aucun autre test ne rougisse.
     */
    public function test_le_disque_prive_n_expose_aucune_porte_automatique(): void
    {
        $disque = config('filesystems.disks.message_files');

        $this->assertArrayNotHasKey('serve', $disque, 'Le disque enregistre une route de service automatique.');
        $this->assertArrayNotHasKey('url', $disque, 'Le disque expose une URL publique.');
        $this->assertStringContainsString('/private/', $disque['root'], 'La racine n\'est pas dans l\'espace prive.');
    }

    /**
     * Le chemin de stockage ne contient PAS le nom depose.
     *
     * Le nom vient de celui qui depose. Le laisser entrer dans un chemin de
     * systeme de fichiers obligerait a prouver qu'aucun `..`, aucun separateur
     * ne passe — a chaque evolution. Un UUID nu supprime la question.
     */
    public function test_le_chemin_ne_porte_pas_le_nom_depose(): void
    {
        $this->envoyer($this->pdf('../../etc/passwd.pdf'))->assertHasNoErrors();

        $message = Message::whereNotNull('file_path')->first();

        $this->assertStringNotContainsString('..', $message->file_path);
        $this->assertStringNotContainsString('passwd', $message->file_path);
        // Le nom AFFICHE, lui, est conserve — debarrasse de son chemin.
        $this->assertSame('passwd.pdf', $message->file_name);
    }

    // ── 2. L'image garde son chemin d'origine ───────────────────────────────

    /**
     * Une image n'emprunte PAS le nouveau chemin.
     *
     * Sans ce volet, un correctif qui enverrait tout sur le disque prive
     * passerait tous les tests precedents en cassant l'affichage des images
     * dans les bulles.
     */
    public function test_une_image_suit_toujours_le_pipeline_existant(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('photo.jpg', 400, 300);

        Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $this->echange])
            ->set('photo', $image)
            ->set('newMessage', 'Une photo.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $message = Message::where('transaction_id', $this->echange->id)
            ->whereNotNull('image_path')->first();

        $this->assertNotNull($message, 'L\'image n\'a pas suivi le chemin des images.');
        $this->assertNull($message->file_path, 'L\'image a ete traitee comme un fichier.');
        $this->assertStringEndsWith('.webp', $message->image_path, 'Le re-encodage WebP a disparu.');
    }

    // ── 3. Ce qui est refuse ────────────────────────────────────────────────

    public function test_un_type_non_accepte_est_refuse(): void
    {
        Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $this->echange])
            ->set('photo', $this->fichier('virus.exe', "MZ\x90\x00\x03\x00\x00\x00binaire"))
            ->assertHasErrors('photo');

        $this->assertSame(0, Message::whereNotNull('file_path')->count());
    }

    /**
     * Les deux plafonds sont bien DEUX, et c'est voulu.
     *
     * Une image est re-encodee, un fichier non : leur plafond n'a pas la meme
     * raison d'etre. Les confondre ferait silencieusement passer de 50 Mo
     * d'images dans `Image::decode()`.
     */
    public function test_les_deux_plafonds_sont_distincts(): void
    {
        $this->assertSame(10240, MessageThread::TAILLE_MAX_IMAGE_KO);
        $this->assertSame(51200, MessageThread::TAILLE_MAX_FICHIER_KO);
    }

    // ── 4. La relecture : la garde ──────────────────────────────────────────

    private function piece(): Message
    {
        $this->envoyer($this->pdf())->assertHasNoErrors();

        return Message::whereNotNull('file_path')->firstOrFail();
    }

    private function url(Message $message, string $action = 'download'): string
    {
        return '/org/'.$this->organization->slug.'/messages/'.$this->echange->id.'/pieces/'.$message->id
            .($action === 'preview' ? '/apercu' : '');
    }

    public function test_un_participant_peut_telecharger_la_piece(): void
    {
        $piece = $this->piece();

        app()->forgetInstance('current_organization');
        app()->instance('current_organization', $this->organization);

        $reponse = $this->actingAs($this->vendeur)->get($this->url($piece));

        $reponse->assertOk();
        $reponse->assertHeader('content-disposition');
        $this->assertStringContainsString('devis.pdf', $reponse->headers->get('content-disposition'));
    }

    /**
     * Un membre de l'Organization qui n'est PAS partie a l'echange est refuse.
     *
     * C'est la garde qui compte le plus : sans elle, toute piece jointe serait
     * lisible par n'importe quel membre du tenant.
     *
     * Le code attendu est **403**, et c'est volontaire : c'est exactement ce
     * que rend deja `MessageController::show()` pour le meme visiteur sur la
     * meme conversation. Une piece jointe ne doit ni etre plus accessible, ni
     * repondre autrement, que le fil qui la porte.
     */
    public function test_un_membre_etranger_a_l_echange_est_refuse(): void
    {
        $piece = $this->piece();

        $intrus = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->forgetInstance('current_organization');
        app()->instance('current_organization', $this->organization);

        $this->actingAs($intrus)->get($this->url($piece))->assertForbidden();
    }

    /**
     * Un visiteur anonyme n'entre pas.
     *
     * La deconnexion explicite n'est PAS decorative : fabriquer la piece passe
     * par `Livewire::actingAs()`, qui laisse la session authentifiee. Sans ce
     * `logout()`, ce test interrogeait l'acheteur en se croyant anonyme — il a
     * d'ailleurs rendu 200 avant correction, et c'est la mesure qui etait
     * fausse, pas la garde.
     */
    public function test_un_visiteur_anonyme_est_refuse(): void
    {
        $piece = $this->piece();

        auth()->logout();
        $this->flushSession();

        app()->forgetInstance('current_organization');
        app()->instance('current_organization', $this->organization);

        $reponse = $this->get($this->url($piece));

        $this->assertContains($reponse->getStatusCode(), [302, 403, 404], 'Un anonyme a obtenu la piece.');
        $this->assertNotSame(200, $reponse->getStatusCode());
    }

    /**
     * Un message d'UN AUTRE echange ne se lit pas depuis celui-ci.
     *
     * La policy ne regarde que l'echange nomme dans l'URL — celui dont on est
     * bien membre. Sans la verification de correspondance, il suffirait d'etre
     * partie a une conversation quelconque pour citer le message d'une autre.
     * Cette garde-la ne peut etre trouvee que par le croisement des deux
     * identifiants.
     */
    public function test_une_piece_d_un_autre_echange_est_refusee(): void
    {
        $piece = $this->piece();

        $autre = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->acheteur->id,
            'seller_id' => User::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'accepted',
            'points_proposed' => 10,
        ]);

        app()->forgetInstance('current_organization');
        app()->instance('current_organization', $this->organization);

        // L'acheteur est partie aux DEUX echanges : la policy dit oui.
        // Seule la correspondance message <-> echange peut refuser.
        $this->actingAs($this->acheteur)
            ->get('/org/'.$this->organization->slug.'/messages/'.$autre->id.'/pieces/'.$piece->id)
            ->assertNotFound();
    }

    // ── 5. L'interface propose ce que le serveur accepte ────────────────────

    /**
     * Le selecteur de la messagerie propose les fichiers ; celui de la
     * ChatLoop ne les propose pas.
     *
     * TASK-1684 avait corrige le defaut inverse sur les Dossiers : l'ecran
     * offrait « Filmer une video » quand la regle la rejetait. Les deux moities
     * sont donc mesurees — ce que l'interface promet, et ce que l'autre surface
     * continue de ne pas promettre.
     */
    public function test_le_selecteur_de_la_messagerie_propose_les_fichiers(): void
    {
        app()->forgetInstance('current_organization');

        $html = $this->actingAs($this->acheteur)
            ->get('/org/'.$this->organization->slug.'/messages/'.$this->echange->id)
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/<input[^>]*x-ref="uploadInput"[^>]*accept="([^"]*)"/', $html, $m),
            'Le champ de la galerie est introuvable.'
        );
        $this->assertStringContainsString('application/pdf', html_entity_decode($m[1]), 'Le selecteur ne propose pas les PDF.');

        // Le bouton APPAREIL PHOTO reste aux images : `capture` n'a de sens
        // que pour un cliche.
        $this->assertSame(
            1,
            preg_match('/<input[^>]*x-ref="cameraInput"[^>]*accept="image\/\*"/', $html),
            'Le bouton appareil photo ne devrait proposer que des images.'
        );
    }

    /** Le defaut du composant partage reste `image/*` pour ses autres appelants. */
    public function test_le_composant_partage_reste_aux_images_par_defaut(): void
    {
        $source = file_get_contents(resource_path('views/components/conversation/composer.blade.php'));

        $this->assertSame(
            1,
            preg_match("/'uploadAccept'\s*=>\s*'image\/\\*'/", $source),
            'Le defaut du composeur partage n\'est plus `image/*` : le ChatLoop proposerait des types que son serveur refuse.'
        );
    }
}
