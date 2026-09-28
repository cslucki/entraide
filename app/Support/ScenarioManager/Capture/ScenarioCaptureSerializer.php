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
 * 3. **Une seule ancre de temps** : `world_anchored_at`, l'instant EXACT ou le
 *    pack a commence a materialiser le monde (T1653). Aucun `now()` par
 *    famille — l'ancre appartient a l'OPERATION (leçon T1644).
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

    /** @var array<string, array<string, true>> famille => clefs REELLEMENT emises */
    private array $emises = [];

    /** @var array<string, string> clef de Boucle => clef de son Dossier racine */
    private array $racinesParBoucle = [];

    public function __construct(
        private readonly Organization $sandbox,
        private readonly ScenarioPackLoad $load,
        private readonly ScenarioCaptureKeyRegistry $registre,
        string $documentSource,
    ) {
        $decode = json_decode($documentSource, true);
        $this->source = is_array($decode) ? $decode : [];
        // L'ancre PERSISTEE du monde, et rien d'autre.
        //
        // T1652 lisait `loaded_at`, qui n'est pas l'instant de materialisation :
        // T1653 persiste `world_anchored_at` au moment exact ou le pack
        // commence a ecrire le monde. Le constructeur n'accepte plus de repli —
        // ni `now()`, ni `created_at`, ni `reset_at` : une ancre inconnue est
        // refusee EN AMONT par {@see ScenarioCaptureService::prouverLaProvenance()},
        // parce qu'un offset calcule sur une ancre approximative est faux sans
        // que rien ne le signale.
        $this->ancre = \DateTimeImmutable::createFromInterface($this->load->world_anchored_at);

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
                // Le locale de l'ORGANIZATION, lu au runtime — pas le locale
                // racine du document.
                //
                // Les deux sont INDEPENDANTS dans le schema, et
                // `ScenarioSandboxProvisioner` charge la sandbox avec
                // `organization.locale`. Lire le locale racine faisait donc
                // diverger la capture d'un manifeste declarant `locale: en` et
                // `organization.locale: fr` : la version capturee aurait
                // recharge une sandbox dans la MAUVAISE langue, et
                // `ensureRootDocument()` y aurait cuit titres et en-tetes de
                // sections, definitivement. Trouve en relecture adverse.
                'locale' => (string) ($this->sandbox->locale
                    ?? $this->source['organization']['locale']
                    ?? $this->source['locale']
                    ?? 'fr'),
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
     * Declare qu'un objet est REELLEMENT entre dans le document.
     *
     * C'est ce registre-la, et pas celui des clefs, qui rend une reference
     * valide : voir {@see ref()}.
     */
    private function emettre(string $famille, ?string $clef): ?string
    {
        if ($clef !== null) {
            $this->emises[$famille][$clef] = true;
        }

        return $clef;
    }

    /**
     * La clef d'un objet PRESENT DANS LE DOCUMENT, ou `null`.
     *
     * ## Pourquoi le registre des clefs ne suffit PAS
     *
     * La premiere version interrogeait {@see ScenarioCaptureKeyRegistry::clefConnue()},
     * qui repond « cet objet A une clef » — jamais « cet objet EST dans le
     * document ». Les deux divergent des qu'un filtre de Capture ecarte une
     * ligne, et le resultat est une reference PENDANTE que le Validator refuse
     * par `REFERENCE_NOT_FOUND`.
     *
     * Trois cas mesures, tous atteignables par un geste produit ordinaire :
     * une sequence ARCHIVEE dont `CourseMaterialService::deleteSequence()`
     * conserve volontairement les progressions ; un Dossier en corbeille dont
     * les liens `dossier_blog_posts` survivent ; un objet ecarte par un filtre.
     *
     * Retirer une sequence qu'un apprenant a commencee rendait ainsi TOUTE la
     * sandbox non capturable. Trouve en relecture adverse.
     */
    private function ref(string $famille, ?string $entityId): ?string
    {
        if ($entityId === null) {
            return null;
        }

        $clef = $this->registre->clefConnue($famille, $entityId);

        return ($clef !== null && isset($this->emises[$famille][$clef])) ? $clef : null;
    }

    /**
     * Une reference OBLIGATOIRE : si elle ne se resout pas, on le DIT.
     *
     * Laisser tomber la ligne en silence fabriquerait un monde ampute sans que
     * personne ne sache pourquoi — exactement ce que la section « bornes »
     * interdit. Un objet indispensable au graphe et non reconstructible fait
     * echouer la Capture, avec son nom.
     */
    private function refObligatoire(string $famille, ?string $entityId, string $contexte, string $ou): ?string
    {
        $clef = $this->ref($famille, $entityId);

        if ($clef === null) {
            $this->bloquer($ou, 'reference_non_capturable', sprintf(
                '%s designe un objet de « %s » qui n est pas capturable.', $contexte, $famille
            ));
        }

        return $clef;
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

            // La garde `.test` est evaluee sur l'email RUNTIME, TOUJOURS.
            //
            // La premiere version ne la posait que sur un persona inconnu du
            // Manifest source : des que la stable key resolvait, l'email reel
            // n'etait plus regarde du tout. Or `AdminController::updateUser()`
            // laisse volontairement modifier un compte de sandbox SUR PLACE
            // tant que son Organization ne change pas — c'est ce qui rend
            // possible de corriger le nom d'un persona.
            //
            // Le chemin etait donc : editer `student-01` en y mettant un vrai
            // email, un vrai nom, une vraie bio ; puis capturer. Le Manifest
            // exportait l'identite REELLE sous l'email fictif de la source, et
            // le Validator passait puisque l'email declare finissait en
            // `.test`. Trouve en relecture adverse.
            //
            // La source ne sert donc qu'a CHOISIR la valeur declarable une fois
            // le compte prouve fictif — jamais a se dispenser de la preuve.
            if (! str_ends_with(mb_strtolower((string) $user->email), '.test')) {
                $this->bloquer('users', 'real_user', sprintf(
                    'Le compte « %s » porte un email qui n est pas de test : il ne peut pas etre exporte.',
                    $clef
                ));

                continue;
            }

            // L'email DECLARE ne se reconstruit jamais par manipulation de
            // chaine : le Loader ecrit `local@<slug>.<domaine>`, et la
            // transformation n'est pas inversible — on ne sait pas ou s'arrete
            // le slug insere, et le slug a pu changer depuis.
            $email = is_string($source['email'] ?? null) ? $source['email'] : (string) $user->email;

            $sortie[] = [
                'key' => $this->emettre('users', $clef),
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
                'key' => $this->emettre('loops', $clef),
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
            ->where('status', 'active')
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
        foreach ($loops as $i => $loop) {
            // La carte vient de `dossiers()`, qui connait le discriminant exact
            // (`root_blog_post_id`). La deduire de `parent === null` elisait
            // n'importe quel Dossier sans parent — y compris un enfant dont le
            // parent n'avait pas ete capture.
            $racine = $this->racinesParBoucle[$loop['key']] ?? null;

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
        //
        // `status` est filtre, et ce n'est pas cosmetique : `removeMember()` et
        // `leave()` ne suppriment pas la ligne, ils posent `status = 'left'`.
        // Sans ce filtre, qui a quitte une Boucle y revenait au re-Load —
        // `applyMemberships()` reecrivant `status = 'active'`. Pire : un
        // `owner` retire laissait DEUX memberships `owner` declares, ce que
        // l'invariant refuse. Trouve en relecture adverse.
        foreach ($this->requete(LoopMember::class)->where('status', 'active')->orderBy('created_at')->orderBy('id')->get() as $membre) {
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
        $racines = [];
        $parIdentifiant = [];
        $parentDe = [];

        $lignes = $this->requete(Dossier::class)
            // Les Dossiers SYSTEME et personnels ne sont pas declarables.
            //
            // En revanche `dossiers[].loop` est NULLABLE en V1 et
            // `applyDossiers()` materialise bien des Dossiers sans Boucle :
            // les ecarter faisait disparaitre du monde declarable des objets
            // parfaitement valides, et rendait pendantes les references de
            // leurs articles et fichiers. Trouve en relecture adverse.
            ->whereNull('system_role')
            ->orderBy('created_at')->orderBy('id')->get();

        foreach ($lignes as $dossier) {
            $clef = $this->clef('dossiers', (string) $dossier->id, (string) $dossier->name);
            $boucle = $dossier->loop_id === null ? null : $this->ref('loops', (string) $dossier->loop_id);

            if ($dossier->loop_id !== null && $boucle === null) {
                // La Boucle existe mais n'est pas capturable : on le DIT,
                // plutot que de laisser tomber le Dossier en silence.
                $this->bloquer('dossiers', 'reference_non_capturable', sprintf(
                    'Le Dossier « %s » appartient a une Boucle qui n est pas capturable.', $clef
                ));

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
                ?? ($boucle === null ? null : ($this->proprietaireDeBoucle[$boucle] ?? null));

            if ($proprietaire === null) {
                $this->bloquer('dossiers', 'owner_unresolved', sprintf(
                    'Le Dossier « %s » n a aucun proprietaire capturable.', $clef
                ));

                continue;
            }

            $ligne = [
                'key' => $this->emettre('dossiers', $clef),
                'name' => (string) $dossier->name,
                'owner' => $proprietaire,
                'loop' => $boucle,
                'parent' => $this->ref('dossiers', (string) ($dossier->parent_id ?? '')),
                'visibility' => $this->visibiliteDeDossier($dossier),
                'root_document' => null,
            ];

            if ($this->estUneRacine($dossier)) {
                $ligne['root_document'] = $this->documentRacine($dossier, $clef);
                $racines[$boucle] = $clef;
            }

            $sortie[] = $ligne;
            $parIdentifiant[(string) $dossier->id] = $clef;
            $parentDe[$clef] = (string) ($dossier->parent_id ?? '');
        }

        // Seconde passe pour `parent` — comme `applyDossiers()` le fait dans
        // l'autre sens : le manifeste n'impose aucun ordre topologique, donc
        // un parent peut etre emis APRES son enfant.
        foreach ($sortie as $i => $ligne) {
            $parent = $parentDe[$ligne['key']] ?? '';
            $sortie[$i]['parent'] = $parent === '' ? null : ($parIdentifiant[$parent] ?? null);
        }

        $this->racinesParBoucle = $racines;

        return $sortie;
    }

    /**
     * Le discriminant EXACT d'un espace documents de Boucle.
     *
     * `parent_id === null` ne suffit pas : un Dossier d'Organization sans
     * parent n'est pas une racine de Boucle, et le prendre pour tel ecrasait sa
     * visibilite declaree et pouvait l'elire racine a la place de la vraie.
     * `root_blog_post_id` est ce que `ensureRootDossier()` pose.
     */
    private function estUneRacine(Dossier $dossier): bool
    {
        return $dossier->loop_id !== null && $dossier->root_blog_post_id !== null;
    }

    private function visibiliteDeDossier(Dossier $dossier): string
    {
        // L'espace documents d'une Boucle est visible DE SA BOUCLE, et
        // `ManifestCoreInvariants::class` en fait un invariant :
        // « A root dossier has the visibility 'loop' ». La colonne runtime peut
        // dire autre chose ; c'est l'invariant qui fait foi, et le reconstruire
        // n'est pas un choix mais la lecture de la visibilite EFFECTIVE.
        if ($this->estUneRacine($dossier)) {
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
            // Format SOURCE s'il vient de la source, `html` sinon.
            //
            // T1652 forcait `html` ici, et la mesure etait juste A L'EPOQUE :
            // aucun applier n'ecrivait `root_document`, donc le document relu
            // etait toujours le gabarit produit de `initialContent()`, du HTML.
            // Lui coller le format declare aurait etiquete du HTML en
            // « markdown ».
            //
            // T1653 a change la PREMISSE, sur arbitrage MASTER : le Loader
            // applique desormais `title`, `author`, `format` et `content`
            // declares. Le document racine d'un Dossier venu de la source EST
            // donc l'objet source — et lui rendre son format n'est plus une
            // etiquette mensongere, c'est la seule lecture exacte.
            //
            // Un Dossier racine NOUVEAU, lui, porte toujours le gabarit
            // produit : `html`, par la meme preuve deterministe que les
            // articles.
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

        // ORDRE explicite : sans lui, l'ordre des articles — et donc
        // l'attribution des stable keys a la PREMIERE Capture — depend du
        // hasard du moteur. Deux articles homonymes pouvaient echanger
        // `note` et `note-2`.
        $liens = DB::table('dossier_blog_posts')
            ->where('organization_id', $this->sandbox->id)
            ->orderBy('dossier_id')->orderBy('position')->orderBy('id')
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

            if (! $post instanceof BlogPost) {
                continue;
            }

            if ($dossier === null) {
                $this->bloquer('articles', 'reference_non_capturable', sprintf(
                    'L article « %s » est place dans un Dossier qui n est pas capturable.', $post->title
                ));

                continue;
            }

            $clef = $this->clef('articles', (string) $post->id, (string) $post->title);
            $auteur = $this->ref('users', (string) ($post->user_id ?? ''));

            if ($auteur === null) {
                continue;
            }

            $source = $this->source('articles', $clef);

            $sortie[] = [
                'key' => $this->emettre('articles', $clef),
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
                'key' => $this->emettre('files', $this->clef('files', (string) $fichier->id, (string) ($fichier->display_name ?: $fichier->original_name))),
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

        // `order` n'a aucune colonne : il se RECALCULE, par Boucle.
        //
        // ## Pourquoi CHRONOLOGIQUE, et pas un parcours d'arbre
        //
        // La premiere version numerotait en parcours prefixe : une racine, puis
        // tout son fil de reponses, puis la racine suivante. Cela viole
        // `ManifestCoreInvariants::assertMessageTimeline()`, qui exige que
        // trier par `order` rende des `offset_minutes` NON DECROISSANTS dans
        // une Boucle.
        //
        // Deux fils entrelaces dans le temps — le comportement normal de
        // ChatLoop — suffisaient : A(10h00), B(10h10) racines, C(10h20) reponse
        // a A donnaient les ordres 1, 2, 3 pour les offsets 0, +20, +10. Le
        // document devenait invalide, et la sandbox non capturable.
        //
        // L'ordre chronologique satisfait les DEUX contraintes a la fois : les
        // offsets croissent par construction, et un parent precede toujours sa
        // reponse puisqu'on ne repond pas a un message qui n'existe pas encore.
        $parBoucle = [];
        $enAttente = [];

        foreach ($lignes as $message) {
            $boucle = $this->ref('loops', (string) $message->loop_id);
            $auteur = $this->ref('users', (string) ($message->sender_id ?? ''));

            if ($boucle === null || $auteur === null) {
                continue;
            }

            $clef = $this->emettre('messages', $this->clef('messages', (string) $message->id, 'message'));
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

        // Seconde passe : les reponses, maintenant que toutes les clefs
        // existent.
        $clefParId = [];

        foreach ($enAttente as $entree) {
            $clefParId[(string) $entree['modele']->id] = $entree['ligne']['key'];
        }

        $sortie = [];

        foreach ($enAttente as $entree) {
            $parent = (string) ($entree['modele']->reply_to_id ?? '');
            $entree['ligne']['reply_to'] = $clefParId[$parent] ?? null;

            $boucle = $entree['ligne']['loop'];

            // `order` est 0-BASE : le manifeste de reference declare 0, 1, 2
            // par Boucle. Numeroter a partir de 1 rendait les quatre messages
            // « modifies » sur une sandbox ou personne n'avait rien fait —
            // un faux positif qui aurait pollue chaque Preview.
            $entree['ligne']['order'] = $parBoucle[$boucle] ?? 0;
            $parBoucle[$boucle] = $entree['ligne']['order'] + 1;

            $sortie[] = $entree['ligne'];
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
                'key' => $this->emettre('categories', $this->clef('categories', (string) $categorie->id, (string) $categorie->name_b2c)),
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
                'key' => $this->emettre('skills', $this->clef('skills', (string) $skill->id, (string) $skill->name)),
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
                'key' => $this->emettre('service_requests', $clef),
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
                'key' => $this->emettre('services', $clef),
                'author' => $auteur,
                'title' => (string) $service->title,
                'description' => (string) $service->description,
                'category' => $categorie,
                // Le pivot REEL, pas un echo du document source.
                //
                // Contrairement a `highlight_in_loop` — qui n'a aucune
                // materialisation — les competences d'une Offre vivent dans
                // `service_skill`, et `applyServices()` y ecrit. Relire la
                // source rendait `skills: []` pour toute Offre creee dans la
                // sandbox, et figeait les autres.
                'skills' => $this->competencesDuService($service, $source),
                'delivery_mode' => (string) $service->delivery_mode,
                'points_cost' => (int) $service->points_cost,
                'status' => (string) $service->status,
                'highlight_in_loop' => is_string($source['highlight_in_loop'] ?? null) ? $source['highlight_in_loop'] : null,
            ];
        }

        return $sortie;
    }

    /**
     * @return list<string>
     */
    private function competencesDuService(Service $service, ?array $source): array
    {
        $clefs = [];

        // Borne par le SERVICE, qui est lui-meme borne a la sandbox.
        //
        // `service_skill` porte bien une colonne `organization_id`, mais
        // `syncWithoutDetaching()` ne la renseigne pas : filtrer dessus rendait
        // zero competence pour toutes les Offres. Le parent borne, et `ref()`
        // reverifie que la competence est bien dans le document.
        foreach (DB::table('service_skill')
            ->where('service_id', $service->id)
            ->orderBy('skill_id')
            ->get() as $lien) {
            $clef = $this->ref('skills', (string) $lien->skill_id);

            if ($clef !== null) {
                $clefs[] = $clef;
            }
        }

        return $this->ordonnerCommeLaSource($clefs, $source['skills'] ?? null);
    }

    /**
     * Range une liste de clefs dans l'ordre DECLARE par la source.
     *
     * L'ordre d'un tableau compte pour la comparaison canonique. Le trier sur
     * un UUID interne — ce que faisait la premiere version — produisait un
     * ordre sans rapport avec l'ordre declare : `["student-01","trainer-2"]`
     * ressortait `["trainer-2","student-01"]` parce que les UUID v7 suivent
     * l'ordre de CREATION. L'objet apparaissait `changed` des le chargement.
     *
     * Les clefs que la source ne connait pas viennent ensuite, triees, pour
     * rester deterministes.
     *
     * @param  list<string>  $clefs
     * @return list<string>
     */
    private function ordonnerCommeLaSource(array $clefs, mixed $ordreSource): array
    {
        if (! is_array($ordreSource)) {
            sort($clefs);

            return $clefs;
        }

        $rang = array_flip(array_values(array_filter($ordreSource, 'is_string')));
        $connues = [];
        $neuves = [];

        foreach ($clefs as $clef) {
            isset($rang[$clef]) ? $connues[$clef] = $rang[$clef] : $neuves[] = $clef;
        }

        asort($connues);
        sort($neuves);

        return [...array_keys($connues), ...$neuves];
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

            // L'identite des options : le registre, puis le rang CORROBORE.
            //
            // ## Pourquoi le rang seul ne suffit pas
            //
            // `applyPolls()` cree les options dans l'ordre declare : la
            // correspondance par rang est exacte A L'INSTANT DU LOAD, et
            // fausse ensuite. Supprimer ou reordonner une option decale les
            // rangs, et la clef d'une option est alors attribuee a une AUTRE —
            // les votes suivant la permutation. Le document reste coherent
            // avec lui-meme, donc le Validator passe : la permutation ne se
            // voit qu'au rechargement. Trouve en relecture adverse.
            //
            // ## Pourquoi pas le registre MOTEUR
            //
            // `loop_poll_options` ne porte aucun `organization_id`, et le
            // `ScenarioPackEntityRegistrar` refuse — a juste titre — d'inscrire
            // une entite dont il ne peut pas verifier le tenant. Les options ne
            // peuvent donc pas etre tracees au Load.
            //
            // ## Ce qu'on fait a la place
            //
            // 1. le registre de CAPTURE d'abord : des la premiere Capture,
            //    l'identite est figee par entite et ne bouge plus ;
            // 2. sinon le rang, mais CORROBORE par le libelle. Si les libelles
            //    ne correspondent plus a ceux de la source, on refuse d'amorcer
            //    et on genere des clefs neuves : les options apparaissent alors
            //    comme modifiees — VISIBLE pour l'operateur — au lieu d'etre
            //    permutees en silence. Le libelle ne sert pas a attribuer une
            //    identite : il sert a refuser de le faire.
            $optionsSource = $this->source('polls', $this->registre->clefConnue('polls', (string) $sondage->id) ?? '')['options'] ?? [];
            $rangs = array_values($options->all());

            $corrobore = count($optionsSource) === count($rangs);

            foreach ($rangs as $rang => $option) {
                if ($corrobore && (string) ($optionsSource[$rang]['label'] ?? null) !== (string) $option->label) {
                    $corrobore = false;
                }
            }

            foreach ($rangs as $rang => $option) {
                $clefSource = $corrobore ? ($optionsSource[$rang]['key'] ?? null) : null;

                $clefOption = $this->registre->clefConnue('poll_options', (string) $option->id)
                    ?? (is_string($clefSource) && $clefSource !== ''
                        ? $this->registre->clefDe('poll_options', (string) $option->id, $clefSource)
                        : $this->clef('poll_options', (string) $option->id, (string) $option->label));

                $clefOption = $this->emettre('poll_options', $clefOption);
                $clefParOption[(string) $option->id] = $clefOption;
                $declarees[] = ['key' => $clefOption, 'label' => (string) $option->label];
            }

            $sortie[] = [
                'key' => $this->emettre('polls', $this->clef('polls', (string) $sondage->id, (string) $sondage->question)),
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
            ->orderBy('option_id')
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

            // `ends_at` est NULLABLE et le produit admet un evenement sans fin.
            // `duration_minutes` est pourtant obligatoire en V1 : inventer 60
            // donnerait a l'evenement, au re-Load, une fin qu'il n'avait pas.
            // On refuse, on ne devine pas.
            if ($debut === null || $fin === null) {
                $this->bloquer('events', 'duree_inconnue', sprintf(
                    'L evenement « %s » n a pas de fin : sa duree ne peut pas etre declaree sans l inventer.',
                    $evenement->title
                ));

                continue;
            }

            $duree = (int) round((strtotime((string) $fin) - strtotime((string) $debut)) / 60);

            $sortie[] = [
                'key' => $this->emettre('events', $this->clef('events', (string) $evenement->id, (string) $evenement->title)),
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
                'key' => $this->emettre('decisions', $this->clef('decisions', (string) $decision->id, (string) $decision->title)),
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

            foreach (DB::table('loop_roadmap_item_user')
                ->where('loop_roadmap_item_id', $item->id)
                ->orderBy('user_id')->get() as $lien) {
                $user = $this->ref('users', (string) $lien->user_id);

                if ($user !== null) {
                    $assignes[] = $user;
                }
            }

            $clefItem = $this->emettre('roadmap_items', $this->clef('roadmap_items', (string) $item->id, (string) $item->title));
            $sourceItem = $this->source('roadmap_items', $clefItem);

            $sortie[] = [
                'key' => $clefItem,
                'loop' => $boucle,
                'created_by' => $auteur,
                'title' => (string) $item->title,
                'description' => $item->description,
                'status' => (string) $item->status,
                'position' => (int) $item->position,
                'assignees' => $this->ordonnerCommeLaSource($assignes, $sourceItem['assignees'] ?? null),
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
                'key' => $this->emettre('training.modules', $this->clef('training.modules', (string) $module->id, (string) $module->title)),
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
            $module = $this->refObligatoire(
                'training.modules', (string) $sequence->course_module_id,
                sprintf('La sequence « %s »', $sequence->title), 'training.sequences'
            );
            $auteur = $this->refObligatoire(
                'users', (string) ($sequence->created_by ?? ''),
                sprintf('La sequence « %s »', $sequence->title), 'training.sequences'
            );

            if ($module === null || $auteur === null) {
                continue;
            }

            $clef = $this->clef('training.sequences', (string) $sequence->id, (string) $sequence->title);
            $source = $this->source('training.sequences', $clef);

            $sortie[] = [
                'key' => $this->emettre('training.sequences', $clef),
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
            $sequence = $this->refObligatoire(
                'training.sequences', (string) $avancee->course_sequence_id,
                'Une progression', 'training.progress'
            );
            $user = $this->refObligatoire('users', (string) $avancee->user_id, 'Une progression', 'training.progress');

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
                'key' => $this->emettre('training.assignments', $this->clef('training.assignments', (string) $devoir->id, (string) $devoir->title)),
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
            $devoir = $this->refObligatoire(
                'training.assignments', (string) $rendu->course_assignment_id,
                'Une remise', 'training.submissions'
            );
            $user = $this->refObligatoire('users', (string) $rendu->user_id, 'Une remise', 'training.submissions');

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
