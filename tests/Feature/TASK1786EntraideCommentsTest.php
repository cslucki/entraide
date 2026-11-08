<?php

namespace Tests\Feature;

use App\Livewire\EntraideComments;
use App\Models\Category;
use App\Models\EntraideComment;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

/**
 * TASK-1786 — commentaires sur les propositions de service et les demandes
 * d'aide, et la notification de l'auteur sur trois canaux.
 *
 * ## Ce que l'ajout d'un type de notification exige vraiment
 *
 * Pas un `create()`. Cinq pieces, parce que quatre autorites refusent ce
 * qu'elles ne declarent pas :
 *
 * 1. une entree au `NotificationCatalogue` (cle, `object_type`, canaux) ;
 * 2. une branche au `NotificationTargetResolver`, **fail-closed** ;
 * 3. les libelles de langue, FR et EN ;
 * 4. une **branche au livreur email** — oubliee DEUX fois dans ce depot
 *    (T1751, T1761), et son absence est SILENCIEUSE : l'email part quand meme
 *    par le repli de langue, sans aucun nom. D'ou
 *    `test_l_email_emploie_le_gabarit_administrable_et_pas_le_repli`, qui
 *    cherche une chaine que SEUL le gabarit produit ;
 * 5. l'emission, par `NotificationEmitter` et jamais en direct.
 *
 * ## Deux manieres de mesurer, et pourquoi les deux
 *
 * `Livewire::test()` pour le comportement : c'est lisible et suffisant quand la
 * question est « que fait le composant ».
 *
 * Mais pour la frontiere de TENANT, les mesures passent par le **VRAI
 * endpoint** — lecon de T1691 : « `Livewire::test()` court-circuite le
 * middleware persistant et ne prouverait donc rien de la production ». Un
 * `#[Locked]` ne se mesure d'ailleurs que la : c'est le serveur qui refuse une
 * propriete reecrite par le client, et `Livewire::test()` n'a pas de client.
 */
class TASK1786EntraideCommentsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    /** Auteur de l'annonce dans A — c'est lui qu'on doit prevenir. */
    private User $auteur;

    /** Membre de A qui commente. */
    private User $commentateur;

    /** Membre de B — ne doit RIEN pouvoir faire sur une annonce de A. */
    private User $etranger;

    private Service $proposition;

    private ServiceRequest $demande;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create([
            'slug' => 'org-1786-a', 'is_active' => true, 'locale' => 'fr',
        ]);
        $this->orgB = Organization::factory()->create([
            'slug' => 'org-1786-b', 'is_active' => true, 'locale' => 'fr',
        ]);

        app()->instance('current_organization', $this->orgA);

        $this->auteur = $this->membre($this->orgA);
        $this->commentateur = $this->membre($this->orgA);
        $this->etranger = $this->membre($this->orgB);

        $this->proposition = $this->propositionDe($this->auteur, $this->orgA);
        $this->demande = $this->demandeDe($this->auteur, $this->orgA);
    }

    // ---------------------------------------------------------------- 1 & 2

    public function test_un_membre_commente_une_proposition_de_service(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Pensez a preciser les competences necessaires.')
            ->call('addComment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('entraide_comments', [
            'commentable_type' => Service::class,
            'commentable_id' => $this->proposition->id,
            'organization_id' => $this->orgA->id,
            'user_id' => $this->commentateur->id,
            'content' => 'Pensez a preciser les competences necessaires.',
            'is_approved' => true,
        ]);
    }

    public function test_un_membre_commente_une_demande_d_aide(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->demande])
            ->set('content', 'Quel niveau de formateur cherchez-vous ?')
            ->call('addComment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('entraide_comments', [
            'commentable_type' => ServiceRequest::class,
            'commentable_id' => $this->demande->id,
            'organization_id' => $this->orgA->id,
        ]);
    }

    // -------------------------------------------------------------------- 3

    public function test_un_commentaire_s_affiche_sur_SA_fiche_et_pas_sur_l_autre(): void
    {
        $surLaProposition = $this->commentaire($this->proposition, $this->commentateur, 'Suggestion cote proposition');
        $surLaDemande = $this->commentaire($this->demande, $this->commentateur, 'Suggestion cote demande');

        // La fiche de la PROPOSITION montre le sien, et seulement le sien.
        $this->actingAs($this->auteur)
            ->get(route('services.show', $this->proposition))
            ->assertOk()
            ->assertSee('Suggestion cote proposition')
            ->assertDontSee('Suggestion cote demande');

        // Et reciproquement. Sans ce second volet, un scope qui ignorerait
        // `commentable_type` passerait : les deux annonces ont des identifiants
        // differents, donc le premier volet seul ne prouve pas le typage.
        $this->actingAs($this->auteur)
            ->get(route('requests.show', $this->demande))
            ->assertOk()
            ->assertSee('Suggestion cote demande')
            ->assertDontSee('Suggestion cote proposition');

        $this->assertSame(2, EntraideComment::count());
        $this->assertNotSame((string) $surLaProposition->id, (string) $surLaDemande->id);
    }

    // -------------------------------------------------------------------- 4

    public function test_un_visiteur_anonyme_n_atteint_ni_la_fiche_ni_le_formulaire(): void
    {
        // La route porte `['auth', 'organization.member']` : la visibilite des
        // commentaires suit celle de l'annonce, et il n'y a aucun acces anonyme
        // a proteger separement.
        $this->get(route('services.show', $this->proposition))->assertRedirect();

        $this->assertSame(0, EntraideComment::count());
    }

    public function test_le_formulaire_disparait_sur_une_annonce_fermee_mais_les_commentaires_restent(): void
    {
        $this->commentaire($this->demande, $this->commentateur, 'Commentaire anterieur a la cloture');

        $this->demande->forceFill(['status' => 'closed'])->save();

        $rendu = Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->demande]);

        // LECTURE conservee — decision explicite exigee par le CDC §4.
        $rendu->assertSee('Commentaire anterieur a la cloture')
            ->assertSee(__('entraide.commentaires.fermee_demande'))
            ->assertDontSee(__('entraide.commentaires.publier'));
    }

    public function test_la_garde_serveur_refuse_d_ecrire_sur_une_annonce_fermee(): void
    {
        $this->demande->forceFill(['status' => 'closed'])->save();

        // Le formulaire masque n'est pas une protection : on appelle l'action
        // directement, ce qu'un client peut faire.
        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->demande])
            ->set('content', 'Tentative sur une demande cloturee')
            ->call('addComment')
            ->assertForbidden();

        $this->assertSame(0, EntraideComment::count());
    }

    // -------------------------------------------------------------------- 5

    public function test_un_membre_d_une_autre_organization_ne_voit_pas_l_annonce(): void
    {
        $this->actingAs($this->etranger)
            ->get(route('services.show', $this->proposition))
            ->assertNotFound();
    }

    /**
     * La frontiere de tenant, mesuree sur le VRAI endpoint.
     *
     * Lecon de T1691 : `Livewire::test()` court-circuite le middleware
     * persistant. Et un `#[Locked]` ne se mesure QUE par un client qui tente de
     * reecrire la propriete — ce que `Livewire::test()` ne fait pas.
     *
     * Chaque falsification est doublee d'un **temoin** : le meme snapshot,
     * rejoue sans rien falsifier, doit passer. Sans temoin, un 4xx du a une
     * cause etrangere se lirait comme une protection.
     */
    public function test_le_verrou_refuse_de_viser_l_annonce_d_une_autre_organization(): void
    {
        $annonceDeB = $this->propositionDe($this->membre($this->orgB), $this->orgB);

        $snapshot = $this->snapshotDe(
            route('organization.services.show', ['organization' => $this->orgA->slug, 'service' => $this->proposition->id]),
            $this->commentateur,
            'entraide-comments'
        );

        // TEMOIN : le meme snapshot, sans falsification. Sans lui, un 4xx du a
        // une cause etrangere se lirait comme une protection.
        $this->update($this->commentateur, $snapshot, [])->assertOk();

        // Falsification de l'IDENTIFIANT.
        //
        // **419**, et non 500 : Livewire refuse l'instantane altere AVANT
        // d'atteindre le composant — la somme de controle ne correspond plus. La
        // protection est donc plus forte que si le composant avait eu a se
        // defendre lui-meme : rien de notre code ne s'execute. C'est la mesure
        // qui l'a dit, pas une hypothese : j'attendais 500.
        $this->update($this->commentateur, $snapshot, ['annonceId' => (string) $annonceDeB->id])
            ->assertStatus(419);

        // Falsification du TYPE. Verrouiller l'identifiant seul ne suffirait
        // pas : `commentable_type` entre dans l'identite de la cible.
        $this->update($this->commentateur, $snapshot, ['annonceType' => ServiceRequest::class])
            ->assertStatus(419);

        // Et la preuve qui compte vraiment : rien n'a ete ecrit, ni dans A ni
        // dans B.
        $this->assertSame(0, EntraideComment::count());
        $this->assertSame(0, MemberNotification::count());
    }

    // -------------------------------------------------------------------- 6

    public function test_le_texte_d_un_membre_est_echappe_et_non_interprete(): void
    {
        $this->commentaire($this->proposition, $this->commentateur, '<script>alert(1)</script> et <b>gras</b>');

        $reponse = $this->actingAs($this->auteur)
            ->get(route('services.show', $this->proposition))
            ->assertOk();

        // Le contenu est stocke TEL QUEL — on n'assainit pas a l'ecriture, on
        // echappe a l'affichage, et c'est Blade qui le fait.
        $reponse->assertDontSee('<script>alert(1)</script>', false);
        $reponse->assertSee('&lt;script&gt;', false);
    }

    public function test_un_contenu_vide_ou_trop_long_est_refuse(): void
    {
        $composant = Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition]);

        $composant->set('content', '')->call('addComment')->assertHasErrors(['content' => 'required']);

        $composant->set('content', str_repeat('a', EntraideComment::CONTENU_MAX + 1))
            ->call('addComment')
            ->assertHasErrors(['content' => 'max']);

        $this->assertSame(0, EntraideComment::count());
    }

    // --------------------------------------------------------------- 7 & 8

    public function test_l_auteur_du_commentaire_peut_supprimer_le_sien(): void
    {
        $commentaire = $this->commentaire($this->proposition, $this->commentateur, 'A retirer');

        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->call('deleteComment', (string) $commentaire->id);

        $this->assertSoftDeleted('entraide_comments', ['id' => $commentaire->id]);

        // La suppression d'un commentaire ne supprime PAS l'annonce (CDC §4).
        $this->assertDatabaseHas('services', ['id' => $this->proposition->id, 'deleted_at' => null]);
    }

    public function test_un_tiers_ne_peut_pas_supprimer_le_commentaire_d_un_autre(): void
    {
        $commentaire = $this->commentaire($this->proposition, $this->commentateur, 'Pas le sien');
        $tiers = $this->membre($this->orgA);

        Livewire::actingAs($tiers)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->call('deleteComment', (string) $commentaire->id)
            ->assertForbidden();

        $this->assertDatabaseHas('entraide_comments', ['id' => $commentaire->id, 'deleted_at' => null]);
    }

    public function test_un_superadmin_modere_et_la_trace_distingue_son_geste(): void
    {
        $commentaire = $this->commentaire($this->proposition, $this->commentateur, 'A moderer');
        $admin = $this->membre($this->orgA, ['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->call('deleteComment', (string) $commentaire->id);

        $this->assertSoftDeleted('entraide_comments', ['id' => $commentaire->id]);

        // La trace de MODERATION, et c'est tout l'interet des deux colonnes :
        // `deleted_at` seul ne dirait pas si l'auteur s'est retracte ou si un
        // administrateur est intervenu.
        $moderne = EntraideComment::withTrashed()->findOrFail($commentaire->id);
        $this->assertSame((string) $admin->id, (string) $moderne->moderated_by);
        $this->assertNotNull($moderne->moderated_at);
    }

    public function test_un_retrait_par_l_auteur_ne_laisse_AUCUNE_trace_de_moderation(): void
    {
        $commentaire = $this->commentaire($this->proposition, $this->commentateur, 'Retire par son auteur');

        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->call('deleteComment', (string) $commentaire->id);

        $retire = EntraideComment::withTrashed()->findOrFail($commentaire->id);

        // Le temoin du test precedent : sans celui-ci, des colonnes remplies a
        // tort passeraient inapercues.
        $this->assertNull($retire->moderated_by);
        $this->assertNull($retire->moderated_at);
    }

    // ------------------------------------------------------------- 9 & 10

    public function test_l_auteur_de_l_annonce_est_prevenu(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Une suggestion utile')
            ->call('addComment');

        $this->assertDatabaseHas('member_notifications', [
            'organization_id' => $this->orgA->id,
            'recipient_id' => $this->auteur->id,
            'notification_key' => NotificationCatalogue::ENTRAIDE_COMMENT_CREATED,
            'object_type' => NotificationCatalogue::OBJECT_ENTRAIDE_COMMENT,
            'actor_id' => $this->commentateur->id,
        ]);

        // LUI SEUL : le CDC interdit de prevenir tous les membres.
        $this->assertSame(1, MemberNotification::count());
    }

    public function test_commenter_sa_propre_annonce_ne_previent_personne(): void
    {
        Livewire::actingAs($this->auteur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Je precise mon annonce')
            ->call('addComment');

        $this->assertSame(1, EntraideComment::count());
        $this->assertSame(0, MemberNotification::count());
    }

    // ------------------------------------------------------- 11, 12, 13, 14

    public function test_le_catalogue_declare_les_trois_canaux_tous_actifs_et_reglables(): void
    {
        $cle = NotificationCatalogue::ENTRAIDE_COMMENT_CREATED;

        $this->assertTrue(NotificationCatalogue::has($cle));

        // `assertSame` sur l'ORDRE et le contenu : un canal ajoute, retire ou
        // reordonne fait rougir ce test, ce qui est le but — un defaut par
        // defaut doit se decider, pas se constater.
        $this->assertSame(
            [
                NotificationCatalogue::CHANNEL_IN_APP,
                NotificationCatalogue::CHANNEL_EMAIL,
                NotificationCatalogue::CHANNEL_WEB_PUSH,
            ],
            NotificationCatalogue::channelsFor($cle)
        );

        foreach (NotificationCatalogue::channelsFor($cle) as $canal) {
            $this->assertTrue(NotificationCatalogue::channelDefault($cle, $canal), "[{$canal}] devrait etre actif par defaut.");
            $this->assertTrue(NotificationCatalogue::channelIsConfigurable($cle, $canal), "[{$canal}] devrait etre reglable.");
        }
    }

    public function test_les_trois_canaux_sont_planifies_sans_aucun_reglage(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Trois canaux attendus')
            ->call('addComment');

        // LE CANAL « APPLICATION » N'A PAS DE LIGNE DE LIVRAISON, et ce n'est
        // pas un manque : `NotificationDeliveryPlanner` le dit — « `in_app` est
        // livre par l'existence meme de la `MemberNotification` ». Mon premier
        // jet cherchait `in_app` dans `member_notification_deliveries` et a
        // rougi a juste titre.
        //
        // La preuve du canal application est donc la ligne elle-meme.
        $notification = MemberNotification::firstOrFail();
        $this->assertSame((string) $this->auteur->id, (string) $notification->recipient_id);

        $canaux = DB::table('member_notification_deliveries')
            ->where('notification_id', $notification->id)
            ->pluck('channel')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([
                NotificationCatalogue::CHANNEL_EMAIL,
                NotificationCatalogue::CHANNEL_WEB_PUSH,
            ])->sort()->values()->all(),
            $canaux,
            'Email et Web Push doivent etre planifies alors qu AUCUNE preference n a ete enregistree.'
        );
    }

    public function test_un_canal_desactive_par_le_membre_est_respecte(): void
    {
        // Le destinataire coupe l'email, et LUI SEUL.
        DB::table('member_notification_preferences')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->auteur->id,
            'notification_key' => NotificationCatalogue::ENTRAIDE_COMMENT_CREATED,
            'channel' => NotificationCatalogue::CHANNEL_EMAIL,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Email coupe')
            ->call('addComment');

        $notification = MemberNotification::firstOrFail();

        $canaux = DB::table('member_notification_deliveries')
            ->where('notification_id', $notification->id)
            ->pluck('channel')
            ->all();

        $this->assertNotContains(NotificationCatalogue::CHANNEL_EMAIL, $canaux);

        // Le Web Push SURVIT : sans ce volet, un reglage qui couperait tout
        // passerait pour un reglage respecte.
        $this->assertContains(NotificationCatalogue::CHANNEL_WEB_PUSH, $canaux);

        // Et le canal application survit aussi — mesure par la ligne elle-meme,
        // qui EST sa livraison, et non par une ligne de `deliveries`.
        $this->assertSame(1, MemberNotification::where('recipient_id', $this->auteur->id)->count());
    }

    public function test_les_preferences_sont_INDEPENDANTES_de_celles_du_blog(): void
    {
        // Couper le commentaire de BLOG ne doit rien couper ici — exigence
        // explicite du CDC §6.
        DB::table('member_notification_preferences')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->auteur->id,
            'notification_key' => NotificationCatalogue::BLOG_COMMENT_CREATED,
            'channel' => NotificationCatalogue::CHANNEL_EMAIL,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Independance des reglages')
            ->call('addComment');

        $canaux = DB::table('member_notification_deliveries')
            ->where('notification_id', MemberNotification::firstOrFail()->id)
            ->pluck('channel')
            ->all();

        $this->assertContains(NotificationCatalogue::CHANNEL_EMAIL, $canaux);
    }

    // ------------------------------------------------------------------- 15

    /**
     * Le meme mecanisme sur PLUSIEURS Organizations, dont une creee ICI.
     *
     * Aucun slug code en dur : l'Organization est fabriquee dans le test, donc
     * un mecanisme qui ne marcherait que pour « main » ou pour une organisation
     * connue d'avance echouerait.
     */
    public function test_le_mecanisme_vaut_pour_toute_Organization_y_compris_creee_a_l_instant(): void
    {
        $orgNeuve = Organization::factory()->create(['is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $orgNeuve);

        $auteurNeuf = $this->membre($orgNeuve);
        $lecteurNeuf = $this->membre($orgNeuve);
        $annonceNeuve = $this->propositionDe($auteurNeuf, $orgNeuve);

        Livewire::actingAs($lecteurNeuf)
            ->test(EntraideComments::class, ['annonce' => $annonceNeuve])
            ->set('content', 'Suggestion dans une organisation neuve')
            ->call('addComment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('entraide_comments', [
            'organization_id' => $orgNeuve->id,
            'commentable_id' => $annonceNeuve->id,
        ]);

        $this->assertDatabaseHas('member_notifications', [
            'organization_id' => $orgNeuve->id,
            'recipient_id' => $auteurNeuf->id,
        ]);
    }

    // ------------------------------------------------------------------- 16

    public function test_la_notification_mene_au_commentaire_dans_LA_BONNE_organization(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Ancre attendue')
            ->call('addComment');

        $commentaire = EntraideComment::firstOrFail();
        $cible = app(NotificationTargetResolver::class)->resolve(MemberNotification::firstOrFail());

        $this->assertSame(
            route('organization.services.show', [
                'organization' => $this->orgA->slug,
                'service' => $this->proposition->id,
            ]).'#commentaire-'.$commentaire->id,
            $cible
        );

        // Et la garde qui compte vraiment : JAMAIS `main`, ni l'Organization
        // par defaut (CDC §7).
        $this->assertStringContainsString('/org/'.$this->orgA->slug.'/', (string) $cible);
        $this->assertStringNotContainsString('/org/main/', (string) $cible);
    }

    public function test_la_notification_d_une_demande_mene_a_la_fiche_de_DEMANDE(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->demande])
            ->set('content', 'Ancre cote demande')
            ->call('addComment');

        $cible = app(NotificationTargetResolver::class)->resolve(MemberNotification::firstOrFail());

        $this->assertStringContainsString('/requests/', (string) $cible);
        $this->assertStringNotContainsString('/services/', (string) $cible);
    }

    public function test_un_commentaire_supprime_ne_mene_nulle_part(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Bientot supprime')
            ->call('addComment');

        EntraideComment::firstOrFail()->delete();

        $this->assertNull(
            app(NotificationTargetResolver::class)->resolve(MemberNotification::firstOrFail())
        );
    }

    // ------------------------------------------------------------------- 17

    /**
     * Une ecriture annulee n'emet AUCUNE notification (CDC §5).
     *
     * La mesure est faite en enveloppant le geste dans une transaction que le
     * test annule : le commentaire disparait, et il ne doit rester aucune
     * notification qui parlerait d'un fait qui n'a pas eu lieu.
     */
    public function test_une_ecriture_annulee_ne_laisse_aucune_notification(): void
    {
        DB::beginTransaction();

        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Ecriture qui sera annulee')
            ->call('addComment');

        $this->assertSame(1, EntraideComment::count());

        DB::rollBack();

        $this->assertSame(0, EntraideComment::count());
        $this->assertSame(0, MemberNotification::count());
    }

    // ------------------------------------------------------------------- 18

    public function test_rejouer_l_emission_sur_le_meme_commentaire_ne_duplique_pas(): void
    {
        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Un seul fait')
            ->call('addComment');

        $this->assertSame(1, MemberNotification::count());
        $avant = MemberNotification::firstOrFail();

        // Le producteur rejoue le MEME fait. L'`event_id` etant deterministe, il
        // CONSTATE la premiere notification au lieu d'en creer une seconde.
        $commentaire = EntraideComment::firstOrFail();
        $methode = new \ReflectionMethod(EntraideComments::class, 'prevenirLAuteur');
        $methode->setAccessible(true);

        $composant = Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->instance();

        $methode->invoke($composant, $this->proposition, $commentaire);

        $this->assertSame(1, MemberNotification::count());
        $this->assertSame((string) $avant->id, (string) MemberNotification::firstOrFail()->id);
    }

    // ------------------------------------------- la branche du livreur email

    /**
     * **L'email emploie le GABARIT ADMINISTRABLE, pas le repli de langue.**
     *
     * C'est le test le plus important de ce fichier, et la raison est ecrite
     * dans le depot : la branche de `NotificationEmailDeliverer` a ete oubliee
     * DEUX fois (T1751, T1761), et son absence est **silencieuse** — le gabarit
     * rend `null`, l'email part quand meme par le repli, sans aucun nom, et
     * rien ne le signale.
     *
     * La mesure cherche donc une chaine que **SEUL** le gabarit produit : le
     * repli de langue, lui, ne porte ni le nom du commentateur ni le titre de
     * l'annonce. « Email actif par defaut » ne prouverait rien du tout.
     */
    public function test_l_email_emploie_le_gabarit_administrable_et_pas_le_repli(): void
    {
        $this->seed(\Database\Seeders\SystemEmailTemplateSeeder::class);

        $commentaire = $this->commentaire($this->proposition, $this->commentateur, 'Texte du membre');

        $compose = app(\App\Services\Entraide\EntraideCommentMailer::class)->compose(
            $commentaire,
            $this->auteur,
            $this->commentateur,
            'https://exemple.test/cible#commentaire-'.$commentaire->id,
            'fr'
        );

        // Le gabarit nomme le commentateur ET l'annonce. Le repli ne fait ni
        // l'un ni l'autre : si l'une de ces deux assertions tombe, c'est que le
        // repli a parle.
        $this->assertStringContainsString($this->commentateur->publicDisplayName(), $compose['body']);
        $this->assertStringContainsString($this->proposition->title, $compose['body']);

        // Et le type d'annonce est resolu, ce qui est ce qui permet a UN gabarit
        // de servir les deux.
        $this->assertStringContainsString(__('entraide.commentaires.type_proposition'), $compose['body']);
    }

    public function test_le_journal_expurge_le_texte_ecrit_par_le_membre(): void
    {
        $this->seed(\Database\Seeders\SystemEmailTemplateSeeder::class);

        $commentaire = $this->commentaire($this->proposition, $this->commentateur, 'PHRASE-PRIVEE-DU-MEMBRE');

        $pourJournal = app(\App\Services\Entraide\EntraideCommentMailer::class)->compose(
            $commentaire,
            $this->auteur,
            $this->commentateur,
            '[action-link-redacted]',
            'fr',
            pourJournal: true
        );

        $this->assertStringNotContainsString('PHRASE-PRIVEE-DU-MEMBRE', $pourJournal['body']);
        $this->assertStringContainsString(
            \App\Services\Entraide\EntraideCommentMailer::EXTRAIT_EXPURGE,
            $pourJournal['body']
        );
    }

    // -------------------------------------------------------------- 19 & 20

    public function test_les_commentaires_de_blog_ne_sont_pas_touches(): void
    {
        // Non-regression : les deux tables sont distinctes, et un commentaire
        // d'entraide n'entre jamais dans `blog_comments`.
        $this->commentaire($this->proposition, $this->commentateur, 'Cote entraide');

        $this->assertSame(1, DB::table('entraide_comments')->count());
        $this->assertSame(0, DB::table('blog_comments')->count());
    }

    public function test_aucune_table_de_transaction_n_est_touchee(): void
    {
        $avant = DB::table('transactions')->count();

        Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition])
            ->set('content', 'Un commentaire n engage rien')
            ->call('addComment');

        // Un commentaire n'est ni une demande d'echange, ni une acceptation
        // d'aide, ni un engagement de temps. Le CDC insiste, et c'est mesurable.
        $this->assertSame($avant, DB::table('transactions')->count());
        $this->assertSame(
            (int) $this->auteur->fresh()->points_balance,
            (int) $this->auteur->points_balance,
            'Le solde de temps ne doit pas bouger.'
        );
    }

    // ----------------------------------------------------- limitation de frequence

    public function test_la_limitation_de_frequence_arrete_une_rafale(): void
    {
        $composant = Livewire::actingAs($this->commentateur)
            ->test(EntraideComments::class, ['annonce' => $this->proposition]);

        for ($i = 0; $i < EntraideComments::ECRITURES_PAR_MINUTE; $i++) {
            $composant->set('content', "Commentaire {$i}")->call('addComment')->assertHasNoErrors();
        }

        $this->assertSame(EntraideComments::ECRITURES_PAR_MINUTE, EntraideComment::count());

        // Le suivant est refuse, et il est refuse LISIBLEMENT.
        $composant->set('content', 'Celui de trop')->call('addComment')->assertHasErrors('content');

        $this->assertSame(EntraideComments::ECRITURES_PAR_MINUTE, EntraideComment::count());
    }

    // ------------------------------------------------------------------ outils

    private function membre(Organization $org, array $surcharges = []): User
    {
        return User::factory()->complete()->create(array_merge([
            'organization_id' => $org->id,
        ], $surcharges));
    }

    private function propositionDe(User $auteur, Organization $org): Service
    {
        return Service::factory()
            ->forUser($auteur)
            ->forCategory(Category::factory()->create())
            ->create(['organization_id' => $org->id, 'status' => 'active']);
    }

    private function demandeDe(User $auteur, Organization $org): ServiceRequest
    {
        return ServiceRequest::factory()
            ->forUser($auteur)
            ->create([
                'organization_id' => $org->id,
                'category_id' => Category::factory()->create()->id,
                'status' => 'open',
            ]);
    }

    private function commentaire(Service|ServiceRequest $annonce, User $auteur, string $texte): EntraideComment
    {
        return EntraideComment::create([
            'organization_id' => $annonce->organization_id,
            'commentable_type' => $annonce->getMorphClass(),
            'commentable_id' => $annonce->getKey(),
            'user_id' => $auteur->id,
            'content' => $texte,
            'is_approved' => true,
        ]);
    }

    private function snapshotDe(string $url, User $acteur, string $composant): string
    {
        $html = $this->actingAs($acteur)->get($url)->assertOk()->getContent();

        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $tous);

        foreach ($tous[1] ?? [] as $candidat) {
            $decode = html_entity_decode($candidat, ENT_QUOTES);

            if (str_contains($decode, $composant)) {
                return $decode;
            }
        }

        $this->fail("le composant [{$composant}] n'est pas monte sur [{$url}]");
    }

    /**
     * Un appel sur le VRAI endpoint. L'URI est resolue a l'execution : son
     * prefixe est derive de l'environnement, et le coder en dur rendrait un 404
     * trompeur qui ressemblerait a une protection.
     */
    private function update(User $acteur, string $snapshot, array $updates, array $calls = [])
    {
        return $this->actingAs($acteur)
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => $calls,
            ]]]);
    }
}
