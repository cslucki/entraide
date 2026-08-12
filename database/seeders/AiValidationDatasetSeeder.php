<?php

namespace Database\Seeders;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\AiValidation\AiValidationDatabaseGuard;
use Illuminate\Database\Seeder;

/**
 * TASK-1201 — dataset déterministe pour l'environnement de validation IA.
 *
 * Deux Organizations sentinelles A/B : chaque enregistrement porte le
 * marqueur "SENTINEL-A"/"SENTINEL-B" dans un champ lisible, pour qu'une
 * fuite cross-tenant soit détectable par une simple recherche texte.
 * Comptes déterministes (jamais fake()->email()), mot de passe unique
 * "password" (convention UserSeeder/QaAccountsSeeder).
 *
 * Prérequis d'usage : appelé après `migrate:fresh` sur une base vide — pas
 * d'idempotence recherchée (`firstOrCreate`), la simplicité prime puisque le
 * flux officiel est toujours reset -> migrate -> seed.
 *
 * NE JAMAIS exécuter contre `bouclepro`/`bouclepro_test` : `run()` (le seul
 * point d'entrée console) appelle `AiValidationDatabaseGuard::assertSafe()`
 * en première ligne. `compose()` fait le travail réel et n'appelle PAS la
 * garde lui-même : c'est le point d'entrée dédié aux tests, qui l'exercent
 * sous SQLite `:memory:` — la garde bloquerait toute connexion non-pgsql
 * exactement `bouclepro_ai_validation`, ce qui est son rôle en usage réel
 * mais empêcherait de tester la logique de composition en isolation.
 */
class AiValidationDatasetSeeder extends Seeder
{
    public function run(): void
    {
        AiValidationDatabaseGuard::assertSafe();

        [$orgA, $orgB] = $this->compose();

        $this->command?->info("Organization A (SENTINEL-A) : {$orgA->id}");
        $this->command?->info("Organization B (SENTINEL-B) : {$orgB->id}");
    }

    /**
     * @return array{0: Organization, 1: Organization}
     */
    public function compose(): array
    {
        $orgA = $this->seedOrganization('ai-validation-org-a', 'AI Validation SENTINEL-A', 'SENTINEL-A');
        $orgB = $this->seedOrganization('ai-validation-org-b', 'AI Validation SENTINEL-B', 'SENTINEL-B');

        return [$orgA, $orgB];
    }

    private function seedOrganization(string $slug, string $name, string $sentinel): Organization
    {
        $organization = Organization::factory()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'is_public' => false,
        ]);

        $admin = $this->seedUser($organization, "admin@{$slug}.ai-validation.test", $sentinel, 'Admin', isAdmin: true);
        $member1 = $this->seedUser($organization, "member1@{$slug}.ai-validation.test", $sentinel, 'Member One');
        $member2 = $this->seedUser($organization, "member2@{$slug}.ai-validation.test", $sentinel, 'Member Two');

        $category = Category::factory()->create([
            'organization_id' => $organization->id,
            'name_b2c' => "{$sentinel} Category",
            'name_b2b' => "{$sentinel} Category",
            'slug' => "{$slug}-category",
        ]);

        $loopMain = Loop::factory()->create([
            'organization_id' => $organization->id,
            'name' => "{$sentinel} Loop Principale",
            'slug' => "{$slug}-loop-principale",
            'description' => "{$sentinel} Loop de validation IA — contexte principal.",
            'created_by' => $admin->id,
        ]);

        Loop::factory()->create([
            'organization_id' => $organization->id,
            'name' => "{$sentinel} Loop Secondaire",
            'slug' => "{$slug}-loop-secondaire",
            'description' => "{$sentinel} Loop de validation IA — contexte secondaire.",
            'created_by' => $member1->id,
        ]);

        LoopMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'loop_id' => $loopMain->id,
            'user_id' => $admin->id,
        ]);
        LoopMember::factory()->create([
            'organization_id' => $organization->id,
            'loop_id' => $loopMain->id,
            'user_id' => $member1->id,
        ]);
        LoopMember::factory()->create([
            'organization_id' => $organization->id,
            'loop_id' => $loopMain->id,
            'user_id' => $member2->id,
        ]);

        $loopMain->messages()->create([
            'sender_id' => $member1->id,
            'body' => "{$sentinel} message de bienvenue dans la Loop principale.",
            'type' => 'user',
        ]);
        $loopMain->messages()->create([
            'sender_id' => $member2->id,
            'body' => "{$sentinel} réponse au message de bienvenue.",
            'type' => 'user',
        ]);

        ServiceRequest::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $member1->id,
            'category_id' => $category->id,
            'title' => "{$sentinel} demande d'aide de validation",
            'description' => "{$sentinel} description de la demande d'aide utilisée pour la validation IA.",
        ]);

        Service::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $member2->id,
            'category_id' => $category->id,
            'title' => "{$sentinel} proposition d'aide de validation",
            'description' => "{$sentinel} description de la proposition d'aide utilisée pour la validation IA.",
        ]);

        $dossier = Dossier::factory()->create([
            'organization_id' => $organization->id,
            'owner_id' => $admin->id,
            'name' => "{$sentinel} Dossier de validation",
        ]);

        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'title' => "{$sentinel} Article de validation IA",
            'slug' => "{$slug}-article-validation",
            'content' => "<p>{$sentinel} contenu indexable pour la recherche sémantique Dossiers. ".
                'Ce paragraphe existe pour produire un texte suffisamment long et distinctif '.
                "afin qu'une fuite cross-tenant soit immédiatement détectable par une recherche ".
                "sur le marqueur {$sentinel}.</p>",
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $admin->id,
            'position' => 1,
        ]);

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'member_profile_summary' => "{$sentinel} profil IA de validation.",
            'service_scope' => "{$sentinel} périmètre de service.",
            'skills' => [$sentinel],
        ]);

        return $organization;
    }

    private function seedUser(Organization $organization, string $email, string $sentinel, string $label, bool $isAdmin = false): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'email' => $email,
            'name' => "{$sentinel} {$label}",
            'first_name' => $sentinel,
            'is_admin' => $isAdmin,
        ]);
    }
}
