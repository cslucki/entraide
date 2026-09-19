<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LocaleController extends Controller
{
    public function switch(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['fr', 'en'], true), 404);

        $request->session()->put('locale', $locale);

        return redirect()->to($this->destination($request));
    }

    /**
     * TASK-1602 — ou renvoyer apres un changement de langue.
     *
     * L'ancien repli etait `redirect('/')` : la racine, qui retombe sur
     * l'Organization par defaut. Depuis `/org/launchpals`, changer de langue
     * ramenait donc chez `main` — mesure : `POST /locale/en` -> 302 vers
     * `https://test.laravel` -> `/org/main`.
     *
     * Le champ `redirect_to` existait deja, mais il n'est rempli qu'en
     * JavaScript (`onsubmit`), et SEULEMENT par deux des six selecteurs. Ceux
     * des gabarits d'Organization — `artscilab-hero`, `hero-v2`,
     * `shell-first`, `org-admin` — ne l'envoient pas du tout. Corriger les six
     * gabarits aurait laisse le trou sans JavaScript ; la decision est donc de
     * reparer le REPLI, une fois, ici.
     *
     * Ordre de preference, du plus precis au plus general :
     *
     * 1. `redirect_to` explicite, s'il pointe bien vers cette application ;
     * 2. la page d'ou l'on vient (`Referer`), qui preserve l'URL exacte —
     *    c'est ce qui fait fonctionner les selecteurs sans JavaScript ;
     * 3. l'accueil canonique de l'Organization courante, ou de celle de
     *    l'utilisateur connecte ;
     * 4. la racine, seulement s'il ne reste rien.
     */
    private function destination(Request $request): string
    {
        $explicite = $request->string('redirect_to')->toString();

        if ($this->estInterne($explicite)) {
            return $explicite;
        }

        $precedente = url()->previous();

        // `previous()` retombe sur `url('/')` quand il n'y a pas de `Referer`,
        // et pointerait sur cette route elle-meme si l'on rechargeait le POST :
        // ni l'un ni l'autre ne conserve un contexte.
        if ($this->estInterne($precedente)
            && ! Str::startsWith($precedente, url('/locale'))
            && rtrim($precedente, '/') !== rtrim(url('/'), '/')) {
            return $precedente;
        }

        $organization = currentOrganization() ?? $request->user()?->organization;

        // Borne : seule une Organization NON PAR DEFAUT a besoin d'un repli
        // explicite. Pour celle par defaut, la racine EST deja chez elle —
        // y toucher changerait le comportement de tout le monde sans rien
        // corriger (`LocaleControllerTest` le mesure).
        if ($organization && ! $organization->is_default) {
            return canonicalHome($organization);
        }

        return '/';
    }

    private function estInterne(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        return $url === url('/') || Str::startsWith($url, url('/').'/');
    }
}
