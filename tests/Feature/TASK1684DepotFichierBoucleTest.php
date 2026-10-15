<?php

namespace Tests\Feature;

use App\Livewire\LoopDossiersCard;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1684 — deposer un fichier dans la Boucle, sans ouvrir son administration.
 *
 * Arbitrage MASTER : tout membre ACTIF doit pouvoir deposer un fichier dans la
 * Boucle. `manageFiles` ne pouvait pas porter cette ouverture — il gouverne
 * AUSSI renommer, deplacer, editer le markdown et la projection « puis-je
 * administrer ce dossier » des ecrans. L'elargir aurait donne tout cela a
 * chaque membre et contredit la doctrine T1130 (« etre membre ne suffit pas »).
 *
 * D'ou une ability dediee, `uploadFiles`, lue par le SEUL
 * `StoreDossierFileRequest`. Ces tests mesurent les deux faces : ce qui
 * s'ouvre, et ce qui reste ferme.
 */
class TASK1684DepotFichierBoucleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $membre;

    private $loop;

    private Dossier $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->owner, 'Boucle de depot', type: 'project');
        $service->addMember($this->loop, $this->membre, 'member');

        // Pose par la creation de la Boucle ; `dossiers.loop_id` est UNIQUE.
        $this->racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();
    }

    public function test_un_membre_simple_peut_deposer_un_fichier_dans_la_boucle(): void
    {
        $this->assertTrue($this->membre->can('uploadFiles', $this->racine));
    }

    /**
     * La contre-mesure qui donne son sens a la precedente : l'ouverture porte
     * sur le DEPOT seul. Si `uploadFiles` avait ete pose en elargissant
     * `manageFiles`, cette assertion tomberait — et avec elle le verrou de
     * renommage, de deplacement et d'edition du markdown.
     */
    public function test_le_depot_n_ouvre_NI_l_administration_NI_la_suppression(): void
    {
        $this->assertFalse($this->membre->can('manageFiles', $this->racine),
            'Renommer, deplacer et editer le markdown restent fermes au membre simple.');
        $this->assertFalse($this->membre->can('update', $this->racine));
        $this->assertFalse($this->membre->can('deleteFile', $this->racine));
    }

    public function test_l_owner_conserve_les_deux(): void
    {
        $this->assertTrue($this->owner->can('uploadFiles', $this->racine));
        $this->assertTrue($this->owner->can('manageFiles', $this->racine));
    }

    public function test_un_non_membre_de_la_boucle_ne_depose_pas(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->assertFalse($etranger->can('uploadFiles', $this->racine));
    }

    public function test_une_autre_organization_ne_depose_jamais(): void
    {
        $autreOrg = Organization::factory()->create();
        $intrus = User::factory()->create(['organization_id' => $autreOrg->id]);

        $this->assertFalse($intrus->can('uploadFiles', $this->racine));
    }

    /**
     * Une Boucle archivee est en lecture seule — meme borne que
     * `LoopPolicy::update`, qui refuse pour exactement cette raison.
     */
    public function test_une_boucle_archivee_ferme_le_depot(): void
    {
        $this->assertTrue($this->membre->can('uploadFiles', $this->racine));

        // `isArchived()` se lit sur `status`, pas sur `archived_at` : poser la
        // seule date laissait la Boucle VIVANTE et le test passait au vert
        // pour une mauvaise raison — constate en l'ecrivant.
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
        $this->racine->refresh()->unsetRelation('loop');

        $this->assertFalse($this->membre->fresh()->can('uploadFiles', $this->racine->fresh()),
            'Archivee, la Boucle ne recoit plus de fichier.');
    }

    /**
     * TASK-1684 recette 3 — la Card Dossiers se remet a jour apres un depot.
     *
     * Le depot lance depuis le menu « + » du composeur part en `fetch` direct,
     * hors du cycle Livewire : la Card, deja montee, gardait son dernier rendu.
     * Le membre devait quitter la Boucle et y revenir pour voir son fichier.
     *
     * Le test mesure le PARCOURS : la Card est montee AVANT que le fichier
     * n'existe, puis l'evenement arrive. Monter la Card apres la creation
     * aurait rendu le test vert sans aucun ecouteur.
     */
    public function test_la_card_dossiers_affiche_le_fichier_apres_l_evenement_de_depot(): void
    {
        $card = Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertDontSee('depot-apres-montage.pdf');

        DossierFile::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->racine->id,
            'uploaded_by' => $this->owner->id,
            'display_name' => 'depot-apres-montage.pdf',
            'original_name' => 'depot-apres-montage.pdf',
            'disk' => 'dossier_files',
            'path' => 'dossier-files/depot-apres-montage.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
            'checksum_sha256' => hash('sha256', 'depot-apres-montage'),
        ]);

        $card->dispatch('bp-dossier-fichier-depose')
            ->assertSee('depot-apres-montage.pdf');
    }

    /**
     * TASK-1684 — la Card ne doit pas cacher un geste que le serveur accepte.
     *
     * Son bouton « Deposer un fichier » etait garde par `manageFiles`, donc
     * invisible au membre simple alors que `StoreDossierFileRequest` accepte
     * desormais son depot. Meme defaut que la tuile du composeur.
     */
    public function test_la_card_propose_le_depot_au_membre_simple(): void
    {
        Livewire::actingAs($this->membre)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.cards.dossiers.upload_file'));
    }

    /**
     * TASK-1684 recette 2 — le panneau « Nouveau » ne doit plus deborder.
     *
     * Ancre `left-0` sous 640 px, le panneau (`w-64`, 256 px) partait du bord
     * gauche d'un bouton deja colle a droite de l'ecran : il depassait le
     * viewport et rendait le DOCUMENT defilable horizontalement. Un geste vers
     * la droite decalait alors toute la page, barre du haut comprise.
     *
     * Mesure du BALISAGE : un debordement ne se constate qu'a la mise en page,
     * mais l'ancrage qui le cause, lui, est lisible et verrouillable.
     */
    public function test_le_panneau_nouveau_est_ancre_a_droite_des_le_mobile(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $this->racine->getKey(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('left-0 sm:right-0 sm:left-auto', $html,
            'Sous 640 px, cet ancrage poussait le panneau hors du viewport.');
    }

    /**
     * TASK-1684 — l'audio et la video sont acceptes par le depot.
     *
     * Defaut rapporte : un fichier video etait refuse. La liste blanche de
     * `StoreDossierFileRequest` ne contenait aucun type audio ni video, alors
     * que l'ecran Dossiers propose « Filmer une video » — l'interface offrait
     * un geste que le serveur refusait.
     *
     * Les fichiers sont construits avec de VRAIS entetes, pas des `fake()`
     * portant un type declare : `mimetypes:` lit le type devine par finfo SUR
     * LE CONTENU, et un faux fichier au type annonce aurait rendu ce test vert
     * sans rien prouver de la regle reelle.
     */
    public function test_le_depot_accepte_une_video_et_un_son(): void
    {
        Storage::fake('dossier_files');

        $repertoire = sys_get_temp_dir().'/t1684-'.uniqid();
        mkdir($repertoire);

        // Boite `ftyp` : ce que libmagic lit pour reconnaitre un MP4.
        $mp4 = $repertoire.'/clip.mp4';
        file_put_contents($mp4, pack('N', 32).'ftyp'.'isom'.pack('N', 512).'isomiso2avc1mp41'.str_repeat("\0", 8));

        $webm = $repertoire.'/clip.webm';
        file_put_contents($webm, "\x1A\x45\xDF\xA3".str_repeat("\0", 4)."\x42\x82\x84webm".str_repeat("\0", 16));

        // WAV reel : entete RIFF + PCM. Detecte `audio/x-wav`, et NON
        // `audio/wav` — c'est pourquoi la liste blanche porte les deux alias.
        $donnees = '';
        for ($i = 0; $i < 400; $i++) {
            $donnees .= pack('v', (int) (3000 * sin(2 * M_PI * 440 * $i / 8000)));
        }
        $wav = $repertoire.'/son.wav';
        file_put_contents($wav, 'RIFF'.pack('V', 36 + strlen($donnees)).'WAVEfmt '.pack('V', 16)
            .pack('v', 1).pack('v', 1).pack('V', 8000).pack('V', 16000).pack('v', 2).pack('v', 16)
            .'data'.pack('V', strlen($donnees)).$donnees);

        foreach ([$mp4, $webm, $wav] as $chemin) {
            $this->actingAs($this->owner)->post(route('organization.dossiers.files.store', [
                'organization' => $this->organization->slug, 'dossier' => $this->racine->id,
            ]), ['files' => [new UploadedFile($chemin, basename($chemin), null, null, true)]])
                ->assertSuccessful();
        }

        $this->assertSame(3, $this->racine->files()->count());

        array_map('unlink', [$mp4, $webm, $wav]);
        rmdir($repertoire);
    }

    /**
     * TASK-1684 — les champs de date ouvrent leur selecteur au toucher.
     *
     * Defaut rapporte : sur la Card Roadmap mobile, toucher ECHEANCE n'ouvrait
     * RIEN. Cause : `@tailwindcss/forms` applique `appearance: none` aux champs
     * de date, et Chrome Android cesse alors d'ouvrir le selecteur au toucher.
     * Invisible en desktop, ou le clic continue de l'ouvrir.
     *
     * Le correctif est un ecouteur DELEGUE (`resources/js/app.js`) : deux des
     * trois cartes concernees n'ont aucune portee Alpine, un attribut par champ
     * n'y serait jamais initialise.
     *
     * CE QUE CE TEST PROUVE, ET CE QU'IL NE PROUVE PAS : il mesure que la regle
     * existe et qu'elle vise bien les champs de date — il ne peut pas prouver
     * qu'un doigt ouvre un selecteur natif. Cette preuve-la a ete faite dans le
     * navigateur sur l'application servie : un clic sur un `input[type=date]`
     * declenche `showPicker()` (espion sur l'API native, 1 appel).
     */
    public function test_les_champs_de_date_ouvrent_leur_selecteur_au_clic(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('showPicker', $js,
            'Sans appel explicite, un champ de date neutralise par le plugin forms reste muet au toucher.');
        $this->assertStringContainsString('input[type="date"]', $js);

        // Les trois champs que le defaut concernait. Les nommer ici fait
        // rougir le test si l'un d'eux disparait ou change de type sans que
        // la regle ci-dessus soit relue.
        foreach (['loop-roadmap-card', 'loop-journal-card', 'loop-decisions-card'] as $carte) {
            $this->assertStringContainsString(
                'type="date"',
                file_get_contents(resource_path("views/livewire/{$carte}.blade.php")),
                "La carte {$carte} ne porte plus de champ de date : la regle deleguee doit etre relue."
            );
        }
    }

    /**
     * Portee etroite assumee : l'ouverture vise le Dossier RACINE, pas
     * l'arborescence. Un sous-dossier garde la regle proprietaire.
     */
    public function test_un_sous_dossier_n_herite_pas_de_l_ouverture(): void
    {
        $enfant = Dossier::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->racine->id,
            'name' => 'Sous-dossier',
            'visibility' => $this->racine->visibility,
        ]);

        $this->assertFalse($this->membre->can('uploadFiles', $enfant),
            'Deposer dans la Boucle n\'est pas administrer son arborescence.');
    }
}
