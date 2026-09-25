<?php

namespace App\Support\ScenarioManifest;

/**
 * Invariants de l'extension TRAINING (spec 12).
 *
 * Training V1 est une section isolee du MEME langage : ses formateurs et ses
 * stagiaires sont les users et memberships de CORE, il n'existe pas de seconde
 * inscription. Toute la section repose donc sur une regle de rattachement
 * unique : chaque objet vise une Loop `type: "training"`, et l'autorite
 * pedagogique — creer, debloquer, valider, relire — appartient aux roles
 * `owner` et `facilitator` de cette Loop.
 *
 * Le parcours V1 est `sequential` : les positions de modules, sequences et
 * travaux sont CONTIGUES a partir de 0. Un trou dans la numerotation rendrait
 * le deblocage sequentiel ambigu, et la previsualisation ne pourrait pas
 * montrer le parcours qui sera reellement construit.
 */
final class ManifestTrainingInvariants
{
    public function validate(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $this->validateModules($graph, $errors);
        $this->validateSequences($graph, $errors);
        $this->validateProgress($graph, $errors);
        $this->validateAssignments($graph, $errors);
        $this->validateSubmissions($graph, $errors);
    }

    private function validateModules(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $positions = [];

        foreach ($graph->collection('training.modules') as $index => $module) {
            $loop = $module->loop ?? null;

            if (! is_string($loop)) {
                continue;
            }

            $this->assertTrainingLoop($graph, 'training.modules', $index, 'loop', $loop, $errors);
            $this->assertLeads($graph, 'training.modules', $index, 'created_by', $loop, $module->created_by ?? null, $errors);

            if (is_int($module->position ?? null)) {
                $positions[$loop][] = ['index' => $index, 'position' => $module->position];
            }
        }

        $this->assertContiguousPositions($graph, 'training.modules', $positions, 'loop', $errors);
    }

    private function validateSequences(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $positions = [];

        foreach ($graph->collection('training.sequences') as $index => $sequence) {
            $module = $sequence->module ?? null;

            if (! is_string($module)) {
                continue;
            }

            $loop = $this->loopOfModule($graph, $module);

            if ($loop !== null) {
                $this->assertLeads($graph, 'training.sequences', $index, 'created_by', $loop, $sequence->created_by ?? null, $errors);
                $this->assertSequenceContentScope($graph, $index, $sequence, $loop, $errors);
            }

            if (is_int($sequence->position ?? null)) {
                $positions[$module][] = ['index' => $index, 'position' => $sequence->position];
            }
        }

        $this->assertContiguousPositions($graph, 'training.sequences', $positions, 'module', $errors);
    }

    /**
     * Une reference article/file d'une sequence doit viser un contenu d'un
     * Dossier gouverne par la MEME Loop training (spec 12.2). Sans cette
     * regle, un support de cours pourrait pointer un Dossier d'une autre
     * Boucle et exposer son contenu aux stagiaires.
     */
    private function assertSequenceContentScope(ManifestGraph $graph, int $index, \stdClass $sequence, string $loop, ManifestErrorBag $errors): void
    {
        $content = $sequence->content ?? null;

        if (! $content instanceof \stdClass) {
            return;
        }

        foreach (['article' => 'articles', 'file' => 'files'] as $field => $collection) {
            $key = $content->{$field} ?? null;

            if (! is_string($key)) {
                continue;
            }

            $item = $graph->find($collection, $key);
            $dossier = $item?->dossier ?? null;

            if ($item === null || ! is_string($dossier)) {
                continue;
            }

            if ($graph->loopOfDossier($dossier) !== $loop) {
                $errors->add(
                    ManifestErrorCode::REFERENCE_WRONG_SCOPE,
                    $this->at($graph, 'training.sequences', $index, 'content', $field),
                    sprintf("Sequence content must live in a dossier governed by loop '%s'.", $loop),
                );
            }
        }
    }

    private function validateProgress(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $seen = [];

        foreach ($graph->collection('training.progress') as $index => $progress) {
            $sequence = $progress->sequence ?? null;
            $user = $progress->user ?? null;

            if (! is_string($sequence) || ! is_string($user)) {
                continue;
            }

            $pair = $sequence."\0".$user;
            $userPath = $this->at($graph, 'training.progress', $index, 'user');

            if (array_key_exists($pair, $seen)) {
                $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $userPath, sprintf(
                    'Progress for (%s, %s) is already declared at %s.',
                    $sequence,
                    $user,
                    $seen[$pair],
                ));

                continue;
            }

            $seen[$pair] = $userPath;

            $loop = $this->loopOfSequence($graph, $sequence);

            if ($loop !== null && ! $graph->isMember($loop, $user)) {
                $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $userPath, sprintf(
                    "User '%s' progresses in loop '%s' without being a member of it.",
                    $user,
                    $loop,
                ));
            }

            $this->assertProgressState($graph, $index, $progress, $loop, $errors);
        }
    }

    private function assertProgressState(ManifestGraph $graph, int $index, \stdClass $progress, ?string $loop, ManifestErrorBag $errors): void
    {
        $status = $progress->status ?? null;
        $started = $progress->started_offset_minutes ?? null;
        $completed = $progress->completed_offset_minutes ?? null;
        $validatedBy = $progress->validated_by ?? null;
        $validatedAt = $progress->validated_offset_minutes ?? null;
        $unlockedBy = $progress->unlocked_by ?? null;
        $unlockedAt = $progress->unlocked_offset_minutes ?? null;

        if (is_int($started) && $started > 0) {
            $errors->add(ManifestErrorCode::TIMELINE_INCONSISTENT, $this->at($graph, 'training.progress', $index, 'started_offset_minutes'), 'A started sequence cannot start in the future.');
        }

        if (in_array($status, ['completed', 'validated'], true) && $completed === null) {
            $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'training.progress', $index, 'completed_offset_minutes'), sprintf("A '%s' progress requires a completion offset.", (string) $status));
        }

        if ($status === 'validated') {
            if ($validatedBy === null) {
                $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'training.progress', $index, 'validated_by'), "A 'validated' progress requires a validator.");
            }

            if ($validatedAt === null) {
                $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'training.progress', $index, 'validated_offset_minutes'), "A 'validated' progress requires a validation offset.");
            }
        } else {
            if ($validatedBy !== null) {
                $errors->add(ManifestErrorCode::INVALID_FORMAT, $this->at($graph, 'training.progress', $index, 'validated_by'), "Only a 'validated' progress carries a validator.");
            }

            if ($validatedAt !== null) {
                $errors->add(ManifestErrorCode::INVALID_FORMAT, $this->at($graph, 'training.progress', $index, 'validated_offset_minutes'), "Only a 'validated' progress carries a validation offset.");
            }
        }

        // "present si et seulement si" (spec 12.3) : les deux champs de
        // deblocage vont ensemble, ou pas du tout.
        if (($unlockedBy === null) !== ($unlockedAt === null)) {
            $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'training.progress', $index, 'unlocked_offset_minutes'), 'An unlock offset is present if and only if an unlocking user is.');
        }

        if ($loop !== null) {
            foreach (['validated_by' => $validatedBy, 'unlocked_by' => $unlockedBy] as $field => $actor) {
                if (is_string($actor) && ! $graph->leads($loop, $actor)) {
                    $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, 'training.progress', $index, $field), sprintf(
                        "User '%s' must be an owner or facilitator of loop '%s'.",
                        $actor,
                        $loop,
                    ));
                }
            }
        }

        $this->assertIncreasing($graph, 'training.progress', $index, [
            'unlocked_offset_minutes' => $unlockedAt,
            'started_offset_minutes' => $started,
            'completed_offset_minutes' => $completed,
            'validated_offset_minutes' => $validatedAt,
        ], $errors);
    }

    private function validateAssignments(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $positions = [];

        foreach ($graph->collection('training.assignments') as $index => $assignment) {
            $loop = $assignment->loop ?? null;

            if (! is_string($loop)) {
                continue;
            }

            $this->assertTrainingLoop($graph, 'training.assignments', $index, 'loop', $loop, $errors);
            $this->assertLeads($graph, 'training.assignments', $index, 'created_by', $loop, $assignment->created_by ?? null, $errors);

            $sequence = $assignment->sequence ?? null;

            if (is_string($sequence) && $graph->has('training.sequences', $sequence) && $this->loopOfSequence($graph, $sequence) !== $loop) {
                $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, 'training.assignments', $index, 'sequence'), sprintf(
                    "An assignment targets a sequence of loop '%s'.",
                    $loop,
                ));
            }

            if (is_int($assignment->position ?? null)) {
                $positions[$loop][] = ['index' => $index, 'position' => $assignment->position];
            }
        }

        $this->assertContiguousPositions($graph, 'training.assignments', $positions, 'loop', $errors);
    }

    private function validateSubmissions(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $seen = [];

        foreach ($graph->collection('training.submissions') as $index => $submission) {
            $assignmentKey = $submission->assignment ?? null;
            $user = $submission->user ?? null;

            if (! is_string($assignmentKey) || ! is_string($user)) {
                continue;
            }

            $pair = $assignmentKey."\0".$user;
            $userPath = $this->at($graph, 'training.submissions', $index, 'user');

            if (array_key_exists($pair, $seen)) {
                $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $userPath, sprintf(
                    'Submission for (%s, %s) is already declared at %s.',
                    $assignmentKey,
                    $user,
                    $seen[$pair],
                ));

                continue;
            }

            $seen[$pair] = $userPath;

            $assignment = $graph->find('training.assignments', $assignmentKey);
            $loop = is_string($assignment?->loop ?? null) ? $assignment->loop : null;

            if ($loop !== null && ! $graph->isMember($loop, $user)) {
                $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $userPath, sprintf(
                    "User '%s' submits in loop '%s' without being a member of it.",
                    $user,
                    $loop,
                ));
            }

            $this->assertSubmissionState($graph, $index, $submission, $loop, $errors);
        }
    }

    private function assertSubmissionState(ManifestGraph $graph, int $index, \stdClass $submission, ?string $loop, ManifestErrorBag $errors): void
    {
        $status = $submission->status ?? null;
        $body = $submission->body ?? null;
        $file = $submission->file ?? null;
        $submittedAt = $submission->submitted_offset_minutes ?? null;
        $reviewedBy = $submission->reviewed_by ?? null;
        $reviewedAt = $submission->reviewed_offset_minutes ?? null;
        $handedIn = in_array($status, ['submitted', 'validated', 'redo'], true);

        // Une remise rendue a un contenu : un brouillon peut etre vide, une
        // remise soumise non (spec 12.5).
        if ($handedIn && ($body === null || (is_string($body) && trim($body) === '')) && $file === null) {
            $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'training.submissions', $index, 'body'), sprintf(
                "A '%s' submission requires a body or a file.",
                (string) $status,
            ));
        }

        if ($handedIn && $submittedAt === null) {
            $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'training.submissions', $index, 'submitted_offset_minutes'), sprintf(
                "A '%s' submission requires a submission offset.",
                (string) $status,
            ));
        }

        if (in_array($status, ['validated', 'redo'], true) && $reviewedBy === null) {
            $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'training.submissions', $index, 'reviewed_by'), sprintf(
                "A '%s' submission requires a reviewer.",
                (string) $status,
            ));
        }

        if (($reviewedBy === null) !== ($reviewedAt === null)) {
            $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'training.submissions', $index, 'reviewed_offset_minutes'), 'A review offset is present if and only if a reviewer is.');
        }

        if ($loop !== null && is_string($reviewedBy) && ! $graph->leads($loop, $reviewedBy)) {
            $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, 'training.submissions', $index, 'reviewed_by'), sprintf(
                "User '%s' must be an owner or facilitator of loop '%s'.",
                $reviewedBy,
                $loop,
            ));
        }

        // Le fichier d'une remise vient du Dossier racine de la meme Loop
        // (spec 12.5) : jamais d'un Dossier d'une autre Boucle.
        if ($loop !== null && is_string($file)) {
            $loopObject = $graph->find('loops', $loop);
            $rootDossier = $loopObject->root_dossier ?? null;
            $fileObject = $graph->find('files', $file);

            if ($fileObject !== null && is_string($rootDossier) && ($fileObject->dossier ?? null) !== $rootDossier) {
                $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, 'training.submissions', $index, 'file'), sprintf(
                    "A submission file must live in the root dossier of loop '%s'.",
                    $loop,
                ));
            }
        }

        $this->assertIncreasing($graph, 'training.submissions', $index, [
            'submitted_offset_minutes' => $submittedAt,
            'reviewed_offset_minutes' => $reviewedAt,
        ], $errors);
    }

    /**
     * Les offsets d'une meme ligne se suivent dans l'ordre ou les faits se
     * produisent (spec 12.3 et 12.5). Un `null` ne rompt pas la chaine : il
     * signifie que l'etape n'a pas eu lieu.
     *
     * @param  array<string, mixed>  $offsets
     */
    private function assertIncreasing(ManifestGraph $graph, string $collection, int $index, array $offsets, ManifestErrorBag $errors): void
    {
        $previousValue = null;
        $previousField = null;

        foreach ($offsets as $field => $value) {
            if (! is_int($value)) {
                continue;
            }

            if ($previousValue !== null && $value < $previousValue) {
                $errors->add(ManifestErrorCode::TIMELINE_INCONSISTENT, $this->at($graph, $collection, $index, $field), sprintf(
                    "Offset must not precede '%s'.",
                    (string) $previousField,
                ));
            }

            $previousValue = $value;
            $previousField = $field;
        }
    }

    /**
     * @param  array<string, list<array{index: int, position: int}>>  $groups
     */
    private function assertContiguousPositions(ManifestGraph $graph, string $collection, array $groups, string $scopeField, ManifestErrorBag $errors): void
    {
        foreach ($groups as $scope => $entries) {
            $size = count($entries);
            $seen = [];

            foreach ($entries as $entry) {
                $path = $this->at($graph, $collection, $entry['index'], 'position');

                if (array_key_exists($entry['position'], $seen)) {
                    $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $path, sprintf(
                        "Position %d is already used in %s '%s' at %s.",
                        $entry['position'],
                        $scopeField,
                        $scope,
                        $seen[$entry['position']],
                    ));

                    continue;
                }

                $seen[$entry['position']] = $path;

                if ($entry['position'] >= $size) {
                    $errors->add(ManifestErrorCode::INVALID_FORMAT, $path, sprintf(
                        'Positions must be contiguous from 0; with %d entries the last position is %d.',
                        $size,
                        $size - 1,
                    ));
                }
            }
        }
    }

    private function assertTrainingLoop(ManifestGraph $graph, string $collection, int $index, string $field, string $loop, ManifestErrorBag $errors): void
    {
        $loopObject = $graph->find('loops', $loop);

        if ($loopObject !== null && ($loopObject->type ?? null) !== 'training') {
            $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, $collection, $index, $field), sprintf(
                "Training objects require a loop with the type 'training'; loop '%s' has another type.",
                $loop,
            ));
        }
    }

    private function assertLeads(ManifestGraph $graph, string $collection, int $index, string $field, string $loop, mixed $user, ManifestErrorBag $errors): void
    {
        if (! is_string($user) || ! $graph->has('loops', $loop) || $graph->leads($loop, $user)) {
            return;
        }

        $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, $collection, $index, $field), sprintf(
            "User '%s' must be an owner or facilitator of loop '%s'.",
            $user,
            $loop,
        ));
    }

    private function loopOfModule(ManifestGraph $graph, string $moduleKey): ?string
    {
        $loop = $graph->find('training.modules', $moduleKey)?->loop ?? null;

        return is_string($loop) ? $loop : null;
    }

    private function loopOfSequence(ManifestGraph $graph, string $sequenceKey): ?string
    {
        $module = $graph->find('training.sequences', $sequenceKey)?->module ?? null;

        return is_string($module) ? $this->loopOfModule($graph, $module) : null;
    }

    private function at(ManifestGraph $graph, string $collection, int $index, string ...$fields): string
    {
        $path = JsonPointer::child($graph->pathOf($collection), $index);

        foreach ($fields as $field) {
            $path = JsonPointer::child($path, $field);
        }

        return $path;
    }
}
