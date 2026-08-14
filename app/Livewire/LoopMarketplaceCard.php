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

    /**
     * Les trois droits, lus une fois par requete.
     *
     * `LoopPermissionResolver::can()` interroge `loop_members` a chaque appel.
     * Ici le cout ne croissait pas avec le nombre de lignes — `canEdit()` n'est
     * pas appele par ligne — mais chaque rendu payait quatre lectures pour la
     * meme reponse.
     *
     * `private` : ne voyage pas dans le snapshot, se reconstruit a chaque
     * requete.
     *
     * @var array<string, bool>
     */
    private array $droitsMemo = [];

    private function droit(string $capacite): bool
    {
        return $this->droitsMemo[$capacite] ??= $this->resolver()->can(auth()->user(), $this->loop, $capacite);
    }

    /**
     * Oublier les droits appris.
     *
     * Appele a l'entree **et** a la sortie de `render()` : le cache ne doit pas
     * survivre au rendu. Une propriete privee ne voyage pas dans le snapshot,
     * mais l'unite de vie d'une instance Livewire n'est pas la requete, c'est
     * le **commit** — et un commit porte plusieurs `calls`. Deux gestes partis
     * dans le meme tick partagent donc une instance, et un droit retire entre
     * les deux par une requete concurrente ne serait plus honore.
     */
    private function forgetDroits(): void
    {
        $this->droitsMemo = [];
    }

    public function canView(): bool
    {
        return $this->droit('marketplace.view');
    }

    public function canHighlight(): bool
    {
        return $this->droit('marketplace.highlight');
    }

    public function canManage(): bool
    {
        return $this->droit('marketplace.manage');
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

        // **Relacher `editingId`** : le laisser pose sur une ligne qui vient de
        // disparaitre faisait rendre 404 au `saveNote()` suivant, au lieu d'un
        // message. Les deux boutons sont visibles en meme temps : le geste est
        // ordinaire.
        $this->reset(['editingId', 'note']);
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
        // Le meme vocabulaire que le selecteur : sans cela, une Demande
        // `in_progress` n'etait pas proposee mais etait acceptee par appel
        // direct — deux verites sur le meme etat.
        $vivants = $modele === Service::class
            ? LoopMarketplaceLink::LIVE_OFFER_STATUSES
            : LoopMarketplaceLink::LIVE_REQUEST_STATUSES;

        $objet = $modele::where('organization_id', $this->loop->organization_id)
            ->where('user_id', auth()->id())
            ->whereIn('status', $vivants)
            ->whereKey($id)
            ->first();

        abort_unless((bool) $objet, 404);

        return $objet;
    }

    /**
     * Les liens vers le catalogue, **scopes sur l'Organization de la Boucle**.
     *
     * Les routes nues `services.show` et `services.create` retombent sur
     * l'Organization **par defaut** : `ResolveUrlOrganization` les traite comme
     * des routes de fonctionnalite. Consequences mesurees — la fiche d'une
     * Offre rendait 404 pour son propre auteur, et « Creer une Offre »
     * enregistrait l'Offre **dans une autre Organization**, ou son auteur ne la
     * retrouvait jamais. Une ecriture cross-tenant par le parcours nominal.
     *
     * Le slug vient de la **Boucle**, jamais de la requete : c'est la lecon de
     * TASK-1103. Six autres ecrans du produit font deja cette bascule ; cette
     * Card etait le seul consommateur a ne pas la faire.
     *
     * @return array{offer_create:string, request_create:string}
     */
    private function catalogueLinks(): array
    {
        $slug = $this->loop->organization?->slug;

        return [
            'offer_create' => $slug
                ? route('organization.services.create', ['organization' => $slug])
                : route('services.create'),
            'request_create' => $slug
                ? route('organization.requests.create', ['organization' => $slug])
                : route('requests.create'),
        ];
    }

    /** La fiche d'une Offre ou d'une Demande, dans la meme Organization. */
    public function catalogueUrl(LoopMarketplaceLink $link): ?string
    {
        $cible = $link->target();

        if (! $cible) {
            return null;
        }

        $slug = $this->loop->organization?->slug;
        $offre = $link->kind() === LoopMarketplaceLink::KIND_OFFER;

        if ($slug) {
            return route($offre ? 'organization.services.show' : 'organization.requests.show',
                ['organization' => $slug, $offre ? 'service' : 'request' => $cible]);
        }

        return route($offre ? 'services.show' : 'requests.show', $cible);
    }

    private function service(): LoopMarketplaceService
    {
        return app(LoopMarketplaceService::class);
    }

    private function resolver(): LoopPermissionResolver
    {
        return app(LoopPermissionResolver::class);
    }

    /**
     * La valeur reellement retenue pour le selecteur.
     *
     * `$picking` est **public** : l'assainissement de `startPicking()` ne
     * protegeait rien, la propriete pouvant etre posee directement. Une valeur
     * inconnue ouvrait le selecteur Demande avec une liste toujours vide, et
     * l'ecran annonçait « aucune Demande ouverte » a quelqu'un qui en avait.
     */
    public function picking(): string
    {
        return in_array($this->picking, ['offer', 'request'], true) ? $this->picking : '';
    }

    /** @return Collection<int, Service|ServiceRequest> */
    private function pickable(): Collection
    {
        return match ($this->picking()) {
            'offer' => $this->service()->offerableBy($this->loop, auth()->user()),
            'request' => $this->service()->requestableBy($this->loop, auth()->user()),
            default => collect(),
        };
    }

    public function render()
    {
        // Le cache d'autorisation ne vit **que** dans ce rendu.
        $this->forgetDroits();

        $canView = $this->canView();
        $canHighlight = $canView && $this->canHighlight();
        $liens = $canView ? $this->service()->linksFor($this->loop) : collect();

        $vue = view('livewire.loop-marketplace-card', [
            'canView' => $canView,
            'canHighlight' => $canHighlight,
            'canManage' => $canView && $this->canManage(),
            'links' => $liens,
            'total' => $canView ? $this->service()->countFor($this->loop) : 0,
            // **Un autre nom que la propriete publique** : Livewire donne la
            // priorite a celle-ci sur la donnee passee, et la vue continuait
            // donc a lire la valeur brute — l'assainissement ne protegeait
            // rien.
            'pickingKind' => $this->picking(),
            'catalogue' => $this->catalogueLinks(),
            // Le selecteur plafonne a vingt : taire le reste laissait des
            // Offres eligibles sans aucun chemin.
            'pickableTotal' => $canHighlight && $this->picking()
                ? $this->service()->pickableCountFor($this->loop, auth()->user(), $this->picking())
                : 0,
            'pickable' => $canHighlight ? $this->pickable() : collect(),
        ]);

        // Les gestes d'ecriture qui suivront dans le meme commit reliront l'etat.
        $this->forgetDroits();

        return $vue;
    }
}
