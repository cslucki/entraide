<?php

namespace Tests\Feature\Knowledge;

use App\Models\AiShellMessage;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Knowledge\LoopReferenceResolver;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1547 — deux durcissements du tour de reference, mesures de face.
 *
 * ## 1. La phrase annoncait DEUX projets, quel que soit leur nombre
 *
 * `LoopReferenceResolver::MAX_CANDIDATS` en autorise quatre, et la liste des
 * candidats est rendue juste sous la phrase. Trois projets faisaient donc lire
 * « Deux projets peuvent correspondre » suivi de trois lignes : la phrase
 * dementie par ce qu'elle introduit. Le nombre annonce est desormais celui des
 * candidats REELLEMENT listes, en francais comme en anglais.
 *
 * ## 2. La correction la plus precise etait celle que le systeme refusait
 *
 * L'appariement de `referentCorrige()` etait un `str_contains` nu. Deux
 * Boucles dont l'une porte le nom de l'autre — « Renovation Pigeonnier » et
 * « Renovation Pigeonnier — temoin » — le mettaient en defaut par
 * construction : nommer la seconde appariait les deux, et la correction etait
 * declaree ambigue alors qu'elle ne l'etait pas.
 *
 * Ce n'est pas un cas de laboratoire. Un projet et son temoin, une phase 1 et
 * une phase 2, un chantier et son lot : les tenants nomment ainsi.
 *
 * ## Ce que ces tests protegent, et ce qu'ils NE relachent pas
 *
 * Une occurrence avalee par un nom plus long ne designe rien. Mais deux
 * references reellement designees restent deux, et la question est re-posee —
 * y compris quand le nom court est ecrit AUSSI hors du nom long. Le
 * durcissement ferme un faux ambigu ; il n'ouvre aucun arbitrage.
 */
class TASK1547ReferenceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    /** Celle qui pose la question. */
    private User $camille;

    /** Celui dont on parle. */
    private User $marin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->organization);

        $this->camille = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Camille Dubreuil']);
        $this->marin = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Marin Delcourt']);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1547',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai_pricing.overrides' => [],
        ]);

        app()->setLocale('fr');

        Http::preventStrayRequests();
    }

    // ──────────────────────────────── le nombre annonce est celui qu'on liste

    public function test_deux_candidats_annoncent_deux_projets(): void
    {
        $this->projetsDeMarin(combien: 2);

        $tour = $this->demander('Le projet dont Marin parlait, ça avance ?');

        $this->assertTrue($tour->metadata['reference']['ambiguous'] ?? false, 'PREMISSE : le tour est ambigu');
        $this->assertCount(2, $tour->metadata['reference']['candidate_loop_ids'] ?? []);
        $this->assertStringContainsString('2 projets peuvent correspondre', $tour->content);
    }

    /**
     * LE cas que le libelle fige rendait faux : trois candidats, une phrase
     * qui en annoncait deux, et trois lignes juste en dessous.
     */
    public function test_trois_candidats_annoncent_trois_projets(): void
    {
        $this->projetsDeMarin(combien: 3);

        $tour = $this->demander('Le projet dont Marin parlait, ça avance ?');

        $this->assertCount(3, $tour->metadata['reference']['candidate_loop_ids'] ?? [],
            'PREMISSE : le resolveur rend bien TROIS candidats');

        $this->assertStringContainsString('3 projets peuvent correspondre', $tour->content);
        $this->assertStringNotContainsString('Deux projets', $tour->content);

        // Le nombre annonce doit valoir le nombre de lignes rendues, sans quoi
        // la phrase est dementie par ce qu'elle introduit.
        $this->assertSame(3, substr_count($tour->content, "\n- **"));
    }

    public function test_le_nombre_se_dit_aussi_en_anglais(): void
    {
        $this->projetsDeMarin(combien: 3);

        app()->setLocale('en');

        $tour = $this->demander('How is the project Marin mentioned going?');

        $this->assertCount(3, $tour->metadata['reference']['candidate_loop_ids'] ?? [], 'PREMISSE');
        $this->assertStringContainsString('3 projects could match', $tour->content);
        $this->assertStringNotContainsString('Two projects', $tour->content);
    }

    // ──────────────────────────────── le nom NOMME l'emporte sur le nom CONTENU

    /**
     * La regression du mandat : « Renovation Pigeonnier » est un morceau de
     * « Renovation Pigeonnier — temoin ». Nommer le temoin appariait les deux,
     * et la correction la plus precise possible etait declaree ambigue.
     */
    public function test_nommer_le_temoin_corrige_le_referent_sur_le_temoin_seul(): void
    {
        [$court, $temoin] = $this->pigeonnier();

        $tour = $this->demander('Non, je parlais de Renovation Pigeonnier — temoin.');

        $this->assertSame(AiShellResponder::PRODUCER_REFERENCE_RESOLUTION, $tour->metadata['producer'] ?? null);
        $this->assertTrue($tour->metadata['reference']['corrected'] ?? false);
        $this->assertFalse($tour->metadata['reference']['ambiguous'] ?? true,
            'le nom le plus long est NOMME : la Boucle courte n existe ici que dedans');
        $this->assertSame([(string) $temoin->id], $tour->metadata['reference']['candidate_loop_ids'] ?? []);
        $this->assertNotSame((string) $court->id, $tour->metadata['reference']['candidate_loop_ids'][0] ?? null);
    }

    /**
     * Le sens inverse doit rester intact : le nom court, ecrit seul, ne peut
     * pas designer le temoin — le nom du temoin n'est nulle part.
     */
    public function test_nommer_le_projet_court_corrige_le_referent_sur_le_projet_court(): void
    {
        [$court] = $this->pigeonnier();

        $tour = $this->demander('Non, je parlais de Renovation Pigeonnier.');

        $this->assertTrue($tour->metadata['reference']['corrected'] ?? false);
        $this->assertFalse($tour->metadata['reference']['ambiguous'] ?? true);
        $this->assertSame([(string) $court->id], $tour->metadata['reference']['candidate_loop_ids'] ?? []);
    }

    /**
     * Le durcissement ferme un FAUX ambigu, il n'ouvre aucun arbitrage : ecrire
     * le nom court AILLEURS que dans le nom long, c'est designer les deux.
     */
    public function test_les_deux_noms_ecrits_chacun_pour_soi_restent_ambigus(): void
    {
        [$court, $temoin] = $this->pigeonnier();

        $tour = $this->demander('Non, je parlais de Renovation Pigeonnier, pas de Renovation Pigeonnier — temoin.');

        $this->assertTrue($tour->metadata['reference']['ambiguous'] ?? false,
            'la Boucle courte est nommee HORS de la longue : deux references sont designees');

        $ids = $tour->metadata['reference']['candidate_loop_ids'] ?? [];
        sort($ids);
        $attendus = [(string) $court->id, (string) $temoin->id];
        sort($attendus);
        $this->assertSame($attendus, $ids);
    }

    public function test_le_temoin_et_un_projet_sans_rapport_restent_ambigus(): void
    {
        [, $temoin, $aria] = $this->pigeonnier();

        $tour = $this->demander('Non, je parlais de Renovation Pigeonnier — temoin ou de ARIA.');

        $this->assertTrue($tour->metadata['reference']['ambiguous'] ?? false);

        $ids = $tour->metadata['reference']['candidate_loop_ids'] ?? [];
        sort($ids);
        $attendus = [(string) $temoin->id, (string) $aria->id];
        sort($attendus);
        $this->assertSame($attendus, $ids,
            'deux references REELLEMENT designees restent deux, et la question est re-posee');
    }

    /**
     * Et l'ambiguite ferme toujours ce qu'elle fermait : un referent non
     * designe ne doit alimenter aucun matching de personnes.
     */
    public function test_une_correction_restee_ambigue_bloque_toujours_le_matching(): void
    {
        $this->pigeonnier();

        $this->demander('Non, je parlais de Renovation Pigeonnier, pas de Renovation Pigeonnier — temoin.');

        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertSame('unresolved_reference', $tour->metadata['people']['blocked_by'] ?? null);
    }

    /**
     * Le degenere du `str_contains` nu : la chaine vide est contenue dans
     * n'importe quel message. Une Boucle sans nom appariait donc TOUTE
     * correction, et rendait ambigue chaque correction pourtant nette.
     */
    public function test_une_boucle_sans_nom_ne_s_apparie_a_aucune_correction(): void
    {
        $anonyme = $this->boucle('Sans nom');
        $revive = $this->boucle('REVIVE');

        $anonyme->forceFill(['name' => ''])->saveQuietly();

        $this->enonce($anonyme, $this->marin, 'Le lot 1 attend son chiffrage.', now()->subDays(3));
        $this->enonce($revive, $this->marin, 'La charpente est confiee a Vaucanson.', now()->subDays(2));

        $ambigu = $this->demander('Le projet dont Marin parlait, ça avance ?');
        $this->assertCount(2, $ambigu->metadata['reference']['candidate_loop_ids'] ?? [], 'PREMISSE');

        $tour = $this->demander('Non, je parlais de REVIVE.');

        $this->assertFalse($tour->metadata['reference']['ambiguous'] ?? true,
            'un nom vide n est pas un nom : il ne designe rien');
        $this->assertSame([(string) $revive->id], $tour->metadata['reference']['candidate_loop_ids'] ?? []);
    }

    // ────────────────────────────────────────────────── mise en place

    /**
     * `$combien` projets dont Marin a ecrit la preuve, tous lisibles par
     * Camille : la question indirecte rend donc `$combien` candidats.
     *
     * @return list<Loop>
     */
    private function projetsDeMarin(int $combien): array
    {
        $noms = ['ARIA', 'REVIVE', 'Atlas'];
        $loops = [];

        foreach (array_slice($noms, 0, $combien) as $index => $nom) {
            $loop = $this->boucle($nom);
            $this->enonce($loop, $this->marin, 'Le lot '.($index + 1).' attend son chiffrage.', now()->subDays(3 - $index));
            $loops[] = $loop;
        }

        $this->assertCount($combien, app(LoopReferenceResolver::class)->resoudre(
            (string) $this->organization->id, $this->camille, 'Le projet dont Marin parlait ?')['candidats'],
            'PREMISSE : le resolveur rend bien '.$combien.' candidats');

        return $loops;
    }

    /**
     * Le cas de nommage reel : un projet, son temoin — dont le nom CONTIENT
     * celui du projet — et un troisieme sans rapport. Le tour ambigu est joue,
     * de sorte que les trois soient OFFERTS a la correction.
     *
     * @return list<Loop> [court, temoin, aria]
     */
    private function pigeonnier(): array
    {
        $court = $this->boucle('Renovation Pigeonnier');
        $temoin = $this->boucle('Renovation Pigeonnier — temoin');
        $aria = $this->boucle('ARIA');

        $this->enonce($court, $this->marin, 'La couverture reste a chiffrer.', now()->subDays(4));
        $this->enonce($temoin, $this->marin, 'Le releve du temoin est fait.', now()->subDays(3));
        $this->enonce($aria, $this->marin, 'Le budget travaux est de 486 000 euros.', now()->subDays(2));

        $ambigu = $this->demander('Le projet dont Marin parlait, ça avance ?');

        $this->assertTrue($ambigu->metadata['reference']['ambiguous'] ?? false,
            'PREMISSE : le tour precedent a demande de choisir');
        $this->assertCount(3, $ambigu->metadata['reference']['candidate_loop_ids'] ?? [],
            'PREMISSE : les trois Boucles sont OFFERTES a la correction');

        return [$court, $temoin, $aria];
    }

    private function boucle(string $nom): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->marin->id,
            'name' => $nom,
            'visibility' => 'private',
        ]);

        foreach ([$this->marin, $this->camille] as $membre) {
            LoopMember::create([
                'organization_id' => $this->organization->id,
                'loop_id' => $loop->id,
                'user_id' => $membre->id,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        app(LoopRootDocumentService::class)->ensureRootDossier($loop->fresh());

        return $loop;
    }

    /**
     * Un enonce et le message humain qui l'etablit — c'est CE lien qui rend
     * « dont Marin parlait » resolvable. Le texte ne nomme jamais son projet.
     */
    private function enonce(Loop $loop, User $auteur, string $texte, \DateTimeInterface $quand): DerivedKnowledgeNote
    {
        $message = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $loop->id,
            'sender_id' => $auteur->id,
            'body' => $texte.' (dit en reunion)',
            'type' => 'user',
        ]);

        $message->forceFill(['created_at' => $quand, 'updated_at' => $quand])->save();

        return DerivedKnowledgeNote::create([
            'organization_id' => $this->organization->id,
            'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
            'kind' => DerivedKnowledgeNote::KIND_CLAIM,
            'source_loop_id' => $loop->id,
            'dossier_id' => Dossier::where('loop_id', $loop->id)->value('id'),
            'subject_key' => (string) Str::uuid(),
            'content' => $texte,
            'source_fingerprint' => hash('sha256', $texte.$loop->id),
            'provenance' => [
                'source_loop_message_ids' => [(string) $message->id],
                'derived_by' => 'loop_conversation_knowledge',
            ],
            'observed_at' => $quand,
            'derived_at' => now(),
            'version' => 1,
            'status' => DerivedKnowledgeNote::STATUS_ACTIVE,
        ]);
    }

    private function demander(string $prompt): AiShellMessage
    {
        $this->actingAs($this->camille);

        return app(AiShellResponder::class)->respond(
            $this->organization, $this->camille, $prompt,
            ['route' => 'dashboard', 'kind' => AiShellPageContext::KIND_OTHER],
        )['answer'];
    }
}
