<?php

namespace App\Support\Notifications;

use App\Models\EmailLog;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * TASK-1377 — livrer par email, une fois et une seule.
 *
 * ## Tout est RELU au moment du travail
 *
 * Un job attend en file. Ce qu'il transporte est une photo d'un etat passe :
 * entre l'emission et l'envoi, le destinataire a pu quitter l'Organization,
 * couper ses emails, changer de langue, ou l'objet metier a pu etre revoque.
 * Rien de ce qui decide n'est donc lu depuis la charge utile — notification,
 * destinataire, appartenance, langue, preference et cible sont relus ici.
 *
 * ## La prise de travail est ATOMIQUE, et c'est le cœur
 *
 * Un `SELECT` puis un `UPDATE` laissent une fenetre pendant laquelle deux
 * workers voient tous deux `pending` : l'email part deux fois. Ici, une SEULE
 * requete fait la transition, avec l'etat attendu dans son `WHERE`. Le nombre de
 * lignes affectees tranche : 1 = ce worker possede la livraison, 0 = un autre
 * l'a prise. Aucune fenetre, sur les deux moteurs.
 *
 * Cette clause `WHERE status = pending` porte la meme regle que la table de
 * transitions du modele — en version non concurrente. Elle ne l'affaiblit pas :
 * elle est strictement plus forte, parce qu'elle est atomique.
 *
 * ## `ambiguous` protege le destinataire, pas le systeme
 *
 * Si le transport leve APRES qu'on lui a remis le message, personne ne sait s'il
 * est parti. Marquer `failed` inviterait a rejouer, donc a envoyer deux fois ;
 * marquer `sent` affirmerait une livraison peut-etre inexistante. `ambiguous`
 * dit l'ignorance, et n'est jamais rejoue automatiquement.
 *
 * ## FRONTIERE H1 (review Fable, 03/09) — jamais `failed` apres le transport
 *
 * Le defaut trouve : `Mail::html()` peut REUSSIR — SMTP a peut-etre deja
 * accepte le message — et si `tracer()` ou le `save()` de la livraison echoue
 * JUSTE APRES, le code concluait `failed`. Faux : le message est peut-etre deja
 * parti, et `failed` inviterait a le renvoyer — exactement le double envoi que
 * ce pipeline existe pour empecher.
 *
 * La regle, et elle est stricte : AVANT que `Mail::html()` ait ete appele, une
 * exception connue sans remise conclut `failed`. A partir du moment ou l'appel
 * est FRANCHI — qu'il ait reussi ou leve — plus rien ne peut conclure `failed`.
 * `tracerSansEchouer()` et `conclureSansEchouer()` executent cette regle : elles
 * n'echouent JAMAIS vers l'appelant, meme si l'ecriture de secours echoue a son
 * tour. Si meme celle-ci echoue, la livraison reste `sending` — observable via
 * `claimed_at`, pas un silence, et reprise par un humain, jamais par un rejeu
 * automatique.
 *
 * ## `EmailLog` est ecrit EXPLICITEMENT
 *
 * Il n'existe aucun listener global sur l'envoi de mail dans ce depot : `Mail`
 * n'ecrit rien tout seul, seuls les appelants explicites le font. Mesure etablie
 * en T1376. Ce pipeline ecrit donc sa propre preuve — en cas de succes ET en cas
 * d'echec, sinon l'historique ne garderait trace que de ce qui a marche.
 *
 * ## TASK-1378 — le vrai lien part, le jeton n'est JAMAIS archive
 *
 * La cible d'une notification peut etre une URL porteuse d'un jeton d'acces
 * vivant. Elle doit atteindre le destinataire — un email sans son lien ne sert a
 * rien — et ne doit jamais entrer dans `email_logs`, qui est consultable et sans
 * expiration propre : un jeton d'action qui y dort reste utilisable.
 *
 * Le gabarit est donc rendu DEUX FOIS : une fois avec la vraie URL, qui part et
 * qui est hachee, une fois avec un marqueur expurge, qui est archive. Pas une
 * expression reguliere passee apres coup sur le rendu reel : une regex doit
 * deviner ce qui est un jeton, se trompe des que le gabarit change, et echoue en
 * SILENCE. Ici la valeur sensible n'entre simplement jamais dans le rendu
 * archive.
 *
 * Et cette classe ne connait AUCUNE cle metier : elle substitue un parametre
 * `:url` que tout gabarit de ce module recoit. Rien n'est specifique a
 * `loop.invitation`.
 *
 * ## M1 (review Fable) — `error_message` ne contient jamais de texte brut
 *
 * Un message d'exception SMTP peut porter un host, un port, un fragment de DSN
 * ou une reponse serveur brute. `email_logs` est une table CONSULTABLE — par
 * l'Explorer, par un futur ecran d'administration — donc pas l'endroit ou
 * stocker cela, meme pour du diagnostic interne. Seul un CODE STABLE y est
 * ecrit ; le `Throwable` complet part vers `report()`, hors de toute trace
 * durable consultable.
 */
final class NotificationEmailDeliverer
{
    /**
     * La file DEDIEE.
     *
     * La file `default` porte un historique qui ne doit etre ni draine ni
     * consomme par cette tranche. Un worker borne a cette file-ci ne peut pas y
     * toucher, meme par erreur.
     */
    public const QUEUE = 'notifications-email';

    /** La largeur de `member_notification_deliveries.diagnostic`. */
    private const DIAGNOSTIC_MAX = 120;

    /**
     * La largeur DEFENSIVE de `email_logs.error_message`.
     *
     * La colonne est `text`, donc sans limite technique — precisement pourquoi
     * une borne applicative est necessaire : rien d'autre n'empecherait un
     * futur appelant d'y glisser un texte long. Les codes ecrits ici font tous
     * moins de 40 caracteres ; la marge est large a dessein.
     */
    private const ERROR_MESSAGE_MAX = 200;

    /**
     * Le marqueur qui remplace la cible dans le corps ARCHIVE.
     *
     * TASK-1378 — la cible peut porter un jeton d'action vivant. Il doit
     * atteindre le destinataire et ne jamais entrer dans `email_logs`, table
     * consultable et sans expiration propre. Voir `rendre()`.
     */
    private const LIEN_EXPURGE = '[action-link-redacted]';

    /**
     * Le transport a ete sollicite, et son issue est INCONNUE — qu'il ait leve
     * ou que la remise ait reussi sans que la suite ait pu le confirmer.
     */
    private const ERROR_TRANSPORT_OUTCOME_UNKNOWN = 'transport_outcome_unknown';

    /**
     * Le transport a REUSSI, mais la journalisation ou la conclusion qui suit a
     * echoue. Voir la section H1 du docblock de la classe.
     */
    private const ERROR_POST_TRANSPORT_PERSISTENCE_FAILED = 'post_transport_persistence_failed';

    public function __construct(
        private readonly NotificationPreferenceResolver $preferences,
        private readonly NotificationTargetResolver $cibles,
    ) {}

    public function deliver(string $notificationId): void
    {
        $notification = MemberNotification::query()->whereKey($notificationId)->first();

        // La notification a disparu : la livraison a suivi par cascade. Il n'y a
        // rien a livrer, et ce n'est pas une erreur.
        if ($notification === null) {
            return;
        }

        $livraison = MemberNotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->forChannel(NotificationCatalogue::CHANNEL_EMAIL)
            ->first();

        if ($livraison === null) {
            return;
        }

        // La prise de travail decide QUI travaille. Tout ce qui suit n'est
        // execute que par le worker qui l'a obtenue.
        if (! $this->prendre($livraison)) {
            return;
        }

        $livraison->refresh();

        try {
            $this->livrer($notification, $livraison);
        } catch (Throwable $incident) {
            // On n'arrive ici que si quelque chose a echoue HORS remise au
            // transport — la remise, elle, a son propre traitement dans
            // `envoyer()`. Un echec ici n'a rien envoye.
            //
            // Le diagnostic est un CODE STABLE, jamais le message d'exception.
            // Un message de moteur peut depasser la colonne — mesure : 129
            // caracteres pour une violation de contrainte, contre 120 permis —
            // et l'ecriture echouerait alors PAR-DESSUS l'incident d'origine,
            // masquant completement sa cause. Il peut aussi transporter des
            // fragments de requete, donc de donnees.
            $this->conclure($livraison, NotificationDeliveryStatus::FAILED, 'delivery_internal_error');
        }
    }

    /**
     * La transition `pending -> sending`, en UNE requete.
     *
     * @return bool ce worker possede-t-il desormais la livraison ?
     */
    private function prendre(MemberNotificationDelivery $livraison): bool
    {
        $maintenant = now();

        $lignes = DB::table('member_notification_deliveries')
            ->where('id', $livraison->id)
            // L'etat attendu est DANS la clause : c'est ce qui rend la prise
            // atomique. Le relire d'abord en PHP rouvrirait la fenetre.
            ->where('status', NotificationDeliveryStatus::PENDING)
            ->update([
                'status' => NotificationDeliveryStatus::SENDING,
                'claimed_at' => $maintenant,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $maintenant,
            ]);

        return $lignes === 1;
    }

    private function livrer(MemberNotification $notification, MemberNotificationDelivery $livraison): void
    {
        $destinataire = User::query()->whereKey($notification->recipient_id)->first();

        if ($destinataire === null || blank($destinataire->email)) {
            $this->conclure($livraison, NotificationDeliveryStatus::SKIPPED_UNREACHABLE, 'recipient_missing_or_no_email');

            return;
        }

        // L'appartenance est relue a la source, sans passer par Eloquent : les
        // portees globales ne resolvent rien sous worker, faute de contexte de
        // requete. Meme raison qu'en T1372.
        $tenant = DB::table('users')->where('id', $destinataire->id)->value('organization_id');

        if ($tenant === null || $tenant !== $notification->organization_id) {
            $this->conclure($livraison, NotificationDeliveryStatus::SKIPPED_UNREACHABLE, 'recipient_left_organization');

            return;
        }

        // La preference COURANTE, pas celle d'au moment de l'emission.
        if (! $this->preferences->allows($destinataire, (string) $notification->notification_key, NotificationCatalogue::CHANNEL_EMAIL)) {
            $this->conclure($livraison, NotificationDeliveryStatus::SKIPPED_PREFERENCE, 'channel_disabled_by_member');

            return;
        }

        // La cible COURANTE. Le resolveur verifie l'objet vivant — invitation
        // revoquee, acceptee, expiree — et rend `null` si elle n'est plus
        // atteignable. Envoyer un email vers un lien mort serait pire que ne
        // rien envoyer.
        $cible = $this->cibles->resolve($notification);

        if ($cible === null) {
            $this->conclure($livraison, NotificationDeliveryStatus::SKIPPED_UNREACHABLE, 'target_no_longer_reachable');

            return;
        }

        $this->envoyer($notification, $livraison, $destinataire, $cible);
    }

    private function envoyer(
        MemberNotification $notification,
        MemberNotificationDelivery $livraison,
        User $destinataire,
        string $cible,
    ): void {
        // La langue COURANTE du destinataire, figee ici : c'est la seule qu'on
        // pourra affirmer plus tard, la preference ayant pu changer depuis.
        $locale = (string) ($destinataire->preferred_locale ?: config('app.locale'));

        $contenu = $this->rendre((string) $notification->notification_key, $locale, $cible);

        if ($contenu === null) {
            // Aucune traduction pour cette cle. `__()` rendrait la cle elle-meme,
            // donc un email contenant `notifications.email.…` — ce qui est pire
            // que pas d'email du tout. Fail-closed, et le diagnostic le dit.
            $this->conclure($livraison, NotificationDeliveryStatus::FAILED, 'missing_email_translation');

            return;
        }

        try {
            Mail::html($contenu['body'], function ($message) use ($destinataire, $contenu) {
                $message->to($destinataire->email)->subject($contenu['subject']);
            });
        } catch (Throwable $incident) {
            // Le transport a leve. On ne peut pas savoir si le message est parti
            // avant la coupure : c'est exactement ce que `ambiguous` designe.
            //
            // A partir d'ICI, le transport est FRANCHI — l'appel a eu lieu.
            // `tracerSansEchouer()` et `conclureSansEchouer()` n'echouent donc
            // jamais vers l'appelant : voir la section H1 du docblock de la
            // classe. Le `Throwable` complet part vers `report()` (M1) ; seul un
            // code stable atteint la trace durable.
            report($incident);

            $this->tracerSansEchouer($notification, $destinataire, $contenu, $locale, EmailLog::STATUS_AMBIGUOUS, self::ERROR_TRANSPORT_OUTCOME_UNKNOWN);
            $this->conclureSansEchouer($livraison, NotificationDeliveryStatus::AMBIGUOUS, self::ERROR_TRANSPORT_OUTCOME_UNKNOWN);

            return;
        }

        // FRONTIERE H1 — le transport a REUSSI : `Mail::html()` n'a pas leve.
        // SMTP a peut-etre deja accepte le message. A partir d'ici, plus AUCUNE
        // exception ne peut conclure `failed` — meme une exception levee par
        // `tracer()` ou par le `save()` de la livraison. `failed` inviterait a
        // renvoyer un message peut-etre deja parti : exactement le double envoi
        // que ce pipeline existe pour empecher.
        //
        // Le bloc vit dans un POINT DE SAUVEGARDE, pour la meme raison qu'en
        // T1372 (`NotificationEmitter`) : sur PostgreSQL, une requete qui echoue
        // ABANDONNE la transaction courante, et toute requete suivante echoue en
        // 25P02 tant qu'on n'a pas fait de rollback. Sans ce point de
        // sauvegarde, un echec ici rendrait aussi la connexion inutilisable pour
        // l'ecriture de secours qui suit — la garantie « meme si l'ecriture de
        // secours echoue a son tour » deviendrait fausse des qu'un appelant
        // ouvre sa propre transaction autour de `deliver()`.
        try {
            DB::transaction(function () use ($notification, $livraison, $destinataire, $contenu, $locale) {
                $this->tracer($notification, $destinataire, $contenu, $locale, EmailLog::STATUS_SENT, null);

                $livraison->status = NotificationDeliveryStatus::SENT;
                $livraison->sent_at = now();
                $livraison->save();
            });
        } catch (Throwable $incident) {
            report($incident);

            $this->conclureSansEchouer($livraison, NotificationDeliveryStatus::AMBIGUOUS, self::ERROR_POST_TRANSPORT_PERSISTENCE_FAILED);
        }
    }

    /**
     * Comme `tracer()`, mais n'echoue JAMAIS vers l'appelant.
     *
     * Reservee a l'APRES-transport : une exception ici ne doit pas remonter
     * jusqu'au `catch` de `deliver()`, qui conclurait `failed` — precisement ce
     * que la frontiere H1 interdit. Le point de sauvegarde protege la meme
     * chose que dans `envoyer()` : voir ce docblock.
     *
     * @param  array{subject: string, body: string}  $contenu
     */
    private function tracerSansEchouer(
        MemberNotification $notification,
        User $destinataire,
        array $contenu,
        string $locale,
        string $status,
        string $erreur,
    ): void {
        try {
            DB::transaction(fn () => $this->tracer($notification, $destinataire, $contenu, $locale, $status, $erreur));
        } catch (Throwable $incident) {
            report($incident);
        }
    }

    /**
     * Comme `conclure()`, mais n'echoue JAMAIS vers l'appelant.
     *
     * Meme raison que `tracerSansEchouer()`. Si cette ecriture de secours
     * echoue elle-meme — y compris a cause d'une transaction deja abandonnee
     * par l'incident qui a mene ici — la livraison reste `sending` : c'est
     * OBSERVABLE via `claimed_at`, pas un silence, et un humain, jamais un
     * rejeu automatique, la reprendra.
     */
    private function conclureSansEchouer(MemberNotificationDelivery $livraison, string $status, string $diagnostic): void
    {
        try {
            DB::transaction(fn () => $this->conclure($livraison, $status, $diagnostic));
        } catch (Throwable $incident) {
            report($incident);
        }
    }

    /**
     * Le sujet et LES DEUX CORPS, dans la langue du destinataire.
     *
     * ## Deux rendus du MEME gabarit, pas une expurgation apres coup
     *
     * TASK-1378 — la cible d'une notification peut etre une URL porteuse d'un
     * jeton d'acces vivant. Elle doit atteindre le destinataire, et ne doit
     * JAMAIS etre archivee : `email_logs` est une table consultable, sans
     * expiration propre, et un jeton d'action qui y dort reste utilisable.
     *
     * Le gabarit est donc rendu DEUX FOIS, avec deux valeurs de `:url` :
     *
     * - `body` — la vraie URL. C'est ce qui part, et ce qui est hache.
     * - `body_for_log` — le meme gabarit avec un marqueur expurge. C'est ce qui
     *   est archive.
     *
     * Deux rendus plutot qu'une expression reguliere passee sur le rendu reel :
     * une regex doit deviner ce qui est un jeton, se trompe des que le gabarit
     * change, et echoue en SILENCE — elle laisserait alors passer exactement ce
     * qu'elle existe pour retirer. Ici, la valeur sensible n'entre simplement
     * jamais dans le second rendu. La donnee ne doit pas ENTRER, pas etre
     * interdite de sortie.
     *
     * ## Cette methode ne connait AUCUNE cle metier
     *
     * Elle ne sait pas ce qu'est une invitation. Elle substitue un parametre
     * `:url` que tout gabarit d'email de ce module recoit, et le marqueur est
     * une constante. Rien ici n'est specifique a `loop.invitation` : le jour ou
     * une autre cle arrive, elle herite de la meme protection sans code
     * supplementaire.
     *
     * @return array{subject: string, body: string, body_for_log: string}|null
     */
    private function rendre(string $notificationKey, string $locale, string $cible): ?array
    {
        // Les points de la cle sont remplaces : `loop.invitation` produirait
        // `notifications.email.loop.invitation.subject`, que le resolveur de
        // traductions lit comme une HIERARCHIE de groupes — le fichier
        // `notifications`, puis `email.loop.invitation.subject`. La cle devient
        // alors introuvable pour une raison qui n'a rien a voir avec son
        // contenu. `loop_invitation` leve l'ambiguite.
        $racine = 'notifications.email.'.str_replace('.', '_', $notificationKey);

        if (! Lang::has($racine.'.subject', $locale) || ! Lang::has($racine.'.body', $locale)) {
            return null;
        }

        return [
            'subject' => (string) __($racine.'.subject', [], $locale),
            'body' => (string) __($racine.'.body', ['url' => e($cible)], $locale),
            'body_for_log' => (string) __($racine.'.body', ['url' => self::LIEN_EXPURGE], $locale),
        ];
    }

    /**
     * La preuve historique, ecrite explicitement.
     *
     * Ecrite aussi quand l'issue est incertaine : un historique qui ne garderait
     * que les succes ne servirait a rien le jour ou il faut comprendre ce qui
     * s'est passe.
     *
     * `$erreur` est un CODE STABLE, jamais un message d'exception brut (M1,
     * review Fable) — voir la section M1 du docblock de la classe. Borne ici
     * DEFENSIVEMENT malgre tout : la colonne est `text`, donc sans limite
     * technique, et rien d'autre n'empecherait un futur appelant d'y glisser un
     * texte long.
     *
     * ## `body_html` archive le corps EXPURGE, `body_hash` hache le corps REEL
     *
     * TASK-1378 — et la dissymetrie est DELIBEREE, c'est tout l'interet :
     *
     * - `body_html` recoit `body_for_log`, ou la cible est remplacee par un
     *   marqueur. Aucun jeton d'action vivant n'entre donc dans une table
     *   consultable et sans expiration propre.
     * - `body_hash` hache `body` — le corps REELLEMENT remis au transport. Un
     *   hachage du corps expurge ne repondrait a rien : la seule question utile
     *   est « le message parti est-il bien celui qu'on croit ? », et elle porte
     *   sur ce qui est parti.
     *
     * Consequence assumee : le hachage ne se verifie pas en recalculant depuis
     * `body_html`. C'est le prix de ne pas archiver le jeton, et c'est le bon
     * cote du compromis.
     *
     * @param  array{subject: string, body: string, body_for_log: string}  $contenu
     */
    private function tracer(
        MemberNotification $notification,
        User $destinataire,
        array $contenu,
        string $locale,
        string $status,
        ?string $erreur,
    ): void {
        $journal = new EmailLog([
            'user_id' => (string) $destinataire->id,
            'to_email' => (string) $destinataire->email,
            'subject' => $contenu['subject'],
            'status' => $status,
            'error_message' => $erreur === null ? null : mb_substr($erreur, 0, self::ERROR_MESSAGE_MAX),
            'organization_id' => (string) $notification->organization_id,
        ]);

        // Ces colonnes sont hors `fillable` : elles ne viennent jamais d'une
        // requete, seulement d'ici.
        $journal->notification_id = (string) $notification->id;
        $journal->locale = $locale;
        $journal->body_html = $contenu['body_for_log'];
        $journal->body_hash = hash('sha256', $contenu['body']);
        $journal->save();
    }

    /**
     * Pose l'etat de fin.
     *
     * Le diagnostic est borne DEFENSIVEMENT, meme si tous les appelants passent
     * aujourd'hui des codes courts : une ecriture qui deborde la colonne
     * echouerait en cascade et remplacerait l'incident a diagnostiquer par un
     * incident de stockage.
     */
    private function conclure(MemberNotificationDelivery $livraison, string $status, string $diagnostic): void
    {
        $livraison->status = $status;
        $livraison->diagnostic = mb_substr($diagnostic, 0, self::DIAGNOSTIC_MAX);
        $livraison->save();
    }
}
