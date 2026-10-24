<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug P — un gabarit administrable part HABILLÉ.
 *
 * ## La mesure qui a déclenché ce correctif
 *
 * `resources/views/emails/system-email.blade.php` était, en entier,
 * `{!! $html !!}`. Sur le même email, le 23/10 : **261 octets de HTML nu**
 * quand le gabarit vient de la base, contre **10 923 octets** de mise en page
 * quand c'est le repli Blade qui part. Ni `<style>`, ni structure en tableau.
 *
 * Ce n'était pas théorique : deux Organizations avaient des lignes **actives**,
 * et leurs emails partaient déjà nus.
 *
 * ## C'est la régression du 01/10, vue par son autre bout
 *
 * TASK-1681 l'avait nommée — « un gabarit semé en HTML brut qui prenait
 * immédiatement le pas sur le repli Blade stylé » — et s'en était protégée en
 * créant les surcharges **désactivées**. La cause n'était pas le gabarit :
 * c'était l'absence d'habillage autour. Protection conservée, cause traitée.
 *
 * ## Pourquoi c'est un préalable à la normalisation
 *
 * Sans ce correctif, activer les lignes Global (la cible du point 4)
 * déshabillerait **tous** les emails transactionnels d'un coup.
 */
class TASK1694SystemTemplateIsDressedTest extends TestCase
{
    use RefreshDatabase;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create([
            'slug' => 'org-1694p',
            'locale' => 'fr',
            'is_active' => true,
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $organization->id,
            'preferred_locale' => 'fr',
        ]);

        SystemEmailTemplate::query()->where('slug', WelcomeNotification::SLUG)->delete();

        SystemEmailTemplate::query()->create([
            'slug' => WelcomeNotification::SLUG,
            'locale' => 'fr',
            'organization_id' => null,
            'name' => 'Gabarit 1694P',
            'subject' => 'Sujet 1694P',
            'content_html' => '<h1>TEXTE_DU_GABARIT_1694P</h1><p>Bonjour {{ name }}</p>',
            'variables' => ['name'],
            'enabled' => true,
        ]);
    }

    private function rendu(): string
    {
        return (new WelcomeNotification)->toMail($this->membre)->render();
    }

    /** Le texte du gabarit part bien — l'habillage ne l'avale pas. */
    public function test_the_template_text_is_still_sent(): void
    {
        $this->assertStringContainsString('TEXTE_DU_GABARIT_1694P', $this->rendu());
    }

    /**
     * Et il part habillé.
     *
     * Mesuré sur ce qui fait qu'un email est lisible dans un client mail : des
     * styles incorporés et une structure en tableau. C'est précisément ce que
     * `->view()` ne déclenchait pas.
     */
    public function test_it_carries_the_mail_layout(): void
    {
        $rendu = $this->rendu();

        $this->assertStringContainsString('style=', $rendu);
        $this->assertStringContainsString('<table', $rendu);
    }

    /**
     * La borne qui empêche la régression de revenir.
     *
     * Un email nu faisait 261 octets. Le seuil est volontairement bas — il ne
     * mesure pas une taille « correcte », il distingue **habillé** de **nu**.
     */
    public function test_a_naked_email_would_be_an_order_of_magnitude_smaller(): void
    {
        $this->assertGreaterThan(2000, strlen($this->rendu()));
    }

    /**
     * Activer un gabarit ne change plus l'APPARENCE, seulement le texte.
     *
     * C'est l'invariant qui rend la normalisation sûre : les deux chemins
     * portent le même habillage.
     */
    public function test_enabling_a_template_no_longer_changes_the_dressing(): void
    {
        $avec = $this->rendu();

        SystemEmailTemplate::query()->update(['enabled' => false]);
        $sans = (new WelcomeNotification)->toMail($this->membre->fresh())->render();

        foreach (['<table', 'style='] as $marqueur) {
            $this->assertStringContainsString($marqueur, $avec);
            $this->assertStringContainsString($marqueur, $sans);
        }
    }
}
