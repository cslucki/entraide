<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1788, correctif 03 — le bandeau du tableau de bord.
 *
 * « Onboarding beta » devient « Parcours d'accueil » : BouclePro est une
 * plateforme d'entraide, pas un produit en rodage. Et la phrase « Votre
 * progression dans la boucle » disparait — le bandeau tient sur une ligne.
 *
 * ## Ce que ce fichier surveille vraiment
 *
 * Un changement de texte ne casse rien… sauf quand d'autres tests se servaient
 * de ce texte comme MARQUEUR. C'etait le cas ici : quatre assertions, dans deux
 * fichiers, cherchaient « Votre progression dans la boucle » pour savoir si le
 * panneau d'accueil etait rendu. Elles portent desormais sur
 * `data-onboarding-total`, qui ne depend d'aucun libelle.
 */
class TASK1788Correctif03ParcoursAccueilTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788-03',
            'is_active' => true,
            'is_public' => true,
            'locale' => 'fr',
            'ai_profiles_enabled' => false,
            'subscriptions_enabled' => false,
        ]);
        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();

        // `bio` vide : il reste un geste a faire, donc le panneau est rendu.
        // Sans cette precondition, toutes les assertions ci-dessous porteraient
        // sur une page qui n'affiche simplement pas le bandeau.
        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'bio' => null,
        ]);
    }

    private function page(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    public function test_le_bandeau_annonce_le_parcours_d_accueil(): void
    {
        $html = $this->page();

        // TEMOIN : le panneau est bien rendu.
        $this->assertStringContainsString('data-onboarding-total', $html,
            'Temoin absent : le panneau d\'accueil n\'est pas rendu, les assertions ne prouveraient rien.');

        // ⚠️ La forme ECHAPPEE : « Parcours d'accueil » porte une apostrophe,
        // que Blade rend `&#039;`. Chercher la forme brute echouerait ici — et,
        // sur une assertion d'ABSENCE, passerait a tort.
        $this->assertStringContainsString(e(__('dashboard.onboarding_badge')), $html,
            'Le bandeau n\'annonce pas « Parcours d\'accueil ».');

        $this->assertStringNotContainsString('Onboarding beta', $html, 'L\'ancien libelle est encore rendu.');
    }

    public function test_la_phrase_de_progression_ne_figure_plus(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-onboarding-total', $html,
            'Temoin absent : sans le panneau, l\'absence de la phrase ne prouve rien.');

        $phrase = __('dashboard.onboarding_title');

        $this->assertStringNotContainsString($phrase, $html, 'La phrase de progression est encore rendue.');
        $this->assertStringNotContainsString(e($phrase), $html, 'La phrase de progression est encore rendue (forme echappee).');
    }

    /**
     * La cle est CONSERVEE dans les deux langues.
     *
     * Plus personne ne l'emploie, mais la supprimer serait un menage de
     * lexique — et ce correctif est un changement de texte. Le meme arbitrage
     * qu'en T1773 pour `entraide.atelier.champ_individuel`.
     */
    public function test_la_cle_retiree_de_l_ecran_reste_definie_dans_les_deux_langues(): void
    {
        foreach (['fr', 'en'] as $langue) {
            app()->setLocale($langue);

            $this->assertNotSame('dashboard.onboarding_title', __('dashboard.onboarding_title'),
                "La cle `onboarding_title` n'est plus definie en {$langue} : c'est un menage, pas ce correctif.");
        }
    }

    public function test_le_libelle_anglais_suit(): void
    {
        $this->organization->update(['locale' => 'en']);
        $this->membre->update(['preferred_locale' => 'en']);

        $html = $this->page();

        $this->assertStringContainsString('data-onboarding-total', $html, 'Temoin absent : panneau non rendu en anglais.');
        $this->assertStringContainsString('Welcome journey', $html, 'Le libelle anglais n\'a pas suivi.');
        $this->assertStringNotContainsString('Beta onboarding', $html, 'L\'ancien libelle anglais est encore rendu.');
    }
}
