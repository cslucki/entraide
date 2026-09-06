<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Constitution;
use App\Http\Controllers\Controller;
use App\Models\PlatformAiConstitution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1348 — la Constitution IA de la PLATEFORME, administrable.
 *
 * Ce controleur vit sous la zone admin GLOBALE (`middleware(['auth','admin'])`,
 * `routes/web.php`). C'est une garde d'ATTRIBUT (`is_admin`), et non la garde
 * RELATIONNELLE d'`OrgAdminMiddleware` : il n'y a aucune Organization dans ces
 * routes, donc rien a comparer. Un administrateur d'Organization n'y accede
 * jamais — c'est exactement l'invariant « ORG ADMIN -> edit Platform = NO ».
 *
 * Le texte edite ici est compose dans CHAQUE appel de CHAQUE capability de
 * TOUTES les Organizations. Il reste neanmoins du texte : le socle de code
 * (`Constitution::guards()`) le domine, et aucune garantie de securite n'en
 * depend.
 */
class AdminAiConstitutionController extends Controller
{
    public function index(): View
    {
        $active = PlatformAiConstitution::active();

        // Le dossier suit la convention des autres ecrans IA d'administration
        // (`admin/ai-benchmark`, `ai-config`, `ai-prompts`...). Ce n'est pas
        // cosmetique : `.gitignore` porte un motif NON ANCRE `ai/`, qui capture
        // `resources/views/admin/ai/` — une vue placee la existerait en local et
        // manquerait au commit, donc a la CI et a la production. Le trait
        // d'union la met naturellement hors du motif, sans `git add -f` et sans
        // toucher au perimetre privacy de T1336/T1337.
        return view('admin.ai-constitution.index', [
            // TASK-1349 : « Mycelium BouclePro » est le nom PUBLIC de cette
            // meme Constitution plateforme. Un seul modele, un seul ecran,
            // deux vocabulaires — celui du code et celui des humains.
            'myceliumTitle' => __('mycelium.title'),
            'myceliumSubtitle' => __('mycelium.subtitle'),
            'active' => $active,
            // Ce qui est REELLEMENT compose : la version active, ou la graine
            // de code quand aucune version ne l'est. L'ecran ne montre jamais
            // un texte que le prompt n'utilise pas.
            'composedText' => PlatformAiConstitution::activeTextOrSeed(),
            'isSeed' => $active === null,
            'seedVersion' => Constitution::VERSION,
            'guards' => (new Constitution)->guards(),
            'history' => PlatformAiConstitution::query()
                ->orderByDesc('version')
                ->with('author')
                ->limit(10)
                ->get(),
            'historyTotal' => PlatformAiConstitution::query()->count(),
            'maxChars' => PlatformAiConstitution::maxChars(),
        ]);
    }

    /** Nouvelle version, activee. Un texte identique a l'active ne cree rien. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.PlatformAiConstitution::maxChars()],
        ]);

        $before = PlatformAiConstitution::active();
        $constitution = PlatformAiConstitution::activate($data['body'], $request->user());

        return redirect()
            ->route('admin.mycelium')
            ->with('success', $before !== null && $before->is($constitution)
                ? __('ai.constitution_admin_unchanged', ['version' => $constitution->version])
                : __('ai.constitution_admin_saved', ['version' => $constitution->version]));
    }

    /**
     * Retire la version active : la plateforme revient a la graine immuable du
     * code. L'historique reste — rien n'est jamais reecrit.
     */
    public function withdraw(): RedirectResponse
    {
        $withdrawn = PlatformAiConstitution::withdraw();

        return redirect()
            ->route('admin.mycelium')
            ->with($withdrawn ? 'success' : 'info', $withdrawn
                ? __('ai.constitution_admin_withdrawn')
                : __('ai.constitution_admin_nothing_to_withdraw'));
    }
}
