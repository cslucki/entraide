<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LoopChat;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1835 — `pollChat()` doit court-circuiter le rendu complet quand rien
 * n'a change dans le perimetre de la Boucle, et JAMAIS quand quelque chose a
 * change. Chaque test ci-dessous mute l'etat par un chemin DIFFERENT de
 * celui du composant qui sonde (comme le ferait une autre personne, dans une
 * autre requete), puis verifie que `pollChat()` le detecte.
 */
class TASK1835LoopChatPollFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $autre;

    private Loop $loop;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->autre = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->service = new LoopService;
        $this->loop = $this->service->createLoop($this->membre, 'TASK-1835 Boucle', type: 'project');
        $this->service->addMember($this->loop, $this->autre);

        app()->instance('current_organization', $this->organization);
    }

    public function test_un_cycle_de_poll_sans_rien_de_change_court_circuite_le_rendu_complet(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Un message avant la mesure.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $empreinteInitiale = $composant->get('pollFingerprint');
        $this->assertNotNull($empreinteInitiale);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $composant->call('pollChat');

        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        // Mesure de reference (audit du 15/11) : un rendu complet coute entre
        // 17 et 37 requetes SQL selon la fixture. L'empreinte seule (messages,
        // fenetre chargee, reactions, adhesion, memoire, permissions
        // globales) en coute une poignee : largement sous le seuil de 50%
        // vise.
        $this->assertLessThanOrEqual(10, count($requetes));
        $this->assertSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_un_nouveau_message_relance_le_rendu_complet_et_apparait(): void
    {
        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->autre->id,
            'body' => 'Message arrive pendant le cycle de poll.',
            'type' => 'user',
        ]);

        $composant->call('pollChat');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
        $composant->assertSee('Message arrive pendant le cycle de poll.');
    }

    public function test_une_edition_de_message_relance_le_rendu_complet(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Texte original.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        $message->update(['body' => 'Texte corrige.', 'edited_at' => now(), 'edited_by' => $this->autre->id]);

        $composant->call('pollChat');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
        $composant->assertSee('Texte corrige.');
    }

    /**
     * Revue MASTER du 15/11, point 1 — verifie directement sur PostgreSQL
     * (et SQLite) que `edited_at`/`updated_at` sont stockes IDENTIQUES a la
     * seconde pres pour deux ecritures successives (le format de date par
     * defaut d'Eloquent tronque les microsecondes AVANT l'ecriture, quel que
     * soit le moteur) : une empreinte qui ne s'appuierait que sur ces
     * horodatages ne verrait JAMAIS la deuxieme edition. Le hachage du
     * contenu reel de la fenetre chargee doit la detecter quand meme.
     */
    public function test_une_deuxieme_edition_dans_la_meme_seconde_relance_le_rendu_complet(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Version 1.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);

        // Horloge figee : sans ca, deux `now()` reels peuvent tomber de part
        // et d'autre d'une frontiere de seconde selon la vitesse du test
        // (observe en CI sur PostgreSQL) — ce qui prouverait le contraire de
        // ce que ce test demontre. Figer l'horloge rend le scenario
        // deterministe plutot que de simplement esperer une execution assez
        // rapide.
        Carbon::setTestNow(now());

        try {
            // Premiere edition : le prochain poll doit deja la refleter.
            $message->update(['body' => 'Version 2.', 'edited_at' => now(), 'edited_by' => $this->autre->id]);
            $composant->call('pollChat');
            $empreinteApresV2 = $composant->get('pollFingerprint');
            $composant->assertSee('Version 2.');
            $edited_at_apres_v2 = DB::table('loop_messages')->where('id', $message->id)->value('edited_at');

            // Deuxieme edition, horloge toujours figee sur la MEME seconde.
            // Verifie explicitement plutot que suppose.
            $message->update(['body' => 'Version 3.', 'edited_at' => now(), 'edited_by' => $this->membre->id]);
            $edited_at_apres_v3 = DB::table('loop_messages')->where('id', $message->id)->value('edited_at');
            $this->assertSame(
                $edited_at_apres_v2,
                $edited_at_apres_v3,
                'ce test suppose que les deux editions tombent dans la meme seconde stockee — sinon il ne demontre rien.'
            );

            $composant->call('pollChat');

            $this->assertNotSame($empreinteApresV2, $composant->get('pollFingerprint'));
            $composant->assertSee('Version 3.');
            $composant->assertDontSee('Version 2.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_un_epinglage_relance_le_rendu_complet(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Message a epingler.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        $message->pin($this->autre);

        $composant->call('pollChat');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_une_suppression_de_message_relance_le_rendu_complet(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Message a supprimer.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        $message->update(['deleted_at' => now(), 'deleted_by' => $this->autre->id]);

        $composant->call('pollChat');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_une_reaction_ajoutee_relance_le_rendu_complet(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Message reagi.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        Reaction::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->autre->id,
            'reactionable_id' => $message->id,
            'reactionable_type' => LoopMessage::class,
            'reaction_type' => 'thumbs_up',
        ]);

        $composant->call('pollChat');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_une_reaction_retiree_relance_le_rendu_complet(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Message avec reaction a retirer.',
            'type' => 'user',
        ]);

        $reaction = Reaction::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->autre->id,
            'reactionable_id' => $message->id,
            'reactionable_type' => LoopMessage::class,
            'reaction_type' => 'thumbs_up',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        // Suppression DURE, sans colonne `deleted_at` (verifie dans la
        // migration) : c'est le cas que `MAX` seul raterait sans `COUNT`.
        $reaction->delete();

        $composant->call('pollChat');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_un_retrait_de_membre_fait_tomber_l_adhesion_et_rend_normalement(): void
    {
        $composant = Livewire::actingAs($this->autre)->test(LoopChat::class, ['loop' => $this->loop]);
        $this->assertTrue($composant->get('isMember'));

        $laBoucleMembre = LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $this->autre->id)
            ->first();
        $this->service->removeMember($laBoucleMembre);

        // `booted()` recalcule `isMember` a CHAQUE requete, avant `pollChat()`
        // lui-meme (TASK-1772) : le garde en tete de methode rend donc la main
        // sans jamais appeler `skipRender()`, et le rendu normal s'execute.
        $composant->call('pollChat');

        $this->assertFalse($composant->get('isMember'));
    }

    public function test_une_promotion_de_role_relance_le_rendu_complet(): void
    {
        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $this->membre->id)
            ->first()
            ->update(['role' => 'moderator']);

        $composant->call('pollChat');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_une_note_de_memoire_derivee_relance_le_rendu_complet(): void
    {
        $dossier = Dossier::factory()->create(['organization_id' => $this->organization->id]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        DerivedKnowledgeNote::create([
            'organization_id' => $this->organization->id,
            'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
            'kind' => DerivedKnowledgeNote::KIND_DIGEST,
            'source_loop_id' => $this->loop->id,
            'dossier_id' => $dossier->id,
            'subject_key' => DerivedKnowledgeNote::SUBJECT_DIGEST,
            'content' => 'Resume derive pour la mesure TASK-1835.',
            'source_fingerprint' => str_repeat('a', 64),
            'provenance' => [],
            'observed_at' => now(),
            'derived_at' => now(),
        ]);

        $composant->call('pollChat');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    /**
     * Revue MASTER du 15/11, point 2 — un reglage de permission GLOBAL
     * (super-admin, sans `loop_id` : voir la migration) ne doit pas attendre
     * la soupape de 9s pour se repercuter.
     */
    public function test_un_changement_de_reglage_de_permission_global_relance_le_rendu_complet(): void
    {
        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        \App\Models\LoopPermissionSetting::create([
            'loop_type' => 'project',
            'loop_role' => 'moderator',
            'permission' => 'delete_message',
            'allowed' => false,
        ]);

        $composant->call('pollChat');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_la_soupape_periodique_force_un_rendu_complet_meme_sans_rien_de_change(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Message stable.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $horodatageInitial = $composant->get('lastFullRenderAt');

        // Soupape reportee a 30s (dernier arbitrage MASTER du 15/11) : son
        // seul role restant est un filet de securite generique, la
        // protection specifique aux cartes projetees etant geree en amont
        // par `fenetreReferenceUnContenuProjete()`.
        Carbon::setTestNow(now()->addSeconds(31));

        try {
            $composant->call('pollChat');

            $this->assertGreaterThan($horodatageInitial, $composant->get('lastFullRenderAt'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_la_soupape_periodique_ne_se_declenche_pas_trop_tot(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Message stable.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $horodatageInitial = $composant->get('lastFullRenderAt');

        Carbon::setTestNow(now()->addSeconds(5));

        try {
            $composant->call('pollChat');

            $this->assertSame($horodatageInitial, $composant->get('lastFullRenderAt'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_un_non_membre_ne_court_circuite_jamais_et_ne_leve_aucune_erreur(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        $composant = Livewire::actingAs($etranger)->test(LoopChat::class, ['loop' => $this->loop]);
        $this->assertFalse($composant->get('isMember'));
        $this->assertNull($composant->get('pollFingerprint'));

        $composant->call('pollChat');

        $this->assertFalse($composant->get('isMember'));
    }

    /**
     * Dernier arbitrage MASTER du 15/11 — optimisation CONDITIONNELLE plutot
     * qu'une soupape uniforme : tant que la fenetre chargee porte une carte
     * projetee externe (T1826), `pollChat()` ne doit JAMAIS court-circuiter,
     * meme quand rien d'autre n'a change — sa visibilite peut changer
     * independamment de la Boucle (permission externe revoquee).
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function provideContenuProjete(): array
    {
        return [
            'service_request' => ['help_request', [
                'projection_type' => 'service_request',
                'service_request_id' => '11111111-1111-1111-1111-111111111111',
            ]],
            'article' => ['blog_post', ['blog_post_id' => '22222222-2222-2222-2222-222222222222']],
            'fichier_dossier' => ['dossier_file', ['file_ids' => ['33333333-3333-3333-3333-333333333333']]],
            'feuille_de_route' => ['roadmap_action', ['roadmap_item_id' => '44444444-4444-4444-4444-444444444444']],
        ];
    }

    #[DataProvider('provideContenuProjete')]
    public function test_une_fenetre_avec_contenu_projete_ne_court_circuite_jamais(string $type, array $metadata): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Message porteur d une carte projetee.',
            'type' => $type,
            'metadata' => $metadata,
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);

        // Rien ne change entre le montage et ce poll : une conversation
        // ORDINAIRE court-circuiterait ici (voir les tests precedents).
        DB::flushQueryLog();
        DB::enableQueryLog();
        $composant->call('pollChat');
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        // Preuve qu'AUCUN court-circuit n'a eu lieu : le volume de requetes
        // reste celui d'un rendu complet (bien au-dessus du seuil mesure
        // pour un cycle inchange sur une conversation ordinaire), jamais
        // celui, minuscule, d'un simple calcul d'empreinte.
        $this->assertGreaterThan(10, count($requetes));
    }

    public function test_une_fenetre_sans_contenu_projete_continue_de_court_circuiter(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Message ordinaire, sans aucune carte projetee.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $composant->call('pollChat');
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, count($requetes));
    }

    /**
     * Dernier arbitrage MASTER du 15/11 — « reutiliser les informations deja
     * chargees pour eviter une requete couteuse supplementaire » : sur un
     * cycle INCHANGE (aucun rendu complet, donc la seule source possible de
     * ce motif de requete est {@see LoopChat::loadedWindowMessages()}), la
     * fenetre chargee ne doit etre requetee qu'UNE SEULE fois, partagee entre
     * la detection de contenu projete et le calcul de l'empreinte.
     */
    public function test_la_fenetre_chargee_n_est_requetee_qu_une_seule_fois_par_cycle_inchange(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Message ordinaire, rien ne change ensuite.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $composant->call('pollChat');
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $requetesFenetre = array_filter(
            $requetes,
            fn (array $r) => str_contains($r['query'], 'loop_messages')
                && str_contains($r['query'], '"id" in (')
        );

        $this->assertCount(
            1,
            $requetesFenetre,
            'la fenetre chargee doit etre requetee une seule fois, partagee entre la detection de contenu projete et l\'empreinte.'
        );
    }

    /**
     * Revue MASTER du 15/11 (correctif securite) — `loadedWindowMessages()`
     * doit filtrer EXPLICITEMENT par `loop_id` ET `organization_id`, jamais
     * se fier au seul contenu de `loadedMessageIds`. Ce test injecte
     * directement un identifiant HORS PERIMETRE (autre Boucle, autre
     * Organization) dans cette propriete publique — non verrouillee car elle
     * doit rester modifiable par les actions legitimes du composant — pour
     * simuler un snapshot falsifie ou un bug futur ailleurs dans le code, et
     * verifie qu'aucun contenu ni aucune detection de carte projetee n'en
     * resulte.
     */
    public function test_un_identifiant_hors_perimetre_injecte_dans_loaded_message_ids_ne_contribue_a_rien(): void
    {
        $autreOrganisation = Organization::factory()->create();
        $autreUtilisateur = User::factory()->create(['organization_id' => $autreOrganisation->id]);
        $autreBoucle = (new LoopService)->createLoop($autreUtilisateur, 'Boucle etrangere', type: 'project');

        // `type: 'blog_post'` deliberement : si la scope manquait, ce message
        // ferait a la fois fuiter son contenu dans l'empreinte ET declencher
        // a tort le mode « contenu projete » (rendu complet permanent) pour
        // la Boucle LEGITIME ci-dessous.
        $messageEtranger = LoopMessage::create([
            'loop_id' => $autreBoucle->id,
            'organization_id' => $autreOrganisation->id,
            'sender_id' => $autreUtilisateur->id,
            'body' => 'CONTENU-SECRET-HORS-PERIMETRE',
            'type' => 'blog_post',
            'metadata' => ['blog_post_id' => (string) \Illuminate\Support\Str::uuid()],
        ]);

        $messageLocal = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->membre->id,
            'body' => 'Message local ordinaire.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);

        // Injection adversariale, PAS une action legitime du composant.
        $composant->set('loadedMessageIds', [$messageLocal->id, $messageEtranger->id]);
        $composant->assertDontSee('CONTENU-SECRET-HORS-PERIMETRE');

        $composant->call('pollChat');
        $composant->assertDontSee('CONTENU-SECRET-HORS-PERIMETRE');

        // Rien ne change ensuite : si le message etranger (`type: 'blog_post'`)
        // avait ete pris en compte par la scope manquante, AUCUN poll ne
        // pourrait plus jamais court-circuiter pour ce composant (mode
        // « contenu projete » permanent, par construction). Prouve que la
        // scope exclut bien ce message de la detection.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $composant->call('pollChat');
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, count($requetes));
        $composant->assertDontSee('CONTENU-SECRET-HORS-PERIMETRE');
    }
}
