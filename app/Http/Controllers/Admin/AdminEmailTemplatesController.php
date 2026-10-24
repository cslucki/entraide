<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmailerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminEmailTemplatesController extends Controller
{
    /**
     * TASK-1694 bug AA — la valeur qui designe « sans Organization ».
     *
     * Hors de l'espace des UUID, comme `FILTRE_GLOBAL` au bug O : une chaine
     * forgee ne peut pas se faire passer pour elle.
     */
    public const CIBLE_GLOBALE = 'global';

    /**
     * TASK-1694 bug AC — toutes les Organizations, et de quoi en isoler une.
     *
     * ## La demande
     *
     * Cyril : « on devrait voir tous les emails types, toutes organisations
     * confondues, et avoir un menu pour afficher "toutes les organisations"
     * (par defaut) et pouvoir choisir une organisation donnee. Donc ajouter une
     * colonne avec le slug de l'organisation. »
     *
     * ## L'ecran montrait DEJA tout — et c'est precisement le defaut
     *
     * Mesure du 24/10 : cette liste n'a jamais filtre par tenant. Elle
     * affichait donc des gabarits de plusieurs Organizations **sans jamais dire
     * a laquelle chacun appartient**. Le defaut n'etait pas ce qui manquait a
     * la liste, c'etait ce qu'elle taisait : deux lignes au meme nom pouvaient
     * venir de deux Organizations differentes, et rien ne les distinguait.
     *
     * La colonne vient donc AVANT le filtre dans l'ordre des raisons — un
     * filtre sur une donnee invisible ne se verifie pas.
     *
     * ## « global » est une VALEUR du filtre
     *
     * Son absence veut dire « toutes », ce qui ne permettait pas de demander
     * « seulement les Global ». Meme idiome et meme borne de type qu'aux bugs O
     * et AA : hors de l'espace des UUID, ecarte avant d'atteindre une colonne
     * `uuid` (`22P02`, invisible sous SQLite).
     */
    public function index(Request $request): View
    {
        $search = $request->input('search');
        $cible = (string) $request->input('organization_id', '');

        $templates = EmailTemplate::query()
            ->with('organization:id,name,slug')
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->when(
                $cible === self::CIBLE_GLOBALE,
                fn ($q) => $q->whereNull('organization_id'),
                fn ($q) => $q->when(
                    $cible !== '' && Str::isUuid($cible),
                    fn ($qq) => $qq->where('organization_id', $cible),
                ),
            )
            ->withCount('logs')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.email-templates.index', [
            'templates' => $templates,
            'search' => $search,
            'organizations' => Organization::orderBy('name')->get(['id', 'name', 'slug']),
            'currentScope' => $cible,
        ]);
    }

    public function create(): View
    {
        // TASK-1694 bug AA — les cibles possibles : « Global », puis les
        // Organizations actives. Une Organization desactivee n'est pas proposee :
        // creer un gabarit pour elle serait un geste sans destinataire.
        return view('admin.email-templates.create', [
            'organizations' => Organization::where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['variables' => $this->parseVariables($request->input('variables'))]);

        $validated = $request->validate([
            // TASK-1694 bug AA — l'unicite porte sur le TRIPLET, plus sur le slug
            // seul : c'est tout l'objet de l'appariement. Deux versions
            // linguistiques partagent leur slug ; deux Organizations aussi.
            'slug' => ['required', 'string', 'max:100'],
            'name' => 'required|string|max:255',
            // TASK-1694 bug Z — FR, EN, ou rien : « langue non precisee » est une
            // troisieme valeur legitime, et c'est l'etat des lignes anterieures.
            'locale' => ['nullable', Rule::in(EmailTemplate::LOCALES)],
            'organization_ids' => ['required', 'array', 'min:1'],
            'organization_ids.*' => ['required', 'string', $this->regleDeCible()],
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
            'variables' => 'nullable|array',
        ]);

        $cibles = array_unique($validated['organization_ids']);
        $locale = $validated['locale'] ?? null;

        $crees = 0;
        $deja = [];

        foreach ($cibles as $cible) {
            $organizationId = $cible === self::CIBLE_GLOBALE ? null : $cible;

            $existe = EmailTemplate::query()
                ->where('slug', $validated['slug'])
                ->where('locale', $locale)
                ->when(
                    $organizationId === null,
                    fn ($q) => $q->whereNull('organization_id'),
                    fn ($q) => $q->where('organization_id', $organizationId),
                )
                ->exists();

            if ($existe) {
                $deja[] = $organizationId === null
                    ? __('admin.emailer_scope_global')
                    : (Organization::whereKey($organizationId)->value('slug') ?? $organizationId);

                continue;
            }

            EmailTemplate::create([
                'slug' => $validated['slug'],
                'name' => $validated['name'],
                'locale' => $locale,
                'organization_id' => $organizationId,
                'subject' => $validated['subject'],
                'content_html' => $validated['content_html'],
                'variables' => $validated['variables'] ?? null,
            ]);

            $crees++;
        }

        if ($crees === 0) {
            return back()
                ->withInput()
                ->withErrors(['organization_ids' => __('admin.emailer_created_none', ['cibles' => implode(', ', $deja)])]);
        }

        return redirect()->route('admin.email-templates')
            ->with('success', $deja === []
                ? __('admin.emailer_created_count', ['count' => $crees])
                : __('admin.emailer_created_partial', ['count' => $crees, 'cibles' => implode(', ', $deja)]));
    }

    /**
     * La regle de forme d'une cible : « global », ou un UUID d'Organization.
     *
     * Posee a part parce qu'elle garde une frontiere de TYPE : laisser passer
     * une chaine quelconque jusqu'a un `where` sur une colonne `uuid` donne un
     * 500 sur PostgreSQL (`22P02`), invisible sous SQLite qui compare comme du
     * texte. Lecon TASK-1683.
     */
    private function regleDeCible(): \Closure
    {
        return function (string $attribut, mixed $valeur, \Closure $refuser): void {
            if ($valeur === self::CIBLE_GLOBALE) {
                return;
            }

            if (! Str::isUuid((string) $valeur) || ! Organization::whereKey($valeur)->exists()) {
                $refuser(__('admin.emailer_scope_unknown'));
            }
        };
    }

    public function show(EmailTemplate $emailTemplate): View
    {
        $emailTemplate->load(['logs' => fn ($q) => $q->latest()->limit(10)]);

        return view('admin.email-templates.show', compact('emailTemplate'));
    }

    public function edit(EmailTemplate $emailTemplate): View
    {
        return view('admin.email-templates.edit', compact('emailTemplate'));
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $request->merge(['variables' => $this->parseVariables($request->input('variables'))]);

        $validated = $request->validate([
            // TASK-1694 bug AA — unicite sur le TRIPLET (voir `store()`).
            //
            // Sans cette regle, renommer un slug vers une collision remonterait
            // jusqu'a l'index unique : une violation de contrainte, c'est-a-dire
            // un 500, la ou il faut un message de formulaire.
            'slug' => [
                'required', 'string', 'max:100',
                Rule::unique('email_templates', 'slug')
                    ->ignore($emailTemplate->id)
                    ->where(fn ($q) => $q
                        ->where('locale', $request->input('locale') ?: null)
                        ->when(
                            $emailTemplate->organization_id === null,
                            fn ($qq) => $qq->whereNull('organization_id'),
                            fn ($qq) => $qq->where('organization_id', $emailTemplate->organization_id),
                        )),
            ],
            'name' => 'required|string|max:255',
            // TASK-1694 bug Z — FR, EN, ou rien : « langue non precisee » est une
            // troisieme valeur legitime, et c'est l'etat des lignes anterieures.
            'locale' => ['nullable', Rule::in(EmailTemplate::LOCALES)],
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
            'variables' => 'nullable|array',
        ]);

        $emailTemplate->update($validated);

        return redirect()->route('admin.email-templates')
            ->with('success', __('admin.emailer_updated'));
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->delete();

        return redirect()->route('admin.email-templates')
            ->with('success', __('admin.emailer_deleted'));
    }

    public function preview(Request $request): string
    {
        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        return $validated['content'];
    }

    public function sendForm(EmailTemplate $emailTemplate): View
    {
        $users = User::activeAccount()
            ->with('organization')
            ->orderBy('name')
            ->paginate(50);

        $service = app(EmailerService::class);

        return view('admin.email-templates.send', [
            'template' => $emailTemplate,
            'users' => $users,
            'service' => $service,
        ]);
    }

    public function sendExecute(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => Rule::exists('users', 'id')->whereNull('banned_at'),
            'confirmed' => 'nullable|string',
        ]);

        $userIds = array_unique($validated['user_ids']);

        if (count($userIds) > 50) {
            return back()->withErrors(['user_ids' => __('admin.emailer_max_50')])->withInput();
        }

        $users = User::activeAccount()->whereIn('id', $userIds)->get();

        if ($users->isEmpty()) {
            return back()->withErrors(['user_ids' => __('admin.emailer_no_recipients')])->withInput();
        }

        if (! $request->has('confirmed') && count($users) > 1) {
            return redirect()->route('admin.email-templates.send.confirm', [
                'emailTemplate' => $emailTemplate,
                'user_ids' => $userIds,
            ]);
        }

        $service = app(EmailerService::class);
        $sender = $request->user();

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {
            $log = $service->sendFromTemplate($emailTemplate, $user, $sender);
            if ($log->status === 'sent') {
                $sent++;
            } else {
                $failed++;
            }
        }

        $total = $sent + $failed;

        return redirect()->route('admin.email-templates.show', $emailTemplate)
            ->with('success', __('admin.emailer_sent_count', ['sent' => $sent, 'failed' => $failed, 'total' => $total]));
    }

    public function sendConfirm(Request $request, EmailTemplate $emailTemplate): View
    {
        $userIds = $request->query('user_ids', []);

        if (empty($userIds)) {
            return redirect()->route('admin.email-templates.send', $emailTemplate);
        }

        $users = User::activeAccount()->whereIn('id', $userIds)->get();
        $service = app(EmailerService::class);
        $previewUser = $users->first();

        return view('admin.email-templates.send', [
            'template' => $emailTemplate,
            'users' => $users,
            'previewUser' => $previewUser,
            'userIds' => $userIds,
            'service' => $service,
            'confirm' => true,
        ]);
    }

    private function parseVariables(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value ?: null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode("\n", $value))));
    }
}
