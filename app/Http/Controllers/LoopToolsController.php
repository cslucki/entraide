<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Services\Loops\LoopPresetConfigurator;
use App\Services\Loops\PresetException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Personnaliser ma Boucle » — l'ecran du proprietaire.
 *
 * Ce n'est pas un second configurateur : les quatre gestes appellent
 * `LoopPresetConfigurator`, qui porte deja la porte
 * (`canConfigure` : SuperAdmin/Admin par `loops.manage_cards`, **ou** le
 * proprietaire si son Organization l'y autorise) et le refus d'une Boucle
 * archivee. Ce qui change ici est le **langage** : des outils, pas des Cards ;
 * une phrase qui explique, pas une cle de dependance.
 *
 * L'ecran administrateur reste ou il est, inchange.
 */
class LoopToolsController extends Controller
{
    public function index(Request $request, string $organization, Loop $loop): View
    {
        $this->assertScope($request, $loop);

        $configurator = app(LoopPresetConfigurator::class);

        // `canConfigure()` dit le droit, pas l'etat : c'est
        // `assertConfigurable()` qui refuse une archivee, et il ne garde que
        // les ecritures. L'ecran lui-meme se ferme donc ici — sinon il
        // offrirait quatre gestes que le serveur refuserait tous.
        abort_if($loop->isArchived(), 403);
        abort_unless($configurator->canConfigure($request->user(), $loop), 403);

        return view('loops.tools', [
            'loop' => $loop,
            'composition' => $configurator->describe($loop),
            'organizationRouteParam' => $request->route('organization'),
        ]);
    }

    /**
     * Les quatre gestes, et rien d'autre.
     *
     * Ajouter / Desactiver / Mettre en avant / Retirer des principaux. Chacun
     * passe par le service canonique : une requete forgee rencontre les memes
     * refus que l'ecran, y compris sur une Boucle archivee.
     */
    public function update(Request $request, string $organization, Loop $loop): RedirectResponse
    {
        $this->assertScope($request, $loop);

        $data = $request->validate([
            'action' => 'required|in:enable,disable,promote,demote',
            'tool' => 'required|string',
        ]);

        $configurator = app(LoopPresetConfigurator::class);
        $user = $request->user();

        try {
            $message = match ($data['action']) {
                'enable' => tap(__('loops.owner_tools_added'), fn () => $configurator->enable($user, $loop, $data['tool'])),
                'disable' => tap(__('loops.owner_tools_removed'), fn () => $configurator->disable($user, $loop, $data['tool'])),
                'promote' => tap(__('loops.tools_promoted'), fn () => $configurator->promote($user, $loop, $data['tool'])),
                default => tap(__('loops.tools_demoted'), fn () => $configurator->demote($user, $loop, $data['tool'])),
            };
        } catch (PresetException $e) {
            // `error_tool` ancre le refus dans la carte du geste : une
            // contrainte qui s'affiche a l'autre bout de la page ressemble a
            // une erreur serveur, pas a une regle du produit.
            return back()->with('error', $e->getMessage())->with('error_tool', $data['tool']);
        }

        // Meme ancrage pour la reussite : la carte qui vient de changer se
        // signale, au lieu de laisser chercher ce qui a bouge.
        return back()->with('success', $message)
            ->with('success_tool', $data['tool'])
            ->with('success_action', $data['action']);
    }

    /**
     * Tenant d'abord : la Boucle appartient a l'Organization de l'URL, et la
     * personne a la meme. Le reste — le droit de composer — revient au
     * configurateur.
     */
    private function assertScope(Request $request, Loop $loop): void
    {
        $organization = currentOrganization();

        abort_unless($organization !== null && $loop->organization_id === $organization->id, 404);
        abort_unless($request->user()?->organization_id === $organization->id, 404);
    }
}
