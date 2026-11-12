<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BulkEmailSend;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Services\BulkEmailSendService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * TASK-1803 — « Envoi emails » : cibler toutes les Organizations ou une
 * seule, tous les membres ou seulement ceux sans presentation, avec un
 * contenu libre (brut ou HTML via TipTap) ou un modele existant.
 *
 * `is_admin` est verifie sur CHAQUE action, en plus du groupe `['auth','admin']` :
 * meme defense en profondeur que `AdminLoopTypeController`/
 * `AdminLoopPluginController`/`AdminLoopPermissionController` — un outil qui
 * envoie un email a potentiellement toutes les Organizations n'a pas droit a
 * une seule garde.
 */
class AdminEmailBulkSendingController extends Controller
{
    public const SCOPE_ALL_ORGANIZATIONS = 'all';

    public function __construct(private readonly BulkEmailSendService $service) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->is_admin, 403);

        return view('admin.email-bulksending.create', [
            'organizations' => Organization::where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug']),
            'templates' => EmailTemplate::orderBy('name')->get(['id', 'name', 'slug', 'subject', 'content_html']),
            'inProgress' => BulkEmailSend::whereIn('status', [BulkEmailSend::STATUS_DRAFT, BulkEmailSend::STATUS_SENDING])
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $validated = $this->validatePayload($request);

        $organizationId = $validated['organization_id'] === self::SCOPE_ALL_ORGANIZATIONS ? null : $validated['organization_id'];
        $count = $this->service->countRecipients($organizationId, $validated['member_filter']);

        $template = $validated['content_source'] === BulkEmailSend::CONTENT_SOURCE_TEMPLATE
            ? EmailTemplate::find($validated['email_template_id'])
            : null;

        // TASK-1803 correctif — sans ca, le recapitulatif affichait
        // `{{ first_name }}` au mot pres : un destinataire REEL sert
        // d'exemple, avec SES vraies valeurs (chaque autre recoit les
        // siennes a l'envoi). `previewIndex` vient d'un bouton "suivant"/
        // "precedent" (re-POST du meme ecran), pas d'un champ valide par
        // `validatePayload()` — il ne fait pas partie du contrat soumis a
        // `store()`, juste de la navigation dans l'apercu. Fige entre 0 et
        // `count - 1` : un index perime (le filtre a change entre deux
        // clics) retombe sur le premier destinataire plutot que planter.
        $previewIndex = $count > 0 ? max(0, min($request->integer('preview_index'), $count - 1)) : 0;
        $previewUser = $this->service->resolveRecipients($organizationId, $validated['member_filter'])
            ->with('organization')
            ->skip($previewIndex)
            ->first();
        $preview = $previewUser ? $this->service->previewFor($validated, $template, $previewUser) : null;

        return view('admin.email-bulksending.confirm', [
            'organizationId' => $organizationId,
            'organization' => $organizationId ? Organization::find($organizationId) : null,
            'count' => $count,
            'validated' => $validated,
            'template' => $template,
            'previewUser' => $previewUser,
            'preview' => $preview,
            'previewIndex' => $previewIndex,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $validated = $this->validatePayload($request);
        $organizationId = $validated['organization_id'] === self::SCOPE_ALL_ORGANIZATIONS ? null : $validated['organization_id'];

        $send = $this->service->createSend(array_merge($validated, ['organization_id' => $organizationId]), $request->user());

        if ($send->total_recipients === 0) {
            return redirect()->route('admin.email-bulksending')
                ->withErrors(['organization_id' => __('admin.bulk_email_no_recipients')]);
        }

        return redirect()->route('admin.email-bulksending.show', $send);
    }

    public function show(Request $request, BulkEmailSend $bulkEmailSend): View
    {
        abort_unless($request->user()?->is_admin, 403);

        $bulkEmailSend->loadCount([
            'recipients as pending_count' => fn ($q) => $q->where('status', 'pending'),
        ]);

        return view('admin.email-bulksending.show', ['send' => $bulkEmailSend]);
    }

    public function sendBatch(Request $request, BulkEmailSend $bulkEmailSend): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $tally = $this->service->sendNextBatch($bulkEmailSend, 50);

        return redirect()->route('admin.email-bulksending.show', $bulkEmailSend)
            ->with('success', __('admin.bulk_email_batch_result', [
                'sent' => $tally['sent'],
                'failed' => $tally['failed'],
                'remaining' => $tally['remaining'],
            ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'organization_id' => [
                'required', 'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === self::SCOPE_ALL_ORGANIZATIONS) {
                        return;
                    }
                    if (! Str::isUuid($value) || ! Organization::whereKey($value)->exists()) {
                        $fail(__('admin.bulk_email_scope_unknown'));
                    }
                },
            ],
            'member_filter' => ['required', Rule::in(BulkEmailSend::MEMBER_FILTERS)],
            'content_source' => ['required', Rule::in(BulkEmailSend::CONTENT_SOURCES)],
            'email_template_id' => ['required_if:content_source,'.BulkEmailSend::CONTENT_SOURCE_TEMPLATE, 'nullable', Rule::exists('email_templates', 'id')],
            'subject' => ['required_if:content_source,'.BulkEmailSend::CONTENT_SOURCE_CUSTOM, 'nullable', 'string', 'max:255'],
            'content_format' => ['required_if:content_source,'.BulkEmailSend::CONTENT_SOURCE_CUSTOM, 'nullable', Rule::in(BulkEmailSend::CONTENT_FORMATS)],
            'content_raw' => ['required_if:content_source,'.BulkEmailSend::CONTENT_SOURCE_CUSTOM, 'nullable', 'string'],
        ]);
    }
}
