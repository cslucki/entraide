<?php

namespace Tests\Feature;

use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Support\ScenarioPacks\Packs\AiLabPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1594 / CDC-NIGHT §6 (invariant MASTER) — le reset du Lab purge AUSSI
 * la connaissance derivee produite APRES le chargement, strictement bornee a
 * l'Organization `ai-lab`.
 *
 *   A. load -> note + chunk derives -> reset -> 0 note, 0 chunk (Lab)
 *   B. une autre Organization avec ses propres derives : avant = apres
 *   C. le reste du Lab est intact (Loops, messages, corpus) et rejouable
 */
#[Group('ai')]
class TASK1594AiLabHygieneResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AiLabPack::DISK);
    }

    public function test_a1_le_reset_purge_notes_et_chunks_derives_du_lab_et_seulement_du_lab(): void
    {
        $this->assertSame(0, $this->artisan('scenario-pack:load', ['pack' => AiLabPack::PACK_ID, 'organization' => AiLabPack::ORGANIZATION_SLUG])->run());
        $lab = Organization::query()->where('slug', AiLabPack::ORGANIZATION_SLUG)->firstOrFail();

        // Une AUTRE Organization (le produit) avec sa propre connaissance derivee.
        $autre = Organization::factory()->create(['slug' => 'main', 'is_active' => true, 'loops_enabled' => true]);
        $autreUser = User::factory()->complete()->create(['organization_id' => $autre->id]);
        $autreLoop = Loop::factory()->create(['organization_id' => $autre->id, 'created_by' => $autreUser->id]);
        $autreDossier = Dossier::factory()->create(['organization_id' => $autre->id, 'owner_id' => $autreUser->id]);
        $this->derive($autre, $autreLoop, $autreDossier);
        $this->derive($autre, $autreLoop, $autreDossier);

        // Apres le load : knowledge:derive-due aurait pu produire ceci dans le Lab.
        $labLoop = Loop::query()->withoutGlobalScopes()->where('organization_id', $lab->id)->where('name', AiLabPack::LOOPS['L1']['name'])->firstOrFail();
        $labDossier = Dossier::query()->withoutGlobalScopes()->where('loop_id', $labLoop->id)->firstOrFail();
        $this->derive($lab, $labLoop, $labDossier);
        $this->derive($lab, $labLoop, $labDossier);
        $this->derive($lab, $labLoop, $labDossier);

        $this->assertSame(3, $this->notes($lab));
        $this->assertSame(3, $this->chunksDerives($lab));
        $avantAutre = $this->empreinte($autre);
        $avantLab = $this->empreinteHorsDerives($lab);
        $this->assertSame(2, $avantAutre['notes']);

        $this->assertSame(0, $this->artisan('scenario-pack:reset', ['pack' => AiLabPack::PACK_ID, 'organization' => AiLabPack::ORGANIZATION_SLUG, '--yes' => true])->run());

        // Lab : 0 / 0.
        $this->assertSame(0, $this->notes($lab), 'derived notes ai-lab = 0');
        $this->assertSame(0, $this->chunksDerives($lab), 'derived chunks ai-lab = 0');
        // L'autre Organization : avant = apres, a la ligne pres.
        $this->assertSame($avantAutre, $this->empreinte($autre), 'main : avant = apres');
        // Le Lab hors derives : intact (les chunks du corpus, les messages, les Loops).
        $this->assertSame($avantLab, $this->empreinteHorsDerives($lab), 'le reset n\'a touche que les derives');
    }

    public function test_a2_un_reset_sans_derive_est_un_no_op_et_le_chargement_purge_aussi(): void
    {
        $this->assertSame(0, $this->artisan('scenario-pack:load', ['pack' => AiLabPack::PACK_ID, 'organization' => AiLabPack::ORGANIZATION_SLUG])->run());
        $lab = Organization::query()->where('slug', AiLabPack::ORGANIZATION_SLUG)->firstOrFail();
        $avant = $this->empreinteHorsDerives($lab);

        $this->assertSame(0, $this->artisan('scenario-pack:reset', ['pack' => AiLabPack::PACK_ID, 'organization' => AiLabPack::ORGANIZATION_SLUG, '--yes' => true])->run());
        $this->assertSame($avant, $this->empreinteHorsDerives($lab));

        // Le rejeu idempotent du load passe par apply() : il nettoie de meme.
        $labLoop = Loop::query()->withoutGlobalScopes()->where('organization_id', $lab->id)->where('name', AiLabPack::LOOPS['L2']['name'])->firstOrFail();
        $this->derive($lab, $labLoop, Dossier::query()->withoutGlobalScopes()->where('loop_id', $labLoop->id)->firstOrFail());
        $this->assertSame(1, $this->notes($lab));
        $this->assertSame(0, $this->artisan('scenario-pack:load', ['pack' => AiLabPack::PACK_ID, 'organization' => AiLabPack::ORGANIZATION_SLUG])->run());
        $this->assertSame(0, $this->notes($lab));
        $this->assertSame(0, $this->chunksDerives($lab));
    }

    // ────────────────────────────── fixtures

    private function derive(Organization $organization, Loop $loop, Dossier $dossier): void
    {
        $note = DerivedKnowledgeNote::create([
            'organization_id' => $organization->id, 'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION, 'kind' => DerivedKnowledgeNote::KIND_CLAIM,
            'source_loop_id' => $loop->id, 'dossier_id' => $dossier->id, 'subject_key' => 'sujet-'.Str::random(6), 'content' => 'Une connaissance derivee.',
            'source_fingerprint' => hash('sha256', (string) Str::uuid()), 'provenance' => ['source_loop_message_ids' => [], 'derived_by' => 'test'],
            'observed_at' => now(), 'derived_at' => now(), 'version' => 1, 'status' => 'active',
        ]);
        DossierChunk::create([
            'organization_id' => $organization->id, 'dossier_id' => $dossier->id, 'blog_post_id' => null, 'dossier_file_id' => null, 'derived_knowledge_note_id' => $note->id,
            'chunk_index' => 0, 'content' => 'contenu derive', 'content_hash' => hash('sha256', (string) Str::uuid()), 'token_count' => 3,
            'embedding' => array_fill(0, config('database.default') === 'pgsql' ? 1536 : 8, 0.1), 'embedding_provider' => 'openrouter', 'embedding_model' => 'text-embedding-3-small', 'indexed_at' => now(),
        ]);
    }

    private function notes(Organization $organization): int
    {
        return DerivedKnowledgeNote::query()->where('organization_id', $organization->id)->count();
    }

    private function chunksDerives(Organization $organization): int
    {
        return DossierChunk::query()->where('organization_id', $organization->id)->whereNotNull('derived_knowledge_note_id')->count();
    }

    /** @return array<string, int> */
    private function empreinte(Organization $organization): array
    {
        return ['notes' => $this->notes($organization), 'chunks_derives' => $this->chunksDerives($organization)] + $this->empreinteHorsDerives($organization);
    }

    /** @return array<string, int> */
    private function empreinteHorsDerives(Organization $organization): array
    {
        return [
            'users' => User::query()->where('organization_id', $organization->id)->count(),
            'loops' => Loop::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'messages' => LoopMessage::query()->where('organization_id', $organization->id)->count(),
            'dossiers' => Dossier::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'chunks_corpus' => DossierChunk::query()->where('organization_id', $organization->id)->whereNull('derived_knowledge_note_id')->count(),
        ];
    }
}
