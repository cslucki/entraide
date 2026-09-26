<?php

namespace App\Support\ScenarioPacks\Manifest;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopDecisionService;
use App\Services\Loops\LoopEventService;
use App\Services\Loops\LoopPollService;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * TASK-1643 — materialise les familles CORE non-Training d'un Manifest V1.
 *
 * Collaborateur de {@see ManifestScenarioPack}, qui garde la charge du socle
 * (users, Loops, memberships, Dossiers racines). La separation n'est pas
 * cosmetique : le socle etait le contrat de T1642 et ses garanties sont deja
 * revues ; CORE est un ajout, et on doit pouvoir le lire — et le retirer —
 * sans relire le socle.
 *
 * ## Deux regles qui gouvernent tout ce fichier
 *
 * 1. **Le service metier canonique quand il existe.** Messages, sondages,
 *    evenements et decisions passent par `LoopMessageService`,
 *    `LoopPollService`, `LoopEventService`, `LoopDecisionService` : ce sont eux
 *    qui portent les permissions, les validations et les effets derives du
 *    produit. Les familles sans service (Dossiers, articles, fichiers,
 *    categories, skills, marketplace, roadmap) sont ecrites avec des champs
 *    NOMMES un par un — jamais un mass assignment du JSON.
 *
 * 2. **L'idempotence vient du REGISTRE, pas d'une devinette.** Aucun de ces
 *    services n'est idempotent : `sendUserMessage()` cree un message a chaque
 *    appel. Avant de creer, on demande donc au registre si CE pack a deja
 *    produit cette stable key dans cette Organization. C'est la seule source
 *    d'identite : ni le titre, ni le slug, ni une date ne sont des cles.
 *
 * ## Le temps
 *
 * La spec 6.3 impose un unique `load_started_at` capture au Load, dont tous
 * les offsets derivent. Depuis T1644 il est capture par
 * `ManifestScenarioPack` et INJECTE ici, parce qu'il appartient au CHARGEMENT
 * et non a cet applier : deux colleagues (CORE et TRAINING) en derivent des
 * offsets dans le meme passage. Chacun capturant le sien, une remise declaree
 * au meme offset qu'un message ne tomberait plus au meme instant que lui, et
 * la spec 6.3 serait violee en silence.
 */
class ManifestCoreApplier
{
    /** Disque des fichiers de Dossier, convention du produit. */
    private const FILE_DISK = 'dossier_files';

    public function __construct(
        private readonly ScenarioManifest $manifest,
        private readonly string $packId,
        private readonly \DateTimeImmutable $loadStartedAt,
    ) {}

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Loop>  $loops
     * @return array{articles: array<string, BlogPost>, files: array<string, DossierFile>}
     */
    public function apply(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $loops,
    ): array {
        $dossiers = $this->applyDossiers($organization, $registrar, $users, $loops);
        $articles = $this->applyArticles($organization, $registrar, $users, $dossiers);
        $files = $this->applyFiles($organization, $registrar, $users, $dossiers);
        $messages = $this->applyMessages($organization, $registrar, $users, $loops);

        $categories = $this->applyCategories($organization, $registrar);
        $skills = $this->applySkills($organization, $registrar, $categories);
        $this->applyServiceRequests($organization, $registrar, $users, $categories);
        $this->applyServices($organization, $registrar, $users, $categories, $skills);

        $this->applyPolls($organization, $registrar, $users, $loops);
        $this->applyEvents($organization, $registrar, $users, $loops);
        $decisions = $this->applyDecisions($organization, $registrar, $users, $loops, $messages);
        $this->applyRoadmapItems($organization, $registrar, $users, $loops, $decisions);

        // T1644 — les Sequences Training referencent un article ou un fichier
        // (spec 12.2). On rend les deux index plutot que de les jeter :
        // les retrouver par le registre demanderait une requete par reference,
        // et surtout une SECONDE source d'identite pour des objets que ce
        // passage vient de produire.
        return ['articles' => $articles, 'files' => $files];
    }

    // =====================================================================
    // Dossiers
    // =====================================================================

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Loop>  $loops
     * @return array<string, Dossier>
     */
    private function applyDossiers(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $loops,
    ): array {
        $rootDossierKeys = [];

        foreach ($this->manifest->collection('loops') as $loop) {
            $rootDossierKeys[(string) $loop->root_dossier] = (string) $loop->key;
        }

        $dossiers = [];

        // Premiere passe : creer ou retrouver, SANS parent. Le manifeste
        // n'impose aucun ordre topologique (spec 8.2 le demande au Validator,
        // pas au producteur), donc un enfant peut etre declare avant son
        // parent.
        foreach ($this->manifest->collection('dossiers') as $declared) {
            $key = (string) $declared->key;

            if (array_key_exists($key, $rootDossierKeys)) {
                // Dossier racine : deja cree par la primitive canonique de
                // T1642. On le retrouve, on ne le recree pas.
                $loop = $loops[$rootDossierKeys[$key]] ?? null;

                if ($loop !== null) {
                    $dossiers[$key] = app(LoopRootDocumentService::class)->ensureRootDossier($loop);
                }

                continue;
            }

            $existing = $this->findTracked($organization, 'manifest_dossier', $key);

            $dossiers[$key] = $existing instanceof Dossier ? $existing : Dossier::query()->create([
                'organization_id' => $organization->id,
                'owner_id' => $users[(string) $declared->owner]->id ?? null,
                'loop_id' => is_string($declared->loop ?? null) ? ($loops[$declared->loop]->id ?? null) : null,
                'name' => (string) $declared->name,
                'visibility' => $this->dossierVisibility((string) $declared->visibility),
            ]);

            $registrar->track('manifest_dossier', $key, $dossiers[$key]);
        }

        // Seconde passe : les parents, maintenant que tout existe.
        foreach ($this->manifest->collection('dossiers') as $declared) {
            $key = (string) $declared->key;
            $parent = $declared->parent ?? null;

            if (! is_string($parent) || ! isset($dossiers[$key], $dossiers[$parent])) {
                continue;
            }

            $dossier = $dossiers[$key];

            if ($dossier->parent_id !== $dossiers[$parent]->id) {
                $dossier->forceFill(['parent_id' => $dossiers[$parent]->id])->save();
            }
        }

        return $dossiers;
    }

    /**
     * Les trois visibilites du manifeste ont chacune leur equivalent CANONIQUE
     * dans le produit ; la correspondance est explicite, sans `default`
     * fourre-tout.
     *
     * `shared` n'en fait PAS partie : c'est une valeur historique, marquee
     * `@deprecated` sur le modele, absente de `Dossier::VISIBILITIES`, et que
     * la `DossierPolicy` ne reconnait pas. Un Dossier declare `organization`
     * mais stocke `shared` aurait donc ete invisible pour l'Organization
     * entiere — un monde charge qui ne montre pas ce que le document a promis.
     */
    private function dossierVisibility(string $declared): string
    {
        return match ($declared) {
            'organization' => Dossier::VISIBILITY_ORGANIZATION,
            'loop' => Dossier::VISIBILITY_LOOP,
            default => Dossier::VISIBILITY_PRIVATE,
        };
    }

    // =====================================================================
    // Articles
    // =====================================================================

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Dossier>  $dossiers
     * @return array<string, BlogPost>
     */
    private function applyArticles(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $dossiers,
    ): array {
        $articles = [];

        foreach ($this->manifest->collection('articles') as $declared) {
            $key = (string) $declared->key;
            $dossier = $dossiers[(string) $declared->dossier] ?? null;
            $author = $users[(string) $declared->author] ?? null;

            if ($dossier === null || $author === null) {
                continue;
            }

            $existing = $this->findTracked($organization, 'manifest_article', $key);

            if ($existing instanceof BlogPost) {
                $registrar->track('manifest_article', $key, $existing);

                $placement = $this->findTracked($organization, 'manifest_article_placement', $key);

                if ($placement instanceof DossierBlogPost) {
                    $registrar->track('manifest_article_placement', $key, $placement);
                }

                $articles[$key] = $existing;

                continue;
            }

            $published = $declared->status === 'published';

            $article = BlogPost::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $author->id,
                'title' => (string) $declared->title,
                'slug' => $this->uniqueSlug('blog_posts', (string) $declared->title),
                'summary' => $declared->summary,
                'content' => $this->renderedContent((string) $declared->content, (string) $declared->format),
                'status' => $published ? 'published' : 'draft',
                'published_at' => $published ? $this->fromOffsetMinutes($declared->published_offset_minutes) : null,
                'audience' => (string) $declared->audience,
                // Un article de manifeste vit dans son Dossier, pas dans le
                // blog public : le lister serait une decision editoriale que le
                // document ne prend pas.
                'listed_in_blog' => false,
            ]);

            $registrar->track('manifest_article', $key, $article);

            // Le rattachement au Dossier est une ENTITE a part entiere
            // (`dossier_blog_posts` porte `organization_id`) : l'inscrire rend
            // le retrait complet.
            $entry = DossierBlogPost::query()->create([
                'organization_id' => $organization->id,
                'dossier_id' => $dossier->id,
                'blog_post_id' => $article->id,
                'added_by' => $author->id,
                'position' => 0,
            ]);

            $registrar->track('manifest_article_placement', $key, $entry);

            if ($dossier->loop_id !== null) {
                $article->loops()->syncWithoutDetaching([$dossier->loop_id]);
            }

            $articles[$key] = $article;
        }

        return $articles;
    }

    // =====================================================================
    // Fichiers inline
    // =====================================================================

    /**
     * Le manifeste ne declare QUE un nom, un type et un contenu inline
     * (spec 7.4). Disque, chemin, taille et SHA-256 sont DERIVES ici : accepter
     * un chemin du document serait lui laisser designer un emplacement de
     * stockage.
     *
     * @param  array<string, User>  $users
     * @param  array<string, Dossier>  $dossiers
     * @return array<string, DossierFile>
     */
    private function applyFiles(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $dossiers,
    ): array {
        $files = [];

        foreach ($this->manifest->collection('files') as $declared) {
            $key = (string) $declared->key;
            $dossier = $dossiers[(string) $declared->dossier] ?? null;
            $uploader = $users[(string) $declared->uploaded_by] ?? null;

            if ($dossier === null || $uploader === null) {
                continue;
            }

            $existing = $this->findTracked($organization, 'manifest_file', $key);

            if ($existing instanceof DossierFile) {
                $registrar->track('manifest_file', $key, $existing);
                $files[$key] = $existing;

                continue;
            }

            $content = (string) $declared->content;

            // Basename du manifeste, DERIVE en chemin sur un emplacement que
            // BouclePro choisit. `basename()` est une seconde ceinture : le
            // Validator refuse deja tout separateur et tout `..`.
            $name = basename((string) $declared->name);
            $path = 'dossier-files/'.$dossier->id.'/'.Str::uuid()->toString().'-'.$name;

            // Un fichier preexistant n'est JAMAIS ecrase (garde TASK-1245).
            $registrar->assertStoragePathAvailable('manifest_file', $key, self::FILE_DISK, $path);

            Storage::disk(self::FILE_DISK)->put($path, $content);

            $file = DossierFile::query()->create([
                'organization_id' => $organization->id,
                'dossier_id' => $dossier->id,
                'uploaded_by' => $uploader->id,
                'disk' => self::FILE_DISK,
                'path' => $path,
                'original_name' => $name,
                'display_name' => $name,
                'mime_type' => (string) $declared->media_type,
                'size_bytes' => strlen($content),
                'checksum_sha256' => hash('sha256', $content),
                'source' => 'upload',
            ]);

            $registrar->track('manifest_file', $key, $file);

            $files[$key] = $file;
        }

        return $files;
    }

    // =====================================================================
    // Messages ChatLoop
    // =====================================================================

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Loop>  $loops
     */
    private function applyMessages(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $loops,
    ): array {
        $declaredMessages = $this->manifest->collection('messages');

        // `order` porte l'ordre metier, pas la position dans le tableau
        // (spec 6.3) : les replies exigent que le parent existe deja.
        usort($declaredMessages, static fn (object $a, object $b): int => $a->order <=> $b->order);

        $sent = [];

        foreach ($declaredMessages as $declared) {
            $key = (string) $declared->key;
            $loop = $loops[(string) $declared->loop] ?? null;
            $author = $users[(string) $declared->author] ?? null;

            if ($loop === null || $author === null) {
                continue;
            }

            $existing = $this->findTracked($organization, 'manifest_message', $key);

            if ($existing instanceof LoopMessage) {
                // Inscrire MEME au rejeu. Le resetter purge ce qui est
                // enregistre mais n'a pas ete declare par LE passage courant :
                // sauter `track()` parce que l'entite existe deja la ferait
                // donc DISPARAITRE au premier reset.
                $registrar->track('manifest_message', $key, $existing);
                $sent[$key] = $existing;

                continue;
            }

            $replyTo = is_string($declared->reply_to ?? null) ? ($sent[$declared->reply_to] ?? null) : null;

            $message = app(LoopMessageService::class)->sendUserMessage(
                $loop,
                $author,
                (string) $declared->body,
                null,
                $replyTo?->id,
            );

            // Le service ancre le message a `now()`. La demonstration exige
            // l'instant RELATIF declare, sinon toute la conversation se tasse
            // sur une seule seconde. `saveQuietly` pour ne pas rejouer les
            // evenements du message.
            $message->forceFill(['created_at' => $this->fromOffsetMinutes($declared->offset_minutes)])->saveQuietly();

            $registrar->track('manifest_message', $key, $message);

            $sent[$key] = $message;
        }

        // TASK-1647 — la carte est desormais RENDUE, pour que `decision.message`
        // puisse resoudre sa reference. Elle est complete meme au rejeu : la
        // branche `$existing` ci-dessus y inscrit les messages preexistants.
        return $sent;
    }

    // =====================================================================
    // Entraide : categories, skills, demandes, offres
    // =====================================================================

    /**
     * @return array<string, Category>
     */
    private function applyCategories(Organization $organization, ScenarioPackEntityRegistrar $registrar): array
    {
        $categories = [];

        foreach ($this->manifest->collection('categories') as $declared) {
            $key = (string) $declared->key;
            $existing = $this->findTracked($organization, 'manifest_category', $key);

            $categories[$key] = $existing instanceof Category ? $existing : Category::query()->create([
                'organization_id' => $organization->id,
                // Il n'existe pas de colonne `name` : le produit porte DEUX
                // variantes de libelle, B2C et B2B, et `name_b2b` est NOT NULL.
                // Le manifeste n'en declare qu'une (spec 7.6) — les deux
                // recoivent donc la meme valeur, plutot que d'inventer une
                // seconde formulation que le document n'a pas approuvee.
                'name_b2c' => (string) $declared->name,
                'name_b2b' => (string) $declared->name,
                'slug' => $this->uniqueSlug('categories', (string) $declared->name),
                'color' => (string) $declared->color,
            ]);

            $registrar->track('manifest_category', $key, $categories[$key]);
        }

        return $categories;
    }

    /**
     * @param  array<string, Category>  $categories
     * @return array<string, Skill>
     */
    private function applySkills(Organization $organization, ScenarioPackEntityRegistrar $registrar, array $categories): array
    {
        $skills = [];

        foreach ($this->manifest->collection('skills') as $declared) {
            $key = (string) $declared->key;
            $category = $categories[(string) $declared->category] ?? null;

            if ($category === null) {
                continue;
            }

            $existing = $this->findTracked($organization, 'manifest_skill', $key);

            $skills[$key] = $existing instanceof Skill ? $existing : Skill::query()->create([
                'organization_id' => $organization->id,
                'category_id' => $category->id,
                'name' => (string) $declared->name,
                'slug' => $this->uniqueSlug('skills', (string) $declared->name),
            ]);

            $registrar->track('manifest_skill', $key, $skills[$key]);
        }

        return $skills;
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Category>  $categories
     */
    private function applyServiceRequests(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $categories,
    ): void {
        foreach ($this->manifest->collection('service_requests') as $declared) {
            $key = (string) $declared->key;
            $author = $users[(string) $declared->author] ?? null;
            $category = $categories[(string) $declared->category] ?? null;

            if ($author === null || $category === null) {
                continue;
            }

            $existing = $this->findTracked($organization, 'manifest_service_request', $key);

            $request = $existing instanceof ServiceRequest ? $existing : ServiceRequest::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $author->id,
                'title' => (string) $declared->title,
                'description' => (string) $declared->description,
                'category_id' => $category->id,
                'delivery_mode' => (string) $declared->delivery_mode,
                'budget_min' => (int) $declared->budget_min,
                'budget_max' => $declared->budget_max,
                'deadline' => $this->fromDayOffset($declared->deadline_day_offset),
                'status' => (string) $declared->status,
            ]);

            $registrar->track('manifest_service_request', $key, $request);

            // `highlight_in_loop` n'est PAS materialise : voir le TASK file.
            // Le seul mecanisme produit existant est une projection ChatLoop,
            // que la spec 7.6 exclut explicitement.
        }
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Category>  $categories
     * @param  array<string, Skill>  $skills
     */
    private function applyServices(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $categories,
        array $skills,
    ): void {
        foreach ($this->manifest->collection('services') as $declared) {
            $key = (string) $declared->key;
            $author = $users[(string) $declared->author] ?? null;
            $category = $categories[(string) $declared->category] ?? null;

            if ($author === null || $category === null) {
                continue;
            }

            $existing = $this->findTracked($organization, 'manifest_service', $key);

            $service = $existing instanceof Service ? $existing : Service::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $author->id,
                'title' => (string) $declared->title,
                'description' => (string) $declared->description,
                'category_id' => $category->id,
                'delivery_mode' => (string) $declared->delivery_mode,
                'points_cost' => (int) $declared->points_cost,
                'status' => (string) $declared->status,
            ]);

            $registrar->track('manifest_service', $key, $service);

            $declaredSkills = is_array($declared->skills ?? null) ? $declared->skills : [];
            $skillIds = [];

            foreach ($declaredSkills as $skillKey) {
                if (isset($skills[(string) $skillKey])) {
                    $skillIds[] = $skills[(string) $skillKey]->id;
                }
            }

            if ($skillIds !== []) {
                $service->skills()->syncWithoutDetaching($skillIds);
            }
        }
    }

    // =====================================================================
    // Collaboration : sondages, evenements, decisions, roadmap
    // =====================================================================

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Loop>  $loops
     */
    private function applyPolls(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $loops,
    ): void {
        $service = app(LoopPollService::class);

        foreach ($this->manifest->collection('polls') as $declared) {
            $key = (string) $declared->key;
            $loop = $loops[(string) $declared->loop] ?? null;
            $author = $users[(string) $declared->author] ?? null;

            if ($loop === null || $author === null) {
                continue;
            }

            $existing = $this->findTracked($organization, 'manifest_poll', $key);

            if ($existing instanceof LoopPoll) {
                $registrar->track('manifest_poll', $key, $existing);

                continue;
            }

            $this->ensureCard($loop, 'core.polls');

            $options = is_array($declared->options ?? null) ? $declared->options : [];
            $labels = array_map(static fn (object $option): string => (string) $option->label, $options);

            $poll = $service->create(
                $author,
                $loop,
                (string) $declared->question,
                $declared->description,
                (string) $declared->selection_type,
                $labels,
            );

            $registrar->track('manifest_poll', $key, $poll);

            // Les cles d'options du manifeste sont LOCALES au document : elles
            // servent a resoudre les votes, elles ne sont pas stockees. La
            // correspondance se fait par position, l'ordre des labels passes au
            // service etant celui du manifeste.
            $optionIdsByKey = [];
            $created = $poll->options()->orderBy('position')->get();

            foreach ($options as $index => $option) {
                if (isset($created[$index])) {
                    $optionIdsByKey[(string) $option->key] = $created[$index]->id;
                }
            }

            $this->applyPollVotes($service, $poll, $loop, $users, $declared, $optionIdsByKey);

            // TASK-1647 — fidelite : un poll declare `closed` doit etre
            // REELLEMENT clos. L'ordre n'est pas un detail, la spec l'impose :
            // « un poll closed est clos par l'auteur lors du chargement APRES
            // creation des votes ». Clore avant de voter ferait refuser les
            // votes par le service, et le monde charge ne porterait ni les
            // voix declarees ni la cloture.
            //
            // `close()` pose `status`, `closed_at` et `closed_by` sous
            // transaction, et ne se plaint pas d'une seconde cloture : le
            // rejeu du meme manifeste est donc sans effet supplementaire.
            if ((string) ($declared->status ?? '') === 'closed') {
                $service->close($author, $poll, $loop);
            }
        }
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, string>  $optionIdsByKey
     */
    private function applyPollVotes(
        LoopPollService $service,
        LoopPoll $poll,
        Loop $loop,
        array $users,
        object $declared,
        array $optionIdsByKey,
    ): void {
        foreach (is_array($declared->votes ?? null) ? $declared->votes : [] as $vote) {
            $voter = $users[(string) $vote->user] ?? null;

            if ($voter === null) {
                continue;
            }

            $optionIds = [];

            foreach (is_array($vote->options ?? null) ? $vote->options : [] as $optionKey) {
                if (isset($optionIdsByKey[(string) $optionKey])) {
                    $optionIds[] = $optionIdsByKey[(string) $optionKey];
                }
            }

            if ($optionIds !== []) {
                $service->vote($voter, $poll, $loop, $optionIds);
            }
        }
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Loop>  $loops
     */
    private function applyEvents(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $loops,
    ): void {
        $service = app(LoopEventService::class);

        foreach ($this->manifest->collection('events') as $declared) {
            $key = (string) $declared->key;
            $loop = $loops[(string) $declared->loop] ?? null;
            $author = $users[(string) $declared->author] ?? null;

            if ($loop === null || $author === null) {
                continue;
            }

            $existingEvent = $this->findTracked($organization, 'manifest_event', $key);

            if ($existingEvent instanceof LoopEvent) {
                $registrar->track('manifest_event', $key, $existingEvent);

                continue;
            }

            $this->ensureCard($loop, 'core.events');

            $timezone = (string) $declared->timezone;
            $startsAt = $this->fromOffsetMinutes($declared->starts_offset_minutes);
            $endsAt = $startsAt?->modify('+'.(int) $declared->duration_minutes.' minutes');

            $event = $service->create($author, $loop, [
                'title' => (string) $declared->title,
                'description' => $declared->description,
                'format' => (string) $declared->format,
                // Le service relit ces chaines DANS le fuseau de l'evenement
                // puis les convertit en UTC. Nos instants sont ancres en UTC :
                // il faut donc leur donner leur horloge LOCALE, sinon un
                // atelier a 14h00 Europe/Paris serait enregistre a 16h00.
                'starts_at' => $this->wallClock($startsAt, $timezone),
                // Le manifeste declare une DUREE ; la table stocke une fin.
                'ends_at' => $this->wallClock($endsAt, $timezone),
                'timezone' => $timezone,
                'location' => $declared->location,
                'meeting_url' => $declared->meeting_url,
                'visibility' => (string) $declared->visibility,
            ]);

            $registrar->track('manifest_event', $key, $event);

            foreach (is_array($declared->responses ?? null) ? $declared->responses : [] as $response) {
                $responder = $users[(string) $response->user] ?? null;

                if ($responder !== null) {
                    $service->respond($responder, $event, $loop, (string) $response->response);
                }
            }

            // TASK-1647 — fidelite : un event declare `cancelled` doit etre
            // REELLEMENT annule, et la spec impose le meme ordre que pour les
            // polls : « les reponses sont appliquees AVANT une eventuelle
            // annulation ». Un evenement annule n'accepte plus de reponse.
            //
            // `cancel()` est idempotent : il rend `changed => false` quand
            // l'evenement est deja annule.
            if ((string) ($declared->status ?? '') === 'cancelled') {
                $service->cancel($author, $event, $loop);
            }
        }
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Loop>  $loops
     * @return array<string, LoopDecision>
     */
    private function applyDecisions(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $loops,
        array $messages,
    ): array {
        $service = app(LoopDecisionService::class);
        $decisions = [];

        foreach ($this->manifest->collection('decisions') as $declared) {
            $key = (string) $declared->key;
            $loop = $loops[(string) $declared->loop] ?? null;
            $author = $users[(string) $declared->author] ?? null;

            if ($loop === null || $author === null) {
                continue;
            }

            $existing = $this->findTracked($organization, 'manifest_decision', $key);

            $this->ensureCard($loop, 'core.decisions');

            if ($existing instanceof LoopDecision) {
                $decisions[$key] = $existing;
            } else {
                $title = (string) $declared->title;
                $rationale = $declared->rationale;
                $date = $this->fromDayOffset($declared->decided_day_offset)?->format('Y-m-d');

                // TASK-1647 — fidelite : `decision.message` declare la
                // CONVERSATION d'ou la decision est sortie. `record()` ne pose
                // jamais `loop_message_id` : la reference etait perdue, et le
                // monde charge presentait une decision sans origine.
                //
                // `promote()` est la primitive canonique de ce geste. Elle
                // relie la decision au message DEJA charge — elle n'en cree
                // aucun second, ce qui inventerait une parole que personne n'a
                // prononcee — et elle refuse d'elle-meme un message d'une
                // autre Boucle. La carte vient du passage courant, donc la
                // resolution reste bornee a la sandbox chargee.
                $messageKey = is_string($declared->message ?? null) ? $declared->message : null;
                $message = $messageKey !== null ? ($messages[$messageKey] ?? null) : null;

                $decisions[$key] = $message instanceof LoopMessage
                    ? $service->promote($loop, $author, $message, $title, $rationale, $date)
                    : $service->record($loop, $author, $title, $rationale, $date);
            }

            $registrar->track('manifest_decision', $key, $decisions[$key]);
        }

        // Les remplacements en second temps : une decision peut en remplacer
        // une declaree apres elle dans le tableau.
        foreach ($this->manifest->collection('decisions') as $declared) {
            $supersedes = $declared->supersedes ?? null;
            $key = (string) $declared->key;

            if (is_string($supersedes) && isset($decisions[$key], $decisions[$supersedes])) {
                $service->supersede($decisions[$supersedes], $decisions[$key]);
            }
        }

        return $decisions;
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Loop>  $loops
     * @param  array<string, LoopDecision>  $decisions
     */
    private function applyRoadmapItems(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        array $users,
        array $loops,
        array $decisions,
    ): void {
        foreach ($this->manifest->collection('roadmap_items') as $declared) {
            $key = (string) $declared->key;
            $loop = $loops[(string) $declared->loop] ?? null;
            $creator = $users[(string) $declared->created_by] ?? null;

            if ($loop === null || $creator === null) {
                continue;
            }

            $existing = $this->findTracked($organization, 'manifest_roadmap_item', $key);

            $this->ensureCard($loop, 'core.roadmap');

            $decision = is_string($declared->decision ?? null) ? ($decisions[$declared->decision] ?? null) : null;

            $item = $existing instanceof LoopRoadmapItem ? $existing : LoopRoadmapItem::query()->create([
                'organization_id' => $organization->id,
                'loop_id' => $loop->id,
                'created_by' => $creator->id,
                'title' => (string) $declared->title,
                'description' => $declared->description,
                'status' => (string) $declared->status,
                'position' => (int) $declared->position,
                'due_at' => $this->fromDayOffset($declared->due_day_offset),
                'loop_decision_id' => $decision?->id,
            ]);

            $registrar->track('manifest_roadmap_item', $key, $item);

            $assigneeIds = [];

            foreach (is_array($declared->assignees ?? null) ? $declared->assignees : [] as $assignee) {
                if (isset($users[(string) $assignee])) {
                    $assigneeIds[] = $users[(string) $assignee]->id;
                }
            }

            if ($assigneeIds !== []) {
                $item->assignees()->syncWithoutDetaching($assigneeIds);
            }
        }
    }

    // =====================================================================
    // Outils
    // =====================================================================

    /**
     * Active la Card canonique d'une famille sur une Boucle.
     *
     * Spec 7.7 : "La presence d'un objet implique l'activation de sa Card
     * canonique sur la Loop si le preset du type ne l'a pas deja activee.
     * Cette activation est DERIVEE [...] realisee par la primitive de
     * composition existante ; le JSON ne declare ni cle de Card ni
     * configuration arbitraire."
     *
     * Ce n'est pas une commodite : sans la Card, `LoopPollService::create()`
     * refuse la creation (`polls.error_not_allowed`), parce que le produit
     * refuse d'ecrire dans un espace que le membre ne peut pas voir. La cle est
     * fixee ICI, en dur, par famille — elle ne vient jamais du document.
     *
     * `enable()` est idempotent : il rallume une ligne existante sans perdre
     * son origine de preset.
     */
    private function ensureCard(Loop $loop, string $cardKey): void
    {
        app(LoopCardCompositionService::class)->enable($loop, $cardKey);
    }

    /**
     * L'entite que CE pack a deja produite pour cette stable key, ou `null`.
     *
     * Le registre est la SEULE source d'identite d'un objet de manifeste : ni
     * le titre, ni le slug, ni une date ne sont des cles naturelles fiables —
     * deux Boucles peuvent porter le meme titre d'article, et un slug est
     * derive donc modifiable.
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

        // `withoutGlobalScopes()` est indispensable, et ce n'est pas une
        // precaution de confort : `ServiceRequest` et `Service` portent
        // `BelongsToOrganizationScope`. Un chargement de pack tourne SANS
        // contexte tenant authentifie, donc ce scope les rend invisibles. En
        // passant par `ScenarioPackEntity::resolveEntity()`, qui interroge avec
        // les scopes, la recherche rendait `null` pour une entite pourtant
        // inscrite — et le rejeu creait une SECONDE demande et une seconde
        // offre a chaque passage.
        //
        // Le filtrage par tenant est de toute facon deja fait : la ligne de
        // registre porte l'`organization_id`, et le registrar refuse d'inscrire
        // une entite d'une autre Organization.
        // On retire UNIQUEMENT le scope tenant, jamais tous les scopes :
        // `withoutGlobalScopes()` desactive aussi le filtre de suppression
        // douce, et une entite soft-deleted serait alors consideree comme
        // presente. Le reset ne la restaurerait pas et ne la recreerait pas
        // non plus — elle disparaitrait du monde sans que rien ne le dise.
        return $model::query()->withoutGlobalScope(BelongsToOrganizationScope::class)->find($row->entity_id);
    }

    /**
     * Un instant derive de l'unique ancre du chargement (spec 6.3).
     */
    private function fromOffsetMinutes(mixed $offsetMinutes): ?\DateTimeImmutable
    {
        if (! is_int($offsetMinutes)) {
            return null;
        }

        return $this->loadStartedAt->modify(sprintf('%+d minutes', $offsetMinutes));
    }

    /**
     * L'horloge LOCALE d'un instant, dans le fuseau donne.
     *
     * Les services du produit qui acceptent une date la parsent dans le fuseau
     * de l'objet : leur passer une horloge UTC decalerait chaque evenement de
     * l'offset du fuseau.
     */
    private function wallClock(?\DateTimeImmutable $instant, string $timezone): ?string
    {
        if ($instant === null) {
            return null;
        }

        return $instant->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i:s');
    }

    private function fromDayOffset(mixed $dayOffset): ?\DateTimeImmutable
    {
        if (! is_int($dayOffset)) {
            return null;
        }

        return $this->loadStartedAt->modify(sprintf('%+d days', $dayOffset));
    }

    /**
     * Le contenu tel qu'il sera rendu.
     *
     * Le Markdown est conserve tel quel : le produit rend du Markdown a
     * l'affichage, et le convertir ici figerait un HTML que le manifeste n'a
     * pas approuve. Les deux formes ont deja passe l'allowlist du Validator
     * (T1641) — c'est la seule raison pour laquelle on peut les stocker sans
     * les retoucher.
     */
    private function renderedContent(string $content, string $format): string
    {
        return $content;
    }

    /**
     * Un slug LIBRE dans la table visee.
     *
     * Le slug est derive d'un titre, jamais fourni par le manifeste : deux
     * sandboxes peuvent porter le meme titre, et plusieurs de ces tables ont un
     * slug unique globalement.
     */
    private function uniqueSlug(string $table, string $source): string
    {
        $base = Str::slug($source) ?: 'item';
        $candidate = $base;
        $suffix = 2;

        while (DB::table($table)->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;

            if ($suffix > 200) {
                return $base.'-'.Str::lower(Str::random(8));
            }
        }

        return $candidate;
    }
}
