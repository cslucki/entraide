<?php

namespace App\Support\GuestShell;

/**
 * TASK-1468 (CDC 21h-23h §3.2, UX-6) — la traduction PRESENTATIONNELLE d'un
 * `GuestShellState` en diagnostic actionnable : un libelle, une cause, un
 * geste.
 *
 * ## Pourquoi « Mal configure » n'aidait personne
 *
 * `MISCONFIGURED` recouvre TROIS situations sans rapport entre elles :
 * l'Organization est inactive, l'Organization n'est pas publique, ou le
 * plafond plateforme n'est pas pose. Trois causes, trois gestes differents,
 * un seul mot pour les trois. Un SuperAdmin lisant « Mal configure » ne savait
 * ni ce qui bloquait, ni quoi faire.
 *
 * D'ou la regle de ce fichier : **le libelle derive de la RAISON, pas du
 * statut.** Le statut garde son role — il colore et il trie ; la raison, elle,
 * dit ce qui s'est passe.
 *
 * ## Ce fichier ne decide rien
 *
 * Aucune seconde autorite de politique. `GuestShellPolicyService::state()`
 * reste seul juge de ce qui bloque ; on ne fait que NOMMER son verdict. Ajouter
 * ici une condition economique, meme evidente, recreerait le double calcul que
 * toute la campagne evite.
 *
 * ## Une raison inconnue ne ment pas
 *
 * Si une future TASK ajoute un code sans passer ici, le repli est « Action
 * requise » avec la raison brute en diagnostic technique — visible du seul
 * SuperAdmin. Jamais un « Pret » par defaut : un etat non reconnu n'est pas un
 * etat sain.
 *
 * ## Jamais un secret
 *
 * Les libelles ne contiennent ni cle, ni jeton. Le nom de la variable
 * d'environnement du plafond est en revanche autorise (CDC §3.3) : il vit dans
 * la zone SuperAdmin, et c'est exactement ce qu'il faut savoir pour agir.
 */
final class GuestShellDiagnosis
{
    /** Tout va bien : le Shell peut appeler un provider. */
    public const TONE_READY = 'ready';

    /** Un choix humain, pas une panne : l'Organization a eteint le Shell. */
    public const TONE_NEUTRAL = 'neutral';

    /** Quelque chose est a faire, et c'est faisable. */
    public const TONE_ACTION = 'action';

    /** Une limite economique est atteinte : rien a reparer, une decision a prendre. */
    public const TONE_BUDGET = 'budget';

    /**
     * La raison -> (cle de diagnostic, ton). L'ordre des raisons produites par
     * `GuestShellPolicyService::state()` fait foi : la PREMIERE est la cause
     * dominante, celle qui bloque en premier.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const REASON_MAP = [
        'disabled' => ['disabled', self::TONE_NEUTRAL],
        'organization_inactive' => ['organization_inactive', self::TONE_ACTION],
        'organization_not_public' => ['organization_not_public', self::TONE_ACTION],
        'no_ai_setting' => ['no_ai_setting', self::TONE_ACTION],
        'ai_setting_unusable' => ['ai_setting_unusable', self::TONE_ACTION],
        'api_key_missing' => ['api_key_missing', self::TONE_ACTION],
        'platform_ceiling_unset' => ['platform_ceiling_unset', self::TONE_ACTION],
        'platform_ceiling_reached' => ['platform_ceiling_reached', self::TONE_BUDGET],
        'organization_budget_reached' => ['organization_budget_reached', self::TONE_BUDGET],
        'guest_monthly_budget_reached' => ['guest_monthly_budget_reached', self::TONE_BUDGET],
        'process_budget_reached' => ['process_budget_reached', self::TONE_BUDGET],
    ];

    /** Le repli d'une raison que ce fichier ne connait pas encore. */
    public const UNKNOWN_KEY = 'unknown';

    /**
     * @return array{key: string, tone: string, label: string, cause: ?string, action: ?string, technical: ?string}
     */
    public static function for(GuestShellState $state): array
    {
        return self::fromStatusAndReasons($state->status, $state->reasons);
    }

    /**
     * TASK-1470 — la MEME table, par une seconde porte.
     *
     * Le cockpit plateforme (`/admin/shell-welcome`) ne recoit pas d'objet
     * `GuestShellState` : `GuestShellUsageService` n'expose qu'un statut et une
     * liste de raisons, agreges par Organization. Plutot que de faire remonter
     * l'objet entier dans un service d'agregation — ou, bien pire, de recopier
     * la table ailleurs — on expose ici le couple brut que toute surface
     * possede deja.
     *
     * `for()` delegue a cette methode : il n'y a qu'UNE table, qu'un repli,
     * qu'une definition du mot « Pret ».
     *
     * @param  list<string>  $reasons
     * @return array{key: string, tone: string, label: string, cause: ?string, action: ?string, technical: ?string}
     */
    public static function fromStatusAndReasons(string $status, array $reasons): array
    {
        if ($status === GuestShellState::ACTIVE) {
            return self::entry('ready', self::TONE_READY);
        }

        $reason = $reasons[0] ?? null;

        if ($reason === null || ! isset(self::REASON_MAP[$reason])) {
            // Un etat qui bloque sans raison nommee reste un etat qui bloque.
            return self::entry(self::UNKNOWN_KEY, self::TONE_ACTION, $reason);
        }

        [$key, $tone] = self::REASON_MAP[$reason];

        return self::entry($key, $tone);
    }

    /**
     * La classe Tailwind du badge, pour que les trois surfaces admin colorent
     * le meme etat de la meme facon. Le `match` etait recopie dans chaque vue,
     * avec des nuances qui divergeaient deja.
     */
    public static function badgeClasses(string $tone): string
    {
        return match ($tone) {
            self::TONE_READY => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300',
            self::TONE_NEUTRAL => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300',
            self::TONE_BUDGET => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-300',
            default => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300',
        };
    }

    /**
     * @return array{key: string, tone: string, label: string, cause: ?string, action: ?string, technical: ?string}
     */
    private static function entry(string $key, string $tone, ?string $technical = null): array
    {
        return [
            'key' => $key,
            'tone' => $tone,
            'label' => __('admin.guest_shell_diag_'.$key.'_label'),
            'cause' => self::optional('admin.guest_shell_diag_'.$key.'_cause'),
            'action' => self::optional('admin.guest_shell_diag_'.$key.'_action'),
            'technical' => $technical,
        ];
    }

    /**
     * `Pret` et `Desactive` n'ont ni cause ni geste : il n'y a rien a expliquer
     * ni rien a faire. Une cle absente rend `null`, jamais son propre nom.
     */
    private static function optional(string $key): ?string
    {
        $value = __($key);

        return $value === $key ? null : $value;
    }
}
