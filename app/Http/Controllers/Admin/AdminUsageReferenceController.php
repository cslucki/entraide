<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsageReference;
use App\Services\UsageReference\UsageReferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1439 — UsageReference V1, administrable par le SuperAdmin SEULEMENT
 * (zone admin globale, garde d'attribut `is_admin`). Brouillon -> publication
 * explicite -> retrait ; une version publiee ne s'edite plus. Pas de CMS, pas
 * de generation IA du contenu (MASTER Q66).
 */
class AdminUsageReferenceController extends Controller
{
    public function __construct(private readonly UsageReferenceService $references) {}

    /**
     * TASK-1480 — l'ecran repond a la question qu'on lui pose vraiment :
     * « qu'est-ce qui est en ligne sur cette surface, dans cette langue ? »
     *
     * Il listait des VERSIONS, triees par surface puis par numero. Un
     * SuperAdmin devait reconstituer de tete, pour chaque couple
     * (surface, locale), laquelle etait publiee et laquelle etait un brouillon
     * en attente. La donnee etait la ; la reponse, non.
     *
     * Le regroupement se fait donc par COUPLE, et chaque couple porte trois
     * choses : la version publiee, le brouillon s'il y en a un, et l'historique.
     * Aucune requete de plus : c'est le meme jeu de lignes, groupe autrement.
     */
    public function index(): View
    {
        $rows = UsageReference::query()
            ->with(['author', 'publisher'])
            ->orderBy('surface_key')
            ->orderBy('locale')
            ->orderByDesc('version')
            ->get();

        $cells = [];

        foreach (UsageReference::SURFACES as $surface) {
            foreach (UsageReference::supportedLocales() as $locale) {
                $versions = $rows->filter(fn (UsageReference $r) => $r->surface_key === $surface && $r->locale === $locale)->values();

                $cells[$surface][$locale] = [
                    'published' => $versions->first(fn (UsageReference $r) => $r->isPublished()),
                    // Le brouillon COURANT : le plus recent. Le service ne
                    // garantit pas l'unicite, et afficher le plus ancien
                    // designerait le mauvais bouton « Publier ».
                    'draft' => $versions->first(fn (UsageReference $r) => $r->isDraft()),
                    'versions' => $versions,
                ];
            }
        }

        return view('admin.usage-references.index', [
            'surfaces' => UsageReference::SURFACES,
            'locales' => UsageReference::supportedLocales(),
            'platformLocale' => UsageReference::platformLocale(),
            'cells' => $cells,
            'maxChars' => UsageReference::maxChars(),
        ]);
    }

    /**
     * TASK-1480 — LIRE une reference. Le geste qui manquait.
     *
     * Mesure faite : `edit` etait la seule vue du texte, et elle rend 404 sur
     * une version publiee. On ne pouvait donc pas relire ce qui etait EN LIGNE.
     * C'est cela qui rendait l'ecran incomprehensible — pas le mot
     * « brouillon ».
     *
     * La meme vue sert de PREVISUALISATION d'un brouillon : previsualiser, ici,
     * c'est lire le texte tel que le Shell le recevra. Une seconde mise en page
     * « pour l'apercu » serait une deuxieme verite.
     */
    public function show(UsageReference $usageReference): View
    {
        return view('admin.usage-references.show', [
            'reference' => $usageReference->load(['author', 'publisher']),
            'maxChars' => UsageReference::maxChars(),
        ]);
    }

    /**
     * TASK-1480 — « Modifier » sur une version PUBLIEE ouvre un brouillon
     * pre-rempli, au lieu d'une page blanche.
     *
     * Une version publiee est immuable, et c'est une bonne regle : elle est ce
     * que le Shell a servi. Mais l'ecran n'offrait aucun chemin vers la suite —
     * il fallait deviner « Nouveau brouillon » et RETAPER le texte, ou le
     * copier depuis nulle part, puisqu'aucune vue ne le montrait.
     *
     * `?from={id}` reprend donc le titre et le contenu d'une version existante.
     * Il ne cree RIEN : c'est un pre-remplissage de formulaire. Le brouillon
     * n'existe qu'apres `store()`, et la publication reste un second geste
     * humain.
     */
    public function create(Request $request): View
    {
        $from = null;

        if ($id = $request->query('from')) {
            $from = UsageReference::query()->find($id);
        }

        return view('admin.usage-references.form', [
            'reference' => null,
            'from' => $from,
            'surfaces' => UsageReference::SURFACES,
            'locales' => UsageReference::supportedLocales(),
            'surfaceKey' => (string) $request->query('surface', $from?->surface_key ?? UsageReference::SURFACE_SHELL_WELCOME),
            'locale' => (string) $request->query('locale', $from?->locale ?? UsageReference::platformLocale()),
            'maxChars' => UsageReference::maxChars(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'surface_key' => ['required', 'string', 'in:'.implode(',', UsageReference::SURFACES)],
            'locale' => ['required', 'string', 'in:'.implode(',', UsageReference::supportedLocales())],
            'title' => ['required', 'string', 'max:'.UsageReference::MAX_TITLE_CHARS],
            'content' => ['required', 'string', 'max:'.UsageReference::maxChars()],
        ]);

        $reference = $this->references->createDraft($data['surface_key'], $data['locale'], $data['title'], $data['content'], $request->user());

        return redirect()->route('admin.usage-references')->with('success', __('admin.usage_reference_draft_created', ['version' => $reference->version]));
    }

    public function edit(UsageReference $usageReference): View
    {
        abort_unless($usageReference->isDraft(), 404);

        return view('admin.usage-references.form', [
            'reference' => $usageReference,
            'from' => null,
            'surfaces' => UsageReference::SURFACES,
            'locales' => UsageReference::supportedLocales(),
            'surfaceKey' => $usageReference->surface_key,
            'locale' => $usageReference->locale,
            'maxChars' => UsageReference::maxChars(),
        ]);
    }

    public function update(Request $request, UsageReference $usageReference): RedirectResponse
    {
        abort_unless($usageReference->isDraft(), 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:'.UsageReference::MAX_TITLE_CHARS],
            'content' => ['required', 'string', 'max:'.UsageReference::maxChars()],
        ]);

        $this->references->updateDraft($usageReference, $data['title'], $data['content'], $request->user());

        return redirect()->route('admin.usage-references')->with('success', __('admin.usage_reference_draft_saved', ['version' => $usageReference->version]));
    }

    public function publish(Request $request, UsageReference $usageReference): RedirectResponse
    {
        abort_unless($usageReference->isDraft(), 404);

        $this->references->publish($usageReference, $request->user());

        return redirect()->route('admin.usage-references')->with('success', __('admin.usage_reference_published', ['version' => $usageReference->version]));
    }

    public function retire(Request $request, UsageReference $usageReference): RedirectResponse
    {
        abort_unless($usageReference->isPublished(), 404);

        $this->references->retire($usageReference, $request->user());

        return redirect()->route('admin.usage-references')->with('success', __('admin.usage_reference_retired', ['version' => $usageReference->version]));
    }
}
