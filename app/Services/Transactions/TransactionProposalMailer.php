<?php

namespace App\Services\Transactions;

use App\Models\Transaction;
use App\Models\User;
use App\Support\Notifications\GabaritEmail;

/**
 * TASK-1751 — le courriel « quelqu'un repond a votre annonce ».
 *
 * ## Une classe a part, et pas une methode de plus sur `LoopInvitationMailer`
 *
 * Les trois composeurs existants vivent dans `LoopInvitationMailer` parce
 * qu'ils parlent tous de Boucles. Une proposition d'echange n'en est pas une :
 * y ajouter une quatrieme methode aurait fait mentir le nom du fichier, et
 * c'est ainsi qu'une classe devient un fourre-tout. Le moteur de rendu, lui,
 * est partage — `GabaritEmail`.
 *
 * ## Le gabarit est ADMINISTRABLE, et c'etait la demande
 *
 * Cyril voulait que le modele soit visible et modifiable dans
 * `/admin/system-email-templates`. Le texte ne vit donc pas ici : il est pose
 * en base par migration (Global, FR et EN), et le SuperAdmin peut le reecrire.
 * Ce fichier ne porte que les VARIABLES offertes et le repli.
 *
 * ## Ce que le texte ne doit pas faire
 *
 * Ne pas distinguer « service » et « demande ». Une seule cle sert les deux
 * sens de l'echange, et `$transaction->subject` rend deja le titre de l'un ou
 * de l'autre sans que l'appelant ait a savoir lequel.
 */
final class TransactionProposalMailer
{
    public const SLUG = 'transaction_proposal_created';

    /**
     * Le titre de l'annonce, lu SANS les scopes globaux.
     *
     * ## Le defaut que cette methode repare (trouve en recette, 04/11)
     *
     * `Transaction::subject` lit `$this->service` et `$this->serviceRequest`.
     * Ces relations portent `BelongsToOrganizationScope`, qui se resout sur
     * l'Organization COURANTE. Or ce code tourne dans un worker, ou il n'y en
     * a aucune : les deux relations rendaient `null`, et `subject` retombait
     * sur son repli generique. L'objet du courriel annoncait
     * « Échange #01a1a865 » au lieu de « Relire un dossier de candidature ».
     *
     * Le defaut etait INVISIBLE au test : un test HTTP s'execute avec une
     * Organization courante, et la relation se chargeait donc correctement.
     * Seule la recette, qui passe par la vraie file, pouvait le montrer.
     *
     * La frontiere de locataire n'est pas perdue pour autant : elle est
     * reposee explicitement par le `where` sur `organization_id`, qui est la
     * vraie garde — le scope n'etait qu'une commodite de requete.
     */
    private function titreDeLAnnonce(Transaction $transaction): string
    {
        if ($transaction->service_id !== null) {
            $service = \App\Models\Service::withoutGlobalScopes()
                ->whereKey($transaction->service_id)
                ->where('organization_id', $transaction->organization_id)
                ->first();

            if ($service !== null) {
                return (string) $service->title;
            }
        }

        if ($transaction->request_id !== null) {
            $demande = \App\Models\ServiceRequest::withoutGlobalScopes()
                ->whereKey($transaction->request_id)
                ->where('organization_id', $transaction->organization_id)
                ->first();

            if ($demande !== null) {
                return (string) $demande->title;
            }
        }

        // L'annonce a disparu entre l'emission et l'envoi : le repli de
        // `subject` reste la reponse juste, mais il est desormais le DERNIER
        // recours et non le cas ordinaire.
        return (string) $transaction->subject;
    }

    /**
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    public function compose(
        Transaction $transaction,
        User $destinataire,
        ?User $acteur,
        string $url,
        string $langue,
    ): array {
        $langueOrigine = app()->getLocale();
        app()->setLocale($langue);

        try {
            $organization = $transaction->organization;

            return app(GabaritEmail::class)->rendre(
                slug: self::SLUG,
                organizationId: (string) $transaction->organization_id,
                langue: $langue,
                sender: $acteur,
                vars: [
                    'recipient_name' => $destinataire->publicDisplayName(),
                    'recipient_email' => $destinataire->email,
                    'sender_name' => $acteur?->publicDisplayName() ?? '',
                    'organization_name' => $organization?->name ?? '',
                    // Le titre du service OU de la demande, indifferemment.
                    'subject_title' => $this->titreDeLAnnonce($transaction),
                    'points_proposed' => (string) $transaction->points_proposed,
                    'exchange_url' => $url,
                    'app_name' => config('app.name'),
                ],
                // Le repli reprend le texte que la suite de coherence exige
                // deja dans `lang/{locale}/notifications.php` — plutot qu'une
                // vue Blade de plus, qui serait une troisieme version du meme
                // message a tenir alignee.
                repli: fn () => [
                    'subject' => __('notifications.email.'.self::SLUG.'.subject'),
                    'html' => str_replace(':url', $url, __('notifications.email.'.self::SLUG.'.body')),
                ],
            );
        } finally {
            app()->setLocale($langueOrigine);
        }
    }
}
