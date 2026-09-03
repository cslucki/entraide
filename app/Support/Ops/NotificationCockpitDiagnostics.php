<?php

namespace App\Support\Ops;

use App\Models\EmailLog;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use App\Support\Notifications\NotificationEmailDeliverer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1380 — ce que le systeme de notifications est en train de faire.
 *
 * ## Supervision n'est pas lecture
 *
 * Ce service COMPTE, il ne NOMME pas. Il ne rend aucun destinataire, aucun
 * acteur, aucune adresse, aucun corps de message, aucun identifiant d'objet
 * metier. Un SuperAdmin doit pouvoir dire « la livraison email est en panne »
 * sans jamais apprendre qui a ete invite ou quel document a ete partage.
 *
 * Meme doctrine que le cockpit IA plateforme (T1223) : « Il ne voit RIEN du
 * contenu tenant. »
 *
 * ## Ce qui n'est JAMAIS lu, et pourquoi
 *
 * - `email_logs.body_html` — porte le corps ARCHIVE. Il est expurge du jeton,
 *   mais **cette garantie est une garantie de CHEMIN, pas de colonne** : elle ne
 *   vaut que pour les lignes ecrites par `NotificationEmailDeliverer`. Rien
 *   n'empeche un futur appelant d'y ecrire un corps entier. Un ecran qui le
 *   rendrait deviendrait retroactivement la fuite de cet appelant. On n'en lit
 *   que la PRESENCE.
 * - `email_logs.error_message` — sur le chemin notifications, c'est un code
 *   stable. Ailleurs, `AdminEmailController` y ecrit le message d'exception
 *   BRUT, qui peut porter un host ou un fragment de DSN. On ne l'agrege donc
 *   jamais ; `member_notification_deliveries.diagnostic` dit la meme chose et
 *   est sur par construction.
 * - `email_logs.data`, `to_email`, `body_hash` — champ libre, adresse en clair,
 *   empreinte correlable.
 * - `jobs.payload`, `failed_jobs.payload` et `exception` — charge serialisee et
 *   trace complete. Ici la charge ne contient qu'un UUID, mais c'est une
 *   propriete de la tranche courante, pas de la colonne.
 * - `recipient_id`, `actor_id`, `user_id` — des identites.
 *
 * ## Le transport n'est pas relu ici
 *
 * `MailDiagnostics` en est l'unique autorite, et applique deja la regle du
 * non-masquage. Un second lecteur serait un second endroit ou l'oublier.
 *
 * ## `skipped_*` n'est PAS une erreur
 *
 * Un membre qui a coupe l'email n'est pas une panne, et une invitation acceptee
 * avant l'envoi non plus. Ces etats sont comptes a part, et jamais agreges dans
 * un taux d'echec — les confondre ferait paniquer sur un systeme qui va bien.
 */
final class NotificationCockpitDiagnostics
{
    /**
     * Au-dela de ce delai, une livraison `sending` n'est plus « en cours ».
     *
     * Le job declare `$timeout = 60` : un worker vivant a donc rendu la main
     * bien avant. Cinq minutes laissent toute la marge necessaire et evitent le
     * faux positif, tout en restant assez court pour etre utile.
     */
    public const SECONDES_AVANT_BLOCAGE = 300;

    /** Meme raisonnement pour une livraison jamais prise en charge. */
    public const SECONDES_AVANT_ATTENTE_ANORMALE = 300;

    public function __construct(private readonly MailDiagnostics $transport) {}

    /**
     * @return array{
     *     transport: array<string, mixed>,
     *     catalogue: list<array<string, mixed>>,
     *     livraisons: array<string, int>,
     *     diagnostics: list<array{code: string, total: int}>,
     *     file: array<string, mixed>,
     *     alertes: array<string, mixed>,
     *     activite: list<array<string, mixed>>,
     *     preuves: array<string, int>,
     * }
     */
    public function overview(): array
    {
        return [
            'transport' => $this->transport->transport(),
            'catalogue' => $this->catalogue(),
            'livraisons' => $this->livraisonsParEtat(),
            'diagnostics' => $this->diagnostics(),
            'file' => $this->file(),
            'alertes' => $this->alertes(),
            'activite' => $this->activiteParOrganisation(),
            'preuves' => $this->preuves(),
        ];
    }

    /**
     * Le catalogue, tel qu'il est REELLEMENT declare.
     *
     * Itere le registre plutot que de coder les cles en dur : une cle ajoutee
     * apparait ici sans une ligne de code. Le volume est joint depuis les
     * notifications emises.
     */
    private function catalogue(): array
    {
        $volumes = DB::table('member_notifications')
            ->selectRaw('notification_key, count(*) as total, max(created_at) as derniere')
            ->groupBy('notification_key')
            ->get()
            ->keyBy('notification_key');

        $lignes = [];

        foreach (NotificationCatalogue::keys() as $cle) {
            $canaux = [];

            foreach (NotificationCatalogue::channelsFor($cle) as $canal) {
                $canaux[] = [
                    'canal' => $canal,
                    'defaut' => (bool) NotificationCatalogue::channelDefault($cle, $canal),
                    'configurable' => NotificationCatalogue::channelIsConfigurable($cle, $canal),
                ];
            }

            $mesure = $volumes->get($cle);

            $lignes[] = [
                'cle' => $cle,
                'object_type' => NotificationCatalogue::objectTypeFor($cle),
                'canaux' => $canaux,
                'total' => (int) ($mesure->total ?? 0),
                'derniere' => $mesure->derniere ?? null,
            ];
        }

        return $lignes;
    }

    /**
     * Les livraisons par etat, canal par canal.
     *
     * Une seule requete groupee, qui frappe l'index `(channel, status)`.
     */
    private function livraisonsParEtat(): array
    {
        $etats = array_fill_keys(NotificationDeliveryStatus::ALL, 0);

        $lignes = DB::table('member_notification_deliveries')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get();

        foreach ($lignes as $ligne) {
            $etats[$ligne->status] = (int) $ligne->total;
        }

        // `skipped_*` est une DECISION, pas un incident : compte a part, et
        // jamais dans le taux d'echec.
        $etats['_en_cours'] = $etats[NotificationDeliveryStatus::PENDING] + $etats[NotificationDeliveryStatus::SENDING];
        $etats['_ignorees'] = $etats[NotificationDeliveryStatus::SKIPPED_PREFERENCE] + $etats[NotificationDeliveryStatus::SKIPPED_UNREACHABLE];
        $etats['_incidents'] = $etats[NotificationDeliveryStatus::FAILED] + $etats[NotificationDeliveryStatus::AMBIGUOUS];

        return $etats;
    }

    /**
     * Les codes de diagnostic rencontres.
     *
     * Sur par construction : la colonne ne recoit que des codes stables bornes a
     * 120 caracteres, jamais un message de moteur ni de transport.
     */
    private function diagnostics(): array
    {
        return DB::table('member_notification_deliveries')
            ->selectRaw('diagnostic, count(*) as total')
            ->whereNotNull('diagnostic')
            ->groupBy('diagnostic')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($l) => ['code' => (string) $l->diagnostic, 'total' => (int) $l->total])
            ->all();
    }

    /**
     * La file DEDIEE, jamais `default`.
     *
     * `default` porte un historique de quarantaine qui n'appartient pas a cette
     * verticale. Il est compte a titre de reference — pour qu'une derive se
     * voie — mais jamais melange aux chiffres des notifications.
     */
    private function file(): array
    {
        $dediee = DB::table('jobs')->where('queue', NotificationEmailDeliverer::QUEUE);

        return [
            'nom' => NotificationEmailDeliverer::QUEUE,
            'en_attente' => (clone $dediee)->count(),
            'prises' => (clone $dediee)->whereNotNull('reserved_at')->count(),
            'plus_ancien' => $this->horodatage((clone $dediee)->min('available_at')),
            'echouees' => DB::table('failed_jobs')->where('queue', NotificationEmailDeliverer::QUEUE)->count(),
            'default_reference' => DB::table('jobs')->where('queue', 'default')->count(),
        ];
    }

    /**
     * Ce qui demande une intervention HUMAINE.
     *
     * Aucun rejeu n'est automatique en V1-A : ces deux situations sont donc
     * documentees comme observables, et c'est cet ecran qui les rend visibles.
     *
     * La distinction entre les deux causes compte, parce que le remede differe :
     * des livraisons en attente SANS job veut dire que les jobs sont perdus ;
     * des livraisons en attente AVEC des jobs veut dire que le worker ne tourne
     * pas. On donne donc les deux chiffres plutot qu'un verdict.
     */
    private function alertes(): array
    {
        $bloquees = DB::table('member_notification_deliveries')
            ->where('status', NotificationDeliveryStatus::SENDING)
            ->where('claimed_at', '<', now()->subSeconds(self::SECONDES_AVANT_BLOCAGE))
            ->count();

        $enAttenteAnciennes = DB::table('member_notification_deliveries')
            ->where('status', NotificationDeliveryStatus::PENDING)
            ->where('created_at', '<', now()->subSeconds(self::SECONDES_AVANT_ATTENTE_ANORMALE))
            ->count();

        $reprises = DB::table('member_notification_deliveries')
            ->where('attempts', '>', 1)
            ->count();

        return [
            'bloquees_en_envoi' => $bloquees,
            'en_attente_anciennes' => $enAttenteAnciennes,
            'reprises_manuelles' => $reprises,
            'seuil_secondes' => self::SECONDES_AVANT_BLOCAGE,
        ];
    }

    /**
     * L'activite par Organization.
     *
     * `member_notification_deliveries` ne porte PAS d'`organization_id` : le
     * tenant s'obtient uniquement par jointure sur les notifications. C'est une
     * contrainte de schema, pas un detail — toute ventilation par tenant passe
     * par la.
     *
     * Les preferences ne sont volontairement PAS ventilees ici : elles
     * appartiennent a la personne, pas au tenant, et les y rattacher ferait de
     * la boite de reception d'un membre un objet de l'Organization.
     */
    private function activiteParOrganisation(): array
    {
        return DB::table('member_notifications')
            ->leftJoin('organizations', 'organizations.id', '=', 'member_notifications.organization_id')
            ->selectRaw('member_notifications.organization_id, organizations.name as nom, count(*) as total')
            ->selectRaw('sum(case when member_notifications.read_at is null then 1 else 0 end) as non_lues')
            ->groupBy('member_notifications.organization_id', 'organizations.name')
            ->orderByDesc('total')
            ->limit(25)
            ->get()
            ->map(fn ($l) => [
                // Une Organization supprimee laisse ses notifications : on le dit
                // plutot que de les faire disparaitre du total.
                'nom' => $l->nom ?? '(organisation supprimee)',
                'total' => (int) $l->total,
                'non_lues' => (int) $l->non_lues,
            ])
            ->all();
    }

    /**
     * La preuve historique, en AGREGAT seulement.
     *
     * Restreinte aux lignes du pipeline — `notification_id` renseigne. Les
     * autres lignes d'`email_logs` viennent d'appelants dont le contenu n'obeit
     * pas aux memes regles, et n'ont rien a faire dans un cockpit Notifications.
     *
     * ## TASK-1383 — le filtre reste, le silence s'arrete
     *
     * `email_logs` a DEUX producteurs, et ils n'ecrivent pas la meme chose :
     *
     * - le livreur du pipeline (`NotificationEmailDeliverer`), qui renseigne
     *   `notification_id`, expurge le corps archive et distingue trois issues ;
     * - un ecouteur de `NotificationSent` (`AppServiceProvider`), qui trace les
     *   `Notification` Laravel — bienvenue, message recu, changement de statut
     *   d'echange, budget IA depasse. Il n'ecrit jamais `notification_id` et
     *   force `status` a `sent`.
     *
     * Les fondre donnerait un total qui ne voudrait rien dire : deux disciplines
     * d'expurgation differentes, et un `sent` qui n'a pas ete constate de la
     * meme facon. Le filtre est donc GARDE.
     *
     * Ce qui change, c'est le silence autour. L'ecran affichait « Emails
     * traces » et renvoyait vers un historique detaille qui, lui, montre TOUT :
     * un exploitant comparant les deux totaux voyait un ecart inexplicable, et
     * pouvait en conclure que la supervision perd des envois. On compte donc
     * l'autre moitie A PART, et on la nomme.
     *
     * Un compte, rien d'autre. Aucune ligne hors pipeline n'est lue ni
     * affichee : leur contenu n'obeit pas aux regles de cet ecran, et le
     * compter ne donne pas le droit de le montrer.
     */
    private function preuves(): array
    {
        $base = fn () => EmailLog::query()->whereNotNull('notification_id');

        return [
            'total' => $base()->count(),
            'envoyees' => $base()->where('status', EmailLog::STATUS_SENT)->count(),
            'echouees' => $base()->where('status', EmailLog::STATUS_FAILED)->count(),
            'ambigues' => $base()->where('status', EmailLog::STATUS_AMBIGUOUS)->count(),
            // On ne lit que la PRESENCE du corps archive, jamais son contenu.
            'corps_archive' => $base()->whereNotNull('body_html')->count(),
            'hors_pipeline' => EmailLog::query()->whereNull('notification_id')->count(),
        ];
    }

    private function horodatage(mixed $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        // `jobs.available_at` est un entier UNIX ; les colonnes de date sont des
        // chaines. Les deux passent ici.
        return is_numeric($valeur)
            ? Carbon::createFromTimestamp((int) $valeur)->toDateTimeString()
            : (string) $valeur;
    }
}
