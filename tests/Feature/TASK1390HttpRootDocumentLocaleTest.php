<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Traits\Localizable;
use Tests\TestCase;

/**
 * TASK-1390 — le scaffold racine cree depuis le WEB suit la langue de
 * l'Organization, pas celle du lecteur.
 *
 * ## Le dernier mecanisme ouvert du classement R3
 *
 * T1388 a pose la locale sur les chemins CLI (packs, backfill). T1389 a repare
 * les donnees deja ecrites. Restait le chemin HTTP : `LoopService` (deux points
 * de creation) et `LoopManifestoCard` appellent `ensureRootDocument()` sous la
 * locale AMBIANTE, qui en requete est celle du LECTEUR.
 *
 * Consequence, et elle est arrivee : un membre francais qui cree une Boucle
 * dans une Organization anglaise y ecrit un Manifeste francais — titre, slug et
 * en-tetes de sections, tous **persistes**.
 *
 * ## L'arbitrage produit qui tranche
 *
 * Tout contenu SYSTEME durable genere pour une Organization suit
 * `Organization.locale`. Le texte libre saisi par une personne reste
 * exactement dans la langue saisie. La distinction n'est pas cosmetique : elle
 * dit quelle partie du document appartient a l'application et laquelle
 * appartient a son auteur.
 *
 * ## Ou la locale est posee, et pourquoi la
 *
 * Dans `ensureRootDocument()` lui-meme, pas dans ses trois appelants. C'est le
 * goulot : les trois convergent vers lui, il est le seul a fabriquer du texte
 * depuis `__()`, et un quatrieme appelant ecrit demain heriterait de la garde
 * sans que personne y pense. Envelopper les appelants aurait demande trois
 * ecritures pour un resultat strictement inferieur.
 */
class TASK1390HttpRootDocumentLocaleTest extends TestCase
{
    use Localizable;
    use RefreshDatabase;

    private Organization $organisationAnglaise;

    private Organization $organisationFrancaise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisationAnglaise = Organization::factory()->create([
            'slug' => 'task1390-org-en',
            'locale' => 'en',
        ]);

        $this->organisationFrancaise = Organization::factory()->create([
            'slug' => 'task1390-org-fr',
            'locale' => 'fr',
        ]);
    }

    // =====================================================================
    // Les deux sens
    // =====================================================================

    /**
     * Lecteur FRANCAIS, Organization ANGLAISE -> scaffold ANGLAIS.
     *
     * Le cas qui a motive la tranche.
     */
    public function test_a_french_reader_creating_in_an_english_organization_writes_english(): void
    {
        $membre = $this->membre($this->organisationAnglaise, 'fr');

        $document = $this->creerBoucleEtLireSonDocument($membre, 'Advisory Board');

        $this->assertStringStartsWith('Manifesto — ', $document->title);
        $this->assertStringContainsString('<h2>Why this Loop exists</h2>', $document->content);
        $this->assertStringNotContainsString('Pourquoi cette Boucle existe', $document->content);
    }

    /**
     * Lecteur ANGLAIS, Organization FRANCAISE -> scaffold FRANCAIS.
     *
     * Le miroir. Sans lui, un correctif qui forcerait l'anglais passerait la
     * mesure precedente sans rien resoudre.
     */
    public function test_an_english_reader_creating_in_a_french_organization_writes_french(): void
    {
        $membre = $this->membre($this->organisationFrancaise, 'en');

        $document = $this->creerBoucleEtLireSonDocument($membre, 'Conseil consultatif');

        $this->assertStringStartsWith('Manifeste — ', $document->title);
        $this->assertStringContainsString('<h2>Pourquoi cette Boucle existe</h2>', $document->content);
        $this->assertStringNotContainsString('Why this Loop exists', $document->content);
    }

    // =====================================================================
    // Ce qui ne doit PAS suivre l'Organization
    // =====================================================================

    /**
     * Le texte saisi par une personne reste dans la langue saisie.
     *
     * C'est la seconde moitie de l'arbitrage, et elle compte autant que la
     * premiere : la description recopiee dans l'introduction ne doit subir
     * aucune traduction, aucune normalisation, aucun remplacement.
     */
    public function test_human_authored_text_keeps_the_language_it_was_written_in(): void
    {
        $membre = $this->membre($this->organisationAnglaise, 'fr');

        $description = 'Une description ecrite en francais, dans une Organization anglaise.';

        $document = $this->creerBoucleEtLireSonDocument($membre, 'Comite de lecture', $description);

        $this->assertStringContainsString('<p>'.e($description).'</p>', $document->content);
        $this->assertStringStartsWith('Manifesto — ', $document->title);
        $this->assertStringContainsString('Comite de lecture', $document->title);
    }

    /**
     * La locale du processus est rendue apres la creation.
     *
     * En requete, la locale porte l'affichage de la reponse : la laisser
     * modifiee rendrait la page de confirmation dans la mauvaise langue —
     * l'utilisateur verrait sa propre interface changer parce qu'il a cree une
     * Boucle.
     */
    public function test_the_readers_locale_is_restored_after_creation(): void
    {
        $membre = $this->membre($this->organisationAnglaise, 'fr');

        $this->withLocale('fr', function () use ($membre) {
            app(LoopService::class)->createLoopForOrg(
                $membre,
                $this->organisationAnglaise->id,
                'Locale Restored',
            );

            $this->assertSame('fr', app()->getLocale());
        });
    }

    /**
     * Deux Organizations de locales differentes, dans le meme processus.
     *
     * Attrape une pose faite une seule fois, ou mise en cache : la seconde
     * Organization recevrait la langue de la premiere.
     */
    public function test_two_organizations_created_in_sequence_each_get_their_own_language(): void
    {
        $anglophone = $this->membre($this->organisationAnglaise, 'fr');
        $francophone = $this->membre($this->organisationFrancaise, 'fr');

        $anglais = $this->creerBoucleEtLireSonDocument($anglophone, 'Sequence EN');
        $francais = $this->creerBoucleEtLireSonDocument($francophone, 'Sequence FR');

        $this->assertStringStartsWith('Manifesto — ', $anglais->title);
        $this->assertStringStartsWith('Manifeste — ', $francais->title);
    }

    /**
     * Le slug d'un NOUVEAU document suit lui aussi l'Organization.
     *
     * `uniqueSlug()` derive du libelle traduit. TASK-1389 a decide de ne pas
     * migrer les slugs EXISTANTS — un identifiant d'URL durable ne se renomme
     * pas retroactivement. Mais un document cree APRES cette tranche n'a aucune
     * URL a preserver : le laisser naitre en francais dans un tenant anglais
     * fabriquerait la dette que T1389 vient de constater.
     */
    public function test_a_new_documents_slug_follows_the_organization_too(): void
    {
        $membre = $this->membre($this->organisationAnglaise, 'fr');

        $document = $this->creerBoucleEtLireSonDocument($membre, 'Slug Follows');

        // Type `project` : le libelle est « Manifesto » en anglais,
        // « Manifeste » en francais. C'est bien ce couple qu'il faut mesurer —
        // ma premiere version attendait le libelle de `general` et rougissait
        // sur une fixture pourtant correcte.
        $this->assertStringContainsString('manifesto', $document->slug);
        $this->assertStringNotContainsString('manifeste', $document->slug);
    }

    // =====================================================================
    // Le chemin HTTP reel
    // =====================================================================

    /**
     * De bout en bout, par la route de creation.
     *
     * Les mesures precedentes passent par le service : elles isolent le
     * mecanisme. Celle-ci traverse le middleware `SetLocale`, qui pose la
     * locale du lecteur depuis `preferred_locale` — c'est-a-dire l'etat reel
     * dans lequel le defaut se produisait. Sans elle, une garde posee au bon
     * endroit mais court-circuitee par le middleware passerait inapercue.
     */
    public function test_the_real_http_creation_route_writes_the_organizations_language(): void
    {
        $membre = $this->membre($this->organisationAnglaise, 'fr');

        $reponse = $this->actingAs($membre)->post(route('loops.store'), [
            'name' => 'Created Over Http',
            'description' => 'Created through the real route.',
            'visibility' => 'private',
            'access_mode' => Loop::ACCESS_REQUEST,
            'type' => 'project',
        ]);

        $reponse->assertRedirect();

        $boucle = Loop::query()->where('organization_id', $this->organisationAnglaise->id)
            ->where('name', 'Created Over Http')
            ->firstOrFail();

        $document = BlogPost::query()->findOrFail($boucle->manifesto_blog_post_id);

        $this->assertStringStartsWith('Manifesto — ', $document->title);
        $this->assertStringContainsString('<h2>Why this Loop exists</h2>', $document->content);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Un membre dont la langue d'interface est EXPLICITE.
     *
     * `SetLocale` lit `preferred_locale` sur l'utilisateur authentifie : c'est
     * la seule facon honnete de fixer la locale du lecteur dans un test HTTP.
     * L'emprunter a l'environnement rendrait la mesure dependante de
     * `APP_LOCALE`.
     */
    private function membre(Organization $organisation, string $locale): User
    {
        return User::factory()->create([
            'organization_id' => $organisation->id,
            'preferred_locale' => $locale,
        ]);
    }

    private function creerBoucleEtLireSonDocument(User $membre, string $nom, ?string $description = null): BlogPost
    {
        $boucle = $this->withLocale(
            $membre->preferred_locale,
            fn () => app(LoopService::class)->createLoopForOrg(
                $membre,
                $membre->organization_id,
                $nom,
                $description,
                'private',
                null,
                Loop::ACCESS_REQUEST,
                'project',
            ),
        );

        return BlogPost::query()->findOrFail($boucle->refresh()->manifesto_blog_post_id);
    }
}
