<?php

namespace App\Services\Loops;

use App\Ai\Context\DossierAccessScope;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\User;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TASK-1310 : capitaliser une reponse IA du ChatLoop en Article du Dossier.
 *
 * Une reponse utile — IA, Dossiers ou IA + Dossiers — devient une connaissance
 * durable de la Boucle, **apres relecture et validation d'un humain**. Ce
 * service est l'unique autorite de ce geste : eligibilite de la source,
 * perimetre tenant, droit d'ecriture, choix du Dossier, creation de l'Article
 * et provenance passent tous par ici.
 *
 * ## Ce qu'il ne construit PAS
 *
 * Rien. L'Article est cree par le MEME chemin que
 * `LoopDossierArticleController::store()` (BlogPost + `DossierBlogPost` +
 * rattachement de la Boucle), avec l'idiome d'un Article de Boucle repris de
 * `LoopRootDocumentService` (`audience = loop`, `listed_in_blog = false`,
 * publie), et indexe par le MEME dispatcher canonique que
 * `DossierArticleController::createAndAttach()`. Aucun second indexer, aucun
 * second pipeline, aucun endpoint RAG nouveau.
 *
 * PIEGE VERIFIE, PAS SUPPOSE : `BlogPostObserver` n'a **pas** de handler
 * `created` — il ne reagit qu'a `updated`, `deleted` et `restored`. Creer un
 * Article publie n'indexe donc RIEN par lui-meme, et de toute facon la ligne
 * `DossierBlogPost` n'existe pas encore a cet instant. Le chemin canonique de
 * creation+attache dispatche explicitement, apres l'attache : c'est ce que
 * fait ce service. Le test le prouve.
 *
 * ## L'invariant qui compte
 *
 * L'auteur de l'Article est l'HUMAIN qui valide (`blog_posts.user_id`), jamais
 * l'IA, jamais le message, jamais un compte technique. Le produit doit pouvoir
 * dire « synthese IA enregistree par X » sans faire de l'IA un auteur.
 *
 * ## Securite
 *
 * L'UI n'est jamais une barriere. Tout identifiant venu du front — message,
 * Dossier — est revalide ici contre le tenant, la Boucle et la policy. Un
 * `dossier_id` arbitraire n'est jamais accepte parce qu'il vient du front.
 */
class LoopAnswerCapitalizationService
{
    /**
     * Les trois moteurs dont une reponse peut etre capitalisee (TASK-1308 /
     * TASK-1309). Une bulle sans `ai_mode` connu — message humain, agent de
     * membre, evenement — n'est jamais eligible.
     */
    public const CAPITALIZABLE_AI_MODES = ['llm', 'rag', 'llm_rag'];

    public const ORIGIN_AI_SYNTHESIS = 'ai_synthesis_human_validated';

    private const MAX_TITLE_LENGTH = 255;

    public function __construct(
        private readonly LoopPermissionResolver $permissions,
        private readonly DossierAccessScope $scope,
        private readonly LoopRootDocumentService $rootDocuments,
        private readonly DossierArticleIndexingDispatcher $indexing,
    ) {}

    /**
     * La bulle est-elle une reponse IA capitalisable, DANS cette Boucle ?
     *
     * Volontairement pur et sans effet de bord : la vue s'en sert pour decider
     * d'afficher l'action, et le service s'en sert pour refuser — une seule
     * regle, jamais deux qui pourraient diverger.
     */
    public function isCapitalizable(Loop $loop, LoopMessage $message): bool
    {
        return $message->type === 'ai'
            && $message->loop_id === $loop->id
            && $message->organization_id === $loop->organization_id
            && ! $message->isDeleted()
            && in_array($this->aiModeOf($message), self::CAPITALIZABLE_AI_MODES, true);
    }

    /**
     * Les Dossiers dans lesquels CET utilisateur peut deposer, pour CETTE
     * Boucle.
     *
     * Composition de deux autorites existantes, jamais une troisieme regle :
     * `DossierAccessScope` donne le perimetre loop-scoped et tenant-safe
     * (T1294/T1307), puis `DossierPolicy::attachArticle` decide du DROIT
     * D'ECRIRE dessus. Un Dossier visible n'est pas un Dossier ou l'on peut
     * ecrire — les deux questions restent distinctes.
     *
     * @return Collection<int, Dossier>
     */
    public function writableDossiers(Loop $loop, User $user): Collection
    {
        if ($user->organization_id !== $loop->organization_id) {
            return new Collection;
        }

        $ids = $this->scope->accessibleDossierIds(
            (string) $loop->organization_id,
            $user,
            (string) $loop->id,
        );

        if ($ids === []) {
            return new Collection;
        }

        $gate = Gate::forUser($user);

        return Dossier::query()
            ->whereIn('id', $ids)
            ->where('organization_id', $loop->organization_id)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Dossier $dossier): bool => $gate->allows('attachArticle', $dossier))
            ->values();
    }

    /**
     * Le Dossier PRESELECTIONNE : celui de la Boucle quand l'utilisateur peut
     * y ecrire, sinon le premier autorise. Aucun identifiant en dur — il est
     * resolu depuis la Boucle a chaque fois.
     */
    public function defaultDossier(Loop $loop, User $user): ?Dossier
    {
        $writable = $this->writableDossiers($loop, $user);

        if ($writable->isEmpty()) {
            return null;
        }

        $rootId = Dossier::query()->where('loop_id', $loop->id)->value('id');

        return $writable->firstWhere('id', $rootId) ?? $writable->first();
    }

    /**
     * Titre PRE-REMPLI, derive localement de la question qui a produit la
     * reponse — et a defaut du debut de la reponse elle-meme.
     *
     * AUCUN appel provider : la feature entiere doit couter zero invocation
     * supplementaire. L'humain reste libre de tout reecrire avant
     * d'enregistrer.
     */
    public function suggestedTitle(LoopMessage $message): string
    {
        $question = trim((string) ($message->metadata['question'] ?? ''));
        $seed = $question !== '' ? $question : trim((string) $message->body);
        $seed = trim(preg_replace('/\s+/u', ' ', $seed) ?? '');
        $seed = rtrim($seed, " \t\n\r\0\x0B?!.:;,");

        if ($seed === '') {
            return __('loops.capitalize_default_title');
        }

        return Str::limit(Str::ucfirst($seed), 120, '…');
    }

    /**
     * Enregistre la reponse validee comme Article du Dossier.
     *
     * Toutes les gardes sont ici, dans cet ordre : appartenance a
     * l'Organization, eligibilite de la bulle, permission d'ecrire dans la
     * Boucle, appartenance du Dossier au perimetre ecrivable. Chacune leve —
     * l'appelant HTTP/Livewire traduit, il ne decide pas.
     *
     * @throws RuntimeException
     */
    public function capitalize(
        Loop $loop,
        User $curator,
        LoopMessage $message,
        string $dossierId,
        string $title,
        string $content,
    ): BlogPost {
        if ($curator->organization_id !== $loop->organization_id) {
            throw new RuntimeException(__('loops.cross_organization'));
        }

        if (! $this->isCapitalizable($loop, $message)) {
            throw new RuntimeException(__('loops.capitalize_source_not_eligible'));
        }

        if (! $this->permissions->can($curator, $loop, 'dossiers.create_article')) {
            throw new RuntimeException(__('loops.capitalize_forbidden'));
        }

        // Le `dossier_id` vient du front : il n'est JAMAIS cru sur parole. Il
        // doit se retrouver dans l'ensemble reellement ecrivable, recalcule
        // ici pour cet utilisateur et cette Boucle.
        $dossier = $this->writableDossiers($loop, $curator)->firstWhere('id', $dossierId);

        if ($dossier === null) {
            throw new RuntimeException(__('loops.capitalize_forbidden'));
        }

        $title = trim($title);
        $content = trim($content);

        if ($title === '' || $content === '') {
            throw new RuntimeException(__('loops.capitalize_content_required'));
        }

        $title = Str::limit($title, self::MAX_TITLE_LENGTH, '');

        return DB::transaction(function () use ($loop, $curator, $message, $dossier, $title, $content): BlogPost {
            $post = BlogPost::create([
                // L'INVARIANT : l'auteur est l'humain qui valide.
                'user_id' => $curator->id,
                'organization_id' => $loop->organization_id,
                'title' => $title,
                'content' => $content,
                // Idiome d'un Article de Boucle (LoopRootDocumentService) :
                // vivant pour sa Boucle des la creation — c'est ce que
                // `publiclyReadable()` exige pour que l'indexation le voie —
                // et jamais dans le Blog public.
                'status' => 'published',
                'published_at' => now(),
                'audience' => BlogPost::AUDIENCE_LOOP,
                'listed_in_blog' => false,
                'ai_origin' => $this->originFor($loop, $curator, $message),
            ]);

            DossierBlogPost::create([
                'organization_id' => $loop->organization_id,
                'dossier_id' => $dossier->id,
                'blog_post_id' => $post->id,
                'added_by' => $curator->id,
                'position' => ((int) $dossier->dossierBlogPosts()->max('position')) + 1,
            ]);

            $post->loops()->attach($loop->id);

            // Le MEME dispatcher que `DossierArticleController::createAndAttach()`,
            // appele APRES l'attache — `dispatchForBlogPost()` lit les lignes
            // `DossierBlogPost`, qui n'existent qu'a partir d'ici. Aucun
            // indexeur nouveau : le pipeline canonique, invoque au bon moment.
            $this->indexing->dispatch(
                (string) $loop->organization_id,
                (string) $dossier->id,
                (string) $post->id,
            );

            return $post;
        });
    }

    /**
     * La provenance persistee sur l'Article (`blog_posts.ai_origin`).
     *
     * `sources` reprend `metadata['sources']` — depuis TASK-1309, les sources
     * REELLEMENT CITEES et elles seules. Jamais `metadata['consulted']` : un
     * document seulement consulte n'a etaye aucune affirmation, et le recopier
     * ici en ferait retroactivement un appui. Une bulle sans citation valide
     * donne donc une liste vide, et c'est correct.
     *
     * @return array<string, mixed>
     */
    private function originFor(Loop $loop, User $curator, LoopMessage $message): array
    {
        $metadata = $message->metadata ?? [];

        return [
            'origin_type' => self::ORIGIN_AI_SYNTHESIS,
            'source_loop_message_id' => (string) $message->id,
            'source_loop_id' => (string) $loop->id,
            'ai_interaction_id' => $metadata['ai_interaction_id'] ?? null,
            'ai_mode' => $this->aiModeOf($message),
            'human_curator_id' => (string) $curator->id,
            'sources' => array_values($metadata['sources'] ?? []),
        ];
    }

    /**
     * Discriminant canonique de moteur, avec le meme repli historique que
     * `LoopChat` (TASK-1308) : les bulles anterieures a cette cle derivent leur
     * mode de leur `action`. Une bulle qui n'est ni l'un ni l'autre rend une
     * chaine vide — donc non capitalisable.
     */
    private function aiModeOf(LoopMessage $message): string
    {
        $mode = $message->metadata['ai_mode'] ?? null;

        if (in_array($mode, self::CAPITALIZABLE_AI_MODES, true)) {
            return $mode;
        }

        $action = $message->metadata['action'] ?? null;

        if ($action === null) {
            return '';
        }

        return in_array($action, ['knowledge', 'slash_ia', 'continuation', 'dossiers'], true) ? 'rag' : 'llm';
    }
}
