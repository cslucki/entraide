<?php

namespace App\Support\ScenarioManager\Capture;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\CourseAssignment;
use App\Models\CourseModule;
use App\Models\CourseSequence;
use App\Models\CourseSequenceProgress;
use App\Models\CourseSubmission;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopRoadmapItem;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\ScenarioPackLoad;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\User;
use App\Support\ScenarioManifest\ManifestAvatarBank;
use App\Support\ScenarioManifest\ManifestSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-1652 — la sandbox vivante redevient un Manifest V1.
 *
 * Capture est l'INVERSE BORNE des appliers. Elle ne connait aucun moteur de
 * graphe generique : chaque famille est lue comme l'applier l'a ecrite.
 *
 * ## Les quatre regles qui gouvernent tout ce fichier
 *
 * 1. **La frontiere est `organization_id = sandbox->id`, toujours explicite.**
 *    Les 20 familles portent cette colonne (mesure de l'audit), donc aucune
 *    n'a besoin d'une jointure pour retrouver son tenant.
 * 2. **Les references passent par le registre de Capture**, jamais par une
 *    ressemblance de nom, jamais par un UUID.
 * 3. **Une seule ancre de temps** : `loaded_at` du chargement SOURCE. Aucun
 *    `now()` par famille — l'ancre appartient a l'OPERATION (leçon T1644).
 * 4. **Rien ne se devine.** Un champ qui n'existe pas au runtime se relit dans
 *    le Manifest SOURCE par sa stable key, ou obeit a une convention ARBITREE
 *    et ecrite ici. Jamais a une heuristique.
 *
 * ## Le scope tenant, et pourquoi il ne suffit pas de « ne rien faire »
 *
 * `BelongsToOrganizationScope` resout l'Organization COURANTE, pas la sandbox.
 * Sur une requete HTTP, `ResolveUrlOrganization` lie l'Organization par defaut
 * meme sur `/admin` : laisser le scope exporterait les lignes d'une AUTRE
 * Organization. Hors HTTP il tombe sur `whereRaw('0 = 1')` : la Capture rendrait
 * zero service en silence. Les deux issues sont fausses, d'ou
 * {@see requete()} — qui retire CE scope et le remplace immediatement.
 *
 * Jamais `withoutGlobalScopes()` : il emporterait aussi `SoftDeletingScope`, et
 * les cinq familles en suppression douce remonteraient leurs lignes effacees.
 */
final class ScenarioCaptureSerializer
{
    /** @var list<array{famille: string, raison: string, detail: string}> */
    private array $blockers = [];

    /** @var array<string, mixed> le Manifest qui a ete charge */
    private array $source;

    /** @var array<string, array<string, array<string, mixed>>> famille => clef => ligne source */
    private array $indexSource = [];

    private \DateTimeImmutable $ancre;

    /** @var array<string, string> clef de Boucle => clef de son proprietaire */
    private array $proprietaireDeBoucle = [];

    public function __construct(
        private readonly Organization $sandbox,
        private readonly ScenarioPackLoad $load,
        private readonly ScenarioCaptureKeyRegistry $registre,
        string $documentSource,
    ) {
        $decode = json_decode($documentSource, true);
        $this->source = is_array($decode) ? $decode : [];
        $this->ancre = \DateTimeImmutable::createFromInterface($this->load->loaded_at);

        $this->indexerLaSource();
    }

    private function indexerLaSource(): void
    {
        foreach (ManifestSchema::envelope() as $nom => $spec) {
            if (($spec['type'] ?? null) !== 'array' || ! isset($spec['key_field'])) {
                continue;
            }

            foreach ($this->source[$nom] ?? [] as $ligne) {
                if (is_array($ligne) && isset($ligne['key'])) {
                    $this->indexSource[$nom][(string) $ligne['key']] = $ligne;
                }
            }
        }

        foreach (['modules', 'sequences', 'assignments'] as $sous) {
            foreach ($this->source['training'][$sous] ?? [] as $ligne) {
                if (is_array($ligne) && isset($ligne['key'])) {
                    $this->indexSource['training.'.$sous][(string) $ligne['key']] = $ligne;
                }
            }
        }
    }

    // =====================================================================
    // L'assemblage
    // =====================================================================

    public function serialiser(): ScenarioCaptureResult
    {
        $document = [
            'schema_version' => ManifestSchema::SCHEMA_VERSION,
            'id' => (string) ($this->source['id'] ?? 'capture'),
            'version' => (string) ($this->source['version'] ?? '1.0.0'),
            'name' => (string) ($this->source['name'] ?? $this->sandbox->name),
            'description' => (string) ($this->source['description'] ?? ''),
            'purpose' => (string) ($this->source['purpose'] ?? ''),
            'locale' => (string) ($this->source['locale'] ?? 'fr'),
            'assets' => ['avatar_bank' => ManifestSchema::AVATAR_BANK],
            'organization' => [
                'name' => (string) $this->sandbox->name,
                'proposed_slug' => (string) ($this->source['organization']['proposed_slug'] ?? $this->sandbox->slug),
                'description' => (string) ($this->source['organization']['description'] ?? ''),
                'locale' => (string) ($this->source['locale'] ?? 'fr'),
            ],
        ];

        // L'ORDRE compte : chaque famille a besoin des clefs des precedentes.
        $document['users'] = $this->users();
        $document['loops'] = $this->loops();
        $document['memberships'] = $this->memberships();
        $document['dossiers'] = $this->dossiers();
        $document['articles'] = $this->articles();
        $document['files'] = $this->files();
        $document['messages'] = $this->messages();
        $document['categories'] = $this->categories();
        $document['skills'] = $this->skills();
        $document['service_requests'] = $this->serviceRequests();
        $document['services'] = $this->services();
        $document['polls'] = $this->polls();
        $document['events'] = $this->events();
        $document['decisions'] = $this->decisions();
        $document['roadmap_items'] = $this->roadmapItems();
        $document['training'] = [
            'modules' => $this->modules(),
            'sequences' => $this->sequences(),
            'progress' => $this->progress(),
            'assignments' => $this->assignments(),
            'submissions' => $this->submissions(),
        ];

        // `loops[].root_dossier` ne se connait qu'apres les Dossiers.
        $document['loops'] = $this->reconcilierLesRacines($document['loops'], $document['dossiers']);

        $this->verifierLesBornes($document);

        return new ScenarioCaptureResult($document, $this->blockers);
    }

    // =====================================================================
    // Le socle : tenant, references, temps
    // =====================================================================

    /**
     * LA requete de Capture. Toute lecture passe par ici.
     *
     * @param  class-string  $classe
     */
    private function requete(string $classe): Builder
    {
        $table = (new $classe)->getTable();

        return $classe::query()
            // CE scope, et lui seul : `withoutGlobalScopes()` emporterait aussi
            // la suppression douce.
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            // La compensation, IMMEDIATE et explicite.
            ->where($table.'.organization_id', $this->sandbox->id);
    }

    private function clef(string $famille, ?string $entityId, string $nomPropose): ?string
    {
        if ($entityId === null) {
            return null;
        }

        return $this->registre->clefDe($famille, $entityId, $nomPropose);
    }

    /**
     * La clef d'un objet DEJA nomme. `null` si l'objet n'est pas capturable :
     * la reference doit alors se voir, pas s'inventer.
     */
    private function ref(string $famille, ?string $entityId): ?string
    {
        return $entityId === null ? null : $this->registre->clefConnue($famille, $entityId);
    }

    private function offset(mixed $instant): ?int
    {
        if ($instant === null) {
            return null;
        }

        $date = $instant instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($instant)
            : new \DateTimeImmutable((string) $instant);

        return (int) round(($date->getTimestamp() - $this->ancre->getTimestamp()) / 60);
    }

    /**
     * L'inverse EXACT de `fromDayOffset()`, qui fait
     * `loadStartedAt->modify('+N days')` en conservant l'heure.
     *
     * On compare donc des DATES, pas des minutes. Plusieurs de ces colonnes
     * (`loop_decisions.decided_on`) sont des dates pures : l'heure y est
     * tronquee a minuit, et diviser des minutes par 1440 perdait alors un jour
     * des que l'ancre tombait plus tard dans la journee. Le round-trip rendait
     * `-9` la ou la source disait `-8`.
     */
    private function offsetJours(mixed $instant): ?int
    {
        if ($instant === null) {
            return null;
        }

        $date = $instant instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($instant)
            : new \DateTimeImmutable((string) $instant);

        $depuis = new \DateTimeImmutable($this->ancre->format('Y-m-d'));
        $jusqua = new \DateTimeImmutable($date->format('Y-m-d'));

        $ecart = $depuis->diff($jusqua);

        return (int) $ecart->days * ($ecart->invert === 1 ? -1 : 1);
    }

    /**
     * La ligne SOURCE d'un objet, par sa stable key.
     *
     * @return array<string, mixed>|null
     */
    private function source(string $famille, ?string $clef): ?array
    {
        return $clef === null ? null : ($this->indexSource[$famille][$clef] ?? null);
    }

    private function bloquer(string $famille, string $raison, string $detail): void
    {
        $this->blockers[] = ['famille' => $famille, 'raison' => $raison, 'detail' => $detail];
    }

    // =====================================================================
    // Personnes
    // =====================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function users(): array
    {
        $responsable = (string) ($this->sandbox->admin_id ?? '');
        $profils = $this->requete(MemberAiProfile::class)->get()->keyBy('user_id');
        $sortie = [];

        foreach ($this->requete(User::class)->orderBy('created_at')->orderBy('id')->get() as $user) {
            $clef = $this->clef('users', (string) $user->id, (string) ($user->first_name ?: $user->name ?: 'persona'));
            $source = $this->source('users', $clef);

            // L'email ne se RECONSTRUIT jamais par manipulation de chaine.
            //
            // Le Loader ecrit `local@<slug>.<domaine>` : la transformation n'est
            // pas inversible, puisqu'on ne sait pas ou s'arrete le slug insere,
            // et le slug d'une Organization peut avoir change depuis. Pour un
            // persona SOURCE on relit donc l'email declare ; pour un persona
            // nouveau on n'accepte que ce qui est deja `.test`.
            $email = $source['email'] ?? null;

            if (! is_string($email)) {
                $runtime = (string) $user->email;

                if (! str_ends_with(strtolower($runtime), '.test')) {
                    $this->bloquer('users', 'real_user', sprintf(
                        'Le compte « %s » n a pas d email de test et ne vient pas du Manifest source : il ne peut pas etre exporte.',
                        $clef
                    ));

                    continue;
                }

                $email = $runtime;
            }

            $sortie[] = [
                'key' => $clef,
                'first_name' => (string) $user->first_name,
                'name' => (string) $user->name,
                'email' => $email,
                'bio' => $user->bio,
                'available' => (bool) $user->is_available,
                'location' => $user->location,
                'avatar' => $this->clefDAvatar($user),
                // `is_admin` est le predicat PLATEFORME : il n'entre JAMAIS
                // dans un Manifest. Le role d'Organization se lit sur la
                // primitive tenant, `organizations.admin_id`.
                'organization_role' => ((string) $user->id === $responsable) ? 'admin' : 'member',
                'member_ai_profile' => $this->profilIa($profils->get($user->id)),
            ];
        }

        return $sortie;
    }

    /**
     * Le chemin Storage redevient une CLEF DE BANQUE — jamais l'inverse.
     *
     * Le Manifest ne transporte aucun chemin : ni S3, ni local. La derivation
     * est deterministe (`<dossier>/<banque>/<clef>.svg`) et la clef obtenue est
     * CONFRONTEE a la banque : un chemin qui ne s'y retrouve pas rend `null`,
     * ce que le schema admet et qui laisse le repli initiales.
     */
    private function clefDAvatar(User $user): ?string
    {
        $chemin = (string) ($user->avatar ?? '');

        if ($chemin === '') {
            return null;
        }

        $banque = ManifestSchema::AVATAR_BANK;
        $clef = pathinfo($chemin, PATHINFO_FILENAME);

        return in_array($clef, ManifestAvatarBank::keys($banque), true) ? $clef : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profilIa(?MemberAiProfile $profil): ?array
    {
        if ($profil === null) {
            return null;
        }

        // `published` runtime -> `published`, tout autre etat interne ->
        // `draft`. Aucun `generated_*`, aucun `wizard_state`, aucune metadata
        // systeme : ce sont des rouages, pas du monde declarable.
        return [
            'status' => ((string) $profil->status === 'published') ? 'published' : 'draft',
            'summary' => $profil->member_profile_summary,
            'service_scope' => $profil->service_scope,
            'experience_context' => $profil->experience_context,
            'target_audience' => $profil->target_audience,
            'problems_helped' => $profil->problems_helped,
            'skills' => $profil->skills,
            'help_types' => $profil->help_types,
            'boundaries' => $profil->boundaries,
            'preferred_contact_action' => $profil->preferred_contact_action,
            'tone' => $profil->tone,
        ];
    }

    // =====================================================================
    // Boucles et participations
    // =====================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function loops(): array
    {
        $sortie = [];

        foreach ($this->requete(Loop::class)->orderBy('created_at')->orderBy('id')->get() as $loop) {
            $clef = $this->clef('loops', (string) $loop->id, (string) $loop->name);

            $proprietaire = $this->ref('users', (string) ($loop->created_by ?? ''))
                ?? $this->proprietaireParMembership($loop);

            if ($proprietaire === null) {
                $this->bloquer('loops', 'owner_unresolved', sprintf(
                    'La Boucle « %s » n a aucun proprietaire capturable.', $clef
                ));

                continue;
            }

            $this->proprietaireDeBoucle[$clef] = $proprietaire;

            $sortie[] = [
                'key' => $clef,
                'name' => (string) $loop->name,
                'description' => (string) ($loop->description ?? ''),
                'type' => (string) $loop->type,
                'owner' => $proprietaire,
                'visibility' => (string) $loop->visibility,
                'access_mode' => (string) $loop->access_mode,
                // Pose a la reconciliation : le Dossier racine n'est connu
                // qu'apres la famille `dossiers`.
                'root_dossier' => null,
            ];
        }

        return $sortie;
    }

    private function proprietaireParMembership(Loop $loop): ?string
    {
        $membre = $this->requete(LoopMember::class)
            ->where('loop_id', $loop->id)
            ->where('role', 'owner')
            ->orderBy('created_at')
            ->first();

        return $membre === null ? null : $this->ref('users', (string) $membre->user_id);
    }

    /**
     * @param  list<array<string, mixed>>  $loops
     * @param  list<array<string, mixed>>  $dossiers
     * @return list<array<string, mixed>>
     */
    private function reconcilierLesRacines(array $loops, array $dossiers): array
    {
        $racineParBoucle = [];

        foreach ($dossiers as $dossier) {
            if (($dossier['parent'] ?? null) === null && ($dossier['loop'] ?? null) !== null) {
                $racineParBoucle[$dossier['loop']] ??= $dossier['key'];
            }
        }

        foreach ($loops as $i => $loop) {
            $racine = $racineParBoucle[$loop['key']] ?? null;

            if ($racine === null) {
                $this->bloquer('loops', 'root_dossier_missing', sprintf(
                    'La Boucle « %s » n a pas d espace documents capturable.', $loop['key']
                ));

                continue;
            }

            $loops[$i]['root_dossier'] = $racine;
        }

        return $loops;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function memberships(): array
    {
        $sortie = [];

        // Identite COMPOSEE : aucune stable key fabriquee.
        foreach ($this->requete(LoopMember::class)->orderBy('created_at')->orderBy('id')->get() as $membre) {
            $loop = $this->ref('loops', (string) $membre->loop_id);
            $user = $this->ref('users', (string) $membre->user_id);

            if ($loop === null || $user === null) {
                continue;
            }

            $sortie[] = ['loop' => $loop, 'user' => $user, 'role' => (string) $membre->role];
        }

        return $sortie;
    }

    // =====================================================================
    // Dossiers, articles, fichiers
    // =====================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function dossiers(): array
    {
        $sortie = [];

        $lignes = $this->requete(Dossier::class)
            // Les Dossiers SYSTEME et personnels ne sont pas declarables.
            ->whereNull('system_role')
            ->whereNotNull('loop_id')
            ->orderBy('created_at')->orderBy('id')->get();

        foreach ($lignes as $dossier) {
            $clef = $this->clef('dossiers', (string) $dossier->id, (string) $dossier->name);
            $boucle = $this->ref('loops', (string) ($dossier->loop_id ?? ''));

            if ($boucle === null) {
                continue;
            }

            // La GOUVERNANCE EFFECTIVE, pas la colonne.
            //
            // Mesure : `ensureRootDossier()` cree l'espace documents d'une
            // Boucle avec `owner_id = NULL`. Or `dossiers[].owner` n'est pas
            // nullable dans Manifest V1. Lire la colonne naivement ferait donc
            // disparaitre TOUS les Dossiers racines de la Capture, et avec eux
            // les Boucles qui les designent.
            //
            // Le proprietaire effectif d'un espace documents est celui de sa
            // Boucle : c'est une reconstruction semantique deterministe, pas
            // un choix par defaut.
            $proprietaire = $this->ref('users', (string) ($dossier->owner_id ?? ''))
                ?? ($this->proprietaireDeBoucle[$boucle] ?? null);

            if ($proprietaire === null) {
                $this->bloquer('dossiers', 'owner_unresolved', sprintf(
                    'Le Dossier « %s » n a aucun proprietaire capturable.', $clef
                ));

                continue;
            }

            $ligne = [
                'key' => $clef,
                'name' => (string) $dossier->name,
                'owner' => $proprietaire,
                'loop' => $boucle,
                'parent' => $this->ref('dossiers', (string) ($dossier->parent_id ?? '')),
                'visibility' => $this->visibiliteDeDossier($dossier),
                'root_document' => null,
            ];

            if ($dossier->parent_id === null && $dossier->root_blog_post_id !== null) {
                $ligne['root_document'] = $this->documentRacine($dossier, $clef);
            }

            $sortie[] = $ligne;
        }

        return $sortie;
    }

    private function visibiliteDeDossier(Dossier $dossier): string
    {
        // L'espace documents d'une Boucle est visible DE SA BOUCLE, et
        // `ManifestCoreInvariants::class` en fait un invariant :
        // « A root dossier has the visibility 'loop' ». La colonne runtime peut
        // dire autre chose ; c'est l'invariant qui fait foi, et le reconstruire
        // n'est pas un choix mais la lecture de la visibilite EFFECTIVE.
        if ($dossier->parent_id === null && $dossier->loop_id !== null) {
            return 'loop';
        }

        // `shared` est une valeur HISTORIQUE, `@deprecated` sur le modele et
        // absente de `Dossier::VISIBILITIES` : la rendre telle quelle
        // produirait un Manifest que le Validator refuse.
        return match ((string) $dossier->visibility) {
            'organization', 'shared' => 'organization',
            'loop' => 'loop',
            default => 'private',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentRacine(Dossier $dossier, string $clefDuDossier): ?array
    {
        $post = BlogPost::query()->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('organization_id', $this->sandbox->id)
            ->whereKey($dossier->root_blog_post_id)
            ->first();

        if (! $post instanceof BlogPost) {
            return null;
        }

        $source = $this->source('dossiers', $clefDuDossier);

        // Meme gouvernance effective que son Dossier : `initialContent()` peut
        // avoir laisse l'auteur vide quand la Boucle n'avait pas encore de
        // proprietaire designe.
        $auteur = $this->ref('users', (string) ($post->user_id ?? ''))
            ?? ($this->proprietaireDeBoucle[$this->ref('loops', (string) ($dossier->loop_id ?? '')) ?? ''] ?? null);

        if ($auteur === null) {
            return null;
        }

        return [
            'title' => (string) $post->title,
            'author' => $auteur,
            'format' => $this->formatDeBlogPost($source['root_document']['format'] ?? null),
            'content' => (string) $post->content,
        ];
    }

    /**
     * `NEW_BLOGPOST_FORMAT = html`, `SOURCE_OBJECT_FORMAT = exact source`.
     *
     * Le format n'a AUCUNE colonne runtime. Pour un objet source on relit donc
     * le format declare ; pour un objet nouveau la reponse est `html`, non par
     * convenance mais par MESURE : les trois voies produit qui creent un
     * BlogPost de Dossier ecrivent du HTML (`'<p></p>'`,
     * `LoopRootDocumentService::initialContent()`), et la surface d'edition est
     * `<x-blog-editor>` qui emet `getHTML()`.
     *
     * Le contenu n'est JAMAIS inspecte pour trancher.
     */
    private function formatDeBlogPost(mixed $formatSource): string
    {
        return is_string($formatSource) && $formatSource !== '' ? $formatSource : 'html';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articles(): array
    {
        $sortie = [];

        // Les articles declarables sont ceux PLACES dans un Dossier de la
        // sandbox. Le document racine n'en est pas un : il est rendu en ligne.
        $racines = $this->requete(Dossier::class)->whereNotNull('root_blog_post_id')->pluck('root_blog_post_id')->all();

        $liens = DB::table('dossier_blog_posts')
            ->where('organization_id', $this->sandbox->id)
            ->get(['blog_post_id', 'dossier_id']);

        foreach ($liens as $lien) {
            if (in_array($lien->blog_post_id, $racines, true)) {
                continue;
            }

            $post = BlogPost::query()->withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('organization_id', $this->sandbox->id)
                ->whereKey($lien->blog_post_id)
                ->first();

            $dossier = $this->ref('dossiers', (string) $lien->dossier_id);

            if (! $post instanceof BlogPost || $dossier === null) {
                continue;
            }

            $clef = $this->clef('articles', (string) $post->id, (string) $post->title);
            $auteur = $this->ref('users', (string) ($post->user_id ?? ''));

            if ($auteur === null) {
                continue;
            }

            $source = $this->source('articles', $clef);

            $sortie[] = [
                'key' => $clef,
                'dossier' => $dossier,
                'author' => $auteur,
                'title' => (string) $post->title,
                'summary' => $post->summary,
                'status' => (string) $post->status,
                'audience' => (string) $post->audience,
                'format' => $this->formatDeBlogPost($source['format'] ?? null),
                'content' => (string) $post->content,
                'published_offset_minutes' => $this->offset($post->published_at),
            ];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function files(): array
    {
        $sortie = [];
        $typesV1 = ManifestSchema::envelope()['files']['of']['fields']['media_type']['values'];

        foreach ($this->requete(DossierFile::class)->orderBy('created_at')->orderBy('id')->get() as $fichier) {
            $dossier = $this->ref('dossiers', (string) ($fichier->dossier_id ?? ''));
            $auteur = $this->ref('users', (string) ($fichier->uploaded_by ?? ''));

            if ($dossier === null || $auteur === null) {
                continue;
            }

            $type = (string) $fichier->mime_type;

            if (! in_array($type, $typesV1, true)) {
                // Aucune conversion opportuniste : un PDF ne devient pas du
                // Markdown pour faire passer le Validator.
                $this->bloquer('files', 'media_type_hors_v1', sprintf(
                    'Le fichier « %s » est en %s ; Manifest V1 n admet que %s.',
                    $fichier->display_name ?: $fichier->original_name, $type, implode(' et ', $typesV1)
                ));

                continue;
            }

            $contenu = $this->contenuDuFichier($fichier);

            if ($contenu === null) {
                $this->bloquer('files', 'contenu_illisible', sprintf(
                    'Le contenu du fichier « %s » n a pas pu etre relu depuis le stockage.',
                    $fichier->display_name ?: $fichier->original_name
                ));

                continue;
            }

            $sortie[] = [
                'key' => $this->clef('files', (string) $fichier->id, (string) ($fichier->display_name ?: $fichier->original_name)),
                'dossier' => $dossier,
                'uploaded_by' => $auteur,
                'name' => (string) ($fichier->display_name ?: $fichier->original_name),
                'media_type' => $type,
                // Ni `disk`, ni `path`, ni `size_bytes`, ni `checksum_sha256` :
                // ce sont des rouages de stockage, pas du monde declarable.
                'content' => $contenu,
            ];
        }

        return $sortie;
    }

    private function contenuDuFichier(DossierFile $fichier): ?string
    {
        try {
            $disque = Storage::disk((string) $fichier->disk);

            return $disque->exists((string) $fichier->path) ? (string) $disque->get((string) $fichier->path) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // =====================================================================
    // ChatLoop
    // =====================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(): array
    {
        $lignes = $this->requete(LoopMessage::class)
            // Seuls les messages HUMAINS. `ai` et `ai_agent` sont des sorties
            // de moteur, pas du monde declarable.
            ->where('type', 'user')
            // `loop_messages` porte `deleted_at` SANS trait SoftDeletes : le
            // filtre doit etre explicite, aucun scope ne le pose.
            ->whereNull('deleted_at')
            ->orderBy('created_at')->orderBy('id')
            ->get();

        // `order` n'a aucune colonne : il se RECALCULE, par Boucle, parent
        // avant reponse.
        $parBoucle = [];
        $sortie = [];
        $enAttente = [];

        foreach ($lignes as $message) {
            $boucle = $this->ref('loops', (string) $message->loop_id);
            $auteur = $this->ref('users', (string) ($message->sender_id ?? ''));

            if ($boucle === null || $auteur === null) {
                continue;
            }

            $clef = $this->clef('messages', (string) $message->id, 'message');
            $source = $this->source('messages', $clef);

            $enAttente[] = [
                'modele' => $message,
                'ligne' => [
                    'key' => $clef,
                    'type' => ManifestSchema::envelope()['messages']['of']['fields']['type']['value'],
                    'loop' => $boucle,
                    'author' => $auteur,
                    'body' => (string) $message->body,
                    // `NEW_CHATLOOP_MESSAGE_FORMAT = plain` — convention
                    // NORMATIVE arbitree, jamais une detection : le contenu
                    // n'est pas inspecte.
                    'format' => is_string($source['format'] ?? null) ? $source['format'] : 'plain',
                    'order' => 0,
                    'offset_minutes' => $this->offset($message->created_at) ?? 0,
                    'reply_to' => null,
                ],
            ];
        }

        // Deuxieme passe : les reponses, maintenant que toutes les clefs
        // existent. Puis l'ordre, parent avant reponse.
        $clefParId = [];

        foreach ($enAttente as $entree) {
            $clefParId[(string) $entree['modele']->id] = $entree['ligne']['key'];
        }

        $racines = [];
        $reponses = [];

        foreach ($enAttente as $entree) {
            $parent = (string) ($entree['modele']->reply_to_id ?? '');
            $entree['ligne']['reply_to'] = $clefParId[$parent] ?? null;

            if ($entree['ligne']['reply_to'] === null) {
                $racines[] = $entree;
            } else {
                $reponses[$entree['ligne']['reply_to']][] = $entree;
            }
        }

        $poser = function (array $entree) use (&$poser, &$reponses, &$parBoucle, &$sortie): void {
            $boucle = $entree['ligne']['loop'];
            $parBoucle[$boucle] = ($parBoucle[$boucle] ?? 0) + 1;
            $entree['ligne']['order'] = $parBoucle[$boucle];
            $sortie[] = $entree['ligne'];

            foreach ($reponses[$entree['ligne']['key']] ?? [] as $enfant) {
                $poser($enfant);
            }
        };

        foreach ($racines as $racine) {
            $poser($racine);
        }

        return $sortie;
    }

    // =====================================================================
    // Referentiel
    // =====================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        $sortie = [];

        foreach ($this->requete(Category::class)->orderBy('created_at')->orderBy('id')->get() as $categorie) {
            $sortie[] = [
                'key' => $this->clef('categories', (string) $categorie->id, (string) $categorie->name_b2c),
                'name' => (string) $categorie->name_b2c,
                'color' => (string) $categorie->color,
            ];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skills(): array
    {
        $sortie = [];

        foreach ($this->requete(Skill::class)->orderBy('created_at')->orderBy('id')->get() as $skill) {
            $categorie = $this->ref('categories', (string) ($skill->category_id ?? ''));

            if ($categorie === null) {
                continue;
            }

            $sortie[] = [
                'key' => $this->clef('skills', (string) $skill->id, (string) $skill->name),
                'category' => $categorie,
                'name' => (string) $skill->name,
            ];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serviceRequests(): array
    {
        $sortie = [];

        foreach ($this->requete(ServiceRequest::class)->orderBy('created_at')->orderBy('id')->get() as $demande) {
            $auteur = $this->ref('users', (string) ($demande->user_id ?? ''));
            $categorie = $this->ref('categories', (string) ($demande->category_id ?? ''));

            if ($auteur === null || $categorie === null) {
                continue;
            }

            $clef = $this->clef('service_requests', (string) $demande->id, (string) $demande->title);
            $source = $this->source('service_requests', $clef);

            $sortie[] = [
                'key' => $clef,
                'author' => $auteur,
                'title' => (string) $demande->title,
                'description' => (string) $demande->description,
                'category' => $categorie,
                'delivery_mode' => (string) $demande->delivery_mode,
                'budget_min' => (int) $demande->budget_min,
                'budget_max' => $demande->budget_max === null ? null : (int) $demande->budget_max,
                'deadline_day_offset' => $this->offsetJours($demande->deadline),
                'status' => (string) $demande->status,
                // Aucune colonne runtime : relu de la source, sinon `null`,
                // valeur que le schema admet.
                'highlight_in_loop' => is_string($source['highlight_in_loop'] ?? null) ? $source['highlight_in_loop'] : null,
            ];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function services(): array
    {
        $sortie = [];

        foreach ($this->requete(Service::class)->orderBy('created_at')->orderBy('id')->get() as $service) {
            $auteur = $this->ref('users', (string) ($service->user_id ?? ''));
            $categorie = $this->ref('categories', (string) ($service->category_id ?? ''));

            if ($auteur === null || $categorie === null) {
                continue;
            }

            $clef = $this->clef('services', (string) $service->id, (string) $service->title);
            $source = $this->source('services', $clef);

            $sortie[] = [
                'key' => $clef,
                'author' => $auteur,
                'title' => (string) $service->title,
                'description' => (string) $service->description,
                'category' => $categorie,
                'skills' => is_array($source['skills'] ?? null) ? $source['skills'] : [],
                'delivery_mode' => (string) $service->delivery_mode,
                'points_cost' => (int) $service->points_cost,
                'status' => (string) $service->status,
                'highlight_in_loop' => is_string($source['highlight_in_loop'] ?? null) ? $source['highlight_in_loop'] : null,
            ];
        }

        return $sortie;
    }

    // =====================================================================
    // Vie de Boucle
    // =====================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function polls(): array
    {
        $sortie = [];

        foreach ($this->requete(LoopPoll::class)->orderBy('created_at')->orderBy('id')->get() as $sondage) {
            $boucle = $this->ref('loops', (string) $sondage->loop_id);
            $auteur = $this->ref('users', (string) ($sondage->created_by ?? ''));

            if ($boucle === null || $auteur === null) {
                continue;
            }

            $options = DB::table('loop_poll_options')->where('poll_id', $sondage->id)
                ->orderBy('position')->orderBy('id')->get(['id', 'label']);

            $clefParOption = [];
            $declarees = [];

            foreach ($options as $option) {
                $clefOption = $this->clef('poll_options', (string) $option->id, (string) $option->label);
                $clefParOption[(string) $option->id] = $clefOption;
                $declarees[] = ['key' => $clefOption, 'label' => (string) $option->label];
            }

            $sortie[] = [
                'key' => $this->clef('polls', (string) $sondage->id, (string) $sondage->question),
                'loop' => $boucle,
                'author' => $auteur,
                'question' => (string) $sondage->question,
                'description' => $sondage->description,
                'selection_type' => (string) $sondage->selection_type,
                'status' => (string) $sondage->status,
                'options' => $declarees,
                'votes' => $this->votes($sondage, $clefParOption),
            ];
        }

        return $sortie;
    }

    /**
     * @param  array<string, string>  $clefParOption
     * @return list<array<string, mixed>>
     */
    private function votes(LoopPoll $sondage, array $clefParOption): array
    {
        $sortie = [];

        $votes = DB::table('loop_poll_votes')
            ->where('organization_id', $this->sandbox->id)
            ->where('poll_id', $sondage->id)
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $choixParVote = DB::table('loop_poll_vote_options')
            ->whereIn('vote_id', $votes->pluck('id'))
            ->get()
            ->groupBy('vote_id');

        foreach ($votes as $vote) {
            $user = $this->ref('users', (string) $vote->user_id);

            if ($user === null) {
                continue;
            }

            $choix = [];

            foreach ($choixParVote[$vote->id] ?? [] as $ligne) {
                $clefOption = $clefParOption[(string) $ligne->option_id] ?? null;

                if ($clefOption !== null) {
                    $choix[] = $clefOption;
                }
            }

            // Identite COMPOSEE (sondage, personne) : pas de clef fabriquee.
            $sortie[] = ['user' => $user, 'options' => $choix];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        $sortie = [];

        foreach ($this->requete(LoopEvent::class)->orderBy('created_at')->orderBy('id')->get() as $evenement) {
            $boucle = $this->ref('loops', (string) $evenement->loop_id);
            $auteur = $this->ref('users', (string) ($evenement->created_by ?? ''));

            if ($boucle === null || $auteur === null) {
                continue;
            }

            $debut = $evenement->starts_at;
            $fin = $evenement->ends_at;
            $duree = ($debut && $fin)
                ? max(1, (int) round((strtotime((string) $fin) - strtotime((string) $debut)) / 60))
                : 60;

            $sortie[] = [
                'key' => $this->clef('events', (string) $evenement->id, (string) $evenement->title),
                'loop' => $boucle,
                'author' => $auteur,
                'title' => (string) $evenement->title,
                'description' => $evenement->description,
                'format' => (string) $evenement->format,
                'starts_offset_minutes' => $this->offset($debut) ?? 0,
                'duration_minutes' => $duree,
                'timezone' => (string) $evenement->timezone,
                'location' => $evenement->location,
                'meeting_url' => $evenement->meeting_url,
                'visibility' => (string) $evenement->visibility,
                'status' => (string) $evenement->status,
                'responses' => $this->reponsesAEvenement($evenement),
            ];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reponsesAEvenement(LoopEvent $evenement): array
    {
        $sortie = [];

        $lignes = DB::table('loop_event_responses')
            ->where('organization_id', $this->sandbox->id)
            ->where('event_id', $evenement->id)
            ->orderBy('created_at')->orderBy('id')
            ->get();

        foreach ($lignes as $ligne) {
            $user = $this->ref('users', (string) $ligne->user_id);

            if ($user !== null) {
                // Identite COMPOSEE (evenement, personne).
                $sortie[] = ['user' => $user, 'response' => (string) $ligne->response];
            }
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decisions(): array
    {
        $lignes = $this->requete(LoopDecision::class)->orderBy('created_at')->orderBy('id')->get();

        // `supersedes` s'inverse : le Loader appelle
        // `supersede($ancienne, $nouvelle)`, ce qui ecrit
        // `ancienne.superseded_by_id = nouvelle.id`. Pour une decision D,
        // `supersedes` est donc la decision X telle que
        // `X.superseded_by_id === D.id`.
        $remplaceePar = [];

        foreach ($lignes as $decision) {
            if ($decision->superseded_by_id !== null) {
                $remplaceePar[(string) $decision->superseded_by_id] = (string) $decision->id;
            }
        }

        $sortie = [];

        foreach ($lignes as $decision) {
            $boucle = $this->ref('loops', (string) $decision->loop_id);
            $auteur = $this->ref('users', (string) ($decision->author_id ?? ''));

            if ($boucle === null || $auteur === null) {
                continue;
            }

            $sortie[] = [
                'key' => $this->clef('decisions', (string) $decision->id, (string) $decision->title),
                'loop' => $boucle,
                'author' => $auteur,
                'title' => (string) $decision->title,
                'rationale' => $decision->rationale,
                'decided_day_offset' => $this->offsetJours($decision->decided_on) ?? 0,
                'message' => $this->ref('messages', (string) ($decision->loop_message_id ?? '')),
                'supersedes' => $this->ref('decisions', $remplaceePar[(string) $decision->id] ?? null),
            ];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function roadmapItems(): array
    {
        $sortie = [];

        foreach ($this->requete(LoopRoadmapItem::class)->orderBy('position')->orderBy('id')->get() as $item) {
            $boucle = $this->ref('loops', (string) $item->loop_id);
            $auteur = $this->ref('users', (string) ($item->created_by ?? ''));

            if ($boucle === null || $auteur === null) {
                continue;
            }

            $assignes = [];

            foreach (DB::table('loop_roadmap_item_user')->where('loop_roadmap_item_id', $item->id)->get() as $lien) {
                $user = $this->ref('users', (string) $lien->user_id);

                if ($user !== null) {
                    $assignes[] = $user;
                }
            }

            $sortie[] = [
                'key' => $this->clef('roadmap_items', (string) $item->id, (string) $item->title),
                'loop' => $boucle,
                'created_by' => $auteur,
                'title' => (string) $item->title,
                'description' => $item->description,
                'status' => (string) $item->status,
                'position' => (int) $item->position,
                'assignees' => $assignes,
                'due_day_offset' => $this->offsetJours($item->due_at),
                'decision' => $this->ref('decisions', (string) ($item->loop_decision_id ?? '')),
            ];
        }

        return $sortie;
    }

    // =====================================================================
    // Formation
    // =====================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function modules(): array
    {
        $sortie = [];

        foreach ($this->requete(CourseModule::class)->whereNull('archived_at')->orderBy('position')->orderBy('id')->get() as $module) {
            $boucle = $this->ref('loops', (string) $module->loop_id);
            $auteur = $this->ref('users', (string) ($module->created_by ?? ''));

            if ($boucle === null || $auteur === null) {
                continue;
            }

            $sortie[] = [
                'key' => $this->clef('training.modules', (string) $module->id, (string) $module->title),
                'loop' => $boucle,
                'title' => (string) $module->title,
                'summary' => $module->summary,
                'position' => (int) $module->position,
                'created_by' => $auteur,
            ];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sequences(): array
    {
        $sortie = [];

        foreach ($this->requete(CourseSequence::class)->whereNull('archived_at')->orderBy('position')->orderBy('id')->get() as $sequence) {
            $module = $this->ref('training.modules', (string) $sequence->course_module_id);
            $auteur = $this->ref('users', (string) ($sequence->created_by ?? ''));

            if ($module === null || $auteur === null) {
                continue;
            }

            $clef = $this->clef('training.sequences', (string) $sequence->id, (string) $sequence->title);
            $source = $this->source('training.sequences', $clef);

            $sortie[] = [
                'key' => $clef,
                'module' => $module,
                'title' => (string) $sequence->title,
                'position' => (int) $sequence->position,
                'requires_validation' => (bool) $sequence->requires_validation,
                'created_by' => $auteur,
                'content' => $this->contenuDeSequence($sequence, $source),
            ];
        }

        return $sortie;
    }

    /**
     * @param  array<string, mixed>|null  $source
     * @return array<string, mixed>
     */
    private function contenuDeSequence(CourseSequence $sequence, ?array $source): array
    {
        if ($sequence->blog_post_id !== null) {
            $clef = $this->ref('articles', (string) $sequence->blog_post_id);

            if ($clef !== null) {
                return ['type' => 'article', 'article' => $clef];
            }
        }

        if ($sequence->dossier_file_id !== null) {
            $clef = $this->ref('files', (string) $sequence->dossier_file_id);

            if ($clef !== null) {
                return ['type' => 'file', 'file' => $clef];
            }
        }

        if (is_string($sequence->body) && $sequence->body !== '') {
            return ['type' => 'text', 'body' => (string) $sequence->body];
        }

        return is_array($source['content'] ?? null) ? $source['content'] : ['type' => 'text', 'body' => ''];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function progress(): array
    {
        $sortie = [];

        foreach ($this->requete(CourseSequenceProgress::class)->orderBy('created_at')->orderBy('id')->get() as $avancee) {
            $sequence = $this->ref('training.sequences', (string) $avancee->course_sequence_id);
            $user = $this->ref('users', (string) $avancee->user_id);

            if ($sequence === null || $user === null) {
                continue;
            }

            // Identite COMPOSEE (sequence, personne).
            $sortie[] = [
                'sequence' => $sequence,
                'user' => $user,
                'status' => (string) $avancee->status,
                'started_offset_minutes' => $this->offset($avancee->started_at),
                'completed_offset_minutes' => $this->offset($avancee->completed_at),
                'validated_by' => $this->ref('users', (string) ($avancee->validated_by ?? '')),
                'validated_offset_minutes' => $this->offset($avancee->validated_at),
                'unlocked_by' => $this->ref('users', (string) ($avancee->unlocked_by ?? '')),
                'unlocked_offset_minutes' => $this->offset($avancee->unlocked_at),
            ];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignments(): array
    {
        $sortie = [];

        foreach ($this->requete(CourseAssignment::class)->whereNull('archived_at')->orderBy('position')->orderBy('id')->get() as $devoir) {
            $boucle = $this->ref('loops', (string) $devoir->loop_id);
            $auteur = $this->ref('users', (string) ($devoir->created_by ?? ''));

            if ($boucle === null || $auteur === null) {
                continue;
            }

            $sortie[] = [
                'key' => $this->clef('training.assignments', (string) $devoir->id, (string) $devoir->title),
                'loop' => $boucle,
                'sequence' => $this->ref('training.sequences', (string) ($devoir->course_sequence_id ?? '')),
                'title' => (string) $devoir->title,
                'brief' => $devoir->brief,
                'due_offset_minutes' => $this->offset($devoir->due_at),
                'position' => (int) $devoir->position,
                'created_by' => $auteur,
            ];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function submissions(): array
    {
        $sortie = [];

        foreach ($this->requete(CourseSubmission::class)->orderBy('created_at')->orderBy('id')->get() as $rendu) {
            $devoir = $this->ref('training.assignments', (string) $rendu->course_assignment_id);
            $user = $this->ref('users', (string) $rendu->user_id);

            if ($devoir === null || $user === null) {
                continue;
            }

            // Identite COMPOSEE (devoir, personne).
            $sortie[] = [
                'assignment' => $devoir,
                'user' => $user,
                'body' => $rendu->body,
                'file' => $this->ref('files', (string) ($rendu->dossier_file_id ?? '')),
                'status' => (string) $rendu->status,
                'submitted_offset_minutes' => $this->offset($rendu->submitted_at),
                'feedback' => $rendu->feedback,
                'reviewed_by' => $this->ref('users', (string) ($rendu->reviewed_by ?? '')),
                'reviewed_offset_minutes' => $this->offset($rendu->reviewed_at),
            ];
        }

        return $sortie;
    }

    // =====================================================================
    // Les bornes V1 : refuser, jamais tronquer
    // =====================================================================

    /**
     * @param  array<string, mixed>  $document
     */
    private function verifierLesBornes(array $document): void
    {
        foreach (ManifestSchema::envelope() as $nom => $spec) {
            if (($spec['type'] ?? null) === 'array') {
                $this->borner($nom, count($document[$nom] ?? []), (int) $spec['max']);

                continue;
            }

            if (($spec['type'] ?? null) === 'object' && $nom === 'training') {
                foreach ($spec['fields'] as $sous => $sousSpec) {
                    if (($sousSpec['type'] ?? null) === 'array') {
                        $this->borner('training.'.$sous, count($document['training'][$sous] ?? []), (int) $sousSpec['max']);
                    }
                }
            }
        }
    }

    private function borner(string $famille, int $mesure, int $limite): void
    {
        if ($mesure > $limite) {
            // Le rapport est CHIFFRE : famille, limite, valeur reelle. Aucune
            // troncature — un Manifest ampute serait un mensonge sur le monde.
            $this->bloquer($famille, 'limite_v1_depassee', sprintf(
                'La sandbox compte %d objets pour une limite Manifest V1 de %d.', $mesure, $limite
            ));
        }
    }
}
