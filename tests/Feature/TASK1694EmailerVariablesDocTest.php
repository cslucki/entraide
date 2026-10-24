<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug W — la documentation des variables, sur les DEUX écrans, et lue
 * à la source.
 *
 * ## La demande, et ce qu'elle a découvert
 *
 * Cyril : « il faudrait une documentation pour indiquer les variables
 * disponibles, sachant qu'on l'a sur `/admin/email-templates/create` ».
 *
 * Celle de la page de création était **fausse**. Elle annonçait `{{user_name}}`
 * et `{{app_name}}` — aucun des deux n'est dans
 * `EmailerService::ALLOWED_VARS`, et mesure du 24/10 :
 * `interpolate('Bonjour {{user_name}}')` rend `Bonjour {{user_name}}`,
 * **verbatim**. Quelqu'un qui suivait cette aide écrivait un email cassé.
 *
 * La recopier telle quelle sur la page d'édition aurait **doublé** le défaut.
 *
 * ## Pourquoi la liste est LUE et non recopiée
 *
 * Le bloc lit `ALLOWED_VARS`, la constante que l'interpolation consulte
 * elle-même. Une documentation écrite à la main dérive le jour où une variable
 * est ajoutée — c'est exactement ce qui est arrivé. Le test ci-dessous ferme la
 * dérive : il ne cite aucun nom de variable, il **itère sur la constante**.
 */
class TASK1694EmailerVariablesDocTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private EmailTemplate $gabarit;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create([
            'slug' => 'org-1694w',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->gabarit = EmailTemplate::factory()->create([
            'organization_id' => $organization->id,
        ]);
    }

    /**
     * Les deux ecrans, parcourus DANS chaque test.
     *
     * Pas un `dataProvider` : mesure faite, cette version de PHPUnit n'honore
     * pas l'annotation `@dataProvider` (il faudrait l'attribut), et un test qui
     * ne recoit pas son argument leve `ArgumentCountError` — rouge pour une
     * raison qui n'a rien a voir avec ce qu'il mesure.
     *
     * @return list<string>
     */
    private function ecrans(): array
    {
        return ['create', 'edit'];
    }

    private function html(string $ecran): string
    {
        $url = $ecran === 'create'
            ? route('admin.email-templates.create')
            : route('admin.email-templates.edit', $this->gabarit);

        return $this->actingAs($this->superAdmin)->get($url)->assertOk()->getContent();
    }

    /** LE geste demandé : la documentation est sur l'ÉDITION aussi. */
    public function test_both_screens_carry_the_documentation(): void
    {
        foreach ($this->ecrans() as $ecran) {
            $this->assertStringContainsString(
                'data-emailer-variables-doc',
                $this->html($ecran),
                "ecran « {$ecran} » sans documentation",
            );
        }
    }

    /**
     * Et elle liste CHAQUE variable que l'interpolation accepte.
     *
     * Le test n'écrit aucun nom : il itère sur `ALLOWED_VARS`. Ajouter demain
     * une variable au service sans l'exposer ici fera donc rougir ce test, au
     * lieu de laisser la documentation vieillir en silence.
     *
     */
    public function test_every_accepted_variable_is_documented(): void
    {
        $this->assertNotEmpty(EmailerService::ALLOWED_VARS);

        foreach ($this->ecrans() as $ecran) {
            $html = $this->html($ecran);

            foreach (EmailerService::ALLOWED_VARS as $variable) {
                $this->assertStringContainsString(
                    'data-emailer-variable="'.$variable.'"',
                    $html,
                    "« {$variable} » est acceptee par l'interpolation mais absente de l'ecran « {$ecran} »",
                );
            }
        }
    }

    /**
     * Les deux noms FANTÔMES ont disparu.
     *
     * Sans cette moitié, ajouter la vraie liste **à côté** de l'ancienne aide
     * suffirait à faire passer le test au-dessus, et la page continuerait de
     * conseiller deux variables qui ne marchent pas.
     *
     */
    public function test_the_two_phantom_variables_are_gone(): void
    {
        foreach ($this->ecrans() as $ecran) {
            $bloc = $this->blocDeDocumentation($this->html($ecran));

            foreach (['user_name', 'app_name'] as $fantome) {
                $this->assertStringNotContainsString(
                    $fantome,
                    $bloc,
                    "« {$fantome} » n'est pas interpolee : la documenter sur « {$ecran} » ferait ecrire un email casse",
                );
            }
        }
    }

    /**
     * Le BLOC de documentation seul, pas la page.
     *
     * Premiere version de l'assertion ci-dessus : « la page ne contient pas
     * `{{user_name}}` ». Rouge sur l'ecran d'edition — et a juste titre : la
     * `EmailTemplateFactory` pose `variables => ['{{user_name}}',
     * '{{app_name}}']`, et l'ecran affiche fidelement cette metadonnee du
     * gabarit dans son champ « Variables ».
     *
     * Deux choses au meme nom et deux roles opposes : ce que le produit
     * CONSEILLE, et ce qu'un gabarit CONTIENT. Mesurer sur la page entiere les
     * confondait. On ne mesure donc que ce dont on parle.
     *
     * (La factory est d'ailleurs une trace de plus que ces deux noms etaient
     * crus reels partout — elle n'est pas corrigee ici : c'est un fixture de
     * test, hors du geste demande, et `AdminEmailTemplatesTest` s'appuie sur
     * ses valeurs.)
     */
    private function blocDeDocumentation(string $html): string
    {
        $debut = strpos($html, 'data-emailer-variables-doc');

        $this->assertNotFalse($debut, 'le bloc de documentation doit etre present');

        $fin = strpos($html, '</div>', strpos($html, '</dl>', $debut));

        return substr($html, $debut, $fin - $debut);
    }

    /**
     * La preuve que ces deux-là ne marchent pas, mesurée et non supposée.
     *
     * C'est la raison d'être de ce correctif : sans cette mesure, retirer les
     * deux noms serait un choix de goût.
     */
    public function test_an_undocumented_variable_goes_out_verbatim(): void
    {
        $emailer = app(EmailerService::class);
        $vars = $emailer->availableVariables($this->superAdmin);

        $this->assertSame(
            'Bonjour {{user_name}} chez {{app_name}}',
            $emailer->interpolate('Bonjour {{user_name}} chez {{app_name}}', $vars),
        );

        $this->assertNotContains('user_name', EmailerService::ALLOWED_VARS);
        $this->assertNotContains('app_name', EmailerService::ALLOWED_VARS);
    }

    /**
     * Chaque variable porte un libellé traduit, dans les deux langues.
     *
     * Le bloc retombe sur le nom brut quand le libellé manque — mieux vaut un
     * nom seul qu'une variable invisible. Mais ce repli ne doit pas devenir
     * l'état normal : ce test garde la couverture.
     */
    public function test_every_variable_has_a_label_in_both_locales(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $traductions = require base_path("lang/{$locale}/admin.php");

            foreach (EmailerService::ALLOWED_VARS as $variable) {
                $this->assertArrayHasKey(
                    'emailer_var_'.$variable,
                    $traductions,
                    "libelle manquant pour « {$variable} » en {$locale}",
                );
            }
        }
    }
}
