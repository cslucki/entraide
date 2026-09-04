<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\User;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\ScenarioPacks\LocaleProbeScenarioPack;
use Tests\TestCase;

/**
 * TASK-1388 — un pack ecrit dans la langue de l'Organization, jamais dans
 * celle du processus qui l'applique.
 *
 * ## Le defaut, MESURE
 *
 * `ScenarioPackLoader::load()` recoit l'Organization — donc sa locale — et
 * appelle `$pack->apply($organization, $registrar)` sans jamais poser de
 * locale. Tout `__()` rencontre pendant l'application rend donc la locale
 * AMBIANTE : celle du CLI, ou celle du worker.
 *
 * L'audit R3 a corrige au passage une hypothese que j'avais posee a tort :
 * il n'existe **aucun `setLocale()` dans `app/`** hors le middleware HTTP. Le
 * risque n'est donc pas une contamination entre requetes — c'est l'absence
 * totale de pose hors du cycle HTTP. Un `php artisan scenario-pack:load`
 * lance depuis un shell francais peuple une Organization anglaise en
 * francais, et le contenu est PERSISTE : le titre, le slug et les sections du
 * document racine restent en francais pour toujours.
 *
 * ## Pourquoi ce point de passage, et lui seul
 *
 * `Test20260822DogfoodingPack::apply()` appelle `ensureRootDocument()`, dont
 * `initialContent()` fabrique `<h2>` a partir de `__()`. Poser la locale
 * autour de `$pack->apply()` couvre donc, en une seule ecriture, le
 * chargeur ET le service de document racine sur le chemin des packs — les
 * deux premiers mecanismes du classement R3.
 *
 * ## Les six mesures, et pourquoi il en faut six
 *
 * 1-2. Les deux sens. Un correctif qui forcerait `en` en dur passerait la
 *      premiere et rougirait la seconde.
 * 3.   Deux Organizations dans le MEME processus. Elle attrape une pose
 *      faite UNE fois — hoistee hors du chargement, ou mise en cache — que
 *      les mesures 1 et 2, prises isolement, laisseraient passer.
 * 4-5. Restauration, en sortie normale ET en sortie par exception.
 *
 * Le partage est MESURE, pas suppose (voir la table de sabotage du fichier
 * TASK) : une pose sans `finally` laisse 1, 2, 3 et 6 VERTS et ne rougit que
 * 4 et 5. La mesure 3 ne dit rien de la restauration — chaque chargement
 * repose la locale, donc la seconde Organization reste correcte meme sans
 * elle. C'est exactement pourquoi 4 et 5 existent separement.
 * 6.   Idempotence. Le correctif enveloppe le corps de la transaction : s'il
 *      deplacait le point de rejeu, un second chargement dupliquerait.
 */
class TASK1388OrganizationLocaleProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisationAnglaise;

    private Organization $organisationFrancaise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisationAnglaise = Organization::factory()->create([
            'slug' => 'task1388-org-en',
            'locale' => 'en',
        ]);

        $this->organisationFrancaise = Organization::factory()->create([
            'slug' => 'task1388-org-fr',
            'locale' => 'fr',
        ]);

        config([
            'scenario_packs.allowed_organizations' => [
                $this->organisationAnglaise->slug,
                $this->organisationFrancaise->slug,
            ],
        ]);
    }

    // =====================================================================
    // 1-2. Les deux sens
    // =====================================================================

    /**
     * CLI francais + Organization anglaise -> contenu ANGLAIS.
     *
     * Le cas de la demonstration du 04/09 : le shell est francais, la cible
     * ne l'est pas.
     */
    public function test_a_french_process_provisions_an_english_organization_in_english(): void
    {
        app()->setLocale('fr');

        $pack = new LocaleProbeScenarioPack;
        app(ScenarioPackLoader::class)->load($pack, $this->organisationAnglaise);

        $this->assertSame('en', $pack->localeObservee, 'Le pack doit s\'appliquer sous la locale de l\'Organization.');

        $document = $this->documentRacine($pack->loopSlug);

        $this->assertStringContainsString('Manifesto', $document->title);
        $this->assertStringNotContainsString('Manifeste', $document->title);

        $this->assertStringContainsString('Why this Loop exists', $document->content);
        $this->assertStringNotContainsString('Pourquoi cette Boucle existe', $document->content);
    }

    /**
     * CLI anglais + Organization francaise -> contenu FRANCAIS.
     *
     * Le miroir. Sans lui, un correctif qui forcerait `en` en dur — ou qui
     * lirait la locale de fallback plutot que celle de l'Organization —
     * passerait la mesure precedente sans rien resoudre.
     */
    public function test_an_english_process_provisions_a_french_organization_in_french(): void
    {
        app()->setLocale('en');

        $pack = new LocaleProbeScenarioPack;
        app(ScenarioPackLoader::class)->load($pack, $this->organisationFrancaise);

        $this->assertSame('fr', $pack->localeObservee);

        $document = $this->documentRacine($pack->loopSlug);

        $this->assertStringContainsString('Manifeste', $document->title);
        $this->assertStringNotContainsString('Manifesto', $document->title);

        $this->assertStringContainsString('Pourquoi cette Boucle existe', $document->content);
        $this->assertStringNotContainsString('Why this Loop exists', $document->content);
    }

    // =====================================================================
    // 3. Deux Organizations, un seul processus
    // =====================================================================

    /**
     * Deux Organizations de locales differentes, chargees a la suite : chacune
     * recoit la sienne.
     *
     * Elle attrape une pose faite une seule fois — hoistee hors du
     * chargement, lue une fois puis mise en cache, ou codee en dur : la
     * seconde Organization recevrait la langue de la premiere. Mesure : sous
     * le sabotage « locale codee en dur », ce test rougit alors que le test 1
     * reste vert.
     */
    public function test_two_organizations_of_different_locales_keep_their_own_language_in_one_process(): void
    {
        app()->setLocale('fr');

        $packAnglais = new LocaleProbeScenarioPack;
        app(ScenarioPackLoader::class)->load($packAnglais, $this->organisationAnglaise);

        $packFrancais = new LocaleProbeScenarioPack;
        app(ScenarioPackLoader::class)->load($packFrancais, $this->organisationFrancaise);

        $this->assertSame('en', $packAnglais->localeObservee);
        $this->assertSame('fr', $packFrancais->localeObservee);

        $this->assertStringContainsString('Manifesto', $this->documentRacine($packAnglais->loopSlug)->title);
        $this->assertStringContainsString('Manifeste', $this->documentRacine($packFrancais->loopSlug)->title);
    }

    // =====================================================================
    // 4-5. Restauration
    // =====================================================================

    /**
     * La locale applicative est rendue apres le chargement.
     *
     * Le chargeur emprunte la locale, il ne la confisque pas : ce qui court
     * apres lui dans le meme processus — une autre commande, un autre job —
     * doit retrouver l'etat qu'il avait pose.
     */
    public function test_the_application_locale_is_restored_after_provisioning(): void
    {
        app()->setLocale('fr');

        app(ScenarioPackLoader::class)->load(new LocaleProbeScenarioPack, $this->organisationAnglaise);

        $this->assertSame('fr', app()->getLocale());
    }

    /**
     * Elle est rendue MEME si le pack echoue.
     *
     * Une pose sans `finally` passerait la mesure precedente et laisserait le
     * processus en anglais des qu'un pack leve. C'est precisement ce que
     * `Illuminate\Support\Traits\Localizable::withLocale()` garantit, et la
     * raison de preferer l'idiome du framework a un `setLocale()` manuel
     * encadre a la main.
     */
    public function test_the_application_locale_is_restored_when_the_pack_fails(): void
    {
        app()->setLocale('fr');

        $pack = new LocaleProbeScenarioPack(echoueApresMesure: true);

        try {
            app(ScenarioPackLoader::class)->load($pack, $this->organisationAnglaise);
            $this->fail('Le pack devait lever.');
        } catch (RuntimeException) {
            // L'exception doit remonter au chargeur : la transaction s'annule.
        }

        $this->assertSame('en', $pack->localeObservee, 'La locale devait bien avoir ete posee avant l\'echec.');
        $this->assertSame('fr', app()->getLocale(), 'Et rendue malgre l\'echec.');
    }

    // =====================================================================
    // 6. Idempotence
    // =====================================================================

    /**
     * Rejouer le chargement ne duplique rien.
     *
     * Le correctif enveloppe le corps de la transaction. S'il avait deplace le
     * point de rejeu — par exemple en sortant `$pack->apply()` de la
     * transaction — un second chargement produirait une seconde copie des
     * entites. La garantie de TASK-1240 doit survivre telle quelle.
     */
    public function test_provisioning_stays_idempotent(): void
    {
        app()->setLocale('fr');

        $premier = app(ScenarioPackLoader::class)->load(new LocaleProbeScenarioPack, $this->organisationAnglaise);
        $second = app(ScenarioPackLoader::class)->load(new LocaleProbeScenarioPack, $this->organisationAnglaise);

        $this->assertTrue($premier->wasFirstLoad);
        $this->assertFalse($second->wasFirstLoad);

        $this->assertSame(
            ScenarioPackEntity::query()->where('scenario_pack_load_id', $premier->load->id)->count(),
            ScenarioPackEntity::query()->where('scenario_pack_load_id', $second->load->id)->count(),
        );

        $this->assertSame(1, Loop::query()->where('organization_id', $this->organisationAnglaise->id)->count());
        $this->assertSame(1, BlogPost::query()->where('organization_id', $this->organisationAnglaise->id)->count());
    }

    // =====================================================================
    // 7-8. L'autre CLI de provisionnement
    // =====================================================================

    /**
     * `loops:backfill-root-documents` balaie plusieurs Organizations en une
     * passe : chaque document sort dans la langue de la SIENNE.
     *
     * C'est la forme la plus severe du contrat. Le pack traite une
     * Organization par appel ; cette commande en traite N dans une seule
     * boucle, depuis un shell dont la locale n'a rien a voir avec elles. Une
     * pose faite une fois avant la boucle — l'erreur naturelle ici —
     * donnerait la meme langue a tout le monde.
     */
    public function test_the_backfill_command_writes_each_document_in_its_own_organizations_language(): void
    {
        app()->setLocale('fr');

        $boucleAnglaise = $this->boucleSansDocument($this->organisationAnglaise, 'task1388-backfill-en');
        $boucleFrancaise = $this->boucleSansDocument($this->organisationFrancaise, 'task1388-backfill-fr');

        $this->artisan('loops:backfill-root-documents')->assertSuccessful();

        $this->assertStringContainsString('Manifesto', $this->documentRacine($boucleAnglaise->slug)->title);
        $this->assertStringContainsString('Why this Loop exists', $this->documentRacine($boucleAnglaise->slug)->content);

        $this->assertStringContainsString('Manifeste', $this->documentRacine($boucleFrancaise->slug)->title);
        $this->assertStringContainsString('Pourquoi cette Boucle existe', $this->documentRacine($boucleFrancaise->slug)->content);
    }

    /**
     * La commande rend la locale du processus.
     *
     * Une commande de rattrapage est souvent chainee avec d'autres dans le
     * meme `artisan` ; elle ne doit pas teindre ce qui la suit.
     */
    public function test_the_backfill_command_restores_the_process_locale(): void
    {
        app()->setLocale('fr');

        $this->boucleSansDocument($this->organisationAnglaise, 'task1388-backfill-restore');

        $this->artisan('loops:backfill-root-documents')->assertSuccessful();

        $this->assertSame('fr', app()->getLocale());
    }

    /**
     * Une Boucle sans Dossier racine ni document — l'etat que la commande
     * repare.
     */
    private function boucleSansDocument(Organization $organisation, string $slug): Loop
    {
        $membre = User::factory()->create(['organization_id' => $organisation->id]);

        return Loop::query()->create([
            'organization_id' => $organisation->id,
            'slug' => $slug,
            'name' => 'Backfill Loop '.$slug,
            // Vide a dessein : voir `LocaleProbeScenarioPack`.
            'description' => null,
            'type' => 'project',
            'status' => 'active',
            'visibility' => 'private',
            'access_mode' => Loop::ACCESS_REQUEST,
            'created_by' => $membre->id,
        ]);
    }

    /**
     * Le document racine de la Boucle creee par le pack.
     *
     * Passe par le champ que `designate()` aligne, pour ne dependre ni d'un
     * ordre de creation ni du seul Dossier racine.
     */
    private function documentRacine(string $loopSlug): BlogPost
    {
        $loop = Loop::query()->where('slug', $loopSlug)->firstOrFail();

        return BlogPost::query()->findOrFail($loop->manifesto_blog_post_id);
    }
}
