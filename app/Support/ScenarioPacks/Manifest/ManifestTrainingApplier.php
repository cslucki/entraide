<?php

namespace App\Support\ScenarioPacks\Manifest;

use App\Models\BlogPost;
use App\Models\CourseAssignment;
use App\Models\CourseModule;
use App\Models\CourseSequence;
use App\Models\CourseSequenceProgress;
use App\Models\CourseSubmission;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use App\Services\Loops\CourseAssignmentService;
use App\Services\Loops\CourseMaterialService;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Illuminate\Database\Eloquent\Model;

/**
 * TASK-1644 — materialise les CINQ familles TRAINING d'un Manifest V1.
 *
 * Troisieme collaborateur de {@see ManifestScenarioPack}, apres le socle
 * (T1642) et CORE (T1643). Meme raison qu'a l'epoque de decouper : le socle et
 * CORE sont revus, et on doit pouvoir lire — et retirer — TRAINING sans les
 * relire.
 *
 *     CourseModule · CourseSequence · CourseSequenceProgress
 *     CourseAssignment · CourseSubmission
 *
 * `CourseQuiz` est HORS SCOPE par DECISION de la spec 12.6 : reporte a Manifest
 * V1.1, aucun champ `quizzes` n'existe en `schema_version: "1.0"`. Rien ici ne
 * l'effleure — pas meme `course_sequence_progress.passed_quiz_id`, qui reste
 * volontairement jamais ecrit.
 *
 * ## La regle qui gouverne ce fichier : la structure et l'etat ne s'ecrivent
 * ## pas de la meme facon
 *
 * La doctrine du moteur laisse deux chemins legitimes — le service metier
 * canonique quand il existe, les primitives Eloquent sur une cle naturelle
 * stable sinon. TRAINING a besoin des DEUX, et l'audit de la TASK a mesure
 * pourquoi :
 *
 * **La structure passe par les services.** `CourseMaterialService` et
 * `CourseAssignmentService` prennent le verrou sur la Loop (ou le Module), en
 * lisent l'`organization_id` — donc jamais depuis le JSON — posent
 * `created_by`, et `addSequence()` porte en plus
 * `assertSameOrganization()` sur l'article ou le fichier reference. Rien de
 * tout cela n'est a reecrire.
 *
 * **L'etat individuel ne PEUT PAS y passer.** `CourseProgressService` et les
 * gestes de remise de `CourseAssignmentService` sont des primitives de
 * l'INSTANT PRESENT ; le manifeste declare une HISTOIRE. Trois empechements
 * independants, chacun suffisant :
 *
 *  1. tous leurs horodatages sont cables sur `now()` — `started_at`,
 *     `completed_at`, `validated_at`, `unlocked_at`, `submitted_at`,
 *     `reviewed_at`. Le manifeste declare des offsets passes ; rejouer par les
 *     services horodaterait toute l'histoire a l'instant du chargement ;
 *  2. `assertAvailable()` REFUSE un etat que le document declare valide. En
 *     mode `sequential` — seul mode de V1 — il exige que l'etape precedente
 *     soit finie, alors que la spec 12.3 n'impose d'ordre qu'A L'INTERIEUR
 *     d'une ligne. Une personne `in_progress` sur la deuxieme sequence sans
 *     ligne sur la premiere est un etat legitime, et le service leve dessus ;
 *  3. `completed` est inatteignable sur une sequence a validation :
 *     `markCompleted()` y rend `submitted`, et `validate()` — seul chemin vers
 *     `validated` — ecrase `completed_at` par `now()`. Un document qui declare
 *     un `completed_offset` DISTINCT de son `validated_offset` n'a donc aucune
 *     sequence d'appels qui le reproduise.
 *
 * L'etat s'ecrit donc en `updateOrCreate` sur la cle naturelle, colonnes
 * NOMMEES une par une. Ce n'est pas un contournement : cette cle est celle que
 * la spec 8.1 designe (`progress:<sequence>:<user>`,
 * `submission:<assignment>:<user>`), celle que l'index unique de la base
 * impose, et celle que le service lui-meme utilise dans son `write()` prive.
 *
 * ## L'idempotence vient du REGISTRE
 *
 * Aucune de ces primitives n'est idempotente : `createModule()` cree un module
 * a chaque appel. Avant de creer, on demande donc au registre si CE pack a
 * deja produit cette stable key dans cette Organization.
 *
 * Et chaque entite est `track()`ee a CHAQUE passage, y compris retrouvee
 * existante : le resetter purge tout ce que le passage COURANT n'a pas
 * re-declare. Sauter un `track()` au rejeu ne cree pas un doublon — il fait
 * DISPARAITRE l'objet au reset suivant.
 */
class ManifestTrainingApplier
{
    /** Le type de Loop que tout objet TRAINING doit viser (spec 12). */
    private const TRAINING_LOOP_TYPE = 'training';

    public function __construct(
        private readonly ScenarioManifest $manifest,
        private readonly string $packId,
        private readonly \DateTimeImmutable $loadStartedAt,
    ) {}

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Loop>  $loops
     * @param  array<string, BlogPost>  $articles
     * @param  array<string, DossierFile>  $files
     */
    public function apply(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $loops,
        array $articles,
        array $files,
    ): void {
        // Ordre de la spec 8.2 : « training structure, training state ». La
        // structure d'abord parce que l'etat la reference, et les assignments
        // avant les progressions parce qu'ils peuvent viser une sequence.
        $modules = $this->applyModules($organization, $registrar, $users, $loops);
        $sequences = $this->applySequences($organization, $registrar, $users, $modules, $articles, $files);
        $assignments = $this->applyAssignments($organization, $registrar, $users, $loops, $sequences);

        $this->applyProgress($organization, $registrar, $users, $sequences);
        $this->applySubmissions($organization, $registrar, $users, $assignments, $files);
    }

    // =====================================================================
    // Structure — modules
    // =====================================================================

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Loop>  $loops
     * @return array<string, CourseModule>
     */
    private function applyModules(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $loops,
    ): array {
        $service = app(CourseMaterialService::class);
        $modules = [];

        foreach ($this->manifest->trainingCollection('modules') as $declared) {
            $key = (string) $declared->key;
            $loopKey = (string) $declared->loop;

            $loop = $loops[$loopKey] ?? null;

            if ($loop === null) {
                throw ManifestNotLoadableException::unresolvedReference('loops', $loopKey);
            }

            $this->assertTrainingLoop('training.modules', $key, $loopKey, $loop);

            $actor = $users[(string) $declared->created_by] ?? null;

            if ($actor === null) {
                throw ManifestNotLoadableException::unresolvedReference('users', (string) $declared->created_by);
            }

            $existing = $this->findTracked($organization, 'manifest_course_module', $key);

            if ($existing instanceof CourseModule) {
                $registrar->track('manifest_course_module', $key, $existing);
                $modules[$key] = $existing;

                continue;
            }

            $module = $service->createModule($loop, $actor, (string) $declared->title, $declared->summary);

            // Inscrit AVANT l'alignement de position : l'ownership se lit sur
            // `wasRecentlyCreated` de l'instance, et on l'inscrit donc telle
            // que la primitive vient de la rendre.
            $registrar->track('manifest_course_module', $key, $module);

            $this->alignPosition($module, $declared->position);

            $modules[$key] = $module;
        }

        return $modules;
    }

    // =====================================================================
    // Structure — sequences
    // =====================================================================

    /**
     * @param  array<string, User>  $users
     * @param  array<string, CourseModule>  $modules
     * @param  array<string, BlogPost>  $articles
     * @param  array<string, DossierFile>  $files
     * @return array<string, CourseSequence>
     */
    private function applySequences(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $modules,
        array $articles,
        array $files,
    ): array {
        $service = app(CourseMaterialService::class);
        $sequences = [];

        foreach ($this->manifest->trainingCollection('sequences') as $declared) {
            $key = (string) $declared->key;
            $moduleKey = (string) $declared->module;

            $module = $modules[$moduleKey] ?? null;

            if ($module === null) {
                throw ManifestNotLoadableException::unresolvedReference('training.modules', $moduleKey);
            }

            $actor = $users[(string) $declared->created_by] ?? null;

            if ($actor === null) {
                throw ManifestNotLoadableException::unresolvedReference('users', (string) $declared->created_by);
            }

            $existing = $this->findTracked($organization, 'manifest_course_sequence', $key);

            if ($existing instanceof CourseSequence) {
                $registrar->track('manifest_course_sequence', $key, $existing);
                $sequences[$key] = $existing;

                continue;
            }

            // Les trois variantes de `content` (spec 12.2) tombent sur la
            // signature du service : un texte, un Article, un fichier. La
            // reference n'est PAS recopiee — c'est tout le sens du modele :
            // une Sequence qui pointe vers un Article EST cet Article.
            $content = $declared->content;
            $contentType = (string) $content->type;

            $reference = match ($contentType) {
                'article' => $articles[(string) $content->article]
                    ?? throw ManifestNotLoadableException::unresolvedReference('articles', (string) $content->article),
                'file' => $files[(string) $content->file]
                    ?? throw ManifestNotLoadableException::unresolvedReference('files', (string) $content->file),
                default => null,
            };

            $sequence = $service->addSequence(
                $module,
                $actor,
                (string) $declared->title,
                $contentType === 'text' ? (string) $content->body : null,
                $reference,
            );

            $registrar->track('manifest_course_sequence', $key, $sequence);

            // Deux champs que `addSequence()` n'expose pas, poses NOMMES.
            //
            // `requires_validation` n'est pas cosmetique : c'est lui qui
            // distingue `completed` — declaratif — de `validated`, prononce par
            // quelqu'un. Le laisser au defaut `false` ferait d'une sequence qui
            // attend un regard une sequence que personne ne relit, sur un monde
            // dont le document dit le contraire.
            $sequence->forceFill([
                'requires_validation' => (bool) $declared->requires_validation,
                'position' => $this->declaredPosition($declared->position),
            ])->save();

            $sequences[$key] = $sequence;
        }

        return $sequences;
    }

    // =====================================================================
    // Structure — travaux a rendre
    // =====================================================================

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Loop>  $loops
     * @param  array<string, CourseSequence>  $sequences
     * @return array<string, CourseAssignment>
     */
    private function applyAssignments(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $loops,
        array $sequences,
    ): array {
        $service = app(CourseAssignmentService::class);
        $assignments = [];

        foreach ($this->manifest->trainingCollection('assignments') as $declared) {
            $key = (string) $declared->key;
            $loopKey = (string) $declared->loop;

            $loop = $loops[$loopKey] ?? null;

            if ($loop === null) {
                throw ManifestNotLoadableException::unresolvedReference('loops', $loopKey);
            }

            $this->assertTrainingLoop('training.assignments', $key, $loopKey, $loop);

            $actor = $users[(string) $declared->created_by] ?? null;

            if ($actor === null) {
                throw ManifestNotLoadableException::unresolvedReference('users', (string) $declared->created_by);
            }

            // `sequence` est nullable par la spec 12.4 : un Travail peut clore
            // une etape du parcours, ou vivre a cote.
            $sequenceKey = is_string($declared->sequence ?? null) ? (string) $declared->sequence : null;
            $sequence = $sequenceKey === null ? null : ($sequences[$sequenceKey] ?? null);

            if ($sequenceKey !== null && $sequence === null) {
                throw ManifestNotLoadableException::unresolvedReference('training.sequences', $sequenceKey);
            }

            $existing = $this->findTracked($organization, 'manifest_course_assignment', $key);

            if ($existing instanceof CourseAssignment) {
                $registrar->track('manifest_course_assignment', $key, $existing);
                $assignments[$key] = $existing;

                continue;
            }

            $assignment = $service->create(
                $loop,
                $actor,
                (string) $declared->title,
                $declared->brief,
                // Le seul offset de TRAINING qui soit legitimement POSITIF : une
                // date limite est devant. AMT declare +20160 (J+14).
                $this->fromOffsetMinutes($declared->due_offset_minutes),
                $sequence?->id,
            );

            $registrar->track('manifest_course_assignment', $key, $assignment);

            $this->alignPosition($assignment, $declared->position);

            $assignments[$key] = $assignment;
        }

        return $assignments;
    }

    // =====================================================================
    // Etat — progressions
    // =====================================================================

    /**
     * @param  array<string, User>  $users
     * @param  array<string, CourseSequence>  $sequences
     */
    private function applyProgress(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $sequences,
    ): void {
        foreach ($this->manifest->trainingCollection('progress') as $declared) {
            $sequenceKey = (string) $declared->sequence;
            $userKey = (string) $declared->user;

            // Cle naturelle de la spec 8.1 : `progress:<sequence>:<user>`.
            $key = $sequenceKey.':'.$userKey;

            $sequence = $sequences[$sequenceKey] ?? null;

            if ($sequence === null) {
                throw ManifestNotLoadableException::unresolvedReference('training.sequences', $sequenceKey);
            }

            $user = $users[$userKey] ?? null;

            if ($user === null) {
                throw ManifestNotLoadableException::unresolvedReference('users', $userKey);
            }

            $this->assertUserInSandbox('training.progress', $key, $userKey, $user, $organization);

            $existing = $this->findTracked($organization, 'manifest_course_progress', $key);

            if ($existing instanceof CourseSequenceProgress) {
                $registrar->track('manifest_course_progress', $key, $existing);

                continue;
            }

            $validatedBy = $this->resolveLead('training.progress', $key, $declared->validated_by ?? null, $users, $organization);
            $unlockedBy = $this->resolveLead('training.progress', $key, $declared->unlocked_by ?? null, $users, $organization);

            $progress = CourseSequenceProgress::updateOrCreate(
                [
                    'course_sequence_id' => $sequence->id,
                    'user_id' => $user->id,
                ],
                [
                    // Lu sur la SEQUENCE, comme le fait `CourseProgressService`
                    // dans son `write()` : le cloisonnement suit l'objet dont
                    // cette ligne est l'etat, il n'est pas redeclare ici. Si la
                    // sequence appartenait a une autre Organization, le
                    // `track()` ci-dessous le refuserait.
                    'organization_id' => $sequence->organization_id,
                    'status' => (string) $declared->status,
                    'started_at' => $this->fromOffsetMinutes($declared->started_offset_minutes),
                    'completed_at' => $this->fromOffsetMinutes($declared->completed_offset_minutes),
                    'validated_at' => $this->fromOffsetMinutes($declared->validated_offset_minutes),
                    'validated_by' => $validatedBy?->id,
                    'unlocked_at' => $this->fromOffsetMinutes($declared->unlocked_offset_minutes),
                    'unlocked_by' => $unlockedBy?->id,
                    // `passed_quiz_id` reste NON ecrit : le QCM est hors V1
                    // (spec 12.6). Une colonne qu'aucun document ne declare ne
                    // se remplit pas "au cas ou".
                ],
            );

            $registrar->track('manifest_course_progress', $key, $progress);
        }
    }

    // =====================================================================
    // Etat — remises
    // =====================================================================

    /**
     * @param  array<string, User>  $users
     * @param  array<string, CourseAssignment>  $assignments
     * @param  array<string, DossierFile>  $files
     */
    private function applySubmissions(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $assignments,
        array $files,
    ): void {
        foreach ($this->manifest->trainingCollection('submissions') as $declared) {
            $assignmentKey = (string) $declared->assignment;
            $userKey = (string) $declared->user;

            // Cle naturelle de la spec 8.1 : `submission:<assignment>:<user>`.
            $key = $assignmentKey.':'.$userKey;

            $assignment = $assignments[$assignmentKey] ?? null;

            if ($assignment === null) {
                throw ManifestNotLoadableException::unresolvedReference('training.assignments', $assignmentKey);
            }

            $user = $users[$userKey] ?? null;

            if ($user === null) {
                throw ManifestNotLoadableException::unresolvedReference('users', $userKey);
            }

            $this->assertUserInSandbox('training.submissions', $key, $userKey, $user, $organization);

            $existing = $this->findTracked($organization, 'manifest_course_submission', $key);

            if ($existing instanceof CourseSubmission) {
                $registrar->track('manifest_course_submission', $key, $existing);

                continue;
            }

            $fileKey = is_string($declared->file ?? null) ? (string) $declared->file : null;
            $file = $fileKey === null ? null : ($files[$fileKey] ?? null);

            if ($fileKey !== null && $file === null) {
                throw ManifestNotLoadableException::unresolvedReference('files', $fileKey);
            }

            $reviewedBy = $this->resolveLead('training.submissions', $key, $declared->reviewed_by ?? null, $users, $organization);

            $submission = CourseSubmission::updateOrCreate(
                [
                    'course_assignment_id' => $assignment->id,
                    'user_id' => $user->id,
                ],
                [
                    'organization_id' => $assignment->organization_id,
                    'body' => $declared->body,
                    // Une REFERENCE, jamais une copie : le fichier vit dans le
                    // Dossier de la Boucle, avec son quota et sa somme de
                    // controle.
                    'dossier_file_id' => $file?->id,
                    'status' => (string) $declared->status,
                    'submitted_at' => $this->fromOffsetMinutes($declared->submitted_offset_minutes),
                    'feedback' => $declared->feedback,
                    'reviewed_at' => $this->fromOffsetMinutes($declared->reviewed_offset_minutes),
                    'reviewed_by' => $reviewedBy?->id,
                ],
            );

            // Inscription OBLIGATOIRE — mais pour la raison EXACTE, mesuree au
            // sabotage de la TASK et non supposee.
            //
            // Ce qui NETTOIE une remise au retrait, c'est la CASCADE de son
            // travail a rendre (`course_submissions.course_assignment_id`), et
            // le travail, lui, est inscrit : omettre ce `track()` ne laisse donc
            // aucune remise derriere elle. `course_submissions.user_id` est bien
            // en `ON DELETE RESTRICT` depuis TASK-1635, mais la cascade du
            // travail passe AVANT — les entites sont purgees par `sequence`
            // decroissante — et la contrainte n'est jamais atteinte.
            //
            // Ce que l'inscription porte reellement : le registre est le COMPTE
            // RENDU de ce que le pack a produit, et c'est lui que le resetter
            // interroge. Une remise absente du registre est une remise dont
            // personne ne sait qu'elle vient du pack — et la seule chose qui la
            // fait disparaitre est alors un effet de bord d'une autre famille,
            // pas une decision.
            $registrar->track('manifest_course_submission', $key, $submission);
        }
    }

    // =====================================================================
    // Gardes
    // =====================================================================

    /**
     * Tout objet TRAINING vise une Loop `type: "training"` (spec 12).
     */
    private function assertTrainingLoop(string $collection, string $key, string $loopKey, Loop $loop): void
    {
        if ((string) $loop->type !== self::TRAINING_LOOP_TYPE) {
            throw ManifestNotLoadableException::trainingOutsideTrainingLoop(
                $collection,
                $key,
                $loopKey,
                (string) $loop->type,
            );
        }
    }

    /**
     * Le compte designe par un etat individuel appartient a la sandbox.
     *
     * Voir `ManifestNotLoadableException::userOutsideSandbox()` : la garde du
     * registrar inspecte l'Organization de l'ENTITE, pas celle des comptes
     * qu'elle designe. C'est ici, et seulement ici, que le croisement est
     * possible.
     */
    private function assertUserInSandbox(
        string $collection,
        string $key,
        string $userKey,
        User $user,
        Organization $organization,
    ): void {
        if ((string) $user->organization_id !== (string) $organization->id) {
            throw ManifestNotLoadableException::userOutsideSandbox($collection, $key, $userKey);
        }
    }

    /**
     * Le membre de l'equipe pedagogique designe par un champ nullable
     * (`validated_by`, `unlocked_by`, `reviewed_by`), ou `null`.
     *
     * Le Validator a deja verifie qu'il s'agit d'un owner/facilitator de la
     * Loop (spec 12.3/12.5) ; ce qui se verifie ICI est le cloisonnement, pour
     * la meme raison que sur le sujet de l'etat : un relecteur d'une autre
     * Organization entrerait dans la sandbox par une colonne que le registre
     * n'inspecte pas.
     *
     * @param  array<string, User>  $users
     */
    private function resolveLead(
        string $collection,
        string $key,
        mixed $declaredKey,
        array $users,
        Organization $organization,
    ): ?User {
        if (! is_string($declaredKey)) {
            return null;
        }

        $lead = $users[$declaredKey] ?? null;

        if ($lead === null) {
            throw ManifestNotLoadableException::unresolvedReference('users', $declaredKey);
        }

        $this->assertUserInSandbox($collection, $key, $declaredKey, $lead, $organization);

        return $lead;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * La position DECLAREE, ecrite par-dessus celle que la primitive a calculee.
     *
     * Les trois primitives de structure calculent
     * `position = ((int) max('position')) + 1`. Sur une Loop vide, `max()` rend
     * `null`, `(int) null` vaut 0, et la premiere position ecrite est donc **1**
     * — alors que la spec 12.1/12.2/12.4 declare des positions contigues A
     * PARTIR DE 0. L'ordre serait le meme, et l'affichage aussi (le numero vient
     * du RANG, jamais de la colonne), mais un monde charge porterait `1,2` la ou
     * le document a approuve `0,1` — et les renumerotations du produit, elles,
     * ecrivent bien `0..n-1`. Deux mondes identiques finiraient avec deux jeux
     * de positions selon qu'on a supprime un objet ou non.
     *
     * On garde donc la primitive — pour son verrou, son `organization_id` lu sur
     * l'objet verrouille et son `created_by` — et on aligne la seule valeur
     * qu'elle ne peut pas connaitre.
     */
    private function alignPosition(CourseModule|CourseAssignment $entity, mixed $declaredPosition): void
    {
        $position = $this->declaredPosition($declaredPosition);

        if ($entity->position !== $position) {
            $entity->forceFill(['position' => $position])->save();
        }
    }

    private function declaredPosition(mixed $declaredPosition): int
    {
        return is_int($declaredPosition) ? $declaredPosition : 0;
    }

    /**
     * Un instant derive de l'unique ancre du chargement (spec 6.3).
     *
     * `null` pour un offset absent : la colonne reste nulle, ce qui est
     * exactement ce que le document declare — une etape non terminee n'a pas de
     * date de fin, et lui en inventer une la ferait passer pour finie.
     */
    private function fromOffsetMinutes(mixed $offsetMinutes): ?\DateTimeImmutable
    {
        if (! is_int($offsetMinutes)) {
            return null;
        }

        return $this->loadStartedAt->modify(sprintf('%+d minutes', $offsetMinutes));
    }

    /**
     * L'entite que CE pack a deja produite pour cette stable key, ou `null`.
     *
     * Meme primitive que dans {@see ManifestCoreApplier}, et pour les deux
     * memes raisons apprises en T1643 :
     *
     *  - le registre est la SEULE source d'identite : ni le titre, ni une date
     *    ne sont des cles naturelles fiables ;
     *  - on retire UNIQUEMENT le scope tenant, jamais tous les scopes.
     *    `withoutGlobalScopes()` desactiverait aussi le filtre de suppression
     *    douce, et une entite soft-supprimee passerait alors pour presente : le
     *    reset ne la restaurerait pas et ne la recreerait pas non plus.
     *
     * Aucun des cinq modeles TRAINING ne porte `SoftDeletes` ni le scope
     * tenant aujourd'hui — mais cette primitive ne doit pas dependre de cela
     * pour etre juste le jour ou l'un des deux est ajoute.
     */
    private function findTracked(Organization $organization, string $entityType, string $internalKey): ?object
    {
        $row = ScenarioPackEntity::query()
            ->where('organization_id', $organization->id)
            ->whereHas('scenarioPackLoad', fn ($query) => $query->where('pack_id', $this->packId))
            ->where('entity_type', $entityType)
            ->where('internal_key', $internalKey)
            ->first();

        if ($row === null) {
            return null;
        }

        $model = $row->entity_model;

        if (! is_string($model) || ! class_exists($model) || ! is_a($model, Model::class, true)) {
            return null;
        }

        return $model::query()->withoutGlobalScope(BelongsToOrganizationScope::class)->find($row->entity_id);
    }
}
