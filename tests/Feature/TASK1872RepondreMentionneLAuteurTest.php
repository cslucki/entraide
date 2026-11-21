<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1872, correctif 06 — repondre a quelqu'un le PREVIENT.
 *
 * ## L'astuce, et pourquoi elle tient
 *
 * Cyril, 21/11 : « au lieu de creer un nouveau mecanisme de notification, on
 * va faire une astuce : quand on repond, mettre l'alias de l'utilisateur
 * automatiquement, exemple "@Cyril : je suis d'accord avec toi". Ce qui fait
 * que cela creera une notification mais avec le mecanisme existant. »
 *
 * Aucune seconde plomberie, donc : repondre ECRIT une mention, et c'est la
 * mention qui notifie. Ce fichier verifie les deux bouts — le texte pose, et
 * la notification qui en sort reellement.
 *
 * ## Le piege que ces tests gardent
 *
 * `mentionsRecadrees()` n'accepte une mention que si le corps contient
 * litteralement `'@'.publicDisplayName()`. Ecrire « @Cyril » quand la
 * personne s'affiche « Cyril Lucki » poserait un texte d'apparence juste et
 * SANS AUCUN EFFET : la notification ne partirait pas, et rien ne le dirait.
 * C'est pour cela que le test lit `publicDisplayName()` plutot que d'ecrire
 * un prenom en clair.
 */
class TASK1872RepondreMentionneLAuteurTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $repondeur;

    private User $tiers;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1872-rep']);
        $this->auteur = User::factory()->complete()->create([
            'organization_id' => $this->org->id, 'first_name' => 'Alice', 'name' => 'Martin',
        ]);
        $this->repondeur = User::factory()->complete()->create([
            'organization_id' => $this->org->id, 'first_name' => 'Bob', 'name' => 'Durand',
        ]);
        $this->tiers = User::factory()->complete()->create([
            'organization_id' => $this->org->id, 'first_name' => 'Chloe', 'name' => 'Nguyen',
        ]);

        app()->instance('current_organization', $this->org);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->auteur, 'Boucle des reponses');
        $service->addMemberByUserId($this->loop, $this->repondeur->id);
        $service->addMemberByUserId($this->loop, $this->tiers->id);
    }

    private function nom(User $u): string
    {
        return $u->fresh()->publicDisplayName();
    }

    private function messageDe(User $qui, string $corps = 'Le message de depart.'): LoopMessage
    {
        return app(LoopMessageService::class)->sendUserMessage($this->loop, $qui, $corps);
    }

    private function chat(User $qui)
    {
        return Livewire::actingAs($qui)->test(LoopChat::class, ['loop' => $this->loop]);
    }

    /* ─────────── LE TEXTE EST POSE, ET IL EST EXACT ─────────── */

    public function test_repondre_ecrit_la_mention_de_l_auteur_dans_le_composeur(): void
    {
        $message = $this->messageDe($this->auteur);

        $this->chat($this->repondeur)
            ->call('replyTo', $message->id)
            ->assertSet('body', '@'.$this->nom($this->auteur).' : ')
            ->assertSet('mentionsSelectionnees', [(string) $this->auteur->id]);
    }

    /**
     * Le nom pose est celui que la GARDE attend, pas un prenom.
     *
     * Sans cette assertion, « @Alice : » passerait le test precedent si on
     * l'avait ecrit en dur — et ne declencherait aucune notification.
     */
    public function test_le_nom_pose_est_celui_que_la_garde_des_mentions_exige(): void
    {
        $message = $this->messageDe($this->auteur);

        $corps = $this->chat($this->repondeur)->call('replyTo', $message->id)->get('body');

        $this->assertStringContainsString('@'.$this->nom($this->auteur), $corps);
        $this->assertStringContainsString(' ', trim($this->nom($this->auteur)),
            'Temoin : le nom affiche porte bien un prenom ET un nom, '
            .'sinon ce test ne distinguerait pas les deux formes.');
    }

    /* ─────────── ET LA NOTIFICATION PART VRAIMENT ─────────── */

    /**
     * Le bout du bout : repondre, envoyer, et l'auteur est prevenu — par le
     * mecanisme de mention existant, sans qu'une ligne de notification neuve
     * ait ete ecrite pour cette TASK.
     */
    public function test_repondre_puis_envoyer_notifie_l_auteur_du_message(): void
    {
        $message = $this->messageDe($this->auteur);

        $this->chat($this->repondeur)
            ->call('replyTo', $message->id)
            ->set('body', '@'.$this->nom($this->auteur).' : je suis d\'accord avec toi')
            ->call('sendMessage');

        $notifications = MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_MENTION_CREATED)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame($this->auteur->id, $notifications->first()->recipient_id);
        $this->assertSame($this->repondeur->id, $notifications->first()->actor_id);
    }

    /* ─────────── CE QUI NE DOIT JAMAIS ARRIVER ─────────── */

    /** On ne se notifie pas de sa propre reponse. */
    public function test_repondre_a_son_propre_message_ne_mentionne_personne(): void
    {
        $message = $this->messageDe($this->repondeur);

        $this->chat($this->repondeur)
            ->call('replyTo', $message->id)
            ->assertSet('body', '')
            ->assertSet('mentionsSelectionnees', []);
    }

    /**
     * UN BROUILLON N'EST JAMAIS ECRASE.
     *
     * C'est la garde la plus importante de ce correctif : une astuce qui
     * ferait disparaitre trois lignes deja ecrites serait bien pire que
     * l'absence de notification qu'elle corrige.
     */
    public function test_un_brouillon_deja_commence_n_est_pas_ecrase(): void
    {
        $message = $this->messageDe($this->auteur);

        $this->chat($this->repondeur)
            ->set('body', 'Je redigeais tranquillement.')
            ->call('replyTo', $message->id)
            ->assertSet('body', 'Je redigeais tranquillement.')
            ->assertSet('mentionsSelectionnees', []);
    }

    /**
     * Changer de cible DEPLACE la mention, il ne l'accumule pas.
     *
     * Sans le retrait de la precedente, repondre a Alice puis a Chloe aurait
     * notifie Alice — qui n'y est pour rien — et pose deux mentions dans un
     * message qui n'en veut qu'une.
     */
    public function test_changer_de_cible_remplace_la_mention_au_lieu_de_l_ajouter(): void
    {
        $premier = $this->messageDe($this->auteur);
        $second = $this->messageDe($this->tiers, 'Un autre message.');

        $this->chat($this->repondeur)
            ->call('replyTo', $premier->id)
            ->call('replyTo', $second->id)
            ->assertSet('body', '@'.$this->nom($this->tiers).' : ')
            ->assertSet('mentionsSelectionnees', [(string) $this->tiers->id]);
    }

    /** Annuler la reponse retire la mention — tant qu'elle est intacte. */
    public function test_annuler_la_reponse_retire_la_mention_restee_intacte(): void
    {
        $message = $this->messageDe($this->auteur);

        $this->chat($this->repondeur)
            ->call('replyTo', $message->id)
            ->call('cancelReply')
            ->assertSet('body', '')
            ->assertSet('mentionsSelectionnees', []);
    }

    /** Mais ce que la personne a ECRIT lui appartient, meme apres annulation. */
    public function test_annuler_la_reponse_ne_touche_pas_au_texte_ecrit(): void
    {
        $message = $this->messageDe($this->auteur);

        $this->chat($this->repondeur)
            ->call('replyTo', $message->id)
            ->set('body', '@'.$this->nom($this->auteur).' : finalement je dis autre chose')
            ->call('cancelReply')
            ->assertSet('body', '@'.$this->nom($this->auteur).' : finalement je dis autre chose');
    }

    /** Un compte desactive n'est plus mentionnable : pas de mention morte. */
    public function test_repondre_a_un_compte_desactive_ne_mentionne_personne(): void
    {
        $message = $this->messageDe($this->auteur);
        $this->auteur->update(['banned_at' => now()]);

        $this->chat($this->repondeur)
            ->call('replyTo', $message->id)
            ->assertSet('body', '')
            ->assertSet('mentionsSelectionnees', []);
    }
}
