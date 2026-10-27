<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1704 correctif 4 — le composeur se remet a plat apres un envoi.
 *
 * Cyril : « sur messagerie. Il y a un probleme supplementaire. Apres avoir
 * partage un lien, le composer chat reste agrandi. »
 *
 * ## Une seule cause, trois symptomes
 *
 * Le composeur partage ecoute `message-sent.window` pour recalculer sa hauteur
 * et son etat `hasText`. Le ChatLoop emet cet evenement ; la Messagerie ne
 * l'avait JAMAIS emis — d'ou un defaut visible ici et nulle part ailleurs.
 *
 * 1. La hauteur reste celle du texte envoye. Sur l'URL de Cyril, longue de
 *    plus de 200 caracteres, elle occupe la moitie de l'ecran.
 * 2. `hasText` reste VRAI : le bouton d'envoi garde son apparence active
 *    devant un champ vide.
 * 3. Ce bouton invite a un second appui, qui declenche « Ajoutez un message ou
 *    une image. » — l'erreur visible sous un champ vide sur la capture. La
 *    validation avait raison ; c'est le bouton qui mentait sur son etat.
 *
 * ## Les deux moities, et pourquoi aucune ne suffit
 *
 * Sans l'emission, l'ecouteur n'entend rien. Sans l'ecouteur, l'emission ne
 * fait rien. Les deux sont mesurees separement — c'est la lecon de TASK-1609
 * (definition contre usage), qui a deja produit un faux vert dans cette meme
 * TASK.
 */
class TASK1704ComposerResetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $acheteur;

    private Transaction $echange;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1704-c4', 'is_active' => true]);
        $this->acheteur = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 200]);
        $vendeur = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->echange = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->acheteur->id,
            'seller_id' => $vendeur->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);

        app()->instance('current_organization', $this->organization);
    }

    /** L'envoi est ANNONCE. */
    public function test_l_envoi_annonce_message_sent(): void
    {
        Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $this->echange])
            ->set('newMessage', 'https://www.lemonde.fr/un/article/tres/long/pour/agrandir/le/champ.html')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertDispatched('message-sent');
    }

    /** Et la propriete est bien remise a vide cote serveur. */
    public function test_le_champ_est_vide_apres_l_envoi(): void
    {
        Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $this->echange])
            ->set('newMessage', 'Un message.')
            ->call('sendMessage')
            ->assertSet('newMessage', '');
    }

    /**
     * Un envoi REFUSE n'annonce rien.
     *
     * Sans ce volet, une emission posee inconditionnellement en tete de
     * methode passerait le premier test tout en remettant le composeur a plat
     * alors que le message n'est pas parti — le membre croirait avoir envoye.
     */
    public function test_un_envoi_refuse_n_annonce_rien(): void
    {
        Livewire::actingAs($this->acheteur)
            ->test(MessageThread::class, ['transaction' => $this->echange])
            ->set('newMessage', '')
            ->call('sendMessage')
            ->assertHasErrors('newMessage')
            ->assertNotDispatched('message-sent');
    }

    /**
     * Le composeur ECOUTE cet evenement, et recalcule les deux choses.
     *
     * La garde porte sur le couple (hauteur, `hasText`) : ne recalculer que la
     * hauteur laisserait le bouton d'envoi actif devant un champ vide, donc
     * l'erreur residuelle de la capture.
     */
    public function test_le_composeur_ecoute_et_recalcule_les_deux_choses(): void
    {
        $source = file_get_contents(resource_path('views/components/conversation/composer.blade.php'));

        $this->assertSame(
            1,
            preg_match('/x-on:message-sent\.window="([^"]*)"/', $source, $m),
            'Le composeur n\'ecoute plus l\'annonce d\'envoi.'
        );

        $this->assertStringContainsString('resize()', $m[1], 'La hauteur n\'est pas recalculee.');
        $this->assertStringContainsString('hasText', $m[1], 'L\'etat du bouton d\'envoi n\'est pas recalcule.');
    }

    /**
     * Le ChatLoop continue d'annoncer le sien.
     *
     * C'est de lui que vient le mecanisme : si une retouche le lui retirait, le
     * defaut corrige ici reapparaitrait la-bas sans qu'aucune autre garde ne le
     * dise.
     */
    public function test_le_chatloop_annonce_toujours_le_sien(): void
    {
        $this->assertStringContainsString(
            "\$this->dispatch('message-sent');",
            file_get_contents(app_path('Livewire/LoopChat.php')),
            'Le ChatLoop n\'annonce plus ses envois.'
        );
    }
}
