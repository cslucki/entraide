<?php

namespace Tests\Unit\ScenarioManifest;

use App\Support\ScenarioManifest\ManifestValidationResult;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScenarioManifest\AmtReferenceManifest;

/**
 * TASK-1641 — les mutations de l'exemple AMT qui brisent une regle NORMATIVE.
 *
 * Chaque cas part du document valide et n'y change qu'une chose. Un test de
 * mutation ne prouve rien s'il part d'un document deja rouge : il faut que le
 * vert de la reference et le rouge de la mutation ne different que par la
 * regle testee. C'est aussi la raison pour laquelle chaque cas verifie le CODE
 * et le PATH attendus, et non le seul verdict INVALID — un document peut etre
 * rouge pour la mauvaise raison.
 */
class ScenarioManifestInvariantsTest extends TestCase
{
    private ScenarioManifestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ScenarioManifestValidator;
    }

    public function test_an_unknown_schema_version_is_refused(): void
    {
        foreach (['1.1', '2.0', '1', 'v1.0'] as $version) {
            $result = $this->validator->validate(AmtReferenceManifest::mutate(
                static fn (\stdClass $manifest) => $manifest->schema_version = $version,
            ));

            $this->assertSame(['UNKNOWN_SCHEMA_VERSION'], $result->errorCodes(), $version);
            $this->assertSame('/schema_version', $result->errors()[0]->path, $version);
        }
    }

    public function test_an_unknown_field_is_refused_at_every_level(): void
    {
        $this->assertRefuses('UNKNOWN_FIELD', '/users/0/nickname', static fn (\stdClass $m) => $m->users[0]->nickname = 'Nono');
        $this->assertRefuses('UNKNOWN_FIELD', '/loops/0/preset', static fn (\stdClass $m) => $m->loops[0]->preset = 'training');
        $this->assertRefuses('UNKNOWN_FIELD', '/training/path_mode', static fn (\stdClass $m) => $m->training->path_mode = 'free');
        $this->assertRefuses('UNKNOWN_FIELD', '/assets/avatar_pack', static fn (\stdClass $m) => $m->assets->avatar_pack = 'x');
    }

    public function test_a_missing_required_field_is_refused(): void
    {
        $this->assertRefuses('MISSING_FIELD', '/users/0/available', static function (\stdClass $m): void {
            unset($m->users[0]->available);
        });

        $this->assertRefuses('MISSING_FIELD', '/training/sequences/0/content/article', static function (\stdClass $m): void {
            unset($m->training->sequences[0]->content->article);
        });
    }

    public function test_a_duplicate_stable_key_is_refused(): void
    {
        $this->assertRefuses('DUPLICATE_KEY', '/users/2/key', static fn (\stdClass $m) => $m->users[2]->key = 'trainer-1');
        $this->assertRefuses('DUPLICATE_KEY', '/loops/1/key', static fn (\stdClass $m) => $m->loops[1]->key = 'training-main');
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $this->assertRefuses('DUPLICATE_KEY', '/users/3/email', static fn (\stdClass $m) => $m->users[3]->email = $m->users[2]->email);
    }

    public function test_a_duplicate_membership_pair_is_refused(): void
    {
        $this->assertRefuses('DUPLICATE_COMPOSITE_KEY', '/memberships/5/user', static function (\stdClass $m): void {
            $m->memberships[5]->loop = $m->memberships[4]->loop;
            $m->memberships[5]->user = $m->memberships[4]->user;
        });
    }

    public function test_a_reference_to_an_unknown_user_is_refused(): void
    {
        $this->assertRefuses('REFERENCE_NOT_FOUND', '/messages/1/author', static fn (\stdClass $m) => $m->messages[1]->author = 'student-99');
    }

    public function test_a_reference_to_an_unknown_loop_is_refused(): void
    {
        $this->assertRefuses('REFERENCE_NOT_FOUND', '/messages/0/loop', static fn (\stdClass $m) => $m->messages[0]->loop = 'ghost-loop');
    }

    public function test_a_loop_whose_owner_holds_no_owner_membership_is_refused(): void
    {
        $this->assertRefuses('OWNER_MEMBERSHIP_MISMATCH', '/loops/0/owner', static fn (\stdClass $m) => $m->loops[0]->owner = 'student-05');
    }

    public function test_a_second_owner_membership_in_the_same_loop_is_refused(): void
    {
        $this->assertRefuses('OWNER_MEMBERSHIP_MISMATCH', '/memberships/1/role', static fn (\stdClass $m) => $m->memberships[1]->role = 'owner');
    }

    public function test_an_author_who_is_not_a_member_of_the_loop_is_refused(): void
    {
        $this->assertRefuses('OWNER_MEMBERSHIP_MISMATCH', '/polls/0/author', static function (\stdClass $m): void {
            // On retire trainer-1 de la Boucle ou il anime le sondage.
            $m->memberships = array_values(array_filter(
                $m->memberships,
                static fn (\stdClass $membership): bool => ! ($membership->loop === 'training-main' && $membership->user === 'trainer-1'),
            ));
            $m->loops[0]->owner = 'trainer-2';
            $m->memberships[0]->role = 'owner';
        });
    }

    public function test_a_forbidden_loop_type_is_refused(): void
    {
        foreach (['writing', 'networking', 'peer_support', 'ai_agent', 'custom'] as $type) {
            $result = $this->validator->validate(AmtReferenceManifest::mutate(
                static fn (\stdClass $m) => $m->loops[1]->type = $type,
            ));

            $this->assertContains('INVALID_ENUM', $result->errorCodes(), $type);
            $this->assertSame('/loops/1/type', $result->errors()[0]->path, $type);
        }
    }

    public function test_the_historical_moderator_role_is_refused(): void
    {
        // `moderator` est un alias interne historique : il n'est jamais ecrit
        // dans un manifeste (spec 7.3).
        $this->assertRefuses('INVALID_ENUM', '/memberships/1/role', static fn (\stdClass $m) => $m->memberships[1]->role = 'moderator');
    }

    public function test_the_historical_interaction_vocabulary_is_refused(): void
    {
        $this->assertRefuses('INVALID_ENUM', '/messages/0/type', static fn (\stdClass $m) => $m->messages[0]->type = 'interaction');
    }

    public function test_a_dossier_cycle_is_refused(): void
    {
        $this->assertRefuses('REFERENCE_CYCLE', '/dossiers/0/parent', static function (\stdClass $m): void {
            $m->dossiers[0]->parent = 'help-docs';
            $m->dossiers[1]->parent = 'training-docs';
        });
    }

    public function test_a_dossier_that_is_its_own_parent_is_refused(): void
    {
        $this->assertRefuses('REFERENCE_CYCLE', '/dossiers/1/parent', static fn (\stdClass $m) => $m->dossiers[1]->parent = 'help-docs');
    }

    public function test_a_training_object_attached_to_a_non_training_loop_is_refused(): void
    {
        $this->assertRefuses('REFERENCE_WRONG_SCOPE', '/training/modules/0/loop', static fn (\stdClass $m) => $m->training->modules[0]->loop = 'help-general');
        $this->assertRefuses('REFERENCE_WRONG_SCOPE', '/training/assignments/0/loop', static fn (\stdClass $m) => $m->training->assignments[0]->loop = 'help-general');
    }

    public function test_a_sequence_content_pointing_outside_its_training_loop_is_refused(): void
    {
        $this->assertRefuses('REFERENCE_WRONG_SCOPE', '/training/sequences/0/content/article', static function (\stdClass $m): void {
            $m->articles[0]->dossier = 'help-docs';
            $m->articles[0]->author = 'trainer-2';
        });
    }

    public function test_a_sequence_content_with_two_variants_is_refused(): void
    {
        // "exactement une variante" (spec 12.2).
        $this->assertRefuses('UNKNOWN_FIELD', '/training/sequences/2/content/article', static fn (\stdClass $m) => $m->training->sequences[2]->content->article = 'article-charte-ia');
    }

    public function test_non_contiguous_training_positions_are_refused(): void
    {
        $this->assertRefuses('INVALID_FORMAT', '/training/modules/1/position', static fn (\stdClass $m) => $m->training->modules[1]->position = 5);
    }

    public function test_a_validated_progress_without_a_validator_is_refused(): void
    {
        $this->assertRefuses('MISSING_FIELD', '/training/progress/1/validated_by', static function (\stdClass $m): void {
            $m->training->progress[1]->validated_by = null;
            $m->training->progress[1]->validated_offset_minutes = null;
        });
    }

    public function test_a_progress_validated_by_a_plain_member_is_refused(): void
    {
        $this->assertRefuses('OWNER_MEMBERSHIP_MISMATCH', '/training/progress/1/validated_by', static fn (\stdClass $m) => $m->training->progress[1]->validated_by = 'student-05');
    }

    public function test_progress_offsets_that_go_backwards_are_refused(): void
    {
        $this->assertRefuses('TIMELINE_INCONSISTENT', '/training/progress/0/completed_offset_minutes', static fn (\stdClass $m) => $m->training->progress[0]->completed_offset_minutes = -9000);
    }

    public function test_a_submitted_submission_without_content_is_refused(): void
    {
        $this->assertRefuses('MISSING_FIELD', '/training/submissions/0/body', static function (\stdClass $m): void {
            $m->training->submissions[0]->body = null;
            $m->training->submissions[0]->file = null;
        });
    }

    public function test_a_duplicate_submission_for_the_same_assignment_and_user_is_refused(): void
    {
        $this->assertRefuses('DUPLICATE_COMPOSITE_KEY', '/training/submissions/1/user', static fn (\stdClass $m) => $m->training->submissions[1]->user = 'student-01');
    }

    public function test_messages_whose_timeline_contradicts_their_order_are_refused(): void
    {
        $this->assertRefuses('TIMELINE_INCONSISTENT', '/messages/2/offset_minutes', static fn (\stdClass $m) => $m->messages[2]->offset_minutes = -20000);
    }

    public function test_a_reply_to_a_later_message_is_refused(): void
    {
        $this->assertRefuses('TIMELINE_INCONSISTENT', '/messages/1/reply_to', static fn (\stdClass $m) => $m->messages[1]->reply_to = 'trainer-answer');
    }

    public function test_a_reply_across_loops_is_refused(): void
    {
        $this->assertRefuses('REFERENCE_WRONG_SCOPE', '/messages/3/reply_to', static fn (\stdClass $m) => $m->messages[3]->reply_to = 'welcome');
    }

    public function test_two_messages_sharing_an_order_in_the_same_loop_are_refused(): void
    {
        $this->assertRefuses('DUPLICATE_COMPOSITE_KEY', '/messages/1/order', static fn (\stdClass $m) => $m->messages[1]->order = 0);
    }

    public function test_a_published_article_without_a_publication_offset_is_refused(): void
    {
        $this->assertRefuses('MISSING_FIELD', '/articles/0/published_offset_minutes', static fn (\stdClass $m) => $m->articles[0]->published_offset_minutes = null);
    }

    public function test_an_article_published_in_the_future_is_refused(): void
    {
        $this->assertRefuses('TIMELINE_INCONSISTENT', '/articles/0/published_offset_minutes', static fn (\stdClass $m) => $m->articles[0]->published_offset_minutes = 60);
    }

    public function test_a_file_whose_media_type_contradicts_its_extension_is_refused(): void
    {
        $this->assertRefuses('INVALID_FORMAT', '/files/0/media_type', static fn (\stdClass $m) => $m->files[0]->media_type = 'text/html');
    }

    public function test_a_single_choice_poll_with_two_selected_options_is_refused(): void
    {
        $this->assertRefuses('INVALID_FORMAT', '/polls/0/votes/0/options', static fn (\stdClass $m) => $m->polls[0]->votes[0]->options = ['sources', 'prompts']);
    }

    public function test_a_vote_for_an_unknown_option_is_refused(): void
    {
        $this->assertRefuses('REFERENCE_NOT_FOUND', '/polls/0/votes/0/options/0', static fn (\stdClass $m) => $m->polls[0]->votes[0]->options = ['ghost']);
    }

    public function test_poll_options_that_are_not_distinct_are_refused(): void
    {
        $this->assertRefuses('DUPLICATE_COMPOSITE_KEY', '/polls/0/options/1/label', static fn (\stdClass $m) => $m->polls[0]->options[1]->label = '  vérifier les sources ');
    }

    public function test_an_online_event_without_a_meeting_url_is_refused(): void
    {
        $this->assertRefuses('MISSING_FIELD', '/events/0/meeting_url', static fn (\stdClass $m) => $m->events[0]->meeting_url = null);
    }

    public function test_an_event_with_an_unknown_time_zone_is_refused(): void
    {
        $this->assertRefuses('INVALID_FORMAT', '/events/0/timezone', static fn (\stdClass $m) => $m->events[0]->timezone = 'Mars/Olympus');
    }

    public function test_an_organization_wide_event_in_a_private_loop_is_refused(): void
    {
        $this->assertRefuses('REFERENCE_WRONG_SCOPE', '/events/0/visibility', static fn (\stdClass $m) => $m->events[0]->visibility = 'organization');
    }

    public function test_a_decision_superseded_twice_is_refused(): void
    {
        $this->assertRefuses('DUPLICATE_COMPOSITE_KEY', '/decisions/2/supersedes', static function (\stdClass $m): void {
            $m->decisions[1] = clone $m->decisions[0];
            $m->decisions[1]->key = 'decision-review-2';
            $m->decisions[1]->supersedes = 'decision-human-review';
            $m->decisions[1]->decided_day_offset = -3;
            $m->decisions[2] = clone $m->decisions[1];
            $m->decisions[2]->key = 'decision-review-3';
            $m->decisions[2]->decided_day_offset = -1;
        });
    }

    public function test_a_published_ai_profile_without_a_summary_is_refused(): void
    {
        $this->assertRefuses('MISSING_FIELD', '/users/0/member_ai_profile/summary', static fn (\stdClass $m) => $m->users[0]->member_ai_profile->summary = null);
    }

    public function test_a_superadmin_organization_role_is_refused(): void
    {
        $this->assertRefuses('INVALID_ENUM', '/users/0/organization_role', static fn (\stdClass $m) => $m->users[0]->organization_role = 'superadmin');
    }

    public function test_an_email_outside_the_test_domain_is_refused(): void
    {
        $this->assertRefuses('INVALID_FORMAT', '/users/0/email', static fn (\stdClass $m) => $m->users[0]->email = 'nora.martin@gmail.com');
    }

    public function test_an_organization_locale_that_contradicts_the_manifest_locale_is_refused(): void
    {
        $this->assertRefuses('INVALID_ENUM', '/organization/locale', static fn (\stdClass $m) => $m->organization->locale = 'en');
    }

    public function test_a_collection_beyond_its_declared_limit_is_refused(): void
    {
        // La limite V1 est de 20 Boucles (spec 9.2).
        $this->assertRefuses('LIMIT_EXCEEDED', '/loops', static function (\stdClass $m): void {
            for ($index = 0; $index < 20; $index++) {
                $loop = clone $m->loops[1];
                $loop->key = 'filler-'.$index;
                $m->loops[] = $loop;
            }
        });
    }

    public function test_a_string_longer_than_its_limit_is_refused(): void
    {
        $this->assertRefuses('VALUE_TOO_LONG', '/name', static fn (\stdClass $m) => $m->name = str_repeat('a', 121));
    }

    public function test_a_numeric_string_is_not_an_integer(): void
    {
        $this->assertRefuses('INVALID_TYPE', '/messages/0/order', static fn (\stdClass $m) => $m->messages[0]->order = '0');
    }

    public function test_a_numeric_boolean_is_not_a_boolean(): void
    {
        $this->assertRefuses('INVALID_TYPE', '/users/0/available', static fn (\stdClass $m) => $m->users[0]->available = 1);
    }

    public function test_an_offset_outside_its_range_is_refused(): void
    {
        $this->assertRefuses('INVALID_FORMAT', '/messages/0/offset_minutes', static fn (\stdClass $m) => $m->messages[0]->offset_minutes = -525601);
    }

    public function test_an_empty_required_collection_is_refused(): void
    {
        $this->assertRefuses('MISSING_FIELD', '/users', static function (\stdClass $m): void {
            $m->users = [];
        });
    }

    /**
     * @param  callable(\stdClass): void  $mutation
     */
    private function assertRefuses(string $code, string $path, callable $mutation): void
    {
        $result = $this->validator->validate(AmtReferenceManifest::mutate($mutation));

        $this->assertSame(ManifestValidationResult::INVALID, $result->verdict(), $path);

        $matching = array_values(array_filter(
            $result->errors(),
            static fn (object $error): bool => $error->code->value === $code && $error->path === $path,
        ));

        $this->assertCount(1, $matching, sprintf(
            '%s expected exactly once at %s; report was: %s',
            $code,
            $path,
            implode(' | ', array_map(
                static fn (object $error): string => $error->code->value.' '.$error->path,
                $result->errors(),
            )),
        ));
    }
}
