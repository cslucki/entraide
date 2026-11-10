<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1801 correctif 03 — une mention ouvre la fiche de la personne.
 *
 * « Comme on vient de le faire quand on mentionne quelqu'un [correctif 02,
 * les liens], on va faire un lien qui affiche la card de l'utilisateur. Et
 * ce lien doit être surligné. »
 *
 * La mention porte desormais le MEME identifiant que l'avatar transmet deja a
 * `apercu-membre` (TASK-1772) : ce test ne re-teste pas `ouvrirApercuMembre`
 * (deja couvert ailleurs pour l'avatar) mais verifie que la mention porte le
 * BON identifiant, reellement accepte par cette porte, et que `@tous` n'en
 * porte aucun.
 */
class TASK1801LoopMentionProfileCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $cible;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->complete()->create(['organization_id' => $this->org->id, 'first_name' => 'Alice', 'name' => 'Martin']);
        $this->cible = User::factory()->complete()->create(['organization_id' => $this->org->id, 'first_name' => 'Bob', 'name' => 'Durand']);

        app()->instance('current_organization', $this->org);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->auteur, 'Boucle des mentions');
        $service->addMemberByUserId($this->loop, $this->cible->id);
    }

    private function nom(User $u): string
    {
        return $u->fresh()->publicDisplayName();
    }

    public function test_a_real_mention_carries_the_clickable_profile_attributes(): void
    {
        $component = Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Salut @'.$this->nom($this->cible).' tu dis quoi ?')
            ->set('mentionsSelectionnees', [$this->cible->id])
            ->call('sendMessage');

        $html = $component->html();

        $this->assertStringContainsString('data-mention-user-id="'.$this->cible->id.'"', $html);
        $this->assertStringContainsString("apercu-membre", $html);
        $this->assertMatchesRegularExpression(
            '#<span class="bp-mention"[^>]*data-mention-user-id="'.preg_quote((string) $this->cible->id, '#').'"[^>]*>@'
            .preg_quote($this->nom($this->cible), '#').'</span>#',
            $html,
        );

        // La meme porte que l'avatar : un identifiant que la mention affiche
        // doit reellement ouvrir la fiche, pas juste decorer le HTML.
        $component->call('ouvrirApercuMembre', (string) $this->cible->id);
        $this->assertSame((string) $this->cible->id, $component->get('apercuMembreId'));
    }

    public function test_the_broadcast_token_carries_no_profile_attribute(): void
    {
        // Le createur d'une Boucle en est proprietaire : seul role, avec
        // l'animateur, autorise a ecrire `@tous` (`calculerPeutMentionnerTous`).
        $component = Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Salut @tous, qui est dispo ?')
            ->set('mentionsSelectionnees', [])
            ->call('sendMessage');

        $html = $component->html();

        $this->assertStringContainsString('<span class="bp-mention">@tous</span>', $html);
        $this->assertStringNotContainsString('data-mention-user-id', $html);
    }

    public function test_a_fake_mention_carries_no_profile_attribute_either(): void
    {
        $component = Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Salut @PersonneInventee, ca va ?')
            ->set('mentionsSelectionnees', [])
            ->call('sendMessage');

        $html = $component->html();

        $this->assertStringNotContainsString('data-mention-user-id', $html);
    }
}
