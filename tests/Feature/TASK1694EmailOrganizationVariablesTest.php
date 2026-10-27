<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\EmailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug E — un email nomme l'ORGANIZATION, pas la marque.
 *
 * ## Le défaut, signalé en recette
 *
 * L'email d'accueil disait « Bienvenue sur Entraide » à quelqu'un qui venait de
 * rejoindre « Organismes de formation solidaires et humanistes ». La marque
 * n'est pas ce que la personne a rejoint.
 *
 * ## La racine : la ligne STOCKÉE, pas le code
 *
 * Le seeder définit pourtant « Bienvenue sur {{ organization }} ». C'est la
 * ligne globale en base qui portait la marque en dur. Et elle en portait
 * **deux** — « Entraide » dans le sujet, « BouclePro » dans le corps : chercher
 * la seule vue sur la capture aurait laissé l'autre.
 *
 * ## Les deux orthographes
 *
 * `organization` existait ; `organisation` et les deux `_slug` manquaient. Les
 * deux graphies sont acceptées **à dessein** : renommer casserait les gabarits
 * qui emploient déjà la forme anglaise, dont la définition du seeder. On
 * ajoute, on ne déplace pas.
 */
class TASK1694EmailOrganizationVariablesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694e',
            'name' => 'Organisation de recette 1694E',
            'is_active' => true,
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    private function rendu(string $gabarit): string
    {
        $emailer = app(EmailerService::class);

        return $emailer->interpolate($gabarit, $emailer->availableVariables($this->membre));
    }

    /** LE manque comblé : le nom et le slug, dans la graphie demandée. */
    public function test_the_french_spelling_renders_the_organization(): void
    {
        $this->assertSame(
            e($this->organization->name).' / '.$this->organization->slug,
            $this->rendu('{{ organisation }} / {{ organisation_slug }}'),
        );
    }

    /**
     * La graphie anglaise continue de rendre la même chose.
     *
     * Les gabarits existants l'emploient — dont la définition du seeder. La
     * remplacer les aurait cassés en silence.
     */
    public function test_the_english_spelling_still_works(): void
    {
        $this->assertSame(
            e($this->organization->name).' / '.$this->organization->slug,
            $this->rendu('{{ organization }} / {{ organization_slug }}'),
        );
    }

    /**
     * Une variable inconnue n'est pas rendue.
     *
     * L'interpolation est bornée par une liste blanche : sans cette mesure,
     * élargir la liste pourrait ouvrir plus que prévu.
     */
    public function test_an_unknown_variable_is_not_rendered(): void
    {
        $this->assertStringNotContainsString(
            $this->organization->slug,
            $this->rendu('{{ organisation_secrete }}'),
        );
    }

    /**
     * AUCUN gabarit ne nomme une marque.
     *
     * ## Ce que ce test peut mesurer, et ce qu'il ne peut pas
     *
     * La ligne GLOBALE fautive est une donnée **héritée** : elle n'existe que
     * sur les bases en service, pas sur une base neuve. La migration qui la
     * répare ne peut donc pas être mesurée ici — je le dis plutôt que
     * d'écrire un test qui passerait sur du vide.
     *
     * Ce qui EST mesurable, et qui garde l'avenir : aucune définition du
     * seeder ne doit nommer une marque. C'est elle qui peuple toute base
     * nouvelle, et c'est par elle qu'une marque en dur reviendrait.
     */
    public function test_no_seeded_template_names_a_brand(): void
    {
        $this->seed(\Database\Seeders\SystemEmailTemplateSeeder::class);

        $gabarits = SystemEmailTemplate::query()->get();

        $this->assertGreaterThan(0, $gabarits->count(), 'premisse : le seeder a bien ecrit');

        foreach ($gabarits as $gabarit) {
            foreach (['Entraide', 'BouclePro'] as $marque) {
                $this->assertStringNotContainsString($marque, (string) $gabarit->subject,
                    "le sujet de « {$gabarit->slug} » ne doit pas nommer « {$marque} »");
                $this->assertStringNotContainsString($marque, (string) $gabarit->content_html,
                    "le corps de « {$gabarit->slug} » ne doit pas nommer « {$marque} »");
            }
        }
    }

    /** Et « Bienvenue », une fois rendu, nomme l'Organization de la personne. */
    public function test_the_welcome_email_names_the_members_organization(): void
    {
        $this->seed(\Database\Seeders\SystemEmailTemplateSeeder::class);

        $gabarit = SystemEmailTemplate::query()
            ->where('slug', \App\Notifications\WelcomeNotification::SLUG)
            // TASK-1694 bug N — le seeder seme du GLOBAL.
            ->whereNull('organization_id')
            ->where('locale', 'fr')
            ->firstOrFail();

        $emailer = app(EmailerService::class);
        $vars = $emailer->availableVariables($this->membre);

        $this->assertStringContainsString(
            $this->organization->name,
            $emailer->interpolateSubject((string) $gabarit->subject, $vars),
        );
        $this->assertStringContainsString(
            e($this->organization->name),
            $emailer->interpolate((string) $gabarit->content_html, $vars, ['url']),
        );
    }
}
