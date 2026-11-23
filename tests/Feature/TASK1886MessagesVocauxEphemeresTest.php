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
 * TASK-1886 — messages vocaux EPHEMERES de la messagerie privee.
 *
 * Arbitrages Cyril (22/11/2026) : 5 jours a compter de l'envoi, 5 minutes et
 * 5 Mo au plus, mention discrete « Disparait dans N j ».
 *
 * Trois promesses, mesurees separement :
 *
 * 1. **L'envoi** — un vocal devient un message sans texte, sous le prefixe
 *    dedie `message-voice/`, avec son echeance, sa duree et sa forme.
 * 2. **La fermeture** — des l'echeance, la route gardee rend 410, meme si le
 *    fichier est encore la. Un `.mp3` JOINT, lui, n'expire jamais.
 * 3. **La suppression** — le purgeur efface le FICHIER, ne touche qu'aux
 *    vocaux echus sous le prefixe, et peut repasser sans dommage.
 */
class TASK1886MessagesVocauxEphemeresTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $acheteur;

    private User $vendeur;

    private Transaction $echange;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('message_files');

        $this->organization = Organization::factory()->create(['slug' => 'org-1886', 'is_active' => true]);
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
     * Un vrai en-tete WebM/Opus, comme celui de `MediaRecorder` sous Chrome.
     *
     * libmagic le devine `video/webm` (audio seul ou non) : c'est exactement
     * le cas que `MessageThread::TYPES_VOCAUX` doit ramener a `audio/webm`.
     */
    private function enregistrement(): UploadedFile
    {
        $ebml = "\x1A\x45\xDF\xA3\xA3\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08"
            ."\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02";
        $segment = "\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"
            ."\x16\x54\xAE\x6B\x8C\xAE\x8A\xD7\x81\x01\x83\x81\x02\x86\x84A_OPUS";

        $fichier = UploadedFile::fake()->create('voice.webm');
        file_put_contents($fichier->getPathname(), $ebml.$segment.str_repeat("\0", 64));

        return $fichier;
    }

    private function envoyerUnVocal(int $duree = 12, array $forme = [10, 50, 90]): Message
    {
        Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $this->echange])
            ->set('voiceDuration', $duree)
            ->set('voiceWaveform', $forme)
            ->set('voice', $this->enregistrement())
            ->call('sendMessage')
            ->assertHasNoErrors();

        return Message::where('transaction_id', $this->echange->id)->whereNotNull('voice_expires_at')->firstOrFail();
    }

    /** Un vocal pose directement sur le disque simule, pour les gardes et le purgeur. */
    private function vocal(array $attributs = []): Message
    {
        $chemin = $attributs['file_path'] ?? 'message-voice/'.$this->organization->id.'/'.$this->echange->id.'/'.fake()->uuid();

        if ($chemin !== null) {
            Storage::disk('message_files')->put($chemin, 'opus');
        }

        return Message::create(array_merge([
            'transaction_id' => $this->echange->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->acheteur->id,
            'type' => 'user',
            'body' => '',
            'created_at' => now(),
            'file_path' => $chemin,
            'file_disk' => 'message_files',
            'file_name' => 'Message vocal',
            'file_mime' => 'audio/webm',
            'file_size' => 4,
            'voice_expires_at' => now()->addDays(5),
            'voice_duration' => 7,
        ], $attributs));
    }

    private function url(Message $message): string
    {
        return '/org/'.$this->organization->slug.'/messages/'.$this->echange->id.'/pieces/'.$message->id.'/apercu';
    }

    // ── 1. L'envoi ──────────────────────────────────────────────────────────

    public function test_un_vocal_est_enregistre_sous_le_prefixe_dedie_avec_son_echeance(): void
    {
        $this->travelTo(now()->startOfMinute());

        $message = $this->envoyerUnVocal();

        $this->assertSame('', (string) $message->body);
        $this->assertStringStartsWith('message-voice/'.$this->organization->id.'/'.$this->echange->id.'/', $message->file_path);
        $this->assertSame('audio/webm', $message->file_mime, 'Le WebM devine video/webm n\'a pas ete ramene a audio/webm.');
        $this->assertTrue($message->voice_expires_at->equalTo(now()->addDays(Message::VOICE_TTL_DAYS)));
        $this->assertSame(12, $message->voice_duration);
        $this->assertSame([10, 50, 90], $message->voiceWaveform());
        $this->assertTrue(Storage::disk('message_files')->exists($message->file_path));
    }

    /** La duree et la forme viennent du navigateur : le serveur les borne. */
    public function test_la_duree_et_la_forme_envoyees_sont_bornees(): void
    {
        $message = $this->envoyerUnVocal(9999, array_merge([-5, 250], array_fill(0, 60, 40)));

        $this->assertSame(Message::VOICE_MAX_SECONDS, $message->voice_duration);
        $forme = $message->voiceWaveform();
        $this->assertCount(40, $forme);
        $this->assertSame(0, $forme[0]);
        $this->assertSame(100, $forme[1]);
    }

    /**
     * Le CIRCUIT : un fichier refuse a la reception ne part jamais.
     *
     * Attention, piege du banc : en tests unitaires,
     * `TemporaryUploadedFile::getMimeType()` rend le type DECLARE du faux
     * fichier (deduit de son extension), pas celui de son contenu. Un PDF
     * nomme `voice.webm` passe donc ici pour du WebM — c'est ce qui a rougi la
     * premiere version de ce test. Le type est donc declare explicitement ;
     * la lecture du CONTENU est mesuree par le test suivant.
     */
    public function test_un_fichier_declare_non_audio_est_refuse_et_rien_ne_part(): void
    {
        $pdf = UploadedFile::fake()->create('voice.pdf', 1, 'application/pdf');

        Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $this->echange])
            ->set('voice', $pdf)
            ->assertHasErrors('voice')
            ->assertSet('voice', null)
            ->call('sendMessage');

        $this->assertSame(0, Message::whereNotNull('voice_expires_at')->count());
        $this->assertSame([], Storage::disk('message_files')->allFiles());
    }

    /**
     * La REGLE, sur un vrai fichier dont le type est lu dans le CONTENU (finfo),
     * comme en production : un PDF renomme `.webm` est refuse, un vrai en-tete
     * WebM/Opus accepte. La regle est celle du composant (reflexion), jamais
     * une copie qui pourrait diverger.
     */
    public function test_la_regle_lit_le_type_dans_le_contenu_et_non_dans_le_nom(): void
    {
        $regles = (new \ReflectionMethod(MessageThread::class, 'reglesDeVocal'))->invoke(new MessageThread);

        $chemin = tempnam(sys_get_temp_dir(), 'vocal');
        file_put_contents($chemin, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        $faux = new UploadedFile($chemin, 'voice.webm', 'audio/webm', null, true);
        $this->assertTrue(validator(['voice' => $faux], ['voice' => $regles])->fails(), 'Un PDF renomme .webm a ete accepte comme vocal.');

        // Copie dans un fichier a nous : le faux fichier de la fabrique est
        // efface des que son objet est libere.
        $cheminWebm = tempnam(sys_get_temp_dir(), 'vocal');
        file_put_contents($cheminWebm, file_get_contents(($enregistrement = $this->enregistrement())->getPathname()));
        $vrai = new UploadedFile($cheminWebm, 'voice.webm', null, null, true);
        $this->assertFalse(validator(['voice' => $vrai], ['voice' => $regles])->fails(), 'Un vrai WebM/Opus a ete refuse.');
    }

    /** Un vocal ne se « corrige » pas : l'edition ecraserait sa forme d'onde. */
    public function test_un_vocal_n_est_pas_editable(): void
    {
        $this->assertFalse($this->vocal()->isEditableBy($this->acheteur));
    }

    /** La citation, l'epingle, l'email et la liste lisent ce libelle. */
    public function test_un_vocal_se_resume_sans_texte(): void
    {
        $this->assertStringContainsString('0:07', (string) $this->vocal()->voiceSummary());
    }

    // ── 2. La fermeture a l'echeance ────────────────────────────────────────

    public function test_un_vocal_en_cours_est_lisible_par_un_participant(): void
    {
        $vocal = $this->vocal();

        $this->actingAs($this->vendeur)->get($this->url($vocal))->assertOk();
    }

    /** Le fichier est ENCORE la : seule l'echeance ferme l'acces. */
    public function test_un_vocal_echu_rend_410_avant_meme_la_purge(): void
    {
        $vocal = $this->vocal(['voice_expires_at' => now()->subMinute()]);

        $this->assertTrue(Storage::disk('message_files')->exists($vocal->file_path));
        $this->actingAs($this->vendeur)->get($this->url($vocal))->assertStatus(410);
    }

    public function test_un_vocal_reste_refuse_a_un_membre_etranger_a_l_echange(): void
    {
        $vocal = $this->vocal();
        $intrus = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($intrus)->get($this->url($vocal))->assertForbidden();
    }

    /** Un audio JOINT n'a pas d'echeance : il ne devient jamais un vocal. */
    public function test_un_mp3_joint_n_expire_jamais(): void
    {
        $mp3 = $this->vocal([
            'file_path' => 'message-files/'.$this->organization->id.'/'.fake()->uuid(),
            'file_mime' => 'audio/mpeg',
            'voice_expires_at' => null,
            'voice_duration' => null,
        ]);

        $this->travel(30)->days();

        $this->assertFalse($mp3->fresh()->isVoice());
        $this->assertFalse($mp3->fresh()->voiceIsExpired());
        $this->actingAs($this->vendeur)->get(str_replace('/apercu', '', $this->url($mp3)))->assertOk();
    }

    // ── 3. Le purgeur ───────────────────────────────────────────────────────

    public function test_le_purgeur_supprime_le_fichier_d_un_vocal_echu_et_marque_la_ligne(): void
    {
        $echu = $this->vocal(['voice_expires_at' => now()->subMinute()]);
        $chemin = $echu->file_path;

        $this->artisan('messages:purge-expired-voices')->assertSuccessful();

        Storage::disk('message_files')->assertMissing($chemin);
        $echu->refresh();
        $this->assertNotNull($echu->voice_purged_at);
        $this->assertNull($echu->file_path);
        $this->assertTrue($echu->voiceIsExpired());
    }

    public function test_le_purgeur_epargne_un_vocal_en_cours_et_un_mp3_joint(): void
    {
        $enCours = $this->vocal();
        $mp3 = $this->vocal([
            'file_path' => 'message-files/'.$this->organization->id.'/'.fake()->uuid(),
            'voice_expires_at' => null,
        ]);

        $this->travel(1)->hours();
        $this->artisan('messages:purge-expired-voices')->assertSuccessful();

        Storage::disk('message_files')->assertExists($enCours->file_path);
        Storage::disk('message_files')->assertExists($mp3->file_path);
        $this->assertNull($enCours->fresh()->voice_purged_at);
    }

    /** Une echeance posee sur un chemin HORS prefixe : refusee, jamais supprimee. */
    public function test_le_purgeur_refuse_un_chemin_hors_prefixe(): void
    {
        $suspect = $this->vocal([
            'file_path' => 'message-files/'.$this->organization->id.'/'.fake()->uuid(),
            'voice_expires_at' => now()->subDay(),
        ]);

        $this->artisan('messages:purge-expired-voices')->assertFailed();

        Storage::disk('message_files')->assertExists($suspect->file_path);
        $this->assertNull($suspect->fresh()->voice_purged_at);
    }

    public function test_le_purgeur_est_idempotent(): void
    {
        $echu = $this->vocal(['voice_expires_at' => now()->subMinute()]);

        $this->artisan('messages:purge-expired-voices')->assertSuccessful();
        $premiere = $echu->fresh()->voice_purged_at;

        $this->travel(15)->minutes();
        $this->artisan('messages:purge-expired-voices')->assertSuccessful();

        $this->assertTrue($premiere->equalTo($echu->fresh()->voice_purged_at), 'La seconde passe a re-marque la ligne.');
    }

    /** Objet deja absent (filet R2 Lifecycle passe avant) : succes, ligne marquee. */
    public function test_un_objet_deja_absent_est_un_succes(): void
    {
        $echu = $this->vocal(['voice_expires_at' => now()->subMinute()]);
        Storage::disk('message_files')->delete($echu->file_path);

        $this->artisan('messages:purge-expired-voices')->assertSuccessful();

        $this->assertNotNull($echu->fresh()->voice_purged_at);
    }

    public function test_le_dry_run_ne_supprime_rien(): void
    {
        $echu = $this->vocal(['voice_expires_at' => now()->subMinute()]);

        $this->artisan('messages:purge-expired-voices', ['--dry-run' => true])
            ->expectsOutputToContain('1 vocal(aux) echu(s)')
            ->assertSuccessful();

        Storage::disk('message_files')->assertExists($echu->file_path);
        $this->assertNull($echu->fresh()->voice_purged_at);
    }

    /** Un vocal purge s'affiche « expire » : la ligne reste, le trou non. */
    public function test_un_vocal_purge_s_affiche_expire_dans_le_fil(): void
    {
        $this->vocal(['voice_expires_at' => now()->subMinute()]);
        $this->artisan('messages:purge-expired-voices')->assertSuccessful();

        Livewire::actingAs($this->vendeur)
            ->test(MessageThread::class, ['transaction' => $this->echange])
            ->assertSeeHtml('data-message-voice-expired')
            ->assertSee(__('messages.voice_expired'));
    }
}
