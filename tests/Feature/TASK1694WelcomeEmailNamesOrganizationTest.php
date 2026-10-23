<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug G — l'email d'accueil nomme l'Organization, dans TOUS ses
 * chemins.
 *
 * ## Pourquoi le bug E n'avait pas suffi
 *
 * Le bug E avait corrigé la **ligne en base**. Mais la résolution ne regardait
 * que les lignes du **tenant**, puis retombait sur un gabarit **Blade** qui
 * codait « Entraide » en dur. Et OFSH n'a **aucune** ligne `welcome` — le
 * seeder a tourné avant sa création. C'est donc le Blade qui partait, et la
 * correction précédente portait sur un texte que personne ne lisait.
 *
 * ## Deux moitiés du même défaut
 *
 * 1. le **repli Blade** nommait la marque ;
 * 2. la ligne **globale** était **inatteignable** dès qu'une personne
 *    appartenait à une Organization — c'est-à-dire toujours.
 *
 * Un gabarit « Global » que l'administration affiche mais que le produit
 * n'emploie jamais est un mensonge d'interface. Les deux sont corrigées
 * ensemble parce qu'en corriger une seule laisserait le défaut visible.
 */
class TASK1694WelcomeEmailNamesOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694g',
            'name' => 'Organisation de recette 1694G',
            'locale' => 'fr',
            'is_active' => true,
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * LE cas de la recette : aucune ligne pour cette Organization.
     *
     * C'est exactement la situation d'OFSH, créée après le seeder.
     */
    public function test_without_any_template_the_email_still_names_the_organization(): void
    {
        SystemEmailTemplate::query()->where('slug', WelcomeNotification::SLUG)->delete();

        $message = (new WelcomeNotification)->toMail($this->membre);

        $this->assertStringContainsString($this->organization->name, $message->subject);
        $this->assertStringNotContainsString('Entraide', $message->subject);
    }

    /** Et le corps du repli aussi — pas seulement le sujet. */
    public function test_the_blade_fallback_body_names_the_organization(): void
    {
        SystemEmailTemplate::query()->where('slug', WelcomeNotification::SLUG)->delete();

        $rendu = (new WelcomeNotification)->toMail($this->membre)->render();

        $this->assertStringContainsString($this->organization->name, $rendu);
        $this->assertStringNotContainsString('Bienvenue sur Entraide', $rendu);
    }

    /**
     * La ligne GLOBALE est enfin atteignable.
     *
     * Avant, la résolution s'arrêtait aux lignes du tenant : une ligne globale
     * existait sans que personne ne puisse la lire.
     */
    public function test_a_global_template_is_reachable_for_a_member_of_an_organization(): void
    {
        SystemEmailTemplate::query()->where('slug', WelcomeNotification::SLUG)->delete();

        SystemEmailTemplate::query()->create([
            'slug' => WelcomeNotification::SLUG,
            'locale' => 'fr',
            'organization_id' => null,
            'name' => 'Globale 1694G',
            'subject' => 'SUJET_GLOBAL_1694G {{ organisation }}',
            'content_html' => '<p>{{ url }}</p>',
            'variables' => ['name', 'url'],
            'enabled' => true,
        ]);

        $message = (new WelcomeNotification)->toMail($this->membre);

        $this->assertStringContainsString('SUJET_GLOBAL_1694G', $message->subject);
        $this->assertStringContainsString($this->organization->name, $message->subject);
    }

    /**
     * Mais la ligne du TENANT garde la priorité.
     *
     * Une Organization qui a personnalisé son email doit garder sa version :
     * le global ne la recouvre jamais.
     */
    public function test_a_tenant_template_still_wins_over_the_global_one(): void
    {
        SystemEmailTemplate::query()->where('slug', WelcomeNotification::SLUG)->delete();

        foreach ([null, $this->organization->id] as $orgId) {
            SystemEmailTemplate::query()->create([
                'slug' => WelcomeNotification::SLUG,
                'locale' => 'fr',
                'organization_id' => $orgId,
                'name' => $orgId ? 'Tenant' : 'Globale',
                'subject' => $orgId ? 'SUJET_TENANT_1694G' : 'SUJET_GLOBAL_1694G',
                'content_html' => '<p>{{ url }}</p>',
                'variables' => ['name', 'url'],
                'enabled' => true,
            ]);
        }

        $this->assertSame('SUJET_TENANT_1694G', (new WelcomeNotification)->toMail($this->membre)->subject);
    }

    /** Et sans Organization du tout, l'email part quand même. */
    public function test_a_member_without_organization_still_gets_an_email(): void
    {
        SystemEmailTemplate::query()->where('slug', WelcomeNotification::SLUG)->delete();

        $orphelin = User::factory()->create(['organization_id' => null]);

        $this->assertNotSame('', trim((string) (new WelcomeNotification)->toMail($orphelin)->subject));
    }
}
