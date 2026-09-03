<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\TransactionStatusChanged;
use App\Support\Ops\NotificationCockpitDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1383 — le cockpit doit dire ce qu'il ne voit pas.
 *
 * ## Le cockpit a raison de filtrer, et c'est important
 *
 * `NotificationCockpitDiagnostics::preuves()` ne compte que les lignes portant
 * un `notification_id`. Ce n'est pas un oubli : les autres lignes d'`email_logs`
 * viennent d'appelants dont le contenu n'obeit pas aux memes regles
 * d'expurgation, et les melanger donnerait un total qui n'aurait de sens pour
 * personne.
 *
 * ## Mais l'ecran ne le disait pas
 *
 * Il affichait « Emails traces » — ce qui se lit « tous les emails traces » — et
 * renvoyait vers un historique detaille qui, lui, montre TOUT. Un exploitant
 * comparant les deux totaux voyait un ecart sans explication, et pouvait en
 * conclure que la supervision perd des envois.
 *
 * Ces tests etablissent la preuve reproductible demandee : une `Notification`
 * Laravel ecrit bien une ligne SANS `notification_id`, elle reste absente du
 * compte du pipeline, et le cockpit la compte desormais A PART en le nommant.
 */
class TASK1383CockpitOutOfPipelineEmailsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
        ]);
    }

    /**
     * Fait partir un email par le chemin LEGACY — une `Notification` Laravel.
     *
     * On passe par `notify()`, pas par une insertion fabriquee : ce qui doit
     * etre prouve est le comportement du listener reel, pas celui d'une ligne
     * qu'on aurait ecrite soi-meme a la forme voulue.
     */
    private function envoyerParLeCheminLegacy(): void
    {
        $destinataire = User::factory()->create(['organization_id' => $this->org->id]);
        $vendeur = User::factory()->create(['organization_id' => $this->org->id]);

        $transaction = Transaction::factory()->create([
            'organization_id' => $this->org->id,
            'buyer_id' => $destinataire->id,
            'seller_id' => $vendeur->id,
        ]);

        $destinataire->notify(new TransactionStatusChanged($transaction));
    }

    // =====================================================================
    // La preuve demandee
    // =====================================================================

    /**
     * Une `Notification` Laravel ecrit un `EmailLog` SANS `notification_id`.
     *
     * C'est le fait de base, et il se mesure sur le listener reel
     * (`AppServiceProvider`, ecoute de `NotificationSent`) plutot que sur une
     * supposition.
     */
    public function test_a_laravel_notification_writes_an_email_log_without_notification_id(): void
    {
        $this->envoyerParLeCheminLegacy();

        $ligne = EmailLog::query()->latest('id')->first();

        $this->assertNotNull($ligne, 'Le listener legacy doit avoir trace cet envoi.');
        $this->assertNull($ligne->notification_id);
    }

    /**
     * Et elle reste ABSENTE du compte du pipeline. C'est voulu.
     */
    public function test_that_email_stays_out_of_the_pipeline_count(): void
    {
        $this->envoyerParLeCheminLegacy();

        $preuves = app(NotificationCockpitDiagnostics::class)->overview()['preuves'];

        $this->assertSame(0, $preuves['total']);
    }

    /**
     * Mais le cockpit la COMPTE desormais a part, et la nomme.
     *
     * C'est tout l'objet de cette tranche : le filtre reste, l'ecran cesse de
     * laisser croire qu'il n'y a rien d'autre.
     */
    public function test_the_cockpit_counts_it_separately(): void
    {
        $this->envoyerParLeCheminLegacy();

        $preuves = app(NotificationCockpitDiagnostics::class)->overview()['preuves'];

        $this->assertSame(1, $preuves['hors_pipeline']);
    }

    /**
     * Les deux comptes se distinguent VRAIMENT.
     *
     * Sans ce test, `hors_pipeline` pourrait etre un simple alias du total
     * general : il compterait juste, et pour la mauvaise raison. On met donc les
     * deux chemins en presence dans la meme base.
     */
    public function test_the_two_counts_do_not_overlap(): void
    {
        $this->envoyerParLeCheminLegacy();

        // `notification_id` est HORS `fillable` — deliberement : il ne vient
        // jamais d'une requete, seulement du livreur. Le passer a `create()`
        // le ferait ignorer EN SILENCE, et cette ligne compterait alors comme
        // hors pipeline : le test aurait mesure l'inverse de son intention.
        //
        // On l'ecrit donc comme le livreur reel l'ecrit — par affectation
        // directe, apres coup (`NotificationEmailDeliverer`).
        $journal = EmailLog::create([
            'user_id' => $this->admin->id,
            'organization_id' => $this->org->id,
            'to_email' => 'destinataire@example.test',
            'subject' => 'Ligne du pipeline',
            'status' => EmailLog::STATUS_SENT,
        ]);

        $journal->notification_id = (string) Str::uuid();
        $journal->save();

        $preuves = app(NotificationCockpitDiagnostics::class)->overview()['preuves'];

        $this->assertSame(1, $preuves['total'], 'Le pipeline ne compte que sa propre ligne.');
        $this->assertSame(1, $preuves['hors_pipeline'], 'Le hors-pipeline ne compte que la sienne.');
        $this->assertSame(2, EmailLog::count(), 'Et les deux existent bien.');
    }

    // =====================================================================
    // Ce que l'ecran en dit
    // =====================================================================

    /**
     * L'ecran NOMME la limite, il ne se contente pas de la respecter.
     *
     * L'interdiction est explicite : ne pas laisser croire que ce cockpit
     * supervise tout l'email tant que ce n'est pas vrai.
     */
    public function test_the_screen_names_the_blind_spot(): void
    {
        $this->envoyerParLeCheminLegacy();

        $this->actingAs($this->admin)
            ->get(route('admin.notifications-cockpit'))
            ->assertOk()
            ->assertSee('data-preuves-hors-pipeline', escape: false)
            ->assertSee('data-cockpit-limites', escape: false);
    }

    /**
     * Et il ne se met pas a AFFICHER ces emails pour autant.
     *
     * Compter n'est pas lire — la doctrine ne change pas parce que le perimetre
     * du compte s'elargit. Le sujet et l'adresse de la ligne legacy ne doivent
     * apparaitre nulle part.
     */
    public function test_counting_them_does_not_mean_showing_them(): void
    {
        $this->envoyerParLeCheminLegacy();

        $ligne = EmailLog::query()->latest('id')->first();

        $reponse = $this->actingAs($this->admin)->get(route('admin.notifications-cockpit'));

        $reponse->assertOk();
        $reponse->assertDontSee($ligne->to_email, escape: false);
        $reponse->assertDontSee((string) $ligne->subject, escape: false);
    }
}
