<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\CrmStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmStatusService;
use App\Services\Crm\CrmTimelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $contacts = $query
            ->orderByRaw('last_interaction_at IS NULL')
            ->orderByDesc('last_interaction_at')
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
