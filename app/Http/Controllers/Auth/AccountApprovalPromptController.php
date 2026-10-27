<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1714 volet 2 — l'ecran qu'un compte en attente a sous les yeux.
 *
 * Meme forme que `EmailVerificationPromptController` : si la condition n'est
 * plus remplie, on ne montre pas un message devenu faux, on renvoie la
 * personne la ou elle allait.
 *
 * Ce renvoi n'est pas qu'une politesse. L'approbation est une fonction VIVANTE
 * du reglage de l'Organization et de `approved_at` : au moment ou un
 * administrateur valide le compte — ou desactive le reglage — la page doit
 * cesser d'exister pour cette personne, sans qu'elle ait a comprendre
 * pourquoi. Elle rafraichit, elle est dedans.
 */
class AccountApprovalPromptController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->attendApprobation()) {
            return redirect()->intended($user?->getLoginRedirectTarget() ?? '/');
        }

        return view('auth.approval-notice', [
            'organisation' => $user->organization,
        ]);
    }
}
