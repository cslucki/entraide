<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workshop;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1611 — LE visuel facultatif d'un atelier, de l'ecran OrgAdmin jusqu'a
 * l'apercu que WhatsApp ou LinkedIn affichent quand la page est partagee.
 *
 * Trois promesses mesurees ici :
 *
 *  1. UN seul visuel par atelier, depose, remplace ou retire depuis la SEULE
 *     interface de creation/edition existante — le fichier precedent ne reste
 *     jamais sur le disque ;
 *  2. la page publique DECLARE son apercu (titre, description reelle, URL
 *     canonique, image) au lieu de subir les replis de marque ;
 *  3. la garde de TASK-1610 tient : le layout partage continue de ne deduire
 *     AUCUNE URL de la requete — c'est la page qui la nomme.
 */
class TASK1611WorkshopFlyerAndSocialCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private Workshop $workshop;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 10:00:00');
        Storage::fake('public');

        $this->organization = Organization::factory()->create(['slug' => 'org-1611-social', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->organization->update(['admin_id' => $this->admin->id]);

        $this->workshop = app(WorkshopService::class)->create($this->organization, [
            'title' => 'Dogfood 1450 : découvrir BouclePro',
            'slug' => 'dogfood-1450',
            'promise' => 'Comprendre en 45 minutes ce que BouclePro fait pour vous.',
            'description' => "Une description longue.\n\nSur plusieurs paragraphes.",
            'format' => 'online',
            'locale' => 'fr',
            'duration_minutes' => 45,
        ], $this->admin);
        app(WorkshopService::class)->publish($this->workshop, $this->admin);
        $session = app(WorkshopSessionService::class)->create($this->workshop, ['starts_at' => '2026-10-22 18:30', 'timezone' => 'Europe/Paris'], $this->admin);
        app(WorkshopSessionService::class)->publish($session, $this->admin);
    }

    private function publicPage(): string
    {
        return $this->withCredentials()
            ->get(route('organization.workshop.show', ['organization' => $this->organization->slug, 'workshop' => $this->workshop->slug]))
            ->assertOk()
            ->getContent();
    }

    private function meta(string $html, string $attribute, string $name): ?string
    {
        return preg_match('/<meta '.$attribute.'="'.preg_quote($name, '/').'" content="([^"]*)"/', $html, $m) === 1
            ? html_entity_decode($m[1])
            : null;
    }

    /** Les champs deja portes par le formulaire, pour un POST complet. */
    private function formPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Atelier neuf',
            'slug' => 'atelier-neuf',
            'format' => 'online',
            'locale' => 'fr',
        ], $overrides);
    }

    // =====================================================================
    // A. L'ecran OrgAdmin : deposer, remplacer, retirer
    // =====================================================================

    public function test_the_creation_screen_accepts_an_optional_flyer_and_the_form_can_carry_a_file(): void
    {
        $form = $this->actingAs($this->admin)
            ->get(route('organization.admin.workshops.create', $this->organization))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('enctype="multipart/form-data"', $form, 'sans cela, aucun fichier ne part');
        $this->assertStringContainsString('data-workshop-flyer', $form);

        $this->actingAs($this->admin)
            ->post(route('organization.admin.workshops.store', $this->organization), $this->formPayload([
                'flyer' => UploadedFile::fake()->image('affiche.jpg', 1200, 630),
            ]))
            ->assertRedirect();

        $created = Workshop::query()->forOrganization($this->organization)->where('slug', 'atelier-neuf')->sole();
        $this->assertTrue($created->hasFlyer());
        Storage::disk('public')->assertExists($created->flyer_path);
        $this->assertStringContainsString($this->organization->id, $created->flyer_path, 'le fichier est range sous son Organization');
        $this->assertSame(1200, $created->flyer_width);
        $this->assertSame(630, $created->flyer_height);
    }

    public function test_replacing_the_flyer_deletes_the_previous_file_and_removing_it_clears_both_the_file_and_the_columns(): void
    {
        app(WorkshopService::class)->attachFlyer($this->workshop, UploadedFile::fake()->image('premier.jpg', 800, 600), $this->admin);
        $first = $this->workshop->refresh()->flyer_path;
        Storage::disk('public')->assertExists($first);

        // Remplacement depuis l'ecran d'edition.
        $this->actingAs($this->admin)
            ->put(route('organization.admin.workshops.update', [$this->organization, $this->workshop]), $this->formPayload([
                'title' => $this->workshop->title,
                'slug' => $this->workshop->slug,
                'flyer' => UploadedFile::fake()->image('second.png', 1200, 630),
            ]))
            ->assertRedirect();

        $second = $this->workshop->refresh()->flyer_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first, 'un remplacement ne laisse pas d\'orphelin sur le disque');
        $this->assertSame('image/png', $this->workshop->flyerMimeType());

        // L'ecran d'edition montre le visuel courant et propose de le retirer.
        $edit = $this->actingAs($this->admin)->get(route('organization.admin.workshops.edit', [$this->organization, $this->workshop]))->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-flyer-preview', $edit);
        $this->assertStringContainsString('data-workshop-flyer-remove', $edit);

        // Retrait.
        $this->actingAs($this->admin)
            ->put(route('organization.admin.workshops.update', [$this->organization, $this->workshop]), $this->formPayload([
                'title' => $this->workshop->title,
                'slug' => $this->workshop->slug,
                'remove_flyer' => '1',
            ]))
            ->assertRedirect();

        $this->workshop->refresh();
        $this->assertFalse($this->workshop->hasFlyer());
        $this->assertNull($this->workshop->flyer_width);
        $this->assertNull($this->workshop->flyer_height);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_a_non_image_or_oversized_upload_is_refused_and_leaves_no_new_file_behind(): void
    {
        // Un visuel LEGITIME d'abord : sans lui, le dossier n'existerait de
        // toute facon pas, et « aucun fichier de plus » ne mesurerait rien.
        app(WorkshopService::class)->attachFlyer($this->workshop, UploadedFile::fake()->image('legitime.jpg', 800, 600), $this->admin);
        $directory = Workshop::FLYER_DIRECTORY.'/'.$this->organization->id;
        $before = Storage::disk('public')->files($directory);
        $this->assertCount(1, $before);

        foreach ([
            'refus-pdf' => UploadedFile::fake()->create('piege.pdf', 20, 'application/pdf'),
            'refus-lourd' => UploadedFile::fake()->image('enorme.jpg')->size(Workshop::FLYER_MAX_KILOBYTES + 1),
        ] as $slug => $file) {
            $this->actingAs($this->admin)
                ->post(route('organization.admin.workshops.store', $this->organization), $this->formPayload([
                    'slug' => $slug,
                    'flyer' => $file,
                ]))
                ->assertSessionHasErrors('flyer');

            $this->assertFalse(
                Workshop::query()->forOrganization($this->organization)->where('slug', $slug)->exists(),
                "rien n'est cree quand le visuel est refuse ({$slug})"
            );
        }

        // EXACTEMENT les memes fichiers qu'avant : ni orphelin de plus, ni
        // visuel legitime emporte par un refus.
        $this->assertSame($before, Storage::disk('public')->files($directory));
        $this->assertSame($before[0], $this->workshop->refresh()->flyer_path);
    }

    public function test_the_stored_file_is_removed_when_the_row_cannot_be_written(): void
    {
        $directory = Workshop::FLYER_DIRECTORY.'/'.$this->organization->id;
        app(WorkshopService::class)->attachFlyer($this->workshop, UploadedFile::fake()->image('premier.jpg', 800, 600), $this->admin);
        $before = Storage::disk('public')->files($directory);
        $first = $this->workshop->refresh()->flyer_path;

        // On fait echouer l'ecriture APRES le rangement du fichier. Le refus
        // vient d'un ecouteur `saving` et non d'un SQL fautif : sous
        // PostgreSQL, une requete en erreur avorte la transaction entiere de
        // `RefreshDatabase` et toute assertion suivante mourrait avec elle.
        // Le dispatcher est remplace le temps du test, puis rendu intact.
        $dispatcher = Workshop::getEventDispatcher();
        Workshop::setEventDispatcher(new Dispatcher);
        Workshop::saving(function () {
            throw new RuntimeException('ecriture refusee');
        });

        try {
            app(WorkshopService::class)->attachFlyer($this->workshop, UploadedFile::fake()->image('second.jpg', 800, 600), $this->admin);
            $this->fail('l\'ecriture aurait du echouer');
        } catch (RuntimeException $exception) {
            $this->assertSame('ecriture refusee', $exception->getMessage(), 'l\'erreur remonte telle quelle a l\'appelant');
        } finally {
            Workshop::flushEventListeners();
            Workshop::setEventDispatcher($dispatcher);
        }

        $this->assertSame($before, Storage::disk('public')->files($directory), 'le fichier neuf est retire quand la ligne n\'a pas pu etre ecrite');
        $this->assertSame($first, $this->workshop->refresh()->flyer_path, 'et l\'ancien visuel n\'a pas ete touche');
        Storage::disk('public')->assertExists($first);
    }

    // =====================================================================
    // B. L'apercu social de la page publique
    // =====================================================================

    public function test_without_a_flyer_the_public_page_declares_its_own_title_description_and_canonical_url(): void
    {
        $html = $this->publicPage();
        $url = route('organization.workshop.show', ['organization' => $this->organization->slug, 'workshop' => $this->workshop->slug]);

        $this->assertStringContainsString('<title>Dogfood 1450 : découvrir BouclePro · A Guild | '.config('app.name').'</title>', $html);

        $description = 'Comprendre en 45 minutes ce que BouclePro fait pour vous.';
        $this->assertSame($description, $this->meta($html, 'name', 'description'));
        $this->assertSame($description, $this->meta($html, 'property', 'og:description'));
        $this->assertSame($description, $this->meta($html, 'name', 'twitter:description'));
        $this->assertStringNotContainsString('Intelligence augmented by your peers.', $html, 'le repli generique ne parle plus a la place de l\'atelier');

        $this->assertSame($url, $this->meta($html, 'property', 'og:url'));
        $this->assertStringContainsString('<link rel="canonical" href="'.e($url).'">', $html);

        // Faute de visuel d'atelier : une image de marque LISIBLE, et la petite carte.
        $this->assertSame(asset('brand/icon-512.png'), $this->meta($html, 'property', 'og:image'));
        $this->assertSame(asset('brand/icon-512.png'), $this->meta($html, 'name', 'twitter:image'));
        $this->assertSame('summary', $this->meta($html, 'name', 'twitter:card'));
        // Le symbole 64 px reste la favicon et le logo de marque ; il ne doit
        // plus etre ce que les reseaux telechargent comme vignette.
        $this->assertStringNotContainsString('bouclepro-symbol-64.png', (string) $this->meta($html, 'property', 'og:image'));
        $this->assertStringNotContainsString('bouclepro-symbol-64.png', (string) $this->meta($html, 'name', 'twitter:image'));
        $this->assertSame('512', $this->meta($html, 'property', 'og:image:width'));
    }

    public function test_with_a_flyer_the_public_page_serves_it_as_the_social_thumbnail_in_a_large_card(): void
    {
        app(WorkshopService::class)->attachFlyer($this->workshop, UploadedFile::fake()->image('affiche.jpg', 1200, 630), $this->admin);
        $this->workshop->refresh();

        $html = $this->publicPage();
        $flyerUrl = $this->workshop->flyerUrl();

        $this->assertStringStartsWith('http', $flyerUrl, 'l\'URL du visuel est absolue');
        $this->assertSame($flyerUrl, $this->meta($html, 'property', 'og:image'));
        $this->assertSame($flyerUrl, $this->meta($html, 'name', 'twitter:image'));
        $this->assertSame('summary_large_image', $this->meta($html, 'name', 'twitter:card'));
        $this->assertSame('Flyer - Dogfood 1450 : découvrir BouclePro', $this->meta($html, 'property', 'og:image:alt'));
        $this->assertSame('image/jpeg', $this->meta($html, 'property', 'og:image:type'));
        $this->assertSame('1200', $this->meta($html, 'property', 'og:image:width'));
        $this->assertSame('630', $this->meta($html, 'property', 'og:image:height'));

        // Le visuel vit aussi DANS la page, pas seulement dans ses balises.
        $this->assertStringContainsString('data-workshop-flyer', $html);
        $this->assertStringContainsString('alt="Flyer - Dogfood 1450 : découvrir BouclePro"', $html);
    }

    public function test_the_social_description_falls_back_to_the_description_as_plain_and_bounded_text(): void
    {
        app(WorkshopService::class)->update($this->workshop, [
            'promise' => null,
            'description' => "<b>Gras</b>\n\nEt une suite ".str_repeat('tres longue ', 60),
        ], $this->admin);

        $description = $this->meta($this->publicPage(), 'property', 'og:description');

        $this->assertNotNull($description);
        $this->assertStringStartsWith('Gras Et une suite tres longue', $description, 'du texte brut, sur une seule ligne');
        $this->assertStringNotContainsString('<b>', $description);
        $this->assertLessThanOrEqual(203, mb_strlen($description), 'assez court pour un apercu social (200 caracteres + points de suspension)');
    }

    // =====================================================================
    // C. La garde de TASK-1610 n'est pas contournee
    // =====================================================================

    public function test_the_shared_layout_still_infers_no_url_from_the_request(): void
    {
        // Rendu SANS `ogUrl` ni `canonicalUrl` : les deux balises se taisent.
        $rendu = view('layouts.app', ['slot' => '', 'title' => 'Page'])->render();

        $this->assertNull($this->meta($rendu, 'property', 'og:url'), 'le layout deduit `og:url` de la requete');
        $this->assertStringNotContainsString('rel="canonical"', $rendu, 'le layout deduit la canonique de la requete');

        // Et le CODE du layout n'appelle aucune source ambiante. Les
        // commentaires Blade sont retires d'abord : celui de TASK-1610 CITE
        // l'appel interdit pour expliquer pourquoi il l'est, et une garde qui
        // se declencherait sur son propre avertissement ne mesurerait rien.
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(resource_path('views/layouts/app.blade.php')));
        foreach (['url()->current()', 'url()->full()', 'request()->url()', 'request()->fullUrl()'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $source, "le layout partage ne doit jamais appeler {$interdit}");
        }
        $this->assertStringContainsString('url()->current()', file_get_contents(resource_path('views/layouts/app.blade.php')), 'la lecon de TASK-1610 reste ecrite dans le fichier, en commentaire');
    }

    public function test_the_social_urls_name_the_canonical_domain_whatever_host_served_the_page(): void
    {
        app(WorkshopService::class)->attachFlyer($this->workshop, UploadedFile::fake()->image('affiche.jpg', 1200, 630), $this->admin);
        $this->workshop->refresh();

        $canonical = rtrim((string) config('app.url'), '/');
        $path = route('organization.workshop.show', ['organization' => $this->organization->slug, 'workshop' => $this->workshop->slug], absolute: false);

        // La MEME page, servie par un domaine de courtoisie. `route()` absolu
        // et `asset()` reprendraient cet hote : une canonique designerait alors
        // le domaine qu'elle est justement censee ecarter, et `og:image`
        // (ancre sur APP_URL) divergerait de `og:url` sur une meme page.
        $html = $this->withCredentials()->get('http://vanity.exemple.test'.$path)->assertOk()->getContent();

        foreach ([
            ['property', 'og:url'],
            ['property', 'og:image'],
            ['name', 'twitter:image'],
        ] as [$attribute, $name]) {
            $value = (string) $this->meta($html, $attribute, $name);
            $this->assertStringStartsWith($canonical.'/', $value, "{$name} doit nommer le domaine canonique");
            $this->assertStringNotContainsString('vanity.exemple.test', $value, "{$name} reprend l'hote de la requete");
        }

        $this->assertSame($canonical.$path, $this->meta($html, 'property', 'og:url'));
        $this->assertStringContainsString('<link rel="canonical" href="'.e($canonical.$path).'">', $html);
        $this->assertStringNotContainsString('vanity.exemple.test', (string) $this->meta($html, 'property', 'og:image'));

        // Sans flyer, le repli de marque suit la meme regle.
        app(WorkshopService::class)->removeFlyer($this->workshop, $this->admin);
        $bare = $this->withCredentials()->get('http://vanity.exemple.test'.$path)->assertOk()->getContent();
        $this->assertSame($canonical.'/brand/icon-512.png', $this->meta($bare, 'property', 'og:image'));
        $this->assertSame($canonical.$path, $this->meta($bare, 'property', 'og:url'));
    }

    public function test_the_canonical_url_ignores_the_attribution_parameters_of_the_visited_url(): void
    {
        $html = $this->withCredentials()
            ->get(route('organization.workshop.show', ['organization' => $this->organization->slug, 'workshop' => $this->workshop->slug]).'?shortcut=demo&utm_source=newsletter')
            ->assertOk()
            ->getContent();

        $url = route('organization.workshop.show', ['organization' => $this->organization->slug, 'workshop' => $this->workshop->slug]);

        $this->assertSame($url, $this->meta($html, 'property', 'og:url'));
        $this->assertStringNotContainsString('shortcut=demo', (string) $this->meta($html, 'property', 'og:url'), 'partager la page ne partage pas le canal d\'arrivee');
    }
}
