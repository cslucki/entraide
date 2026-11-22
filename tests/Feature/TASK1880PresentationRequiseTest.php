<?php

namespace Tests\Feature;

use App\Livewire\CreateFeedPost;
use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1880, correctif 03 (demande Cyril, 22/11) :
 * - a la connexion, un MEMBRE arrive sur le Flux (administrateur
 *   d'Organization et superadmin gardent leur tableau de bord) ;
 * - publier une annonce, rejoindre une Boucle et OUVRIR une Boucle dont on est
 *   membre exigent la presentation de profil — meme garde `profile.complete`
 *   que les demandes et propositions d'aide ;
 * - accepter une invitation reste immediat (arbitrage).
 */
class TASK1880PresentationRequiseTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'feed_post_publish_mode' => 'members']);
        app()->instance('current_organization', $this->org);
    }

    private function membre(bool $presentation = true): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'bio' => $presentation ? 'Je suis une personne qui se presente.' : null,
        ]);
    }

    private function boucle(?User $membre = null, string $visibility = 'public'): Loop
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'visibility' => $visibility]);

        if ($membre !== null) {
            LoopMember::factory()->create(['loop_id' => $boucle->id, 'user_id' => $membre->id]);
        }

        return $boucle;
    }

    private function profil(): string
    {
        return route('organization.profile.edit', ['organization' => $this->org->slug]);
    }

    public function test_un_membre_arrive_sur_le_flux_a_la_connexion(): void
    {
        $membre = $this->membre();

        $this->assertSame(
            route('organization.flux', ['organization' => $this->org->slug], absolute: false),
            $membre->getLoginRedirectTarget(),
        );
    }

    public function test_administrateur_et_superadmin_gardent_leur_tableau_de_bord(): void
    {
        $proprietaire = $this->membre();
        $this->org->update(['admin_id' => $proprietaire->id]);
        $superadmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $this->assertSame(
            route('organization.admin.dashboard', ['organization' => $this->org->slug], absolute: false),
            $proprietaire->fresh()->getLoginRedirectTarget(),
        );
        $this->assertSame(route('admin.dashboard', absolute: false), $superadmin->getLoginRedirectTarget());
    }

    public function test_publier_sans_presentation_renvoie_au_formulaire_de_profil(): void
    {
        $membre = $this->membre(presentation: false);

        $this->actingAs($membre)
            ->get(route('organization.flux.create', ['organization' => $this->org->slug]))
            ->assertRedirect($this->profil())
            ->assertSessionHas('profile_required', true);

        // Sur le Flux, l'invite « Quoi de neuf ? » mene a cette garde, sans composeur.
        $this->actingAs($membre)
            ->get(route('organization.flux', ['organization' => $this->org->slug]))
            ->assertOk()
            ->assertSee('data-feed-presentation-required', false)
            ->assertDontSee('data-feed-composer-sheet', false);
    }

    public function test_un_envoi_livewire_sans_presentation_ne_publie_rien(): void
    {
        $membre = $this->membre(presentation: false);
        $boucle = $this->boucle($membre);

        Livewire::actingAs($membre)->test(CreateFeedPost::class)
            ->set('selectedLoops', [(string) $boucle->id])
            ->set('content', '<p>Bonjour</p>')
            ->call('submit')
            ->assertRedirect(route('organization.flux.create', ['organization' => $this->org->slug]));

        $this->assertSame(0, FeedPost::count());
    }

    public function test_avec_presentation_le_composeur_est_present(): void
    {
        $this->actingAs($this->membre())
            ->get(route('organization.flux', ['organization' => $this->org->slug]))
            ->assertOk()
            ->assertSee('data-feed-composer-sheet', false)
            ->assertDontSee('data-feed-presentation-required', false);
    }

    public function test_rejoindre_une_boucle_sans_presentation_renvoie_au_formulaire(): void
    {
        $membre = $this->membre(presentation: false);
        $boucle = $this->boucle();

        $this->actingAs($membre)
            ->post(route('organization.loops.join', ['organization' => $this->org->slug, 'loop' => $boucle]))
            ->assertRedirect($this->profil());

        $this->assertFalse(LoopMember::where('loop_id', $boucle->id)->where('user_id', $membre->id)->exists());
        // Un POST n'est pas une intention rejouable apres connexion.
        $this->assertNull(session('url.intended'));

        $this->actingAs($membre)
            ->post(route('organization.loops.join-requests.store', ['organization' => $this->org->slug, 'loop' => $this->boucle(visibility: 'private')]))
            ->assertRedirect($this->profil());
    }

    public function test_ouvrir_une_boucle_dont_on_est_membre_sans_presentation_renvoie_au_formulaire(): void
    {
        $membre = $this->membre(presentation: false);
        $boucle = $this->boucle($membre);
        $url = route('organization.loops.show', ['organization' => $this->org->slug, 'loop' => $boucle]);

        $this->actingAs($membre)->get($url)
            ->assertRedirect($this->profil())
            ->assertSessionHas('profile_required', true);
        $this->assertSame($url, session('url.intended'));

        $membre->update(['bio' => 'Ma presentation est faite.']);
        $this->actingAs($membre->fresh())->get($url)->assertOk();
    }

    /** Correctif 04 (demande Cyril, 22/11) — rediger un article du blog exige aussi la presentation. */
    public function test_rediger_un_article_sans_presentation_renvoie_au_formulaire(): void
    {
        $membre = $this->membre(presentation: false);
        $slug = ['organization' => $this->org->slug];

        $this->actingAs($membre)->get(route('organization.blog.create', $slug))
            ->assertRedirect($this->profil())
            ->assertSessionHas('profile_required', true);
        $this->actingAs($membre)->post(route('organization.blog.store', $slug), ['title' => 'Titre'])
            ->assertRedirect($this->profil());
        $this->actingAs($membre)->post(route('organization.blog.create-draft', $slug), ['title' => 'Titre', 'summary' => 'Resume'])
            ->assertRedirect($this->profil());
        $this->assertSame(0, \App\Models\BlogPost::count());

        $this->actingAs($this->membre())->get(route('organization.blog.create', $slug))->assertOk();
    }
}
