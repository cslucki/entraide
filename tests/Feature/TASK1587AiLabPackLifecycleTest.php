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
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\Ai\AiTurnIdempotency;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackCrossTenantException;
use App\Support\ScenarioPacks\Packs\AiLabDataset;
use App\Support\ScenarioPacks\Packs\AiLabPack;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1587 / CDC-03 L-A — « le Lab existe ».
 *
 * A cycle ABSENT -> load -> status -> reload idempotent -> reset -> delete
 * borne -> ABSENT ; B contenu §4 (users, Loops, ACL `lab.member.c`, corpus
 * declare, ≥ 30 messages, replies explicites, hypothese §4.5 : reponse `ai`
 * seedee vue par l'idempotence) ; C tenant (hard-bound, outsider jamais une
 * entite, aucune fuite hors `ai-lab`) ; D indexation REELLE par le chemin
 * produit avec `Embeddings::fake()` -> `GOLD_INDEXED = YES` (DOCX tableau,
 * PDF, XLSX, MD) ; E corpus incomplet = refus bruyant.
 */
#[Group('ai')]
class TASK1587AiLabPackLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AiLabPack::DISK);
    }

    // ────────────────────────────── A. cycle de vie

    public function test_a1_absent_load_status_reload_reset_delete_revient_a_absent(): void
    {
        $this->assertNull($this->organization(), 'Etat de depart : ABSENT.');
        $this->assertSame(0, $this->load());

        $organization = $this->organization();
        $this->assertNotNull($organization, 'le pack provisionne son Organization');
        $this->assertSame('fr', $organization->locale);
        $this->assertTrue((bool) $organization->loops_enabled);
        $this->assertFalse((bool) $organization->is_public);
        $this->assertTrue((bool) ScenarioPackLoad::query()->where('pack_id', AiLabPack::PACK_ID)->where('organization_id', $organization->id)->value('organization_created_by_pack'));

        $counts = $this->counts($organization);
        $this->assertSame(4, $counts['persona']);
        $this->assertSame(4, $counts['loop']);
        $this->assertSame(4, $counts['folder']);
        $this->assertSame(4 + 3 + 2 + 2 + 2, $counts['loop_member'], 'owner + membres declares');
        $this->assertSame(7, $counts['folder_file']);
        $this->assertSame(count(AiLabDataset::messages()), $counts['loop_message']);
        $this->assertSame(1, $counts['organization_ai_setting']);
        $this->assertSame((string) $organization->admin_id, (string) User::query()->where('email', AiLabPack::emailFor('lab.admin'))->value('id'));

        // status
        $this->artisan('scenario-pack:status', ['pack' => AiLabPack::PACK_ID, 'organization' => AiLabPack::ORGANIZATION_SLUG])->assertExitCode(0);

        // Rejeu idempotent : memes comptes en base ET au registre, rien de duplique.
        $avant = $this->snapshot($organization);
        $this->assertSame(0, $this->load());
        $this->assertSame($avant, $this->snapshot($organization), 'rejeu sans doublon');
        $this->assertSame($counts, $this->counts($organization));

        // Reset : meme etat.
        $this->artisan('scenario-pack:reset', ['pack' => AiLabPack::PACK_ID, 'organization' => AiLabPack::ORGANIZATION_SLUG, '--yes' => true])->assertExitCode(0);
        $this->assertSame($avant, $this->snapshot($organization->fresh()));

        // Delete borne : tout ce que le pack a cree disparait, l'Organization aussi.
        $this->assertSame(0, $this->removePack());
        $this->assertNull($this->organization(), 'ABSENT a nouveau');
        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('email', 'like', '%@'.AiLabPack::EMAIL_DOMAIN)->count());
        $this->assertSame(0, ScenarioPackLoad::query()->where('pack_id', AiLabPack::PACK_ID)->count());
        $this->assertSame([], Storage::disk(AiLabPack::DISK)->allFiles(AiLabPack::ORGANIZATION_SLUG), 'aucun fichier de corpus ne survit');
    }

    // ────────────────────────────── B. contenu §4

    public function test_b1_les_loops_les_acl_et_le_corpus_declare_sont_ceux_du_cdc(): void
    {
        $this->load();
        $organization = $this->organization();

        $c = User::query()->where('email', AiLabPack::emailFor('lab.member.c'))->firstOrFail();
        $a = User::query()->where('email', AiLabPack::emailFor('lab.member.a'))->firstOrFail();
        $l1 = Loop::query()->where('organization_id', $organization->id)->where('name', AiLabPack::LOOPS['L1']['name'])->firstOrFail();
        $l2 = Loop::query()->where('organization_id', $organization->id)->where('name', AiLabPack::LOOPS['L2']['name'])->firstOrFail();

        // ACL Loop : `lab.member.c` n'est membre QUE de L1.
        $this->assertSame(1, LoopMember::query()->where('user_id', $c->id)->where('status', 'active')->count());
        $this->assertTrue(LoopMember::query()->where('user_id', $c->id)->where('loop_id', $l1->id)->exists());
        $this->assertFalse(LoopMember::query()->where('user_id', $c->id)->where('loop_id', $l2->id)->exists());
        $this->assertSame(['L1'], AiLabPack::loopKeysFor('lab.member.c'));
        $this->assertSame(['L1', 'L2', 'L3', 'L4'], AiLabPack::loopKeysFor('lab.member.a'));
        $this->assertSame(4, LoopMember::query()->where('user_id', $a->id)->where('status', 'active')->count());

        // Chaque Loop a son Dossier racine, et le corpus declare y est.
        foreach (AiLabPack::CORPUS as $loopKey => $files) {
            $loop = Loop::query()->where('organization_id', $organization->id)->where('name', AiLabPack::LOOPS[$loopKey]['name'])->firstOrFail();
            $dossier = Dossier::query()->withoutGlobalScopes()->where('loop_id', $loop->id)->firstOrFail();
            $this->assertEqualsCanonicalizing($files, DossierFile::query()->where('dossier_id', $dossier->id)->pluck('original_name')->all(), "corpus de {$loopKey}");
        }
        // TASK-1628 — `pluck()` sur une requete SANS `ORDER BY` : PostgreSQL rend
        // les lignes dans l'ordre du tas, qui depend de ce qui s'est passe avant
        // dans la meme base. `assertSame` compare AUSSI l'ordre des clefs : la
        // reussite tenait donc a une coincidence, pas a un invariant.
        //
        // Mesure : ce test est passe du shard 5 au shard 2 quand un fichier de
        // test a ete ajoute a `tests/Feature` (la decoupe est deterministe mais
        // repartie tout l'ensemble), il a change de voisins, et l'ordre du tas
        // avec eux — `equipes.docx` est arrive en derniere position. Rouge en CI,
        // vert en local, sur le meme code.
        //
        // `ksort()` rend la mesure deterministe sans l'affaiblir : `assertSame`
        // continue de verifier exactement les quatre paires nom => MIME.
        // Trois lignes plus haut, la meme precaution est deja prise avec
        // `assertEqualsCanonicalizing`.
        $mimeTypes = DossierFile::query()
            ->where('organization_id', $organization->id)
            ->whereIn('original_name', ['equipes.docx', 'charte.pdf', 'budget.xlsx', 'notes-structurees.md'])
            ->pluck('mime_type', 'original_name')
            ->all();
        ksort($mimeTypes);

        $this->assertSame([
            'budget.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'charte.pdf' => 'application/pdf',
            'equipes.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'notes-structurees.md' => 'text/markdown',
        ], $mimeTypes, 'MIME lus du contenu, repli extension pour le texte');
        // Le corpus ne fait jamais 200 Ko.
        $this->assertLessThan(200 * 1024, (int) DossierFile::query()->where('organization_id', $organization->id)->sum('size_bytes'));
    }

    public function test_b2_la_conversation_seedee_a_ses_replies_et_l_hypothese_de_la_reponse_ia_seedee_tient(): void
    {
        $this->load();
        $organization = $this->organization();
        $messages = LoopMessage::query()->where('organization_id', $organization->id)->get();

        $this->assertGreaterThanOrEqual(30, $messages->count(), 'CDC-03 §4.5 : ≥ 30 messages');
        $replies = $messages->whereNotNull('reply_to_id');
        $this->assertGreaterThanOrEqual(8, $replies->count(), 'des replies EXPLICITES');
        // Chaque reply pointe un message du MEME fil (la primitive canonique le garantit).
        foreach ($replies as $reply) {
            $parent = $messages->firstWhere('id', $reply->reply_to_id);
            $this->assertNotNull($parent);
            $this->assertSame((string) $parent->loop_id, (string) $reply->loop_id);
        }
        // Des racines et des suites SANS reply (mesure A2 du CDC-02).
        $this->assertGreaterThanOrEqual(15, $messages->whereNull('reply_to_id')->count());
        // Les dates s'etalent : pas vingt messages a la meme seconde.
        $this->assertGreaterThan(5, $messages->pluck('created_at')->map(fn ($d) => $d->toDateString())->unique()->count());

        // Hypothese §4.5 : UNE reponse `type = ai` seedee en reply, sans provider.
        $ia = $messages->where('type', 'ai');
        $this->assertCount(1, $ia);
        $tour1 = $ia->first();
        $this->assertNotNull($tour1->reply_to_id);
        $this->assertSame(AiLabPack::PACK_ID, $tour1->metadata['seeded_by_pack']);
        $this->assertArrayNotHasKey('ai_interaction_id', $tour1->metadata, 'un tour seede n\'est PAS un vrai tour');
        // L'idempotence produit la voit comme une reponse : le declencheur est « repondu ».
        $trigger = $messages->firstWhere('id', $tour1->reply_to_id);
        $this->assertTrue(AiTurnIdempotency::alreadyAnswered($trigger), 'HYPOTHESE §4.5 = YES : un tour 1 deterministe est possible');
        // Et la suite « Et pour Alice ? » n'a PAS de reply : c'est la paire _1 / _1B.
        $suite = $messages->firstWhere('body', 'Et pour Alice ?');
        $this->assertNull($suite->reply_to_id);
    }

    // ────────────────────────────── C. tenant

    public function test_c1_hard_bound_outsider_hors_pack_et_aucune_fuite_hors_ai_lab(): void
    {
        // Une autre Organization avec un outsider : le pack ne doit rien y toucher.
        $autre = Organization::factory()->create(['slug' => 'sentinel-b-1587', 'name' => 'SENTINEL-B']);
        $outsider = User::factory()->create(['organization_id' => $autre->id, 'email' => AiLabPack::OUTSIDER_EMAIL]);
        $avantAutre = [User::query()->where('organization_id', $autre->id)->count(), Loop::query()->withoutGlobalScopes()->where('organization_id', $autre->id)->count(), LoopMessage::query()->where('organization_id', $autre->id)->count()];

        $this->load();
        $organization = $this->organization();

        // Hard-bound : cibler une autre Organization allowlistee est refuse.
        config(['scenario_packs.allowed_organizations' => [...config('scenario_packs.allowed_organizations'), $autre->slug]]);
        $this->assertNotSame(0, $this->artisan('scenario-pack:load', ['pack' => AiLabPack::PACK_ID, 'organization' => $autre->slug])->run());
        $this->assertSame($avantAutre, [User::query()->where('organization_id', $autre->id)->count(), Loop::query()->withoutGlobalScopes()->where('organization_id', $autre->id)->count(), LoopMessage::query()->where('organization_id', $autre->id)->count()], 'l\'autre Organization est intacte');

        // Defense en profondeur : meme appele directement (hors commande, hors
        // adoptabilite), le pack refuse une autre Organization AVANT d'ecrire.
        $loadAutre = ScenarioPackLoad::query()->create(['organization_id' => $autre->id, 'pack_id' => AiLabPack::PACK_ID, 'pack_version' => '0', 'loaded_at' => now()]);
        try {
            app(AiLabPack::class)->apply($autre, new ScenarioPackEntityRegistrar($loadAutre));
            $this->fail('hard-bound attendu');
        } catch (\LogicException) {
        }
        $loadAutre->delete();
        $this->assertSame($avantAutre, [User::query()->where('organization_id', $autre->id)->count(), Loop::query()->withoutGlobalScopes()->where('organization_id', $autre->id)->count(), LoopMessage::query()->where('organization_id', $autre->id)->count()]);

        // L'outsider n'est ni une entite du pack ni un membre du Lab.
        $this->assertSame(0, ScenarioPackEntity::query()->whereHas('scenarioPackLoad', fn ($q) => $q->where('pack_id', AiLabPack::PACK_ID))->where('entity_id', $outsider->id)->count());
        $this->assertSame(0, LoopMember::query()->where('user_id', $outsider->id)->count());
        // Toute entite du registre appartient a `ai-lab` (sentinelle du registrar).
        foreach (ScenarioPackEntity::query()->whereHas('scenarioPackLoad', fn ($q) => $q->where('pack_id', AiLabPack::PACK_ID))->get() as $entity) {
            $this->assertSame((string) $organization->id, (string) $entity->organization_id);
        }
        // Le registrar refuse une entite d'ailleurs (defense en profondeur).
        $load = ScenarioPackLoad::query()->where('pack_id', AiLabPack::PACK_ID)->firstOrFail();
        $this->expectException(ScenarioPackCrossTenantException::class);
        (new ScenarioPackEntityRegistrar($load))->track('persona', 'lab.outsider', $outsider);
    }

    // ────────────────────────────── D. indexation reelle

    public function test_d1_le_corpus_s_indexe_par_le_chemin_reel_gold_indexed_yes(): void
    {
        // L'Organization est pre-creee (vide, adoptable) pour pouvoir ouvrir
        // la porte semantique et poser une cle tenant AVANT le chargement :
        // sans cle, l'indexer ne fait rien (le pack n'ecrit jamais de cle).
        $organization = Organization::factory()->create(['slug' => AiLabPack::ORGANIZATION_SLUG, 'name' => 'AI Lab', 'locale' => 'fr', 'loops_enabled' => true]);
        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-lab-1587']);
        config([
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $organization->id],
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => config('database.default') === 'pgsql' ? 1536 : 8,
        ]);
        $textes = [];
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$textes): array {
            $textes = [...$textes, ...$prompt->inputs];

            return array_map(fn (int $i): array => array_fill(0, $prompt->dimensions, ($i + 1) / 10), array_keys($prompt->inputs));
        })->preventStrayEmbeddings();

        $this->assertSame(0, $this->load());

        // Le reglage IA pose par l'operateur (cle incluse) est REUTILISE, jamais mute.
        $this->assertSame('sk-lab-1587', OrganizationAiSetting::query()->where('organization_id', $organization->id)->value('api_key'));

        // GOLD_INDEXED = YES : chaque fichier declare a ses chunks, par le vrai chemin.
        foreach (DossierFile::query()->where('organization_id', $organization->id)->get() as $file) {
            $this->assertGreaterThan(0, DossierChunk::query()->where('dossier_file_id', $file->id)->count(), "{$file->original_name} indexe");
        }
        $corpus = implode("\n", $textes);
        $this->assertStringContainsString('Nadia Ferreira', $corpus, 'MD L1');
        $this->assertStringContainsString('Atelier Capteurs', $corpus, 'DOCX tableau (table-aware)');
        $this->assertStringContainsString('Charte de fonctionnement', $corpus, 'PDF');
        $this->assertStringContainsString('48000', $corpus, 'XLSX');
        $this->assertStringContainsString('Les Lanternes', $corpus, 'MD L4 decoy');
        // Le rejeu n'embedde pas deux fois (alreadyIndexed).
        $n = count($textes);
        $this->assertSame(0, $this->load());
        $this->assertSame($n, count($textes), 'rejeu : aucun embedding supplementaire');
    }

    // ────────────────────────────── E. corpus incomplet

    public function test_e1_un_fichier_de_corpus_declare_absent_fait_echouer_le_chargement_avant_toute_ecriture(): void
    {
        $source = sys_get_temp_dir().'/ai-lab-1587-'.uniqid();
        mkdir($source.'/L1', 0777, true);
        file_put_contents($source.'/L1/faits-simples.md', '# incomplet');
        config([AiLabPack::SOURCE_CONFIG_KEY => $source]);

        $this->assertNotSame(0, $this->load());
        $this->assertNull($this->organization(), 'echec AVANT toute ecriture : l\'Organization provisionnee est retiree');
        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('email', 'like', '%@'.AiLabPack::EMAIL_DOMAIN)->count());

        config([AiLabPack::SOURCE_CONFIG_KEY => '/nulle/part']);
        $this->assertNotSame(0, $this->load());
    }

    // ────────────────────────────── fixtures

    private function load(): int
    {
        return $this->artisan('scenario-pack:load', ['pack' => AiLabPack::PACK_ID, 'organization' => AiLabPack::ORGANIZATION_SLUG])->run();
    }

    private function removePack(): int
    {
        return $this->artisan('scenario-pack:delete', ['pack' => AiLabPack::PACK_ID, 'organization' => AiLabPack::ORGANIZATION_SLUG, '--yes' => true])->run();
    }

    private function organization(): ?Organization
    {
        return Organization::query()->withoutGlobalScopes()->where('slug', AiLabPack::ORGANIZATION_SLUG)->first();
    }

    /** @return array<string, int> */
    private function counts(Organization $organization): array
    {
        return ScenarioPackEntity::query()
            ->whereHas('scenarioPackLoad', fn ($q) => $q->where('pack_id', AiLabPack::PACK_ID)->where('organization_id', $organization->id))
            ->selectRaw('entity_type, COUNT(*) as n')->groupBy('entity_type')->pluck('n', 'entity_type')
            ->map(fn ($n): int => (int) $n)->all();
    }

    /** @return array<string, int> */
    private function snapshot(Organization $organization): array
    {
        return [
            'users' => User::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'loops' => Loop::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'members' => LoopMember::query()->where('organization_id', $organization->id)->count(),
            'dossiers' => Dossier::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'files' => DossierFile::query()->where('organization_id', $organization->id)->count(),
            'messages' => LoopMessage::query()->where('organization_id', $organization->id)->count(),
            'entities' => ScenarioPackEntity::query()->whereHas('scenarioPackLoad', fn ($q) => $q->where('pack_id', AiLabPack::PACK_ID))->count(),
            'storage' => count(Storage::disk(AiLabPack::DISK)->allFiles(AiLabPack::ORGANIZATION_SLUG)),
        ];
    }
}
