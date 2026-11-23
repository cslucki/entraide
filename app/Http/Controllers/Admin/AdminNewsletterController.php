<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BulkEmailSend;
use App\Models\BulkEmailSendRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Services\BulkEmailSendService;
use App\Support\Email\HelpDigestBlocks;
use App\Support\Email\SystemEmailTemplateResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * TASK-1883 (demande Cyril, 22/11) — « Newsletter » (Email&Messages) : la
 * newsletter des demandes et propositions d'aide, envoyee a la main quand on
 * veut (arbitrage n°3), par le circuit BulkEmailSend (Managed Queue).
 */
class AdminNewsletterController extends Controller
{
    /** Un second envoi dans ce delai demande une confirmation explicite. */
    public const DELAI_DOUBLON_JOURS = 6;

    public function __construct(private readonly BulkEmailSendService $envois) {}

    public function index(): View
    {
        $gabarit = app(SystemEmailTemplateResolver::class)->resolve(BulkEmailSendService::NEWSLETTER_SLUG, null, 'fr');

        return view('admin.newsletter.index', [
            'organizations' => Organization::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug', 'is_default']),
            'gabarit' => $gabarit,
            'historique' => BulkEmailSend::query()
                ->where('content_source', BulkEmailSend::CONTENT_SOURCE_NEWSLETTER)
                ->with(['organizations:id,name', 'creator:id,first_name,name'])
                ->latest()
                ->limit(10)
                ->get(),
            'dernier' => $this->dernierEnvoiRecent()?->load('organizations:id,name'),
        ]);
    }

    /** Apercu reel : donnees de l'organisation choisie, nombres saisis, langue choisie. */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->valider($request, apercu: true);
        $ids = $data['organization_ids'] ?? [];

        $org = Organization::query()->whereKey($data['preview_organization_id'] ?? ($ids[0] ?? null))->first()
            ?? Organization::query()->where('is_default', true)->first()
            ?? Organization::query()->where('is_active', true)->orderBy('name')->firstOrFail();

        $exemple = new User;
        $exemple->forceFill([
            'id' => (string) Str::uuid(),
            'first_name' => $data['locale'] === 'en' ? 'Jordan' : 'Camille',
            'name' => $data['locale'] === 'en' ? 'Smith' : 'Durand',
            'email' => 'apercu@example.test',
            'organization_id' => $org->id,
            'preferred_locale' => $data['locale'],
        ])->setRelation('organization', $org);

        $envoi = new BulkEmailSend([
            'system_template_slug' => BulkEmailSendService::NEWSLETTER_SLUG,
            'subject' => $data['subject'] ?? null,
            'demand_count' => $data['demand_count'],
            'offer_count' => $data['offer_count'],
            'blog_count' => $data['blog_count'],
            'event_count' => $data['event_count'],
        ]);

        $rendu = $this->envois->newsletterPour($exemple, $envoi, apercu: true);

        return response()->json([
            'organization' => $org->name,
            'subject' => $rendu['subject'] ?? null,
            'html' => $rendu['body'] ?? null,
            'recipients' => $this->envois->newsletterRecipients($ids, $data['demand_count'], $data['offer_count'], $data['blog_count'], $data['event_count'])->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->valider($request);

        if ($this->dernierEnvoiRecent() !== null && ! $request->boolean('confirm_repeat')) {
            return back()->withInput()->with('error', 'Une newsletter est déjà partie il y a moins de '.self::DELAI_DOUBLON_JOURS.' jours : cochez la confirmation pour envoyer quand même.');
        }

        $send = $this->envois->createNewsletterSend([
            'organization_ids' => $data['organization_ids'] ?? [],
            'subject' => $data['subject'] ?? null,
            'demand_count' => $data['demand_count'],
            'offer_count' => $data['offer_count'],
            'blog_count' => $data['blog_count'],
            'event_count' => $data['event_count'],
        ], $request->user());

        return redirect()->route('admin.newsletter.show', $send);
    }

    public function show(BulkEmailSend $send): View
    {
        abort_unless($send->estNewsletter(), 404);

        return view('admin.newsletter.show', ['send' => $send->load('organizations:id,name'), 'progression' => $this->etat($send)]);
    }

    /** Met le lot suivant (50) en file ; appele en boucle par l'ecran de progression. */
    public function batch(BulkEmailSend $send): JsonResponse
    {
        abort_unless($send->estNewsletter(), 404);

        $this->envois->sendNextBatch($send, 50);

        return response()->json($this->etat($send->fresh()));
    }

    public function progress(BulkEmailSend $send): JsonResponse
    {
        abort_unless($send->estNewsletter(), 404);

        return response()->json($this->etat($send->fresh()));
    }

    /** @return array<string, int|bool> */
    private function etat(BulkEmailSend $send): array
    {
        $parStatut = $send->recipients()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $n = fn (string $statut) => (int) ($parStatut[$statut] ?? 0);

        $etat = [
            'total' => (int) $send->total_recipients,
            'pending' => $n(BulkEmailSendRecipient::STATUS_PENDING),
            'queued' => $n(BulkEmailSendRecipient::STATUS_QUEUED) + $n(BulkEmailSendRecipient::STATUS_PROCESSING),
            'sent' => $n(BulkEmailSendRecipient::STATUS_SENT),
            'failed' => $n(BulkEmailSendRecipient::STATUS_FAILED),
            'ambiguous' => $n(BulkEmailSendRecipient::STATUS_AMBIGUOUS),
            'skipped' => $n(BulkEmailSendRecipient::STATUS_SKIPPED),
        ];
        $etat['done'] = $etat['pending'] === 0 && $etat['queued'] === 0;

        return $etat;
    }

    private function dernierEnvoiRecent(): ?BulkEmailSend
    {
        return BulkEmailSend::query()
            ->where('content_source', BulkEmailSend::CONTENT_SOURCE_NEWSLETTER)
            ->where('total_recipients', '>', 0)
            ->where('created_at', '>=', now()->subDays(self::DELAI_DOUBLON_JOURS))
            ->latest()
            ->first();
    }

    /** @return array{organization_ids?: list<string>, subject?: ?string, demand_count: int, offer_count: int, blog_count: int, event_count: int, locale?: string} */
    private function valider(Request $request, bool $apercu = false): array
    {
        $data = $request->validate([
            'scope' => ['required', 'in:all,selection'],
            'organization_ids' => ['array', 'required_if:scope,selection'],
            'organization_ids.*' => ['uuid', 'exists:organizations,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'demand_count' => ['required', 'integer', 'min:0', 'max:'.HelpDigestBlocks::MAX],
            'offer_count' => ['required', 'integer', 'min:0', 'max:'.HelpDigestBlocks::MAX],
            // Demande Cyril (22/11) : derniers billets de blog, 0 = aucun.
            'blog_count' => ['nullable', 'integer', 'min:0', 'max:'.HelpDigestBlocks::MAX],
            // TASK-1891 (demande Cyril, 23/11) : prochains evenements de l'agenda, 0 = aucun.
            'event_count' => ['nullable', 'integer', 'min:0', 'max:'.HelpDigestBlocks::MAX],
            'locale' => [$apercu ? 'required' : 'nullable', 'in:fr,en'],
            'preview_organization_id' => ['nullable', 'uuid'],
        ]);

        // Le champ arrive pre-rempli avec l'objet FR du modele : inchange, il ne
        // s'impose pas aux membres anglophones, qui gardent l'objet EN.
        $objetFr = app(SystemEmailTemplateResolver::class)->resolve(BulkEmailSendService::NEWSLETTER_SLUG, null, 'fr')?->subject;
        if (blank($data['subject'] ?? null) || trim((string) $data['subject']) === trim((string) $objetFr)) {
            $data['subject'] = null;
        }

        if ($data['scope'] === 'all') {
            $data['organization_ids'] = [];
        }

        $data['demand_count'] = (int) $data['demand_count'];
        $data['offer_count'] = (int) $data['offer_count'];
        $data['blog_count'] = (int) ($data['blog_count'] ?? 0);
        $data['event_count'] = (int) ($data['event_count'] ?? 0);

        return $data;
    }
}
