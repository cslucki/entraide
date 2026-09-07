<?php

namespace App\Http\Controllers;

use App\Models\OrganizationShortcut;
use App\Services\Acquisition\GuestAttribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * TASK-1447 — `/s/{code}` (Growth V2 §5, MASTER Q75) : resolution SERVEUR,
 * Organization active et publique, Shortcut actif, 302 vers la destination
 * canonique avec une query string TRANSPARENTE (shortcut, journey, campaign,
 * UTM autorises et bornes). Rien n'est ecrit, aucun cookie : l'attribution
 * n'est posee qu'au premier geste Guest, depuis le Shortcut relu en base.
 */
class ShortcutController extends Controller
{
    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $shortcut = OrganizationShortcut::query()->active()->where('code', $code)->with('organization')->first();
        abort_if($shortcut === null || $shortcut->organization === null || ! $shortcut->organization->is_active || ! $shortcut->organization->is_public, 404);

        return redirect()->to($shortcut->destinationUrl(GuestAttribution::allowedUtm($request->query())), 302);
    }
}
