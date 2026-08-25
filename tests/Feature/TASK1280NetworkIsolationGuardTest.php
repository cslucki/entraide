<?php

namespace Tests\Feature;

use App\Models\AiProviderInvocation;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1280 (P0) — preuve que la garde d'isolation reseau MORD.
 *
 * REGLE ABSOLUE : la preuve se fait par l'ECHEC ATTENDU d'une requete non
 * doublee, jamais par un appel reel. Aucun test de ce fichier ne doit
 * atteindre le reseau — c'est precisement ce qu'il demontre.
 *
 * Incident de reference : T3BlogEditorAiAdminTest posait
 * `Http::fake(['api.openai.com/*' => ...])` sur un banc configure OpenRouter.
 * Le motif ne matchait jamais et `Http::fake()` avec motif laisse partir vers
 * le reseau reel ce qu'il n'apparie pas : ~13 generations reellement
 * facturees. La garde (`Http::preventStrayRequests()` dans TestCase::setUp)
 * transforme ce scenario en echec bruyant et local.
 */
class TASK1280NetworkIsolationGuardTest extends TestCase
{
    public function test_unfaked_provider_request_throws_before_reaching_network(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'x']);
    }

    public function test_incident_motif_partial_fake_does_not_let_unmatched_request_out(): void
    {
        // Motif EXACT de l'incident : doublure posee sur OpenAI seulement,
        // requete a destination d'OpenRouter. Avant TASK-1280 elle partait
        // reellement ; desormais elle jette avant tout reseau.
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => []]),
        ]);

        $this->expectException(StrayRequestException::class);

        Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'x']);
    }

    public function test_explicitly_doubled_request_still_works(): void
    {
        // La garde n'empeche pas la voie legitime : un test qui veut un
        // provider le double explicitement, et la doublure repond.
        Http::fake([
            'api.openai.com/*' => Http::response(['ok' => true]),
        ]);

        $response = Http::post('https://api.openai.com/v1/chat/completions', []);

        $this->assertTrue($response->json('ok'));
        Http::assertSentCount(1);
    }

    public function test_incident_scenario_end_to_end_fails_loudly_instead_of_billing(): void
    {
        // Rejoue l'incident a l'identique, bout en bout : banc configure
        // OpenRouter, doublure posee sur api.openai.com uniquement, appel du
        // meme endpoint que T3. Le contrat de l'endpoint est une degradation
        // gracieuse (le brouillon est cree avant l'appel IA, la reponse est
        // 200 avec une cle `error`). Attendu : l'erreur "without a matching
        // fake" fait surface, la tentative est journalisee FAILED, et AUCUNE
        // generation n'aboutit — la ou, avant TASK-1280, la requete partait
        // reellement vers openrouter.ai et etait facturee.
        config([
            'ai.default_provider' => 'openrouter',
            'ai.default_model' => null,
            'ai.openrouter.api_key' => 'test-key',
            'ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'ai.openrouter.model' => 'mistralai/ministral-3b-2512',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'jamais servi']]],
            ]),
        ]);

        $organization = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'is_admin' => false,
        ]);
        $category = Category::create([
            'name_b2c' => 'Cat 1280',
            'name_b2b' => 'Cat 1280 B2B',
            'slug' => 'cat-1280-'.uniqid(),
            'color' => '#6366f1',
            'organization_id' => $organization->id,
        ]);

        $response = $this->actingAs($user)->post(route('blog.ai-generate'), [
            'title' => 'Titre incident',
            'summary' => 'Resume incident',
            'category_id' => $category->id,
        ]);

        $this->assertStringContainsString(
            'without a matching fake',
            (string) $response->json('error'),
        );

        // La tentative est journalisee FAILED dans la base de test (jetable),
        // avec la StrayRequestException pour cause — preuve que la garde a
        // intercepte AVANT le reseau. Rien n'est jamais journalise SUCCESS.
        $this->assertSame(
            1,
            AiProviderInvocation::query()
                ->where('status', AiProviderInvocation::STATUS_FAILED)
                ->where('failure_reason', StrayRequestException::class)
                ->count(),
        );
        $this->assertSame(
            0,
            AiProviderInvocation::query()
                ->where('status', AiProviderInvocation::STATUS_SUCCESS)
                ->count(),
        );
    }
}
