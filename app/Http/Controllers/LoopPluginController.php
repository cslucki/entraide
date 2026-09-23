<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Services\Loops\LoopAiAssistants;
use App\Services\Loops\LoopPluginActivation;
use App\Support\Loops\LoopPluginRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Activer un plugin dans une Boucle, et regler ses assistants. (TASK-1616)
 *
 * **Un seul controleur pour DEUX surfaces**, et c'est le point : l'ecran
 * SuperAdmin (`/admin/loops/{loop}/configure`) et l'ecran du proprietaire
 * (`/org/{org}/loops/{loop}/outils`) posent la meme question et doivent
 * recevoir le meme refus. Deux controleurs auraient fait deux portes a garder,
 * et c'est toujours la seconde qu'on oublie.
 *
 * Ce qui differe entre les deux n'est pas l'autorisation mais la PORTEE :
 * `/admin` autorise un SuperAdmin a agir hors de son Organization ; `/org`
 * exige que l'Organization courante soit celle de la Boucle. Les deux gardes
 * sont reprises telles quelles de `AdminLoopController::assertOrgAccess()` et
 * de `LoopToolsController::assertScope()`, plutot que reecrites.
 *
 * **Le hard gate Organization n'est PAS ici.** Il vit dans
 * `LoopPluginActivation`, derniere porte avant la base, et c'est lui qui rend
 * un POST forge inoperant. Ce controleur ne fait que le consulter tot, pour
 * repondre 403 plutot que de laisser lever une exception.
 */
class LoopPluginController extends Controller
{
    public function __construct(
        private LoopPluginActivation $activation,
        private LoopAiAssistants $assistants,
        private LoopPluginRegistry $plugins,
    ) {}

    // ── Activation ──────────────────────────────────────────────────────────

    /** Allumer ou eteindre le plugin dans cette Boucle, depuis l'une ou l'autre surface. */
    public function update(Request $request): RedirectResponse
    {
        [$loop, $plugin, $adminScope] = $this->depuisLaRoute($request);

        $this->assertScope($request, $loop, $adminScope);
        $this->assertConfigurable($request, $plugin, $loop);

        $data = $request->validate(['enabled' => 'required|boolean']);

        $this->activation->setEnabled($plugin, $loop, (bool) $data['enabled'], $request->user());

        return back()->with('success', __(
            $data['enabled'] ? 'loops.plugins_loop_enabled_flash' : 'loops.plugins_loop_disabled_flash',
            ['plugin' => $this->plugins->label($plugin)],
        ));
    }

    // ── Configuration des trois assistants ──────────────────────────────────

    public function configure(Request $request): View
    {
        [$loop, $plugin, $adminScope] = $this->depuisLaRoute($request);

        $this->assertScope($request, $loop, $adminScope);
        $this->assertConfigurable($request, $plugin, $loop);

        return view('loops.plugins.configure', [
            'loop' => $loop,
            'pluginKey' => $plugin,
            'pluginLabel' => $this->plugins->label($plugin),
            'experimental' => $this->plugins->isExperimental($plugin),
            'enabled' => $this->activation->isEnabled($plugin, $loop),
            'assistants' => $this->assistants->describeFor($loop),
            'backUrl' => $this->backUrl($request, $loop, $adminScope),
            'saveUrl' => $adminScope
                ? route('admin.loops.plugins.configure.update', ['loop' => $loop->id, 'plugin' => $plugin])
                : route('organization.loops.plugins.configure.update', [
                    'organization' => $request->route('organization'),
                    'loop' => $loop->id,
                    'plugin' => $plugin,
                ]),
        ]);
    }

    public function saveConfiguration(Request $request): RedirectResponse
    {
        [$loop, $plugin, $adminScope] = $this->depuisLaRoute($request);

        $this->assertScope($request, $loop, $adminScope);
        $this->assertConfigurable($request, $plugin, $loop);

        // Les cles sont bornees par le CATALOGUE, pas par la validation : un
        // formulaire forge ne cree pas de quatrieme assistant, et le service
        // ignore ce qu'il ne connait pas.
        $data = $request->validate([
            'assistants' => 'required|array',
            'assistants.*.instruction' => 'nullable|string|max:4000',
            'assistants.*.enabled' => 'nullable|boolean',
        ]);

        $reglages = [];

        foreach ($data['assistants'] as $key => $valeurs) {
            $reglages[(string) $key] = [
                'instruction' => $valeurs['instruction'] ?? null,
                'enabled' => (bool) ($valeurs['enabled'] ?? false),
            ];
        }

        $this->assistants->save($loop, $reglages, $request->user());

        return redirect()
            ->to($this->backUrl($request, $loop, $adminScope))
            ->with('success', __('loops.plugins_loop_saved', ['plugin' => $this->plugins->label($plugin)]));
    }

    // ── Lecture de la route ─────────────────────────────────────────────────

    /**
     * Les trois valeurs, lues par NOM et jamais recues en parametres.
     *
     * Les deux surfaces n'ont pas la meme URI :
     *
     *     /admin/loops/{loop}/plugins/{plugin}
     *     /org/{organization}/loops/{loop}/plugins/{plugin}
     *
     * Les parametres de route sont injectes POSITIONNELLEMENT dans une methode
     * de controleur. Une signature partagee recevrait donc `{organization}` la
     * ou le modele `Loop` est attendu des qu'on passe d'une surface a l'autre
     * — une TypeError, c'est-a-dire une 500 la ou une garde devait repondre
     * 403 ou 404. Lire par nom supprime le probleme au lieu de le contourner
     * avec deux methodes jumelles.
     *
     * `loop` est deja un modele : `SubstituteBindings` l'a resolu en amont.
     *
     * @return array{0: Loop, 1: string, 2: bool}
     */
    private function depuisLaRoute(Request $request): array
    {
        $loop = $request->route('loop');

        abort_unless($loop instanceof Loop, 404);

        return [$loop, (string) $request->route('plugin'), (bool) $request->route('adminScope')];
    }

    // ── Gardes ──────────────────────────────────────────────────────────────

    /**
     * La portee, et elle n'est pas la meme des deux cotes.
     *
     * `/admin` : un SuperAdmin administre des Boucles qui ne sont pas dans son
     * Organization — c'est la garde de `AdminLoopController`.
     * `/org` : l'Organization courante DOIT etre celle de la Boucle, et
     * l'utilisateur doit en etre — c'est la garde de `LoopToolsController`.
     */
    private function assertScope(Request $request, Loop $loop, bool $adminScope): void
    {
        $user = $request->user();

        if ($adminScope) {
            if ($user?->is_admin) {
                return;
            }

            abort_unless($user?->organization_id && $loop->organization_id === $user->organization_id, 404);

            return;
        }

        $organization = currentOrganization();

        abort_unless($organization !== null && $loop->organization_id === $organization->id, 404);
        abort_unless($user?->organization_id === $organization->id, 404);
    }

    /**
     * Le plugin est-il reglable ici, par cette personne ?
     *
     * `canConfigure()` porte les DEUX conditions : la disponibilite de
     * l'Organization de la Boucle, puis le droit `loop_plugins.configure`.
     * Une Organization non autorisee rend donc 403 meme a un owner — et c'est
     * voulu : le droit n'existe pas la ou la capacite n'est pas donnee.
     *
     * Une Boucle archivee est refusee par le resolveur lui-meme (lecture
     * seule pour tout le monde, super-admin compris).
     */
    private function assertConfigurable(Request $request, string $plugin, Loop $loop): void
    {
        abort_unless($this->plugins->exists($plugin), 404);
        abort_unless($this->activation->canConfigure($request->user(), $plugin, $loop), 403);
    }

    private function backUrl(Request $request, Loop $loop, bool $adminScope): string
    {
        return $adminScope
            ? route('admin.loops.configure', $loop)
            : route('organization.loops.tools', [
                'organization' => $request->route('organization'),
                'loop' => $loop->id,
            ]);
    }
}
