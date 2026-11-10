<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\MemberNotification;
use App\Models\MemberNotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmailDeliverer;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1797 — la mention collective `@tous`.
 *
 * ## Ce que le CDC interdit, et que ce fichier mesure
 *
 * Le plafond de dix mentions (`LoopChat::MENTIONS_MAX`, T1772) existait
 * PRECISEMENT pour empecher un usage collectif non controle. `@tous` rouvre
 * cette porte, mais UNIQUEMENT pour le proprietaire et l'animateur de CETTE
 * Boucle — jamais pour un simple membre, un visiteur, ou un administrateur qui
 * n'est ni l'un ni l'autre ICI (arbitrage explicite du CDC, qui exclut le
 * bypass habituel de `LoopPermissionResolver`).
 *
 * ## La discipline P1772 est reprise a l'identique
 *
 * Rien n'est cru sur parole : l'autorisation est re-derivee en base a chaque
 * envoi (`calculerPeutMentionnerTous()`), et le token lui-meme est re-derive
 * du corps REELLEMENT ecrit (`corpsContientMentionTous()`) — jamais d'une
 * propriete Livewire. Un refus est EXPLICITE : aucun message publie, aucune
 * notification emise.
 */
class TASK1797MentionAllTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $proprietaire;

    private User $animateur;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1797']);

        $this->proprietaire = User::factory()->complete()->create([
            'organization_id' => $this->org->id, 'first_name' => 'Alice', 'name' => 'Owner',
        ]);
        $this->animateur = User::factory()->complete()->create([
            'organization_id' => $this->org->id, 'first_name' => 'Bob', 'name' => 'Facilitator',
        ]);
        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->org->id, 'first_name' => 'Carl', 'name' => 'Membre',
        ]);

        app()->instance('current_organization', $this->org);

        Http::preventStrayRequests();
        Http::fake();

        $service = new LoopService;
        // `createLoop()` fait du PREMIER argument le `owner` (T-service, ligne
        // 136+) : c'est ce comportement, pas une convention de ce fichier.
        $this->loop = $service->createLoop($this->proprietaire, 'Boucle de la mention collective');
        $service->addMemberByUserId($this->loop, $this->animateur->id, LoopRoleRegistry::FACILITATOR);
        $service->addMemberByUserId($this->loop, $this->membre->id, LoopRoleRegistry::MEMBER);
    }

    private function envoyer(User $auteur, string $corps): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', $corps)
            ->call('sendMessage');
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MemberNotification> */
    private function notificationsDeLaMention(): \Illuminate\Database\Eloquent\Collection
    {
        return MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_MENTION_CREATED)
            ->get();
    }

    private function messagesPublies(): int
    {
        return LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->count();
    }

    // =========================================================================
    // 1-2 — proprietaire et animateur autorises
    // =========================================================================

    public function test_le_proprietaire_peut_mentionner_tous_les_membres(): void
    {
        $this->envoyer($this->proprietaire, 'Bonjour @tous')->assertHasNoErrors();

        $this->assertSame(1, $this->messagesPublies());
        // Les deux AUTRES membres actifs — l'auteur ne se notifie jamais.
        $this->assertCount(2, $this->notificationsDeLaMention());
    }

    public function test_l_animateur_peut_mentionner_tous_les_membres(): void
    {
        $this->envoyer($this->animateur, 'Bonjour @tous')->assertHasNoErrors();

        $this->assertSame(1, $this->messagesPublies());
        $this->assertCount(2, $this->notificationsDeLaMention());
    }

    /** L'alias historique `moderator` compte comme `facilitator` (`LoopRoleRegistry`). */
    public function test_l_alias_historique_moderator_est_aussi_autorise(): void
    {
        LoopMember::query()
            ->where('loop_id', $this->loop->id)
            ->where('user_id', $this->animateur->id)
            ->update(['role' => 'moderator']);

        $this->envoyer($this->animateur, 'Bonjour @tous')->assertHasNoErrors();

        $this->assertSame(1, $this->messagesPublies());
    }

    // =========================================================================
    // 3-5 — refus EXPLICITES, rien ne part
    // =========================================================================

    public function test_un_membre_ordinaire_est_refuse_et_rien_n_est_publie(): void
    {
        $this->envoyer($this->membre, 'Bonjour @tous')->assertHasErrors('body');

        $this->assertSame(0, $this->messagesPublies());
        $this->assertCount(0, $this->notificationsDeLaMention());
    }

    /**
     * `canContribute()` refuse DEJA, EN AMONT, silencieusement — c'est le
     * comportement etabli de `sendMessage()` pour quiconque n'est pas membre
     * (aucun `addError`, un simple retour). Le controle `@tous` n'a donc
     * meme pas l'occasion de s'exprimer ici, et c'est la bonne nouvelle :
     * aucun message n'est publie, par la garde la plus en amont.
     */
    public function test_un_visiteur_non_membre_est_refuse(): void
    {
        $visiteur = User::factory()->complete()->create(['organization_id' => $this->org->id]);

        $this->envoyer($visiteur, 'Bonjour @tous');

        $this->assertSame(0, $this->messagesPublies());
        $this->assertCount(0, $this->notificationsDeLaMention());
    }

    /**
     * Un animateur BANNI est deja bloque par `canContribute()` — la garde la
     * plus en amont — avant meme d'atteindre le controle `@tous`. Les deux
     * defenses sont independantes et s'accordent.
     */
    public function test_un_animateur_banni_est_refuse(): void
    {
        $this->animateur->forceFill(['banned_at' => now()])->save();

        Livewire::actingAs($this->animateur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Bonjour @tous')
            ->call('sendMessage');

        $this->assertSame(0, $this->messagesPublies());
        $this->assertCount(0, $this->notificationsDeLaMention());
    }

    // =========================================================================
    // 6 — Boucle archivee : refusee en amont, pour tout le monde
    // =========================================================================

    public function test_une_boucle_archivee_refuse_meme_le_proprietaire(): void
    {
        $this->loop->forceFill(['status' => 'archived'])->save();

        Livewire::actingAs($this->proprietaire)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Bonjour @tous')
            ->call('sendMessage');

        $this->assertSame(0, $this->messagesPublies());
        $this->assertCount(0, $this->notificationsDeLaMention());
    }

    // =========================================================================
    // 7 — isolation entre Organizations
    // =========================================================================

    /**
     * Une adhesion FORGEE, dans l'Organization d'une AUTRE Boucle, ne doit
     * donner aucun droit sur CETTE Boucle.
     *
     * Meme doctrine que `TASK1772MentionsEmissionTest::
     * test_un_identifiant_forge_ne_notifie_rien` : un cas hostile realiste,
     * pose directement en base plutot que simule par la seule UI.
     */
    public function test_une_adhesion_proprietaire_d_une_autre_organization_ne_donne_aucun_droit(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1797-ailleurs']);
        $etranger = User::factory()->complete()->create(['organization_id' => $autreOrg->id]);

        $autreService = new LoopService;
        $autreLoop = $autreService->createLoop($etranger, 'Boucle etrangere');
        // $etranger est bien OWNER, mais de $autreLoop — pas de $this->loop.

        app()->instance('current_organization', $this->org);

        Livewire::actingAs($etranger)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Bonjour @tous')
            ->call('sendMessage');

        $this->assertSame(0, $this->messagesPublies());
        $this->assertSame(0, LoopMessage::where('loop_id', $autreLoop->id)->count());
    }

    // =========================================================================
    // 8-10 — destinataires : actif notifie, auteur exclu
    // =========================================================================

    public function test_un_membre_actif_recoit_la_notification_attendue(): void
    {
        $this->envoyer($this->proprietaire, 'Bonjour @tous');

        $pourLeMembre = $this->notificationsDeLaMention()
            ->firstWhere('recipient_id', $this->membre->id);

        $this->assertNotNull($pourLeMembre);
        $this->assertSame($this->proprietaire->id, $pourLeMembre->actor_id);
        $this->assertSame(NotificationCatalogue::OBJECT_LOOP_MESSAGE, $pourLeMembre->object_type);
    }

    public function test_un_membre_sorti_de_la_boucle_est_exclu(): void
    {
        LoopMember::query()
            ->where('loop_id', $this->loop->id)
            ->where('user_id', $this->membre->id)
            ->update(['status' => 'left']);

        $this->envoyer($this->proprietaire, 'Bonjour @tous');

        $this->assertNull($this->notificationsDeLaMention()->firstWhere('recipient_id', $this->membre->id));
        // L'animateur, lui, reste actif et doit toujours etre notifie.
        $this->assertNotNull($this->notificationsDeLaMention()->firstWhere('recipient_id', $this->animateur->id));
    }

    public function test_l_auteur_ne_se_notifie_jamais_lui_meme(): void
    {
        $this->envoyer($this->proprietaire, 'Bonjour @tous');

        $this->assertNull($this->notificationsDeLaMention()->firstWhere('recipient_id', $this->proprietaire->id));
    }

    // =========================================================================
    // 11 — anti-doublon : `@tous` + mention individuelle, meme destinataire
    // =========================================================================

    public function test_tous_et_une_mention_individuelle_ne_dedoublent_pas_le_meme_destinataire(): void
    {
        $nomAnimateur = $this->animateur->fresh()->publicDisplayName();

        Livewire::actingAs($this->proprietaire)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Bonjour @tous, et surtout @'.$nomAnimateur)
            ->set('mentionsSelectionnees', [$this->animateur->id])
            ->call('sendMessage')
            ->assertHasNoErrors();

        $pourLAnimateur = $this->notificationsDeLaMention()->where('recipient_id', $this->animateur->id);

        $this->assertCount(1, $pourLAnimateur,
            'une seule notification pour l\'animateur, meme nomme deux fois dans le meme texte');
        // Et le membre, present seulement via `@tous`, est bien la aussi.
        $this->assertCount(1, $this->notificationsDeLaMention()->where('recipient_id', $this->membre->id));
    }

    // =========================================================================
    // 12 — 50 membres, pas de troncature, anomalie signalee au-dela
    // =========================================================================

    public function test_sous_le_maximum_documente_aucune_anomalie_n_est_signalee(): void
    {
        Log::spy();

        $this->envoyer($this->proprietaire, 'Bonjour @tous');

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Au-dela de 50 membres actifs (49 + l'auteur), AUCUNE troncature : tout
     * le monde est notifie, et l'anomalie est journalisee plutot que masquee.
     */
    public function test_au_dela_du_maximum_documente_personne_n_est_tronque_et_l_anomalie_est_signalee(): void
    {
        $effectif = [$this->proprietaire, $this->animateur, $this->membre];

        for ($i = 0; $i < 49; $i++) {
            $u = User::factory()->complete()->create(['organization_id' => $this->org->id]);
            (new LoopService)->addMemberByUserId($this->loop, $u->id);
            $effectif[] = $u;
        }

        // 3 (proprietaire + animateur + membre) + 49 = 52 actifs.
        $this->assertSame(52, LoopMember::where('loop_id', $this->loop->id)->where('status', 'active')->count());

        Log::spy();

        $this->envoyer($this->proprietaire, 'Bonjour @tous')->assertHasNoErrors();

        Log::shouldHaveReceived('warning')->with('loop.mention_all.over_capacity', \Mockery::any())->once();

        // 52 actifs, moins l'auteur : 51 destinataires, SANS troncature.
        $this->assertCount(51, $this->notificationsDeLaMention());
    }

    // =========================================================================
    // 13 — preferences email / web push respectees
    // =========================================================================

    public function test_un_destinataire_ayant_desactive_l_email_ne_recoit_pas_de_livraison_email(): void
    {
        $p = new MemberNotificationPreference([
            'notification_key' => NotificationCatalogue::LOOP_MENTION_CREATED,
            'channel' => NotificationCatalogue::CHANNEL_EMAIL,
            'enabled' => false,
        ]);
        $p->user_id = (string) $this->membre->id;
        $p->save();

        $this->envoyer($this->proprietaire, 'Bonjour @tous');

        $pourLeMembre = $this->notificationsDeLaMention()->firstWhere('recipient_id', $this->membre->id);
        $this->assertNotNull($pourLeMembre, 'l\'in-app, lui, reste obligatoire pour une mention');

        $this->assertSame(
            0,
            \App\Models\MemberNotificationDelivery::where('notification_id', $pourLeMembre->id)
                ->where('channel', NotificationCatalogue::CHANNEL_EMAIL)
                ->count(),
            'aucune livraison EMAIL ne doit etre planifiee pour ce destinataire',
        );

        // L'animateur, lui, n'a rien coupe : sa livraison email EST planifiee.
        $pourLAnimateur = $this->notificationsDeLaMention()->firstWhere('recipient_id', $this->animateur->id);
        $this->assertSame(
            1,
            \App\Models\MemberNotificationDelivery::where('notification_id', $pourLAnimateur->id)
                ->where('channel', NotificationCatalogue::CHANNEL_EMAIL)
                ->count(),
        );
    }

    // =========================================================================
    // 14 — le gabarit collectif, DISTINCT du gabarit individuel
    // =========================================================================

    /**
     * PREUVE que `@tous` ne se presente jamais comme une interpellation
     * nominative : le sujet dit « a mentionné TOUS LES MEMBRES », jamais
     * « vous a mentionné ».
     */
    public function test_le_gabarit_collectif_ne_dit_jamais_vous_a_mentionne(): void
    {
        $this->envoyer($this->proprietaire, 'Bonjour @tous');

        $notification = $this->notificationsDeLaMention()->firstWhere('recipient_id', $this->membre->id);

        $methode = (new \ReflectionClass(NotificationEmailDeliverer::class))->getMethod('rendreDepuisGabarit');
        $methode->setAccessible(true);

        $contenu = $methode->invoke(
            app(NotificationEmailDeliverer::class),
            $notification,
            'https://exemple.test/cible',
            'fr',
            $this->membre->fresh(),
        );

        $this->assertNotNull($contenu);
        $this->assertStringContainsString('tous les membres', $contenu['subject']);
        $this->assertStringNotContainsString('vous a mentionné', $contenu['subject']);
        $this->assertStringContainsString($this->loop->name, $contenu['subject']);
    }

    /** Et une mention INDIVIDUELLE garde son texte d'avant, inchange. */
    public function test_une_mention_individuelle_garde_son_gabarit_nominatif(): void
    {
        $nom = $this->membre->fresh()->publicDisplayName();

        Livewire::actingAs($this->proprietaire)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Salut @'.$nom)
            ->set('mentionsSelectionnees', [$this->membre->id])
            ->call('sendMessage');

        $notification = $this->notificationsDeLaMention()->firstWhere('recipient_id', $this->membre->id);

        $methode = (new \ReflectionClass(NotificationEmailDeliverer::class))->getMethod('rendreDepuisGabarit');
        $methode->setAccessible(true);

        $contenu = $methode->invoke(
            app(NotificationEmailDeliverer::class),
            $notification,
            'https://exemple.test/cible',
            'fr',
            $this->membre->fresh(),
        );

        $this->assertStringContainsString('vous a mentionné', $contenu['subject']);
    }

    // =========================================================================
    // 15-16 — idempotence et edition
    // =========================================================================

    public function test_un_rejeu_de_la_meme_mention_ne_cree_pas_de_seconde_notification(): void
    {
        $message = (new LoopMessageService)->sendUserMessage(
            $this->loop,
            $this->proprietaire,
            'Bonjour @tous',
            ['mention_all' => true],
            null,
            null,
            [$this->animateur->id, $this->membre->id],
        );

        $avant = $this->notificationsDeLaMention()->count();

        // Rejeu TECHNIQUE du meme fait, sur le MEME message : l'identifiant
        // d'evenement est deterministe (`identifiantEvenementMention`).
        $reflexion = new \ReflectionClass(LoopMessageService::class);
        $methode = $reflexion->getMethod('notifierLesMentions');
        $methode->setAccessible(true);
        $methode->invoke(new LoopMessageService, $this->loop, $this->proprietaire, $message, [$this->animateur->id, $this->membre->id]);

        $this->assertSame($avant, $this->notificationsDeLaMention()->count(),
            'le rejeu ne doit creer AUCUNE notification supplementaire');
    }

    public function test_editer_en_retirant_tous_ne_renotifie_personne_et_desactive_le_marqueur(): void
    {
        $this->envoyer($this->proprietaire, 'Bonjour @tous');
        $avant = $this->notificationsDeLaMention()->count();

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->firstOrFail();
        $this->assertTrue((bool) ($message->metadata['mention_all'] ?? false));

        Livewire::actingAs($this->proprietaire)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Bonjour tout le monde (corrige, sans la commande)')
            ->call('saveEdit')
            ->assertHasNoErrors('editingBody');

        $this->assertSame($avant, $this->notificationsDeLaMention()->count(),
            'retirer @tous ne doit RETIRER aucune notification deja partie');
        $this->assertFalse((bool) ($message->fresh()->metadata['mention_all'] ?? false),
            'le marqueur doit suivre le texte EDITE, pas le texte d\'origine');
    }

    public function test_editer_en_gardant_tous_ne_renvoie_pas_49_notifications_supplementaires(): void
    {
        $this->envoyer($this->proprietaire, 'Bonjour @tous');
        $avant = $this->notificationsDeLaMention()->count();

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->firstOrFail();

        Livewire::actingAs($this->proprietaire)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Bonjour @tous (faute corrigee)')
            ->call('saveEdit')
            ->assertHasNoErrors('editingBody');

        $this->assertSame($avant, $this->notificationsDeLaMention()->count(),
            'une mention collective INCHANGEE ne doit pas re-notifier qui que ce soit');
    }

    public function test_editer_pour_ajouter_tous_est_re_autorise_au_serveur(): void
    {
        $this->envoyer($this->membre, 'Un message tout a fait ordinaire');
        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->firstOrFail();

        // Le membre ordinaire tente d'AJOUTER la commande par une edition :
        // le refus doit etre le MEME qu'a l'envoi, et rien n'est sauvegarde.
        Livewire::actingAs($this->membre)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Un message tout a fait ordinaire, @tous regardez')
            ->call('saveEdit')
            ->assertHasErrors('editingBody');

        $this->assertStringNotContainsString('@tous', $message->fresh()->body);
        $this->assertCount(0, $this->notificationsDeLaMention());
    }

    // =========================================================================
    // 17 — composeur : l'option n'atteint que qui en a le droit
    // =========================================================================

    public function test_l_option_tous_n_est_rendue_que_pour_le_proprietaire_et_l_animateur(): void
    {
        $htmlProprietaire = Livewire::actingAs($this->proprietaire)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->html();
        $this->assertStringContainsString('data-mention-all-confirm', $htmlProprietaire);

        $htmlAnimateur = Livewire::actingAs($this->animateur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->html();
        $this->assertStringContainsString('data-mention-all-confirm', $htmlAnimateur);

        $htmlMembre = Livewire::actingAs($this->membre)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->html();
        $this->assertStringNotContainsString('data-mention-all-confirm', $htmlMembre,
            'un membre ordinaire ne doit jamais voir la confirmation collective, encore moins l\'option');
    }

    /** Le token `@tous` se met en valeur dans la bulle, exactement comme une mention nominative. */
    public function test_le_token_tous_est_mis_en_valeur_dans_la_bulle(): void
    {
        $this->envoyer($this->proprietaire, 'Bonjour @tous');

        $html = Livewire::actingAs($this->proprietaire)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->html();

        $this->assertMatchesRegularExpression(
            '#<strong><span class="bp-mention">@tous</span></strong>#',
            $html,
        );
    }

    // =========================================================================
    // Le catalogue du seeder et la migration jumelle portent le MEME texte
    // =========================================================================

    public function test_le_catalogue_du_seeder_et_la_migration_portent_le_meme_texte(): void
    {
        $definition = SystemEmailTemplateSeeder::definitions()['loop_mention_all_created'];

        $migration = file_get_contents(
            database_path('migrations/2026_11_09_170000_add_loop_mention_all_created_email_template.php')
        );

        foreach ([
            $definition['subject_fr'],
            $definition['subject_en'],
            $definition['content_html_fr'],
            $definition['content_html_en'],
        ] as $texte) {
            $this->assertStringContainsString(
                str_replace("'", "\\'", $texte),
                $migration,
                'la migration doit porter exactement le texte du catalogue',
            );
        }
    }
}
