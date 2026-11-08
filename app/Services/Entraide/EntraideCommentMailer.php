<?php

namespace App\Services\Entraide;

use App\Models\EntraideComment;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Notifications\GabaritEmail;
use Illuminate\Support\Str;

/**
 * TASK-1786 — le courriel « on a commenté votre annonce d'entraide ».
 *
 * Écrit sur le patron de `BlogCommentMailer` (T1784), et pour les mêmes raisons.
 * Les trois contraintes reprises telles quelles :
 *
 * ## 1. Le texte ne vit PAS ici
 *
 * Il est posé en base par migration (Global, FR et EN) et le SuperAdmin le
 * réécrit depuis `/admin/system-email-templates`. Ce fichier ne porte que les
 * **variables offertes** et le **repli**. Le CDC §8 l'exige — « créer le gabarit
 * système administrable selon l'architecture existante » — et interdit
 * explicitement « un nouveau système d'envoi email ».
 *
 * ## 2. Tout se lit SANS les portées globales, puis se borne à la main
 *
 * Ce code tourne dans un **worker**. Il n'y a alors aucune
 * `current_organization`, donc `BelongsToOrganizationScope` applique son
 * fail-closed et `Service` comme `ServiceRequest` — qui le portent toutes deux —
 * ressortent INTROUVABLES, en silence. C'est le défaut que
 * `TransactionProposalMailer` a payé en recette le 04/11 : le titre de l'annonce
 * arrivait vide dans le courriel, sans la moindre erreur.
 *
 * La frontière de tenant n'est pas affaiblie : le `where('organization_id', …)`
 * est posé explicitement sous chaque retrait, et c'est lui seul qui décide.
 *
 * ## 3. L'extrait est du texte écrit par un membre
 *
 * C'est ce qui impose `$pourJournal`. Le courriel porte la phrase qu'une
 * personne a écrite sous une annonce ; `email_logs` n'a pas à l'archiver. Même
 * raison que `BlogCommentMailer::EXTRAIT_EXPURGE`, et c'est la seule part de ce
 * fichier qui ne se devine pas depuis la demande — le CDC §8 la demande
 * d'ailleurs nommément, « respecter les règles de journalisation, d'expurgation
 * des textes des membres (…) mises en place dans T1784 ».
 *
 * ## `listing_type` : UN gabarit pour DEUX types d'annonce
 *
 * Le gabarit porte `{{ listing_type }}`, rempli par « proposition d'entraide »
 * ou « demande d'aide ». Un seul gabarit sert donc les deux, exactement comme
 * une seule clé de catalogue les sert. Deux gabarits auraient doublé le travail
 * d'un SuperAdmin qui veut retoucher une phrase.
 */
final class EntraideCommentMailer
{
    public const SLUG = 'entraide_comment_created';

    /**
     * Même borne que le commentaire de blog : assez pour situer, trop court pour
     * remplacer la lecture sur le site.
     */
    public const EXTRAIT_MAX = 400;

    public const EXTRAIT_EXPURGE = '[comment-redacted]';

    /**
     * @param  bool  $pourJournal  rend la variante destinée à `email_logs` :
     *                             l'extrait est expurgé, et le marqueur fourni
     *                             en `$url` sert AUSSI de lien de réglages.
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    public function compose(
        EntraideComment $commentaire,
        User $destinataire,
        ?User $acteur,
        string $url,
        string $langue,
        bool $pourJournal = false,
    ): array {
        $langueOrigine = app()->getLocale();
        app()->setLocale($langue);

        try {
            $annonce = $this->annonce($commentaire);

            $organization = Organization::query()
                ->whereKey($commentaire->organization_id)
                ->first();

            // Le corps n'est PAS échappé ici : `EmailerService::interpolate()`
            // passe chaque variable par `e()`. L'échapper deux fois afficherait
            // `&amp;` à la place de `&`. Les retours à la ligne sont rendus par
            // `white-space: pre-line` dans le gabarit — un `<br>` injecté ici
            // serait échappé et s'afficherait tel quel.
            $corps = trim((string) $commentaire->content);
            $extrait = $corps === ''
                ? (string) __('notifications.entraide_comment_email_excerpt_empty')
                : Str::limit($corps, self::EXTRAIT_MAX);

            if ($pourJournal) {
                $extrait = self::EXTRAIT_EXPURGE;
            }

            // Le lien de désabonnement mène aux réglages DE L'ORGANIZATION :
            // les préférences sont par tenant, et la version sans `org/` ferait
            // atterrir sur un autre jeu de cases. Le slug plutôt que l'id, pour
            // la même raison qu'en T1720 : cette adresse se lit dans un
            // courriel, et parfois se recopie à la main.
            $urlReglages = $pourJournal
                ? $url
                : ($organization !== null
                    ? route('organization.notifications.preferences.edit', ['organization' => $organization])
                    : route('notifications.preferences.edit'));

            $typeLisible = $annonce instanceof ServiceRequest
                ? (string) __('entraide.commentaires.type_demande')
                : (string) __('entraide.commentaires.type_proposition');

            return app(GabaritEmail::class)->rendre(
                slug: self::SLUG,
                organizationId: (string) $commentaire->organization_id,
                langue: $langue,
                sender: $acteur,
                vars: [
                    'recipient_name' => $destinataire->publicDisplayName(),
                    'recipient_email' => $destinataire->email,
                    'author_name' => $acteur?->publicDisplayName() ?? '',
                    'organization_name' => $organization?->name ?? '',
                    'listing_type' => $typeLisible,
                    'listing_title' => $annonce?->title ?? '',
                    'comment_url' => $url,
                    'comment_excerpt' => $extrait,
                    'preferences_url' => $urlReglages,
                    'app_name' => config('app.name'),
                ],
                // Le repli reprend le texte de `lang/{locale}/notifications.php`
                // plutôt qu'une vue Blade de plus — ce serait une troisième
                // version du même message à tenir alignée.
                //
                // L'ordre des substitutions n'est pas indifférent : l'extrait
                // passe EN DERNIER. Il porte du texte écrit par un membre, qui
                // peut très bien contenir « :url » — substitué plus tôt, il
                // serait relu comme un jeton. Et il est échappé à la main,
                // parce que ce chemin-là ne traverse pas `interpolate()`.
                repli: fn () => [
                    'subject' => __('notifications.email.'.self::SLUG.'.subject'),
                    'html' => str_replace(
                        ':extrait',
                        e($extrait),
                        str_replace(
                            [':url', ':reglages', ':titre'],
                            [$url, $urlReglages, e((string) ($annonce?->title ?? ''))],
                            (string) __('notifications.email.'.self::SLUG.'.body'),
                        ),
                    ),
                ],
            );
        } finally {
            app()->setLocale($langueOrigine);
        }
    }

    /**
     * L'annonce commentée, relue sans les portées et bornée à la main.
     *
     * Rend `null` plutôt que de lever : un courriel sans titre d'annonce reste
     * préférable à une livraison qui échoue, et le gabarit rend alors une chaîne
     * vide à cet emplacement. Le cas se produit si l'annonce a disparu entre
     * l'écriture du commentaire et l'envoi — une `ServiceRequest` n'a pas
     * `SoftDeletes`, elle est supprimée DUREMENT.
     */
    private function annonce(EntraideComment $commentaire): Service|ServiceRequest|null
    {
        $type = (string) $commentaire->commentable_type;

        if (! in_array($type, EntraideComment::TYPES_COMMENTABLES, true)) {
            return null;
        }

        // Portee d'Organization retiree NOMMEMENT : `withoutGlobalScopes()`
        // retirerait aussi `SoftDeletingScope`, et le courriel nommerait alors
        // une annonce supprimee.
        /** @var class-string<Service|ServiceRequest> $type */
        return $type::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($commentaire->commentable_id)
            ->where('organization_id', $commentaire->organization_id)
            ->first();
    }
}
