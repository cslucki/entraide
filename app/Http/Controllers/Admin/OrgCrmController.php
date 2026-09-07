<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\CrmStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmContactPolicyService;
use App\Services\Crm\CrmEmailSendService;
use App\Services\Crm\CrmEmailTemplateService;
use App\Services\Crm\CrmNextActionService;
use App\Services\Crm\CrmStatusService;
use App\Services\Crm\CrmTimelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LogicException;

/**
 * TASK-1416 — CRM-4 : « Relations », la premiere surface OrgAdmin du Mini-CRM.
 *
 * Controller DEDIE (OrgAdminController depasse 1000 lignes). Tout ce qui est
 * ecrit passe par les services CRM (T1413/T1414/T1415) : ce controller ne
 * connait ni la deduplication, ni la timeline, ni le pipeline — il valide,
 * resout dans le tenant, delegue, et redirige.
 *
 * Tenant : la route est sous `OrgAdminMiddleware` ; chaque `{contact}` est
 * resolu DANS l'Organization de la route (`forOrganization`), jamais par un
 * binding global — un Contact d'ailleurs donne un 404, pas un 403 qui
 * revelerait son existence.
 */
class OrgCrmController extends Controller
{
    public const IDLE_DAYS = 30;

    public function __construct(
        private readonly CrmContactService $contacts,
        private readonly CrmStatusService $statuses,
        private readonly CrmTimelineService $timeline,
        private readonly CrmNextActionService $nextActions,
        private readonly CrmEmailSendService $emails,
        private readonly CrmEmailTemplateService $emailTemplates,
        private readonly CrmContactPolicyService $policy,
    ) {}

    public function contacts(Request $request, Organization $organization): View
    {
        $this->statuses->ensureDefaultPipeline($organization);
        $statuses = CrmStatus::forOrganization($organization)->active()->ordered()->get();

        $query = CrmContact::forOrganization($organization)->with('status');

        $search = trim((string) $request->input('search'));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            // Telephone : on compare des CHIFFRES a des chiffres (« 06 11 22 »
            // trouve « +33 6 11 22 … » par sa sous-chaine), sans deviner de
            // pays — la vraie normalisation est CRM-10.
            $digits = preg_replace('/\D+/', '', $search);
            $query->where(function ($q) use ($needle, $digits) {
                $q->whereRaw('LOWER(first_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(company) LIKE ?', [$needle]);
                if ($digits !== '') {
                    $q->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
            });
        }

        // Un statut qui n'est pas de l'Organization est simplement ignore :
        // il ne filtre rien et ne revele rien.
        $statusFilter = (string) $request->input('status');
        if ($statusFilter !== '' && $statuses->contains('id', $statusFilter)) {
            $query->where('status_id', $statusFilter);
        } else {
            $statusFilter = '';
        }

        $idle = $request->boolean('idle');
        if ($idle) {
            $query->where(function ($q) {
                $q->whereNull('last_interaction_at')
                    ->orWhere('last_interaction_at', '<', now()->subDays(self::IDLE_DAYS));
            });
        }

        // TASK-1418 — « que dois-je faire aujourd'hui ? » : Aujourd'hui / En retard /
        // Cette semaine (semaine ISO, jusqu'a dimanche). Avec une echeance
        // active, on trie par echeance ; sinon par dernier contact.
        $due = (string) $request->input('due');
        $now = now();
        match ($due) {
            // whereDate : la colonne DATE s'ecrit « Y-m-d H:i:s » sur SQLite (cast
            // Eloquent) et « Y-m-d » sur PostgreSQL ; comparer des JOURS, pas des chaines.
            'today' => $query->whereDate('next_action_date', $now->toDateString()),
            'overdue' => $query->whereDate('next_action_date', '<', $now->toDateString()),
            'week' => $query->whereDate('next_action_date', '>=', $now->toDateString())
                ->whereDate('next_action_date', '<=', $now->copy()->endOfWeek()->toDateString()),
            default => $due = '',
        };

        if ($due !== '') {
            $query->orderBy('next_action_date')->orderByRaw('next_action_time IS NULL')->orderBy('next_action_time');
        } else {
            $query->orderByRaw('last_interaction_at IS NULL')
                ->orderByDesc('last_interaction_at');
        }

        $contacts = $query
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.org.crm.contacts', [
            'organization' => $organization,
            'contacts' => $contacts,
            'statuses' => $statuses,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'idle' => $idle,
            'channels' => CrmTimelineService::CHANNELS,
            'idleDays' => self::IDLE_DAYS,
            'due' => $due,
            'actionTypes' => CrmNextActionService::TYPES,
        ]);
    }

    /**
     * TASK-1417 — CRM-5 : la fiche. Le Contact courant fait autorite ; la
     * timeline se lit du plus recent au plus ancien.
     */
    public function show(Organization $organization, string $contact): View
    {
        $contact = $this->resolveContact($organization, $contact);
        $this->statuses->ensureDefaultPipeline($organization);

        return view('admin.org.crm.show', [
            'organization' => $organization,
            'contact' => $contact->load(['status', 'user', 'createdBy']),
            'statuses' => CrmStatus::forOrganization($organization)->active()->ordered()->get(),
            'events' => $contact->events()->with('author')->chronological()->get()->reverse()->values(),
            'channels' => CrmTimelineService::CHANNELS,
            'actionTypes' => CrmNextActionService::TYPES,
            'emailTemplates' => $this->emailTemplates->forOrganization($organization)->orderBy('name')->get(),
            'policyReasons' => CrmContactPolicyService::REASONS,
        ]);
    }

    /**
     * TASK-1417 — corriger une coordonnee sans supprimer/recreer : chaque
     * changement est un fait `contact_updated` de la MEME timeline. La
     * deduplication tenant-scoped reste souveraine : un email deja porte par
     * un autre Contact de l'Organization est refuse (rien n'est ecrit).
     */
    public function updateContact(Request $request, Organization $organization, string $contact): RedirectResponse
    {
        $contact = $this->resolveContact($organization, $contact);

        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:30', 'required_without:email'],
            'company' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $changes = $this->contacts->update($contact, $data, $request->user());
        } catch (LogicException) {
            return back()->withInput()->with('error', __('crm.flash_update_conflict'));
        }

        return redirect()->route('organization.admin.crm.contacts.show', ['organization' => $organization->slug, 'contact' => $contact->id])
            ->with('success', $changes === [] ? __('crm.flash_contact_unchanged') : __('crm.flash_contact_updated'));
    }

    public function storeContact(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:30', 'required_without:email'],
            'company' => ['nullable', 'string', 'max:150'],
        ]);

        $contact = $this->contacts->findOrCreate($organization, $data + ['source' => CrmContact::SOURCE_MANUAL], $request->user());

        return redirect()->route('organization.admin.crm.contacts', ['organization' => $organization->slug])
            ->with('success', $contact->wasRecentlyCreated ? __('crm.flash.contact_created') : __('crm.flash.contact_found'));
    }

    public function changeStatus(Request $request, Organization $organization, string $contact): RedirectResponse
    {
        $contact = $this->resolveContact($organization, $contact);

        $data = $request->validate([
            'status_id' => ['required', 'string'],
        ]);

        // Le statut aussi est resolu DANS l'Organization : un id d'ailleurs = 404.
        $status = CrmStatus::forOrganization($organization)->active()->whereKey($data['status_id'])->firstOrFail();

        $this->statuses->changeStatus($contact, $status, $request->user());

        return back()->with('success', __('crm.flash.status_changed', ['status' => $status->label]));
    }

    public function storeNote(Request $request, Organization $organization, string $contact): RedirectResponse
    {
        $contact = $this->resolveContact($organization, $contact);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.CrmTimelineService::MAX_NOTE_LENGTH],
            'channel' => ['nullable', 'string', Rule::in(CrmTimelineService::CHANNELS)],
        ]);

        $this->timeline->addNote($contact, $data['body'], $request->user(), $data['channel'] ?? null);

        return back()->with('success', __('crm.flash.note_added'));
    }

    /**
     * TASK-1418 — planifier (ou remplacer) la prochaine action : un JOUR et une
     * heure OPTIONNELLE, jamais d'heure inventee ; « en retard » = jour passe.
     */
    public function planNextAction(Request $request, Organization $organization, string $contact): RedirectResponse
    {
        $contact = $this->resolveContact($organization, $contact);

        $data = $request->validate([
            'next_action_type' => ['required', 'string', Rule::in(CrmNextActionService::TYPES)],
            'next_action_date' => ['required', 'date_format:Y-m-d'],
            'next_action_time' => ['nullable', 'date_format:H:i'],
            'next_action_label' => ['nullable', 'string', 'max:'.CrmNextActionService::MAX_LABEL_LENGTH],
        ]);

        $this->nextActions->plan($contact, $data['next_action_type'], Carbon::parse($data['next_action_date']), $data['next_action_time'] ?? null, $data['next_action_label'] ?? null, $request->user());

        return back()->with('success', __('crm.flash_next_action_planned'));
    }

    public function completeNextAction(Request $request, Organization $organization, string $contact): RedirectResponse
    {
        $contact = $this->resolveContact($organization, $contact);

        $done = $this->nextActions->complete($contact, $request->user());

        return back()->with('success', $done ? __('crm.flash_next_action_done') : __('crm.flash_next_action_nothing'));
    }

    // ── TASK-1419 — CRM-4b : gestion du pipeline ─────────────────────────────

    public function statuses(Organization $organization): View
    {
        $this->statuses->ensureDefaultPipeline($organization);

        return view('admin.org.crm.statuses', [
            'organization' => $organization,
            'statuses' => CrmStatus::forOrganization($organization)->ordered()->withCount('contacts')->get(),
        ]);
    }

    public function storeStatus(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'no_color' => ['nullable', 'boolean'],
        ]);

        // Le pipeline initial est seme AVANT toute creation manuelle : un statut
        // cree en premier ne doit pas priver l'Organization de ses six statuts.
        $this->statuses->ensureDefaultPipeline($organization);

        try {
            $this->statuses->create($organization, $data['label'], $request->boolean('no_color') ? null : ($data['color'] ?? null));
        } catch (LogicException) {
            return back()->withInput()->with('error', __('crm.flash_status_label_taken'));
        }

        return redirect()->route('organization.admin.crm.statuses', ['organization' => $organization->slug])->with('success', __('crm.flash_status_created'));
    }

    public function updateStatus(Request $request, Organization $organization, string $status): RedirectResponse
    {
        $status = $this->resolveStatus($organization, $status);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        try {
            $this->statuses->edit($status, $data['label'], $data['color'] ?? null);
        } catch (LogicException) {
            return back()->with('error', __('crm.flash_status_label_taken'));
        }

        return back()->with('success', __('crm.flash_status_updated'));
    }

    /**
     * Monter / descendre d'un cran : l'ordre COMPLET est recalcule dans
     * l'Organization et confie a `reorder`, qui refuse tout id etranger.
     */
    public function moveStatus(Request $request, Organization $organization, string $status): RedirectResponse
    {
        $status = $this->resolveStatus($organization, $status);
        $direction = $request->input('direction') === 'up' ? -1 : 1;

        $ids = CrmStatus::forOrganization($organization)->ordered()->pluck('id')->all();
        $i = array_search($status->id, $ids, true);
        $j = $i + $direction;

        if ($i !== false && $j >= 0 && $j < count($ids)) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            $this->statuses->reorder($organization, $ids);
        }

        return back()->with('success', __('crm.flash_status_moved'));
    }

    public function toggleStatus(Organization $organization, string $status): RedirectResponse
    {
        $status = $this->resolveStatus($organization, $status);

        try {
            if ($status->is_active) {
                $this->statuses->deactivate($status);

                return back()->with('success', __('crm.flash_status_deactivated'));
            }

            $this->statuses->activate($status);

            return back()->with('success', __('crm.flash_status_activated'));
        } catch (LogicException) {
            return back()->with('error', __('crm.flash_status_default_cannot_deactivate'));
        }
    }

    public function defaultStatus(Organization $organization, string $status): RedirectResponse
    {
        $status = $this->resolveStatus($organization, $status);

        try {
            $this->statuses->setDefault($status);
        } catch (LogicException) {
            return back()->with('error', __('crm.flash_status_inactive_cannot_default'));
        }

        return back()->with('success', __('crm.flash_status_default'));
    }

    private function resolveStatus(Organization $organization, string $id): CrmStatus
    {
        return CrmStatus::forOrganization($organization)->whereKey($id)->firstOrFail();
    }

    // ── TASK-1421 — CRM-7b : envoyer un email au Contact ───────────────────────

    /** Le select de la fiche envoie ici ; on redirige vers la confirmation du modele choisi. */
    public function pickEmailTemplate(Request $request, Organization $organization, string $contact): RedirectResponse
    {
        $contact = $this->resolveContact($organization, $contact);
        $template = $this->emailTemplates->resolve($organization, (string) $request->input('template'));

        return redirect()->route('organization.admin.crm.contacts.email.preview', ['organization' => $organization->slug, 'contact' => $contact->id, 'template' => $template->id]);
    }

    /**
     * Confirmation : rendu pour CE Contact, jeton one-shot pose en session.
     * Les gardes fail-closed s'appliquent ICI aussi : un Contact non
     * contactable n'a meme pas de preview.
     */
    public function previewEmail(Request $request, Organization $organization, string $contact, string $template): View|RedirectResponse
    {
        $contact = $this->resolveContact($organization, $contact);
        $template = $this->emailTemplates->resolve($organization, $template);

        try {
            $this->emails->guard($contact, $template, $request->user());
        } catch (LogicException $e) {
            return redirect()->route('organization.admin.crm.contacts.show', ['organization' => $organization->slug, 'contact' => $contact->id])
                ->with('error', __('crm.email.flash_blocked', ['reason' => __('crm.email.reason.'.$e->getMessage())]));
        }

        return view('admin.org.crm.email', [
            'organization' => $organization,
            'contact' => $contact,
            'template' => $template,
            'rendered' => $this->emails->render($contact, $template),
            'token' => $this->emails->issueToken($contact, $template),
        ]);
    }

    public function sendEmail(Request $request, Organization $organization, string $contact, string $template): RedirectResponse
    {
        $contact = $this->resolveContact($organization, $contact);
        $template = $this->emailTemplates->resolve($organization, $template);
        $back = redirect()->route('organization.admin.crm.contacts.show', ['organization' => $organization->slug, 'contact' => $contact->id]);

        // Le jeton est consomme AVANT tout : un second clic n'envoie rien.
        if (! $this->emails->consumeToken($contact, $template, $request->input('token'))) {
            return $back->with('error', __('crm.email.flash_token'));
        }

        try {
            $log = $this->emails->send($contact, $template, $request->user());
        } catch (LogicException $e) {
            return $back->with('error', __('crm.email.flash_blocked', ['reason' => __('crm.email.reason.'.$e->getMessage())]));
        }

        if ($log->status === \App\Models\EmailLog::STATUS_SENT) {
            return $back->with('success', __('crm.email.flash_sent', ['to' => $log->to_email]));
        }

        return $back->with('error', __('crm.email.flash_failed', ['error' => \Illuminate\Support\Str::limit((string) $log->error_message, 120)]));
    }

    /**
     * TASK-1422 — CRM-13 : « ne plus contacter » / « autoriser a nouveau », avec
     * une raison bornee. Le meme etat redemande n'ecrit rien.
     */
    public function changePolicy(Request $request, Organization $organization, string $contact): RedirectResponse
    {
        $contact = $this->resolveContact($organization, $contact);

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['block', 'allow'])],
            'reason' => ['required', 'string', Rule::in(CrmContactPolicyService::REASONS)],
            'note' => ['nullable', 'string', 'max:'.CrmContactPolicyService::MAX_NOTE_LENGTH],
        ]);

        $event = $data['action'] === 'block'
            ? $this->policy->block($contact, $data['reason'], $data['note'] ?? null, $request->user())
            : $this->policy->allow($contact, $data['reason'], $data['note'] ?? null, $request->user());

        if ($event === null) {
            return back()->with('success', __('crm.policy.flash_unchanged'));
        }

        return back()->with('success', $data['action'] === 'block' ? __('crm.policy.flash_blocked') : __('crm.policy.flash_allowed'));
    }

    /**
     * « Ajouter au suivi » : un membre ne devient un Contact que par decision
     * explicite de l'OrgAdmin (MASTER Q1/Q12). Meme Organization obligatoire ;
     * idempotent : un second clic retrouve le meme Contact.
     */
    public function followMember(Request $request, Organization $organization, User $user): RedirectResponse
    {
        abort_unless($user->organization_id === $organization->id, 404);

        try {
            $contact = $this->contacts->findOrCreate($organization, [
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->name,
                'phone' => $user->phone,
                'source' => CrmContact::SOURCE_MANUAL,
            ], $request->user());

            $this->contacts->linkToUser($contact, $user);
        } catch (LogicException $e) {
            return back()->with('error', __('crm.flash.follow_conflict'));
        }

        return redirect()->route('organization.admin.crm.contacts', ['organization' => $organization->slug, 'search' => $user->email])
            ->with('success', $contact->wasRecentlyCreated ? __('crm.flash.member_followed') : __('crm.flash.member_already_followed'));
    }

    private function resolveContact(Organization $organization, string $id): CrmContact
    {
        return CrmContact::forOrganization($organization)->whereKey($id)->firstOrFail();
    }
}
