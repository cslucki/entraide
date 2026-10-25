<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1697 — les SEPT derniers liens de la fiche profil restent dans le
 * locataire.
 *
 * ## Le defaut, et c'est son TROISIEME passage sur ce seul fichier
 *
 * Un lien construit sans prefixe d'Organization fait resoudre a
 * `ResolveUrlOrganization` l'Organization PAR DEFAUT de la plateforme : depuis
 * `/org/ofsh/profile/...`, on basculait sur un AUTRE locataire sans s'en
 * apercevoir.
 *
 * Deja corrige deux fois ici : le bug AA de TASK-1693 (« Ecrire a cette
 * personne ») et la fleche de l'annuaire (TASK-1697 correctif 13, trouvee par
 * Cyril en recette). Les sept restants suivent.
 *
 * Le septieme n'est pas un lien mais un `<form method="POST">` — le
 * signalement d'un membre. C'etait une **ECRITURE** qui partait au mauvais
 * endroit.
 *
 * ## Pourquoi les assertions negatives portent sur l'URL ABSOLUE
 *
 * `/profile/edit` est un SOUS-TEXTE de `/org/ofsh/profile/edit` : une garde
 * ecrite sur le chemin relatif serait rouge quoi qu'il arrive. Les URL
 * absolues, elles, divergent des l'hote (`…laravel/profile/edit` contre
 * `…laravel/org/ofsh/…`) : la garde est donc exacte.
 */
class TASK1697ProfileTenantLinksTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1697t',
            'is_active' => true,
            'ai_profiles_enabled' => true,
        ]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    public function test_les_liens_du_proprietaire_restent_dans_le_locataire(): void
    {
        MemberAiProfile::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'status' => MemberAiProfile::STATUS_PUBLISHED,
        ]);

        $html = $this->rendu($this->membre);

        // Assertion BORNEE aux liens DE CETTE VUE.
        //
        // Le mobilier de navigation partage (`layouts/navigation.blade.php`,
        // `app-side-nav`, `mobile-topbar`) appelle lui aussi
        // `route('profile.edit')`, SANS garde d'Organization — son entree
        // « Parametres » quitte donc le locataire sur TOUTE page du produit.
        // C'est un defaut reel, signale a Cyril, hors du perimetre de ce
        // correctif : il vit dans le mobilier, pas dans la fiche profil.
        //
        // Une garde ecrite sur la page entiere serait donc rouge quoi qu'il
        // arrive. Elle porte sur les deux balises marquees.
        preg_match_all('/<a[^>]*data-profile-edit[^>]*>/', $html, $trouvees);
        $this->assertCount(2, $trouvees[0], 'Les deux liens « Modifier » de la fiche profil ne sont pas tous deux rendus.');

        foreach ($trouvees[0] as $balise) {
            $this->assertStringContainsString(e($this->scopee('profile.edit')), $balise);
            $this->assertStringNotContainsString(e(route('profile.edit')).'"', $balise);
        }

        $this->assertStringContainsString(e($this->scopee('agent-ia.interactions')), $html);
        $this->assertStringNotContainsString(e(route('agent-ia.interactions')), $html);
    }

    public function test_le_lien_d_une_proposition_reste_dans_le_locataire(): void
    {
        $service = Service::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie(),
            'status' => 'active',
        ]);

        $html = $this->rendu($this->visiteur());

        $this->assertStringContainsString(e($this->scopee('services.show', ['service' => $service])), $html);
        $this->assertStringNotContainsString(e(route('services.show', $service)), $html);
    }

    public function test_le_lien_d_une_demande_reste_dans_le_locataire(): void
    {
        $demande = ServiceRequest::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie(),
            'status' => 'open',
        ]);

        $html = $this->rendu($this->visiteur());

        $this->assertStringContainsString(e($this->scopee('requests.show', ['request' => $demande])), $html);
        $this->assertStringNotContainsString(e(route('requests.show', $demande)), $html);
    }

    public function test_le_lien_d_un_article_reste_dans_le_locataire(): void
    {
        $article = BlogPost::query()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article de mesure',
            'slug' => 'article-de-mesure-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $html = $this->rendu($this->visiteur());

        $this->assertStringContainsString(e($this->scopee('blog.show', ['post' => $article])), $html);
        $this->assertStringNotContainsString(e(route('blog.show', $article)), $html);
    }

    /** Le septieme : une ECRITURE, pas un lien. */
    public function test_le_formulaire_de_signalement_poste_dans_le_locataire(): void
    {
        $html = $this->rendu($this->visiteur());

        $this->assertStringContainsString(e($this->scopee('reports.user', ['user' => $this->membre])), $html);
        $this->assertStringNotContainsString(e(route('reports.user', $this->membre)), $html);
    }

    /**
     * Temoin : hors de toute surface d'Organization, le repli non prefixe
     * reste celui qui est servi. Le helper ne doit pas inventer un prefixe.
     */
    public function test_hors_organization_le_repli_non_prefixe_est_servi(): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('profile.show', $this->membre->profileRouteKey()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(route('profile.edit')), $html);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function categorie(): string
    {
        return Category::factory()->create(['organization_id' => $this->organization->id])->id;
    }

    private function visiteur(): User
    {
        return User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    private function scopee(string $route, array $params = []): string
    {
        return route('organization.'.$route, ['organization' => $this->organization->slug] + $params);
    }

    private function rendu(User $qui): string
    {
        return $this->actingAs($qui)
            ->get(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $this->membre->profileRouteKey(),
            ]))
            ->assertOk()
            ->getContent();
    }
}
