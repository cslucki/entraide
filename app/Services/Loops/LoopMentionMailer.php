<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use App\Support\Email\SystemEmailTemplateResolver;
use App\Support\Notifications\GabaritEmail;
use App\Support\ScopedReadCache;
use Illuminate\Support\Str;

/**
 * TASK-1772 — le courriel « on vous a mentionné ».
 *
 * ## Le texte ne vit PAS ici
 *
 * Il est posé en base par migration (Global, FR et EN) et le SuperAdmin peut
 * le réécrire depuis `/admin/system-email-templates`. Ce fichier ne porte que
 * les **variables offertes** et le **repli**.
 *
 * ## Tout se lit sans les scopes globaux, puis se borne à la main
 *
 * Ce code tourne dans un **worker**. Il n'y a alors aucune
 * `current_organization`, donc `BelongsToOrganizationScope` applique son
 * fail-closed et les objets ressortent INTROUVABLES — en silence. C'est le
 * défaut que `TransactionProposalMailer` a payé en recette le 04/11 : le
 * titre de l'annonce arrivait vide dans le courriel, sans la moindre erreur.
 *
 * La frontière de tenant n'est pas affaiblie pour autant : le
 * `where('organization_id', …)` est posé explicitement sous chaque retrait,
 * et c'est lui seul qui décide.
 */
final class LoopMentionMailer
{
    public const SLUG = 'loop_mention_created';

    /**
     * TASK-1797 — le gabarit de la mention COLLECTIVE.
     *
     * Un slug distinct, pas une variante du meme texte : « {{ author_name }}
     * vous a mentionné » serait une interpellation nominative abusive quand
     * `@tous` a ete employe — personne n'a ete nomme. Meme `notification_key`
     * (`LOOP_MENTION_CREATED`), meme destinataire, meme evenement — seul le
     * TEXTE differe, et c'est tout ce que ce second gabarit porte.
     */
    public const SLUG_TOUS = 'loop_mention_all_created';

    /**
     * Ce que le courriel reprend du message, au plus.
     *
     * Le but est de pouvoir LIRE la phrase sans ouvrir l'application — pas de
     * recopier le fil. Au-dela, `Str::limit()` coupe et pose une ellipse, et le
     * lien reste la pour la suite.
     */
    public const EXTRAIT_MAX = 400;

    /**
     * Ce qui remplace l'extrait dans la copie ARCHIVEE (`email_logs`).
     *
     * Meme raison que le `[action-link-redacted]` du livreur, un cran plus
     * loin : le lien etait expurge parce qu'il OUVRE une porte, l'extrait l'est
     * parce qu'il porte la parole d'un membre dans une Boucle privee. Le
     * journal garde la preuve que le courriel est parti et ce qu'il contenait
     * comme STRUCTURE ; il n'archive pas la conversation.
     */
    public const EXTRAIT_EXPURGE = '[message-redacted]';

    /**
     * TASK-1799 (A1) — UN SEUL appel rend les DEUX variantes (le courriel
     * reel ET sa copie expurgee pour `email_logs`), a partir d'UNE SEULE
     * resolution du gabarit administrable.
     *
     * Avant A1, l'appelant (`NotificationEmailDeliverer`) appelait
     * `compose()` deux fois pour le MEME destinataire — une fois `$pourJournal
     * = false`, une fois `true` — et chaque appel relisait independamment la
     * meme ligne de gabarit (meme slug, meme Organization, meme langue), la
     * meme Boucle et la meme Organization. Mesure TASK-1798 : ce doublon
     * comptait pour une part mesurable des requetes SQL du chemin de
     * mention. Fusionner les deux appels en un seul, sans toucher au TEXTE
     * ni aux liens produits, supprime le doublon sans rien changer au
     * courriel envoye.
     *
     * @return array{
     *     reel: array{subject: string, body: string, fallback_reason: string|null},
     *     journal: array{subject: string, body: string, fallback_reason: string|null},
     * }
     */
    public function compose(
        LoopMessage $message,
        User $destinataire,
        ?User $acteur,
        string $url,
        string $urlJournal,
        string $langue,
    ): array {
        $langueOrigine = app()->getLocale();
        app()->setLocale($langue);

        try {
            // TASK-1799 (B1) — memes cles que `NotificationTargetResolver::
            // loopMessage()`/`loop()`, deja remplies dans l'immense majorite
            // des cas par la resolution de cible qui precede systematiquement
            // cet appel (`NotificationEmailDeliverer::livrer()`). Donnees de
            // reference, identiques pour TOUS les destinataires d'une meme
            // mention : voir le docblock de `ScopedReadCache` (precedent
            // TASK-1778).
            $boucle = app(ScopedReadCache::class)->souviens(
                'cible-boucle:'.$message->organization_id.':'.$message->loop_id,
                fn (): ?Loop => Loop::query()
                    ->withoutGlobalScope(BelongsToOrganizationScope::class)
                    ->whereKey($message->loop_id)
                    ->where('organization_id', $message->organization_id)
                    ->first(),
            );

            $organization = app(ScopedReadCache::class)->souviens(
                'cible-organization:'.$message->organization_id,
                fn (): ?Organization => Organization::query()
                    ->withoutGlobalScope(BelongsToOrganizationScope::class)
                    ->whereKey($message->organization_id)
                    ->first(),
            );

            // ## L'extrait, et pourquoi il est la
            //
            // Demande de Cyril en recette (06/11) : « cela reduit le FOMO, et
            // on ne stresse pas les membres. Ils peuvent lire le message sur
            // la Boucle, mais ils ont le temps de le faire quand ils veulent
            // car ils ont eu l'info dans l'email. »
            //
            // Le corps n'est PAS echappe ici : `EmailerService::interpolate()`
            // passe chaque variable par `e()`. L'echapper deux fois afficherait
            // `&amp;` a la place de `&`. Les retours a la ligne sont rendus par
            // `white-space: pre-line` dans le gabarit — un `<br>` injecte ici
            // serait echappe et s'afficherait tel quel.
            $corps = trim((string) $message->body);
            $extraitReel = $corps === ''
                ? (string) __('notifications.mention_email_excerpt_empty')
                : Str::limit($corps, self::EXTRAIT_MAX);

            // Le lien de desabonnement mene aux reglages DE L'ORGANIZATION :
            // les preferences sont par tenant, et la version sans `org/` ferait
            // atterrir sur un autre jeu de cases. Le slug plutot que l'id, pour
            // la meme raison qu'en T1720 : cette adresse se lit dans un
            // courriel, et parfois se recopie a la main.
            $urlReglagesReel = $organization !== null
                ? route('organization.notifications.preferences.edit', ['organization' => $organization])
                : route('notifications.preferences.edit');

            // TASK-1797 — le slug depend du FAIT, pas d'un choix de
            // l'appelant : `mention_all` est pose sur le message lui-meme
            // par `LoopMessageService`/`LoopChat`, donc identique pour TOUS
            // ses destinataires, qu'ils soient lus a l'instant de l'envoi ou
            // rejoues plus tard par un worker.
            $slug = ($message->metadata['mention_all'] ?? false) ? self::SLUG_TOUS : self::SLUG;

            // TASK-1799 (A1) — la ligne de gabarit est IDENTIQUE pour les deux
            // rendus de ce destinataire (meme slug, meme Organization, meme
            // langue) : une seule lecture, partagee ci-dessous via
            // `GabaritEmail::rendre(..., templateResolu: ...)`.
            $templateResolu = app(SystemEmailTemplateResolver::class)
                ->resolve($slug, (string) $message->organization_id, $langue);

            $rendre = function (string $extrait, string $urlMessage, string $urlReglages) use (
                $slug, $message, $acteur, $destinataire, $organization, $boucle, $templateResolu,
            ): array {
                return app(GabaritEmail::class)->rendre(
                    slug: $slug,
                    organizationId: (string) $message->organization_id,
                    langue: app()->getLocale(),
                    sender: $acteur,
                    vars: [
                        'recipient_name' => $destinataire->publicDisplayName(),
                        'recipient_email' => $destinataire->email,
                        'author_name' => $acteur?->publicDisplayName() ?? '',
                        'organization_name' => $organization?->name ?? '',
                        'loop_name' => $boucle?->name ?? '',
                        'message_url' => $urlMessage,
                        'message_excerpt' => $extrait,
                        'preferences_url' => $urlReglages,
                        'app_name' => config('app.name'),
                    ],
                    // Le repli reprend le texte que la suite de cohérence exige
                    // déjà dans `lang/{locale}/notifications.php`, plutôt qu'une
                    // vue Blade de plus — ce serait une troisième version du même
                    // message à tenir alignée.
                    //
                    // L'ordre des substitutions n'est pas indifferent : l'extrait
                    // passe EN DERNIER. Il porte du texte ecrit par un membre, qui
                    // peut tres bien contenir « :url » — substitue plus tot, il
                    // serait relu comme un jeton. Et il est echappe a la main,
                    // parce que ce chemin-la ne traverse pas `interpolate()`.
                    repli: fn () => [
                        'subject' => __('notifications.email.'.$slug.'.subject'),
                        'html' => str_replace(
                            ':extrait',
                            e($extrait),
                            str_replace(
                                [':url', ':reglages'],
                                [$urlMessage, $urlReglages],
                                (string) __('notifications.email.'.$slug.'.body'),
                            ),
                        ),
                    ],
                    templateResolu: $templateResolu,
                );
            };

            return [
                'reel' => $rendre($extraitReel, $url, $urlReglagesReel),
                // La copie destinee a `email_logs` : l'extrait est expurge, et
                // le marqueur `$urlJournal` sert AUSSI de lien de reglages —
                // meme raison qu'avant A1, seule la forme a change.
                'journal' => $rendre(self::EXTRAIT_EXPURGE, $urlJournal, $urlJournal),
            ];
        } finally {
            app()->setLocale($langueOrigine);
        }
    }
}
