<?php

namespace App\Livewire;

use App\Models\AiShellMessage;
use App\Models\Category;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Support\Ai\AiFabContext;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellPinnedContext;
use App\Support\Ai\AiShellThread;
use App\Support\Ai\AiShellTurnCards;
use App\Support\Loops\HelpRequestHandoff;
use DomainException;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * TASK-1315 — le Shell « BouclePro IA » : la surface conversationnelle qui
 * reste disponible pendant que l'utilisateur navigue.
 *
 * ## Pourquoi un composant monte dans le layout
 *
 * L'application ne fait AUCUNE navigation SPA (`wire:navigate` : 0 occurrence).
 * Chaque page est un rechargement complet, et le composant est donc remonte a
 * chaque page. Ce qui survit n'est pas l'etat du composant : c'est le FIL, en
 * base (`AiShellThread`). Le composant ne fait que le relire.
 *
 * ## Le contexte de page n'accorde aucun droit
 *
 * `$contextKind` / `$contextObjectId` sont `#[Locked]` — Livewire refuse qu'un
 * client les modifie. Mais on ne fait pas reposer une frontiere de tenant sur
 * une signature : `AiShellPageContext` REJOUE la garde de la page a chaque
 * rendu et a chaque action. Un identifiant qui ne passe pas donne un contexte
 * `other` : pas de nom, pas d'URL, pas d'action. C'est la reponse a « et si on
 * forge un identifiant ? » — le Shell ne revele rien parce qu'il ne CONNAIT
 * rien qu'il n'ait revalide.
 *
 * ## Ce composant ne publie jamais
 *
 * La seule action qui touche a une donnee durable est « preparer ma demande » :
 * elle depose un BROUILLON hors session (`HelpRequestHandoff`, T1211) et ouvre
 * le formulaire canonique. C'est un humain qui publie, au bout.
 */
class AiShell extends Component
{
    #[Locked]
    public ?string $organizationId = null;

    #[Locked]
    public ?string $contextKind = null;

    #[Locked]
    public ?string $contextObjectId = null;

    #[Locked]
    public string $routeName = '';

    /**
     * L'identifiant de conversation en cours. `#[Locked]`, et de toute facon
     * RELU en base a chaque montage : le client ne choisit jamais la
     * conversation qu'il lit. C'est ce qui rend le Shell continu d'une page a
     * l'autre sans SPA — meme identifiant, fil relu, contexte recalcule.
     */
    #[Locked]
    public ?string $conversationId = null;

    public string $draft = '';

    public ?string $notice = null;

    public bool $confirmingClear = false;

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        // Meme regle que `AiFabContext` : sur une route non prefixee,
        // l'Organization courante est celle par defaut de la plateforme — le
        // fil, lui, doit rester celui de l'utilisateur.
        $organization = $user->organization ?? currentOrganization();

        if (! $organization instanceof Organization) {
            return;
        }

        $this->organizationId = (string) $organization->id;

        $context = app(AiShellPageContext::class)->forRequest(request(), $user, $organization);

        $this->routeName = (string) ($context['route'] ?? '');
        $this->contextKind = (string) ($context['kind'] ?? AiShellPageContext::KIND_OTHER);
        $this->contextObjectId = is_array($context['object'] ?? null)
            ? (string) $context['object']['id']
            : null;

        $this->conversationId = app(AiShellThread::class)->currentConversationId($organization, $user);
    }

    /** Un tour de conversation. Le moteur reste celui de la clarification. */
    public function send(AiShellResponder $responder): void
    {
        $this->notice = null;
        $this->confirmingClear = false;

        // Vide AVANT tout travail : un second clic ne renvoie pas la meme
        // question (Livewire serialise les requetes d'un composant, donc les
        // deux appels ne se chevauchent pas et le verrou ne les verrait pas).
        $prompt = trim($this->draft);
        $this->draft = '';

        [$user, $organization] = $this->actor();

        if ($user === null || $organization === null || $prompt === '') {
            return;
        }

        if ($this->creditRefusal() !== null) {
            // Le refus reel vit dans `AiEconomicGuard`, atteint par la
            // clarification. Ici on evite seulement un aller-retour inutile.
            $this->notice = $this->creditRefusal();

            return;
        }

        try {
            // TASK-1326 : le contexte epingle est re-resolu ICI, dans la
            // requete du tour — c'est la revalidation « a chaque usage ». Ce
            // qui ne passe plus n'est ni injecte ni conserve.
            $turn = $responder->respond(
                $organization,
                $user,
                $prompt,
                $this->pageContext(),
                $this->conversationId,
                app(AiShellPinnedContext::class)->resolved($organization, $user),
            );

            // La conversation reellement inscrite fait foi — le composant s'y
            // aligne, il ne la decide pas.
            $this->conversationId = (string) $turn['trigger']->conversation_id;
        } catch (RuntimeException|DomainException $exception) {
            $this->draft = $prompt;
            $this->notice = $exception->getMessage();
        }

        // Confort d'affichage uniquement : ramener le fil sur le dernier tour.
        $this->dispatch('ai-shell-updated');
    }

    public function askForClear(): void
    {
        $this->confirmingClear = true;
        $this->notice = null;
    }

    public function cancelClear(): void
    {
        $this->confirmingClear = false;
    }

    public function clearThread(AiShellThread $thread): void
    {
        [$user, $organization] = $this->actor();

        $this->confirmingClear = false;

        if ($user === null || $organization === null) {
            return;
        }

        // On efface la conversation que la BASE designe comme courante, jamais
        // un identifiant venu du client.
        $thread->clear($organization, $user, $thread->currentConversationId($organization, $user));

        // Effacer, c'est ouvrir une conversation neuve — pas continuer la
        // precedente sans ses messages.
        $this->conversationId = $thread->currentConversationId($organization, $user);
        $this->notice = null;
    }

    /**
     * « Preparer ma demande » : depose un brouillon et ouvre le formulaire
     * canonique. AUCUNE publication — exactement le trajet de « Continuer ma
     * demande » (T1211), y compris la revalidation de la categorie et de la
     * Boucle de relais contre cette Organization.
     *
     * TASK-1325 : la LoopCard d'un tour transmet l'identifiant de SON message
     * — le brouillon vient de CE tour, pas du dernier. L'identifiant est
     * revalide par le scope `forThread` : celui d'un autre utilisateur, d'une
     * autre Organization ou d'un message inexistant ne resout rien.
     */
    public function prepareRequest(HelpRequestHandoff $handoff, ?string $messageId = null)
    {
        [$user, $organization] = $this->actor();

        if ($user === null || $organization === null) {
            return null;
        }

        $answer = $messageId !== null
            ? AiShellMessage::query()
                ->forThread((string) $organization->id, (string) $user->id)
                ->whereKey($messageId)
                ->where('role', AiShellMessage::ROLE_ASSISTANT)
                ->first()
            : $this->lastAnswer();

        if (! $answer instanceof AiShellMessage) {
            return null;
        }

        $metadata = is_array($answer->metadata) ? $answer->metadata : [];

        if (($metadata['status'] ?? null) !== AiShellResponder::STATUS_ANSWERED) {
            return null;
        }

        $categoryId = $metadata['suggested_category']['id'] ?? null;
        $category = $categoryId !== null
            ? Category::query()->whereKey($categoryId)->where('organization_id', $organization->id)->first(['id'])
            : null;

        $relayLoop = $this->suggestedLoop($answer);

        $handoff->storeDraft($user, $organization, [
            'title' => (string) ($metadata['title'] ?? ''),
            'description' => (string) ($metadata['message_draft'] ?: $answer->content),
            'relay_loop_id' => $relayLoop?->id,
            'category_id' => $category?->id,
        ]);

        return redirect()->to($this->requestsCreateUrl($organization));
    }

    /**
     * TASK-1326 — epingler un objet au contexte du Shell. Le couple (kind, id)
     * vient du client — comme le `messageId` de `prepareRequest` — et il est
     * REVALIDE par la garde de la page de l'objet au moment du geste : un
     * identifiant forge ne peut epingler qu'un objet que l'utilisateur peut
     * deja voir dans SA Organization. Epingler n'accorde rien : chaque usage
     * du pin rejoue la meme garde.
     */
    public function pin(AiShellPinnedContext $pins, string $kind, string $objectId): void
    {
        $this->notice = null;
        $this->confirmingClear = false;

        [$user, $organization] = $this->actor();

        if ($user === null || $organization === null) {
            return;
        }

        $status = $pins->pin($organization, $user, $kind, $objectId);

        if ($status === AiShellPinnedContext::PIN_LIMIT_REACHED) {
            $this->notice = __('ai.shell_pin_limit_reached', ['max' => $pins->limit()]);
        }
    }

    /**
     * Retirer un pin — retrancher une entree de sa propre session, rien
     * d'autre. Une valeur forgee ne retire rien ou retire un pin : inoffensif.
     */
    public function unpin(AiShellPinnedContext $pins, string $kind, string $objectId): void
    {
        $this->notice = null;

        [$user, $organization] = $this->actor();

        if ($user === null || $organization === null) {
            return;
        }

        $pins->unpin($organization, $kind, $objectId);
    }

    public function render(): View
    {
        [$user, $organization] = $this->actor();

        if ($user === null || $organization === null || ! $this->enabled()) {
            return view('livewire.ai-shell', ['shell' => null]);
        }

        $thread = app(AiShellThread::class);

        // La conversation lue est celle que la BASE porte, pas celle que le
        // client annonce : `conversation_id` regroupe, il n'ouvre rien. Sur un
        // fil VIDE il n'y a rien a imposer — on garde alors l'identifiant deja
        // affiche, sinon chaque rendu en fabriquerait un nouveau.
        $conversationId = $thread->persistedConversationId($organization, $user)
            ?? $this->conversationId
            ?? $thread->currentConversationId($organization, $user);
        $this->conversationId = $conversationId;

        $messages = $thread->messages($organization, $user, $conversationId);
        $context = $this->pageContext();

        // TASK-1326 : les pins re-resolus MAINTENANT — la liste affichee est
        // celle qui serait injectee au prochain tour, et ce qui ne passe plus
        // sa garde vient d'etre retire de la session. L'utilisateur voit
        // exactement ce qui est epingle.
        $pinnedContext = app(AiShellPinnedContext::class);
        $pins = $pinnedContext->resolved($organization, $user);

        $object = is_array($context['object'] ?? null) ? $context['object'] : null;
        $pinnable = $object !== null
            && in_array($object['type'] ?? null, AiShellPinnedContext::KINDS, true)
            && ! collect($pins)->contains(
                fn (array $pin): bool => $pin['kind'] === ($object['type'] ?? null) && $pin['id'] === (string) $object['id'],
            );

        // TASK-1325 : les cartes de chaque tour, re-resolues et re-autorisees
        // MAINTENANT — une carte dont l'objet ne passe plus sa garde n'existe
        // plus. Une instance unique par rendu : son memo d'eligibilite tient
        // le cout constant.
        $turnCards = app(AiShellTurnCards::class);
        $cards = [];

        foreach ($messages as $message) {
            $displayable = $turnCards->forDisplay($organization, $user, $message);

            if ($displayable !== []) {
                $cards[(string) $message->id] = $displayable;
            }
        }

        return view('livewire.ai-shell', [
            'shell' => [
                'context' => $context,
                'conversation_id' => $conversationId,
                'messages' => $messages,
                'cards' => $cards,
                'pins' => $pins,
                'pinnable' => $pinnable
                    ? ['kind' => (string) $object['type'], 'id' => (string) $object['id'], 'label' => (string) $object['label']]
                    : null,
                'pin_limit' => $pinnedContext->limit(),
                'actions' => $this->actions($context),
                'refusal' => $this->creditRefusal(),
                'offers_url' => $this->fab()['offers_url'] ?? null,
                'max_input_chars' => (int) config('ai.shell.max_input_chars', 2000),
            ],
        ]);
    }

    /**
     * Les actions natives du Shell restantes. TASK-1325 : « ouvrir la Boucle
     * suggeree » et « preparer ma demande » ne vivent plus en bas de fil —
     * elles appartiennent a la LoopCard de CHAQUE tour ({@see AiShellTurnCards}),
     * ou elles restent revalidees de la meme facon. Ne subsiste ici que
     * l'action liee au CONTEXTE de page, pas a un tour.
     *
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function actions(array $context): array
    {
        [$user, $organization] = $this->actor();

        if ($user === null || $organization === null) {
            return [];
        }

        $actions = [];

        // Interroger les Dossiers de la Boucle courante — MEME garde que le
        // bouton de la page, calculee par la seule autorite qui la connait.
        if (($context['kind'] ?? null) === AiShellPageContext::KIND_LOOP) {
            $loop = Loop::query()->find($context['object']['id'] ?? null);

            if ($loop instanceof Loop) {
                $knowledge = collect(app(AiFabContext::class)->loopActions($loop, $user))
                    ->firstWhere('key', AiFabContext::ACTION_LOOP_KNOWLEDGE);

                if (is_array($knowledge)) {
                    $actions[] = [
                        'key' => 'shell_loop_knowledge',
                        'kind' => 'event',
                        'label' => __('ai.shell_action_loop_knowledge'),
                        'event' => $knowledge['event'],
                    ];
                }
            }
        }

        return $actions;
    }

    /**
     * La Boucle suggeree par un tour, RE-RESOLUE sous la garde de la page.
     * L'identifiant seul est stocke ; s'il ne passe plus, il n'existe plus.
     */
    private function suggestedLoop(AiShellMessage $answer): ?Loop
    {
        $loopId = is_array($answer->metadata) ? ($answer->metadata['suggested_loop_id'] ?? null) : null;

        if (! is_string($loopId) || $loopId === '') {
            return null;
        }

        [$user, $organization] = $this->actor();

        if ($user === null || $organization === null) {
            return null;
        }

        $resolved = app(AiShellPageContext::class)->resolve(
            $user,
            $organization,
            AiShellPageContext::KIND_LOOP,
            $loopId,
        );

        return is_array($resolved['object'] ?? null)
            ? Loop::query()->find($resolved['object']['id'])
            : null;
    }

    private function lastAnswer(): ?AiShellMessage
    {
        [$user, $organization] = $this->actor();

        if ($user === null || $organization === null) {
            return null;
        }

        $thread = app(AiShellThread::class);
        $last = $thread->messages($organization, $user, $thread->currentConversationId($organization, $user), 1)->last();

        return $last instanceof AiShellMessage && $last->role === AiShellMessage::ROLE_ASSISTANT ? $last : null;
    }

    /** @return array<string, mixed> */
    private function pageContext(): array
    {
        [$user, $organization] = $this->actor();

        if ($user === null || $organization === null) {
            return [];
        }

        return app(AiShellPageContext::class)->resolve(
            $user,
            $organization,
            $this->contextKind,
            $this->contextObjectId,
            $this->routeName,
        );
    }

    /** @return array{0: ?User, 1: ?Organization} */
    private function actor(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || $this->organizationId === null) {
            return [null, null];
        }

        $organization = Organization::query()->find($this->organizationId);

        // Le fil est celui de l'utilisateur dans SON Organization : si le
        // couple ne tient plus, il n'y a pas de fil a montrer.
        if (! $organization instanceof Organization) {
            return [null, null];
        }

        $own = $user->organization ?? currentOrganization();

        if (! $own instanceof Organization || (string) $own->id !== (string) $organization->id) {
            return [null, null];
        }

        return [$user, $organization];
    }

    private function creditRefusal(): ?string
    {
        return $this->fab()['refusal_message'] ?? null;
    }

    /** @return array<string, mixed> */
    private function fab(): array
    {
        $user = auth()->user();

        return $user instanceof User
            ? (app(AiFabContext::class)->forRequest(request(), $user) ?? [])
            : [];
    }

    private function enabled(): bool
    {
        return (bool) config('ai.shell.enabled', true) && (bool) config('ai.fab.enabled', true);
    }

    private function requestsCreateUrl(Organization $organization): string
    {
        if (RouteFacade::has('organization.requests.create')) {
            return route('organization.requests.create', ['organization' => $organization->slug]);
        }

        return route('requests.create');
    }
}
