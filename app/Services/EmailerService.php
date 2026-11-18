<?php

namespace App\Services;

use App\Models\CrmContact;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailerService
{
    /**
     * TASK-1694 bug E — l'Organization et son slug, utilisables dans un gabarit.
     *
     * `organization` existait deja ; `organization_slug` manquait, et aucune
     * forme FRANCAISE n'etait acceptee alors que tous les gabarits sont ecrits
     * en francais par des gens qui ecrivent « organisation ».
     *
     * Les deux orthographes sont donc acceptees, et c'est delibere : renommer
     * `organization` en `organisation` casserait les gabarits existants qui
     * l'emploient — dont la definition du seeder. On AJOUTE, on ne deplace pas.
     */
    public const ALLOWED_VARS = [
        'first_name', 'name', 'full_name', 'email', 'city',
        'organization', 'organization_slug',
        'organisation', 'organisation_slug',
    ];

    /**
     * TASK-1694 bug Y — les variables qui sont DEUX NOMS POUR LA MEME VALEUR.
     *
     * ## Pourquoi cette carte existe
     *
     * Cyril, le 24/10, devant la documentation du bug W : « je ne comprends pas
     * la difference entre `organization` et `organisation` ». Il n'y en a
     * aucune — mesure faite, les deux rendent la meme chaine, de meme que
     * `organisation_slug`/`organization_slug` et `full_name`/`name`.
     *
     * L'ecran les presentait cote a cote comme s'il s'agissait de deux choses.
     * Une documentation qui laisse deviner une equivalence ne documente pas :
     * elle fait hesiter.
     *
     * ## Pourquoi les doublons existent, et pourquoi on les garde
     *
     * `organisation` et `organisation_slug` sont nes au bug E : tous les
     * gabarits sont ecrits en francais, par des gens qui ecrivent
     * « organisation ». Renommer `organization` aurait casse les gabarits qui
     * l'emploient — dont le catalogue du seeder. On AJOUTE, on ne deplace pas.
     *
     * ## Declaree ICI, a cote de la liste qu'elle commente
     *
     * Et PROUVEE : un test verifie que chaque alias rend bien la meme valeur
     * que son nom canonique. Une carte qui mentirait serait pire que pas de
     * carte — elle ferait ecrire des gabarits sur une equivalence fausse.
     *
     * @var array<string, string> alias => nom canonique
     */
    public const VAR_ALIASES = [
        'full_name' => 'name',
        'organisation' => 'organization',
        'organisation_slug' => 'organization_slug',
    ];

    public function availableVariables(User $user): array
    {
        return [
            'first_name' => $user->first_name ?? '',
            'name' => $user->fullName,
            'full_name' => $user->fullName,
            'email' => $user->email,
            'organization' => $user->organization?->name ?? '',
            'organization_slug' => $user->organization?->slug ?? '',
            // TASK-1694 bug E — meme valeur, orthographe francaise.
            'organisation' => $user->organization?->name ?? '',
            'organisation_slug' => $user->organization?->slug ?? '',
            'city' => $user->city ?? '',
        ];
    }

    /**
     * TASK-1421 — les memes variables, lues sur un Contact CRM (sans faux User).
     * `city` reste une chaine vide : le Contact n'en a pas, le contrat des
     * modeles historiques l'attend.
     */
    public function availableVariablesForContact(CrmContact $contact): array
    {
        return [
            'first_name' => $contact->first_name ?? '',
            'name' => $contact->last_name ?? '',
            'full_name' => $contact->fullName,
            'email' => $contact->email ?? '',
            'organization' => $contact->organization?->name ?? '',
            'organization_slug' => $contact->organization?->slug ?? '',
            'organisation' => $contact->organization?->name ?? '',
            'organisation_slug' => $contact->organization?->slug ?? '',
            'city' => '',
            'company' => $contact->company ?? '',
        ];
    }

    public function interpolate(string $content, array $variables, array $extraAllowedVars = []): string
    {
        $allowed = array_merge(self::ALLOWED_VARS, $extraAllowedVars);

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($variables, $allowed) {
            $key = $matches[1];
            if (in_array($key, $allowed, true) && isset($variables[$key])) {
                return e($variables[$key]);
            }

            return $matches[0];
        }, $content);
    }

    /**
     * TASK-1803 — meme substitution que `interpolate()`, mais SANS `e()`.
     *
     * `interpolate()` echappe la valeur pour un rendu HTML : correct pour un
     * `Mail::html()`, mais faux pour un `Mail::raw()` — un email texte verrait
     * les accents/apostrophes des variables sortir en entites HTML
     * (`&#039;`) au lieu du caractere lui-meme. L'outil d'envoi en masse est
     * le premier appelant a envoyer du texte brut ; les deux usages HTML
     * historiques (`sendFromTemplate`, `sendFromTemplateToContact`) restent
     * sur `interpolate()`.
     */
    public function interpolateRaw(string $content, array $variables, array $extraAllowedVars = []): string
    {
        $allowed = array_merge(self::ALLOWED_VARS, $extraAllowedVars);

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($variables, $allowed) {
            $key = $matches[1];
            if (in_array($key, $allowed, true) && isset($variables[$key])) {
                return (string) $variables[$key];
            }

            return $matches[0];
        }, $content);
    }

    public function interpolateSubject(string $subject, array $variables, array $extraAllowedVars = []): string
    {
        $allowed = array_merge(self::ALLOWED_VARS, $extraAllowedVars);

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($variables, $allowed) {
            $key = $matches[1];
            if (in_array($key, $allowed, true) && isset($variables[$key])) {
                return strip_tags($variables[$key]);
            }

            return $matches[0];
        }, $subject);
    }

    /**
     * TASK-1863 (TASK B2) — rend `null` quand le transport a ete SOLLICITE
     * (reussi ou leve) mais que la PREUVE elle-meme (`EmailLog::create()`)
     * n'a pas pu s'ecrire. Ne laisse JAMAIS une exception de persistance
     * remonter apres le transport : l'appelant doit pouvoir conclure sans
     * jamais risquer de rejouer un envoi peut-etre deja parti. Meme motif
     * que `BulkEmailSendService::sendOne()` (TASK-1861).
     */
    public function sendFromTemplate(EmailTemplate $template, User $user, ?User $sender = null): ?EmailLog
    {
        $variables = $this->availableVariables($user);
        $html = $this->interpolate($template->content_html, $variables);
        $subject = $this->interpolateSubject($template->subject, $variables);

        try {
            Mail::html($html, function ($message) use ($user, $subject, $sender) {
                $message->to($user->email, $user->fullName)
                    ->subject($subject);

                if ($sender) {
                    $message->replyTo($sender->email, $sender->fullName);
                }
            });

            $status = EmailLog::STATUS_SENT;
            $error = null;
        } catch (Throwable $e) {
            // TASK-1863 (TASK B2) — FRONTIERE H1 (meme discipline que
            // `NotificationEmailDeliverer::envoyer()`, TASK-1377, et
            // `BulkEmailSendService::sendOne()`, TASK-1861) : a partir du
            // moment ou `Mail::html()` a ete appele, SMTP a peut-etre deja
            // accepte le message. `failed` inviterait a rejouer ce
            // destinataire, donc a l'envoyer deux fois ; `ambiguous` dit
            // l'ignorance et n'est jamais repris automatiquement. Avant ce
            // correctif, cette ligne concluait `'failed'` litteral — seul
            // appelant de ce service a ne pas encore respecter cette
            // frontiere.
            $status = EmailLog::STATUS_AMBIGUOUS;
            $error = $e->getMessage();
        }

        // TASK-1863 (TASK B2) — a partir d'ICI, le transport a ete
        // SOLLICITE : cette ecriture ne doit jamais laisser une exception
        // remonter. Point de sauvegarde (`DB::transaction()`), meme motif
        // que `NotificationEmailDeliverer::tracer()` : sur PostgreSQL, une
        // requete qui echoue abandonne la transaction courante (25P02),
        // et toute requete suivante echoue tant qu'un ROLLBACK n'a pas eu
        // lieu — un simple `catch` PHP n'en declenche aucun. Lecon
        // directe de TASK-1861 (meme defaut trouve par la CI PostgreSQL
        // sur `BulkEmailSendService::sendOne()`), appliquee ici d'emblee.
        try {
            return DB::transaction(fn () => EmailLog::create([
                'template_id' => $template->id,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'to_email' => $user->email,
                'subject' => $subject,
                'status' => $status,
                'error_message' => $error,
                'data' => [
                    'source' => 'emailer',
                    'sender_id' => $sender?->id,
                    'template_slug' => $template->slug,
                ],
            ]));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * TASK-1421 — envoi a un Contact CRM : meme transport, meme journal, mais
     * le destinataire est l'adresse du Contact et la preuve porte
     * `crm_contact_id` (et `user_id` seulement si le Contact est relie a un
     * compte). Le corps envoye est conserve (`body_html` + `body_hash`,
     * colonnes T1383) pour rester relisible depuis la fiche.
     */
    /**
     * TASK-1431 (MASTER Q53) : `$sender` est l'ACTEUR (audit : sender_id, timeline) ;
     * `$replyTo` est l'adresse a laquelle le prospect repond — par defaut l'acteur,
     * mais jamais un admin plateforme au nom d'un tenant dont il n'est pas membre.
     */
    public function sendFromTemplateToContact(EmailTemplate $template, CrmContact $contact, ?User $sender = null, ?User $replyTo = null): EmailLog
    {
        $replyTo ??= $sender;
        $variables = $this->availableVariablesForContact($contact);
        $extra = ['company'];
        $html = $this->interpolate($template->content_html, $variables, $extra);
        $subject = $this->interpolateSubject($template->subject, $variables, $extra);
        $to = CrmContact::normalizeEmail($contact->email);
        $name = $contact->fullName !== '' ? $contact->fullName : $to;

        try {
            Mail::html($html, function ($message) use ($to, $name, $subject, $replyTo) {
                $message->to($to, $name)
                    ->subject($subject);

                if ($replyTo) {
                    $message->replyTo($replyTo->email, $replyTo->fullName);
                }
            });

            $status = EmailLog::STATUS_SENT;
            $error = null;
        } catch (Throwable $e) {
            $status = EmailLog::STATUS_FAILED;
            $error = $e->getMessage();
        }

        return EmailLog::create([
            'template_id' => $template->id,
            'user_id' => $contact->user_id,
            'crm_contact_id' => $contact->id,
            'organization_id' => $contact->organization_id,
            'to_email' => $to,
            'subject' => $subject,
            'status' => $status,
            'error_message' => $error,
            'body_html' => $html,
            'body_hash' => hash('sha256', $html),
            'data' => [
                'source' => 'crm',
                'sender_id' => $sender?->id,
                'reply_to_id' => $replyTo?->id,
                'template_slug' => $template->slug,
            ],
        ]);
    }
}
