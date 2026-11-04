<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\RequestAttachment;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\LoopMessageService;
use App\Support\Loops\HelpRequestHandoff;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RequestController extends Controller
{
    public function __construct(
        private readonly ClarifyUserHelpRequestService $clarifier,
        private readonly LoopMessageService $loopMessages,
        private readonly HelpRequestHandoff $handoff,
    ) {}

    public function show(ServiceRequest $request): View
    {
        $organization = currentOrganization();
        if (! $organization || $request->organization_id !== $organization->id) {
            abort(404);
        }

        $request->load(['user', 'category', 'attachments']);

        if ($request->user?->banned_at !== null) {
            abort(404);
        }

        $ogTitle = $request->title;
        $ogDescription = Str::limit(strip_tags($request->description), 160);
        $ogImage = null;

        return view('requests.show', compact('request', 'ogTitle', 'ogDescription', 'ogImage'));
    }

    public function orgShow(string $org, ServiceRequest $request): View
    {
        return $this->show($request);
    }

    public function create(): View
    {
        $user = request()->user();
        $organization = $this->organizationFor($user);

        // TASK-1211 : le brouillon prepare depuis une Boucle (« Qui peut
        // m'aider ? » → « Continuer ma demande ») arrive ici hors session et
        // n'est lu qu'une fois. Il ne remplace jamais une saisie renvoyee par
        // une erreur de validation.
        $draft = $this->handoff->pullDraft($user, $organization);

        // TASK-1553 — la provenance sort du brouillon AVANT `flashInput()`.
        //
        // `flashInput()` verse tout le tableau dans l'ancienne saisie : une cle
        // `provenance` s'y retrouverait sous `old('provenance')`, a cote de
        // champs de formulaire, et repartirait au prochain aller-retour de
        // validation comme si la personne l'avait tapee. Ce n'est pas une
        // saisie : c'est ce sur quoi la proposition se fonde.
        $draftProvenance = null;

        if ($draft !== null) {
            $draftProvenance = is_array($draft['provenance'] ?? null) ? $draft['provenance'] : null;
            unset($draft['provenance']);
        }

        if ($draft !== null && ! request()->session()->hasOldInput()) {
            request()->session()->flashInput($draft);
        }

        // TASK-1749 — la relance d'une demande pourvue.
        //
        // Le pre-remplissage passe par `flashInput()`, exactement comme le
        // brouillon de Boucle ci-dessus : c'est le meme mecanisme, et les
        // champs se retrouvent donc sous `old()` sans que le gabarit ait a
        // connaitre deux sources.
        //
        // Il ne remplace JAMAIS une saisie renvoyee par une erreur de
        // validation (`hasOldInput()`), sans quoi un aller-retour de validation
        // effacerait ce que la personne vient de corriger.
        //
        // L'ECHEANCE N'EST PAS REPRISE, et c'est delibere : celle de la demande
        // d'origine est passee, et `store()` exige `after:today`. La recopier
        // aurait produit un formulaire pre-rempli qui echoue a l'envoi, sur un
        // champ que la personne n'a pas touche.
        //
        // Les pieces jointes ne peuvent pas etre pre-remplies dans un champ
        // `file` — aucun navigateur ne l'autorise. Elles sont copiees a
        // l'ENREGISTREMENT, a partir de `relancer_de`.
        $relance = $this->demandeARelancer(request()->query('relancer'), $user, $organization);

        if ($relance !== null && ! request()->session()->hasOldInput()) {
            request()->session()->flashInput([
                'title' => $relance->title,
                'description' => $relance->description,
                'category_id' => $relance->category_id,
                'delivery_mode' => $relance->delivery_mode,
                'budget_min' => $relance->budget_min,
                'budget_max' => $relance->budget_max,
            ]);
        }

        $categories = Category::where('organization_id', $organization?->id)->with('pointGuidelines')->get();
        $relayLoops = $this->relayLoopsFor($organization, $user);

        return view('requests.create', compact('categories', 'organization', 'relayLoops', 'draftProvenance', 'relance'));
    }

    /** L'aide IA ne remplit que le titre et la description, sans rien publier. */
    public function formulate(Request $httpRequest): JsonResponse
    {
        $user = $httpRequest->user();
        $organization = $this->organizationFor($user);

        $data = $httpRequest->validate([
            'title' => ['nullable', 'string', 'max:255', 'required_without:description'],
            'description' => ['nullable', 'string', 'max:2000', 'required_without:title'],
        ]);

        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $intention = implode("\n", array_filter([
            $title !== '' ? 'Titre actuel : '.$title : null,
            $description !== '' ? 'Description actuelle : '.$description : null,
        ]));

        try {
            $result = $this->clarifier->clarifyForOrganization($organization, $user, $intention);
        } catch (DomainException $exception) {
            report($exception);

            return response()->json(['error' => __('ai.request_formulation_unavailable')], 503);
        }

        if ($result->isBlocked()) {
            return response()->json([
                'error' => $result->fallback['reason'] ?? __('ai.request_formulation_error'),
            ], 422);
        }

        if ($result->producer === 'deterministic_fallback') {
            return response()->json(['error' => __('ai.request_formulation_unavailable')], 422);
        }

        $suggestedTitle = $this->nonDestructiveSuggestion($title, $result->title, true);
        $suggestedDescription = $this->nonDestructiveSuggestion(
            $description,
            $result->messageDraft ?: $result->need,
        );
        $suggestedCategory = $result->suggestedCategory;

        $hasImprovement = $this->normalizedSuggestion($suggestedTitle) !== $this->normalizedSuggestion($title)
            || $this->normalizedSuggestion($suggestedDescription) !== $this->normalizedSuggestion($description)
            || $suggestedCategory !== null;

        if (! $hasImprovement) {
            return response()->json(['error' => __('ai.request_formulation_no_improvement')], 422);
        }

        return response()->json([
            'suggestion' => [
                'title' => $suggestedTitle,
                'description' => $suggestedDescription,
                'category_id' => $suggestedCategory['id'] ?? null,
                'category_label' => $suggestedCategory['label'] ?? null,
            ],
        ]);
    }

    public function store(Request $httpRequest): RedirectResponse
    {
        $user = $httpRequest->user();
        $organization = $this->organizationFor($user);

        $data = $httpRequest->validate($this->validationRules($organization, ['deadline' => 'nullable|date|after:today']), [], __('marketplace.validation_attributes'));

        // TASK-1749 — la demande relancee est resolue AVANT toute ecriture.
        //
        // DEFAUT ATTRAPE PAR LE TEST, et il merite d'etre ecrit : la premiere
        // version resolvait la source APRES `ServiceRequest::create()`. Un
        // `relancer_de` refuse levait donc son 404 une fois la demande neuve
        // DEJA ENREGISTREE — rien n'enveloppe ce bloc dans une transaction. La
        // tentative echouait a l'ecran et laissait une demande orpheline
        // publiee.
        //
        // Le champ est cache, donc modifiable : ces conditions sont rejouees
        // ici et pas seulement a l'affichage.
        $relance = $this->demandeARelancer($httpRequest->input('relancer_de'), $user, $organization);

        $relayLoop = $this->relayLoopOrNull($data['relay_loop_id'] ?? null, $organization, $user);

        if (! empty($data['relay_loop_id']) && $relayLoop === null) {
            throw ValidationException::withMessages([
                'relay_loop_id' => __('requests.relay_loop_invalid'),
            ]);
        }

        $serviceRequest = ServiceRequest::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'budget_min' => $data['budget_min'],
            'budget_max' => $data['budget_max'] ?? null,
            'deadline' => $data['deadline'] ?? null,
            'status' => 'open',
        ]);

        $this->storeAttachments($httpRequest, $serviceRequest);

        // TASK-1749 — les pieces jointes de la demande relancee.
        //
        // La source est resolue PLUS HAUT, avant toute ecriture : voir la note
        // la-bas. Ici on ne fait que copier.
        //
        // APRES `storeAttachments()` : ce que la personne vient de deposer
        // passe en premier, les reprises suivent.
        if ($relance !== null) {
            $this->copierLesPiecesJointes($relance, $serviceRequest);
        }

        if ($relayLoop !== null) {
            try {
                $message = $this->loopMessages->sendServiceRequestProjection(
                    $relayLoop,
                    $user,
                    $serviceRequest,
                );

                return redirect()->route('organization.loops.show', [
                    'organization' => $organization->slug,
                    'loop' => $relayLoop,
                ])->with('success', __('requests.notification.created_and_relayed'))
                    ->with('help_request_message_id', $message->id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $redirectRoute = $organization && Route::has('organization.dashboard.requests')
            ? route('organization.dashboard.requests', ['organization' => $organization->slug])
            : route('dashboard.requests');

        $response = redirect($redirectRoute)->with('success', __('requests.notification.created'));

        return $relayLoop !== null
            ? $response->with('warning', __('requests.relay_failed'))
            : $response;
    }

    private function storeAttachments(Request $httpRequest, ServiceRequest $serviceRequest): void
    {
        if (! $httpRequest->hasFile('attachments')) {
            return;
        }
        foreach ($httpRequest->file('attachments') as $index => $file) {
            $path = $file->store('request-attachments', 'public');
            $serviceRequest->attachments()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'order' => $index,
                'organization_id' => $serviceRequest->organization_id,
            ]);
        }
    }

    public function edit(ServiceRequest $request): View
    {
        $organization = currentOrganization();
        if (! $organization || $request->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $request);

        $categories = Category::where('organization_id', $organization->id)->with('pointGuidelines')->get();
        $request->load(['attachments']);

        return view('requests.edit', compact('request', 'categories', 'organization'));
    }

    public function update(Request $httpRequest, ServiceRequest $request): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $request->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $request);

        $rules = array_merge($this->validationRules($organization, ['deadline' => 'nullable|date']), [
            'delete_attachments' => 'nullable|array',
            'delete_attachments.*' => 'uuid|exists:request_attachments,id',
        ]);

        $data = $httpRequest->validate($rules, [], __('marketplace.validation_attributes'));

        if (! empty($data['delete_attachments'])) {
            $attachmentsToDelete = RequestAttachment::whereIn('id', $data['delete_attachments'])
                ->where('service_request_id', $request->id)->get();
            foreach ($attachmentsToDelete as $attachment) {
                Storage::disk('public')->delete($attachment->path);
                $attachment->delete();
            }
        }

        $request->update([
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'budget_min' => $data['budget_min'],
            'budget_max' => $data['budget_max'] ?? null,
            'deadline' => $data['deadline'] ?? null,
        ]);

        $this->storeAttachments($httpRequest, $request);

        $redirectRoute = $organization && Route::has('organization.dashboard.requests')
            ? route('organization.dashboard.requests', ['organization' => $organization->slug])
            : route('dashboard.requests');

        return redirect($redirectRoute)->with('success', __('requests.notification.updated'));
    }

    public function destroy(ServiceRequest $request): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $request->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('delete', $request);

        $request->update(['status' => 'closed']);

        return redirect()->route('dashboard')->with('success', __('requests.notification.closed'));
    }

    private function validationRules(Organization $organization, array $deadlineRule): array
    {
        return [
            'title' => 'required|string|min:10|max:255',
            'description' => 'required|string|min:100',
            'category_id' => [
                'bail',
                'required',
                'uuid',
                Rule::exists((new Category)->getTable(), 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organization->id)),
            ],
            'delivery_mode' => 'required|in:remote,onsite,both',
            'budget_min' => array_merge(['required', 'integer'], $this->pointLimitRules($organization)),
            'budget_max' => array_merge(['nullable', 'integer', 'gte:budget_min'], $this->pointLimitRules($organization)),
            'deadline' => $deadlineRule['deadline'],
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx|max:10240',
            'relay_loop_id' => ['bail', 'nullable', 'uuid'],
            // TASK-1749 — jamais une regle `exists` : l'appartenance est
            // verifiee par `demandeARelancer()`, qui borne aussi a l'auteur et
            // au statut. Une regle `exists` ici confirmerait a qui bricole le
            // champ qu'un identifiant existe quelque part.
            'relancer_de' => ['bail', 'nullable', 'uuid'],
        ];
    }

    /**
     * TASK-1749 (demande Cyril, 04/11) — la demande que l'on RELANCE.
     *
     * ## Pourquoi relancer, et pas rouvrir
     *
     * Une demande pourvue ne peut pas etre modifiee : `ServiceRequestPolicy`
     * refuse des qu'une transaction la reference, et c'est juste — editer
     * reecrirait les termes d'un echange deja conclu, qui pointe dessus par
     * `request_id`.
     *
     * Rouvrir la meme ligne aurait fait pointer un echange TERMINE vers une
     * demande OUVERTE : le badge du tableau de bord, l'historique d'echanges et
     * l'ecriture de points auraient dit trois choses differentes du meme objet.
     * On cree donc une demande NEUVE, et l'ancienne reste le registre de ce qui
     * s'est passe.
     *
     * ## Les quatre conditions, et aucune n'est decorative
     *
     * Ce jeton arrive d'une URL ou d'un champ cache : il est a la portee de
     * n'importe qui.
     *
     *  - l'Organization COURANTE, sinon on recopierait le contenu d'un autre
     *    locataire ;
     *  - l'AUTEUR, sinon on recopierait le texte et les PIECES JOINTES de
     *    quelqu'un d'autre — c'est la condition qui compte le plus ici, puisque
     *    les fichiers sont dupliques ;
     *  - le statut `closed`, parce que c'est le seul perimetre demande : une
     *    demande encore ouverte se modifie, elle n'a pas besoin d'etre relancee ;
     *  - l'existence, evidemment.
     *
     * `null` quand le parametre est absent. `abort(404)` quand il est present et
     * ne satisfait pas les conditions : repondre un formulaire vierge laisserait
     * croire que la relance a marche.
     */
    private function demandeARelancer(?string $id, User $user, Organization $organization): ?ServiceRequest
    {
        if ($id === null || $id === '') {
            return null;
        }

        $source = ServiceRequest::query()
            ->whereKey($id)
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('status', 'closed')
            ->first();

        if ($source === null) {
            abort(404);
        }

        return $source;
    }

    /**
     * TASK-1749 — les pieces jointes sont REPRISES (arbitrage Cyril).
     *
     * Le fichier est COPIE sur le disque, et ce n'est pas un detail : la
     * suppression d'une piece jointe efface l'original
     * (`Storage::disk('public')->delete($attachment->path)`, methode `update`).
     * Reutiliser le meme chemin aurait donc rendu la nouvelle demande
     * dependante de l'ancienne — supprimer la piece jointe d'un echange conclu
     * aurait casse l'illustration d'une demande vivante.
     *
     * Une copie qui echoue ne fait pas echouer la relance : la demande neuve
     * existe deja et a ete publiee. On saute le fichier plutot que de laisser
     * une ligne pointer vers un chemin vide.
     */
    private function copierLesPiecesJointes(ServiceRequest $source, ServiceRequest $nouvelle): void
    {
        foreach ($source->attachments()->orderBy('order')->get() as $index => $piece) {
            if (! Storage::disk('public')->exists($piece->path)) {
                continue;
            }

            $copie = 'request-attachments/'.Str::uuid()->toString()
                .($piece->path !== '' && str_contains($piece->path, '.')
                    ? '.'.pathinfo($piece->path, PATHINFO_EXTENSION)
                    : '');

            if (! Storage::disk('public')->copy($piece->path, $copie)) {
                continue;
            }

            $nouvelle->attachments()->create([
                'path' => $copie,
                'original_name' => $piece->original_name,
                'mime_type' => $piece->mime_type,
                'order' => $index,
                'organization_id' => $nouvelle->organization_id,
            ]);
        }
    }

    private function organizationFor(?User $user): Organization
    {
        $organization = currentOrganization();

        if (! $organization || ! $user || $user->isDeactivated() || $user->organization_id !== $organization->id) {
            abort(404);
        }

        return $organization;
    }

    private function relayLoopsFor(Organization $organization, User $user)
    {
        return Loop::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereHas('members', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('status', 'active'))
            // TASK-1553 — « qui va voir cela ? » est la question qu'on se pose
            // avant de publier. Le compte s'obtient par UNE clause sur la
            // requete deja executee : aucune seconde requete, aucun N+1.
            //
            // ACL-safe par construction : ce `whereHas` ne laisse passer que
            // des Boucles dont cette personne est membre ACTIVE. On ne compte
            // jamais les membres d'une Boucle qu'elle ne peut pas voir.
            ->withCount(['members' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')
            ->get(['id', 'name', 'organization_id']);
    }

    private function relayLoopOrNull(mixed $loopId, Organization $organization, User $user): ?Loop
    {
        if ($loopId === null || $loopId === '') {
            return null;
        }

        if (! is_string($loopId) || ! Str::isUuid($loopId)) {
            return null;
        }

        return Loop::query()
            ->whereKey($loopId)
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereHas('members', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('status', 'active'))
            ->first();
    }

    private function nonDestructiveSuggestion(string $current, ?string $candidate, bool $title = false): string
    {
        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            return $current;
        }

        if ($title && in_array($this->normalizedSuggestion($candidate), [
            'demande d’aide',
            "demande d'aide",
            'nouvelle demande',
        ], true)) {
            return $current;
        }

        return $candidate;
    }

    private function normalizedSuggestion(string $value): string
    {
        return mb_strtolower(Str::squish($value));
    }

    private function pointLimitRules(Organization $organization): array
    {
        return array_values(array_filter([
            $organization->servicePointsMin() !== null ? 'min:'.$organization->servicePointsMin() : null,
            $organization->servicePointsMax() !== null ? 'max:'.$organization->servicePointsMax() : null,
        ]));
    }
}
