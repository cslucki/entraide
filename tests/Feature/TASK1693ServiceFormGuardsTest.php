<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1693, bug X — le formulaire « Proposer de l'aide » tenait trois
 * promesses qu'il ne livrait pas.
 *
 * Recette Cyril du 21/10, capture a l'appui : « Images (max 5) » avec
 * **8 fichiers** selectionnes, et les tags « AZD, QSD, DEZD, AZD, AZD ».
 *
 * ## 1. Les tags en double
 *
 * `addTag()` ne verifiait rien. Or le serveur slugifie chaque nom puis
 * `syncWithPivotValues()` sur des IDENTIFIANTS : « AZD » trois fois ne produit
 * qu'UN tag. L'ecran affichait donc trois chips pour un seul tag reel.
 *
 * ## 2. Huit fichiers pour une limite de cinq
 *
 * `.slice(0, 5)` ne tronquait que l'APERCU — l'input conservait les huit
 * fichiers et les envoyait. L'ecran en montrait 5 et en transmettait 8 : il
 * MENTAIT, et c'est ce mensonge qui provoquait les deux defauts suivants.
 *
 * ## 3. Un message qui parle de « tableau »
 *
 * « Le tableau images ne peut pas contenir plus de 5 elements » : « tableau »
 * est un mot de programmeur, « elements » n'en est pas un de photographe.
 *
 * ## 4. Et tout etait a recommencer
 *
 * Un navigateur ne peut PAS repeupler un champ fichier — c'est une garde de
 * securite, pas un defaut applicatif. La seule facon de ne pas perdre les
 * images est donc d'EMPECHER l'erreur, ce que fait la troncature reelle de la
 * selection. Les tags, eux, revenaient par `old('tags')` — doublons compris,
 * puisque `init()` ne filtrait pas davantage.
 */
class TASK1693ServiceFormGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Category $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693x',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'points_balance' => 500,
        ]);

        $this->categorie = Category::factory()->create(['organization_id' => $this->organization->id]);

        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake();
    }

    private function page(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.services.create', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'title' => 'Un titre suffisamment long',
            'description' => str_repeat('Une description de prestation detaillee. ', 5),
            'category_id' => $this->categorie->id,
            'delivery_mode' => 'remote',
            'points_cost' => 20,
        ], $extra);
    }

    // ── 1. Les tags en double ────────────────────────────────────────────────

    /** L'ecran refuse un tag deja present, et le DIT. */
    public function test_the_form_refuses_a_duplicate_tag(): void
    {
        $html = $this->page();

        // Viser la GARDE, pas le nom de la fonction : « normaliserTag » est un
        // sous-mot de « normaliserTagX », et un sabotage qui se contentait de
        // renommer passait au travers.
        $this->assertStringContainsString(
            'this.tagList.some(t => this.normaliserTag(t) === vise)',
            $html,
            'la comparaison doit exister cote ecran',
        );
        $this->assertStringContainsString('data-tag-duplicate', $html,
            'un refus silencieux laisserait croire a une panne');
        $this->assertStringContainsString(e(__('marketplace.tag_duplicate')), $html);
    }

    /**
     * La comparaison porte sur la MEME base que le serveur.
     *
     * Sinon « AZD » et « azd » passeraient la garde de l'ecran pour n'etre
     * qu'un seul tag en base — le defaut d'origine, deplace d'un cran.
     */
    public function test_the_duplicate_check_is_case_insensitive_like_the_server(): void
    {
        $this->assertStringContainsString('toLowerCase()', $this->page());
    }

    /**
     * LE fait, cote serveur : trois chips identiques ne font qu'un tag.
     *
     * C'est ce que l'ecran promettait a tort, et ce test fixe la realite qu'il
     * doit refleter.
     */
    public function test_three_identical_tags_only_ever_produced_one_tag(): void
    {
        $this->actingAs($this->membre)
            ->post(route('organization.services.store', ['organization' => $this->organization->slug]),
                $this->payload(['tags' => 'AZD,QSD,AZD,AZD']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $service = Service::where('user_id', $this->membre->id)->sole();

        $this->assertSame(2, $service->tags()->count(),
            'le serveur collapse par slug : l\'ecran ne doit pas promettre trois tags');
    }

    /** Et les doublons revenus d'une soumission refusee sont filtres. */
    public function test_duplicates_coming_back_from_a_rejected_submission_are_filtered(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('vus.has(vise)', $html,
            'init() doit dedupliquer ce que `old(\'tags\')` ramene');
    }

    // ── 2. La selection de fichiers ──────────────────────────────────────────

    /**
     * LE defaut central : la selection REELLE est tronquee, pas seulement
     * l'apercu.
     *
     * `DataTransfer` est la seule facon de reecrire le contenu d'un champ
     * fichier. Sans lui, l'ecran affichait 5 vignettes et envoyait 8 fichiers.
     */
    public function test_the_actual_file_selection_is_truncated_not_just_the_preview(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('new DataTransfer()', $html);
        $this->assertStringContainsString('$event.target.files = dt.files', $html,
            'sans cette ligne, seul l\'apercu est tronque et le formulaire ment');
    }

    /** Et l'ecran NOMME ce qui vient de se passer. */
    public function test_the_screen_says_how_many_images_were_dropped(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-images-trimmed', $html);
        $this->assertStringContainsString('ecartees', $html);
    }

    // ── 3. Le message du serveur ─────────────────────────────────────────────

    /**
     * Le serveur garde sa regle — la troncature cote ecran est une commodite,
     * pas une garde — mais il le dit desormais en francais.
     */
    public function test_the_server_still_refuses_six_images_and_says_so_plainly(): void
    {
        $images = [];
        for ($i = 0; $i < 6; $i++) {
            $images[] = UploadedFile::fake()->image("photo{$i}.jpg");
        }

        $reponse = $this->actingAs($this->membre)
            ->post(route('organization.services.store', ['organization' => $this->organization->slug]),
                $this->payload(['images' => $images]))
            ->assertSessionHasErrors('images');

        $message = session('errors')->first('images');

        $this->assertStringNotContainsString('tableau', $message,
            '« tableau » est un mot de programmeur');
        $this->assertStringNotContainsString('éléments', $message);
        $this->assertStringContainsString('images', $message);
        $this->assertStringContainsString('5', $message);
    }

    /** Cinq images passent : la limite n'a pas bouge. */
    public function test_five_images_are_still_accepted(): void
    {
        $images = [];
        for ($i = 0; $i < 5; $i++) {
            $images[] = UploadedFile::fake()->image("photo{$i}.jpg");
        }

        $this->actingAs($this->membre)
            ->post(route('organization.services.store', ['organization' => $this->organization->slug]),
                $this->payload(['images' => $images]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(5, Service::where('user_id', $this->membre->id)->sole()->images()->count());
    }

    // ── 4. Ce qui survit a une soumission refusee ────────────────────────────

    /**
     * Les tags survivent a une erreur de validation — les images, non.
     *
     * Un navigateur ne peut pas repeupler un champ fichier : c'est une garde
     * de securite, pas un defaut applicatif. Ce test FIXE cette limite plutot
     * que de la taire, et rappelle que le vrai remede est d'empecher l'erreur.
     */
    public function test_tags_survive_a_rejected_submission(): void
    {
        $this->actingAs($this->membre)
            ->post(route('organization.services.store', ['organization' => $this->organization->slug]),
                // `min:10` — « trop court » en fait exactement 10 et PASSAIT.
                // Premier jet : le test echouait sur l'absence d'erreur, pas
                // sur le repeuplement qu'il pretend mesurer.
                $this->payload(['title' => 'court', 'tags' => 'AZD,QSD']))
            ->assertSessionHasErrors('title');

        $html = $this->actingAs($this->membre)
            ->get(route('organization.services.create', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('AZD,QSD', $html,
            'les tags sont repeuples par old() — seules les images ne peuvent pas l\'etre');
    }

    // ── Les ecrans voisins, qui partageaient le meme `addTag()` ──────────────

    /**
     * Le meme defaut vivait a l'identique sur trois autres ecrans.
     *
     * Ne corriger que celui signale aurait garanti de le revoir demain depuis
     * la page de modification.
     */
    public function test_the_sibling_screens_got_the_same_guard(): void
    {
        foreach ([
            'resources/views/services/edit.blade.php',
            'resources/views/blog/edit.blade.php',
            'resources/views/admin/blog/edit.blade.php',
        ] as $vue) {
            $this->assertStringContainsString(
                'normaliserTag',
                file_get_contents(base_path($vue)),
                "{$vue} partage le meme addTag() et doit porter la meme garde",
            );
        }
    }
}
