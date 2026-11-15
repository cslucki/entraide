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
            'sender_id' => $this->membre->id,
            'body' => 'Version 1.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);

        // Premiere edition : le prochain poll doit deja la refleter.
        $message->update(['body' => 'Version 2.', 'edited_at' => now(), 'edited_by' => $this->autre->id]);
        $composant->call('pollChat');
        $empreinteApresV2 = $composant->get('pollFingerprint');
        $composant->assertSee('Version 2.');
        $edited_at_apres_v2 = DB::table('loop_messages')->where('id', $message->id)->value('edited_at');

        // Deuxieme edition. Le reste du test ne prouve le point souleve par
        // MASTER que si les deux editions tombent dans la MEME seconde
        // stockee — verifie explicitement plutot que suppose.
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
    }

    public function test_un_epinglage_relance_le_rendu_complet(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
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
            'sender_id' => $this->membre->id,
            'body' => 'Message stable.',
            'type' => 'user',
        ]);

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);
        $horodatageInitial = $composant->get('lastFullRenderAt');

        // Soupape reduite a 9s (revue MASTER du 15/11, trois cycles de poll).
        Carbon::setTestNow(now()->addSeconds(10));

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
}
