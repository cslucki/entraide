<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopRootDocumentService;
use App\Services\Loops\RootDocumentLocaleRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Traits\Localizable;
use Tests\TestCase;

/**
 * TASK-1389 — remettre le texte SYSTEME des documents racines dans la langue de
 * leur Organization, sans jamais toucher a ce qu'une personne a ecrit.
 *
 * ## Ce que TASK-1388 n'a pas fait
 *
 * T1388 a corrige le MECANISME : packs et backfill posent desormais la locale
 * de l'Organization. Elle n'a rien repare de ce qui etait deja en base, et elle
 * ne le pouvait pas — `ensureRootDocument()` est idempotent et rend le document
 * existant sans le regarder. Mesure sur le parc au 04/09 : **8 documents
 * racines francais dans des Organizations anglaises**, six sur `artscilab-en`
 * et deux sur `launchpals`.
 *
 * ## Le risque reel de cette tranche, et la forme choisie pour l'annuler
 *
 * Une reparation de donnees peut EFFACER. La conception ecarte ce risque par sa
 * forme plutot que par une precaution : seuls des fragments delimites par leurs
 * propres balises sont remplaces — le prefixe du titre, le paragraphe
 * placeholder, les en-tetes `<h2>`. Le texte qui suit un `<h2>` n'est jamais lu
 * ni reecrit ; il n'existe aucun chemin par lequel ce service pourrait
 * l'effacer.
 *
 * Les mesures ci-dessous verifient les deux moities separement : ce qui DOIT
 * changer, et ce qui ne doit PAS bouger.
 */
class TASK1389RootDocumentLocaleRepairTest extends TestCase
{
    use Localizable;
    use RefreshDatabase;

    private Organization $organisationAnglaise;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisationAnglaise = Organization::factory()->create([
            'slug' => 'task1389-org-en',
            'locale' => 'en',
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->organisationAnglaise->id,
        ]);
    }

    // =====================================================================
    // Ce qui DOIT changer
    // =====================================================================

    /**
     * Le titre et les en-tetes de section passent a l'anglais.
     *
     * La mesure de base : un document ecrit sous la locale francaise dans une
     * Organization anglaise — exactement l'etat de `artscilab-en`.
     */
    public function test_the_system_title_and_section_headings_become_english(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais('task1389-base');

        app(RootDocumentLocaleRepairService::class)->repair($this->organisationAnglaise);

        $document = $this->document($boucle);

        $this->assertStringStartsWith('Manifesto — ', $document->title);
        $this->assertStringNotContainsString('Manifeste', $document->title);

        $this->assertStringContainsString('<h2>Why this Loop exists</h2>', $document->content);
        $this->assertStringNotContainsString('Pourquoi cette Boucle existe', $document->content);
    }

    /**
     * L'introduction placeholder est SYSTEME, donc traduite.
     *
     * `initialContent()` ne l'ecrit que si la Boucle n'avait pas de
     * description. C'est donc bien du texte fabrique par l'application, au
     * meme titre qu'un en-tete de section.
     */
    public function test_the_placeholder_introduction_is_translated(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais('task1389-placeholder', description: null);

        $this->assertStringContainsString(
            'Cette Boucle s',
            $this->document($boucle)->content,
            'La fixture doit bien partir du placeholder francais.',
        );

        app(RootDocumentLocaleRepairService::class)->repair($this->organisationAnglaise);

        $contenu = $this->document($boucle)->content;

        $this->assertStringContainsString('This Loop is called', $contenu);
        $this->assertStringNotContainsString('Cette Boucle s', $contenu);
    }

    /**
     * Une Boucle RENOMMEE depuis la creation est reparee quand meme.
     *
     * Le titre du document ne se resynchronise jamais avec le nom de la
     * Boucle. Une garde qui exigerait l'egalite complete avec le scaffold
     * recalcule refuserait donc de reparer toute Boucle renommee — c'est-a-dire,
     * sur un parc vivant, la plupart. La comparaison porte sur le PREFIXE.
     */
    public function test_a_renamed_loop_is_still_repaired(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais('task1389-renommee');

        $ancienTitre = $this->document($boucle)->title;
        $boucle->update(['name' => 'Un nom entierement different']);

        app(RootDocumentLocaleRepairService::class)->repair($this->organisationAnglaise);

        $document = $this->document($boucle);

        $this->assertStringStartsWith('Manifesto — ', $document->title);
        $this->assertSame(
            'Manifesto — '.mb_substr($ancienTitre, mb_strlen('Manifeste — ')),
            $document->title,
            'Le suffixe du titre — la part humaine — doit etre conserve tel quel.',
        );
    }

    // =====================================================================
    // Ce qui ne doit PAS bouger
    // =====================================================================

    /**
     * Le slug ne change jamais.
     *
     * Decision produit explicite : un slug est un identifiant d'URL durable.
     * `uniqueSlug()` le derive pourtant du libelle traduit, donc les slugs du
     * parc anglais portent `-cadre-du-dialogue`. La dette est assumee, et ce
     * test est ce qui l'empeche d'etre corrigee par megarde.
     */
    public function test_the_slug_is_never_touched(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais('task1389-slug');
        $slugAvant = $this->document($boucle)->slug;

        app(RootDocumentLocaleRepairService::class)->repair($this->organisationAnglaise);

        $this->assertSame($slugAvant, $this->document($boucle)->slug);
        $this->assertStringContainsString('manifeste', $slugAvant, 'La fixture doit bien porter un slug francais.');
    }

    /**
     * Le texte ecrit SOUS une section est preserve.
     *
     * C'est la mesure qui compte le plus : une reparation de donnees qui
     * effacerait une phrase ecrite par quelqu'un serait un incident, pas un
     * bug. Le remplacement etant borne a `<h2>…</h2>`, ce texte est hors de
     * portee par construction — ce test le prouve.
     */
    public function test_human_text_written_under_a_section_survives(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais('task1389-humain');
        $document = $this->document($boucle);

        $document->content = str_replace(
            '<h2>Pourquoi cette Boucle existe</h2><p></p>',
            '<h2>Pourquoi cette Boucle existe</h2><p>Une phrase que personne ne doit effacer.</p>',
            $document->content,
        );
        $document->save();

        app(RootDocumentLocaleRepairService::class)->repair($this->organisationAnglaise);

        $contenu = $this->document($boucle)->content;

        $this->assertStringContainsString('<h2>Why this Loop exists</h2>', $contenu);
        $this->assertStringContainsString('Une phrase que personne ne doit effacer.', $contenu);
    }

    /**
     * Une introduction venue de la description humaine n'est pas traduite.
     *
     * C'est l'etat reel de `artscilab-en` : l'intro y est deja anglaise parce
     * qu'elle vient de `loops.description`, saisie par une personne. Le
     * contrat le dit explicitement — le texte libre reste dans la langue
     * saisie.
     */
    public function test_a_human_written_introduction_is_left_alone(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais(
            'task1389-intro-humaine',
            description: 'Where the sonification work happens.',
        );

        app(RootDocumentLocaleRepairService::class)->repair($this->organisationAnglaise);

        $this->assertStringContainsString(
            '<p>Where the sonification work happens.</p>',
            $this->document($boucle)->content,
        );
    }

    /**
     * Un titre reecrit a la main n'est pas touche.
     *
     * Sans prefixe systeme reconnu, le service n'a aucun moyen de distinguer
     * quelle part serait traduisible. Il s'abstient.
     */
    public function test_a_hand_written_title_is_left_alone(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais('task1389-titre-humain');

        $document = $this->document($boucle);
        $document->title = 'Notre charte, ecrite a la main';
        $document->save();

        app(RootDocumentLocaleRepairService::class)->repair($this->organisationAnglaise);

        $this->assertSame('Notre charte, ecrite a la main', $this->document($boucle)->title);
    }

    /**
     * Une Organization deja dans la bonne langue n'est pas modifiee.
     *
     * Le contre-exemple. Sans lui, un service qui reecrirait tout
     * systematiquement passerait toutes les mesures precedentes.
     */
    public function test_an_organization_already_in_its_own_language_is_untouched(): void
    {
        $organisationFrancaise = Organization::factory()->create([
            'slug' => 'task1389-org-fr',
            'locale' => 'fr',
        ]);
        $membre = User::factory()->create(['organization_id' => $organisationFrancaise->id]);

        $boucle = $this->boucleAvecDocumentFrancais(
            'task1389-deja-bonne',
            organisation: $organisationFrancaise,
            membre: $membre,
        );
        $avant = $this->document($boucle);

        $rapport = app(RootDocumentLocaleRepairService::class)->repair($organisationFrancaise);

        $this->assertSame([], $rapport);

        $apres = $this->document($boucle);
        $this->assertSame($avant->title, $apres->title);
        $this->assertSame($avant->content, $apres->content);
    }

    /**
     * Une autre Organization n'est jamais touchee.
     *
     * La reparation est bornee a sa cible. Une commande de donnees qui
     * deborderait sur un voisin serait la faute la plus couteuse possible ici.
     */
    public function test_another_organization_is_never_touched(): void
    {
        $voisine = Organization::factory()->create(['slug' => 'task1389-voisine', 'locale' => 'en']);
        $membreVoisin = User::factory()->create(['organization_id' => $voisine->id]);

        $boucleVoisine = $this->boucleAvecDocumentFrancais(
            'task1389-voisine-boucle',
            organisation: $voisine,
            membre: $membreVoisin,
        );
        $avant = $this->document($boucleVoisine);

        $this->boucleAvecDocumentFrancais('task1389-cible');

        app(RootDocumentLocaleRepairService::class)->repair($this->organisationAnglaise);

        $apres = $this->document($boucleVoisine);
        $this->assertSame($avant->title, $apres->title);
        $this->assertSame($avant->content, $apres->content);
    }

    // =====================================================================
    // Rejeu et surface CLI
    // =====================================================================

    /**
     * Rejouer la reparation ne change plus rien.
     *
     * Une commande de donnees se relance : par prudence, apres une erreur, ou
     * parce que personne ne sait si elle a deja tourne. Le second passage doit
     * etre un no-op complet, rapport vide compris.
     */
    public function test_repairing_twice_changes_nothing_the_second_time(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais('task1389-rejeu');

        $premier = app(RootDocumentLocaleRepairService::class)->repair($this->organisationAnglaise);
        $apresPremier = $this->document($boucle);

        $second = app(RootDocumentLocaleRepairService::class)->repair($this->organisationAnglaise);
        $apresSecond = $this->document($boucle);

        $this->assertCount(1, $premier);
        $this->assertSame([], $second);
        $this->assertSame($apresPremier->title, $apresSecond->title);
        $this->assertSame($apresPremier->content, $apresSecond->content);
    }

    /**
     * `--dry-run` annonce le travail sans rien ecrire.
     *
     * L'apercu et l'application passent par le meme parcours, qui ne differe
     * que par le `save()`. Ce test verifie la moitie qui compte : l'apercu
     * n'ecrit pas.
     */
    public function test_the_dry_run_reports_without_writing(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais('task1389-dry-run');
        $avant = $this->document($boucle);

        $this->artisan('loops:repair-root-document-locale', [
            'organization' => $this->organisationAnglaise->slug,
            '--dry-run' => true,
        ])->assertSuccessful();

        $apres = $this->document($boucle);
        $this->assertSame($avant->title, $apres->title);
        $this->assertSame($avant->content, $apres->content);
    }

    /**
     * La commande repare, et refuse une Organization inconnue.
     *
     * La surface CLI est ce qui sera reellement lance sur le tenant de demo.
     */
    public function test_the_command_repairs_and_rejects_an_unknown_organization(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais('task1389-cli');

        $this->artisan('loops:repair-root-document-locale', [
            'organization' => $this->organisationAnglaise->slug,
        ])->assertSuccessful();

        $this->assertStringStartsWith('Manifesto — ', $this->document($boucle)->title);

        $this->artisan('loops:repair-root-document-locale', ['organization' => 'organization-qui-n-existe-pas'])
            ->assertFailed();
    }

    /**
     * La cible peut aussi etre donnee par son id.
     *
     * Ce test existe parce que la premiere version a plante en PostgreSQL sur
     * le tenant reel : `orWhere('id', $slug)` compare une chaine a une colonne
     * UUID, et le moteur leve `invalid input syntax for type uuid` avant meme
     * d'evaluer la clause sur le slug. La resolution ne tente donc l'id que si
     * la valeur en est un — et il faut les deux formes pour le mesurer.
     */
    public function test_the_command_accepts_a_slug_or_an_id(): void
    {
        $boucle = $this->boucleAvecDocumentFrancais('task1389-par-id');

        $this->artisan('loops:repair-root-document-locale', [
            'organization' => $this->organisationAnglaise->id,
        ])->assertSuccessful();

        $this->assertStringStartsWith('Manifesto — ', $this->document($boucle)->title);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Une Boucle dont le document racine a ete ecrit sous la locale FRANCAISE,
     * quelle que soit la locale de son Organization.
     *
     * C'est exactement le defaut que TASK-1388 a ferme pour l'avenir :
     * `ensureRootDocument()` appele hors du chargeur suit la locale ambiante.
     * La fixture le reproduit donc sans rien simuler.
     */
    private function boucleAvecDocumentFrancais(
        string $slug,
        ?string $description = 'Une description ecrite par une personne.',
        ?Organization $organisation = null,
        ?User $membre = null,
    ): Loop {
        $organisation ??= $this->organisationAnglaise;
        $membre ??= $this->membre;

        $boucle = Loop::query()->create([
            'organization_id' => $organisation->id,
            'slug' => $slug,
            'name' => 'Boucle '.$slug,
            'description' => $description,
            'type' => 'project',
            'status' => 'active',
            'visibility' => 'private',
            'access_mode' => Loop::ACCESS_REQUEST,
            'created_by' => $membre->id,
        ]);

        $this->withLocale('fr', fn () => app(LoopRootDocumentService::class)->ensureRootDocument($boucle, $membre));

        return $boucle->refresh();
    }

    private function document(Loop $boucle): BlogPost
    {
        return BlogPost::query()->findOrFail($boucle->refresh()->manifesto_blog_post_id);
    }
}
