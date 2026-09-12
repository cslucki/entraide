<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\PeopleQuestionShape;
use App\Models\AiShellMessage;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellTurnCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1546 — « Qui pourrait les aider ? » puis « Et moi ? ».
 *
 * ## Les quatre regles que ces tests protegent
 *
 *  1. **le serveur garde l'univers.** Le Shell n'interroge aucun annuaire :
 *     il delegue a `RelevantPeopleService`, qui consomme lui-meme
 *     `EligiblePeopleService`. Un membre d'un autre tenant, ou un membre de
 *     l'Organization etranger a la Boucle, n'entre dans aucune requete ;
 *  2. **le referent est HERITE, et revalide.** Sans referent, le tour ne
 *     s'ouvre pas et le chemin habituel reprend la main INTACT. Avec un
 *     referent, l'appartenance est rejouee a l'instant de la question — un
 *     identifiant de fil n'est jamais un droit ;
 *  3. **l'ambiguite non resolue BLOQUE.** Prendre le premier candidat « pour
 *     avancer » chercherait des personnes pour un projet que personne n'a
 *     designe ;
 *  4. **quatre etats qui se ressemblent restent distincts.** Ambiguite,
 *     absence de connaissance, absence de correspondance et impossibilite de
 *     mesurer ne disent pas la meme chose. Les confondre fabriquerait une
 *     certitude que personne n'a.
 */
class TASK1546PeopleAndSelfTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    /** Celle qui pose les questions. */
    private User $camille;

    /** Celui dont on parle au tour de reference. */
    private User $marin;

    /** La personne que le besoin du projet designe. */
    private User $salome;

    private Loop $aria;

    private Loop $revive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'locale' => 'fr',
            'ai_profiles_enabled' => true,
        ]);
        app()->instance('current_organization', $this->organization);

        $this->camille = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Camille Dubreuil']);
        $this->marin = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Marin Delcourt']);
        $this->salome = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Salome Vasseur']);

        $this->aria = $this->boucle('ARIA', [$this->marin, $this->camille, $this->salome]);
        $this->revive = $this->boucle('REVIVE', [$this->marin, $this->camille, $this->salome]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1546',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    // ──────────────────────────────── la forme de la question

    public function test_la_forme_reconnait_une_demande_de_personnes(): void
    {
        $this->assertTrue(PeopleQuestionShape::isPeople('Qui pourrait les aider ?'));
        $this->assertTrue(PeopleQuestionShape::isPeople("Qui peut m'aider ?"));
        $this->assertTrue(PeopleQuestionShape::isPeople('Who could help?'));

        $this->assertFalse(PeopleQuestionShape::isPeople('Comment avance ARIA ?'));
        $this->assertFalse(PeopleQuestionShape::isPeople(''));
    }

    /**
     * `str_contains()` nu declare « et moins de budget » comme une question
     * sur soi : « et moins » PORTE « et moi ». L'appariement est donc borne
     * aux mots.
     */
    public function test_la_forme_ne_confond_pas_et_moi_avec_et_moins(): void
    {
        $this->assertTrue(PeopleQuestionShape::isSelf('Et moi ?'));
        $this->assertTrue(PeopleQuestionShape::isSelf('Et moi dans tout ca ?'));
        $this->assertTrue(PeopleQuestionShape::isSelf('What about me?'));

        $this->assertFalse(PeopleQuestionShape::isSelf('Et moins de budget, ca donne quoi ?'),
            '« et moins » ne doit jamais etre lu comme « et moi »');
        $this->assertFalse(PeopleQuestionShape::isSelf('Et Marin ?'),
            'nommer quelqu un d autre n est pas une question sur soi');
    }

    public function test_une_phrase_qui_porte_les_deux_formes_est_une_question_sur_soi(): void
    {
        $prompt = 'Et moi, je pourrais aider ?';

        $this->assertTrue(PeopleQuestionShape::isSelf($prompt));

        $this->referentResolu();
        $tour = $this->demander($prompt);

        $this->assertSame(AiShellResponder::PRODUCER_SELF_MATCHING, $tour->metadata['producer'] ?? null,
            'la personne interroge sa PROPRE place : rendre une liste d autres membres serait repondre a cote');
    }

    // ──────────────────────────────── le referent herite

    /**
     * La garde etroite est ce qui rend ce tour purement ADDITIF : hors d'une
     * conversation qui a deja etabli un projet, « qui pourrait m'aider ? »
     * continue d'aller a la clarification d'entraide.
     */
    public function test_sans_referent_le_tour_de_personnes_ne_s_ouvre_pas(): void
    {
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);

        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_PEOPLE_MATCHING, $tour->metadata['producer'] ?? null);
        $this->assertNotSame(AiShellResponder::PRODUCER_SELF_MATCHING, $tour->metadata['producer'] ?? null);
    }

    public function test_un_identifiant_de_fil_n_est_jamais_un_droit(): void
    {
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);
        $this->referentResolu();

        // Camille quitte la Boucle ENTRE les deux tours : le referent du fil
        // designe une Boucle qu'elle n'a plus le droit de lire.
        LoopMember::query()
            ->where('loop_id', $this->aria->id)
            ->where('user_id', $this->camille->id)
            ->update(['status' => 'left']);

        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_PEOPLE_MATCHING, $tour->metadata['producer'] ?? null,
            'le tour s efface au lieu de nommer un projet qu il n a plus le droit de lire');
        $this->assertStringNotContainsString('ARIA', $tour->content);
    }

    // ──────────────────────────────── People

    public function test_qui_pourrait_les_aider_rend_les_personnes_autorisees_et_leurs_raisons(): void
    {
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);
        $this->referentResolu();

        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertSame(AiShellResponder::PRODUCER_PEOPLE_MATCHING, $tour->metadata['producer'] ?? null);
        $this->assertSame((string) $this->aria->id, $tour->metadata['people']['referent_loop_id'] ?? null);
        $this->assertSame([(string) $this->salome->id], $tour->metadata['people']['selected_user_ids'] ?? []);

        $this->assertStringContainsString('Salome', $tour->content);
        $this->assertStringContainsString('Charpente traditionnelle', $tour->content,
            'la raison est le libelle DECLARE, jamais une reformulation');
    }

    /**
     * Le texte et les cartes sortent de la MEME primitive et du MEME besoin :
     * ils ne peuvent pas diverger.
     */
    public function test_les_cartes_du_tour_portent_les_memes_personnes_que_le_texte(): void
    {
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);
        $this->referentResolu();

        $tour = $this->demander('Qui pourrait les aider ?');

        $personnes = array_values(array_filter(
            $tour->metadata['cards'] ?? [],
            static fn (array $card): bool => ($card['type'] ?? null) === AiShellTurnCards::TYPE_PERSON,
        ));

        $this->assertCount(1, $personnes);
        $this->assertSame((string) $this->salome->id, $personnes[0]['user_id']);
        $this->assertSame((string) $this->aria->id, $personnes[0]['loop_id']);
    }

    public function test_un_membre_sans_profil_publie_n_est_jamais_candidat(): void
    {
        // Le profil de Marin declare exactement le besoin — mais il est en
        // brouillon. La publication vaut consentement de visibilite.
        MemberAiProfile::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->marin->id,
            'status' => MemberAiProfile::STATUS_DRAFT,
            'skills' => ['Charpente traditionnelle'],
            'help_types' => [],
            'problems_helped' => [],
        ]);

        $this->referentResolu();
        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertSame([], $tour->metadata['people']['selected_user_ids'] ?? null);
        $this->assertStringNotContainsString('Marin', $tour->content);
    }

    public function test_un_membre_de_l_organization_hors_de_la_boucle_n_est_jamais_candidat(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Nadia Fontaine']);
        $this->profilPublie($etranger, ['Charpente traditionnelle']);

        $this->referentResolu();
        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertSame([], $tour->metadata['people']['selected_user_ids'] ?? null);
        $this->assertStringNotContainsString('Nadia', $tour->content,
            'l univers est celui de la BOUCLE, pas de l Organization');
    }

    public function test_aucune_personne_d_un_autre_tenant_n_est_candidate(): void
    {
        $autre = Organization::factory()->create(['is_active' => true, 'ai_profiles_enabled' => true]);
        $outsider = User::factory()->create(['organization_id' => $autre->id, 'name' => 'Ilan Berthier']);

        MemberAiProfile::factory()->create([
            'organization_id' => $autre->id,
            'user_id' => $outsider->id,
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'published_at' => now(),
            'skills' => ['Charpente traditionnelle'],
            'help_types' => [],
            'problems_helped' => [],
        ]);

        // Et il est meme inscrit dans la Boucle : la frontiere ne doit pas
        // dependre de la seule absence d'appartenance.
        LoopMember::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->aria->id,
            'user_id' => $outsider->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->referentResolu();
        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertSame([], $tour->metadata['people']['selected_user_ids'] ?? null);
        $this->assertStringNotContainsString('Ilan', $tour->content);
    }

    /**
     * « Je n'ai rien appris sur ce projet » et « personne ne correspond » se
     * ressemblent a l'ecran et n'ont pas le meme sens.
     */
    public function test_zero_connaissance_n_est_pas_confondu_avec_zero_correspondance(): void
    {
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);

        // Un referent etabli sur REVIVE, dont aucun enonce ne survit.
        $this->referentResolu();
        DerivedKnowledgeNote::query()->update(['status' => DerivedKnowledgeNote::STATUS_SUPERSEDED]);

        $sansConnaissance = $this->demander('Qui pourrait les aider ?');

        $this->assertFalse($sansConnaissance->metadata['people']['need_derived'] ?? true);
        $this->assertStringContainsString("rien appris", $sansConnaissance->content);
        $this->assertStringNotContainsString('Personne,', $sansConnaissance->content);
    }

    public function test_zero_correspondance_est_un_resultat_propre(): void
    {
        $this->profilPublie($this->salome, ['Photographie argentique']);
        $this->referentResolu();

        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertTrue($tour->metadata['people']['need_derived'] ?? false);
        $this->assertSame([], $tour->metadata['people']['selected_user_ids'] ?? null);
        $this->assertStringContainsString('Personne', $tour->content);
    }

    // ──────────────────────────────── Self

    public function test_et_moi_mesure_l_utilisateur_authentifie_et_personne_d_autre(): void
    {
        $this->profilPublie($this->camille, ['Charpente traditionnelle']);
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);

        $this->referentResolu();
        $tour = $this->demander('Et moi ?');

        $this->assertSame(AiShellResponder::PRODUCER_SELF_MATCHING, $tour->metadata['producer'] ?? null);
        $this->assertSame((string) $this->camille->id, $tour->metadata['self']['user_id'] ?? null,
            'moi = l utilisateur AUTHENTIFIE du tour');
        $this->assertTrue($tour->metadata['self']['fits'] ?? false);

        $this->assertStringContainsString('Camille', $tour->content);
        $this->assertStringNotContainsString('Salome', $tour->content,
            '« et moi ? » ne rend aucune autre personne');
    }

    public function test_et_moi_n_affiche_aucune_carte_de_personne(): void
    {
        $this->profilPublie($this->camille, ['Charpente traditionnelle']);
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);

        $this->referentResolu();
        $tour = $this->demander('Et moi ?');

        $types = array_column($tour->metadata['cards'] ?? [], 'type');

        $this->assertNotContains(AiShellTurnCards::TYPE_PERSON, $types,
            'proposer d autres aidants a quelqu un qui envisage d aider serait repondre a cote');
    }

    /**
     * « Je ne peux pas le dire » n'est pas « vous ne correspondez pas ».
     */
    public function test_un_profil_non_publie_rend_une_mesure_impossible_pas_un_refus(): void
    {
        $this->referentResolu();
        $tour = $this->demander('Et moi ?');

        $this->assertTrue($tour->metadata['self']['authorized'] ?? false,
            'ce n est pas un refus de contexte');
        $this->assertFalse($tour->metadata['self']['assessable'] ?? true);
        $this->assertSame('profile_not_published', $tour->metadata['self']['not_assessable_reason'] ?? null);
        $this->assertStringContainsString("n'est pas publie", $tour->content);
    }

    public function test_un_profil_publie_sans_correspondance_le_dit_franchement(): void
    {
        $this->profilPublie($this->camille, ['Photographie argentique']);

        $this->referentResolu();
        $tour = $this->demander('Et moi ?');

        $this->assertTrue($tour->metadata['self']['assessable'] ?? false);
        $this->assertFalse($tour->metadata['self']['fits'] ?? true);
        $this->assertStringContainsString('Rien de ce que votre profil publie declare', $tour->content);
        $this->assertStringContainsString('disponibilite', $tour->content,
            'les limites de la mesure se disent, elles ne se devinent pas');
    }

    // ──────────────────────────────── ambiguite et correction

    public function test_une_ambiguite_non_resolue_bloque_tout_matching(): void
    {
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);

        $this->enonce($this->aria, $this->marin, 'Le chantier attend une expertise en charpente traditionnelle.', now()->subDays(3));
        $this->enonce($this->revive, $this->marin, 'La couverture en ardoise reste a chiffrer.', now()->subDays(2));

        $ambigu = $this->demander("Le projet dont Marin parlait, ca avance ?");
        $this->assertTrue($ambigu->metadata['reference']['ambiguous'] ?? false, 'PREMISSE : le tour precedent a demande de choisir');

        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertSame('unresolved_reference', $tour->metadata['people']['blocked_by'] ?? null);
        // `??` avalerait le null : la cle doit EXISTER et valoir null.
        $this->assertArrayHasKey('referent_loop_id', $tour->metadata['people']);
        $this->assertNull($tour->metadata['people']['referent_loop_id']);
        $this->assertSame([], array_column($tour->metadata['cards'] ?? [], 'user_id'),
            'aucune personne ne doit etre proposee pour un projet que personne n a designe');
        $this->assertStringNotContainsString('Salome', $tour->content);
    }

    public function test_l_ambiguite_bloque_aussi_la_question_sur_soi(): void
    {
        $this->profilPublie($this->camille, ['Charpente traditionnelle']);

        $this->enonce($this->aria, $this->marin, 'Le chantier attend une expertise en charpente traditionnelle.', now()->subDays(3));
        $this->enonce($this->revive, $this->marin, 'La couverture en ardoise reste a chiffrer.', now()->subDays(2));

        $this->demander("Le projet dont Marin parlait, ca avance ?");
        $tour = $this->demander('Et moi ?');

        $this->assertSame(AiShellResponder::PRODUCER_SELF_MATCHING, $tour->metadata['producer'] ?? null);
        $this->assertSame('unresolved_reference', $tour->metadata['self']['blocked_by'] ?? null);
    }

    /**
     * Le besoin se relit a la SOURCE a chaque tour. Heriter du texte affiche
     * ferait d'un referent corrige un besoin vide — une correction n'affiche
     * qu'une note de service — et le matching ne trouverait jamais personne.
     */
    public function test_la_correction_du_referent_recalcule_completement(): void
    {
        // Chaque projet appelle une competence differente, et une personne
        // differente : un recalcul incomplet se verrait.
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);

        $ardoise = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Theo Marchand']);
        LoopMember::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->revive->id,
            'user_id' => $ardoise->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->profilPublie($ardoise, ['Couverture ardoise']);

        $this->enonce($this->aria, $this->marin, 'Le chantier attend une expertise en charpente traditionnelle.', now()->subDays(3));
        $this->enonce($this->revive, $this->marin, 'La couverture en ardoise reste a chiffrer.', now()->subDays(2));

        $this->demander("Le projet dont Marin parlait, ca avance ?");

        $corrige = $this->demander('Non, je parlais de REVIVE.');
        $this->assertTrue($corrige->metadata['reference']['corrected'] ?? false, 'PREMISSE : le referent a ete corrige');

        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertSame((string) $this->revive->id, $tour->metadata['people']['referent_loop_id'] ?? null);
        $this->assertSame([(string) $ardoise->id], $tour->metadata['people']['selected_user_ids'] ?? null,
            'le besoin du referent CORRIGE, pas celui du tour ambigu');
        $this->assertStringContainsString('Theo', $tour->content);
        $this->assertStringNotContainsString('Salome', $tour->content);
    }

    // ──────────────────────────────── refus de contexte

    public function test_une_boucle_archivee_refuse_le_matching(): void
    {
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);
        $this->referentResolu();

        $this->aria->forceFill(['status' => 'archived'])->saveQuietly();

        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertFalse($tour->metadata['people']['authorized'] ?? true);
        $this->assertSame('loop_not_active', $tour->metadata['people']['refusal_reason'] ?? null);
        $this->assertStringNotContainsString('Salome', $tour->content,
            'un refus de contexte n est pas un ensemble vide : personne n est nomme');
    }

    public function test_les_profils_ia_desactives_refusent_le_matching(): void
    {
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);
        $this->referentResolu();

        $this->organization->forceFill(['ai_profiles_enabled' => false])->saveQuietly();

        $tour = $this->demander('Qui pourrait les aider ?');

        $this->assertFalse($tour->metadata['people']['authorized'] ?? true);
        $this->assertSame('ai_profiles_disabled', $tour->metadata['people']['refusal_reason'] ?? null);
        $this->assertStringNotContainsString('Salome', $tour->content);
    }

    // ──────────────────────────────── l'ordre des branches du Shell

    /**
     * La branche People passe AVANT les branches documentaires, et ce test
     * est la seule chose qui le prouve.
     *
     * Le Dossier est ici REELLEMENT capable de repondre — recherche mockee
     * qui rend un extrait, agent documentaire prepare. Sans la priorite,
     * `dossierAnswerTurn()` repondrait « et moi ? » avec des extraits de
     * documents, c'est-a-dire a cote de la question.
     */
    public function test_sur_une_page_dossier_la_question_sur_soi_ne_part_pas_au_moteur_documentaire(): void
    {
        $this->profilPublie($this->camille, ['Charpente traditionnelle']);
        $this->referentResolu();

        $dossier = Dossier::query()->where('loop_id', $this->aria->id)->firstOrFail();

        $search = $this->mock(DossierSemanticSearchService::class);
        $search->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $search->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $dossier->id,
            'dossier_name' => $dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) Str::uuid(),
            'filename' => 'rapport.docx',
            'mime_type' => 'application/pdf',
            'chunk_index' => 2,
            'content' => 'Le chantier attend une expertise en charpente traditionnelle.',
            'distance' => 0.2,
        ]])->byDefault();

        LoopKnowledgeAgent::fake([
            new TextResponse('Reponse documentaire. [S1]', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
            new TextResponse('Reponse documentaire. [S1]', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);

        $this->actingAs($this->camille);

        $contexte = app(AiShellPageContext::class)->resolve(
            $this->camille, $this->organization, AiShellPageContext::KIND_DOSSIER, (string) $dossier->id,
        );

        $tour = app(AiShellResponder::class)
            ->respond($this->organization, $this->camille, 'Et moi ?', $contexte)['answer'];

        $this->assertSame(AiShellResponder::PRODUCER_SELF_MATCHING, $tour->metadata['producer'] ?? null,
            'une question sur soi n est pas une question documentaire, quelle que soit la page');
        $this->assertStringNotContainsString('Reponse documentaire', $tour->content);
    }

    // ──────────────────────────────── cout

    public function test_les_tours_de_personnes_n_appellent_aucun_modele(): void
    {
        $this->profilPublie($this->camille, ['Charpente traditionnelle']);
        $this->profilPublie($this->salome, ['Charpente traditionnelle']);

        $this->referentResolu();
        $this->demander('Qui pourrait les aider ?');
        $this->demander('Et moi ?');

        // Un modele n'aurait ici qu'une chose a apporter — elargir l'univers —
        // et c'est precisement ce qu'il ne doit pas faire.
        $this->assertSame(0, DB::table('ai_provider_invocations')->count());
    }

    // ────────────────────────────────────────────────── helpers

    /**
     * @param  list<User>  $membres
     */
    private function boucle(string $nom, array $membres): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->marin->id,
            'name' => $nom,
            'visibility' => 'private',
        ]);

        foreach ($membres as $membre) {
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
     * Un referent NON AMBIGU sur ARIA : un seul projet porte un enonce ecrit
     * par Marin, et son texte appelle une competence precise.
     */
    private function referentResolu(): AiShellMessage
    {
        $this->enonce($this->aria, $this->marin, 'Le chantier attend une expertise en charpente traditionnelle.', now()->subDays(2));

        $tour = $this->demander("Le projet dont Marin parlait, ca avance ?");

        $this->assertFalse($tour->metadata['reference']['ambiguous'] ?? true,
            'PREMISSE : le referent doit etre etabli et non ambigu');

        return $tour;
    }

    /**
     * @param  list<string>  $skills
     */
    private function profilPublie(User $user, array $skills): MemberAiProfile
    {
        return MemberAiProfile::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'published_at' => now(),
            // Les valeurs du factory sont aleatoires : un banc d'appariement
            // exige des champs signaux MAITRISES.
            'skills' => $skills,
            'help_types' => [],
            'problems_helped' => [],
        ]);
    }

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
