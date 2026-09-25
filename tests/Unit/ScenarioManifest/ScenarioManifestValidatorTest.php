<?php

namespace Tests\Unit\ScenarioManifest;

use App\Support\ScenarioManifest\ManifestValidationResult;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScenarioManifest\AmtReferenceManifest;

/**
 * TASK-1641 — contrat de reference du Validator : l'exemple AMT de la spec, le
 * parsing strict, le digest canonique et le determinisme du rapport.
 *
 * Ce test etend `PHPUnit\Framework\TestCase`, PAS `Tests\TestCase`, et c'est
 * delibere : sans conteneur Laravel, il n'existe ni connexion, ni facade, ni
 * disque, ni client HTTP. Le Validator tourne donc ici dans un environnement
 * ou une ecriture metier ou un appel provider serait IMPOSSIBLE — la preuve la
 * plus forte que "Import != Load" (spec 5.2) tient par construction et non par
 * convention. La preuve complementaire, dans une application bootee, est
 * portee par `ScenarioManifestHasNoSideEffectsTest`.
 */
class ScenarioManifestValidatorTest extends TestCase
{
    private ScenarioManifestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ScenarioManifestValidator;
    }

    public function test_the_amt_example_of_the_specification_is_valid(): void
    {
        $result = $this->validator->validate(AmtReferenceManifest::json());

        $this->assertSame(
            [],
            array_map(
                static fn (array $error): string => $error['code'].' '.$error['path'].' — '.$error['message'],
                $result->toArray()['errors'],
            ),
            'The AMT example carried by the specification must validate without a single error.',
        );

        $this->assertSame(ManifestValidationResult::VALID, $result->verdict());
        $this->assertIsString($result->digest());
    }

    /**
     * Garde anti-derive : la fixture versionnee EST l'exemple de la spec.
     *
     * `TODO/` est gitignore, donc la spec n'existe pas en CI et la fixture doit
     * etre commitee pour que la suite tourne. Ce test empeche la consequence
     * naturelle de cette copie — une spec modifiee sans que la fixture suive —
     * et s'execute la ou cette derive peut naitre : sur un poste qui a la spec.
     */
    public function test_the_committed_fixture_is_byte_identical_to_the_example_in_the_specification(): void
    {
        $specJson = AmtReferenceManifest::specJson();

        if ($specJson === null) {
            $this->markTestSkipped('The specification is not distributed with the repository; there is no copy to compare against here.');
        }

        $this->assertSame(
            $specJson,
            AmtReferenceManifest::json(),
            'The committed AMT fixture has drifted from section 16 of the specification.',
        );
    }

    public function test_the_reference_manifest_reports_every_counter_the_preview_needs(): void
    {
        $counters = $this->validator->validate(AmtReferenceManifest::json())->counters();

        // Les nombres attendus sont ceux de l'exemple AMT, compte a la main
        // sur la spec : deux formateurs et vingt stagiaires, deux Boucles,
        // deux Dossiers racines, un article, deux fichiers, quatre messages.
        $this->assertSame([
            'users' => 22,
            'loops' => 2,
            'memberships' => 44,
            'dossiers' => 2,
            'root_documents' => 2,
            'articles' => 1,
            'files' => 2,
            'messages' => 4,
            'categories' => 1,
            'skills' => 2,
            'service_requests' => 1,
            'services' => 1,
            'polls' => 1,
            'events' => 1,
            'decisions' => 1,
            'roadmap_items' => 1,
            'training_modules' => 2,
            'training_sequences' => 3,
            'training_progress' => 3,
            'training_assignments' => 1,
            'training_submissions' => 2,
        ], array_diff_key($counters, ['declared_objects' => true, 'content_bytes' => true]));

        $this->assertGreaterThan(0, $counters['declared_objects']);
        $this->assertGreaterThan(0, $counters['content_bytes']);
    }

    public function test_two_validations_of_the_same_document_agree_on_verdict_digest_and_errors(): void
    {
        $json = AmtReferenceManifest::json();

        $first = $this->validator->validate($json);
        $second = (new ScenarioManifestValidator)->validate($json);

        $this->assertSame($first->toArray(), $second->toArray());
    }

    public function test_two_validations_of_the_same_invalid_document_agree_on_verdict_digest_and_errors(): void
    {
        // Le determinisme n'a d'interet que s'il tient aussi sur un document
        // FAUTIF : c'est le rapport d'erreurs qu'un producteur externe rejoue.
        $json = AmtReferenceManifest::mutate(static function (\stdClass $manifest): void {
            $manifest->users[3]->email = 'not-an-email';
            $manifest->loops[0]->owner = 'ghost';
            $manifest->messages[1]->order = 0;
            $manifest->unexpected = 'x';
        });

        $first = $this->validator->validate($json);
        $second = (new ScenarioManifestValidator)->validate($json);

        $this->assertSame(ManifestValidationResult::INVALID, $first->verdict());
        $this->assertSame($first->toArray(), $second->toArray());
    }

    public function test_errors_are_sorted_by_path_then_code_then_message(): void
    {
        $result = $this->validator->validate(AmtReferenceManifest::mutate(static function (\stdClass $manifest): void {
            $manifest->users[5]->email = 'nope';
            $manifest->users[1]->email = 'nope-too';
            $manifest->locale = 'de';
            $manifest->version = '1.0';
        }));

        $sortable = array_map(
            static fn (array $error): array => [$error['path'], $error['code'], $error['message']],
            $result->toArray()['errors'],
        );

        $sorted = $sortable;
        usort($sorted, static fn (array $a, array $b): int => $a <=> $b);

        $this->assertSame($sorted, $sortable);
        $this->assertNotSame([], $sortable);
    }

    public function test_the_digest_ignores_property_order_and_whitespace_but_not_array_order(): void
    {
        $reference = $this->validator->validate(AmtReferenceManifest::json())->digest();

        // Meme contenu, proprietes de l'enveloppe reordonnees et document
        // reindente : le digest approuve par un humain porte sur le CONTENU.
        $reordered = AmtReferenceManifest::mutate(static function (\stdClass $manifest): void {
            $members = get_object_vars($manifest);
            $reversed = array_reverse($members, preserve_keys: true);

            foreach (array_keys($members) as $name) {
                unset($manifest->{$name});
            }

            foreach ($reversed as $name => $value) {
                $manifest->{$name} = $value;
            }
        });

        $this->assertSame($reference, $this->validator->validate($reordered)->digest());

        // L'ordre des TABLEAUX reste significatif (spec 5.1).
        $swapped = AmtReferenceManifest::mutate(static function (\stdClass $manifest): void {
            [$manifest->loops[0], $manifest->loops[1]] = [$manifest->loops[1], $manifest->loops[0]];
        });

        $this->assertNotSame($reference, $this->validator->validate($swapped)->digest());
    }

    public function test_the_digest_is_the_sha256_of_the_canonical_json(): void
    {
        $result = $this->validator->validate(AmtReferenceManifest::json());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $result->digest());
    }

    /**
     * Le contrat d'import est strict (spec 15) : aucune des formes ci-dessous
     * n'est un manifeste, et chacune doit le dire au lieu d'etre silencieusement
     * reparee.
     */
    public function test_the_parser_refuses_every_lenient_json_dialect(): void
    {
        $cases = [
            'markdown fence' => "```json\n{\"schema_version\": \"1.0\"}\n```",
            'comment' => '{"schema_version": "1.0" /* pack */}',
            'trailing comma' => '{"schema_version": "1.0",}',
            'not a number' => '{"schema_version": "1.0", "version": NaN}',
            'infinity' => '{"schema_version": "1.0", "version": Infinity}',
            'single quotes' => "{'schema_version': '1.0'}",
            'byte order mark' => "\xEF\xBB\xBF".'{"schema_version": "1.0"}',
            'truncated' => '{"schema_version": "1.0"',
        ];

        foreach ($cases as $label => $json) {
            $result = $this->validator->validate($json);

            $this->assertSame(ManifestValidationResult::INVALID, $result->verdict(), $label);
            $this->assertSame(['INVALID_JSON'], $result->errorCodes(), $label);
            $this->assertNull($result->digest(), $label);
        }
    }

    public function test_a_duplicate_json_member_is_reported_instead_of_being_silently_overwritten(): void
    {
        // `json_decode()` garde la DERNIERE occurrence sans rien dire : deux
        // textes differents produiraient alors le meme digest approuve.
        $json = str_replace(
            '"key": "trainer-1", "first_name": "Nora"',
            '"key": "trainer-1", "key": "trainer-2", "first_name": "Nora"',
            AmtReferenceManifest::json(),
        );

        $result = $this->validator->validate($json);

        $this->assertSame(ManifestValidationResult::INVALID, $result->verdict());
        $this->assertSame(['DUPLICATE_KEY'], $result->errorCodes());
        $this->assertSame('/users/0/key', $result->errors()[0]->path);
    }

    public function test_a_payload_larger_than_two_mebibytes_is_refused_before_parsing(): void
    {
        $result = $this->validator->validate('{"filler": "'.str_repeat('a', 2097152).'"}');

        $this->assertSame(['PAYLOAD_TOO_LARGE'], $result->errorCodes());
    }

    public function test_a_document_nested_deeper_than_twenty_levels_is_refused(): void
    {
        $json = str_repeat('{"a":', 21).'1'.str_repeat('}', 21);

        $this->assertSame(['MAX_DEPTH_EXCEEDED'], $this->validator->validate($json)->errorCodes());
    }

    public function test_a_document_that_is_not_a_json_object_is_refused(): void
    {
        $this->assertSame(['INVALID_TYPE'], $this->validator->validate('[]')->errorCodes());
    }

    public function test_an_invalid_utf8_payload_is_refused(): void
    {
        $this->assertSame(['INVALID_UTF8'], $this->validator->validate("{\"name\": \"\xC3\x28\"}")->errorCodes());
    }

    public function test_an_error_message_never_leaks_a_class_a_path_or_a_query(): void
    {
        // Un message de Validator est affiche a un SuperAdmin et copie-colle a
        // un producteur externe (spec 9.4).
        $result = $this->validator->validate(AmtReferenceManifest::mutate(static function (\stdClass $manifest): void {
            $manifest->users[0]->organization_id = 42;
            $manifest->users[1]->email = 'boom';
            $manifest->loops[0]->type = 'writing';
            $manifest->dossiers[0]->parent = 'help-docs';
        }));

        $this->assertNotSame([], $result->errors());

        foreach ($result->errors() as $error) {
            $this->assertStringNotContainsString('App\\', $error->message);
            $this->assertStringNotContainsString('/home/', $error->message);
            $this->assertStringNotContainsString('.php', $error->message);
            $this->assertStringNotContainsString('select ', strtolower($error->message));
            $this->assertMatchesRegularExpression('#^/#', $error->path);
        }
    }
}
