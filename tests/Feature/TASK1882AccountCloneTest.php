<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1882 (demande Cyril, 22/11) — « Clonage de comptes ».
 *
 * Copier un compte et ses billets de blog dans une autre Organization ; le
 * compte d'origine reste tel quel. Copie volontairement restreinte.
 */
class TASK1882AccountCloneTest extends TestCase
{
    use RefreshDatabase;

    private Organization $main;

    private Organization $cible;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->main = Organization::factory()->create(['is_active' => true, 'name' => 'Main']);
        $this->cible = Organization::factory()->create(['is_active' => true, 'name' => 'Cible']);
        $this->superadmin = User::factory()->create(['organization_id' => $this->main->id, 'is_admin' => true]);
    }

    private function source(): User
    {
        Storage::disk('public')->put('avatars/photo.jpg', 'AVATAR');

        return User::factory()->create([
            'organization_id' => $this->main->id,
            'first_name' => 'Alice',
            'name' => 'Martin',
            'email' => 'alice@example.test',
            'password' => Hash::make('secret-123'),
            'bio' => 'Ma presentation.',
            'slogan' => 'Une phrase.',
            'city' => 'Lyon',
            'points_balance' => 137,
            'avatar' => 'avatars/photo.jpg',
        ]);
    }

    private function billet(User $auteur, array $attributs = []): BlogPost
    {
        $billet = new BlogPost;
        $billet->forceFill($attributs + [
            'user_id' => $auteur->id,
            'organization_id' => $auteur->organization_id,
            'title' => 'Mon article',
            'content' => '<p>Texte</p>',
            'status' => 'published',
            'published_at' => now(),
        ])->save();

        return $billet;
    }

    private function url(array $params = []): string
    {
        return route('admin.outils.clonage-de-comptes', $params);
    }

    public function test_le_compte_et_ses_billets_sont_clones_dans_l_organisation_cible(): void
    {
        $source = $this->source();
        Storage::disk('public')->put('blog/couverture.png', 'COVER');
        Storage::disk('public')->put("blog/images/{$this->main->id}/{$source->id}/img.png", 'INLINE');
        $urlInterne = Storage::disk('public')->url("blog/images/{$this->main->id}/{$source->id}/img.png");

        $boucleOrigine = Loop::factory()->create(['organization_id' => $this->main->id]);
        $avecBoucle = $this->billet($source, [
            'image' => 'blog/couverture.png',
            'content' => '<p>Intro</p><img src="'.$urlInterne.'"><img src="https://media.licdn.com/x.png">',
        ]);
        $avecBoucle->loops()->attach($boucleOrigine);
        $sansBoucle = $this->billet($source, ['title' => 'Second', 'status' => 'draft', 'published_at' => null]);

        $categorie = Category::factory()->create(['organization_id' => $this->cible->id]);
        $boucleCible = Loop::factory()->create(['organization_id' => $this->cible->id]);

        $this->actingAs($this->superadmin)
            ->get($this->url(['origine' => $this->main->id, 'compte' => $source->id, 'cible' => $this->cible->id]))
            ->assertOk()
            ->assertSee('Mon article')->assertSee('Second')
            ->assertSee('name="billets['.$avecBoucle->id.'][loop_id]"', false)
            ->assertDontSee('name="billets['.$sansBoucle->id.'][loop_id]"', false);

        $reponse = $this->actingAs($this->superadmin)->post(route('admin.outils.clonage-de-comptes.store'), [
            'origine' => $this->main->id,
            'compte' => $source->id,
            'cible' => $this->cible->id,
            'billets' => [
                $avecBoucle->id => ['category_id' => $categorie->id, 'loop_id' => $boucleCible->id],
                $sansBoucle->id => ['category_id' => ''],
            ],
        ]);

        $clone = User::withoutGlobalScopes()->where('organization_id', $this->cible->id)->where('email', 'alice@example.test')->firstOrFail();
        $reponse->assertRedirect(route('admin.users.edit', $clone));

        // Compte : identite, mot de passe, presentation, points ; photo COPIEE.
        $this->assertSame(['Alice', 'Martin', 'Ma presentation.', 'Une phrase.', 'Lyon', 137],
            [$clone->first_name, $clone->name, $clone->bio, $clone->slogan, $clone->city, $clone->points_balance]);
        $this->assertTrue(Hash::check('secret-123', $clone->password));
        $this->assertFalse((bool) $clone->is_admin);
        $this->assertNotSame($source->referral_code, $clone->referral_code);
        $this->assertNotSame('avatars/photo.jpg', $clone->avatar);
        $this->assertSame('AVATAR', Storage::disk('public')->get($clone->avatar));

        // Billets : categorie et Boucle de la cible ; couverture et image interne copiees, image externe intacte.
        $copies = BlogPost::where('user_id', $clone->id)->orderBy('created_at')->get();
        $this->assertCount(2, $copies);
        $copie = $copies->firstWhere('title', 'Mon article');
        $this->assertSame((string) $this->cible->id, (string) $copie->organization_id);
        $this->assertSame((string) $categorie->id, (string) $copie->category_id);
        $this->assertSame([(string) $boucleCible->id], $copie->loops->pluck('id')->map(fn ($id) => (string) $id)->all());
        $this->assertNotSame($avecBoucle->slug, $copie->slug);
        $this->assertNotSame('blog/couverture.png', $copie->image);
        $this->assertSame('COVER', Storage::disk('public')->get($copie->image));
        $this->assertStringNotContainsString($urlInterne, $copie->content);
        $this->assertStringContainsString("blog/images/{$this->cible->id}/{$clone->id}/", $copie->content);
        $this->assertStringContainsString('https://media.licdn.com/x.png', $copie->content);
        $this->assertSame(1, count(Storage::disk('public')->files("blog/images/{$this->cible->id}/{$clone->id}")));

        $brouillon = $copies->firstWhere('title', 'Second');
        $this->assertSame('draft', $brouillon->status);
        $this->assertNull($brouillon->category_id);

        // L'origine est intacte.
        $this->assertSame((string) $this->main->id, (string) $source->fresh()->organization_id);
        $this->assertSame(2, BlogPost::where('user_id', $source->id)->count());
        $this->assertSame([(string) $boucleOrigine->id], $avecBoucle->fresh()->loops->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    public function test_une_image_optimisee_de_l_article_est_recopiee_depuis_son_original(): void
    {
        $source = $this->source();
        $original = "blog/images/{$this->main->id}/{$source->id}/orig.png";
        Storage::disk('public')->put($original, 'ORIGINAL');
        Storage::disk('public')->put('variants/content-v1/ab/hash.webp', 'VARIANTE');

        $variante = new \App\Models\ImageVariant;
        $variante->forceFill([
            'organization_id' => $this->main->id, 'surface' => 'blog_content', 'disk' => 'public',
            'source_path' => $original, 'preset' => 'content', 'preset_version' => 1, 'status' => 'ready',
            'path' => 'variants/content-v1/ab/hash.webp', 'format' => 'webp', 'attempts' => 1,
        ])->save();

        $this->billet($source, ['content' => '<img src="'.Storage::disk('public')->url('variants/content-v1/ab/hash.webp').'">']);

        $clone = app(\App\Services\AccountCloner::class)->cloner($source, $this->cible)[0];

        $fichiers = Storage::disk('public')->files("blog/images/{$this->cible->id}/{$clone->id}");
        $this->assertCount(1, $fichiers);
        $this->assertSame('ORIGINAL', Storage::disk('public')->get($fichiers[0]));
        $this->assertStringContainsString($fichiers[0], BlogPost::where('user_id', $clone->id)->value('content'));
    }

    public function test_une_categorie_ou_une_boucle_d_une_autre_organisation_est_ignoree(): void
    {
        $source = $this->source();
        $billet = $this->billet($source);
        $billet->loops()->attach(Loop::factory()->create(['organization_id' => $this->main->id]));

        $this->actingAs($this->superadmin)->post(route('admin.outils.clonage-de-comptes.store'), [
            'origine' => $this->main->id, 'compte' => $source->id, 'cible' => $this->cible->id,
            'billets' => [$billet->id => [
                'category_id' => Category::factory()->create(['organization_id' => $this->main->id])->id,
                'loop_id' => Loop::factory()->create(['organization_id' => $this->main->id])->id,
            ]],
        ])->assertRedirect();

        $copie = BlogPost::where('organization_id', $this->cible->id)->firstOrFail();
        $this->assertNull($copie->category_id);
        $this->assertCount(0, $copie->loops);
    }

    public function test_un_email_deja_present_dans_la_cible_bloque_le_clonage(): void
    {
        $source = $this->source();
        User::factory()->create(['organization_id' => $this->cible->id, 'email' => 'ALICE@example.test']);
        $params = ['origine' => $this->main->id, 'compte' => $source->id, 'cible' => $this->cible->id];

        $this->actingAs($this->superadmin)->get($this->url($params))
            ->assertOk()->assertSee('data-admin-account-clone-email-taken', false);

        $this->actingAs($this->superadmin)->post(route('admin.outils.clonage-de-comptes.store'), $params)
            ->assertRedirect($this->url($params))
            ->assertSessionHas('error');

        $this->assertSame(1, User::withoutGlobalScopes()->where('organization_id', $this->cible->id)->count());
    }

    public function test_l_outil_est_reserve_au_superadmin_et_present_dans_le_menu(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->main->id]);

        $this->actingAs($membre)->get($this->url())->assertForbidden();
        $this->actingAs($membre)->post(route('admin.outils.clonage-de-comptes.store'), [])->assertForbidden();

        $this->actingAs($this->superadmin)->get($this->url())
            ->assertOk()
            ->assertSee('Clonage de comptes');
    }
}
