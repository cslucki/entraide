<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopNotificationPreference;
use App\Models\LoopPoll;
use App\Models\LoopRoadmapItem;
use App\Models\LoopMessage;
use App\Livewire\LoopRoadmapCard;
use App\Livewire\LoopJournalCard;
use App\Models\LoopCard;
use App\Models\LoopJournalEntry;
use Livewire\Livewire;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventEmitter;
use App\Support\Feed\FeedEventRenderer;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1879 (demande Cyril, 22/11) — comme la Veille, trois annonces ChatLoop
 * jusque-la muettes notifient maintenant les membres en in-app + Web Push
 * (jamais par e-mail) : un Sondage pose, un Article publie dans la Boucle, un
 * depot de fichiers dans Dossiers. Seules les CREATIONS notifient.
 */
class TASK1879NotificationsCollectivesBoucleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Loop $loop;

    private User $auteur;

    private User $membre;

    private User $parti;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');
        Mail::fake();

        $this->organization = Organization::factory()->create(['is_active' => true, 'is_public' => true]);
        $this->auteur = $this->personne();
        $this->membre = $this->personne();
        $this->parti = $this->personne();

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
        ]);

        $this->adhesion($this->auteur, 'owner');
        $this->adhesion($this->membre, 'member');
        $this->adhesion($this->parti, 'member', 'removed');
    }

    public function test_un_sondage_pose_notifie_les_membres_mais_pas_sa_cloture(): void
    {
        $sondage = LoopPoll::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
            'question' => 'Quel creneau ?',
            'options' => ['Lundi', 'Mardi'],
            'status' => 'open',
        ]);

        $this->service()->sendPollEventMessage($this->loop, $this->auteur, $sondage, 'created');
        $this->assertDestinataires(NotificationCatalogue::LOOP_POLL_CREATED);

        $this->service()->sendPollEventMessage($this->loop, $this->auteur, $sondage, 'closed');
        $this->assertSame(1, $this->notifications(NotificationCatalogue::LOOP_POLL_CREATED)->count(), 'la cloture ne notifie pas');
    }

    public function test_un_article_publie_notifie_une_seule_fois(): void
    {
        $post = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->auteur->id,
            'title' => 'Article de test',
            'content' => '<p>Contenu.</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->service()->sendArticlePublishedMessage($this->loop, $this->auteur, $post);
        $this->service()->sendArticlePublishedMessage($this->loop, $this->auteur, $post);

        $this->assertDestinataires(NotificationCatalogue::LOOP_ARTICLE_PUBLISHED);
    }

    public function test_un_depot_de_plusieurs_fichiers_notifie_une_seule_fois(): void
    {
        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => null,
            'loop_id' => $this->loop->id,
        ]);
        $fichiers = DossierFile::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->auteur->id,
        ]);

        $this->service()->sendDossierFileMessage($this->loop, $this->auteur, $fichiers);

        $this->assertDestinataires(NotificationCatalogue::LOOP_DOSSIER_FILE_UPLOADED);
    }

    public function test_canaux_in_app_et_web_push_jamais_email_et_sourdine_par_boucle(): void
    {
        foreach ([
            NotificationCatalogue::LOOP_POLL_CREATED,
            NotificationCatalogue::LOOP_ARTICLE_PUBLISHED,
            NotificationCatalogue::LOOP_DOSSIER_FILE_UPLOADED,
        ] as $cle) {
            $this->assertTrue(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_IN_APP), $cle);
            $this->assertTrue(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_WEB_PUSH), $cle);
            $this->assertTrue(NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_WEB_PUSH), $cle);
            $this->assertNull(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_EMAIL), $cle);
            $this->assertSame((string) $this->loop->id, NotificationCatalogue::loopScopeFor($cle, (string) $this->loop->id), $cle);
        }

        $reglage = new LoopNotificationPreference(['collective_enabled' => false]);
        $reglage->organization_id = (string) $this->organization->id;
        $reglage->loop_id = (string) $this->loop->id;
        $reglage->user_id = (string) $this->membre->id;
        $reglage->save();

        $sondage = LoopPoll::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
            'question' => 'En sourdine ?',
            'options' => ['Oui', 'Non'],
            'status' => 'open',
        ]);
        $this->service()->sendPollEventMessage($this->loop, $this->auteur, $sondage, 'created');

        $this->assertSame(0, $this->notifications(NotificationCatalogue::LOOP_POLL_CREATED)->count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_sondage_article_et_depot_apparaissent_dans_le_flux_de_la_boucle(): void
    {
        $sondage = LoopPoll::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
            'question' => 'Bruit le soir ?',
            'status' => 'open',
        ]);
        $sondage->options()->create(['label' => 'Oui', 'position' => 1]);
        $sondage->options()->create(['label' => 'Non', 'position' => 2]);
        $this->service()->sendPollEventMessage($this->loop, $this->auteur, $sondage, 'created');
        $this->service()->sendPollEventMessage($this->loop, $this->auteur, $sondage, 'closed');

        $article = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->auteur->id,
            'title' => 'Article du Flux',
            'content' => '<p>Un <strong>contenu</strong> riche.</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $this->service()->sendArticlePublishedMessage($this->loop, $this->auteur, $article);

        $dossier = Dossier::factory()->create(['organization_id' => $this->organization->id, 'owner_id' => null, 'loop_id' => $this->loop->id]);
        $fichiers = DossierFile::factory()->count(2)->create(['organization_id' => $this->organization->id, 'dossier_id' => $dossier->id, 'uploaded_by' => $this->auteur->id]);
        $depot = $this->service()->sendDossierFileMessage($this->loop, $this->auteur, $fichiers);

        foreach ([
            FeedEventCatalogue::SONDAGE_PUBLIE => [$sondage->id, 'violet', 'Bruit le soir ?', 'Oui / Non'],
            FeedEventCatalogue::ARTICLE_PUBLIE => [$article->id, 'rose', 'Article du Flux', 'Un contenu riche.'],
            FeedEventCatalogue::FICHIERS_DEPOSES => [$depot->id, 'emerald', '2 fichiers', $fichiers->map->nomLisible()->implode(', ')],
        ] as $type => [$objetId, $teinte, $titre, $texte]) {
            $lignes = FeedPost::query()->where('type', $type)->get();
            $this->assertCount(1, $lignes, "{$type} : une seule ligne (la cloture d'un sondage n'en ajoute pas)");
            $ligne = $lignes->first();
            $this->assertSame((string) $objetId, (string) $ligne->object_id);
            $this->assertSame(FeedEventCatalogue::VISIBILITE_BOUCLE, $ligne->visibility, "{$type} : membres de la Boucle seulement");
            $this->assertSame($this->loop->id, $ligne->loop_id);

            $apercu = FeedEventRenderer::apercu($ligne, (string) $this->organization->slug);
            $this->assertNotNull($apercu, $type);
            $this->assertSame($teinte, $apercu['teinte']);
            $this->assertSame($titre, $apercu['titre']);
            $this->assertSame($texte, $apercu['texte']);
            $this->assertNotNull($apercu['url'], "{$type} : la carte mene quelque part");
            $this->assertStringContainsString((string) $this->loop->name, (string) FeedEventRenderer::ligneHtml($ligne, (string) $this->organization->slug));
        }

        // Un ancien membre (retire) ne voit pas ces lignes ; un membre actif, si.
        $this->assertSame(0, FeedPost::query()->visibleTo($this->parti)->whereIn('type', [FeedEventCatalogue::SONDAGE_PUBLIE, FeedEventCatalogue::ARTICLE_PUBLIE, FeedEventCatalogue::FICHIERS_DEPOSES])->count());
        $this->assertSame(3, FeedPost::query()->visibleTo($this->membre)->whereIn('type', [FeedEventCatalogue::SONDAGE_PUBLIE, FeedEventCatalogue::ARTICLE_PUBLIE, FeedEventCatalogue::FICHIERS_DEPOSES])->count());
    }

    /**
     * Demande Cyril (22/11) — les autres lignes du Flux ont aussi leur icone :
     * profil (presentation), Boucles (adhesion), tuile date (evenement),
     * fleche haut (demande d'aide) et fleche bas (proposition).
     */
    public function test_les_autres_lignes_du_flux_portent_leur_icone(): void
    {
        $emetteur = app(FeedEventEmitter::class);
        $org = (string) $this->organization->id;

        $this->auteur->forceFill(['bio' => 'Je suis benevole.'])->save();
        $presentation = $emetteur->emettre(FeedEventCatalogue::PRESENTATION_TERMINEE, $this->auteur, $this->auteur, $org);
        $adhesion = $emetteur->emettre(FeedEventCatalogue::ADHESION_BOUCLE, LoopMember::where('user_id', $this->membre->id)->sole(), $this->membre, $org, $this->loop);
        $demande = $emetteur->emettre(FeedEventCatalogue::DEMANDE_PUBLIEE, ServiceRequest::factory()->create(['organization_id' => $org, 'user_id' => $this->auteur->id]), $this->auteur, $org);
        $offre = $emetteur->emettre(FeedEventCatalogue::PROPOSITION_PUBLIEE, Service::factory()->create(['organization_id' => $org, 'user_id' => $this->auteur->id]), $this->auteur, $org);
        $evenement = $emetteur->emettre(FeedEventCatalogue::EVENEMENT_CREE, LoopEvent::create([
            'organization_id' => $org, 'loop_id' => $this->loop->id, 'created_by' => $this->auteur->id,
            'status' => LoopEvent::STATUS_SCHEDULED, 'title' => 'Reunion', 'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => \Carbon\CarbonImmutable::parse('2026-12-01 10:00', 'Europe/Paris')->utc(),
            'timezone' => 'Europe/Paris', 'location' => '', 'meeting_url' => '', 'visibility' => 'loop',
        ]), $this->auteur, $org, $this->loop);

        $apercu = fn ($ligne) => FeedEventRenderer::apercu($ligne, (string) $this->organization->slug);

        $this->assertStringStartsWith('M15.75 7.5a3.75', $apercu($presentation)['icone']);
        $this->assertSame(config('navigation_icons.loops'), $apercu($adhesion)['icone']);
        $this->assertSame('M12 20V4m0 0-6 6m6-6 6 6', $apercu($demande)['icone']);
        $this->assertSame('M12 4v16m0 0-6-6m6 6 6-6', $apercu($offre)['icone']);
        $this->assertSame(['01', '10:00', false], [$apercu($evenement)['date']['quantieme'], $apercu($evenement)['date']['heure'], $apercu($evenement)['date']['annule']]);
    }

    /**
     * Demande Cyril (22/11) — Roadmap : une action ajoutee, puis passee « En
     * cours » et « Fait » : carte ChatLoop, notification in-app + Web Push et
     * ligne du Flux a chaque etape. Un retour vers « A faire » ne dit rien.
     */
    public function test_la_roadmap_annonce_l_ajout_et_l_avancee_d_une_action(): void
    {
        Livewire::actingAs($this->auteur)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Preparer la fete')
            ->call('createAction');

        $action = LoopRoadmapItem::where('title', 'Preparer la fete')->sole();
        $this->assertDestinataires(NotificationCatalogue::LOOP_ROADMAP_ITEM_CREATED);
        $ajout = FeedPost::where('type', FeedEventCatalogue::ROADMAP_ACTION_AJOUTEE)->sole();
        $this->assertStringStartsWith('M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25', FeedEventRenderer::apercu($ajout, (string) $this->organization->slug)['icone'], 'icone Kanban de la Card Roadmap');
        $this->assertSame((string) $action->id, (string) $ajout->object_id);
        $this->assertSame(FeedEventCatalogue::VISIBILITE_BOUCLE, $ajout->visibility);
        $this->assertSame(['teal', 'Preparer la fete', __('loops.roadmap_status_todo')], array_values(array_intersect_key(
            FeedEventRenderer::apercu($ajout, (string) $this->organization->slug), array_flip(['teinte', 'titre', 'texte']))));

        Livewire::actingAs($this->auteur)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('setStatus', $action->id, LoopRoadmapItem::STATUS_IN_PROGRESS)
            ->call('setStatus', $action->id, LoopRoadmapItem::STATUS_DONE)
            ->call('setStatus', $action->id, LoopRoadmapItem::STATUS_TODO);

        $deplacements = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'roadmap_action')->where('metadata->event', 'moved')->orderBy('created_at')->get();
        $this->assertEqualsCanonicalizing([LoopRoadmapItem::STATUS_IN_PROGRESS, LoopRoadmapItem::STATUS_DONE], $deplacements->pluck('metadata.to')->all(), 'le retour a « A faire » n\'est pas annonce');
        $this->assertSame(2, $this->notifications(NotificationCatalogue::LOOP_ROADMAP_ITEM_MOVED)->count());
        $this->assertSame([$this->membre->id], $this->notifications(NotificationCatalogue::LOOP_ROADMAP_ITEM_MOVED)->pluck('recipient_id')->unique()->values()->all());

        $lignes = FeedPost::where('type', FeedEventCatalogue::ROADMAP_ACTION_AVANCEE)->get();
        $this->assertCount(2, $lignes);
        $textes = $lignes->map(fn ($l) => FeedEventRenderer::apercu($l, (string) $this->organization->slug)['texte'])->sort()->values()->all();
        $this->assertContains(__('loops.roadmap_status_todo').' → '.__('loops.roadmap_status_in_progress'), $textes);
        $this->assertContains(__('loops.roadmap_status_in_progress').' → '.__('loops.roadmap_status_done'), $textes);
        $this->assertSame(0, FeedPost::query()->visibleTo($this->parti)->whereIn('type', [FeedEventCatalogue::ROADMAP_ACTION_AJOUTEE, FeedEventCatalogue::ROADMAP_ACTION_AVANCEE])->count());
        Mail::assertNothingSent();
    }

    /**
     * Demande Cyril (22/11) — dans le Flux, un objet de Boucle mene a SON
     * message ChatLoop (`?message=`) ; un evenement d'Organization, a sa
     * ligne de l'Agenda.
     */
    public function test_le_flux_mene_au_message_ou_a_l_agenda(): void
    {
        $slug = (string) $this->organization->slug;
        $sondage = LoopPoll::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'created_by' => $this->auteur->id, 'question' => 'Lien ?', 'status' => 'open']);
        $annonce = $this->service()->sendPollEventMessage($this->loop, $this->auteur, $sondage, 'created');
        $ligne = FeedPost::where('type', FeedEventCatalogue::SONDAGE_PUBLIE)->sole();

        $this->assertStringEndsWith('?message='.$annonce->id, FeedEventRenderer::lien($ligne, $slug));
        $this->assertSame(FeedEventRenderer::lien($ligne, $slug), FeedEventRenderer::apercu($ligne, $slug)['url']);

        $emetteur = app(FeedEventEmitter::class);
        $evenement = fn (string $visibilite, string $titre) => LoopEvent::create([
            'organization_id' => $this->organization->id, 'loop_id' => $this->loop->id, 'created_by' => $this->auteur->id,
            'status' => LoopEvent::STATUS_SCHEDULED, 'title' => $titre, 'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => now()->addWeek(), 'timezone' => 'Europe/Paris', 'location' => '', 'meeting_url' => '', 'visibility' => $visibilite,
        ]);

        $deBoucle = $evenement(LoopEvent::VISIBILITY_LOOP, 'De Boucle');
        $message = $this->service()->sendEventMessage($this->loop, $this->auteur, $deBoucle, 'created');
        $ligneBoucle = $emetteur->emettre(FeedEventCatalogue::EVENEMENT_CREE, $deBoucle, $this->auteur, (string) $this->organization->id, $this->loop);
        $this->assertStringEndsWith('?message='.$message->id, FeedEventRenderer::lien($ligneBoucle, $slug));

        $ouvert = $evenement(LoopEvent::VISIBILITY_ORGANIZATION, 'Pour tous');
        $ligneOrg = $emetteur->emettre(FeedEventCatalogue::EVENEMENT_CREE, $ouvert, $this->auteur, (string) $this->organization->id, $this->loop);
        $this->assertStringEndsWith('/agenda#agenda-event-'.$ouvert->id, FeedEventRenderer::lien($ligneOrg, $slug));
    }

    /**
     * Demande Cyril (22/11, design) — une ligne NEE dans une Boucle porte le
     * bandeau « lieu » (nom, visibilite, « Ouvrir la Boucle ») ; l'adhesion,
     * dont l'apercu EST la Boucle, non.
     */
    public function test_le_flux_met_en_valeur_la_boucle_d_un_evenement(): void
    {
        $sondage = LoopPoll::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'created_by' => $this->auteur->id, 'question' => 'Bandeau ?', 'status' => 'open']);
        $this->service()->sendPollEventMessage($this->loop, $this->auteur, $sondage, 'created');
        app(FeedEventEmitter::class)->emettre(FeedEventCatalogue::ADHESION_BOUCLE, LoopMember::where('user_id', $this->membre->id)->sole(), $this->membre, (string) $this->organization->id, $this->loop);

        $html = $this->actingAs($this->membre)->get(route('organization.flux', $this->organization->slug))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-feed-loop-tile="'.$this->loop->id.'"'), 'une tuile Boucle : le sondage, pas l\'adhesion');
        $this->assertStringNotContainsString('>'.__('feed.open_loop').'<', $html, 'pas de bouton « Ouvrir la Boucle » visible');
        $this->assertStringContainsString(e(str_replace(':name ', '', __('feed.events_sans_boucle.loop_poll_created'))), $html);
        $this->assertStringNotContainsString(e(str_replace([':name ', ':object'], '', __('feed.events.loop_poll_created'))), $html, 'la phrase ne repete plus la Boucle');
    }

    /**
     * Demande Cyril (22/11) — une entree ECRITE dans le Journal : carte
     * ChatLoop, ligne du Flux (visible de la Boucle seule), notification
     * in-app + Web Push aux membres. Une correction ou une promotion de
     * message ne reannonce rien.
     */
    public function test_le_journal_annonce_une_entree_ecrite(): void
    {
        app()->instance('current_organization', $this->organization);
        LoopCard::firstOrCreate(['loop_id' => $this->loop->id, 'card_key' => 'core.journal'], ['organization_id' => $this->organization->id, 'enabled' => true]);

        Livewire::actingAs($this->auteur)
            ->test(LoopJournalCard::class, ['loop' => $this->loop])
            ->set('showForm', true)
            ->set('body', 'Premiere reunion, tout le monde etait la.')
            ->set('occurredOn', '2026-11-20')
            ->call('save')
            ->assertHasNoErrors();

        $entree = LoopJournalEntry::sole();
        $annonce = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'journal_entry')->sole();
        $this->assertSame($entree->id, $annonce->metadata['journal_entry_id']);
        $this->assertDestinataires(NotificationCatalogue::LOOP_JOURNAL_ENTRY_CREATED);

        $ligne = FeedPost::where('type', FeedEventCatalogue::JOURNAL_ENTREE_ECRITE)->sole();
        $this->assertSame(FeedEventCatalogue::VISIBILITE_BOUCLE, $ligne->visibility);
        $apercu = FeedEventRenderer::apercu($ligne, (string) $this->organization->slug);
        $this->assertSame('stone', $apercu['teinte']);
        $this->assertStringContainsString('Premiere reunion', $apercu['texte']);
        $this->assertStringEndsWith('?message='.$annonce->id, (string) $apercu['url']);
        $this->assertSame(0, FeedPost::query()->visibleTo($this->parti)->where('type', FeedEventCatalogue::JOURNAL_ENTREE_ECRITE)->count());

        // Corriger l'entree ne reannonce pas.
        Livewire::actingAs($this->auteur)
            ->test(LoopJournalCard::class, ['loop' => $this->loop])
            ->call('startEditing', $entree->id)
            ->set('body', 'Premiere reunion (corrigee).')
            ->call('save');
        $this->assertSame(1, LoopMessage::where('type', 'journal_entry')->count());
        $this->assertSame(1, $this->notifications(NotificationCatalogue::LOOP_JOURNAL_ENTRY_CREATED)->count());

        // La carte ChatLoop relit le texte EN DIRECT.
        Livewire::actingAs($this->membre)
            ->test(\App\Livewire\LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-journal-entry-card')
            ->assertSee('Premiere reunion (corrigee).');
        Mail::assertNothingSent();
    }

    // ── Outils ──────────────────────────────────────────────────────────────

    private function assertDestinataires(string $cle): void
    {
        $notifications = $this->notifications($cle);

        $this->assertSame([$this->membre->id], $notifications->pluck('recipient_id')->all(), "{$cle} : membres actifs sauf l'auteur");
        $this->assertSame($this->loop->id, $notifications->first()->object_id);
        $this->assertFalse(
            $notifications->flatMap->deliveries->contains('channel', NotificationCatalogue::CHANNEL_EMAIL),
            "{$cle} : aucune livraison e-mail",
        );
        Mail::assertNothingSent();
    }

    private function notifications(string $cle)
    {
        return MemberNotification::query()->where('notification_key', $cle)->get();
    }

    private function service(): LoopMessageService
    {
        return app(LoopMessageService::class);
    }

    private function personne(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'approved_at' => now(),
        ]);
    }

    private function adhesion(User $user, string $role, string $status = 'active'): void
    {
        LoopMember::create([
            'loop_id' => $this->loop->id,
            'user_id' => $user->id,
            'organization_id' => $this->organization->id,
            'role' => $role,
            'status' => $status,
        ]);
    }
}
