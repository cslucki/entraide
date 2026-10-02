<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\BlogPost;
use App\Models\CourseAssignment;
use App\Models\CourseModule;
use App\Models\CourseQuiz;
use App\Models\CourseQuizAttempt;
use App\Models\CourseSequence;
use App\Models\CourseSequenceProgress;
use App\Models\CourseSetting;
use App\Models\CourseSubmission;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use App\Support\ScenarioPacks\Manifest\ManifestNotLoadableException;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadResult;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadService;
use App\Support\ScenarioPacks\Manifest\ManifestScenarioPack;
use App\Support\ScenarioPacks\Manifest\ManifestTrainingApplier;
use App\Support\ScenarioPacks\Manifest\ScenarioManifest;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Tests\TestCase;

/**
 * TASK-1644 — le monde TRAINING d'AMT, du chargement au retrait.
 *
 * T1642 prouvait le socle, T1643 le contenu CORE ; cette suite prouve la
 * formation. Les nombres attendus sont ceux que la fixture VERSIONNEE declare,
 * comptes a la main sur le document : deux modules, trois sequences, trois
 * progressions, un travail a rendre, deux remises.
 *
 * ## Comment cette suite prouve les dates
 *
 * Le manifeste ne declare pas des instants, il declare des OFFSETS depuis
 * l'unique ancre du chargement (spec 6.3). Un test ne peut donc pas comparer a
 * une date en dur — l'ancre est prise au moment du Load. Ce qu'il peut comparer,
 * et ce qui est en realite bien plus fort, ce sont les ECARTS entre deux dates :
 * ils valent exactement la difference des offsets declares, a la seconde, quelle
 * que soit l'ancre. Un `now()` cable a la place d'un offset ecrase cet ecart et
 * fait rougir l'assertion.
 *
 * Le meme raisonnement donne la preuve de l'ancre PARTAGEE : l'ecart entre une
 * date CORE (`published_at` d'un article) et une date TRAINING (`started_at`
 * d'une progression) vaut exactement la difference de leurs offsets si — et
 * seulement si — les deux appliers derivent de la MEME ancre.
 */
class ManifestTrainingLoaderTest extends TestCase
{
    private function manifestJson(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
    }

    private function approvedDigest(): string
    {
        return (string) (new ScenarioManifestValidator)->validate($this->manifestJson())->digest();
    }

    private function load(): ManifestSandboxLoadResult
    {
        return app(ManifestSandboxLoadService::class)->load($this->manifestJson(), $this->approvedDigest());
    }

    private function pack(): ManifestScenarioPack
    {
        return new ManifestScenarioPack(
            ScenarioManifest::fromApprovedJson($this->manifestJson(), $this->approvedDigest())
        );
    }

    // =====================================================================
    // Le monde TRAINING tel que le manifeste le declare
    // =====================================================================

    public function test_the_amt_training_world_is_materialised_exactly_as_declared(): void
    {
        $organization = $this->load()->organization;
        $scope = ['organization_id' => $organization->id];

        $this->assertSame(2, CourseModule::query()->where($scope)->count());
        $this->assertSame(3, CourseSequence::query()->where($scope)->count());
        $this->assertSame(3, CourseSequenceProgress::query()->where($scope)->count());
        $this->assertSame(1, CourseAssignment::query()->where($scope)->count());
        $this->assertSame(2, CourseSubmission::query()->where($scope)->count());
    }

    /**
     * Chaque objet TRAINING est gouverne par la Boucle `training`, jamais par
     * l'autre Boucle du manifeste (`help-general`, de type `general`).
     */
    public function test_every_training_object_belongs_to_the_training_loop(): void
    {
        $organization = $this->load()->organization;

        $training = Loop::query()->where('organization_id', $organization->id)->where('type', 'training')->sole();
        $general = Loop::query()->where('organization_id', $organization->id)->where('type', 'general')->sole();

        $this->assertSame(
            2,
            CourseModule::query()->where('loop_id', $training->id)->count(),
            'Both declared modules belong to the training loop.'
        );
        $this->assertSame(0, CourseModule::query()->where('loop_id', $general->id)->count());
        $this->assertSame(1, CourseAssignment::query()->where('loop_id', $training->id)->count());
        $this->assertSame(0, CourseAssignment::query()->where('loop_id', $general->id)->count());

        // Les sequences n'ont pas de `loop_id` : leur rattachement passe par
        // leur module. On le verifie donc par la jointure, sans quoi une
        // sequence pourrait pendre a un module d'une autre Boucle sans que rien
        // ne le dise.
        $moduleIds = CourseModule::query()->where('loop_id', $training->id)->pluck('id');
        $this->assertSame(3, CourseSequence::query()->whereIn('course_module_id', $moduleIds)->count());
    }

    public function test_modules_carry_their_declared_title_summary_author_and_position(): void
    {
        $organization = $this->load()->organization;

        $modules = CourseModule::query()
            ->where('organization_id', $organization->id)
            ->orderBy('position')
            ->get();

        $this->assertSame(['Fondations', 'Évaluation'], $modules->pluck('title')->all());
        $this->assertSame('Comprendre les limites et formuler une demande.', $modules[0]->summary);
        $this->assertSame('Vérifier faits, sources et utilité.', $modules[1]->summary);

        // Positions CONTIGUES A PARTIR DE 0 (spec 12.1). La primitive
        // canonique calcule `max(position) + 1`, donc 1 puis 2 sur une Boucle
        // vide : sans l'alignement explicite du loader, cette assertion rougit.
        $this->assertSame([0, 1], $modules->pluck('position')->all());

        // Chaque module porte SON auteur declare, pas celui du premier.
        $trainer1 = $this->userByEmailLocal($organization, 'nora.martin');
        $trainer2 = $this->userByEmailLocal($organization, 'samir.diallo');

        $this->assertSame($trainer1->id, $modules[0]->created_by);
        $this->assertSame($trainer2->id, $modules[1]->created_by);
    }

    public function test_sequences_carry_their_position_validation_flag_and_author(): void
    {
        $organization = $this->load()->organization;

        $foundations = $this->moduleByTitle($organization, 'Fondations');
        $evaluation = $this->moduleByTitle($organization, 'Évaluation');

        $foundationSequences = CourseSequence::query()
            ->where('course_module_id', $foundations->id)
            ->orderBy('position')
            ->get();

        $this->assertSame(['Lire la charte', 'Structurer un prompt'], $foundationSequences->pluck('title')->all());
        $this->assertSame([0, 1], $foundationSequences->pluck('position')->all());

        $evaluate = CourseSequence::query()->where('course_module_id', $evaluation->id)->sole();
        $this->assertSame('Évaluer une réponse', $evaluate->title);
        $this->assertSame(0, $evaluate->position);

        // `requires_validation` n'est PAS expose par `addSequence()` : sans la
        // pose explicite du loader, les trois sequences resteraient au defaut
        // `false`, et une sequence qui attend un regard n'en attendrait plus.
        $this->assertFalse($foundationSequences[0]->requires_validation);
        $this->assertFalse($foundationSequences[1]->requires_validation);
        $this->assertTrue($evaluate->requires_validation);

        $trainer2 = $this->userByEmailLocal($organization, 'samir.diallo');
        $this->assertSame($trainer2->id, $foundationSequences[1]->created_by);
    }

    /**
     * Les trois variantes de `content` (spec 12.2), et surtout le fait qu'une
     * reference reste une REFERENCE : rien n'est recopie, donc rien ne diverge
     * de l'original.
     */
    public function test_each_sequence_content_variant_points_at_the_real_object(): void
    {
        $organization = $this->load()->organization;

        $charter = $this->sequenceByTitle($organization, 'Lire la charte');
        $prompt = $this->sequenceByTitle($organization, 'Structurer un prompt');
        $evaluate = $this->sequenceByTitle($organization, 'Évaluer une réponse');

        // Variante `article` : la sequence pointe vers l'Article declare par
        // CORE, pas vers une copie de son texte.
        $article = BlogPost::query()
            ->where('organization_id', $organization->id)
            ->where('title', "Charte d'usage responsable")
            ->sole();

        $this->assertSame(CourseSequence::TYPE_ARTICLE, $charter->contentType());
        $this->assertSame($article->id, $charter->blog_post_id);
        $this->assertNull($charter->dossier_file_id);
        $this->assertNull($charter->body, 'An article-backed sequence stores no copy of the text.');

        // Variante `file`.
        $file = DossierFile::query()
            ->where('organization_id', $organization->id)
            ->where('original_name', 'guide-prompt.md')
            ->sole();

        $this->assertSame(CourseSequence::TYPE_FILE, $prompt->contentType());
        $this->assertSame($file->id, $prompt->dossier_file_id);
        $this->assertNull($prompt->blog_post_id);
        $this->assertNull($prompt->body);

        // Variante `text` : le corps est ecrit sur place, aucune reference.
        $this->assertSame(CourseSequence::TYPE_TEXT, $evaluate->contentType());
        $this->assertSame('Comparez la réponse aux sources et notez les incertitudes.', $evaluate->body);
        $this->assertNull($evaluate->blog_post_id);
        $this->assertNull($evaluate->dossier_file_id);
    }

    /**
     * Le travail a rendre, sa sequence, et sa date limite DEVANT nous.
     *
     * `due_offset_minutes` est le seul offset legitimement positif de TRAINING :
     * AMT declare +20160 (J+14). Un loader qui bornerait les offsets au passe
     * ferait d'une echeance a venir une echeance depassee.
     */
    public function test_the_assignment_carries_its_sequence_position_and_future_due_date(): void
    {
        $organization = $this->load()->organization;

        $assignment = CourseAssignment::query()->where('organization_id', $organization->id)->sole();

        $this->assertSame('Analyser une réponse IA', $assignment->title);
        $this->assertSame('Rendre une analyse courte avec deux sources vérifiées.', $assignment->brief);
        $this->assertSame(0, $assignment->position);
        $this->assertNull($assignment->archived_at);

        $evaluate = $this->sequenceByTitle($organization, 'Évaluer une réponse');
        $this->assertSame($evaluate->id, $assignment->course_sequence_id);

        $this->assertNotNull($assignment->due_at);
        $this->assertFalse($assignment->isOverdue(), 'A +20160 minute offset is a deadline in the future.');

        $trainer2 = $this->userByEmailLocal($organization, 'samir.diallo');
        $this->assertSame($trainer2->id, $assignment->created_by);
    }

    // =====================================================================
    // L'histoire declaree — ce que les services canoniques ne savent PAS ecrire
    // =====================================================================

    /**
     * Les trois progressions, avec leurs statuts et leurs relecteurs.
     */
    public function test_progress_rows_carry_their_declared_status_and_reviewers(): void
    {
        $organization = $this->load()->organization;

        $student1 = $this->userByEmailLocal($organization, 'student-01');
        $student2 = $this->userByEmailLocal($organization, 'student-02');
        $trainer1 = $this->userByEmailLocal($organization, 'nora.martin');
        $trainer2 = $this->userByEmailLocal($organization, 'samir.diallo');

        $charter = $this->progressOf($organization, 'Lire la charte', $student1);
        $this->assertSame(CourseSequenceProgress::STATUS_COMPLETED, $charter->status);
        $this->assertNull($charter->validated_by);
        $this->assertNull($charter->validated_at);
        $this->assertNull($charter->unlocked_by);

        $evaluate = $this->progressOf($organization, 'Évaluer une réponse', $student1);
        $this->assertSame(CourseSequenceProgress::STATUS_VALIDATED, $evaluate->status);
        $this->assertSame($trainer2->id, $evaluate->validated_by);
        $this->assertSame($trainer1->id, $evaluate->unlocked_by);
        $this->assertTrue($evaluate->isManuallyUnlocked());

        $prompt = $this->progressOf($organization, 'Structurer un prompt', $student2);
        $this->assertSame(CourseSequenceProgress::STATUS_IN_PROGRESS, $prompt->status);
        $this->assertNull($prompt->completed_at);

        // Le QCM est hors V1 : la colonne qui le porterait reste vide.
        $this->assertNull($evaluate->passed_quiz_id);
    }

    /**
     * La PREUVE que l'histoire declaree est materialisee, et non horodatee au
     * chargement.
     *
     * Les ecarts valent exactement la difference des offsets declares. Un
     * `now()` mis a la place de l'un d'eux les ecrase a zero.
     */
    public function test_progress_timestamps_reproduce_the_declared_history_not_the_load_instant(): void
    {
        $organization = $this->load()->organization;

        $student1 = $this->userByEmailLocal($organization, 'student-01');

        // sequence-charter : started -7200, completed -7100 => 100 minutes.
        $charter = $this->progressOf($organization, 'Lire la charte', $student1);
        $this->assertSame(100 * 60, $charter->completed_at->getTimestamp() - $charter->started_at->getTimestamp());
        $this->assertTrue($charter->started_at->isPast(), 'A started sequence started in the past.');

        // sequence-evaluate : unlocked -3100, started -3000, completed -2500,
        // validated -2400. Quatre instants DISTINCTS et ordonnes.
        $evaluate = $this->progressOf($organization, 'Évaluer une réponse', $student1);

        $this->assertSame(100 * 60, $evaluate->started_at->getTimestamp() - $evaluate->unlocked_at->getTimestamp());
        $this->assertSame(500 * 60, $evaluate->completed_at->getTimestamp() - $evaluate->started_at->getTimestamp());
        $this->assertSame(100 * 60, $evaluate->validated_at->getTimestamp() - $evaluate->completed_at->getTimestamp());
    }

    /**
     * L'etat que `CourseProgressService` REFUSE, et que le document declare
     * pourtant valide.
     *
     * student-02 est `in_progress` sur la DEUXIEME sequence du module sans
     * aucune ligne sur la premiere. En mode `sequential` — seul mode de V1 —
     * `assertAvailable()` leve dessus. C'est la raison mesurable pour laquelle
     * l'etat individuel ne passe pas par le service ; ce test la fige, pour
     * qu'un futur remaniement qui "reviendrait au service" rougisse au lieu de
     * charger un monde amputé.
     */
    public function test_the_loader_materialises_a_progress_the_sequential_path_rules_would_refuse(): void
    {
        $organization = $this->load()->organization;

        $student2 = $this->userByEmailLocal($organization, 'student-02');
        $charter = $this->sequenceByTitle($organization, 'Lire la charte');
        $prompt = $this->sequenceByTitle($organization, 'Structurer un prompt');

        // La premiere sequence du module n'a AUCUNE progression pour ce compte.
        $this->assertSame(0, CourseSequenceProgress::query()
            ->where('course_sequence_id', $charter->id)
            ->where('user_id', $student2->id)
            ->count());

        // La seconde en a une, `in_progress`.
        $this->assertSame(0, $charter->position <=> 0);
        $this->assertSame(1, $prompt->position);
        $this->assertSame(
            CourseSequenceProgress::STATUS_IN_PROGRESS,
            $this->progressOf($organization, 'Structurer un prompt', $student2)->status
        );
    }

    /**
     * L'autre etat inatteignable par le service : `completed_at` et
     * `validated_at` DISTINCTS sur une sequence a validation.
     *
     * `markCompleted()` y rend `submitted` sans ecrire `completed_at`, et
     * `validate()` le remplit par `now()` — donc a la meme seconde que
     * `validated_at`. Deux instants distincts prouvent que l'histoire vient du
     * document.
     */
    public function test_a_validated_progress_keeps_a_completion_distinct_from_its_validation(): void
    {
        $organization = $this->load()->organization;

        $evaluate = $this->progressOf(
            $organization,
            'Évaluer une réponse',
            $this->userByEmailLocal($organization, 'student-01')
        );
        $sequence = CourseSequence::query()->whereKey($evaluate->course_sequence_id)->sole();

        $this->assertTrue($sequence->requires_validation);
        $this->assertNotNull($evaluate->completed_at);
        $this->assertNotNull($evaluate->validated_at);
        $this->assertNotSame(
            $evaluate->completed_at->getTimestamp(),
            $evaluate->validated_at->getTimestamp(),
            'A declared completion and its later validation are two distinct instants.'
        );
        $this->assertTrue($evaluate->completed_at->lessThan($evaluate->validated_at));
    }

    public function test_submissions_carry_their_body_status_feedback_and_reviewer(): void
    {
        $organization = $this->load()->organization;

        $student1 = $this->userByEmailLocal($organization, 'student-01');
        $student2 = $this->userByEmailLocal($organization, 'student-02');
        $trainer2 = $this->userByEmailLocal($organization, 'samir.diallo');

        $assignment = CourseAssignment::query()->where('organization_id', $organization->id)->sole();

        $validated = CourseSubmission::query()
            ->where('course_assignment_id', $assignment->id)
            ->where('user_id', $student1->id)
            ->sole();

        $this->assertSame(CourseSubmission::STATUS_VALIDATED, $validated->status);
        $this->assertSame("J'ai distingué trois affirmations et vérifié deux sources.", $validated->body);
        $this->assertSame('Analyse claire ; conserver cette méthode.', $validated->feedback);
        $this->assertSame($trainer2->id, $validated->reviewed_by);
        $this->assertNull($validated->dossier_file_id);
        $this->assertTrue($validated->isFinished());

        // submitted -2200, reviewed -2000 => 200 minutes, dans cet ordre.
        $this->assertSame(200 * 60, $validated->reviewed_at->getTimestamp() - $validated->submitted_at->getTimestamp());

        // Un brouillon peut etre vide de tout sauf de son corps, et n'attend
        // personne : ni remise, ni relecture.
        $draft = CourseSubmission::query()
            ->where('course_assignment_id', $assignment->id)
            ->where('user_id', $student2->id)
            ->sole();

        $this->assertSame(CourseSubmission::STATUS_DRAFT, $draft->status);
        $this->assertSame("Brouillon de la grille d'analyse.", $draft->body);
        $this->assertNull($draft->submitted_at);
        $this->assertNull($draft->feedback);
        $this->assertNull($draft->reviewed_by);
        $this->assertNull($draft->reviewed_at);
        $this->assertFalse($draft->awaitsReview());
    }

    /**
     * Spec 6.3 : UN SEUL `load_started_at` pour tout le chargement.
     *
     * CORE et TRAINING sont deux appliers distincts. S'ils capturaient chacun
     * leur ancre, l'ecart entre une date CORE et une date TRAINING ne vaudrait
     * plus la difference exacte de leurs offsets declares.
     *
     *     article-charte-ia  published_offset_minutes = -20160  (CORE)
     *     progress charter   started_offset_minutes   =  -7200  (TRAINING)
     *     ecart attendu                              =  12960 minutes
     */
    public function test_core_and_training_offsets_derive_from_one_single_load_anchor(): void
    {
        $organization = $this->load()->organization;

        $article = BlogPost::query()
            ->where('organization_id', $organization->id)
            ->where('title', "Charte d'usage responsable")
            ->sole();

        $progress = $this->progressOf(
            $organization,
            'Lire la charte',
            $this->userByEmailLocal($organization, 'student-01')
        );

        $this->assertSame(
            12960 * 60,
            $progress->started_at->getTimestamp() - $article->published_at->getTimestamp(),
            'CORE and TRAINING must derive their offsets from the same single load anchor (spec 6.3).'
        );
    }

    // =====================================================================
    // Cards et perimetre
    // =====================================================================

    /**
     * Les trois Cards Training viennent du PRESET du type (spec 7) : le loader
     * n'a aucune Card a allumer lui-meme. Ce test le prouve plutot que de le
     * supposer — si le preset changeait, il faudrait le savoir ici.
     */
    public function test_the_training_cards_are_enabled_on_the_training_loop(): void
    {
        $organization = $this->load()->organization;

        $training = Loop::query()->where('organization_id', $organization->id)->where('type', 'training')->sole();

        $enabled = LoopCard::query()
            ->where('loop_id', $training->id)
            ->where('enabled', true)
            ->pluck('card_key')
            ->all();

        $this->assertContains('training.course_material', $enabled);
        $this->assertContains('training.progression', $enabled);
        $this->assertContains('training.assignments', $enabled);

        // Le QCM n'est pas au preset et n'est pas allume : le declarer avant de
        // le livrer serait promettre.
        $this->assertNotContains('training.quiz', $enabled);
    }

    /**
     * Ce que T1644 ne materialise PAS, et qui doit rester vide.
     */
    public function test_no_quiz_and_no_course_setting_is_ever_written(): void
    {
        $organization = $this->load()->organization;
        $scope = ['organization_id' => $organization->id];

        // Spec 12.6 : le QCM est reporte a Manifest V1.1, par DECISION.
        $this->assertSame(0, CourseQuiz::query()->where($scope)->count());
        $this->assertSame(0, CourseQuizAttempt::query()->count());

        // Aucun champ `path_mode` n'existe en V1 : une Boucle sans ligne est
        // sequentielle, defaut arrete par la migration. Ecrire la ligne serait
        // inventer un reglage que le document n'a pas approuve.
        $this->assertSame(0, CourseSetting::query()->where($scope)->count());
    }

    /**
     * Le monde CORE de T1643 n'a pas bouge d'un objet.
     *
     * TRAINING est un AJOUT. Une regression sur CORE — un article consomme, un
     * fichier deplace, une Card eteinte — se verrait ici et nulle part ailleurs
     * dans cette suite.
     */
    public function test_the_core_world_of_the_previous_task_is_untouched(): void
    {
        $organization = $this->load()->organization;
        $scope = ['organization_id' => $organization->id];

        $this->assertSame(22, User::query()->where($scope)->count());
        $this->assertSame(2, Loop::query()->where($scope)->count());
        $this->assertSame(3, BlogPost::query()->where($scope)->count());
        $this->assertSame(2, DossierFile::query()->where($scope)->count());
        $this->assertSame(44, LoopMember::query()->whereIn(
            'loop_id',
            Loop::query()->where($scope)->pluck('id')
        )->count());
    }

    // =====================================================================
    // Cycle de vie
    // =====================================================================

    public function test_an_exact_replay_duplicates_nothing_of_the_training_world(): void
    {
        $first = $this->load();
        $before = $this->census($first->organization);

        $replay = $this->load();

        $this->assertTrue($replay->wasReplay);
        $this->assertSame($first->organization->id, $replay->organization->id);
        $this->assertSame($before, $this->census($replay->organization));
    }

    public function test_replaying_the_pack_in_place_duplicates_nothing_of_the_training_world(): void
    {
        $result = $this->load();
        $before = $this->census($result->organization);

        // Le chemin que le resetter emprunte.
        app(ScenarioPackLoader::class)->load($this->pack(), $result->organization);

        $this->assertSame($before, $this->census($result->organization));
    }

    /**
     * Un rejeu ne DEPLACE pas l'histoire.
     *
     * Les objets retrouves au registre ne sont pas reecrits : leurs
     * horodatages restent ceux du premier chargement, alors que la seconde
     * passe a pris une nouvelle ancre. Sans cette propriete, chaque rejeu
     * ferait glisser toute la formation de quelques secondes.
     */
    public function test_replaying_does_not_move_the_declared_history(): void
    {
        $result = $this->load();
        $organization = $result->organization;

        $student1 = $this->userByEmailLocal($organization, 'student-01');
        $before = $this->progressOf($organization, 'Lire la charte', $student1)->started_at;

        app(ScenarioPackLoader::class)->load($this->pack(), $organization);

        $after = $this->progressOf($organization, 'Lire la charte', $student1)->started_at;

        $this->assertSame($before->getTimestamp(), $after->getTimestamp());
    }

    public function test_resetting_restores_the_training_world(): void
    {
        $result = $this->load();
        $organization = $result->organization;
        $before = $this->census($organization);

        // Trois derives posterieures au chargement, une par forme de perte :
        // un etat individuel, une remise, et une sequence entiere.
        CourseSequenceProgress::query()->where('organization_id', $organization->id)->limit(1)->delete();
        CourseSubmission::query()->where('organization_id', $organization->id)->limit(1)->delete();
        CourseSequence::query()->where('organization_id', $organization->id)
            ->where('title', 'Structurer un prompt')->delete();

        $this->assertNotSame($before, $this->census($organization));

        app(ScenarioPackResetter::class)->reset($this->pack(), $organization);

        $this->assertSame(
            $before,
            $this->census($organization),
            'Reset must return the sandbox to the training world the manifest describes.'
        );
    }

    /**
     * Le reset restaure aussi les RELATIONS, pas seulement les comptes.
     *
     * Une sequence detruite est recreee : son article reference, son drapeau de
     * validation et sa position doivent revenir a l'identique, sinon le monde
     * "restaure" serait un monde different qui compte pareil.
     */
    public function test_resetting_restores_the_relations_of_a_destroyed_sequence(): void
    {
        $result = $this->load();
        $organization = $result->organization;

        $evaluate = $this->sequenceByTitle($organization, 'Évaluer une réponse');
        $module = $evaluate->course_module_id;

        // La progression part avec elle (cascade sur `course_sequence_id`).
        CourseSequence::query()->whereKey($evaluate->id)->delete();

        app(ScenarioPackResetter::class)->reset($this->pack(), $organization);

        $restored = $this->sequenceByTitle($organization, 'Évaluer une réponse');

        $this->assertNotSame($evaluate->id, $restored->id, 'The row was really destroyed and rebuilt.');
        $this->assertSame($module, $restored->course_module_id);
        $this->assertTrue($restored->requires_validation);
        $this->assertSame(0, $restored->position);
        $this->assertSame('Comparez la réponse aux sources et notez les incertitudes.', $restored->body);

        // L'etat individuel qui pendait a cette sequence est la aussi.
        $this->assertSame(
            CourseSequenceProgress::STATUS_VALIDATED,
            $this->progressOf($organization, 'Évaluer une réponse', $this->userByEmailLocal($organization, 'student-01'))->status
        );
    }

    public function test_removing_the_pack_leaves_no_training_entity_behind(): void
    {
        $result = $this->load();
        $organization = $result->organization;

        app(ScenarioPackRemover::class)->remove($this->pack()->packId(), $organization);

        $scope = ['organization_id' => $organization->id];

        $this->assertSame(0, ScenarioPackEntity::query()->where($scope)->count());
        $this->assertSame(0, CourseModule::query()->where($scope)->count());
        $this->assertSame(0, CourseSequence::query()->where($scope)->count());
        $this->assertSame(0, CourseSequenceProgress::query()->where($scope)->count());
        $this->assertSame(0, CourseAssignment::query()->where($scope)->count());
        $this->assertSame(0, CourseSubmission::query()->where($scope)->count());

        // Et le socle part aussi : une remise oubliee au registre aurait bloque
        // la suppression des personas sur `course_submissions.user_id`, en
        // `ON DELETE RESTRICT` depuis TASK-1635.
        $this->assertSame(0, User::query()->where($scope)->count());
        $this->assertSame(0, Loop::query()->where($scope)->count());
    }

    // =====================================================================
    // Securite tenant
    // =====================================================================

    public function test_a_client_organization_receives_no_training_entity(): void
    {
        $client = Organization::create([
            'name' => 'Client reel',
            'slug' => 'amt-formation-ia',
            'is_active' => true,
            'locale' => 'fr',
        ]);

        $this->load();

        $scope = ['organization_id' => $client->id];

        $this->assertSame(0, CourseModule::query()->where($scope)->count());
        $this->assertSame(0, CourseSequence::query()->where($scope)->count());
        $this->assertSame(0, CourseSequenceProgress::query()->where($scope)->count());
        $this->assertSame(0, CourseAssignment::query()->where($scope)->count());
        $this->assertSame(0, CourseSubmission::query()->where($scope)->count());
        $this->assertNull($client->fresh()->scenario_sandbox_created_at);
    }

    /**
     * Aucun etat individuel ne designe un compte hors de la sandbox.
     *
     * C'est le croisement que le registre ne peut PAS voir : une progression
     * tient son `organization_id` de sa sequence — donc correct — tout en
     * pouvant pointer par `user_id` vers un compte etranger.
     */
    public function test_no_training_state_references_a_user_outside_the_sandbox(): void
    {
        $organization = $this->load()->organization;

        $sandboxUserIds = User::query()->where('organization_id', $organization->id)->pluck('id');

        $this->assertSame(0, CourseSequenceProgress::query()
            ->where('organization_id', $organization->id)
            ->whereNotIn('user_id', $sandboxUserIds)
            ->count());

        $this->assertSame(0, CourseSubmission::query()
            ->where('organization_id', $organization->id)
            ->whereNotIn('user_id', $sandboxUserIds)
            ->count());

        // Les relecteurs et debloqueurs aussi : trois colonnes nullable qui
        // portent une identite.
        foreach (['validated_by', 'unlocked_by'] as $column) {
            $this->assertSame(0, CourseSequenceProgress::query()
                ->where('organization_id', $organization->id)
                ->whereNotNull($column)
                ->whereNotIn($column, $sandboxUserIds)
                ->count(), "Column {$column} must never leave the sandbox.");
        }

        $this->assertSame(0, CourseSubmission::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('reviewed_by')
            ->whereNotIn('reviewed_by', $sandboxUserIds)
            ->count());
    }

    public function test_every_training_entity_is_registered_under_the_sandbox_only(): void
    {
        $organization = $this->load()->organization;

        $trainingTypes = [
            'manifest_course_module',
            'manifest_course_sequence',
            'manifest_course_progress',
            'manifest_course_assignment',
            'manifest_course_submission',
        ];

        // Les cinq familles sont TOUTES inscrites : c'est ce qui rend le reset
        // et le retrait complets.
        $counts = ScenarioPackEntity::query()
            ->where('organization_id', $organization->id)
            ->whereIn('entity_type', $trainingTypes)
            ->selectRaw('entity_type, count(*) as aggregate')
            ->groupBy('entity_type')
            ->pluck('aggregate', 'entity_type')
            ->map(fn ($count) => (int) $count)
            ->all();

        $this->assertSame([
            'manifest_course_assignment' => 1,
            'manifest_course_module' => 2,
            'manifest_course_progress' => 3,
            'manifest_course_sequence' => 3,
            'manifest_course_submission' => 2,
        ], collect($counts)->sortKeys()->all());

        $this->assertSame(0, ScenarioPackEntity::query()
            ->whereIn('entity_type', $trainingTypes)
            ->where('organization_id', '!=', $organization->id)
            ->count());
    }

    // =====================================================================
    // Les gardes du loader — defense en profondeur
    // =====================================================================

    /**
     * Le Validator T1641 refuse deja qu'un objet TRAINING vise une Boucle qui
     * n'est pas de type `training` : le document n'atteint jamais le loader.
     * Cette moitie-la se prouve en amont.
     */
    public function test_a_manifest_aiming_training_at_a_general_loop_never_becomes_loadable(): void
    {
        $document = json_decode($this->manifestJson(), true, 512, JSON_THROW_ON_ERROR);
        $document['training']['modules'][0]['loop'] = 'help-general';
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $result = (new ScenarioManifestValidator)->validate($json);

        $this->assertFalse($result->isValid());
        $this->assertContains('REFERENCE_WRONG_SCOPE', array_map(
            fn ($error) => $error->code->value,
            $result->errors(),
        ));
    }

    /**
     * Et le loader le refuse AUSSI, sur un document pourtant valide.
     *
     * Un applier ne suppose pas que son appelant a valide. On lui presente donc
     * une resolution de Boucle fausse — la Boucle `general` de la meme sandbox
     * a la place de la Boucle training — et il doit s'arreter, pas poser un
     * Support de cours sur une Boucle qui n'a aucune Card pour l'afficher.
     */
    public function test_the_loader_refuses_a_training_object_on_a_non_training_loop(): void
    {
        $result = $this->load();
        $organization = $result->organization;

        $general = Loop::query()
            ->where('organization_id', $organization->id)
            ->where('type', 'general')
            ->sole();

        $loops = $this->trackedLoops($organization);
        $loops['training-main'] = $general;

        // Le message nomme la COLLECTION, et l'assertion l'exige. Sans cela le
        // test passait pour la mauvaise raison : retirer la garde des modules
        // laissait celle des travaux a rendre lever a sa place, avec un message
        // different — donc un test vert sur une garde absente.
        // Un SEUL `expectExceptionMessage` — le second ecraserait le premier —
        // et il nomme la collection.
        $this->expectException(ManifestNotLoadableException::class);
        $this->expectExceptionMessage("'module-foundations' in 'training.modules' targets loop 'training-main' of type 'general'");

        $this->trainingApplier()->apply(
            $organization,
            new ScenarioPackEntityRegistrar($result->packLoad->load),
            $this->trackedUsers($organization),
            $loops,
            [],
            [],
        );
    }

    /**
     * Un etat individuel ne peut pas designer un compte d'une autre
     * Organization.
     *
     * C'est le seul croisement que la garde du registrar ne voit PAS : la
     * progression tient son `organization_id` de sa sequence — donc correct —
     * tout en pointant par `user_id` ailleurs. Le compte etranger est cree ici
     * dans une VRAIE autre Organization, pas simule.
     */
    public function test_the_loader_refuses_a_progress_whose_user_lives_in_another_organization(): void
    {
        $result = $this->load();
        $organization = $result->organization;

        $foreignOrganization = Organization::create([
            'name' => 'Autre tenant',
            'slug' => 'autre-tenant',
            'is_active' => true,
            'locale' => 'fr',
        ]);

        $foreign = User::create([
            'organization_id' => $foreignOrganization->id,
            'first_name' => 'Etrangere',
            'name' => 'Aucune',
            'email' => 'etrangere@autre-tenant.test',
            'password' => bcrypt('x'),
        ]);

        $users = $this->trackedUsers($organization);
        $users['student-01'] = $foreign;

        // Meme precaution : la collection est nommee, donc une autre garde qui
        // leverait a la place de celle-ci ne rendrait pas ce test vert.
        $this->expectException(ManifestNotLoadableException::class);
        $this->expectExceptionMessage("in 'training.progress'");

        $this->trainingApplier()->apply(
            $organization,
            new ScenarioPackEntityRegistrar($result->packLoad->load),
            $users,
            $this->trackedLoops($organization),
            [],
            [],
        );
    }

    /**
     * Une reference de sequence qui ne resout pas arrete le chargement.
     *
     * `applyProgress` recoit la sequence par son index ; un index incomplet
     * signifierait qu'une sequence declaree n'a pas ete produite. Passer outre
     * en silence laisserait une formation sans l'etape que le document promet,
     * et un reset la purgerait ensuite comme un orphelin.
     */
    public function test_the_loader_refuses_a_progress_whose_sequence_is_missing(): void
    {
        $result = $this->load();
        $organization = $result->organization;

        // L'applier appele directement avec un index d'articles/fichiers VIDE :
        // la premiere sequence declaree pointe vers `article-charte-ia`, qui ne
        // peut donc plus etre resolu.
        $this->expectException(ManifestNotLoadableException::class);
        $this->expectExceptionMessage("'article-charte-ia' could not be resolved in 'articles'");

        // Le registre est vide pour un pack_id inconnu : l'applier repart donc
        // d'une creation reelle et rencontre la reference manquante.
        $load = ScenarioPackLoad::create([
            'organization_id' => $organization->id,
            'pack_id' => 'manifest:probe-unresolved',
            'pack_version' => '1',
            'loaded_at' => now(),
        ]);

        (new ManifestTrainingApplier(
            ScenarioManifest::fromApprovedJson($this->manifestJson(), $this->approvedDigest()),
            'manifest:probe-unresolved',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ))->apply(
            $organization,
            new ScenarioPackEntityRegistrar($load),
            $this->trackedUsers($organization),
            $this->trackedLoops($organization),
            [],
            [],
        );
    }

    // =====================================================================
    // Outillage de lecture
    // =====================================================================

    /**
     * Recensement des familles TRAINING plus le socle dont elles dependent.
     *
     * @return array<string, int>
     */
    private function census(Organization $organization): array
    {
        $scope = ['organization_id' => $organization->id];

        return [
            'users' => User::query()->where($scope)->count(),
            'loops' => Loop::query()->where($scope)->count(),
            'articles' => BlogPost::query()->where($scope)->count(),
            'files' => DossierFile::query()->where($scope)->count(),
            'modules' => CourseModule::query()->where($scope)->count(),
            'sequences' => CourseSequence::query()->where($scope)->count(),
            'progress' => CourseSequenceProgress::query()->where($scope)->count(),
            'assignments' => CourseAssignment::query()->where($scope)->count(),
            'submissions' => CourseSubmission::query()->where($scope)->count(),
            'registry' => ScenarioPackEntity::query()->where($scope)->count(),
        ];
    }

    /**
     * Le persona dont l'adresse declaree commence par ce local part.
     *
     * Les adresses sont derivees par sandbox (`local@<slug>.<domaine>`, T1642) :
     * on ne peut donc pas comparer a l'adresse du document.
     */
    private function userByEmailLocal(Organization $organization, string $localPart): User
    {
        return User::query()
            ->where('organization_id', $organization->id)
            ->where('email', 'like', $localPart.'@%')
            ->sole();
    }

    private function moduleByTitle(Organization $organization, string $title): CourseModule
    {
        return CourseModule::query()
            ->where('organization_id', $organization->id)
            ->where('title', $title)
            ->sole();
    }

    private function sequenceByTitle(Organization $organization, string $title): CourseSequence
    {
        return CourseSequence::query()
            ->where('organization_id', $organization->id)
            ->where('title', $title)
            ->sole();
    }

    private function progressOf(Organization $organization, string $sequenceTitle, User $user): CourseSequenceProgress
    {
        return CourseSequenceProgress::query()
            ->where('course_sequence_id', $this->sequenceByTitle($organization, $sequenceTitle)->id)
            ->where('user_id', $user->id)
            ->sole();
    }

    private function trainingApplier(): ManifestTrainingApplier
    {
        $manifest = ScenarioManifest::fromApprovedJson($this->manifestJson(), $this->approvedDigest());

        return new ManifestTrainingApplier(
            $manifest,
            ManifestScenarioPack::PACK_ID_PREFIX.$manifest->id(),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /**
     * L'index `stable key -> User` tel que le socle l'a produit, relu au
     * registre. C'est ce que `ManifestScenarioPack` passe aux appliers.
     *
     * @return array<string, User>
     */
    private function trackedUsers(Organization $organization): array
    {
        return $this->trackedMap($organization, 'manifest_user', User::class);
    }

    /**
     * @return array<string, Loop>
     */
    private function trackedLoops(Organization $organization): array
    {
        return $this->trackedMap($organization, 'manifest_loop', Loop::class);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return array<string, TModel>
     */
    private function trackedMap(Organization $organization, string $entityType, string $model): array
    {
        $map = [];

        $rows = ScenarioPackEntity::query()
            ->where('organization_id', $organization->id)
            ->where('entity_type', $entityType)
            ->get();

        foreach ($rows as $row) {
            $map[(string) $row->internal_key] = $model::query()->whereKey($row->entity_id)->sole();
        }

        return $map;
    }
}
