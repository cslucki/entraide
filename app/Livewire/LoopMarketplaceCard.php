<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopMarketplaceLink;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Services\Loops\LoopMarketplaceService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * La Card Demande-Offre : ce que la Boucle met en avant du catalogue.
 *
 * **Aucun second systeme.** Les Offres et les Demandes existent depuis
 * l'origine du produit ; cette Card les rattache a une Boucle. Elle ne porte
 * meme pas de formulaire de creation — le parcours existe, avec ses regles de
 * categorie, de mode de livraison et de cout en points, et le dupliquer aurait
 * fait diverger les deux.
 *
 * Les cinq invariants de la serie sont tenus des la conception :
 *
 * 1. **Mettre en avant est une ecriture** : `marketplace.highlight`, sans
 *    `read`. Une Boucle archivee la refuse.
 * 2. **Une permission ne suffit pas** : on ne met en avant que **les siennes**,
 *    et seulement dans la meme Organization.
 * 3. **Le cout ne croit pas** : la lecture est bornee, les relations chargees
 *    en une fois.
 * 4. **Chaque geste a un chemin**, teste au refus, au succes **et** a l'ecran.
 * 5. **Retirer un lien ne touche jamais l'Offre.**
 *
 * `$editingId` est public — donc pose depuis le client. Chaque geste qui le
 * consomme revalide le droit au moment de l'ecriture, et un identifiant qui
 * n'est pas un UUID est arrete **avant** la requete : sous PostgreSQL la
 * colonne est un `uuid` natif et rendrait 500. Les deux lecons viennent de
 * TASK-1104 et TASK-1106.
 */
class LoopMarketplaceCard extends Component
{
    public Loop $loop;

    /** `offer`, `request`, ou vide quand aucun selecteur n'est ouvert. */
    public string $picking = '';

    public ?string $editingId = null;

    public string $note = '';

    public string $flash = '';

    public string $problem = '';

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
    }

    // ── Droits ──────────────────────────────────────────────────────────────

    public function canView(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'marketplace.view');
    }

    public function canHighlight(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'marketplace.highlight');
    }

    public function canManage(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'marketplace.manage');
    }

    /**
     * Chacun retire les siennes ; l'animation retire tout.
     *
     * **`protected` et non `public`** : Livewire expose toute methode publique
     * comme action et resout son argument par liaison implicite — donc **sans**
     * le `where('loop_id', …)` de `resolveLink()`. Elle repondait alors sur un
     * lien d'une autre Boucle, et distinguait l'inexistant de l'existant : un
     * oracle, sans mutation possible, mais un oracle.
     */
    protected function canEdit(LoopMarketplaceLink $link): bool
    {
        if (! $this->canHighlight()) {
            return false;
        }

        return $this->canManage() || $link->added_by === auth()->id();
    }

    // ── Gestes ──────────────────────────────────────────────────────────────

    public function highlightOffer(string $serviceId): void
    {
        $this->authorizeHighlight();

        $service = $this->resolveOwn(Service::class, $serviceId);

        try {
            $this->service()->highlightOffer($this->loop, auth()->user(), $service, $this->note ?: null);
        } catch (ValidationException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->reset(['picking', 'note']);
        $this->problem = '';
        $this->flash = __('loops.cards.marketplace.highlighted');
    }

    public function highlightRequest(string $requestId): void
    {
        $this->authorizeHighlight();

        $demande = $this->resolveOwn(ServiceRequest::class, $requestId);

        try {
            $this->service()->highlightRequest($this->loop, auth()->user(), $demande, $this->note ?: null);
        } catch (ValidationException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->reset(['picking', 'note']);
        $this->problem = '';
        $this->flash = __('loops.cards.marketplace.highlighted');
    }

    /**
     * Ouvrir un selecteur, **et relacher ce que le formulaire tenait**.
     *
     * `$set('picking', …)` ne suffisait pas : `editingId` restait pose, et le
     * mot tape pour la nouvelle mise en avant **ecrasait** celui de la
     * precedente — ou, symetriquement, la nouvelle naissait avec le mot de
     * l'ancienne. Trois clics ordinaires. C'est le defaut du Journal, revenu
     * par l'autre porte : `cancel()` etait garde, celle-ci ne l'etait pas.
     */
    public function startPicking(string $kind): void
    {
        $this->authorizeHighlight();

        $this->reset(['editingId', 'note']);
        $this->picking = in_array($kind, ['offer', 'request'], true) ? $kind : '';
        $this->flash = '';
        $this->problem = '';
    }

    public function startEditingNote(string $linkId): void
    {
        $this->authorizeHighlight();

        $link = $this->resolveLink($linkId);
        abort_unless($this->canEdit($link), 403);

        $this->editingId = $link->id;
        $this->note = (string) $link->note;
        $this->picking = '';
        $this->flash = '';
        $this->problem = '';
    }

    public function saveNote(): void
    {
        $this->authorizeHighlight();

        abort_unless((bool) $this->editingId, 403);

        $link = $this->resolveLink($this->editingId);

        // Revalide **ici**, pas seulement a l'ouverture : `$editingId` est
        // public, et le poser depuis le client suffisait sinon a ecrire le mot
        // de n'importe qui sous son nom.
        abort_unless($this->canEdit($link), 403);

        $this->service()->updateNote($link, $this->note ?: null);

        $this->reset(['editingId', 'note']);
        $this->problem = '';
        // **Pas « mis en avant »** : rien n'a ete mis en avant, un mot a ete
        // corrige. Observe en recette : la confirmation decrivait un autre
        // geste que celui qu'on venait de faire.
        $this->flash = __('loops.cards.marketplace.note_saved');
    }

    public function remove(string $linkId): void
    {
        $this->authorizeHighlight();

        $link = $this->resolveLink($linkId);
        abort_unless($this->canEdit($link), 403);

        $this->service()->remove($link);

        $this->problem = '';
        $this->flash = __('loops.cards.marketplace.removed');
    }

    /**
     * Fermer ce qui est ouvert **et** relacher ce qu'il tenait.
     *
     * `$set('picking', '')` ne suffirait pas : `editingId` resterait pose, et
     * le mot suivant ecraserait celui qu'on venait de renoncer a corriger. Le
     * Journal a paye ce defaut ; il ne se represente pas.
     */
    public function cancel(): void
    {
        $this->reset(['picking', 'editingId', 'note']);
        $this->problem = '';
    }

    // ── Gardes ──────────────────────────────────────────────────────────────

    private function authorizeHighlight(): void
    {
        // `canView()` **aussi** : sans lui, une personne privee de lecture
        // ecrivait quand meme, dans une Card que l'ecran lui declare
        // inaccessible.
        abort_unless($this->canView() && $this->canHighlight(), 403);
    }

    /** Un lien de **cette** Boucle, ou 404. */
    private function resolveLink(string $linkId): LoopMarketplaceLink
    {
        abort_unless(Str::isUuid($linkId), 404);

        $link = LoopMarketplaceLink::where('loop_id', $this->loop->id)->whereKey($linkId)->first();

        abort_unless((bool) $link, 404);

        return $link;
    }

    /**
     * Une Offre ou une Demande **a soi**, dans cette Organization, ou 404.
     *
     * Mettre en avant celle de quelqu'un d'autre l'engagerait a repondre a une
     * Boucle qu'il n'a pas choisie : la Card servirait a inscrire autrui.
     *
     * @param  class-string<Service|ServiceRequest>  $modele
     */
    private function resolveOwn(string $modele, string $id): Service|ServiceRequest
    {
        abort_unless(Str::isUuid($id), 404);

        // **Le statut est verifie ici, pas seulement dans le selecteur.**
        // Celui-ci filtrait deja, mais l'identifiant vient du client : on
        // pouvait mettre en avant, par appel direct, une Offre `paused` ou
        // `deleted`, ou une Demande `closed` — et injecter du mort dans la
        // Boucle.
        $vivants = $modele === Service::class ? ['active'] : ['open', 'in_progress'];

        $objet = $modele::where('organization_id', $this->loop->organization_id)
            ->where('user_id', auth()->id())
            ->whereIn('status', $vivants)
            ->whereKey($id)
            ->first();

        abort_unless((bool) $objet, 404);

        return $objet;
    }

    private function service(): LoopMarketplaceService
    {
        return app(LoopMarketplaceService::class);
    }

    private function resolver(): LoopPermissionResolver
    {
        return app(LoopPermissionResolver::class);
    }

    /** @return Collection<int, Service|ServiceRequest> */
    private function pickable(): Collection
    {
        return match ($this->picking) {
            'offer' => $this->service()->offerableBy($this->loop, auth()->user()),
            'request' => $this->service()->requestableBy($this->loop, auth()->user()),
            default => collect(),
        };
    }

    public function render()
    {
        $canView = $this->canView();
        $canHighlight = $canView && $this->canHighlight();
        $liens = $canView ? $this->service()->linksFor($this->loop) : collect();

        return view('livewire.loop-marketplace-card', [
            'canView' => $canView,
            'canHighlight' => $canHighlight,
            'canManage' => $canView && $this->canManage(),
            'links' => $liens,
            'total' => $canView ? $this->service()->countFor($this->loop) : 0,
            'pickable' => $canHighlight ? $this->pickable() : collect(),
        ]);
    }
}
