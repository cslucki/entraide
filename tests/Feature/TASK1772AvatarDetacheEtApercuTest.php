<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1772 (recette Cyril, 06/11) — l'avatar détaché et l'aperçu d'annuaire.
 *
 * « Je voudrais que cela ressemble plus à WhatsApp : que l'avatar du pseudo
 * soit détaché de la bulle de chat. Et que quand on clique sur cet avatar on
 * puisse avoir un pop-up, qui va permettre de voir un aperçu de sa card
 * d'annuaire. »
 *
 * Le pop-up est une surface de LECTURE sur des données de personne. Ce qui se
 * mesure ici n'est donc pas qu'il s'ouvre — c'est **ce qu'il refuse**.
 */
class TASK1772AvatarDetacheEtApercuTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $lecteur;

    private User $etranger;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1772-avatar']);
        $this->auteur = User::factory()->complete()->create(['organization_id' => $this->org->id]);
        $this->lecteur = User::factory()->complete()->create(['organization_id' => $this->org->id]);
        // Membre de l'Organization, ETRANGER a cette Boucle. C'est lui qui
        // mesure la quatrieme porte : l'annuaire le montre, ce fil non.
        $this->etranger = User::factory()->complete()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->auteur, 'Boucle des avatars');
        $service->addMemberByUserId($this->loop, $this->lecteur->id);

        Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Bonjour tout le monde')
            ->call('sendMessage');
    }

    private function fil(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->lecteur)->test(LoopChat::class, ['loop' => $this->loop]);
    }

    /**
     * L'avatar est SORTI de la bulle — et il n'y est pas resté en double.
     *
     * Les deux assertions comptent autant l'une que l'autre : déplacer sans
     * retirer donnerait deux pastilles sur la même rangée, ce qu'aucun test
     * de présence ne verrait.
     */
    public function test_l_avatar_est_detache_et_ne_reste_pas_dans_la_bulle(): void
    {
        $html = $this->fil()->html();

        $this->assertStringContainsString('data-member-avatar="'.$this->auteur->id.'"', $html,
            'La gouttiere d\'avatar n\'est pas rendue : le clic n\'a rien a ouvrir.');

        // L'auteur n'a qu'UN message : une seule pastille doit le designer.
        $this->assertSame(1, substr_count($html, 'data-member-avatar="'.$this->auteur->id.'"'),
            'L\'avatar apparait deux fois sur la meme rangee — deplacé sans etre retiré.');
    }

    /** Le cas nominal : un membre du fil ouvre la fiche d'un autre. */
    public function test_un_membre_du_fil_ouvre_l_apercu(): void
    {
        $composant = $this->fil()->call('ouvrirApercuMembre', $this->auteur->id);

        $composant->assertSet('apercuMembreId', (string) $this->auteur->id);
        $composant->assertSee($this->auteur->fresh()->publicDisplayName());
    }

    /**
     * LA GARDE QUI COMPTE : un identifiant forgé n'ouvre rien.
     *
     * La personne existe, elle est dans la même Organization, et l'annuaire la
     * montre à ce lecteur. Elle n'a pourtant rien à voir avec CE fil : une
     * surface ne doit pas offrir plus que ce qu'elle affiche, sans quoi elle
     * n'est plus vérifiable.
     */
    public function test_un_identifiant_etranger_au_fil_n_ouvre_rien(): void
    {
        $this->fil()
            ->call('ouvrirApercuMembre', $this->etranger->id)
            ->assertSet('apercuMembreId', null);
    }

    /** Qui ne lit pas ce fil n'ouvre aucune fiche depuis ce fil. */
    public function test_un_non_membre_n_ouvre_rien(): void
    {
        $dehors = User::factory()->complete()->create(['organization_id' => $this->org->id]);

        Livewire::actingAs($dehors)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('ouvrirApercuMembre', $this->auteur->id)
            ->assertSet('apercuMembreId', null);
    }

    /**
     * Le panneau se referme TOUT SEUL quand le droit disparaît.
     *
     * Entre l'ouverture et le `wire:poll.3s` suivant, la personne peut avoir
     * quitté la Boucle. Rien ne serait ré-vérifié si la fiche était stockée :
     * c'est pour cela que seul l'identifiant voyage, et que la lecture repasse
     * par les mêmes quatre portes à chaque rendu.
     */
    public function test_le_panneau_se_referme_si_la_personne_quitte_la_boucle(): void
    {
        $composant = $this->fil()->call('ouvrirApercuMembre', $this->auteur->id);
        $composant->assertSet('apercuMembreId', (string) $this->auteur->id);

        // L'auteur quitte la Boucle ET ses messages disparaissent : sans les
        // deux, il reste « lie au fil » par ce qu'il a ecrit.
        LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->auteur->id)
            ->update(['status' => 'removed']);
        \App\Models\LoopMessage::where('loop_id', $this->loop->id)
            ->where('sender_id', $this->auteur->id)->delete();

        $composant->call('$refresh')->assertSet('apercuMembreId', null);
    }
}
