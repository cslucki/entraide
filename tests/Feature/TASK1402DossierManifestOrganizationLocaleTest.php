<?php

namespace Tests\Feature;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\DossierManifestSource;
use App\Ai\ContexteIa;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1402 — les libelles SYSTEME du manifeste Dossier suivent
 * `Organization.locale`.
 *
 * Le fait mesure (FINAL PASS 1, 06/09) : sur `artscilab-en`, une reponse
 * ANGLAISE de « Ask the Folders » contenait « Fichier MD » et « Fichier TXT ».
 * Ce n'etait pas une hesitation du modele : `DossierManifestSource` lui
 * DONNAIT ces mots, en dur, dans le contexte. Le modele recopiait un fait.
 *
 * T1400 avait pose la langue de la REPONSE. Ici c'est la langue du CONTEXTE :
 * un producteur distinct, que le grep de T1400 ne pouvait pas atteindre
 * puisqu'il ne cherchait pas dans les sources de contexte.
 *
 * Ce que ces gardes mesurent, c'est le TEXTE REELLEMENT COMPOSE par la source
 * — pas la presence d'une cle de traduction. Une cle mal ecrite rend la
 * chaine brute et resterait invisible a une garde qui se contenterait de
 * verifier que `trans()` est appele.
 */
class TASK1402DossierManifestOrganizationLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_english_organization_gets_english_system_labels(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture('en');
        $this->draftRootDocument($loop);
        $this->attachArticle($organization, $owner, $dossier, 'Renewal narrative outline');
        $this->attachFile($organization, $owner, $dossier, 'Budget justification notes.txt', 'text/plain');
        $this->attachFile($organization, $owner, $dossier, 'Evidence log.md', 'text/markdown');

        $texte = $this->manifestText($organization, $owner, $loop);

        // La promesse, mot pour mot.
        $this->assertStringNotContainsString('Fichier', $texte);
        $this->assertStringNotContainsString('Dossier', $texte);
        $this->assertStringNotContainsString('ELEMENTS', $texte);

        // Et le positif : les libelles anglais sont bien la, pas une chaine vide
        // ni une cle brute. Sans ces assertions, effacer tout le manifeste
        // ferait passer les trois `assertStringNotContainsString` ci-dessus.
        $this->assertStringContainsString("ITEMS IN THIS LOOP'S FOLDER", $texte);
        $this->assertStringContainsString('Article: Renewal narrative outline', $texte);
        $this->assertStringContainsString('TXT file: Budget justification notes.txt', $texte);
        $this->assertStringContainsString('MD file: Evidence log.md', $texte);
        $this->assertStringNotContainsString('ai.dossier_manifest', $texte);
    }

    public function test_a_french_organization_keeps_the_french_labels(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture('fr');
        $this->draftRootDocument($loop);
        $this->attachArticle($organization, $owner, $dossier, 'Cadre du dialogue');
        $this->attachFile($organization, $owner, $dossier, 'Manifeste.pdf', 'application/pdf');

        $texte = $this->manifestText($organization, $owner, $loop);

        $this->assertStringContainsString('ELEMENTS DU DOSSIER DE CETTE BOUCLE', $texte);
        $this->assertStringContainsString('Article : Cadre du dialogue', $texte);
        $this->assertStringContainsString('Fichier PDF : Manifeste.pdf', $texte);
        $this->assertStringContainsString('Dossier «', $texte);
    }

    /**
     * L'autorite est l'Organization, PAS le lecteur. Un membre francophone
     * d'une Organization anglaise doit recevoir le meme contexte que ses
     * collegues — sinon le modele recoit une langue differente selon qui
     * pose la question, ce qui est exactement le defaut d'origine.
     */
    public function test_a_french_reader_inside_an_english_organization_still_gets_english(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture('en');
        $this->draftRootDocument($loop);
        $this->attachFile($organization, $owner, $dossier, 'Notes.md', 'text/markdown');

        App::setLocale('fr');

        $texte = $this->manifestText($organization, $owner, $loop, contexteLocale: 'fr');

        $this->assertStringContainsString("ITEMS IN THIS LOOP'S FOLDER", $texte);
        $this->assertStringContainsString('MD file: Notes.md', $texte);
        $this->assertStringNotContainsString('Fichier', $texte);
    }

    public function test_an_english_reader_inside_a_french_organization_still_gets_french(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture('fr');
        $this->draftRootDocument($loop);
        $this->attachFile($organization, $owner, $dossier, 'Notes.md', 'text/markdown');

        App::setLocale('en');

        $texte = $this->manifestText($organization, $owner, $loop, contexteLocale: 'en');

        $this->assertStringContainsString('Fichier MD : Notes.md', $texte);
        $this->assertStringNotContainsString("ITEMS IN THIS LOOP'S FOLDER", $texte);
    }

    /**
     * `trans($cle, [], $locale)` epingle la langue sans deplacer
     * `App::setLocale`. Si un jour quelqu'un remplace cela par un
     * `App::setLocale()` suivi d'une restauration, cette garde attrapera le
     * jour ou la restauration sautera (exception, retour anticipe...).
     */
    public function test_the_application_locale_is_untouched_by_the_generation(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture('en');
        $this->attachFile($organization, $owner, $dossier, 'Notes.md', 'text/markdown');

        App::setLocale('fr');
        $avant = App::getLocale();

        $texte = $this->manifestText($organization, $owner, $loop, contexteLocale: 'fr');

        // Temoin : la generation a bien produit quelque chose. Sans lui, cette
        // garde passerait meme si la source ne faisait plus rien du tout.
        $this->assertStringContainsString('Notes.md', $texte);
        $this->assertSame('fr', $avant);
        $this->assertSame('fr', App::getLocale());
    }

    /**
     * Le repli quand le type MIME est vide est un MOT, pas un sigle : PDF, MD
     * et TXT sont identiques dans toutes les langues, « fichier » non.
     */
    public function test_the_empty_mime_fallback_follows_the_organization_too(): void
    {
        [$organizationEn, $ownerEn, $loopEn, $dossierEn] = $this->fixture('en');
        $this->attachFile($organizationEn, $ownerEn, $dossierEn, 'Sans-type', '');

        [$organizationFr, $ownerFr, $loopFr, $dossierFr] = $this->fixture('fr');
        $this->attachFile($organizationFr, $ownerFr, $dossierFr, 'Sans-type', '');

        $this->assertStringContainsString('file file: Sans-type', $this->manifestText($organizationEn, $ownerEn, $loopEn));
        $this->assertStringContainsString('Fichier fichier : Sans-type', $this->manifestText($organizationFr, $ownerFr, $loopFr));
    }

    /**
     * `organizations.locale` est NOT NULL DEFAULT 'fr' : une Organization
     * « sans locale » n'existe pas au sens SQL. La seule forme vide atteignable
     * est la chaine VIDE — c'est donc la seule branche que le repli
     * `app.fallback_locale` protege reellement, et c'est elle qu'on mesure.
     * Ecrire cette garde sur `null` aurait teste une situation que le schema
     * interdit.
     */
    public function test_an_empty_organization_locale_falls_back_to_the_application_fallback(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture('');
        $this->attachFile($organization, $owner, $dossier, 'Notes.md', 'text/markdown');

        $this->assertSame('', (string) $organization->fresh()->locale);
        $this->assertSame('fr', (string) config('app.fallback_locale'));
        $this->assertStringContainsString('Fichier MD : Notes.md', $this->manifestText($organization, $owner, $loop));
    }

    /**
     * Le contenu HUMAIN n'est jamais traduit : seuls les libelles de structure
     * le sont. Un nom de Dossier francais dans une Organization anglaise reste
     * francais — c'est une donnee, pas un libelle.
     */
    public function test_human_content_is_never_translated(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture('en');
        $dossier->update(['name' => 'Dossier de la subvention']);
        $this->attachFile($organization, $owner, $dossier, 'Journal des preuves.md', 'text/markdown');

        $texte = $this->manifestText($organization, $owner, $loop);

        $this->assertStringContainsString('Journal des preuves.md', $texte);
        $this->assertStringContainsString('Dossier de la subvention', $texte);
        // Le libelle de structure, lui, est bien anglais.
        $this->assertStringContainsString('Folder "Dossier de la subvention"', $texte);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * @return array{0: Organization, 1: User, 2: Loop, 3: Dossier}
     */
    private function fixture(string $locale): array
    {
        $organization = Organization::factory()->create(['locale' => $locale]);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        app()->instance('current_organization', $organization);

        $loop = (new LoopService)->createLoop($owner, 'Boucle manifest '.uniqid());
        $dossier = Dossier::query()->where('loop_id', $loop->id)->firstOrFail();

        return [$organization, $owner, $loop, $dossier];
    }

    private function manifestText(Organization $organization, User $owner, Loop $loop, string $contexteLocale = 'fr'): string
    {
        app()->instance('current_organization', $organization);

        $fragment = app(DossierManifestSource::class)->collect(new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $owner->id,
            loopId: (string) $loop->id,
            locale: $contexteLocale,
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            query: 'inventaire',
        ), 6000);

        return $fragment->text;
    }

    private function attachArticle(Organization $organization, User $owner, Dossier $dossier, string $title): void
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::uuid(),
            'content' => '<p>x</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);
    }

    private function attachFile(Organization $organization, User $owner, Dossier $dossier, string $name, string $mimeType): void
    {
        DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/'.Str::uuid().'-'.$name,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => $mimeType,
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', $name.uniqid()),
            'source' => 'upload',
        ]);
    }

    private function draftRootDocument(Loop $loop): void
    {
        BlogPost::whereKey(Dossier::where('loop_id', $loop->id)->value('root_blog_post_id'))
            ->update(['status' => 'draft']);
    }
}
