<?php

namespace Tests\Unit\ScenarioManifest;

use App\Support\ScenarioManifest\ManifestValidationResult;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScenarioManifest\AmtReferenceManifest;

/**
 * TASK-1641 — les mutations HOSTILES de l'exemple AMT.
 *
 * Le critere d'acceptation 12 de la spec demande explicitement "un test de
 * securite [qui] prouve qu'un payload contenant `organization_id`,
 * `target_organization: "main"` ou `tenant: "customer-x"` est rejete avant
 * toute ecriture". Chaque cas part du document VALIDE : le seul changement est
 * la charge hostile, donc le seul responsable du rejet est elle.
 *
 * Ces mutations ne sont pas des variantes de schema. Ce sont les trois choses
 * qu'un producteur — ou une IA mal intentionnee, ou simplement mal informee —
 * pourrait tenter pour sortir du bac a sable : designer un tenant existant,
 * nommer du code ou du stockage, faire executer du contenu chez un membre.
 */
class ScenarioManifestSecurityTest extends TestCase
{
    private ScenarioManifestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ScenarioManifestValidator;
    }

    public function test_an_injected_organization_id_is_refused_as_a_tenant_target(): void
    {
        $this->assertRefuses(
            'TENANT_TARGET_FORBIDDEN',
            '/users/0/organization_id',
            static fn (\stdClass $manifest) => $manifest->users[0]->organization_id = 7,
        );
    }

    public function test_a_target_organization_set_to_main_is_refused(): void
    {
        $this->assertRefuses(
            'TENANT_TARGET_FORBIDDEN',
            '/target_organization',
            static fn (\stdClass $manifest) => $manifest->target_organization = 'main',
        );
    }

    public function test_an_injected_tenant_is_refused(): void
    {
        $this->assertRefuses(
            'TENANT_TARGET_FORBIDDEN',
            '/organization/tenant',
            static fn (\stdClass $manifest) => $manifest->organization->tenant = 'customer-x',
        );
    }

    public function test_a_tenant_target_is_refused_at_any_depth(): void
    {
        // La garde vaut "a toute profondeur" (spec 10.1) : enfouir la
        // propriete dans un objet imbrique ne la rend pas acceptable.
        $this->assertRefuses(
            'TENANT_TARGET_FORBIDDEN',
            '/users/0/member_ai_profile/community_id',
            static fn (\stdClass $manifest) => $manifest->users[0]->member_ai_profile->community_id = 3,
        );
    }

    public function test_a_reserved_slug_proposal_is_refused_as_a_tenant_target(): void
    {
        foreach (['main', 'admin', 'production', 'develop'] as $slug) {
            $result = $this->validator->validate(AmtReferenceManifest::mutate(
                static fn (\stdClass $manifest) => $manifest->organization->proposed_slug = $slug,
            ));

            $this->assertContains('TENANT_TARGET_FORBIDDEN', $result->errorCodes(), $slug);
            $this->assertSame('/organization/proposed_slug', $result->errors()[0]->path, $slug);
        }
    }

    public function test_naming_code_storage_or_a_credential_is_refused(): void
    {
        $forbidden = [
            'class' => 'App\\Support\\ScenarioPacks\\Packs\\AiLabPack',
            'table' => 'users',
            'path' => '/etc/passwd',
            'disk' => 'public',
            'command' => 'scenario-pack:load',
            'provider' => 'openai',
            'api_key' => 'sk-live-000',
            'secret' => 'hunter2',
            'token' => 'bearer-000',
            'uuid' => '00000000-0000-0000-0000-000000000000',
            'connection' => 'pgsql',
            'callback' => 'handle',
        ];

        foreach ($forbidden as $property => $value) {
            $this->assertRefuses(
                'FORBIDDEN_PROPERTY',
                '/loops/0/'.$property,
                static fn (\stdClass $manifest) => $manifest->loops[0]->{$property} = $value,
            );
        }
    }

    public function test_a_nested_id_is_refused_while_the_root_id_remains_the_pack_key(): void
    {
        // `id` est la stable key du pack a la racine, et une porte vers un
        // identifiant DB partout ailleurs (spec 10.1).
        $this->assertRefuses(
            'FORBIDDEN_PROPERTY',
            '/users/2/id',
            static fn (\stdClass $manifest) => $manifest->users[2]->id = 1234,
        );

        $this->assertSame(
            ManifestValidationResult::VALID,
            $this->validator->validate(AmtReferenceManifest::json())->verdict(),
        );
    }

    public function test_an_excluded_object_is_refused_whatever_its_spelling(): void
    {
        $spellings = ['quizzes', 'point_ledger', 'pointLedger', 'Transactions', 'badges', 'reactions', 'ai_interactions', 'dossier_chunks', 'scenario_pack_loads'];

        foreach ($spellings as $property) {
            $this->assertRefuses(
                'FORBIDDEN_OBJECT',
                '/'.$property,
                static fn (\stdClass $manifest) => $manifest->{$property} = [],
            );
        }
    }

    public function test_a_course_quiz_declared_inside_training_is_refused(): void
    {
        // CourseQuiz est reporte a Manifest V1.1 (spec 12.6).
        $this->assertRefuses(
            'FORBIDDEN_OBJECT',
            '/training/quizzes',
            static fn (\stdClass $manifest) => $manifest->training->quizzes = [],
        );
    }

    public function test_a_file_name_that_escapes_its_directory_is_refused(): void
    {
        foreach (['../guide-prompt.md', 'notes/guide.md', '..\\guide.md', 'guide.php'] as $name) {
            $result = $this->validator->validate(AmtReferenceManifest::mutate(
                static fn (\stdClass $manifest) => $manifest->files[0]->name = $name,
            ));

            $this->assertContains('INVALID_FORMAT', $result->errorCodes(), $name);
            $this->assertSame(ManifestValidationResult::INVALID, $result->verdict(), $name);
        }
    }

    public function test_dangerous_html_in_a_document_is_refused(): void
    {
        $payloads = [
            '<script>fetch("https://evil.test")</script>',
            '<p onclick="steal()">Bonjour</p>',
            '<iframe src="https://evil.test"></iframe>',
            '<img src="x" onerror="steal()">',
            '<svg onload="steal()"></svg>',
            '<a href="javascript:steal()">cliquer</a>',
            '<a href="data:text/html,<script>x</script>">cliquer</a>',
            '<p style="background:url(https://evil.test)">x</p>',
        ];

        foreach ($payloads as $payload) {
            $result = $this->validator->validate(AmtReferenceManifest::mutate(
                static function (\stdClass $manifest) use ($payload): void {
                    $manifest->articles[0]->format = 'html';
                    $manifest->articles[0]->content = $payload;
                },
            ));

            $this->assertSame(['UNSAFE_CONTENT'], $result->errorCodes(), $payload);
            $this->assertSame('/articles/0/content', $result->errors()[0]->path, $payload);
        }
    }

    public function test_dangerous_markdown_in_a_message_is_refused(): void
    {
        $payloads = [
            '![pixel](https://evil.test/track.png)',
            '[cliquer](javascript:steal())',
            'Texte puis <script>steal()</script>',
            '<https://evil.test> reste permis mais <javascript:steal()> non',
        ];

        foreach ($payloads as $payload) {
            $result = $this->validator->validate(AmtReferenceManifest::mutate(
                static function (\stdClass $manifest) use ($payload): void {
                    $manifest->messages[0]->format = 'markdown';
                    $manifest->messages[0]->body = $payload;
                },
            ));

            $this->assertSame(['UNSAFE_CONTENT'], $result->errorCodes(), $payload);
            $this->assertSame('/messages/0/body', $result->errors()[0]->path, $payload);
        }
    }

    /**
     * Regression TASK-1641 (revue Sonnet sur e8f48562) : les images Markdown
     * ne s'ecrivent pas seulement `![alt](url)`.
     *
     * Les trois formes de REFERENCE tirent leur URL d'une definition `[ref]:
     * <url>` qui peut se trouver n'importe ou dans le document. La version
     * precedente ne cherchait que la forme inline et laissait donc passer
     * trois images sur quatre : la definition ressemble a un lien legitime, et
     * c'est le `!` seul qui transforme la reference en requete sortante depuis
     * le navigateur d'un membre.
     */
    public function test_every_commonmark_image_syntax_is_refused(): void
    {
        $images = [
            'inline' => '![alt](https://evil.test/track.png)',
            'full reference' => "![alt][pixel]\n\n[pixel]: https://evil.test/track.png",
            'collapsed reference' => "![alt][]\n\n[alt]: https://evil.test/track.png",
            'shortcut reference' => "![alt]\n\n[alt]: https://evil.test/track.png",
        ];

        foreach ($images as $label => $payload) {
            $result = $this->validator->validate(AmtReferenceManifest::mutate(
                static function (\stdClass $manifest) use ($payload): void {
                    $manifest->messages[0]->format = 'markdown';
                    $manifest->messages[0]->body = $payload;
                },
            ));

            $this->assertSame(ManifestValidationResult::INVALID, $result->verdict(), $label);
            $this->assertSame(['UNSAFE_CONTENT'], $result->errorCodes(), $label);
            $this->assertSame('/messages/0/body', $result->errors()[0]->path, $label);
        }
    }

    /**
     * La regle vit dans la primitive de contenu PARTAGEE : elle doit donc
     * valoir sur chaque surface Markdown du langage, pas seulement sur celle
     * qu'un test a choisie. Une regle de securite qui ne tiendrait que sur
     * `messages` laisserait un article ou un fichier de cours porter la meme
     * image.
     */
    public function test_a_reference_image_is_refused_on_every_markdown_surface(): void
    {
        $payload = "![alt][pixel]\n\n[pixel]: https://evil.test/track.png";

        $surfaces = [
            '/articles/0/content' => static function (\stdClass $manifest) use ($payload): void {
                $manifest->articles[0]->format = 'markdown';
                $manifest->articles[0]->content = $payload;
            },
            '/files/0/content' => static function (\stdClass $manifest) use ($payload): void {
                $manifest->files[0]->content = $payload;
            },
            '/dossiers/0/root_document/content' => static function (\stdClass $manifest) use ($payload): void {
                $manifest->dossiers[0]->root_document->content = $payload;
            },
            '/training/submissions/0/body' => static function (\stdClass $manifest) use ($payload): void {
                $manifest->training->submissions[0]->body = $payload;
            },
        ];

        foreach ($surfaces as $path => $mutation) {
            $result = $this->validator->validate(AmtReferenceManifest::mutate($mutation));

            $this->assertSame(ManifestValidationResult::INVALID, $result->verdict(), $path);
            $this->assertSame(['UNSAFE_CONTENT'], $result->errorCodes(), $path);
            $this->assertSame($path, $result->errors()[0]->path, $path);
        }
    }

    /**
     * Contrepartie indispensable : un lien de REFERENCE est legitime et doit
     * rester vert. Sans ce test, refuser tout `[` serait une facon triviale de
     * rendre le test precedent vert.
     */
    public function test_reference_style_links_remain_valid(): void
    {
        $result = $this->validator->validate(AmtReferenceManifest::mutate(
            static function (\stdClass $manifest): void {
                $manifest->messages[0]->format = 'markdown';
                $manifest->messages[0]->body = "Voir [le guide][guide] et [la charte][charte].\n\n[guide]: https://example.test/guide\n[charte]: https://example.test/charte";
            },
        ));

        $this->assertSame(ManifestValidationResult::VALID, $result->verdict());
    }

    public function test_safe_markdown_links_remain_valid(): void
    {
        // La contrepartie du test precedent : la garde ne doit pas refuser du
        // contenu legitime, sans quoi un producteur apprendrait a la contourner.
        $result = $this->validator->validate(AmtReferenceManifest::mutate(
            static function (\stdClass $manifest): void {
                $manifest->messages[0]->format = 'markdown';
                $manifest->messages[0]->body = 'Voir <https://example.test/guide> ou [le guide](https://example.test/guide), ecrire a [Nora](mailto:nora@amt-demo.test).';
            },
        ));

        $this->assertSame(ManifestValidationResult::VALID, $result->verdict());
    }

    public function test_an_avatar_absent_from_the_local_bank_is_refused_without_any_network_call(): void
    {
        $this->assertRefuses(
            'AVATAR_NOT_FOUND',
            '/users/0/avatar',
            static fn (\stdClass $manifest) => $manifest->users[0]->avatar = 'female-99',
        );
    }

    /**
     * @param  callable(\stdClass): void  $mutation
     */
    private function assertRefuses(string $code, string $path, callable $mutation): void
    {
        $result = $this->validator->validate(AmtReferenceManifest::mutate($mutation));

        $this->assertSame(ManifestValidationResult::INVALID, $result->verdict(), $path);
        $this->assertContains($code, $result->errorCodes(), sprintf(
            '%s expected at %s, got: %s',
            $code,
            $path,
            implode(', ', $result->errorCodes()),
        ));

        $matching = array_values(array_filter(
            $result->errors(),
            static fn (object $error): bool => $error->code->value === $code && $error->path === $path,
        ));

        $this->assertCount(1, $matching, sprintf('Exactly one %s expected at %s.', $code, $path));
    }
}
