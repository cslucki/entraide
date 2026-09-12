<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\ContexteBorne;
use App\Ai\Context\KnowledgeDeltaSource;
use App\Ai\Context\TemporalQuestionShape;
use App\Ai\ContexteIa;
use App\Models\AdminAiPrompt;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\Knowledge\DerivedKnowledgeNoteIndexer;
use App\Services\Knowledge\LoopClaimDelta;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\Support\Ai\FakeDossierSemanticSearch;
use Tests\TestCase;

/**
 * TASK-1543 — « Qu'est-ce qui a change depuis mardi ? »
 *
 * ## HISTORY = deterministic lineage lookup
 *
 * L'histoire ne se retrouve pas par similarite. Un delta est une relation
 * entre deux lignes reliees par `superseded_by_id` : le vecteur ne la connait
 * pas, la cle etrangere si. Et rendre les archives candidates du READ courant
 * reviendrait a defaire T1541 — deux budgets ne peuvent pas coexister dans une
 * reponse.
 *
 * ## Les trois regles que ces tests protegent
 *
 *  1. **le temps lu est celui des HUMAINS.** Jamais `derived_at`, jamais
 *     `superseded_at` : ce sont des heures de machine. Un ajout et une
 *     correction sont dates par `observed_at` (date de leur propre preuve
 *     depuis T1541) ; un retrait, par les messages qui l'ont justifie ;
 *  2. **l'ACL de l'histoire est l'ACL de la Boucle.** Pas de variante, pas de
 *     seconde regle. Perdre l'acces a la Boucle, c'est perdre l'histoire ;
 *  3. **on n'invente jamais « depuis mardi ».** Une question sans reperage
 *     temporel porte sur toute l'histoire connue — pas sur une fenetre par
 *     defaut que personne n'a demandee.
 */
class TASK1543TemporalDeltaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    /** Membre de la Boucle, pour mesurer que l'acces n'est pas personnel. */
    private User $bob;

    /** Membre de l'Organization, membre d'AUCUNE Boucle de ce test. */
    private User $carol;

    private Loop $loop;

    private FakeDossierSemanticSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Renard']);
        $this->bob = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bob Lemoine']);
        $this->carol = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Carol Vasseur']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Chantier Belleville',
            'visibility' => 'private',
        ]);

        foreach ([$this->alice, $this->bob] as $membre) {
            LoopMember::create([
                'organization_id' => $this->organization->id,
                'loop_id' => $this->loop->id,
                'user_id' => $membre->id,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1543',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai_pricing.overrides' => [],
        ]);

        $this->search = new FakeDossierSemanticSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (): array => array_fill(0, 1536, 0.01),
            $prompt->inputs,
        ))->preventStrayEmbeddings();

        config([
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
        ]);

        Http::preventStrayRequests();
    }

    // ──────────────────────────────── la forme de la question

    public function test_une_question_de_changement_se_reconnait_sans_appeler_un_modele(): void
    {
        foreach ([
            "Qu'est-ce qui a changé depuis mardi ?",
            'Quoi de neuf sur le chantier ?',
            "Qu'est-ce qui a bougé depuis la semaine dernière ?",
            'What changed since Tuesday?',
            'Y a-t-il du nouveau ?',
        ] as $question) {
            $this->assertTrue(TemporalQuestionShape::wantsChangeReport($question), $question);
        }

        // Et surtout : ce qui n'en est PAS une. Un faux positif injecterait un
        // bloc d'histoire dans une question qui n'en demande pas.
        foreach ([
            'Quel est le budget des travaux ?',
            'Quels fichiers sont disponibles dans cette Boucle ?',
            'Résume les documents du Dossier.',
            'Qui pose la charpente ?',
            '',
        ] as $question) {
            $this->assertFalse(TemporalQuestionShape::wantsChangeReport($question), $question);
        }
    }

    public function test_depuis_mardi_designe_le_mardi_le_plus_recent_et_non_une_fenetre_choisie(): void
    {
        // Un jeudi, « depuis mardi » = l'avant-veille.
        $jeudi = Carbon::parse('2026-09-10 15:30:00');
        $this->assertSame('2026-09-08 00:00:00',
            TemporalQuestionShape::anchor("Qu'est-ce qui a changé depuis mardi ?", $jeudi)?->toDateTimeString());

        // Un mardi, « depuis mardi » = aujourd'hui — jamais mardi dernier :
        // quelqu'un qui dit « depuis mardi » un mardi parle de sa journee.
        $mardi = Carbon::parse('2026-09-08 09:00:00');
        $this->assertSame('2026-09-08 00:00:00',
            TemporalQuestionShape::anchor('Quoi de neuf depuis mardi ?', $mardi)?->toDateTimeString());

        // Les autres reperages, dans les deux langues.
        $this->assertSame('2026-09-09 00:00:00',
            TemporalQuestionShape::anchor("Qu'est-ce qui a changé depuis hier ?", $jeudi)?->toDateTimeString());
        $this->assertSame('2026-09-07 00:00:00',
            TemporalQuestionShape::anchor('What changed in the last three days?', $jeudi)?->toDateTimeString());
        $this->assertSame('2026-08-31 00:00:00',
            TemporalQuestionShape::anchor('Quoi de neuf depuis la semaine dernière ?', $jeudi)?->toDateTimeString());
    }

    public function test_une_question_sans_reperage_temporel_n_invente_aucune_date(): void
    {
        // LA regle du mandat : ne jamais simuler « depuis mardi ». Une question
        // sans repere porte sur toute l'histoire, ce qui est sa lecture la plus
        // large et la seule qui ne fabrique rien.
        $this->assertNull(TemporalQuestionShape::anchor('Quoi de neuf ?'));
        $this->assertNull(TemporalQuestionShape::anchor("Qu'est-ce qui a changé ?"));
    }

    // ──────────────────────────────── le lignage

    public function test_le_delta_rend_ajouts_corrections_et_retraits_avec_les_preuves_des_deux_cotes(): void
    {
        $histoire = $this->histoire();

        $evenements = app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, $this->alice, null);

        $par = fn (string $type): array => array_values(array_filter(
            $evenements, static fn (array $e): bool => $e['type'] === $type,
        ));

        $ajouts = $par(LoopClaimDelta::ADDED);
        $corrections = $par(LoopClaimDelta::UPDATED);
        $retraits = $par(LoopClaimDelta::RETRACTED);

        $this->assertCount(1, $corrections, 'le budget a ete corrige');
        $this->assertCount(1, $retraits, 'la date a ete retiree');
        $this->assertCount(1, $ajouts, 'le fournisseur est un ajout jamais touche');

        // La correction : l'ancien ET le nouveau, avec les preuves des deux.
        $this->assertStringContainsString('486 000', (string) $corrections[0]['ancien']);
        $this->assertStringContainsString('531 000', (string) $corrections[0]['nouveau']);
        $this->assertSame([(string) $histoire['msg_budget']->id], $corrections[0]['preuves_ancien']);
        $this->assertSame([(string) $histoire['msg_correction']->id], $corrections[0]['preuves_nouveau']);

        // Le retrait : aucun remplacant inventé, et la raison est rendue.
        $this->assertStringContainsString('15 novembre', (string) $retraits[0]['ancien']);
        $this->assertNull($retraits[0]['nouveau'],
            'un RETRACT ne fabrique aucun remplacant : le delta ne doit pas en inventer un non plus');
        $this->assertStringContainsString('plus valable', (string) $retraits[0]['raison']);
        $this->assertSame([(string) $histoire['msg_retrait']->id], $retraits[0]['preuves_nouveau']);
    }

    public function test_une_version_remplacee_n_est_pas_comptee_une_seconde_fois(): void
    {
        $this->histoire();

        $evenements = app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, $this->alice, null);

        // Trois evenements, pas quatre : la ligne archivee du budget est le DOS
        // de la correction, deja rapportee par son successeur. La compter
        // ferait dire au delta qu'il s'est passe deux choses.
        $this->assertCount(3, $evenements);

        $idsArchives = DerivedKnowledgeNote::query()
            ->where('status', DerivedKnowledgeNote::STATUS_SUPERSEDED)
            ->whereNotNull('superseded_by_id')->pluck('id')->map('strval')->all();

        $this->assertNotEmpty($idsArchives, 'PREMISSE : une version remplacee existe bien');

        foreach ($evenements as $e) {
            $this->assertNotContains($e['claim_id'], $idsArchives);
        }
    }

    public function test_le_delta_ignore_derived_at_et_ne_lit_que_le_temps_des_humains(): void
    {
        $histoire = $this->histoire();

        // Le sabotage du temps : on recule `derived_at` et `superseded_at` de
        // toutes les lignes a une date absurde. Si le delta les lisait, la
        // fenetre « depuis mardi » ne rendrait plus rien.
        DerivedKnowledgeNote::query()->update([
            'derived_at' => now()->subYears(3),
            'superseded_at' => now()->subYears(3),
        ]);

        // Sans ancre : les TROIS chemins de datation doivent sortir, et chacun
        // doit avoir echappe a la date de machine. Une premiere version posait
        // une ancre, et seul le chemin du RETRAIT — date par ses preuves —
        // survivait au sabotage : le test restait vert alors que l'ajout et la
        // correction lisaient bien `derived_at`.
        $evenements = app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, $this->alice, null);

        $types = array_unique(array_column($evenements, 'type'));
        sort($types);

        $this->assertSame(
            [LoopClaimDelta::ADDED, LoopClaimDelta::RETRACTED, LoopClaimDelta::UPDATED],
            $types,
            'les trois natures d evenement doivent etre presentes : chacune a son propre chemin de datation',
        );

        foreach ($evenements as $e) {
            $this->assertTrue($e['quand']->greaterThan(now()->subYear()),
                "aucune date de machine ne doit remonter dans le delta ({$e['type']})");
        }

        // Et la date lue est exactement celle du message humain.
        $correction = array_values(array_filter($evenements,
            static fn (array $e): bool => $e['type'] === LoopClaimDelta::UPDATED))[0];

        $this->assertTrue(
            $correction['quand']->isSameDay($histoire['msg_correction']->created_at),
            'une correction porte la date du message qui l a etablie',
        );
    }

    public function test_l_ancrage_temporel_ecarte_ce_qui_s_est_passe_avant(): void
    {
        $histoire = $this->histoire();

        // Ancre posee APRES la correction et le retrait, AVANT rien d'autre.
        $apres = Carbon::instance($histoire['msg_retrait']->created_at)->addHour();

        $this->assertSame([], app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, $this->alice, $apres),
            'une ancre posterieure a tout ne doit rien rendre');

        // Ancre posee entre l'etat initial et les changements : seuls la
        // correction et le retrait doivent sortir. Elle doit tomber apres les
        // TROIS enonces d'origine — une premiere version ne depassait que le
        // budget, et l'ajout du fournisseur entrait legitimement dans le delta.
        $entre = now()->subDays(10);
        $evenements = app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, $this->alice, $entre);

        $types = array_column($evenements, 'type');
        sort($types);

        $this->assertSame([LoopClaimDelta::RETRACTED, LoopClaimDelta::UPDATED], $types);
    }

    // ──────────────────────────────── l'ACL, sans variante historique

    public function test_qui_ne_voit_pas_la_boucle_ne_lit_pas_son_histoire(): void
    {
        $this->histoire();

        // PREMISSE : l'histoire existe, et un membre de la Boucle la lit.
        $this->assertNotEmpty(app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, $this->bob, null));

        // Carol est du MEME tenant et n'est pas membre de cette Boucle.
        $this->assertSame([], app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, $this->carol, null),
            'l acces a l histoire d une Boucle EST l acces a la Boucle');

        // Sans utilisateur : ferme par defaut, jamais ouvert.
        $this->assertSame([], app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, null, null));
    }

    public function test_un_membre_qui_quitte_la_boucle_perd_son_histoire_au_tour_suivant(): void
    {
        $this->histoire();

        $this->assertNotEmpty(app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, $this->bob, null),
            'PREMISSE : Bob, membre actif, lit bien l histoire');

        LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->bob->id)
            ->update(['status' => 'left']);

        // Aucune reindexation, aucune synchronisation : la garde se lit.
        $this->assertSame([], app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, $this->bob->fresh(), null));
    }

    public function test_aucune_histoire_ne_franchit_la_frontiere_du_tenant(): void
    {
        $this->histoire();

        $autre = Organization::factory()->create(['is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autre->id]);

        $this->assertSame([], app(LoopClaimDelta::class)
            ->pour((string) $autre->id, $this->loop, $etranger, null));
        $this->assertSame([], app(LoopClaimDelta::class)
            ->pour((string) $this->organization->id, $this->loop, $etranger, null));
    }

    // ──────────────────────────────── les archives hors du READ courant

    public function test_les_archives_ne_sont_jamais_candidates_du_read_vectoriel(): void
    {
        $this->histoire();

        $indexeur = app(DerivedKnowledgeNoteIndexer::class);

        $actif = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();
        $archive = DerivedKnowledgeNote::query()
            ->where('status', DerivedKnowledgeNote::STATUS_SUPERSEDED)->firstOrFail();

        // PREMISSE, et elle n'est pas decorative : il faut prouver que
        // l'indexeur SAIT indexer cette famille. Une premiere version se
        // contentait de constater zero chunk sur des lignes que personne
        // n'avait jamais essaye d'indexer — le sabotage la laissait verte.
        $this->assertGreaterThan(0, $indexeur->synchronize($actif),
            'PREMISSE : un enonce actif est bien indexable');

        $this->assertSame(0, $indexeur->synchronize($archive),
            'une version archivee n est jamais servie : l histoire se lit par lignage');
        $this->assertSame(0, DossierChunk::where('derived_knowledge_note_id', $archive->id)->count(),
            'la rendre vectorielle ferait coexister deux etats dans une meme reponse');
    }

    // ──────────────────────────────── la source de contexte

    public function test_une_question_qui_ne_parle_pas_de_changement_ne_produit_aucune_histoire(): void
    {
        $this->histoire();

        $borne = $this->contexteBorne('Quel est le budget des travaux ?');

        $this->assertStringNotContainsString('[H1]', $borne->text);
        $this->assertSame([], array_values(array_filter(
            $borne->provenance, static fn (array $p): bool => ($p['source'] ?? '') === KnowledgeDeltaSource::NAME,
        )));
    }

    public function test_une_question_de_changement_produit_un_bloc_d_histoire_cite(): void
    {
        $this->histoire();

        $borne = $this->contexteBorne("Qu'est-ce qui a changé dans cette Boucle ?");

        $this->assertStringContainsString('[H1]', $borne->text);
        $this->assertStringContainsString('531 000', $borne->text);
        $this->assertStringContainsString('486 000', $borne->text,
            'une correction doit montrer ce qui etait dit AVANT : c est la moitie du delta');

        // LA garde contre la fenetre inventee, et elle se mesure ICI — pas sur
        // l'indice de forme. La question ne porte aucun reperage temporel :
        // l'histoire rendue doit donc couvrir TOUT, y compris un ajout vieux de
        // dix-huit jours. Un repli discret sur « les sept derniers jours »
        // repondrait a une question que personne n'a posee.
        $this->assertStringContainsString('Vaucanson', $borne->text,
            'sans ancre, le delta couvre toute l histoire connue : aucune fenetre par defaut');

        $histoire = array_values(array_filter(
            $borne->provenance, static fn (array $p): bool => ($p['source'] ?? '') === KnowledgeDeltaSource::NAME,
        ));

        $this->assertNotEmpty($histoire);

        foreach ($histoire as $p) {
            $this->assertStringStartsWith('H', (string) $p['ref']);
            $this->assertContains($p['change_type'],
                [LoopClaimDelta::ADDED, LoopClaimDelta::UPDATED, LoopClaimDelta::RETRACTED]);
            $this->assertArrayHasKey('evidence_before', $p);
            $this->assertArrayHasKey('evidence_after', $p);
        }
    }

    public function test_la_source_d_histoire_est_fermee_pour_qui_ne_voit_pas_la_boucle(): void
    {
        $this->histoire();

        $borne = $this->contexteBorne("Qu'est-ce qui a changé dans cette Boucle ?", $this->carol);

        $this->assertStringNotContainsString('[H1]', $borne->text);
        $this->assertStringNotContainsString('486 000', $borne->text);
    }

    // ──────────────────────────────── la consigne est REELLEMENT posee

    public function test_les_prompts_declarent_le_troisieme_espace_de_citation(): void
    {
        // Une migration de prompt qui ne trouve pas son repere et se contente
        // de ne rien faire est un no-op SILENCIEUX : le deploiement passe au
        // vert, la consigne n'est jamais posee, et la mesure suivante conclut a
        // tort que la retouche ne sert a rien. C'est arrive en T1537, avec
        // « preambule » contre « préambule ».
        //
        // Ce test mesure donc l'ETAT, pas l'intention de la migration.
        foreach (['loop_knowledge_answer', 'loop_hybrid_answer'] as $scenario) {
            $texte = (string) AdminAiPrompt::query()
                ->where('scenario_id', $scenario)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->value('prompt_text');

            $this->assertNotSame('', $texte, "PREMISSE : {$scenario} doit avoir un prompt actif");
            $this->assertStringContainsString('[H1]', $texte,
                "{$scenario} doit declarer le troisieme espace de citation");
            $this->assertStringContainsString('HISTORIQUE DE LA MÉMOIRE', $texte,
                "{$scenario} doit nommer la famille, accents compris");
        }
    }

    // ──────────────────────────────── le vrai Shell

    public function test_le_shell_cite_l_histoire_et_la_citation_survit_a_la_validation(): void
    {
        $this->histoire();

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Le budget est passé de 486 000 à 531 000 euros [H1], et la date du 15 novembre a été retirée [H2].',
            new Usage(40, 20), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $reponse = app(LoopKnowledgeAnswerService::class)
            ->answer($this->loop->fresh(), $this->alice, "Qu'est-ce qui a changé dans cette Boucle ?");

        // Les references [Hn] doivent SURVIVRE : la validation ne connaissait
        // que [Mn] et [Sn], et effacait donc toute citation d'histoire du texte
        // publie — la source etait reellement lue, reellement citee, et
        // disparaissait quand meme.
        $this->assertStringContainsString('[H1]', $reponse->answer);
        $this->assertStringContainsString('[H2]', $reponse->answer);
        $this->assertTrue($reponse->grounded);

        $refs = array_column($reponse->sources, 'ref');
        $this->assertContains('H1', $refs);
        $this->assertContains('H2', $refs);
    }

    public function test_une_reference_d_histoire_inventee_est_effacee_comme_les_autres(): void
    {
        $this->histoire();

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Le budget a changé [H1]. Et le calendrier a été revu [H9].',
            new Usage(40, 20), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $reponse = app(LoopKnowledgeAnswerService::class)
            ->answer($this->loop->fresh(), $this->alice, "Qu'est-ce qui a changé dans cette Boucle ?");

        $this->assertStringContainsString('[H1]', $reponse->answer);
        $this->assertStringNotContainsString('[H9]', $reponse->answer,
            'une reference qui ne designe aucune source ne doit pas rester sous les yeux du membre');
        $this->assertNotContains('H9', array_column($reponse->sources, 'ref'));
    }

    // ────────────────────────────────────────────────── helpers

    /**
     * Une histoire reelle : un budget corrige, une date retiree, un
     * fournisseur jamais touche.
     *
     * Ecrite DIRECTEMENT en base et non par le compilateur : ce fichier mesure
     * la LECTURE du lignage, et passer par un modele double ferait dependre
     * chaque assertion d'un patch que le test aurait lui-meme dicte.
     *
     * @return array{msg_budget: LoopMessage, msg_correction: LoopMessage, msg_retrait: LoopMessage}
     */
    private function histoire(): array
    {
        $dossierId = (string) Dossier::where('loop_id', $this->loop->id)->value('id');

        $msgBudget = $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.', now()->subDays(20));
        $msgDate = $this->message('La mairie veut le plan de circulation avant le 15 novembre.', now()->subDays(20));
        $msgFournisseur = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.', now()->subDays(18));
        $msgCorrection = $this->message('Correction : le budget travaux passe a 531 000 euros.', now()->subDays(2));
        $msgRetrait = $this->message('La date du 15 novembre ne tient plus, aucune nouvelle echeance.', now()->subDay());

        $sujetBudget = (string) Str::uuid();

        // Le budget, v1 — remplacee.
        $budgetV1 = $this->note($dossierId, $sujetBudget, 1,
            'Le budget travaux de Belleville est de 486 000 euros.',
            [(string) $msgBudget->id], $msgBudget->created_at, DerivedKnowledgeNote::STATUS_SUPERSEDED);

        // Le budget, v2 — active, et c'est ELLE qui porte la correction.
        $budgetV2 = $this->note($dossierId, $sujetBudget, 2,
            'Le budget travaux de Belleville est de 531 000 euros.',
            [(string) $msgCorrection->id], $msgCorrection->created_at, DerivedKnowledgeNote::STATUS_ACTIVE);

        $budgetV1->forceFill(['superseded_by_id' => $budgetV2->id, 'superseded_at' => now()])->save();

        // La date — retiree, sans remplacant.
        $date = $this->note($dossierId, (string) Str::uuid(), 1,
            'Le plan de circulation est attendu avant le 15 novembre.',
            [(string) $msgDate->id], $msgDate->created_at, DerivedKnowledgeNote::STATUS_SUPERSEDED);

        $provenance = $date->provenance;
        $provenance['retracted_reason'] = 'la date n est plus valable';
        $provenance['retracted_evidence'] = [(string) $msgRetrait->id];
        $date->forceFill(['provenance' => $provenance, 'superseded_at' => now()])->save();

        // Le fournisseur — jamais touche.
        $this->note($dossierId, (string) Str::uuid(), 1,
            'Vaucanson realise la charpente, avec une hausse de 12%.',
            [(string) $msgFournisseur->id], $msgFournisseur->created_at, DerivedKnowledgeNote::STATUS_ACTIVE);

        return ['msg_budget' => $msgBudget, 'msg_correction' => $msgCorrection, 'msg_retrait' => $msgRetrait];
    }

    /** @param  list<string>  $preuves */
    private function note(string $dossierId, string $sujet, int $version, string $contenu,
        array $preuves, \DateTimeInterface $observeA, string $status): DerivedKnowledgeNote
    {
        return DerivedKnowledgeNote::create([
            'organization_id' => $this->organization->id,
            'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
            'kind' => DerivedKnowledgeNote::KIND_CLAIM,
            'source_loop_id' => $this->loop->id,
            'dossier_id' => $dossierId,
            'subject_key' => $sujet,
            'content' => $contenu,
            'source_fingerprint' => hash('sha256', $sujet.'|'.$version.'|'.$contenu),
            'provenance' => ['source_loop_message_ids' => $preuves, 'derived_by' => 'loop_conversation_knowledge'],
            'observed_at' => $observeA,
            'derived_at' => now(),
            'version' => $version,
            'status' => $status,
        ]);
    }

    private function message(string $body, \DateTimeInterface $quand): LoopMessage
    {
        $m = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => $body,
            'type' => 'user',
        ]);

        $m->forceFill(['created_at' => $quand, 'updated_at' => $quand])->save();

        return $m;
    }

    private function contexteBorne(string $question, ?User $user = null): ContexteBorne
    {
        return app(ContextBuilder::class)->build(
            new ContexteIa(
                organizationId: (string) $this->organization->id,
                userId: (string) ($user ?? $this->alice)->id,
                loopId: (string) $this->loop->id,
                locale: 'fr',
                capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
                correlationId: (string) Str::uuid(),
                query: $question,
            ),
            app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER),
        );
    }
}
