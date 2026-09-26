<?php

namespace App\Support\ScenarioManifest;

/**
 * Phases 4 et 6 de la spec 9.1 pour le vocabulaire CORE (spec 11) : cles
 * composees, invariants relationnels et invariants de tenant.
 *
 * Ces regles sont celles qu'un schema JSON ne peut pas porter : elles parlent
 * de la COHERENCE entre deux objets, pas de la forme d'une valeur. Un
 * manifeste dont chaque champ est individuellement bien forme peut decrire un
 * monde impossible — un auteur qui n'est pas membre de sa Boucle, une Boucle
 * sans owner, deux messages au meme rang. Le loader les ferait echouer a
 * mi-chemin ; le Validator les refuse avant toute ecriture.
 */
final class ManifestCoreInvariants
{
    public function validate(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $this->validateEnvelope($graph, $errors);
        $this->validateUsers($graph, $errors);
        $this->validateLoops($graph, $errors);
        $this->validateMemberships($graph, $errors);
        $this->validateDossiers($graph, $errors);
        $this->validateArticles($graph, $errors);
        $this->validateFiles($graph, $errors);
        $this->validateMessages($graph, $errors);
        $this->validateMarketplace($graph, $errors);
        $this->validatePolls($graph, $errors);
        $this->validateEvents($graph, $errors);
        $this->validateDecisions($graph, $errors);
        $this->validateRoadmap($graph, $errors);
    }

    private function validateEnvelope(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $root = $graph->root();
        $organization = $root->organization ?? null;
        $locale = $root->locale ?? null;

        if ($organization instanceof \stdClass && is_string($locale) && is_string($organization->locale ?? null)
            && $organization->locale !== $locale) {
            $errors->add(ManifestErrorCode::INVALID_ENUM, '/organization/locale', sprintf(
                "Organization locale must be identical to the manifest locale '%s'.",
                $locale,
            ));
        }

        $slug = $organization instanceof \stdClass ? ($organization->proposed_slug ?? null) : null;

        // Un slug reserve n'est pas une faute de forme : c'est une tentative de
        // designer une Organization existante (spec 10.2). Le code le dit.
        if (is_string($slug) && in_array($slug, ManifestSchema::RESERVED_SLUGS, true)) {
            $errors->add(ManifestErrorCode::TENANT_TARGET_FORBIDDEN, '/organization/proposed_slug', sprintf(
                "Slug '%s' is reserved; a manifest proposes a new sandbox slug, never an existing one.",
                $slug,
            ));
        }

        // Un manifeste CHARGEABLE a au moins un user, une Boucle, un
        // membership et un Dossier (spec 9.2). Zero objet reste valide pour
        // toutes les autres collections.
        foreach (['users' => 'user', 'loops' => 'loop', 'memberships' => 'membership', 'dossiers' => 'dossier'] as $collection => $label) {
            $node = $root->{$collection} ?? null;

            if (is_array($node) && $node === []) {
                $errors->add(ManifestErrorCode::MISSING_FIELD, '/'.$collection, sprintf(
                    'A loadable manifest declares at least one %s.',
                    $label,
                ));
            }
        }
    }

    private function validateUsers(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $seenEmails = [];

        foreach ($graph->collection('users') as $index => $user) {
            $email = $user->email ?? null;

            if (is_string($email)) {
                $normalized = mb_strtolower($email, 'UTF-8');

                if (array_key_exists($normalized, $seenEmails)) {
                    $errors->add(ManifestErrorCode::DUPLICATE_KEY, $this->at($graph, 'users', $index, 'email'), sprintf(
                        "Email '%s' is already used at %s.",
                        $email,
                        $seenEmails[$normalized],
                    ));
                } else {
                    $seenEmails[$normalized] = $this->at($graph, 'users', $index, 'email');
                }
            }

            $profile = $user->member_ai_profile ?? null;

            if (! $profile instanceof \stdClass || ($profile->status ?? null) !== 'published') {
                continue;
            }

            // Un profil publie est VISIBLE par les autres membres : la spec 7.2
            // exige qu'il dise au moins qui est la personne et sur quoi elle
            // peut aider.
            $summary = $profile->summary ?? null;

            if (! is_string($summary) || trim($summary) === '') {
                $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'users', $index, 'member_ai_profile', 'summary'), 'A published member AI profile requires a non-empty summary.');
            }

            $skills = is_array($profile->skills ?? null) ? $profile->skills : [];
            $problems = is_array($profile->problems_helped ?? null) ? $profile->problems_helped : [];

            if ($skills === [] && $problems === []) {
                $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'users', $index, 'member_ai_profile', 'skills'), 'A published member AI profile requires at least one skill or one problem helped.');
            }
        }
    }

    private function validateLoops(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $rootDossiers = [];

        foreach ($graph->collection('loops') as $index => $loop) {
            $key = $loop->key ?? null;
            $owner = $loop->owner ?? null;

            if (is_string($key) && is_string($owner)) {
                $ownerMemberships = array_keys($graph->membersOf($key), 'owner', true);

                if ($ownerMemberships === []) {
                    $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, 'loops', $index, 'owner'), sprintf(
                        "Loop '%s' declares no membership with the owner role.",
                        $key,
                    ));
                } elseif (! in_array($owner, $ownerMemberships, true)) {
                    $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, 'loops', $index, 'owner'), sprintf(
                        "Loop owner '%s' is not the user holding the owner membership.",
                        $owner,
                    ));
                }
            }

            $rootDossier = $loop->root_dossier ?? null;

            if (! is_string($rootDossier) || ! is_string($key)) {
                continue;
            }

            // Aucun autre Dossier ne peut revendiquer la meme Loop comme
            // racine (spec 7.4) ; symetriquement, deux Boucles ne peuvent pas
            // partager une racine.
            if (array_key_exists($rootDossier, $rootDossiers)) {
                $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $this->at($graph, 'loops', $index, 'root_dossier'), sprintf(
                    "Dossier '%s' is already the root dossier of loop '%s'.",
                    $rootDossier,
                    $rootDossiers[$rootDossier],
                ));

                continue;
            }

            $rootDossiers[$rootDossier] = $key;

            $dossier = $graph->find('dossiers', $rootDossier);

            if ($dossier === null) {
                continue;
            }

            $dossierIndex = (int) $graph->indexOf('dossiers', $rootDossier);

            if (($dossier->loop ?? null) !== $key) {
                $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, 'dossiers', $dossierIndex, 'loop'), sprintf(
                    "Root dossier of loop '%s' must belong to that loop.",
                    $key,
                ));
            }

            if (($dossier->parent ?? null) !== null) {
                $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, 'dossiers', $dossierIndex, 'parent'), 'A root dossier has no parent.');
            }

            if (($dossier->visibility ?? null) !== 'loop') {
                $errors->add(ManifestErrorCode::INVALID_ENUM, $this->at($graph, 'dossiers', $dossierIndex, 'visibility'), "A root dossier has the visibility 'loop'.");
            }

            if (($dossier->root_document ?? null) === null) {
                $errors->add(ManifestErrorCode::MISSING_FIELD, $this->at($graph, 'dossiers', $dossierIndex, 'root_document'), 'A root dossier requires a root document.');
            }
        }
    }

    private function validateMemberships(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $seen = [];
        $owners = [];

        foreach ($graph->collection('memberships') as $index => $membership) {
            $loop = $membership->loop ?? null;
            $user = $membership->user ?? null;
            $role = $membership->role ?? null;

            if (! is_string($loop) || ! is_string($user)) {
                continue;
            }

            $pair = $loop."\0".$user;

            if (array_key_exists($pair, $seen)) {
                $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $this->at($graph, 'memberships', $index, 'user'), sprintf(
                    'Membership (%s, %s) is already declared at %s.',
                    $loop,
                    $user,
                    $seen[$pair],
                ));

                continue;
            }

            $seen[$pair] = $this->at($graph, 'memberships', $index, 'user');

            if ($role !== 'owner') {
                continue;
            }

            if (array_key_exists($loop, $owners)) {
                $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, 'memberships', $index, 'role'), sprintf(
                    "Loop '%s' already has an owner membership at %s; a loop has exactly one.",
                    $loop,
                    $owners[$loop],
                ));

                continue;
            }

            $owners[$loop] = $this->at($graph, 'memberships', $index, 'role');
        }
    }

    private function validateDossiers(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        foreach ($graph->collection('dossiers') as $index => $dossier) {
            $loop = $dossier->loop ?? null;
            $owner = $dossier->owner ?? null;

            if (is_string($loop) && is_string($owner) && $graph->has('loops', $loop) && ! $graph->isMember($loop, $owner)) {
                $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, 'dossiers', $index, 'owner'), sprintf(
                    "User '%s' owns a dossier of loop '%s' without being a member of it.",
                    $owner,
                    $loop,
                ));
            }

            // Un enfant herite du perimetre effectif de son parent (spec 7.4) :
            // un sous-dossier ne peut pas s'echapper vers une autre Boucle.
            $parent = $dossier->parent ?? null;

            if (is_string($parent)) {
                $parentDossier = $graph->find('dossiers', $parent);

                if ($parentDossier !== null && ($parentDossier->loop ?? null) !== $loop) {
                    $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, 'dossiers', $index, 'parent'), 'A child dossier inherits the effective scope of its parent.');
                }
            }

            $document = $dossier->root_document ?? null;

            if (! $document instanceof \stdClass) {
                continue;
            }

            $author = $document->author ?? null;

            if (is_string($author) && is_string($loop) && $graph->has('loops', $loop) && ! $graph->isMember($loop, $author)) {
                $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, 'dossiers', $index, 'root_document', 'author'), sprintf(
                    "User '%s' authors a document of loop '%s' without being a member of it.",
                    $author,
                    $loop,
                ));
            }
        }
    }

    private function validateArticles(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        foreach ($graph->collection('articles') as $index => $article) {
            $dossier = $article->dossier ?? null;
            $author = $article->author ?? null;

            if (is_string($dossier) && is_string($author)) {
                $this->assertAuthorisedOnDossier($graph, $dossier, $author, $this->at($graph, 'articles', $index, 'author'), $errors);
            }

            // Une audience `loop` exige un Dossier gouverne par une Loop
            // (spec 7.4) : sans Boucle, l'audience n'existerait pas.
            if (($article->audience ?? null) === 'loop' && is_string($dossier) && $graph->loopOfDossier($dossier) === null) {
                $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, 'articles', $index, 'audience'), "Audience 'loop' requires a dossier governed by a loop.");
            }

            $status = $article->status ?? null;
            $published = $article->published_offset_minutes ?? null;
            $path = $this->at($graph, 'articles', $index, 'published_offset_minutes');

            if ($status === 'published' && $published === null) {
                $errors->add(ManifestErrorCode::MISSING_FIELD, $path, 'A published article requires a publication offset.');
            } elseif ($status === 'published' && is_int($published) && $published > 0) {
                $errors->add(ManifestErrorCode::TIMELINE_INCONSISTENT, $path, 'A published article cannot be published in the future.');
            } elseif ($status === 'draft' && $published !== null) {
                $errors->add(ManifestErrorCode::TIMELINE_INCONSISTENT, $path, 'A draft article has no publication offset.');
            }
        }
    }

    private function validateFiles(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        foreach ($graph->collection('files') as $index => $file) {
            $dossier = $file->dossier ?? null;
            $uploader = $file->uploaded_by ?? null;

            if (is_string($dossier) && is_string($uploader)) {
                $this->assertAuthorisedOnDossier($graph, $dossier, $uploader, $this->at($graph, 'files', $index, 'uploaded_by'), $errors);
            }

            $name = $file->name ?? null;
            $mediaType = $file->media_type ?? null;

            if (! is_string($name) || ! is_string($mediaType)) {
                continue;
            }

            $expected = str_ends_with($name, '.md') ? 'text/markdown' : (str_ends_with($name, '.html') ? 'text/html' : null);

            if ($expected !== null && $mediaType !== $expected) {
                $errors->add(ManifestErrorCode::INVALID_FORMAT, $this->at($graph, 'files', $index, 'media_type'), sprintf(
                    "Media type must be '%s' for a file named '%s'.",
                    $expected,
                    $name,
                ));
            }
        }
    }

    private function validateMessages(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $orders = [];

        foreach ($graph->collection('messages') as $index => $message) {
            $loop = $message->loop ?? null;
            $author = $message->author ?? null;

            if (! is_string($loop)) {
                continue;
            }

            if (is_string($author) && $graph->has('loops', $loop) && ! $graph->isMember($loop, $author)) {
                $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, 'messages', $index, 'author'), sprintf(
                    "User '%s' writes in loop '%s' without being a member of it.",
                    $author,
                    $loop,
                ));
            }

            $order = $message->order ?? null;

            if (is_int($order)) {
                if (isset($orders[$loop][$order])) {
                    $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $this->at($graph, 'messages', $index, 'order'), sprintf(
                        "Order %d is already used in loop '%s' at %s.",
                        $order,
                        $loop,
                        $orders[$loop][$order],
                    ));
                } else {
                    $orders[$loop][$order] = $this->at($graph, 'messages', $index, 'order');
                }
            }

            $replyTo = $message->reply_to ?? null;

            if (! is_string($replyTo)) {
                continue;
            }

            $parent = $graph->find('messages', $replyTo);

            if ($parent === null) {
                continue;
            }

            if (($parent->loop ?? null) !== $loop) {
                $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, 'messages', $index, 'reply_to'), 'A reply targets a message of the same loop.');

                continue;
            }

            if (is_int($order) && is_int($parent->order ?? null) && $parent->order >= $order) {
                $errors->add(ManifestErrorCode::TIMELINE_INCONSISTENT, $this->at($graph, 'messages', $index, 'reply_to'), 'A reply targets an earlier message of the same loop.');
            }
        }

        $this->assertMessageTimeline($graph, $errors);
    }

    /**
     * "L'ordre temporel doit suivre `order`" (spec 7.5). Sans cette regle, une
     * Boucle pourrait s'afficher dans un ordre et s'etre deroulee dans un
     * autre : la previsualisation mentirait sur la conversation.
     */
    private function assertMessageTimeline(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $byLoop = [];

        foreach ($graph->collection('messages') as $index => $message) {
            $loop = $message->loop ?? null;
            $order = $message->order ?? null;
            $offset = $message->offset_minutes ?? null;

            if (is_string($loop) && is_int($order) && is_int($offset)) {
                $byLoop[$loop][] = ['index' => $index, 'order' => $order, 'offset' => $offset];
            }
        }

        foreach ($byLoop as $messages) {
            usort($messages, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

            $previous = null;

            foreach ($messages as $message) {
                if ($previous !== null && $message['offset'] < $previous) {
                    $errors->add(ManifestErrorCode::TIMELINE_INCONSISTENT, $this->at($graph, 'messages', $message['index'], 'offset_minutes'), 'Message offsets must follow the declared order within a loop.');
                }

                $previous = $message['offset'];
            }
        }
    }

    private function validateMarketplace(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        foreach ($graph->collection('service_requests') as $index => $request) {
            $min = $request->budget_min ?? null;
            $max = $request->budget_max ?? null;

            if (is_int($min) && is_int($max) && $max < $min) {
                $errors->add(ManifestErrorCode::INVALID_FORMAT, $this->at($graph, 'service_requests', $index, 'budget_max'), 'Maximum budget must be greater than or equal to the minimum budget.');
            }

            $this->assertHighlightMembership($graph, 'service_requests', $index, $request, $errors);
        }

        foreach ($graph->collection('services') as $index => $service) {
            $this->assertHighlightMembership($graph, 'services', $index, $service, $errors);
        }
    }

    /**
     * `highlight_in_loop` demande la mise en avant produit d'une Offre/Demande
     * dans une Boucle. Il ne cree pas de lien cross-tenant (spec 7.6) : encore
     * faut-il que son auteur soit membre de la Boucle ou il apparait.
     */
    private function assertHighlightMembership(ManifestGraph $graph, string $collection, int $index, \stdClass $item, ManifestErrorBag $errors): void
    {
        $loop = $item->highlight_in_loop ?? null;
        $author = $item->author ?? null;

        if (is_string($loop) && is_string($author) && $graph->has('loops', $loop) && ! $graph->isMember($loop, $author)) {
            $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, $collection, $index, 'highlight_in_loop'), sprintf(
                "User '%s' cannot highlight in loop '%s' without being a member of it.",
                $author,
                $loop,
            ));
        }
    }

    private function validatePolls(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        foreach ($graph->collection('polls') as $index => $poll) {
            $loop = $poll->loop ?? null;
            $author = $poll->author ?? null;

            if (! is_string($loop)) {
                continue;
            }

            $this->assertLoopMember($graph, 'polls', $index, 'author', $loop, $author, $errors);

            $options = is_array($poll->options ?? null) ? $poll->options : [];
            $optionKeys = [];
            $labels = [];

            foreach ($options as $optionIndex => $option) {
                if (! $option instanceof \stdClass) {
                    continue;
                }

                if (is_string($option->key ?? null)) {
                    $optionKeys[] = $option->key;
                }

                $label = $option->label ?? null;

                if (! is_string($label)) {
                    continue;
                }

                // Distincts apres trim et casse (spec 7.7) : deux libelles
                // qui se lisent pareil ne sont pas un choix.
                $normalized = mb_strtolower(trim($label), 'UTF-8');
                $labelPath = $this->at($graph, 'polls', $index, 'options', (string) $optionIndex, 'label');

                if (array_key_exists($normalized, $labels)) {
                    $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $labelPath, sprintf(
                        'Option label is not distinct from the one at %s.',
                        $labels[$normalized],
                    ));

                    continue;
                }

                $labels[$normalized] = $labelPath;
            }

            $this->validatePollVotes($graph, $index, $poll, $loop, $optionKeys, $errors);
        }
    }

    /**
     * @param  list<string>  $optionKeys
     */
    private function validatePollVotes(ManifestGraph $graph, int $index, \stdClass $poll, string $loop, array $optionKeys, ManifestErrorBag $errors): void
    {
        $votes = is_array($poll->votes ?? null) ? $poll->votes : [];
        $selectionType = $poll->selection_type ?? null;
        $voters = [];

        foreach ($votes as $voteIndex => $vote) {
            if (! $vote instanceof \stdClass) {
                continue;
            }

            $votePath = $this->at($graph, 'polls', $index, 'votes', (string) $voteIndex);
            $user = $vote->user ?? null;

            if (is_string($user)) {
                if (array_key_exists($user, $voters)) {
                    $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, JsonPointer::child($votePath, 'user'), sprintf(
                        "User '%s' already voted at %s; a user votes once in a poll.",
                        $user,
                        $voters[$user],
                    ));
                } else {
                    $voters[$user] = JsonPointer::child($votePath, 'user');

                    if ($graph->has('loops', $loop) && ! $graph->isMember($loop, $user)) {
                        $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, JsonPointer::child($votePath, 'user'), sprintf(
                            "User '%s' votes in loop '%s' without being a member of it.",
                            $user,
                            $loop,
                        ));
                    }
                }
            }

            $selected = is_array($vote->options ?? null) ? $vote->options : [];
            $optionsPath = JsonPointer::child($votePath, 'options');

            if ($selectionType === 'single' && count($selected) !== 1) {
                $errors->add(ManifestErrorCode::INVALID_FORMAT, $optionsPath, 'A single-choice poll takes exactly one option per vote.');
            }

            if (count($selected) !== count(array_unique($selected))) {
                $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $optionsPath, 'A vote selects each option at most once.');
            }

            foreach ($selected as $selectedIndex => $optionKey) {
                if (is_string($optionKey) && ! in_array($optionKey, $optionKeys, true)) {
                    $errors->add(ManifestErrorCode::REFERENCE_NOT_FOUND, JsonPointer::child($optionsPath, (string) $selectedIndex), sprintf(
                        "Unknown poll option key '%s'.",
                        $optionKey,
                    ));
                }
            }
        }
    }

    private function validateEvents(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        foreach ($graph->collection('events') as $index => $event) {
            $loop = $event->loop ?? null;

            if (! is_string($loop)) {
                continue;
            }

            $author = $event->author ?? null;
            $this->assertLoopMember($graph, 'events', $index, 'author', $loop, $author, $errors);

            $format = $event->format ?? null;
            $needsLocation = in_array($format, ['in_person', 'hybrid'], true);
            $needsUrl = in_array($format, ['online', 'hybrid'], true);

            $this->assertRequiredWhen($errors, $needsLocation, $event->location ?? null, $this->at($graph, 'events', $index, 'location'), 'location', (string) $format);
            $this->assertRequiredWhen($errors, $needsUrl, $event->meeting_url ?? null, $this->at($graph, 'events', $index, 'meeting_url'), 'meeting url', (string) $format);

            // Regle V1 volontairement plus etroite que toute permission
            // dynamique du produit (spec 7.7).
            if (($event->visibility ?? null) === 'organization') {
                $loopObject = $graph->find('loops', $loop);

                if ($loopObject !== null && ($loopObject->visibility ?? null) !== 'public') {
                    $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, 'events', $index, 'visibility'), "An organization-wide event requires a loop with the visibility 'public'.");
                }

                if (is_string($author) && $graph->roleIn($loop, $author) !== 'owner') {
                    $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, 'events', $index, 'visibility'), 'An organization-wide event is authored by the loop owner.');
                }
            }

            $this->validateEventResponses($graph, $index, $event, $loop, $errors);
        }
    }

    private function validateEventResponses(ManifestGraph $graph, int $index, \stdClass $event, string $loop, ManifestErrorBag $errors): void
    {
        $responses = is_array($event->responses ?? null) ? $event->responses : [];
        $seen = [];

        foreach ($responses as $responseIndex => $response) {
            if (! $response instanceof \stdClass || ! is_string($response->user ?? null)) {
                continue;
            }

            $user = $response->user;
            $path = $this->at($graph, 'events', $index, 'responses', (string) $responseIndex, 'user');

            if (array_key_exists($user, $seen)) {
                $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $path, sprintf(
                    "User '%s' already responded at %s.",
                    $user,
                    $seen[$user],
                ));

                continue;
            }

            $seen[$user] = $path;

            if ($graph->has('loops', $loop) && ! $graph->isMember($loop, $user)) {
                $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $path, sprintf(
                    "User '%s' responds to an event of loop '%s' without being a member of it.",
                    $user,
                    $loop,
                ));
            }
        }
    }

    private function validateDecisions(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $superseded = [];

        /** @var array<string, string> cle de message -> cle de la decision qui l'a pris */
        $decisionsByMessage = [];

        foreach ($graph->collection('decisions') as $index => $decision) {
            $loop = $decision->loop ?? null;

            if (! is_string($loop)) {
                continue;
            }

            $this->assertLoopMember($graph, 'decisions', $index, 'author', $loop, $decision->author ?? null, $errors);

            $message = $decision->message ?? null;

            if (is_string($message)) {
                $target = $graph->find('messages', $message);

                if ($target !== null && ($target->loop ?? null) !== $loop) {
                    $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, 'decisions', $index, 'message'), 'A decision cites a message of its own loop.');
                }

                // TASK-1647 — un message ne peut sourcer qu'UNE decision.
                //
                // Ce n'est pas une regle inventee ici : la table porte deja
                // `unique(loop_id, loop_message_id)`. Le langage etait
                // simplement plus permissif que le produit, et cet ecart se
                // payait au chargement — la seconde decision ne creait rien,
                // son titre n'etait ecrit nulle part, et un roadmap item qui
                // la citait s'accrochait silencieusement a la premiere.
                //
                // Le refus arrive donc a la VALIDATION, avec un chemin JSON
                // precis, plutot qu'en divergence silencieuse au Load.
                if (array_key_exists($message, $decisionsByMessage)) {
                    $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $this->at($graph, 'decisions', $index, 'message'), sprintf(
                        "Message '%s' is already the source of decision '%s'.",
                        $message,
                        $decisionsByMessage[$message],
                    ));
                } else {
                    $decisionsByMessage[$message] = (string) ($decision->key ?? '');
                }
            }

            $supersedes = $decision->supersedes ?? null;

            if (! is_string($supersedes)) {
                continue;
            }

            $path = $this->at($graph, 'decisions', $index, 'supersedes');

            if ($supersedes === ($decision->key ?? null)) {
                $errors->add(ManifestErrorCode::REFERENCE_CYCLE, $path, 'A decision cannot supersede itself.');

                continue;
            }

            // "Une decision ne peut etre remplacee qu'une fois" (spec 7.7) :
            // deux remplacantes rendraient l'historique indecidable.
            if (array_key_exists($supersedes, $superseded)) {
                $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $path, sprintf(
                    "Decision '%s' is already superseded at %s.",
                    $supersedes,
                    $superseded[$supersedes],
                ));

                continue;
            }

            $superseded[$supersedes] = $path;

            $previous = $graph->find('decisions', $supersedes);

            if ($previous === null) {
                continue;
            }

            if (($previous->loop ?? null) !== $loop) {
                $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $path, 'A decision supersedes a decision of its own loop.');

                continue;
            }

            if (is_int($previous->decided_day_offset ?? null) && is_int($decision->decided_day_offset ?? null)
                && $previous->decided_day_offset > $decision->decided_day_offset) {
                $errors->add(ManifestErrorCode::TIMELINE_INCONSISTENT, $path, 'A decision supersedes an earlier decision.');
            }
        }
    }

    private function validateRoadmap(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        $positions = [];

        foreach ($graph->collection('roadmap_items') as $index => $item) {
            $loop = $item->loop ?? null;

            if (! is_string($loop)) {
                continue;
            }

            $this->assertLoopMember($graph, 'roadmap_items', $index, 'created_by', $loop, $item->created_by ?? null, $errors);

            $status = $item->status ?? null;
            $position = $item->position ?? null;

            if (is_string($status) && is_int($position)) {
                $bucket = $loop."\0".$status;

                if (isset($positions[$bucket][$position])) {
                    $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $this->at($graph, 'roadmap_items', $index, 'position'), sprintf(
                        "Position %d is already used in loop '%s' with status '%s' at %s.",
                        $position,
                        $loop,
                        $status,
                        $positions[$bucket][$position],
                    ));
                } else {
                    $positions[$bucket][$position] = $this->at($graph, 'roadmap_items', $index, 'position');
                }
            }

            $assignees = is_array($item->assignees ?? null) ? $item->assignees : [];
            $seenAssignees = [];

            foreach ($assignees as $assigneeIndex => $assignee) {
                if (! is_string($assignee)) {
                    continue;
                }

                $path = $this->at($graph, 'roadmap_items', $index, 'assignees', (string) $assigneeIndex);

                if (in_array($assignee, $seenAssignees, true)) {
                    $errors->add(ManifestErrorCode::DUPLICATE_COMPOSITE_KEY, $path, 'Assignees must be distinct.');

                    continue;
                }

                $seenAssignees[] = $assignee;

                if ($graph->has('loops', $loop) && ! $graph->isMember($loop, $assignee)) {
                    $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $path, sprintf(
                        "User '%s' is assigned in loop '%s' without being a member of it.",
                        $assignee,
                        $loop,
                    ));
                }
            }

            $decision = $item->decision ?? null;

            if (is_string($decision)) {
                $target = $graph->find('decisions', $decision);

                if ($target !== null && ($target->loop ?? null) !== $loop) {
                    $errors->add(ManifestErrorCode::REFERENCE_WRONG_SCOPE, $this->at($graph, 'roadmap_items', $index, 'decision'), 'A roadmap item cites a decision of its own loop.');
                }
            }
        }
    }

    /**
     * Un auteur autorise sur un Dossier est membre de la Boucle qui le
     * gouverne ; hors Boucle, seul son proprietaire l'est.
     */
    private function assertAuthorisedOnDossier(ManifestGraph $graph, string $dossierKey, string $userKey, string $path, ManifestErrorBag $errors): void
    {
        $dossier = $graph->find('dossiers', $dossierKey);

        if ($dossier === null) {
            return;
        }

        $loop = $dossier->loop ?? null;

        if (is_string($loop)) {
            if ($graph->has('loops', $loop) && ! $graph->isMember($loop, $userKey)) {
                $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $path, sprintf(
                    "User '%s' is not a member of loop '%s' and cannot author content in its dossier.",
                    $userKey,
                    $loop,
                ));
            }

            return;
        }

        if (($dossier->owner ?? null) !== $userKey) {
            $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $path, sprintf(
                "User '%s' is not allowed to author content in dossier '%s'.",
                $userKey,
                $dossierKey,
            ));
        }
    }

    private function assertLoopMember(ManifestGraph $graph, string $collection, int $index, string $field, string $loop, mixed $user, ManifestErrorBag $errors): void
    {
        if (! is_string($user) || ! $graph->has('loops', $loop) || $graph->isMember($loop, $user)) {
            return;
        }

        $errors->add(ManifestErrorCode::OWNER_MEMBERSHIP_MISMATCH, $this->at($graph, $collection, $index, $field), sprintf(
            "User '%s' acts in loop '%s' without being a member of it.",
            $user,
            $loop,
        ));
    }

    private function assertRequiredWhen(ManifestErrorBag $errors, bool $required, mixed $value, string $path, string $label, string $format): void
    {
        if ($required && $value === null) {
            $errors->add(ManifestErrorCode::MISSING_FIELD, $path, sprintf(
                "An event with the format '%s' requires a %s.",
                $format,
                $label,
            ));

            return;
        }

        if (! $required && $value !== null) {
            $errors->add(ManifestErrorCode::INVALID_FORMAT, $path, sprintf(
                "An event with the format '%s' has no %s.",
                $format,
                $label,
            ));
        }
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
