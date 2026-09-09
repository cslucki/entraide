<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\UsageReference;
use App\Models\User;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellUsageReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1477 — le Shell MEMBRE consomme enfin le Referentiel d'utilisation.
 *
 * ## Le symptome, et la cause
 *
 * Le Shell nommait correctement la surface — « Vous etes sur l'agenda » — puis
 * enchainait partout sur la meme phrase : « Cette page n'a pas d'action IA qui
 * lui soit propre. »
 *
 * La phrase n'etait pas fausse. Elle etait la SEULE chose disponible, parce que
 * la couche qui explique un lieu n'avait **aucun consommateur cote membre** :
 * `GuestPublicContextBuilder` etait le seul appelant de
 * `UsageReferenceResolver`. TASK-1473 l'avait deja constate.
 *
 * Corriger le wording aurait masque la cause. On branche la couche.
 *
 * ## Les quatre couches restent quatre
 *
 * | Couche | Question | Autorite |
 * |---|---|---|
 * | PageContext | ou suis-je ? | `AiShellPageContext` |
 * | UsageReference | a quoi sert cet endroit ? | `UsageReferenceResolver` |
 * | Runtime | que puis-je faire ici ? | `AiFabContext` |
 * | Constitution | comment l'IA se comporte ? | inchangee |
 *
 * Ce fichier mesure qu'elles ne fusionnent pas — en particulier qu'une
 * UsageReference n'ajoute AUCUNE action.
 *
 * ## Le verrou qui compte
 *
 * `UsageReference::SURFACES` porte desormais les surfaces publiques ET membre.
 * Sans garde, une cle homonyme servirait au membre l'aide ecrite pour un
 * visiteur — exactement le risque que TASK-1473 avait desamorce. Le lecteur
 * membre n'accepte donc que `SURFACES_MEMBER`, et la section D le sabote.
 */
class TASK1477MemberShellUsageReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'slug' => 'org-usage-ref',
            'name' => 'Org Usage Ref',
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        $this->platformAdmin = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        app()->instance('current_organization', $this->organization);

        config(['ai.fab.enabled' => true, 'ai.shell.enabled' => true]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Les deux tables de surfaces parlent le meme langage
    // =====================================================================

    /**
     * Deux vocabulaires pour le meme lieu recreeraient l'homonymie que
     * TASK-1473 a du defaire. Les cles membre du referentiel sont donc
     * exactement des cles de `AiShellPageContext`.
     */
    public function test_member_surface_keys_come_from_the_page_context_authority(): void
    {
        foreach (UsageReference::SURFACES_MEMBER as $surface) {
            $this->assertArrayHasKey(
                $surface,
                AiShellPageContext::SURFACE_ROUTES,
                "[{$surface}] doit etre une surface que PageContext sait nommer",
            );
        }
    }

    /**
     * `organization_home` reste PUBLIC des deux cotes. Le tableau de bord porte
     * `dashboard` — c'est tout l'objet de TASK-1473, et l'elargissement ne doit
     * pas le defaire.
     */
    public function test_the_public_home_key_never_becomes_a_member_surface(): void
    {
        $this->assertNotContains(UsageReference::SURFACE_ORGANIZATION_HOME, UsageReference::SURFACES_MEMBER);
        $this->assertContains(UsageReference::SURFACE_ORGANIZATION_HOME, UsageReference::SURFACES_PUBLIC);
        $this->assertSame([], array_intersect(UsageReference::SURFACES_PUBLIC, UsageReference::SURFACES_MEMBER),
            'aucune cle ne peut etre a la fois publique et membre');
    }

    /** Un objet precis n'a pas de mode d'emploi : un LIEU en a un. */
    public function test_single_object_surfaces_are_not_declared(): void
    {
        foreach (['dossier', 'article'] as $objectSurface) {
            $this->assertArrayHasKey($objectSurface, AiShellPageContext::SURFACE_ROUTES);
            $this->assertNotContains($objectSurface, UsageReference::SURFACES);
        }
    }

    /** Chaque surface declaree porte un libelle d'administration, FR et EN. */
    public function test_every_declared_surface_has_an_admin_label(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (UsageReference::SURFACES as $surface) {
                $key = 'admin.usage_reference_surface_'.$surface;
                $this->assertNotSame($key, __($key), "[{$locale}] {$surface} : libelle manquant");
            }
        }

        app()->setLocale('fr');
    }

    // =====================================================================
    // B. A l'ecran : la surface est EXPLIQUEE, plus niee
    // =====================================================================

    public function test_the_agenda_is_explained_by_its_published_reference(): void
    {
        $this->publish('agenda', 'fr', 'L\'agenda', 'Vous retrouvez ici les rencontres de vos Boucles.');

        $html = $this->visit('organization.events.agenda');

        $this->assertStringContainsString('data-ai-shell-surface="agenda"', $html);
        $this->assertStringContainsString('data-ai-shell-usage-reference="agenda"', $html);
        $this->assertStringContainsString(e('Vous retrouvez ici les rencontres de vos Boucles.'), $html);

        // Et la negation a disparu du panneau.
        $this->assertStringNotContainsString(e(__('ai.fab_no_page_action')), $html);
    }

    public function test_the_directory_and_the_exchanges_too(): void
    {
        $this->publish('directory', 'fr', 'L\'annuaire', 'Parcourez les membres de votre Organization.');
        $this->publish('exchanges', 'fr', 'Les echanges', 'Consultez les demandes et propositions d\'aide accessibles.');

        $directory = $this->visit('organization.members.index');
        $this->assertStringContainsString('data-ai-shell-usage-reference="directory"', $directory);
        $this->assertStringContainsString(e('Parcourez les membres de votre Organization.'), $directory);

        $exchanges = $this->visit('organization.explorer');
        $this->assertStringContainsString('data-ai-shell-usage-reference="exchanges"', $exchanges);
        $this->assertStringContainsString(e('Consultez les demandes et propositions d\'aide accessibles.'), $exchanges);
    }

    /**
     * Le repere est rendu dans le panneau du FAB aussi — surface qui, depuis
     * TASK-1478, n'existe plus que lorsqu'aucun Shell n'est actif. Ce chemin
     * doit rester juste : c'est la seule surface de cette configuration.
     */
    public function test_the_fab_panel_shows_the_reference_when_no_shell_exists(): void
    {
        config(['ai.shell.enabled' => false]);

        $this->publish('agenda', 'fr', 'L\'agenda', 'Les rencontres de vos Boucles.');

        $html = $this->visit('organization.events.agenda');

        $this->assertStringContainsString('data-ai-fab-panel', $html);
        $this->assertStringContainsString('data-ai-fab-usage-reference="agenda"', $html);
        $this->assertStringContainsString(e('Les rencontres de vos Boucles.'), $html);
    }

    // =====================================================================
    // C. Le repli ne ment pas, et n'ouvre plus par une negation
    // =====================================================================

    /**
     * Sans repere publie, le panneau ne dit plus ce que la page N'A PAS. Il dit
     * ce que le Shell peut reellement faire — et il le peut : la conversation
     * suit la navigation, c'est le comportement mesure depuis TASK-1315.
     */
    /**
     * Sans repere publie, le repli neutre est rendu — dans le SHELL depuis
     * TASK-1478, qui a supprime l'etape intermediaire ou il vivait. Meme
     * phrase, meme verite, nouveau domicile.
     */
    public function test_without_a_reference_the_fallback_is_neutral_and_true(): void
    {
        $html = $this->visit('organization.events.agenda');

        $this->assertStringNotContainsString('data-ai-shell-usage-reference=', $html, 'aucun bloc vide');
        $this->assertStringContainsString('data-ai-shell-page-help', $html);
        $this->assertStringContainsString(e(__('ai.fab_page_help')), $html);
    }

    /** Et les deux ne coexistent jamais : un repere REMPLACE le repli. */
    public function test_a_reference_replaces_the_fallback(): void
    {
        $this->publish('agenda', 'fr', 'L\'agenda', 'Les rencontres de vos Boucles.');

        $html = $this->visit('organization.events.agenda');

        $this->assertStringContainsString('data-ai-shell-usage-reference="agenda"', $html);
        $this->assertStringNotContainsString('data-ai-shell-page-help', $html);
    }

    /** Et la phrase neutre n'ouvre par aucune negation, dans les deux langues. */
    public function test_the_neutral_fallback_opens_on_what_is_possible(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            $label = __('ai.fab_page_help');

            $this->assertNotSame('ai.fab_page_help', $label, $locale);
            $this->assertDoesNotMatchRegularExpression(
                '/^(cette page n\'a pas|this page has no|il n\'y a (pas|aucune)|there is no)/iu',
                $label,
                "[{$locale}] : le repli n'ouvre pas sur une absence",
            );
        }

        app()->setLocale('fr');
    }

    // =====================================================================
    // D. Les sabotages exiges : rien ne doit passer par accident
    // =====================================================================

    /** Sabotage : une reference en BROUILLON n'est pas publiee — elle ne sort pas. */
    public function test_a_draft_reference_never_reaches_the_shell(): void
    {
        app(\App\Services\UsageReference\UsageReferenceService::class)
            ->createDraft('agenda', 'fr', 'Brouillon', 'Texte-non-publie-TASK1477', $this->platformAdmin);

        $html = $this->visit('organization.events.agenda');

        $this->assertStringNotContainsString('Texte-non-publie-TASK1477', $html);
        $this->assertStringNotContainsString('data-ai-shell-usage-reference=', $html);
    }

    /** Sabotage : une reference RETIREE ne revient pas. */
    public function test_a_retired_reference_never_comes_back(): void
    {
        $reference = $this->publish('agenda', 'fr', 'L\'agenda', 'Texte-retire-TASK1477');
        app(\App\Services\UsageReference\UsageReferenceService::class)->retire($reference, $this->platformAdmin);

        $html = $this->visit('organization.events.agenda');

        $this->assertStringNotContainsString('Texte-retire-TASK1477', $html);
    }

    /** Sabotage : la reference d'une AUTRE surface ne franchit pas. */
    public function test_the_reference_of_another_surface_never_crosses(): void
    {
        $this->publish('directory', 'fr', 'L\'annuaire', 'Texte-annuaire-TASK1477');

        $html = $this->visit('organization.events.agenda');

        $this->assertStringNotContainsString('Texte-annuaire-TASK1477', $html);
    }

    /**
     * L'aide ecrite pour l'ACCUEIL PUBLIC n'atterrit pas sur une page membre.
     *
     * **Ce que ce test prouve exactement**, mesure par sabotage : il tient
     * grace a la DISJONCTION des deux jeux de cles (section A) et a la
     * correspondance exacte du resolver — une page membre demande `agenda`, et
     * `organization_home` n'est simplement jamais demande. Remplacer
     * `SURFACES_MEMBER` par `SURFACES` dans le lecteur laisse ce test VERT.
     *
     * La garde `SURFACES_MEMBER` est donc une profondeur de defense, pour le
     * jour ou une cle figurerait dans les deux listes. Sa falsifiabilite est
     * portee par `test_the_reader_refuses_every_non_member_surface`, qui rougit
     * bien sur ce sabotage.
     */
    public function test_a_public_reference_never_reaches_a_member_surface(): void
    {
        $this->publish(UsageReference::SURFACE_ORGANIZATION_HOME, 'fr', 'Accueil public', 'Texte-accueil-public-TASK1477');
        $this->publish(UsageReference::SURFACE_SHELL_WELCOME, 'fr', 'Shell Welcome', 'Texte-guest-TASK1477');

        foreach (['organization.events.agenda', 'organization.members.index', 'organization.dashboard'] as $route) {
            $html = $this->visit($route);

            $this->assertStringNotContainsString('Texte-accueil-public-TASK1477', $html, $route);
            $this->assertStringNotContainsString('Texte-guest-TASK1477', $html, $route);
        }
    }

    /** Le lecteur refuse toute surface hors du perimetre membre, y compris `unknown`. */
    public function test_the_reader_refuses_every_non_member_surface(): void
    {
        foreach (UsageReference::SURFACES_PUBLIC as $surface) {
            $this->publish($surface, 'fr', 'Titre '.$surface, 'Contenu '.$surface);
        }

        $reader = app(AiShellUsageReference::class);

        foreach (UsageReference::SURFACES_PUBLIC as $surface) {
            $this->assertNull($reader->forSurface($surface, 'fr'), "[{$surface}] est publique : le Shell membre ne la sert pas");
        }

        $this->assertNull($reader->forSurface(AiShellPageContext::SURFACE_UNKNOWN, 'fr'));
        $this->assertNull($reader->forSurface('surface-inexistante', 'fr'));
    }

    /**
     * Locale : la reference demandee est servie dans la langue demandee, et le
     * repli de plateforme reste celui du resolver — on n'en ecrit pas un second.
     */
    public function test_the_locale_is_honoured_and_falls_back_like_the_resolver(): void
    {
        $this->publish('agenda', 'en', 'The agenda', 'Contenu-EN-TASK1477');

        $reader = app(AiShellUsageReference::class);

        $this->assertSame('Contenu-EN-TASK1477', $reader->forSurface('agenda', 'en')['content']);

        // En FR, aucune version francaise : le repli est celui de la plateforme.
        $platform = UsageReference::platformLocale();
        $expected = $platform === 'en' ? 'Contenu-EN-TASK1477' : null;

        $this->assertSame($expected, $reader->forSurface('agenda', 'fr')['content'] ?? null,
            'le repli de locale reste celui du resolver, jamais une seconde regle');
    }

    // =====================================================================
    // E. Une UsageReference n'accorde RIEN
    // =====================================================================

    /**
     * Le point le plus important du CDC : le repere explique, il n'autorise
     * pas. Publier une reference ne doit ajouter aucune action au panneau.
     */
    public function test_publishing_a_reference_adds_no_action(): void
    {
        $before = $this->visit('organization.events.agenda');
        $actionsBefore = substr_count($before, 'data-ai-fab-action');

        $this->publish('agenda', 'fr', 'L\'agenda', 'Vous pouvez organiser une rencontre, inviter tout le monde et publier le compte-rendu.');

        $after = $this->visit('organization.events.agenda');
        $actionsAfter = substr_count($after, 'data-ai-fab-action');

        $this->assertSame($actionsBefore, $actionsAfter, 'un texte ne cree pas une capacite');
    }

    /**
     * Et le lecteur ne connait aucune autorite d'action ni d'economie.
     *
     * La mesure porte sur le CODE, pas sur le fichier : `php_strip_whitespace`
     * retire les commentaires. Une interdiction brute de la chaine condamnait
     * le docblock, qui nomme `AiFabContext` precisement pour dire que ce
     * lecteur n'en est pas un — mesure faite, le test rougissait sur son propre
     * commentaire d'architecture.
     */
    public function test_the_reader_reaches_for_no_other_authority(): void
    {
        $code = php_strip_whitespace(app_path('Support/Ai/AiShellUsageReference.php'));

        $this->assertNotSame('', $code, 'php_strip_whitespace doit avoir rendu le code');

        foreach (['AiFabContext', 'AiEconomicGuard', 'ProviderResolver', 'Gate::', '->can(', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, $forbidden.' n\'a rien a faire dans un lecteur');
        }

        // Et la seule autorite qu'il appelle est bien celle qui existe deja.
        $this->assertStringContainsString('UsageReferenceResolver', $code);
    }

    /**
     * L'acquisition s'adresse a un VISITEUR : les surfaces membre n'ont rien a
     * faire dans son menu, ni dans sa validation.
     */
    public function test_the_acquisition_journey_only_offers_public_surfaces(): void
    {
        $controller = (string) file_get_contents(app_path('Http/Controllers/Admin/OrgAcquisitionController.php'));

        $this->assertStringContainsString('UsageReference::SURFACES_PUBLIC', $controller);
        $this->assertStringNotContainsString('UsageReference::SURFACES,', $controller,
            'le parcours d\'acquisition ne doit pas proposer une surface membre');
    }

    /** L'invariant de TASK-1466 tient : toujours pas de Shell global sur une Boucle. */
    public function test_no_global_shell_on_loops(): void
    {
        $loop = \App\Models\Loop::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->member)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $loop->id]))
            ->assertOk()
            ->assertDontSee('data-ai-shell-usage-reference', false)
            ->assertDontSee('data-ai-fab-usage-reference', false);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function publish(string $surface, string $locale, string $title, string $content): UsageReference
    {
        $service = app(\App\Services\UsageReference\UsageReferenceService::class);
        $draft = $service->createDraft($surface, $locale, $title, $content, $this->platformAdmin);

        return $service->publish($draft, $this->platformAdmin);
    }

    private function visit(string $routeName): string
    {
        return $this->actingAs($this->member)
            ->get(route($routeName, ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }
}
