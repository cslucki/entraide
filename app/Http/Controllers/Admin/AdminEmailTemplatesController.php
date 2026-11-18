<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendTemplateEmailToUser;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmailerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

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
            // TASK-1863 (TASK B2) — une NOUVELLE intention nait ICI, a
            // chaque chargement du formulaire. Elle traverse le detour de
            // confirmation sans en reprendre une nouvelle (voir
            // `sendExecute()`/`sendConfirm()`).
            'sendToken' => (string) Str::uuid(),
        ]);
    }

    public function sendExecute(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => Rule::exists('users', 'id')->whereNull('banned_at'),
            'confirmed' => 'nullable|string',
            'send_token' => ['nullable', 'string', 'max:64'],
        ]);

        $userIds = array_unique($validated['user_ids']);

        if (count($userIds) > 50) {
            return back()->withErrors(['user_ids' => __('admin.emailer_max_50')])->withInput();
        }

        $users = User::activeAccount()->whereIn('id', $userIds)->get();

        if ($users->isEmpty()) {
            return back()->withErrors(['user_ids' => __('admin.emailer_no_recipients')])->withInput();
        }

        // TASK-1863 (TASK B2) — un jeton ABSENT (lien perime, appelant qui
        // ignore le champ) n'empeche jamais la requete d'avancer : il en
        // nait un ICI, scope a CETTE seule requete. Un jeton present doit
        // survivre tel quel jusqu'a la mise en file.
        $sendToken = $validated['send_token'] ?? (string) Str::uuid();

        if (! $request->has('confirmed') && count($users) > 1) {
            return redirect()->route('admin.email-templates.send.confirm', [
                'emailTemplate' => $emailTemplate,
                'user_ids' => $userIds,
                'send_token' => $sendToken,
            ]);
        }

        // TASK-1863 (TASK B2) — reservation ATOMIQUE de l'INTENTION, pas
        // des destinataires. `Cache::add()` echoue si la cle existe deja :
        // une seconde soumission du MEME jeton (double-clic, retry
        // reseau) ne doit jamais redeclencher un envoi. `CACHE_STORE`
        // database (confirme : table `cache` migree, idiome PRIMARY KEY)
        // rend cette garantie valable entre plusieurs instances web, pas
        // seulement dans ce process. Ceci est une protection a DUREE
        // LIMITEE contre la double soumission, PAS une garantie de
        // livraison exactement une fois (voir le docblock du job).
        $cacheKey = 'admin-email-template-send:'.$sendToken;

        if (! Cache::add($cacheKey, true, now()->addMinutes(10))) {
            return redirect()->route('admin.email-templates.show', $emailTemplate)
                ->with('error', __('admin.emailer_duplicate_submission'));
        }

        // TASK-1863 (TASK B2) — le contenu VALIDE par l'administrateur,
        // capture AVANT toute mise en file : voir le docblock du job pour
        // pourquoi c'est au dispatch, et non a l'ecran de confirmation,
        // que cette photo doit etre prise.
        $expectedContentHash = SendTemplateEmailToUser::contentHash($emailTemplate);
        $sender = $request->user();

        $queued = 0;
        $dispatchFailed = 0;

        foreach ($users as $user) {
            // `rescue()` rend `null` dans les deux branches : son resultat
            // ne peut pas distinguer succes et echec, d'ou le drapeau
            // capture par reference (meme bug corrige en TASK-1861).
            $dispatched = true;

            rescue(
                fn () => Bus::dispatch(new SendTemplateEmailToUser(
                    (string) $emailTemplate->id,
                    (string) $user->id,
                    (string) $sender->id,
                    $expectedContentHash,
                )),
                function (Throwable $e) use (&$dispatched, $emailTemplate, $user): void {
                    $dispatched = false;

                    // Un echec de mise en file pour CE destinataire ne
                    // doit jamais retenter ni annuler les destinataires
                    // DEJA acceptes par la file plus haut dans la boucle.
                    Log::error('Email notification failed after successful business action', [
                        'event' => 'admin_template_email_dispatch_failed',
                        'template_id' => $emailTemplate->id,
                        'user_id' => $user->id,
                        'exception_class' => $e::class,
                    ]);
                },
                false,
            );

            if ($dispatched) {
                $queued++;
            } else {
                $dispatchFailed++;
            }
        }

        $total = $queued + $dispatchFailed;

        // TASK-1863 (TASK B2) — l'envoi SMTP est deporte : au moment ou
        // cette reponse part, on sait ce qui a ete MIS EN FILE, jamais ce
        // qui a ete reellement livre.
        return redirect()->route('admin.email-templates.show', $emailTemplate)
            ->with('success', __('admin.emailer_sent_count', ['queued' => $queued, 'failed' => $dispatchFailed, 'total' => $total]));
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
            // TASK-1863 (TASK B2) — reprend le jeton deja ne au premier
            // POST (ou a l'ouverture du formulaire), jamais un nouveau :
            // c'est ce qui rend le jeton STABLE pendant tout le detour de
            // confirmation. Le repli n'existe que pour une arrivee
            // directe sur cet ecran sans jeton (lien forge/perime).
            'sendToken' => (string) $request->query('send_token', (string) Str::uuid()),
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
