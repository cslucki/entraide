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
     * TASK-1694 bug X — la forme À RECOPIER est affichée correctement.
     *
     * ## Ce que mes tests du bug W ne mesuraient pas
     *
     * Ils vérifiaient le marqueur `data-emailer-variable="first_name"` — un
     * attribut HTML. **Pas ce qu'un humain lit.** L'écran affichait en réalité
     * `{{ {{ $_var }} }}`, littéralement, sur les deux pages : `@` + accolades
     * échappe **toute** l'expression Blade jusqu'à son `}}`, pas seulement les
     * accolades d'ouverture.
     *
     * Cinq tests verts, et une aide inutilisable. Cyril l'a vue d'un coup d'œil
     * sur une capture d'écran.
     *
     * Ce test mesure donc le **texte rendu**, celui qu'on vient recopier.
     */
    public function test_each_variable_is_shown_in_its_copyable_form(): void
    {
        foreach ($this->ecrans() as $ecran) {
            $bloc = $this->blocDeDocumentation($this->html($ecran));

            foreach (EmailerService::ALLOWED_VARS as $variable) {
                $this->assertStringContainsString(
                    '{{ '.$variable.' }}',
                    $bloc,
                    "l'ecran « {$ecran} » doit montrer la forme a recopier de « {$variable} »",
                );
            }
        }
    }

    /**
     * Et aucune trace de Blade ne fuit dans ce que lit l'humain.
     *
     * Sans cette moitié, un rendu qui afficherait `{{ {{ $_var }} }}`
     * contiendrait quand même `{{ first_name }}` nulle part — mais un rendu
     * cassé d'une autre façon pourrait contenir les deux. On nomme donc le
     * défaut exact qu'on a eu.
     */
    public function test_no_blade_leftovers_are_shown(): void
    {
        foreach ($this->ecrans() as $ecran) {
            $bloc = $this->blocDeDocumentation($this->html($ecran));

            $this->assertStringNotContainsString('$_var', $bloc);
            $this->assertStringNotContainsString('{{ {{', $bloc);
        }
    }

    /**
     * TASK-1694 bug Y — la carte d'alias NE MENT PAS.
     *
     * C'est le test qui compte de ce correctif. Une carte fausse serait pire
     * que pas de carte : elle ferait écrire des gabarits sur une équivalence
     * qui n'existe pas. Chaque alias déclaré doit rendre **exactement** la même
     * valeur que son nom canonique.
     */
    public function test_every_declared_alias_really_yields_the_same_value(): void
    {
        $vars = app(EmailerService::class)->availableVariables($this->superAdmin);

        $this->assertNotEmpty(EmailerService::VAR_ALIASES);

        foreach (EmailerService::VAR_ALIASES as $alias => $canonique) {
            $this->assertArrayHasKey($alias, $vars);
            $this->assertArrayHasKey($canonique, $vars);
            $this->assertSame(
                $vars[$canonique],
                $vars[$alias],
                "« {$alias} » est annonce comme un alias de « {$canonique} », mais les valeurs different",
            );
        }
    }

    /**
     * Et un alias n'est jamais présenté comme sa propre chose.
     *
     * Le défaut signalé par Cyril : `organization` et `organisation` posés côte
     * à côte, sans rien qui dise qu'ils sont identiques. Devant ça, on hésite
     * — et hésiter devant une documentation, c'est qu'elle manque.
     */
    public function test_the_screens_say_which_variables_are_aliases(): void
    {
        foreach ($this->ecrans() as $ecran) {
            $bloc = $this->blocDeDocumentation($this->html($ecran));

            foreach (EmailerService::VAR_ALIASES as $alias => $canonique) {
                $this->assertStringContainsString(
                    'data-emailer-variable-alias="'.$alias.'"',
                    $bloc,
                    "« {$alias} » doit etre signale comme alias sur l'ecran « {$ecran} »",
                );
            }

            $this->assertStringContainsString(
                __('admin.emailer_var_alias_badge'),
                $bloc,
                'le libelle du badge doit etre rendu, pas seulement le marqueur',
            );
        }
    }

    /**
     * Les variables qui NE SONT PAS des alias n'en portent pas la marque.
     *
     * Sans cette moitié, marquer tout le monde « alias » passerait le test
     * précédent — et la documentation serait aussi trompeuse qu'avant, dans
     * l'autre sens.
     */
    public function test_a_canonical_variable_is_not_marked_as_an_alias(): void
    {
        $bloc = $this->blocDeDocumentation($this->html('create'));

        $canoniques = array_diff(
            EmailerService::ALLOWED_VARS,
            array_keys(EmailerService::VAR_ALIASES),
        );

        $this->assertNotEmpty($canoniques);

        foreach ($canoniques as $variable) {
            $this->assertStringNotContainsString(
                'data-emailer-variable-alias="'.$variable.'"',
                $bloc,
                "« {$variable} » n'est pas un alias : il ne doit pas en porter la marque",
            );
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
     * TASK-1694 bug AJ — plus aucune variable fantôme dans la FACTORY.
     *
     * Elle posait `['{{user_name}}', '{{app_name}}']` dans les métadonnées de
     * chaque gabarit fabriqué. Aucun effet en production — et c'est ce qui le
     * rendait facile à laisser. Mais tant que ces noms restent écrits quelque
     * part, quelqu'un les recopie : ils étaient dans l'aide, dans les tests et
     * dans la factory, comme s'ils étaient réels.
     *
     * Ils ont déjà coûté : au bug W, mon assertion les cherchait dans la page
     * et les **trouvait** — ils venaient du gabarit fabriqué ici, pas de la
     * documentation. Un faux rouge qui m'a fait chercher au mauvais endroit.
     *
     * ## `app_name` n'est PAS un fantôme partout
     *
     * Distinction mesurée le 24/10, et elle compte pour la suite :
     * `LoopInvitationMailer` et `OrganizationInvitationMailer` **fournissent**
     * `app_name` dans leurs variables supplémentaires. Il est donc **réel** pour
     * ces deux gabarits **système**, et le seeder a raison de l'employer.
     *
     * Il n'est fantôme que dans **l'Emailer**, dont les variables sont bornées
     * par `ALLOWED_VARS`. Deux contextes, deux listes — ne pas « corriger » le
     * seeder en croyant bien faire.
     */
    public function test_the_factory_only_uses_real_variables(): void
    {
        $variables = (array) \App\Models\EmailTemplate::factory()->make()->variables;

        $this->assertNotEmpty($variables);

        foreach ($variables as $variable) {
            $this->assertContains(
                $variable,
                EmailerService::ALLOWED_VARS,
                "la factory ne doit poser que des variables que l'interpolation accepte",
            );
        }
    }

    /**
     * Et elle les écrit NUES, sans accolades.
     *
     * Convention de la maison, relevée dans le catalogue du seeder
     * (`'variables' => ['name', 'url']`). La version précédente les entourait
     * d'accolades : troisième incohérence du même champ.
     */
    public function test_the_factory_writes_bare_names(): void
    {
        foreach ((array) \App\Models\EmailTemplate::factory()->make()->variables as $variable) {
            $this->assertStringNotContainsString('{{', (string) $variable);
        }
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
