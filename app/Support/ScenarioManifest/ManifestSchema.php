<?php

namespace App\Support\ScenarioManifest;

/**
 * Le catalogue d'objets de la spec (sections 6, 7 et 12), traduit en DONNEES.
 *
 * C'est volontairement une structure declarative et non du code de validation :
 * le critere d'acceptation 14 de la spec demande qu'un developpeur puisse
 * implementer le Validator "sans choisir de nouveaux noms, enums, limites,
 * relations ou regles de nullabilite". Un champ, son type, sa nullabilite et
 * sa borne se lisent donc ICI, a un seul endroit, en face du tableau normatif
 * correspondant — et non disperses dans des `if` a travers plusieurs phases.
 *
 * Toute propriete absente de ce catalogue est un champ INCONNU : la spec 4.2
 * impose une allowlist, jamais une tolerance.
 */
final class ManifestSchema
{
    public const SCHEMA_VERSION = '1.0';

    public const AVATAR_BANK = 'fictional-avatars-v1';

    /** Bornes de `offset_minutes`, spec 6.3. */
    public const MIN_OFFSET_MINUTES = -525600;

    public const MAX_OFFSET_MINUTES = 525600;

    /** Bornes de `day_offset`, spec 6.3. */
    public const MIN_DAY_OFFSET = -365;

    public const MAX_DAY_OFFSET = 365;

    /** Total d'objets declares, enfants inclus, spec 9.2. */
    public const MAX_OBJECTS = 10000;

    /** Somme des contenus root/article/file/message, spec 9.2 : 1 MiB. */
    public const MAX_CONTENT_BYTES = 1048576;

    /** Profondeur maximale du graphe de Dossiers, spec 7.4. */
    public const MAX_DOSSIER_DEPTH = 10;

    /**
     * Slugs qu'une sandbox ne peut jamais proposer (spec 10.2). Les proposer
     * n'est pas une faute de forme mais une tentative de designer une cible
     * existante : le code retourne est donc TENANT_TARGET_FORBIDDEN.
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = ['main', 'admin', 'api', 'app', 'www', 'prod', 'production', 'develop'];

    /**
     * Enveloppe complete du document (spec 6.1). Toutes les proprietes sont
     * obligatoires ; les collections vides valent `[]`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function envelope(): array
    {
        return [
            'schema_version' => ['type' => 'const', 'value' => self::SCHEMA_VERSION, 'code' => ManifestErrorCode::UNKNOWN_SCHEMA_VERSION],
            'id' => ['type' => 'stable_key'],
            'version' => ['type' => 'semver'],
            'name' => self::line(1, 120),
            'description' => self::text(1, 2000),
            'purpose' => self::text(1, 500),
            'locale' => self::enum(['fr', 'en']),
            'assets' => self::object([
                'avatar_bank' => ['type' => 'const', 'value' => self::AVATAR_BANK, 'code' => ManifestErrorCode::INVALID_ENUM],
            ]),
            'organization' => self::object([
                'name' => self::line(1, 120),
                'proposed_slug' => ['type' => 'slug', 'min' => 3, 'max' => 64],
                'description' => self::text(1, 2000),
                'locale' => self::enum(['fr', 'en']),
            ]),
            'users' => self::collection(100, self::userFields(), 'key'),
            'loops' => self::collection(20, self::loopFields(), 'key'),
            'memberships' => self::collection(500, [
                'loop' => self::ref('loops'),
                'user' => self::ref('users'),
                'role' => self::enum(['owner', 'facilitator', 'member']),
            ]),
            'dossiers' => self::collection(100, self::dossierFields(), 'key'),
            'articles' => self::collection(200, self::articleFields(), 'key'),
            'files' => self::collection(200, self::fileFields(), 'key'),
            'messages' => self::collection(1000, self::messageFields(), 'key'),
            'categories' => self::collection(50, [
                'key' => ['type' => 'stable_key'],
                'name' => self::line(1, 120),
                'color' => ['type' => 'color'],
            ], 'key'),
            'skills' => self::collection(200, [
                'key' => ['type' => 'stable_key'],
                'category' => self::ref('categories'),
                'name' => self::line(1, 120),
            ], 'key'),
            'service_requests' => self::collection(100, self::serviceRequestFields(), 'key'),
            'services' => self::collection(100, self::serviceFields(), 'key'),
            'polls' => self::collection(100, self::pollFields(), 'key'),
            'events' => self::collection(100, self::eventFields(), 'key'),
            'decisions' => self::collection(100, self::decisionFields(), 'key'),
            'roadmap_items' => self::collection(200, self::roadmapFields(), 'key'),
            'training' => self::object([
                'modules' => self::collection(100, self::moduleFields(), 'key'),
                'sequences' => self::collection(500, self::sequenceFields(), 'key'),
                'progress' => self::collection(5000, self::progressFields()),
                'assignments' => self::collection(200, self::assignmentFields(), 'key'),
                'submissions' => self::collection(5000, self::submissionFields()),
            ]),
        ];
    }

    /**
     * Chemins des collections adressables par une reference, en JSON Pointer
     * relatif a la racine. Sert a indexer les stable keys et a citer la
     * collection attendue dans un message d'erreur.
     *
     * @return array<string, string>
     */
    public static function referencableCollections(): array
    {
        return [
            'users' => '/users',
            'loops' => '/loops',
            'dossiers' => '/dossiers',
            'articles' => '/articles',
            'files' => '/files',
            'messages' => '/messages',
            'categories' => '/categories',
            'skills' => '/skills',
            'service_requests' => '/service_requests',
            'services' => '/services',
            'polls' => '/polls',
            'events' => '/events',
            'decisions' => '/decisions',
            'roadmap_items' => '/roadmap_items',
            'training.modules' => '/training/modules',
            'training.sequences' => '/training/sequences',
            'training.assignments' => '/training/assignments',
        ];
    }

    /**
     * Collections qu'aucune reference ne peut viser : leur identite est une
     * cle COMPOSEE (spec 8.1), pas une stable key. Elles sont malgre tout
     * indexees, parce que les invariants relationnels les parcourent.
     *
     * @return array<string, string>
     */
    public static function keylessCollections(): array
    {
        return [
            'memberships' => '/memberships',
            'training.progress' => '/training/progress',
            'training.submissions' => '/training/submissions',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function userFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'first_name' => self::line(1, 80),
            'name' => self::line(1, 120),
            'email' => ['type' => 'email'],
            'bio' => self::text(0, 2000, nullable: true),
            'available' => ['type' => 'bool'],
            'location' => self::line(1, 160, nullable: true),
            'avatar' => ['type' => 'avatar', 'nullable' => true],
            'organization_role' => self::enum(['admin', 'member']),
            'member_ai_profile' => self::object([
                'status' => self::enum(['draft', 'published']),
                'summary' => self::text(0, 2000, nullable: true),
                'service_scope' => self::text(0, 2000, nullable: true),
                'experience_context' => self::text(0, 2000, nullable: true),
                'target_audience' => self::stringList(20, 1, 160),
                'problems_helped' => self::stringList(20, 1, 160),
                'skills' => self::stringList(30, 1, 120),
                'help_types' => self::stringList(20, 1, 120),
                'boundaries' => self::stringList(20, 1, 200),
                'preferred_contact_action' => self::enum(['message', 'service_request'], nullable: true),
                'tone' => self::enum(['warm', 'direct', 'pedagogical'], nullable: true),
            ], nullable: true),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loopFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'name' => self::line(1, 120),
            'description' => self::text(1, 2000),
            // `writing`, `networking`, `peer_support`, `ai_agent` et tout type
            // custom sont invalides en V1 (spec 7.3), meme s'ils existent
            // ailleurs dans le produit.
            'type' => self::enum(['general', 'project', 'coaching', 'training']),
            'owner' => self::ref('users'),
            'visibility' => self::enum(['private', 'public']),
            'access_mode' => self::enum(['open', 'request', 'invitation']),
            'root_dossier' => self::ref('dossiers'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function dossierFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'name' => self::line(1, 160),
            'owner' => self::ref('users'),
            'loop' => self::ref('loops', nullable: true),
            'parent' => self::ref('dossiers', nullable: true),
            'visibility' => self::enum(['private', 'organization', 'loop']),
            'root_document' => self::object([
                'title' => self::line(1, 200),
                'author' => self::ref('users'),
                'format' => self::enum(['markdown', 'html']),
                'content' => self::content(1, 100000, 'format'),
            ], nullable: true),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function articleFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'dossier' => self::ref('dossiers'),
            'author' => self::ref('users'),
            'title' => self::line(1, 200),
            'summary' => self::text(0, 500, nullable: true),
            'status' => self::enum(['draft', 'published']),
            'audience' => self::enum(['organization', 'loop']),
            'format' => self::enum(['markdown', 'html']),
            'content' => self::content(1, 100000, 'format'),
            'published_offset_minutes' => self::offset(nullable: true),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function fileFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'dossier' => self::ref('dossiers'),
            'uploaded_by' => self::ref('users'),
            // Basename seul : le loader derive lui-meme disque, chemin, taille
            // et SHA-256 (spec 7.4). Un `/`, un `\` ou un `..` serait une
            // tentative de designer un emplacement de stockage.
            'name' => ['type' => 'filename', 'min' => 1, 'max' => 160],
            'media_type' => self::enum(['text/markdown', 'text/html']),
            'content' => self::content(1, 100000, 'media_type'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function messageFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            // Le vocabulaire public est `messages` / `loop_message` ; le vieux
            // `interaction` des Seeders n'appartient pas au langage (spec 7.5).
            'type' => ['type' => 'const', 'value' => 'loop_message', 'code' => ManifestErrorCode::INVALID_ENUM],
            'loop' => self::ref('loops'),
            'author' => self::ref('users'),
            'body' => self::content(1, 10000, 'format'),
            'format' => self::enum(['plain', 'markdown']),
            'order' => ['type' => 'int', 'min' => 0, 'max' => 9999],
            'offset_minutes' => self::offset(),
            'reply_to' => self::ref('messages', nullable: true),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function serviceRequestFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'author' => self::ref('users'),
            'title' => self::line(1, 255),
            'description' => self::text(1, 5000),
            'category' => self::ref('categories'),
            'delivery_mode' => self::enum(['remote', 'onsite', 'both']),
            'budget_min' => ['type' => 'int', 'min' => 0, 'max' => 1000000],
            'budget_max' => ['type' => 'int', 'min' => 0, 'max' => 1000000, 'nullable' => true],
            'deadline_day_offset' => self::dayOffset(nullable: true),
            'status' => self::enum(['open', 'in_progress', 'closed']),
            'highlight_in_loop' => self::ref('loops', nullable: true),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function serviceFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'author' => self::ref('users'),
            'title' => self::line(1, 255),
            'description' => self::text(1, 5000),
            'category' => self::ref('categories'),
            'skills' => ['type' => 'array', 'max' => 20, 'of' => self::ref('skills')],
            'delivery_mode' => self::enum(['remote', 'onsite', 'both']),
            'points_cost' => ['type' => 'int', 'min' => 0, 'max' => 1000000],
            'status' => self::enum(['active', 'paused']),
            'highlight_in_loop' => self::ref('loops', nullable: true),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function pollFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'loop' => self::ref('loops'),
            'author' => self::ref('users'),
            'question' => self::line(1, 500),
            'description' => self::text(0, 2000, nullable: true),
            'selection_type' => self::enum(['single', 'multiple']),
            'status' => self::enum(['open', 'closed']),
            'options' => ['type' => 'array', 'min' => 2, 'max' => 10, 'key_field' => 'key', 'of' => self::object([
                'key' => ['type' => 'stable_key'],
                'label' => self::line(1),
            ])],
            'votes' => ['type' => 'array', 'max' => 100, 'of' => self::object([
                'user' => self::ref('users'),
                'options' => ['type' => 'array', 'min' => 1, 'max' => 10, 'of' => ['type' => 'stable_key']],
            ])],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function eventFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'loop' => self::ref('loops'),
            'author' => self::ref('users'),
            'title' => self::line(1, 255),
            'description' => self::text(0, 5000, nullable: true),
            'format' => self::enum(['in_person', 'online', 'hybrid']),
            'starts_offset_minutes' => self::offset(),
            'duration_minutes' => ['type' => 'int', 'min' => 15, 'max' => 1440],
            'timezone' => ['type' => 'timezone', 'max' => 64],
            'location' => self::line(1, nullable: true),
            // Affichee comme lien, JAMAIS visitee pendant Validate ou Load
            // (spec 7.7) : le Validator ne fait aucun appel reseau.
            'meeting_url' => ['type' => 'url', 'max' => 2048, 'nullable' => true],
            'visibility' => self::enum(['loop', 'organization']),
            'status' => self::enum(['scheduled', 'cancelled']),
            'responses' => ['type' => 'array', 'max' => 100, 'of' => self::object([
                'user' => self::ref('users'),
                'response' => self::enum(['going', 'maybe', 'not_going']),
            ])],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function decisionFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'loop' => self::ref('loops'),
            'author' => self::ref('users'),
            'title' => self::line(1, 255),
            'rationale' => self::text(0, 5000, nullable: true),
            'decided_day_offset' => ['type' => 'int', 'min' => self::MIN_DAY_OFFSET, 'max' => 0],
            'message' => self::ref('messages', nullable: true),
            'supersedes' => self::ref('decisions', nullable: true),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function roadmapFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'loop' => self::ref('loops'),
            'created_by' => self::ref('users'),
            'title' => self::line(1, 255),
            'description' => self::text(0, 5000, nullable: true),
            'status' => self::enum(['todo', 'in_progress', 'done']),
            'position' => ['type' => 'int', 'min' => 0, 'max' => 9999],
            'assignees' => ['type' => 'array', 'max' => 3, 'of' => self::ref('users')],
            'due_day_offset' => self::dayOffset(nullable: true),
            'decision' => self::ref('decisions', nullable: true),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function moduleFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'loop' => self::ref('loops'),
            'title' => self::line(1, 255),
            'summary' => self::text(0, 2000, nullable: true),
            'position' => ['type' => 'int', 'min' => 0, 'max' => 9999],
            'created_by' => self::ref('users'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function sequenceFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'module' => self::ref('training.modules'),
            'title' => self::line(1, 255),
            'position' => ['type' => 'int', 'min' => 0, 'max' => 9999],
            'requires_validation' => ['type' => 'bool'],
            'created_by' => self::ref('users'),
            // Exactement UNE variante (spec 12.2) : la forme depend de `type`,
            // elle est donc resolue par le validateur de forme.
            'content' => ['type' => 'sequence_content'],
        ];
    }

    /**
     * Les trois variantes de `sequences[].content`. Chaque variante est une
     * allowlist fermee : `{type, <champ de la variante>}`, rien d'autre.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function sequenceContentVariants(): array
    {
        return [
            'text' => ['body' => self::text(1, 100000)],
            'article' => ['article' => self::ref('articles')],
            'file' => ['file' => self::ref('files')],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function progressFields(): array
    {
        return [
            'sequence' => self::ref('training.sequences'),
            'user' => self::ref('users'),
            // `available` / `unavailable` sont des CONCLUSIONS calculees par le
            // produit, jamais des etats stockes (spec 12.3) : ils ne sont pas
            // declarables.
            'status' => self::enum(['in_progress', 'submitted', 'completed', 'validated', 'redo']),
            'started_offset_minutes' => self::offset(nullable: true),
            'completed_offset_minutes' => self::offset(nullable: true),
            'validated_by' => self::ref('users', nullable: true),
            'validated_offset_minutes' => self::offset(nullable: true),
            'unlocked_by' => self::ref('users', nullable: true),
            'unlocked_offset_minutes' => self::offset(nullable: true),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function assignmentFields(): array
    {
        return [
            'key' => ['type' => 'stable_key'],
            'loop' => self::ref('loops'),
            'sequence' => self::ref('training.sequences', nullable: true),
            'title' => self::line(1, 255),
            'brief' => self::text(0, 5000, nullable: true),
            'due_offset_minutes' => self::offset(nullable: true),
            'position' => ['type' => 'int', 'min' => 0, 'max' => 9999],
            'created_by' => self::ref('users'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function submissionFields(): array
    {
        return [
            'assignment' => self::ref('training.assignments'),
            'user' => self::ref('users'),
            'body' => self::content(0, 20000, null, nullable: true),
            'file' => self::ref('files', nullable: true),
            'status' => self::enum(['draft', 'submitted', 'validated', 'redo']),
            'submitted_offset_minutes' => self::offset(nullable: true),
            'feedback' => self::text(0, 5000, nullable: true),
            'reviewed_by' => self::ref('users', nullable: true),
            'reviewed_offset_minutes' => self::offset(nullable: true),
        ];
    }

    // =====================================================================
    // Constructeurs de specs. Ils n'ajoutent aucune regle : ils nomment
    // celles de la spec pour que le catalogue ci-dessus reste lisible en face
    // de ses tableaux normatifs.
    // =====================================================================

    /**
     * Texte d'une seule ligne : aucun caractere de controle, pas meme un saut
     * de ligne (spec 6.2, "texte court ... sans caractere de controle").
     *
     * @return array<string, mixed>
     */
    private static function line(int $min, ?int $max = null, bool $nullable = false): array
    {
        return array_filter([
            'type' => 'string',
            'min' => $min,
            'max' => $max,
            'nullable' => $nullable,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Texte long : les sauts de ligne et tabulations y sont legitimes, les
     * autres caracteres de controle restent interdits.
     *
     * @return array<string, mixed>
     */
    private static function text(int $min, int $max, bool $nullable = false): array
    {
        return ['type' => 'string', 'multiline' => true, 'min' => $min, 'max' => $max, 'nullable' => $nullable];
    }

    /**
     * Texte long SANITIZABLE. `$formatField` nomme la propriete voisine qui
     * porte le format ; `null` signifie Markdown, seule forme admise pour le
     * champ concerne.
     *
     * @return array<string, mixed>
     */
    private static function content(int $min, int $max, ?string $formatField, bool $nullable = false): array
    {
        return [
            'type' => 'string',
            'multiline' => true,
            'min' => $min,
            'max' => $max,
            'nullable' => $nullable,
            'sanitize_format_field' => $formatField,
        ];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private static function enum(array $values, bool $nullable = false): array
    {
        return ['type' => 'enum', 'values' => $values, 'nullable' => $nullable];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ref(string $collection, bool $nullable = false): array
    {
        return ['type' => 'ref', 'collection' => $collection, 'nullable' => $nullable];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private static function object(array $fields, bool $nullable = false): array
    {
        return ['type' => 'object', 'fields' => $fields, 'nullable' => $nullable];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private static function collection(int $max, array $fields, ?string $keyField = null): array
    {
        return array_filter([
            'type' => 'array',
            'max' => $max,
            'key_field' => $keyField,
            'of' => self::object($fields),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringList(int $maxItems, int $minLength, int $maxLength): array
    {
        return ['type' => 'array', 'max' => $maxItems, 'of' => self::line($minLength, $maxLength)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function offset(bool $nullable = false): array
    {
        return ['type' => 'int', 'min' => self::MIN_OFFSET_MINUTES, 'max' => self::MAX_OFFSET_MINUTES, 'nullable' => $nullable];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dayOffset(bool $nullable = false): array
    {
        return ['type' => 'int', 'min' => self::MIN_DAY_OFFSET, 'max' => self::MAX_DAY_OFFSET, 'nullable' => $nullable];
    }
}
