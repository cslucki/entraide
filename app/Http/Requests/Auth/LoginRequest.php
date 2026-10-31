<?php

namespace App\Http\Requests\Auth;

use App\Models\Organization;
use App\Services\Users\LoginAccountResolver;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // TASK-1728 (A3) — la selection explicite d'Organization sur le
            // formulaire GLOBAL. Facultative, et jamais une regle `exists` :
            // un slug inconnu doit rendre le MEME refus generique qu'un mot de
            // passe faux, pas une erreur de validation qui confirmerait quelles
            // Organizations existent.
            'organization' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * L'Organization dans laquelle cette tentative cherche un compte.
     *
     * ## La route GOUVERNE, le formulaire ne peut pas la contredire
     *
     * Arbitrage MASTER D1 : « Impossible de remplacer ce contexte par un champ
     * POST. » Sur `/org/{slug}/login`, l'Organization vient donc du PARAMETRE
     * DE ROUTE, et un `organization` poste est IGNORE — pas refuse, ignore :
     * le refuser apprendrait a qui essaie que le champ est lu quelque part.
     *
     * Sans parametre de route, on est sur `/login` : la selection explicite
     * est alors la seule source, et elle est revalidee contre les
     * Organizations publiques et actives (voir `organizationSelectionnable()`).
     * Rien n'est poste qui n'ait son equivalent dans l'interface.
     */
    private function organizationDeLaTentative(LoginAccountResolver $comptes): ?Organization
    {
        $deLaRoute = $this->route('organization');

        if ($deLaRoute !== null) {
            return $deLaRoute instanceof Organization
                ? $deLaRoute
                : Organization::findBySlug((string) $deLaRoute);
        }

        $slug = trim((string) $this->input('organization', ''));

        if ($slug === '') {
            return null;
        }

        $selectionnee = $comptes->organizationSelectionnable($slug);

        // DEFAUT ATTRAPE PAR LE TEST, et il faut le dire parce que la
        // correction n'est pas intuitive : une selection FOURNIE mais
        // introuvable ne doit PAS retomber sur « aucune selection ».
        //
        // Ma premiere version rendait `null` dans les deux cas. Un slug
        // bricole a la main — ou simplement une Organization devenue privee
        // depuis que la page a ete affichee — ELARGISSAIT donc la recherche a
        // tous les tenants, soit exactement l'inverse de ce que la selection
        // demande. Le refus generique est la seule reponse juste : la personne
        // a nomme une porte, cette porte n'existe pas pour elle.
        if ($selectionnee === null) {
            $this->refuserGeneriquement();
        }

        return $selectionnee;
    }

    /**
     * Le refus unique, et le seul que ce formulaire sache exprimer.
     *
     * Adresse inconnue, mot de passe faux, compte absent de l'Organization
     * demandee, selection introuvable, adresse ambigue : **un seul message**.
     * Chaque nuance qu'on ajouterait ici dirait quelque chose a qui essaie —
     * et ce qu'elle dirait porte toujours sur l'appartenance de quelqu'un.
     *
     * Le compteur d'echecs est incremente comme pour n'importe quel echec : un
     * refus moins couteux que les autres serait, lui aussi, une information.
     */
    private function refuserGeneriquement(): never
    {
        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.failed'),
        ]);
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $comptes = app(LoginAccountResolver::class);

        // TASK-1728 (A3) — UNE resolution, explicite, pour toute la methode.
        //
        // Il y en avait DEUX, et elles pouvaient deja designer des comptes
        // differents : un `User::where('email')->first()` pour la garde
        // `banned_at`, puis celui que `Auth::attempt(['email', 'password'])`
        // refaisait a l'interieur du framework. Le compte qu'on refusait
        // n'etait donc pas forcement celui qu'on authentifiait.
        //
        // `null` couvre trois cas volontairement INDISCERNABLES : adresse
        // inconnue, adresse absente de l'Organization demandee, et adresse
        // ambigue. Les trois tombent dans `auth.failed` plus bas — sans quoi la
        // reponse dirait dans quelle Organization une adresse se trouve.
        $organization = $this->organizationDeLaTentative($comptes);

        $user = $comptes->compteUnique($this->string('email')->toString(), $organization);

        if ($user && $user->banned_at !== null) {
            // TASK-1716 — le refus est le MEME, le message ne l'est pas.
            //
            // `banned_at` ne disait pas QUI avait decide. « Votre compte a ete
            // desactive, contactez l'administrateur » partait donc aussi a
            // quelqu'un qui venait de fermer son compte lui-meme : il lisait
            // une sanction la ou il y avait sa propre decision, et on
            // l'envoyait reclamer une explication a un administrateur qui
            // n'avait rien fait.
            //
            // Le refus, lui, reste identique — un compte ferme n'entre pas.
            throw ValidationException::withMessages([
                'email' => $user->estFermeVolontairement()
                    ? trans('auth.closed_by_owner')
                    : trans('auth.deactivated'),
            ]);
        }

        // Le compte est DESIGNE par sa cle primaire : le framework n'a plus
        // rien a redeviner, et il ne peut plus authentifier un homonyme. Si la
        // resolution n'a rien rendu, on ne tente RIEN — ni ici, ni compte par
        // compte : eprouver des mots de passe pour savoir lequel repond ferait
        // du formulaire un oracle d'appartenance (arbitrage MASTER D2).
        try {
            $authenticated = $user !== null && Auth::attempt(
                [...$comptes->credentials($user), 'password' => $this->string('password')->toString()],
                $this->boolean('remember')
            );
        } catch (RuntimeException) {
            $authenticated = false;
        }

        if (! $authenticated) {
            $this->refuserGeneriquement();
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     *
     * TASK-1728 (A3) — `email|ip`, INCHANGE, et c'est une decision.
     *
     * J'avais propose d'y ajouter l'Organization, pour qu'en A4 deux homonymes
     * ne partagent plus le meme seau — cinq echecs sur l'un verrouillant
     * l'autre. Arbitrage MASTER D4 : non. Un seau PAR TENANT multiplierait le
     * nombre d'essais possibles pour une meme adresse, et une protection
     * anti-force-brute qui se divise a chaque nouveau tenant n'en est plus
     * une. Le deni de service entre homonymes est le moindre des deux maux, et
     * il est assume.
     *
     * Ne pas « optimiser » cette cle sans rouvrir la question.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
