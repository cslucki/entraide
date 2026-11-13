<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierFileIndexer;
use App\Services\Dossiers\OrganizationRagOverview;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiRefusedException;
use App\Support\AiLab\LabPreconditions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1819 — limitation du DEBIT des appels IA factures.
 *
 * ## Le trou, avant cette TASK
 *
 * `AiEconomicGuard` bornait un CUMUL mensuel (budget $, quota d'inconnus,
 * credit) : sous le plafond, rien ne bornait la VITESSE. 38 surfaces (11
 * routes HTTP, 13 actions Livewire — dont `AiShell`, montee sur TOUTES les
 * pages —, l'ingestion documentaire) pouvaient etre appelees en rafale.
 *
 * ## Pourquoi ces tests touchent SEULEMENT `AiEconomicGuard`
 *
 * La matrice TASK-1282/1283 (`AiEconomicAuthorityIsolationTest`, toujours
 * verte) prouve qu'AUCUN appel provider joignable en production ne contourne
 * `AiEconomicGuard`. Le debit est donc ajoute UNE fois, dans `authorize()` et
 * `authorizeEmbeddings()` — les 38 surfaces en heritent sans qu'aucune d'elles
 * soit touchee. Le test §F (Livewire) prouve ce raisonnement sur UNE surface
 * reelle plutot que de l'affirmer.
 *
 * ## Le piege que MASTER a signale, et comment il est evite
 *
 * `authorize()` ne doit PAS empiler deux `RateLimiter::attempt()` (l'un pour
 * l'utilisateur, l'autre pour l'Organization) : si le premier consomme et que
 * le second refuse, un cran du quota utilisateur est brule pour un appel qui
 * n'a jamais eu lieu. Le code VERIFIE les deux compteurs (lecture pure, prouve
 * sur l'implementation Laravel) avant d'en INCREMENTER un seul, et seulement
 * si le verdict final — apres TOUTES les gardes — est `allow()`. §D prouve
 * que ce pattern tient : aucune consommation sur un refus, quelle que soit la
 * garde qui refuse.
 */
class TASK1819RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private AiEconomicGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new AiEconomicGuard;

        config([
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'unpriced' => ['*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => false]],
            ],
            // Seuils ARTIFICIELLEMENT bas : des tests lisibles, pas 10 boucles de 10.
            'ai.rate_limits.per_user_per_minute' => 3,
            'ai.rate_limits.per_org_per_minute' => 5,
            'ai.rate_limits.embeddings_per_org_per_minute' => 2,
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function organization(): Organization
    {
        return Organization::factory()->create();
    }

    private function member(Organization $organization): User
    {
        return User::factory()->create(['organization_id' => $organization->id]);
    }

    private function authorize(Organization $organization, ?User $user = null): \App\Support\Ai\AiEconomicVerdict
    {
        return $this->guard->authorize($organization, 'test.process', 'openrouter', 'unpriced', 1000.0, 1000, $user);
    }

    // ═══════════════════════════════════════════════════════════════════
    // A. Usage normal non bloque, puis refus au seuil, avec delai
    // ═══════════════════════════════════════════════════════════════════

    public function test_usage_normal_passe_puis_le_seuil_refuse_avec_un_delai(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        foreach (range(1, 3) as $tentative) {
            $verdict = $this->authorize($organization, $user);
            $this->assertTrue($verdict->allowed, "L'appel {$tentative}/3 doit passer.");
        }

        $refus = $this->authorize($organization, $user);

        $this->assertFalse($refus->allowed);
        $this->assertSame(AiEconomicGuard::REASON_RATE_LIMITED, $refus->reason);
        $this->assertGreaterThan(0, $refus->retryAfterSeconds);
        $this->assertLessThanOrEqual(60, $refus->retryAfterSeconds);
    }

    // ═══════════════════════════════════════════════════════════════════
    // B. Isolation multi-tenant — OBLIGATOIRE
    // ═══════════════════════════════════════════════════════════════════

    public function test_un_refus_de_debit_dans_une_organization_ne_touche_jamais_une_autre(): void
    {
        $organizationA = $this->organization();
        $userA = $this->member($organizationA);
        $organizationB = $this->organization();
        $userB = $this->member($organizationB);

        // A epuise son plafond ORGANIZATION (5), par deux utilisateurs.
        $autreUserA = $this->member($organizationA);
        foreach (range(1, 3) as $_) {
            $this->assertTrue($this->authorize($organizationA, $userA)->allowed);
        }
        foreach (range(1, 2) as $_) {
            $this->assertTrue($this->authorize($organizationA, $autreUserA)->allowed);
        }
        $this->assertSame(
            AiEconomicGuard::REASON_RATE_LIMITED,
            $this->authorize($organizationA, $userA)->reason,
            "L'Organization A doit etre a son plafond (5/5)."
        );

        // B, totalement etrangere, passe comme si rien ne s'etait passe.
        $verdictB = $this->authorize($organizationB, $userB);
        $this->assertTrue($verdictB->allowed, 'Organization B ne doit JAMAIS etre affectee par le debit de A.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // C. Deux utilisateurs de la meme Organization ne se bloquent pas
    // ═══════════════════════════════════════════════════════════════════

    public function test_deux_utilisateurs_de_la_meme_organization_ne_se_bloquent_pas(): void
    {
        $organization = $this->organization();
        $alice = $this->member($organization);
        $bob = $this->member($organization);

        // Alice epuise SON plafond utilisateur (3) — reste sous le plafond org (5).
        foreach (range(1, 3) as $_) {
            $this->assertTrue($this->authorize($organization, $alice)->allowed);
        }
        $refusAlice = $this->authorize($organization, $alice);
        $this->assertSame(AiEconomicGuard::REASON_RATE_LIMITED, $refusAlice->reason);

        // Bob, meme Organization, passe encore : compteur UTILISATEUR distinct.
        $verdictBob = $this->authorize($organization, $bob);
        $this->assertTrue($verdictBob->allowed, 'Le compteur utilisateur est distinct de celui de ses collegues.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // D. Limites utilisateur ET Organization combinees — aucune consommation injustifiee
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_plafond_organization_refuse_meme_quand_l_utilisateur_est_sous_son_propre_plafond(): void
    {
        $organization = $this->organization();
        $alice = $this->member($organization);
        $bob = $this->member($organization);
        $carla = $this->member($organization);

        // Organization au plafond (5) par TROIS utilisateurs differents, chacun
        // sous SON propre plafond (3) — seul le compteur org est epuise.
        $this->assertTrue($this->authorize($organization, $alice)->allowed);
        $this->assertTrue($this->authorize($organization, $alice)->allowed);
        $this->assertTrue($this->authorize($organization, $bob)->allowed);
        $this->assertTrue($this->authorize($organization, $bob)->allowed);
        $this->assertTrue($this->authorize($organization, $carla)->allowed);

        $refus = $this->authorize($organization, $carla);

        $this->assertFalse($refus->allowed);
        $this->assertSame(AiEconomicGuard::REASON_RATE_LIMITED, $refus->reason);
    }

    /**
     * Le coeur de l'avertissement MASTER : un refus (par N'IMPORTE QUELLE
     * garde plus loin dans `authorize()`) ne doit JAMAIS avoir consomme le
     * debit. Sinon un utilisateur dont le budget mensuel est epuise verrait
     * SON compteur de rafale bruler pour des appels qui n'ont jamais eu lieu.
     */
    public function test_un_refus_economique_plus_loin_dans_la_garde_ne_consomme_aucun_credit_de_debit(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);
        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id, 'monthly_budget_usd' => 0.0]);

        foreach (range(1, 5) as $_) {
            $verdict = $this->authorize($organization->fresh(), $user);
            $this->assertFalse($verdict->allowed);
            $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $verdict->reason);
        }

        $userKey = 'ai-debit:user:'.$organization->id.':'.$user->id;
        $orgKey = 'ai-debit:org:'.$organization->id;

        $this->assertSame(0, RateLimiter::attempts($userKey), 'Un refus budgetaire ne doit JAMAIS consommer le debit utilisateur.');
        $this->assertSame(0, RateLimiter::attempts($orgKey), 'Un refus budgetaire ne doit JAMAIS consommer le debit Organization.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // E. Comportement sur sous appels repetes — proxy de la concurrence
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Une vraie simultaneite OS (deux requetes PHP-FPM) ne se simule pas en
     * PHPUnit mono-thread : `DatabaseStore::increment()` (verifie dans le
     * vendor) l'enferme dans une transaction `lockForUpdate()`, serialisee au
     * niveau SQL — c'est la garantie du FRAMEWORK, pas quelque chose que ce
     * test reproduit. Ce qui EST testable ici, et qui est la vraie faille
     * qu'une mauvaise implementation introduirait : que N appels produisent
     * EXACTEMENT N increments, jamais plus — pas de double-hit, pas de hit
     * sur verification seule.
     */
    public function test_n_appels_autorises_produisent_exactement_n_increments_jamais_plus(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        foreach (range(1, 3) as $_) {
            $this->authorize($organization, $user);
        }
        // 2 refus supplementaires, au-dela du plafond.
        $this->authorize($organization, $user);
        $this->authorize($organization, $user);

        $userKey = 'ai-debit:user:'.$organization->id.':'.$user->id;
        $this->assertSame(3, RateLimiter::attempts($userKey), 'Exactement 3 increments pour 3 appels AUTORISES — les refus ne comptent pas.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // F. Refus de debit = zero appel fournisseur (symetrie des refus existants)
    // ═══════════════════════════════════════════════════════════════════

    public function test_un_refus_de_debit_n_appelle_jamais_un_fournisseur_ni_le_ledger(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        foreach (range(1, 3) as $_) {
            $this->authorize($organization, $user);
        }

        $ledgerAvant = \App\Models\AiProviderInvocation::query()->count();

        $refus = $this->authorize($organization, $user);

        $this->assertFalse($refus->allowed);
        $this->assertSame($ledgerAvant, \App\Models\AiProviderInvocation::query()->count(), 'Aucune ligne de ledger sur un refus de debit.');
        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════════════════════════════
    // G. Absence de contournement entre routes/process : la cle ne depend PAS du process
    // ═══════════════════════════════════════════════════════════════════

    public function test_epuiser_le_debit_sur_un_process_refuse_aussi_un_process_different(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        foreach (range(1, 3) as $_) {
            $verdict = $this->guard->authorize($organization, 'requests.ai_formulate', 'openrouter', 'unpriced', 1000.0, 1000, $user);
            $this->assertTrue($verdict->allowed);
        }

        // Une AUTRE surface (services.ai_formulate, loops.ai, peu importe) :
        // la cle de debit est (organization, user), independante du process.
        $verdictAutreSurface = $this->guard->authorize($organization, 'services.ai_formulate', 'openrouter', 'unpriced', 1000.0, 1000, $user);

        $this->assertFalse($verdictAutreSurface->allowed, 'Le debit est epuise par SURFACE, pas par process : aucun contournement en changeant de route.');
        $this->assertSame(AiEconomicGuard::REASON_RATE_LIMITED, $verdictAutreSurface->reason);
    }

    // ═══════════════════════════════════════════════════════════════════
    // H. GuestShell / bac a sable : AUCUNE limitation supplementaire ($user = null)
    // ═══════════════════════════════════════════════════════════════════

    public function test_un_appel_sans_utilisateur_n_est_jamais_limite_par_le_nouveau_debit(): void
    {
        $organization = $this->organization();

        // 10 appels SANS utilisateur (doctrine : GuestShellGate et
        // OrganizationDoctrineSandbox appellent authorize() ainsi) — largement
        // au-dela des plafonds configures (3 et 5) — doivent tous passer : ils
        // gardent UNIQUEMENT leurs protections existantes, jamais celle-ci.
        foreach (range(1, 10) as $tentative) {
            $verdict = $this->authorize($organization, null);
            $this->assertTrue($verdict->allowed, "L'appel sans utilisateur {$tentative}/10 ne doit JAMAIS etre limite par le nouveau debit.");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // I. Couverture d'une action Livewire REELLE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * `LoopAiSummaryCard::generate()` appelle `ChatLoopAiService::summarize()`,
     * qui passe le REQUERANT a `AiEconomicGuard::authorize()` — exactement le
     * chemin que ce correctif couvre. Le debit est epuise par un AUTRE appel
     * (simulant une autre surface, ex. le Shell IA) ; la carte doit refuser
     * SANS defaut d'affichage Livewire et sans rien perdre : le composant
     * garde son etat (`loop`), `errorCode` porte le code stable, et aucune
     * exception ne remonte au test.
     */
    public function test_une_carte_livewire_reelle_refuse_proprement_quand_le_debit_est_epuise(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1819',
        ]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $loop = Loop::factory()->create(['organization_id' => $organization->id, 'created_by' => $user->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $user->id, 'status' => 'active']);
        // `loopHasEnoughContent()` exige 30 mots mini (`ai.chatloop.min_summary_words`).
        LoopMessage::factory()->create([
            'loop_id' => $loop->id,
            'sender_id' => $user->id,
            'body' => implode(' ', array_fill(0, 40, 'mot')),
        ]);

        // Epuise le plafond UTILISATEUR (3) par des appels simulant UNE AUTRE
        // surface — la carte n'en sait rien, elle n'a encore rien tente.
        foreach (range(1, 3) as $_) {
            $this->guard->authorize($organization, 'autre.surface', 'openai', 'gpt-4o-mini', 1000.0, 1000, $user);
        }

        $this->actingAs($user);

        Livewire::test(\App\Livewire\LoopAiSummaryCard::class, ['loop' => $loop])
            ->call('generate')
            ->assertSet('errorCode', AiRefusedException::CODE_RATE_LIMITED)
            ->assertSet('loop.id', $loop->id); // le composant garde son etat, pas de crash Livewire

        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════════════════════════════
    // J. Embeddings — debit, integrite des fichiers, zero appel sur refus
    // ═══════════════════════════════════════════════════════════════════

    protected function setUpEmbeddingsFixture(): array
    {
        Storage::fake('dossier_files');

        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.driver', 'openai');
        config()->set('ai.providers.openai.key', 'platform-should-not-be-used');
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', 8);

        $organization = Organization::factory()->create();
        config()->set(
            'ai.dossiers.semantic_search.organization_ids',
            array_unique(array_merge(config('ai.dossiers.semantic_search.organization_ids', []), [$organization->id])),
        );

        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => 'TASK1819 dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $path = 'dossier-files/'.$dossier->id.'/note.txt';
        $content = 'Le serveur B accueille 42 utilisateurs simultanes.';
        Storage::disk('dossier_files')->put($path, $content);

        // TASK-1819 — ORDRE DELIBERE, meme piege que TASK1216FileIngestionTest :
        // `DossierFileObserver::created()` declenche `IndexDossierFileChunks`
        // `->afterCommit()`, et le pilote `sync` l'honore DANS CE TEST (meme
        // sous RefreshDatabase). Si le credential existe DEJA a la creation du
        // fichier, cette indexation IMPLICITE part reellement — contre un
        // fournisseur pas encore double — et fait echouer la fixture elle-meme,
        // avant la moindre assertion. Creer le fichier SANS credential d'abord
        // fait retourner `resolveEmbeddingInstance() === null`, donc un retrait
        // propre sans appel ; le credential arrive APRES, pour l'appel EXPLICITE
        // de chaque test (avec le fournisseur deja double a ce moment-la).
        $file = DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => $path,
            'original_name' => 'note.txt',
            'display_name' => 'note.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'source' => 'upload',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1819-embeddings',
        ]);

        // L'indexation IMPLICITE ci-dessus a tout de meme appele
        // `authorizeEmbeddings()` — qui AUTORISE (le credential n'est verifie
        // qu'APRES, par `resolveEmbeddingInstance()`) et consomme donc 1 cran
        // du debit de CETTE Organization, avant que `resolveEmbeddingInstance()`
        // ne retourne null et n'arrete l'ingestion. C'est un comportement
        // REEL et sans danger (un essai qui n'aboutit a aucun appel compte
        // quand meme comme un essai) — mais c'est un bruit de montage, pas
        // une mesure du test. On remet le compteur a zero pour que chaque
        // test mesure SA PROPRE consommation, a partir d'une fixture neutre.
        RateLimiter::clear('ai-debit:embeddings:org:'.$organization->id);

        return [$organization, $dossier, $file, $path];
    }

    private function fakeEmbeddings(): void
    {
        Embeddings::fake(function (EmbeddingsPrompt $prompt): EmbeddingsResponse {
            $vectors = array_map(fn (): array => array_fill(0, 8, 0.1), $prompt->inputs);

            return new EmbeddingsResponse($vectors, count($prompt->inputs) * 3, new Meta($prompt->provider->name(), $prompt->model));
        })->preventStrayEmbeddings();
    }

    public function test_un_depassement_de_debit_embeddings_est_refuse_sans_aucun_appel(): void
    {
        [$organization] = $this->setUpEmbeddingsFixture();
        $this->fakeEmbeddings();

        config(['ai.rate_limits.embeddings_per_org_per_minute' => 2]);

        foreach (range(1, 2) as $_) {
            $this->assertTrue($this->guard->authorizeEmbeddings($organization)->allowed);
        }

        $refus = $this->guard->authorizeEmbeddings($organization);

        $this->assertFalse($refus->allowed);
        $this->assertSame(AiEconomicGuard::REASON_RATE_LIMITED, $refus->reason);
        $this->assertGreaterThan(0, $refus->retryAfterSeconds);
    }

    /**
     * Le point TECHNIQUE que MASTER a demande d'instruire : le comportement
     * de l'ingestion REELLE (`DossierFileIndexer`, le chemin de l'upload
     * sous `QUEUE_CONNECTION=sync`) sur un refus de debit.
     *
     * Verifie ICI, sur le vrai indexeur, PAS seulement sur `authorizeEmbeddings()`
     * en isolation : le fichier stocke n'est jamais touche, aucune exception ne
     * remonte (donc aucune 500 sur l'upload), aucun chunk n'est cree (le
     * document n'est donc jamais "indexe" a tort), et aucun appel provider ne
     * part.
     */
    public function test_l_indexeur_reel_de_fichier_refuse_sans_perte_ni_exception_ni_appel(): void
    {
        [$organization, $dossier, $file, $path] = $this->setUpEmbeddingsFixture();
        $this->fakeEmbeddings();

        config(['ai.rate_limits.embeddings_per_org_per_minute' => 1]);
        // Epuise le debit par un AUTRE appel (simule une recherche documentaire
        // qui a embedde une question juste avant).
        $this->guard->authorizeEmbeddings($organization);

        $ledgerAvant = \App\Models\AiProviderInvocation::query()->count();

        $retour = app(DossierFileIndexer::class)->synchronize(
            (string) $organization->id,
            (string) $dossier->id,
            (string) $file->id,
        );

        // Aucun chunk : le document n'est pas declare indexe a tort.
        $this->assertSame(0, $retour);
        $this->assertSame(0, DossierChunk::query()->count());
        // Le fichier STOCKE n'est jamais touche par un refus de debit.
        $this->assertTrue(Storage::disk('dossier_files')->exists($path));
        $this->assertNotNull(DossierFile::query()->find($file->id), 'La ligne DossierFile doit survivre a un refus de debit.');
        // Aucun appel provider, aucune ligne fictive au ledger.
        $this->assertSame($ledgerAvant, \App\Models\AiProviderInvocation::query()->count());
        Http::assertNothingSent();
    }

    /**
     * Sabotage inverse : sous le plafond, l'ingestion reelle fonctionne
     * toujours exactement comme avant T1819 — le correctif n'a pas durci un
     * chemin qui doit rester ouvert.
     */
    public function test_l_indexeur_reel_de_fichier_fonctionne_toujours_sous_le_plafond(): void
    {
        [$organization, $dossier, $file] = $this->setUpEmbeddingsFixture();
        $this->fakeEmbeddings();

        $retour = app(DossierFileIndexer::class)->synchronize(
            (string) $organization->id,
            (string) $dossier->id,
            (string) $file->id,
        );

        $this->assertGreaterThan(0, $retour);
        $this->assertGreaterThan(0, DossierChunk::query()->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // K. Les lecteurs DIAGNOSTIQUES restent des lectures pures
    // ═══════════════════════════════════════════════════════════════════

    /**
     * `OrganizationRagOverview::indexingAvailability()` est une page d'ETAT,
     * pas une tentative d'ingestion. Avant T1819 elle ne consommait rien ;
     * apres, elle ne doit TOUJOURS rien consommer — sinon ouvrir un tableau de
     * bord epuiserait le quota d'une vraie ingestion en attente.
     */
    public function test_la_lecture_diagnostique_du_tableau_de_bord_rag_ne_consomme_aucun_debit(): void
    {
        [$organization] = $this->setUpEmbeddingsFixture();

        app(OrganizationRagOverview::class)->indexingAvailability($organization->fresh());
        app(OrganizationRagOverview::class)->indexingAvailability($organization->fresh());
        app(OrganizationRagOverview::class)->indexingAvailability($organization->fresh());

        $this->assertSame(
            0,
            RateLimiter::attempts('ai-debit:embeddings:org:'.$organization->id),
            'Un tableau de bord ouvert trois fois ne doit consommer AUCUN debit embeddings.'
        );
    }

    public function test_la_precondition_de_lab_ne_consomme_aucun_debit(): void
    {
        [$organization] = $this->setUpEmbeddingsFixture();
        // LabPreconditions::check() exige une instance embedding resolvable ;
        // le point teste ici est l'appel direct a authorizeEmbeddings(..., false)
        // qu'il porte desormais — la garantie de non-consommation, isolee.
        $this->guard->authorizeEmbeddings($organization, null, countRateLimit: false);
        $this->guard->authorizeEmbeddings($organization, null, countRateLimit: false);

        $this->assertSame(
            0,
            RateLimiter::attempts('ai-debit:embeddings:org:'.$organization->id),
            'countRateLimit: false ne doit jamais consommer le debit — lecture pure.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // L. Le message de refus porte un delai, et le code stable attendu
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_refus_remonte_porte_un_code_stable_et_un_delai_lisible(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        foreach (range(1, 3) as $_) {
            $this->authorize($organization, $user);
        }

        $verdict = $this->authorize($organization, $user);
        $exception = AiRefusedException::fromVerdict($verdict);

        $this->assertSame(AiRefusedException::CODE_RATE_LIMITED, $exception->refusalCode);
        $this->assertNotNull($exception->retryAfterSeconds);
        $this->assertStringContainsString((string) $exception->retryAfterSeconds, $exception->getMessage());
        // Pas de bouton « offres » pour un refus temporaire : ca n'a pas de sens ici.
        $this->assertNull($exception->offersUrl($organization));
    }
}
