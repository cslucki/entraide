<?php

namespace App\Support\ScenarioPacks\Packs;

use App\Models\BlogPost;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Dossiers\FileContentExtractor;
use App\Services\Knowledge\DerivedKnowledgeNoteIndexer;
use App\Services\LoopMessageService;
use App\Services\Loops\LoopRootDocumentService;
use App\Services\LoopService;
use App\Support\ScenarioPacks\Contracts\ProvisionsItsOrganization;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOrganizationNotAdoptableException;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/**
 * TASK-1587 / CDC-03 L-A — « le Lab existe ».
 *
 * L'Organization **AI Lab** (`ai-lab`) : le terrain de golds CONTROLES de la
 * campagne Nervous System. Le Lab ne cree pas un second produit — il cree des
 * donnees et des attentes, et le vrai produit s'execute dessus (CDC-03 §2).
 *
 * Forme : EXTEND du moteur de scenario packs (K1) — `ScenarioPackDefinition` +
 * `ProvisionsItsOrganization`, sur le modele exact d'`ArtSciLabEnglishPack` ;
 * idempotence par cle naturelle (`updateOrCreate` / lookup avant service),
 * ownership/reset/suppression par le registre. Aucun seeder parallele, aucun
 * `dossier_chunks` insere a la main.
 *
 * Contenu (CDC-03 §4) :
 *   utilisateurs  lab.admin (admin d'org), lab.member.a, lab.member.b,
 *                 lab.member.c (membre de L1 SEULEMENT — test ACL Loop) ;
 *   Loops         L1 Faits simples · L2 Personnes & suites ·
 *                 L3 Documents structures · L4 Near miss / abstention ;
 *   Dossiers      le Dossier racine de chaque Loop ; corpus synthetique
 *                 VERSIONNE sous `database/scenario-packs/ai-lab/corpus/`
 *                 (MD, DOCX a tableau, PDF, XLSX — < 200 Ko), importe par le
 *                 chemin REEL (DossierFile -> observer -> job -> indexer) ;
 *   messages      ≥ 30, racines / replies explicites / suites sans reply /
 *                 changement de sujet / decisions, + UNE reponse `type = ai`
 *                 seedee (hypothese §4.5, verifiee par test).
 *
 * `lab.outsider` (K5, sentinelle cross-tenant) n'est PAS une entite de ce
 * pack : le registre refuse — a raison — toute entite d'une autre
 * Organization. Le pack en DECLARE le contrat (`OUTSIDER_EMAIL`) ; les tests le
 * creent dans une autre Organization, le banc utilise SENTINEL-B.
 *
 * Ce que le pack n'ecrit JAMAIS : une cle API (l'`OrganizationAiSetting` est
 * posee SANS credential — l'operateur la colle), un secret, un `dossier_chunk`.
 */
final class AiLabPack implements ProvisionsItsOrganization, ScenarioPackDefinition
{
    public const PACK_ID = 'ai-lab';

    public const ORGANIZATION_SLUG = 'ai-lab';

    public const ORGANIZATION_NAME = 'AI Lab';

    public const DISK = 'dossier_files';

    public const SOURCE_CONFIG_KEY = 'scenario_packs.sources.'.self::PACK_ID;

    public const EMAIL_DOMAIN = 'ai-lab.test';

    /** Contrat declare, jamais une entite du pack (voir docblock de classe). */
    public const OUTSIDER_EMAIL = 'lab.outsider@'.self::EMAIL_DOMAIN;

    /** @var array<string, array{first_name: string, name: string, bio: string, is_admin: bool}> */
    public const PERSONAS = [
        'lab.admin' => ['first_name' => 'Nadia', 'name' => 'Ferreira', 'bio' => 'Administratrice de l\'AI Lab, responsable du projet Helios.', 'is_admin' => false],
        'lab.member.a' => ['first_name' => 'Alice', 'name' => 'Martin', 'bio' => 'Coordination des partenaires exterieurs.', 'is_admin' => false],
        'lab.member.b' => ['first_name' => 'Bob', 'name' => 'Okafor', 'bio' => 'Integration technique des capteurs.', 'is_admin' => false],
        'lab.member.c' => ['first_name' => 'Chloe', 'name' => 'Duval', 'bio' => 'Nouvelle recrue, documentation des procedures.', 'is_admin' => false],
    ];

    /** @var array<string, array{name: string, tagline: string, description: string, owner: string, members: list<string>}> */
    public const LOOPS = [
        'L1' => ['name' => 'L1 — Faits simples', 'tagline' => 'Cinq faits controles sur le projet Helios.', 'description' => 'Prose courte : projet, responsable, date, budget, prochaine reunion.', 'owner' => 'lab.admin', 'members' => ['lab.member.a', 'lab.member.b', 'lab.member.c']],
        'L2' => ['name' => 'L2 — Personnes & suites', 'tagline' => 'Qui est qui, et qui fait quoi ensuite.', 'description' => 'Presentations, pronoms, references : Alice presente Bob, Bob explique son role, un tiers parle d\'Alice.', 'owner' => 'lab.admin', 'members' => ['lab.member.a', 'lab.member.b']],
        'L3' => ['name' => 'L3 — Documents structures', 'tagline' => 'Un tableau, une charte, un budget.', 'description' => 'DOCX a tableau Organization / Team Lead / Role / Expertise, PDF texte, XLSX simple, Markdown ; un fait volontairement absent.', 'owner' => 'lab.admin', 'members' => ['lab.member.a', 'lab.member.b']],
        'L4' => ['name' => 'L4 — Near miss / abstention', 'tagline' => 'Un programme Apollo sans aucune donnee NASA.', 'description' => 'Leurre lexical : la saison culturelle de la salle Apollo.', 'owner' => 'lab.admin', 'members' => ['lab.member.a', 'lab.member.b']],
    ];

    /**
     * Le corpus DECLARE (la declaration fait foi, pas le disque) : un fichier
     * declare absent fait echouer le chargement. Nom = fichier sous
     * `<source>/<loop>/`.
     *
     * @var array<string, list<string>>
     */
    public const CORPUS = [
        'L1' => ['faits-simples.md'],
        'L2' => ['personnes-et-roles.md'],
        'L3' => ['equipes.docx', 'charte.pdf', 'budget.xlsx', 'notes-structurees.md'],
        'L4' => ['programme-apollo-local.md'],
    ];

    public function __construct(
        private readonly DerivedKnowledgeNoteIndexer $derivedIndex,
        private readonly LoopService $loops,
        private readonly LoopRootDocumentService $rootDocuments,
        private readonly LoopMessageService $messages,
    ) {}

    public function packId(): string
    {
        return self::PACK_ID;
    }

    public function packVersion(): string
    {
        return '1.0.0';
    }

    public function packName(): string
    {
        return self::ORGANIZATION_NAME;
    }

    public function purpose(): string
    {
        return 'Terrain de golds controles de la campagne Nervous System : quatre Loops, un corpus synthetique versionne, une conversation seedee — le vrai produit s\'execute dessus (CDC-03).';
    }

    public function organizationSlug(): string
    {
        return self::ORGANIZATION_SLUG;
    }

    public function provisionOrganization(): Organization
    {
        return Organization::create([
            'name' => self::ORGANIZATION_NAME,
            'slug' => self::ORGANIZATION_SLUG,
            'description' => 'Organization de laboratoire de la campagne Nervous System : donnees synthetiques, attentes declarees.',
            'is_active' => true,
            'is_public' => false,
            'is_default' => false,
            'welcome_points' => 500,
            'loops_enabled' => true,
            'members_can_create_loops' => true,
            'ai_profiles_enabled' => true,
            'loop_mode' => 'multi',
            'locale' => 'fr',
            'platform_tagline' => 'Le laboratoire du systeme nerveux',
            'default_country_code' => 'FR',
            'show_country' => false,
        ]);
    }

    public function assertOrganizationAdoptable(Organization $organization): void
    {
        $contents = array_filter([
            'users' => User::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'loops' => Loop::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'dossiers' => Dossier::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'blog_posts' => BlogPost::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'service_requests' => ServiceRequest::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
            'services' => Service::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count(),
        ], static fn (int $count): bool => $count > 0);

        if ($contents !== []) {
            throw ScenarioPackOrganizationNotAdoptableException::forOrganization($organization->slug, $contents);
        }
    }

    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        if ($organization->slug !== self::ORGANIZATION_SLUG) {
            throw new LogicException("AiLabPack ne peut cibler que l'Organization '".self::ORGANIZATION_SLUG."', reçu '{$organization->slug}'.");
        }

        // Le corpus est verifie AVANT toute ecriture : un Lab au corpus
        // incomplet n'est pas un Lab (bruyant, jamais partiel).
        $source = $this->sourceDirectory();

        // TASK-1594 / CDC-NIGHT §6 (invariant MASTER) — un Lab propre : la
        // connaissance DERIVEE produite apres le chargement (knowledge:derive-due)
        // n'est pas une entite du pack, le registre ne la voit pas ; un reset
        // qui la laisserait servirait un pipeline different de celui declare
        // (precondition `derived_chunks_absent`). Purge AVANT re-application.
        $this->purgeDerivedKnowledge($organization);

        $base = CarbonImmutable::parse('2026-05-04 09:00:00', 'UTC');

        $personas = $this->personas($organization, $registrar, $base);
        [$loops, $dossiers] = $this->loops($organization, $personas, $registrar);
        $this->corpus($organization, $dossiers, $personas, $registrar, $source);
        $this->conversation($loops, $personas, $registrar, $base);
        $this->aiSettings($organization, $registrar);
    }

    /**
     * Retire TOUTE la connaissance derivee de l'Organization Lab — et rien
     * d'autre : la borne est `organization_id` (la note porte son tenant) et
     * `apply()` a deja refuse toute Organization qui n'est pas `ai-lab`.
     * Les vecteurs partent par l'autorite WRITE existante
     * (`DerivedKnowledgeNoteIndexer::forget()`, T1539), puis la note.
     * Aucun hook generique ScenarioPack, aucune purge globale.
     */
    private function purgeDerivedKnowledge(Organization $organization): void
    {
        if ($organization->slug !== self::ORGANIZATION_SLUG) {
            throw new LogicException('Purge de connaissance derivee reservee au Lab.');
        }

        // Les ids d'abord, puis la suppression : jamais `each()` (pagine par
        // OFFSET) sur un ensemble que l'on supprime — revue Opus T1594 #1.
        $ids = DerivedKnowledgeNote::query()
            ->where('organization_id', (string) $organization->id)
            ->pluck('id');

        foreach ($ids as $id) {
            $note = DerivedKnowledgeNote::query()->where('organization_id', (string) $organization->id)->whereKey($id)->first();
            if ($note !== null) {
                $this->derivedIndex->forget($note);
                $note->delete();
            }
        }

        if ($ids->isNotEmpty()) {
            Log::info('AiLabPack : connaissance derivee purgee au (re)chargement du Lab.', ['organization_id' => (string) $organization->id, 'derived_notes' => $ids->count()]);
        }
    }

    /**
     * Les Boucles ou l'utilisateur est membre actif — le contrat d'acces du
     * Lab, lisible par les scenarios (`LOOP_ACL_1` : `lab.member.c` -> L1 seul).
     *
     * @return list<string>
     */
    public static function loopKeysFor(string $personaKey): array
    {
        $keys = [];
        foreach (self::LOOPS as $key => $loop) {
            if ($loop['owner'] === $personaKey || in_array($personaKey, $loop['members'], true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public static function emailFor(string $personaKey): string
    {
        return $personaKey.'@'.self::EMAIL_DOMAIN;
    }

    private function sourceDirectory(): string
    {
        $directory = (string) config(self::SOURCE_CONFIG_KEY, '');

        if ($directory === '' || ! is_dir($directory)) {
            throw new RuntimeException('AiLabPack : repertoire du corpus introuvable ('.self::SOURCE_CONFIG_KEY." = '{$directory}').");
        }

        foreach (self::CORPUS as $loopKey => $files) {
            foreach ($files as $name) {
                if (! is_file(rtrim($directory, '/')."/{$loopKey}/{$name}")) {
                    throw new RuntimeException("AiLabPack : fichier de corpus declare absent : {$loopKey}/{$name}.");
                }
            }
        }

        return rtrim($directory, '/');
    }

    /** @return array<string, User> */
    private function personas(Organization $organization, ScenarioPackEntityRegistrar $registrar, CarbonImmutable $base): array
    {
        $personas = [];

        foreach (self::PERSONAS as $key => $persona) {
            $user = User::updateOrCreate(
                ['email' => self::emailFor($key)],
                [
                    'organization_id' => $organization->id,
                    'name' => $persona['name'],
                    'first_name' => $persona['first_name'],
                    'phone' => null,
                    // Convention des bancs (AiValidationDatasetSeeder) : comptes
                    // deterministes, mot de passe unique `password` ; jamais un
                    // secret dans un pack.
                    'password' => Hash::make('password'),
                    'bio' => $persona['bio'],
                    'city' => null,
                    'location' => null,
                    'country_code' => 'FR',
                    'preferred_locale' => 'fr',
                    'is_available' => true,
                    'is_admin' => $persona['is_admin'],
                    'banned_at' => null,
                ],
            );

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => $base])->saveQuietly();
            }

            if ($user->wasRecentlyCreated) {
                $user->update(['points_balance' => 500]);
            }

            $registrar->track('persona', $key, $user);
            $personas[$key] = $user;
        }

        $organization->update(['admin_id' => $personas['lab.admin']->id]);

        return $personas;
    }

    /**
     * @param  array<string, User>  $personas
     * @return array{0: array<string, Loop>, 1: array<string, Dossier>}
     */
    private function loops(Organization $organization, array $personas, ScenarioPackEntityRegistrar $registrar): array
    {
        $loops = [];
        $dossiers = [];

        foreach (self::LOOPS as $key => $definition) {
            $owner = $personas[$definition['owner']];

            $loop = Loop::query()->where('organization_id', $organization->id)->where('name', $definition['name'])->first();

            if ($loop === null) {
                $loop = $this->loops->createLoopForOrg(
                    user: $owner,
                    organizationId: $organization->id,
                    name: $definition['name'],
                    description: $definition['description'],
                    visibility: 'public',
                    tagline: $definition['tagline'],
                    accessMode: Loop::ACCESS_REQUEST,
                );
            }

            $registrar->track('loop', $key, $loop);

            $ownerMember = $loop->members()->where('user_id', $owner->id)->first();
            if ($ownerMember !== null) {
                $registrar->track('loop_member', "{$key}:{$definition['owner']}", $ownerMember);
            }

            foreach ($definition['members'] as $memberKey) {
                $member = $loop->members()->where('user_id', $personas[$memberKey]->id)->first()
                    ?? $this->loops->addMemberByUserId($loop, $personas[$memberKey]->id);

                $registrar->track('loop_member', "{$key}:{$memberKey}", $member);
            }

            $dossier = $this->rootDocuments->ensureRootDossier($loop);
            $registrar->track('folder', $key, $dossier);

            $loops[$key] = $loop;
            $dossiers[$key] = $dossier;
        }

        return [$loops, $dossiers];
    }

    /**
     * Le corpus, par le chemin REEL : `DossierFile` ecrit AVEC ses evenements
     * (observer -> job `dossier-files-indexing` -> `DossierFileIndexer`) —
     * jamais `withoutEvents()` (K2). Le contenu vient du disque versionne ;
     * le MIME est lu du contenu, avec repli sur l'extension pour le texte.
     *
     * @param  array<string, Dossier>  $dossiers
     * @param  array<string, User>  $personas
     */
    private function corpus(Organization $organization, array $dossiers, array $personas, ScenarioPackEntityRegistrar $registrar, string $source): void
    {
        // L'uploader n'est jamais le proprietaire du Dossier (contrainte FK
        // PostgreSQL, lecon ArtSciLabEnglishPack) : les Dossiers racines n'ont
        // pas de proprietaire, l'uploader reste stable.
        $uploader = $personas['lab.admin'];

        foreach (self::CORPUS as $loopKey => $files) {
            $dossier = $dossiers[$loopKey];

            foreach ($files as $name) {
                $absolute = "{$source}/{$loopKey}/{$name}";
                $body = (string) file_get_contents($absolute);
                $path = self::ORGANIZATION_SLUG.'/'.$loopKey.'/'.$name;
                $key = "{$loopKey}/{$name}";

                $registrar->assertStoragePathAvailable('folder_file', $key, self::DISK, $path);

                if (! Storage::disk(self::DISK)->put($path, $body)) {
                    throw new RuntimeException("AiLabPack : ecriture impossible pour {$path}.");
                }

                $file = DossierFile::withTrashed()->updateOrCreate(
                    ['organization_id' => $organization->id, 'path' => $path],
                    [
                        'dossier_id' => $dossier->id,
                        'uploaded_by' => $uploader->id,
                        'disk' => self::DISK,
                        'original_name' => $name,
                        'display_name' => Str::of(pathinfo($name, PATHINFO_FILENAME))->replace('-', ' ')->ucfirst()->toString(),
                        'mime_type' => $this->mimeType($absolute, $name),
                        'size_bytes' => strlen($body),
                        'checksum_sha256' => hash('sha256', $body),
                        'source' => 'upload',
                    ],
                );

                if ($file->trashed()) {
                    $file->restore();
                }

                $registrar->track('folder_file', $key, $file);
            }
        }
    }

    private function mimeType(string $absolutePath, string $originalName): string
    {
        $guessed = (new File($absolutePath))->getMimeType() ?? 'application/octet-stream';

        // Un texte est reconnu `text/plain` par son contenu : l'extension dit
        // sa nature (Markdown). Les formats bureautiques gardent le MIME lu.
        if (str_starts_with($guessed, 'text/')) {
            return match (Str::lower(pathinfo($originalName, PATHINFO_EXTENSION))) {
                'md', 'markdown' => 'text/markdown',
                default => 'text/plain',
            };
        }

        if (! in_array($guessed, FileContentExtractor::SUPPORTED_MIME_TYPES, true)) {
            throw new RuntimeException("AiLabPack : format non supporte par l'ingestion : {$originalName} ({$guessed}).");
        }

        return $guessed;
    }

    /**
     * La conversation seedee. Idempotence par (Loop, body). Les replies
     * passent par la primitive canonique (`sendUserMessage(..., replyToId)`) ;
     * la reponse `type = ai` seedee (hypothese CDC-03 §4.5) est la SEULE
     * ecriture directe : aucun service produit n'ecrit une bulle IA sans
     * provider, et c'est exactement ce que le Lab veut — un tour 1
     * deterministe. Elle porte `metadata.seeded_by_pack` pour ne jamais etre
     * prise pour un vrai tour (aucun `ai_interaction_id`, aucun bloc `turn`).
     *
     * @param  array<string, Loop>  $loops
     * @param  array<string, User>  $personas
     */
    private function conversation(array $loops, array $personas, ScenarioPackEntityRegistrar $registrar, CarbonImmutable $base): void
    {
        $byKey = [];

        foreach (AiLabDataset::messages() as $spec) {
            $loop = $loops[$spec['loop']];
            $replyTo = isset($spec['reply_to']) ? ($byKey[$spec['reply_to']] ?? null) : null;

            if (isset($spec['reply_to']) && $replyTo === null) {
                throw new LogicException("AiLabDataset : {$spec['key']} repond a {$spec['reply_to']}, qui n'est pas encore seede.");
            }

            $message = LoopMessage::query()->where('loop_id', $loop->id)->where('body', $spec['body'])->first();

            if ($message === null) {
                $message = ($spec['type'] ?? 'user') === 'ai'
                    ? LoopMessage::create([
                        'loop_id' => $loop->id,
                        'organization_id' => $loop->organization_id,
                        'sender_id' => $personas[$spec['sender']]->id,
                        'reply_to_id' => $replyTo?->id,
                        'body' => $spec['body'],
                        'type' => 'ai',
                        'metadata' => ['seeded_by_pack' => self::PACK_ID],
                    ])
                    : $this->messages->sendUserMessage($loop, $personas[$spec['sender']], $spec['body'], null, $replyTo?->id);

                $at = $base->addDays($spec['day'])->addMinutes(crc32($spec['key']) % 480);
                $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
            }

            $registrar->track('loop_message', $spec['key'], $message);
            $byKey[$spec['key']] = $message;
        }
    }

    /**
     * Configuree SANS credential : la cle est collee par l'operateur, jamais
     * par un pack. Un reglage deja present (l'operateur a configure le Lab
     * avant le chargement) est REUTILISE tel quel — le registre refuserait,
     * a raison, qu'un pack mute une entite qu'il n'a pas creee.
     */
    private function aiSettings(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        $settings = OrganizationAiSetting::firstOrCreate(
            ['organization_id' => $organization->id],
            [
                'provider' => 'openrouter',
                'model' => 'openai/gpt-4o-mini',
                'monthly_budget_usd' => 5.00,
                'is_enabled' => true,
                'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_PLATFORM,
            ],
        );

        $registrar->track('organization_ai_setting', 'lab', $settings);
    }
}
