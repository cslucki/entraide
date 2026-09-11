<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopConversationKnowledgeAgent;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Knowledge\LoopConversationKnowledgeDeriver;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1538 — les deux façons dont la mémoire pouvait mentir.
 *
 * ## HIGH A — une dérivation partie d'un état ancien gagnait encore
 *
 * `persist()` comparait l'empreinte de source à celle de la note courante et,
 * si elles différaient, écrivait. Cette garde protège le REJEU IDENTIQUE —
 * deux workers partis du même état — et rien d'autre. Or deux états
 * différents ont, par construction, des empreintes différentes : une
 * dérivation partie de S0 voyait donc une note née de S1 comme « autre chose »
 * et la supersédait tranquillement.
 *
 * Ce n'est pas une course théorique : c'est exactement ce que produit un appel
 * provider lent pendant qu'une correction humaine arrive.
 *
 * ## HIGH B — un fait durable disparaissait par simple glissement de fenêtre
 *
 * La dérivation lit les 60 derniers messages. Au-delà, un fait ancien sort de
 * la fenêtre, n'est plus dans la source, et la recompilation produit une note
 * qui ne le contient plus — laquelle supersède celle qui le contenait.
 *
 * Le fait n'est ni corrigé, ni contredit, ni invalidé : il est simplement
 * oublié, sans que rien ne le signale. **Une fenêtre de lecture n'est pas une
 * durée de vie de mémoire**, et l'agrandir ne change pas sa nature.
 */
class TASK1538MemoryHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Renard']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Chantier Belleville',
            'visibility' => 'private',
        ]);

        LoopMember::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->alice->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1538',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai_pricing.overrides' => [],
        ]);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (): array => array_fill(0, 1536, 0.01),
            $prompt->inputs,
        ))->preventStrayEmbeddings();
    }

    // ───────────────────────────────────────────────── HIGH A

    public function test_une_derivation_partie_d_un_etat_ancien_ne_supersede_jamais_une_plus_recente(): void
    {
        $deriver = app(LoopConversationKnowledgeDeriver::class);

        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $this->fakeAgent('Budget 486 000 euros.');
        $v1 = $deriver->derive($this->loop->fresh());
        $this->assertSame(1, $v1->version);

        // A doit avoir du travail : sans source nouvelle, le court-circuit
        // d'empreinte le fait sortir AVANT le provider et la course n'a jamais
        // lieu. Une premiere version de ce test tombait exactement la.
        $this->message('Je confirme le chiffrage transmis par la maitrise d oeuvre.');

        // A part maintenant de la note v1. Son appel provider est LENT :
        // pendant qu'il dure, un humain corrige et B recompile. On reproduit
        // cet ordre exact en faisant travailler B DANS le double de A.
        $bATravaille = false;

        LoopConversationKnowledgeAgent::fake(function () use (&$bATravaille, $deriver): TextResponse {
            if (! $bATravaille) {
                $bATravaille = true;

                $this->message('Correction : le budget a ete revu a 512 000 euros.');

                // B possede son propre double, le temps de son tour.
                LoopConversationKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
                    'Budget revise a 512 000 euros.', new Usage(30, 12), new Meta('openrouter', 'openai/gpt-4o-mini'),
                ));

                $deriver->derive($this->loop->fresh());
            }

            // Ce que A rend : une note derivee de l'etat ANCIEN.
            return new TextResponse('Budget 486 000 euros.', new Usage(30, 12), new Meta('openrouter', 'openai/gpt-4o-mini'));
        });

        $deriver->derive($this->loop->fresh());

        $active = DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)->active()->firstOrFail();

        // PREMISSE : la course a bien eu lieu — B a produit une version.
        $this->assertGreaterThanOrEqual(2, DerivedKnowledgeNote::query()->count(),
            'PREMISSE : B doit avoir ecrit une version pendant l appel de A');

        $this->assertStringContainsString('512 000', (string) $active->content,
            'OLD_BASIS_LOSES : la correction humaine doit rester la verite courante');
        $this->assertStringNotContainsString('486 000', (string) $active->content,
            'une derivation partie d un etat ancien ne doit jamais ecraser une version plus recente');
    }

    public function test_le_rejeu_identique_reste_sans_effet(): void
    {
        // La garde ajoutee ne doit pas casser ce qu'elle protegeait deja : deux
        // workers partis du MEME etat produisent une seule note.
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $this->fakeAgent('Budget 486 000 euros.');

        $deriver = app(LoopConversationKnowledgeDeriver::class);
        $a = $deriver->derive($this->loop->fresh());
        $b = $deriver->derive($this->loop->fresh());

        $this->assertSame((string) $a->id, (string) $b->id);
        $this->assertSame(1, DerivedKnowledgeNote::query()->count());
    }

    // ───────────────────────────────────────────────── HIGH B

    public function test_la_connaissance_deja_compilee_est_transmise_a_la_recompilation(): void
    {
        // Un fait durable, dit tres tot.
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.', now()->subMonths(6));

        $deriver = app(LoopConversationKnowledgeDeriver::class);
        $this->fakeAgent('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $v1 = $deriver->derive($this->loop->fresh());
        $this->assertStringContainsString('486 000', (string) $v1->content,
            'PREMISSE : le fait est bien entre en memoire');

        // Six mois de vie de la Boucle, sans que personne ne revienne dessus.
        for ($i = 0; $i < 70; $i++) {
            $this->message("Point d avancement numero {$i} : la reunion hebdomadaire a eu lieu comme prevu.",
                now()->subMonths(5)->addDays($i));
        }

        // PREMISSE : la recompilation ne VOIT plus le message d'origine.
        $sources = $this->sourcesLues();
        $this->assertLessThanOrEqual(60, count($sources), 'PREMISSE : la fenetre de lecture est bornee');
        $this->assertSame([], array_values(array_filter($sources, static fn (string $b): bool => str_contains($b, '486 000'))),
            'PREMISSE : le fait d origine est SORTI de la fenetre de lecture');

        // Ce qui est mesurable de facon DETERMINISTE : la memoire deja compilee
        // entre dans le tour, accompagnee de la consigne de report.
        //
        // Ce qui ne l'est pas, et qu'aucun double ne peut prouver : que le
        // modele OBEISSE. Cela se mesure au banc, contre un vrai modele — c'est
        // la lecon de T1537, ou deux retouches de prompt « evidentes » se sont
        // revelees degradantes a la mesure.
        $this->fakeAgent('Les points d avancement hebdomadaires ont eu lieu comme prevu.');
        $deriver->derive($this->loop->fresh());

        // Le predicat porte la revendication entiere : UN tour a recu la
        // memoire, le fait sorti de la fenetre, ET les deux consignes qui
        // encadrent son report. `assertPrompted` s'arrete au premier prompt
        // qui satisfait le predicat — l'exprimer ainsi evite de dependre de
        // l'ordre d'enregistrement.
        LoopConversationKnowledgeAgent::assertPrompted(
            fn (AgentPrompt $p): bool => str_contains((string) $p->prompt, 'CONNAISSANCE DEJA COMPILEE')
                && str_contains((string) $p->prompt, '486 000')
                && str_contains((string) $p->prompt, 'Reprends tels quels les faits')
                && str_contains((string) $p->prompt, 'corrigent, invalident ou rendent caduc'),
        );
    }

    public function test_la_premiere_compilation_ne_transmet_aucune_memoire(): void
    {
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $this->fakeAgent('Budget 486 000 euros.');

        app(LoopConversationKnowledgeDeriver::class)->derive($this->loop->fresh());

        $recu = null;
        LoopConversationKnowledgeAgent::assertPrompted(function (AgentPrompt $p) use (&$recu): bool {
            $recu = (string) $p->prompt;

            return true;
        });

        // Sans memoire prealable, le tour reste exactement ce qu'il etait : une
        // transcription. Aucun en-tete parasite, aucune consigne sans objet.
        $this->assertStringNotContainsString('CONNAISSANCE DEJA COMPILEE', (string) $recu);
    }

    // ───────────────────────────────────────────────── helpers

    /** @return list<string> */
    private function sourcesLues(): array
    {
        $r = new \ReflectionClass(LoopConversationKnowledgeDeriver::class);
        $m = $r->getMethod('sourceMessages');
        $m->setAccessible(true);

        return array_map(
            static fn (LoopMessage $m): string => (string) $m->body,
            $m->invoke(app(LoopConversationKnowledgeDeriver::class), $this->loop->fresh()),
        );
    }

    private function message(string $body, ?\DateTimeInterface $quand = null): LoopMessage
    {
        $m = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => $body,
            'type' => 'user',
        ]);

        if ($quand !== null) {
            // `created_at` n'est pas fillable : il se pose apres, sinon les
            // messages partagent tous la meme seconde.
            $m->forceFill(['created_at' => $quand, 'updated_at' => $quand])->save();
        }

        return $m;
    }

    private function fakeAgent(string $texte): void
    {
        LoopConversationKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            $texte, new Usage(30, 12), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }
}
