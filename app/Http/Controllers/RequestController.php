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

        $categories = Category::where('organization_id', $organization?->id)->with('pointGuidelines')->get();
        $relayLoops = $this->relayLoopsFor($organization, $user);

        return view('requests.create', compact('categories', 'organization', 'relayLoops'));
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

        $result = $this->clarifier->clarifyForOrganization($organization, $user, $intention);

        if ($result->isBlocked()) {
            return response()->json([
                'error' => $result->fallback['reason'] ?? __('ai.request_formulation_error'),
            ], 422);
        }

        return response()->json([
            'suggestion' => [
                'title' => $result->title,
                'description' => $result->messageDraft ?: $result->need,
            ],
        ]);
    }

    public function store(Request $httpRequest): RedirectResponse
    {
        $user = $httpRequest->user();
        $organization = $this->organizationFor($user);

        $data = $httpRequest->validate($this->validationRules($organization, ['deadline' => 'nullable|date|after:today']), [], __('marketplace.validation_attributes'));
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
        ];
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

    private function pointLimitRules(Organization $organization): array
    {
        return array_values(array_filter([
            $organization->servicePointsMin() !== null ? 'min:'.$organization->servicePointsMin() : null,
            $organization->servicePointsMax() !== null ? 'max:'.$organization->servicePointsMax() : null,
        ]));
    }
}
