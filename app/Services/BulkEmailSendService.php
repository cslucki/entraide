<?php

namespace App\Services;

use App\Http\Controllers\NewsletterSubscriptionController;
use App\Jobs\SendBulkEmailToRecipient;
use App\Models\BulkEmailSend;
use App\Models\BulkEmailSendRecipient;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\Email\HelpDigestBlocks;
use App\Support\Email\SystemEmailTemplateResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * TASK-1803 — l'outil « Envoi emails » du superadmin.
 *
 * ## TASK-1861 — reservation atomique, envoi deporte vers la Managed Queue
 *
 * `sendNextBatch()` ne telephone plus le SMTP lui-meme : il RESERVE, par
 * lot plafonne a 50 (inchange), les destinataires encore `pending`, puis
 * dispatche UN job par destinataire reserve (`SendBulkEmailToRecipient`,
 * meme famille que `SendLoopInvitationEmail` TASK-1860). La reservation
 * (`claimRecipient()`) reprend A L'IDENTIQUE l'idiome de
 * `NotificationEmailDeliverer::prendre()` (TASK-1377) : une UNIQUE requete
 * UPDATE conditionnee sur l'etat attendu, le nombre de lignes affectees
 * tranchant qui possede la ligne — aucune fenetre de double envoi, meme
 * sous deux declenchements concurrents sur le meme lot.
 *
 * La cible (Organization x filtre membres) reste resolue UNE FOIS a la
 * creation et figee en lignes `bulk_email_send_recipients` : voir le
 * docblock de la migration pour pourquoi (re-resoudre le meme filtre a
 * chaque lot decalerait silencieusement "le lot suivant" si un membre
 * s'inscrit, change d'Organization ou remplit sa bio entre deux clics).
 */
class BulkEmailSendService
{
    /** TASK-1883 — gabarit systeme global de la newsletter. */
    public const NEWSLETTER_SLUG = 'weekly_help_digest';

    /** Blocs memorises le temps d'une instance (un job). */
    private ?HelpDigestBlocks $blocs = null;

    public function __construct(private readonly EmailerService $emailer) {}

    /**
     * Les membres cibles par (Organization, filtre de presentation).
     *
     * `$organizationId === null` veut dire « toutes les Organizations » —
     * jamais confondu avec une Organization qui n'existerait pas : c'est
     * l'appelant (le contrôleur) qui valide l'UUID avant d'arriver ici.
     */
    public function resolveRecipients(?string $organizationId, string $memberFilter): Builder
    {
        return User::query()
            ->activeAccount()
            // « Membres des Organizations » : un compte orphelin (SuperAdmin
            // plateforme compris, `organization_id` NULL) n'est membre d'AUCUNE
            // Organization et ne doit jamais recevoir un envoi "toutes les
            // Organizations" — l'exclure ici evite qu'il se glisse par defaut.
            ->whereNotNull('organization_id')
            ->when($organizationId !== null, fn (Builder $q) => $q->where('organization_id', $organizationId))
            ->when(
                $memberFilter === BulkEmailSend::MEMBER_FILTER_NO_PRESENTATION,
                // TASK-1803 — « presentation » = bio remplie, definition canonique
                // de `App\Support\Onboarding\MemberOnboardingSteps::doneFor()`.
                // Aucun drapeau persiste n'existe pour cet etat ; on interroge la
                // MEME colonne, jamais une copie.
                fn (Builder $q) => $q->where(fn (Builder $qq) => $qq->whereNull('bio')->orWhere('bio', '')),
            )
            ->orderBy('id');
    }

    public function countRecipients(?string $organizationId, string $memberFilter): int
    {
        return $this->resolveRecipients($organizationId, $memberFilter)->count();
    }

    /**
     * @param  array<string, mixed>  $data  champs deja valides par le contrôleur
     */
    public function createSend(array $data, User $admin): BulkEmailSend
    {
        return DB::transaction(function () use ($data, $admin) {
            $send = BulkEmailSend::create([
                'organization_id' => $data['organization_id'],
                'member_filter' => $data['member_filter'],
                'content_source' => $data['content_source'],
                'email_template_id' => $data['email_template_id'] ?? null,
                'subject' => $data['subject'] ?? null,
                'content_format' => $data['content_format'] ?? null,
                'content_raw' => $data['content_raw'] ?? null,
                'status' => BulkEmailSend::STATUS_DRAFT,
                'total_recipients' => 0,
                'sent_count' => 0,
                'failed_count' => 0,
                'created_by' => $admin->id,
            ]);

            $userIds = $this->resolveRecipients($data['organization_id'], $data['member_filter'])
                ->pluck('id');

            if ($userIds->isEmpty()) {
                // TASK-1803 — sans ce `total_recipients` explicite ci-dessus,
                // l'instance en memoire ne porterait PAS le defaut `0` de la
                // colonne (Eloquent ne le lit qu'en rechargeant la ligne) :
                // `$send->total_recipients` vaudrait NULL, et la comparaison
                // stricte `=== 0` du contrôleur ne verrait jamais ce cas.
                return $send;
            }

            $now = now();
            $rows = $userIds->map(fn (string $userId) => [
                'id' => (string) Str::orderedUuid(),
                'bulk_email_send_id' => $send->id,
                'user_id' => $userId,
                'status' => BulkEmailSendRecipient::STATUS_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            // `insert()` en lot, jamais `create()` ligne a ligne : un envoi a
            // plusieurs centaines de destinataires ne doit pas couter autant de
            // requetes que de lignes.
            foreach (array_chunk($rows, 500) as $chunk) {
                BulkEmailSendRecipient::query()->insert($chunk);
            }

            $send->update([
                'total_recipients' => $userIds->count(),
                'status' => BulkEmailSend::STATUS_SENDING,
            ]);

            return $send->fresh();
        });
    }

    /**
     * Reserve jusqu'a `$batchSize` destinataires encore `pending` et
     * dispatche un job par destinataire effectivement reserve.
     *
     * Ne dit JAMAIS combien ont ete envoyes : l'envoi est deporte, donc
     * inconnu au moment ou cette methode rend la main. `queued` est le seul
     * compte fiable a cet instant precis.
     *
     * @return array{queued: int, remaining: int}
     */
    public function sendNextBatch(BulkEmailSend $send, int $batchSize = 50): array
    {
        $candidateIds = $send->recipients()
            ->where('status', BulkEmailSendRecipient::STATUS_PENDING)
            ->orderBy('created_at')
            ->limit($batchSize)
            ->pluck('id');

        $queued = 0;

        foreach ($candidateIds as $recipientId) {
            if (! $this->claimRecipient($recipientId)) {
                // Un autre declenchement concurrent a deja reserve cette
                // ligne precise entre notre SELECT et cette tentative : on
                // ne la retente pas, exactement ce que cette reservation
                // existe pour empecher.
                continue;
            }

            // `rescue()` rend `null` dans les DEUX branches ici (le
            // dispatch comme la fermeture de rattrapage ne rendent rien) :
            // son resultat ne peut donc pas distinguer succes et echec.
            // Un drapeau capture par reference le peut.
            $dispatched = true;

            rescue(
                fn () => Bus::dispatch(new SendBulkEmailToRecipient((string) $recipientId)),
                function (Throwable $e) use ($recipientId, &$dispatched): void {
                    $dispatched = false;

                    // La mise en file a echoue AVANT tout appel SMTP : on
                    // SAIT avec certitude que rien n'a ete tente, donc il
                    // est sur de revenir a `pending` pour un prochain lot —
                    // contrairement a une panne APRES le transport, ceci
                    // n'est jamais ambigu.
                    BulkEmailSendRecipient::whereKey($recipientId)
                        ->where('status', BulkEmailSendRecipient::STATUS_QUEUED)
                        ->update(['status' => BulkEmailSendRecipient::STATUS_PENDING]);

                    Log::error('Email notification failed after successful business action', [
                        'event' => 'bulk_email_recipient_dispatch_failed',
                        'bulk_email_send_recipient_id' => $recipientId,
                        'exception_class' => $e::class,
                    ]);
                },
                false,
            );

            if ($dispatched) {
                $queued++;
            }
        }

        $remaining = $send->recipients()->where('status', BulkEmailSendRecipient::STATUS_PENDING)->count();

        return ['queued' => $queued, 'remaining' => $remaining];
    }

    /**
     * La transition `pending -> queued`, en UNE requete — meme idiome que
     * `NotificationEmailDeliverer::prendre()` (TASK-1377). L'etat attendu
     * est DANS la clause `WHERE` : c'est ce qui rend la reservation
     * atomique. Le relire d'abord en PHP rouvrirait la fenetre.
     */
    public function claimRecipient(string $recipientId): bool
    {
        return BulkEmailSendRecipient::whereKey($recipientId)
            ->where('status', BulkEmailSendRecipient::STATUS_PENDING)
            ->update(['status' => BulkEmailSendRecipient::STATUS_QUEUED]) === 1;
    }

    /**
     * TASK-1861 (revue MASTER, correctif avant fusion) — la transition
     * `queued -> processing`, EN UNE requete, meme idiome que
     * `claimRecipient()`. Une simple lecture de `$recipient->status` ne
     * suffit PAS : deux workers qui liraient tous deux `queued` avant que
     * l'un d'eux n'ecrive franchiraient tous deux la garde et enverraient
     * deux fois. Seul le nombre de lignes affectees par CETTE requete
     * tranche qui possede desormais la ligne.
     */
    public function claimForProcessing(string $recipientId): bool
    {
        return BulkEmailSendRecipient::whereKey($recipientId)
            ->where('status', BulkEmailSendRecipient::STATUS_QUEUED)
            ->update(['status' => BulkEmailSendRecipient::STATUS_PROCESSING]) === 1;
    }

    /**
     * Le travail d'UN destinataire DEJA reserve — appele par
     * `SendBulkEmailToRecipient::handle()`. Tout est RELU au moment du
     * travail : le destinataire, l'envoi parent et l'utilisateur sont une
     * photo d'un etat passe au moment du dispatch.
     */
    public function processRecipient(string $recipientId): void
    {
        $recipient = BulkEmailSendRecipient::find($recipientId);

        if ($recipient === null) {
            Log::warning('BulkEmailSendService: destinataire introuvable au moment du traitement.', [
                'bulk_email_send_recipient_id' => $recipientId,
            ]);

            return;
        }

        // TASK-1861 (revue MASTER) — prise de travail ATOMIQUE. Remplace la
        // simple lecture `$recipient->status !== QUEUED` : celle-ci laissait
        // une fenetre ou deux executions du MEME job (redelivraison
        // "at-least-once", deux workers) pouvaient toutes deux la franchir
        // avant qu'aucune n'ecrive, et donc envoyer deux fois.
        if (! $this->claimForProcessing($recipientId)) {
            Log::warning('BulkEmailSendService: destinataire non reserve au moment du traitement, ignore.', [
                'bulk_email_send_recipient_id' => $recipientId,
                'status' => $recipient->status,
            ]);

            return;
        }

        $send = BulkEmailSend::find($recipient->bulk_email_send_id);

        if ($send === null) {
            Log::warning('BulkEmailSendService: envoi introuvable au moment du traitement.', [
                'bulk_email_send_recipient_id' => $recipientId,
            ]);

            return;
        }

        // TASK-1883 — une newsletter peut viser plusieurs organisations : le
        // tenant est celui du DESTINATAIRE, jamais celui de l'envoi.
        $contexte = $send->estNewsletter()
            ? User::query()->whereKey($recipient->user_id)->value('organization_id')
            : $send->organization_id;

        $this->withOrganizationContext($contexte, function () use ($recipient, $send): void {
            $user = User::find($recipient->user_id);

            if ($user === null) {
                // Le membre a ete supprime pendant l'envoi : la ligne reste
                // comme trace, mais il n'y a plus personne a qui ecrire.
                // Pre-transport, donc un `failed` propre — jamais ambigu.
                $recipient->update(['status' => BulkEmailSendRecipient::STATUS_FAILED]);
                $send->increment('failed_count');
                $this->maybeComplete($send);

                return;
            }

            // TASK-1883 — desabonne entre-temps, ou plus rien a montrer pour son
            // organisation : rien n'est envoye, la ligne est marquee `skipped`.
            if ($send->estNewsletter() && $this->newsletterPour($user, $send) === null) {
                $recipient->update(['status' => BulkEmailSendRecipient::STATUS_SKIPPED]);
                $this->maybeComplete($send);

                return;
            }

            $log = $this->sendOne($user, $send, $send->creator);

            if ($log === null) {
                // TASK-1861 (revue MASTER) — le transport a ete SOLLICITE
                // (reussi ou leve, `sendOne()` l'a deja absorbe), mais la
                // PREUVE elle-meme (`EmailLog::create()`) n'a pas pu
                // s'ecrire. FRONTIERE H1 : a partir de la sollicitation du
                // transport, plus rien ne peut conclure `failed`. On tente
                // `ambiguous` ; si MEME cette ecriture echoue, la ligne
                // reste `processing` — observable, jamais silencieusement
                // perdue, et jamais rejouee automatiquement.
                try {
                    DB::transaction(fn () => $recipient->update(['status' => BulkEmailSendRecipient::STATUS_AMBIGUOUS]));
                } catch (Throwable $e) {
                    report($e);
                    Log::error('BulkEmailSendService: aucune preuve ecrite apres une tentative SMTP, destinataire reste `processing` — reconciliation manuelle necessaire.', [
                        'bulk_email_send_recipient_id' => $recipient->id,
                    ]);

                    return;
                }

                Log::warning('BulkEmailSendService: journal introuvable apres une tentative de transport (echec de persistance), destinataire marque ambigu — reconciliation manuelle necessaire.', [
                    'bulk_email_send_recipient_id' => $recipient->id,
                ]);

                $this->maybeComplete($send);

                return;
            }

            try {
                DB::transaction(fn () => $recipient->update([
                    'status' => match ($log->status) {
                        EmailLog::STATUS_SENT => BulkEmailSendRecipient::STATUS_SENT,
                        EmailLog::STATUS_AMBIGUOUS => BulkEmailSendRecipient::STATUS_AMBIGUOUS,
                        default => BulkEmailSendRecipient::STATUS_FAILED,
                    },
                    'email_log_id' => $log->id,
                ]));
            } catch (Throwable $e) {
                report($e);
                // Le journal EXISTE (voir `email_log_id` ci-dessous) : la
                // reconciliation manuelle part de la, meme si cette
                // ecriture-ci a echoue.
                Log::error('BulkEmailSendService: le destinataire n a pas pu etre mis a jour apres une tentative SMTP, reste `processing` — reconciliation manuelle necessaire.', [
                    'bulk_email_send_recipient_id' => $recipient->id,
                    'email_log_id' => $log->id,
                ]);

                return;
            }

            match ($log->status) {
                EmailLog::STATUS_SENT => $send->increment('sent_count'),
                // `ambiguous` n'est NI confirme envoye NI confirme echoue —
                // ne grossit aucun des deux compteurs, voir le docblock de
                // `BulkEmailSendRecipient`.
                EmailLog::STATUS_AMBIGUOUS => null,
                default => $send->increment('failed_count'),
            };

            $this->maybeComplete($send);
        });
    }

    /**
     * L'envoi passe `completed` quand plus AUCUNE ligne n'est `pending`,
     * `queued` NI `processing` — jamais seulement quand `pending` atteint
     * zero : une ligne `queued`/`processing` est un job en vol ou en cours
     * d'execution, pas un travail termine. Idempotent : deux jobs qui
     * concluent au meme instant ne font que poser la MEME valeur deux fois,
     * sans effet de bord supplementaire.
     */
    private function maybeComplete(BulkEmailSend $send): void
    {
        $remaining = $send->recipients()
            ->whereIn('status', [
                BulkEmailSendRecipient::STATUS_PENDING,
                BulkEmailSendRecipient::STATUS_QUEUED,
                BulkEmailSendRecipient::STATUS_PROCESSING,
            ])
            ->count();

        if ($remaining === 0) {
            $send->update(['status' => BulkEmailSend::STATUS_COMPLETED]);
        }
    }

    /**
     * Idiome repris du reste de la famille (TASK-1854/1855/1857/1858/1860),
     * adapte a la seule particularite de ce modele : `organization_id` NULL
     * est une valeur a part entiere (« toutes les Organizations »), pas une
     * absence a combler.
     *
     * TASK-1861 (revue MASTER, correctif avant fusion) — NULL lie
     * desormais EXPLICITEMENT « aucun tenant », au lieu de ne rien faire.
     * Un worker de Managed Queue persiste entre deux jobs : ne RIEN faire
     * pour un envoi « toutes les Organizations » aurait laisse en place le
     * tenant eventuellement encore lie par le JOB PRECEDENT traite par le
     * MEME worker — une fuite de contexte entre jobs, pas une absence de
     * contexte. `forgetInstance()` ferme cette fenetre.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withOrganizationContext(?string $organizationId, callable $callback): mixed
    {
        $hadPrevious = app()->bound('current_organization');
        $previous = $hadPrevious ? app('current_organization') : null;

        $organization = $organizationId !== null
            ? Organization::query()->whereKey($organizationId)->first()
            : null;

        if ($organization) {
            app()->instance('current_organization', $organization);
        } else {
            // `organization_id` NULL, OU l'Organization visee a disparu :
            // aucun tenant n'est lie pour CE destinataire — jamais celui,
            // eventuellement perime, d'un job precedent traite par le MEME
            // worker persistant.
            app()->forgetInstance('current_organization');
        }

        try {
            return $callback();
        } finally {
            if ($hadPrevious) {
                app()->instance('current_organization', $previous);
            } else {
                app()->forgetInstance('current_organization');
            }
        }
    }

    /**
     * Un apercu de ce qu'UN destinataire donne recevrait reellement — meme
     * rendu que `sendOne()`, jamais re-derive : l'ecran de confirmation
     * affichait `{{ first_name }}` au mot pres (TASK-1803 correctif), ce
     * qu'aucun test Feature (qui ne regarde jamais le HTML du recapitulatif
     * au pixel pres) ne pouvait voir. Les variables sont REELLES pour ce
     * destinataire precis ; chaque autre destinataire recevra les siennes.
     *
     * @param  array<string, mixed>  $data  champs deja valides par le contrôleur
     * @return array{subject: string, body: string, isHtml: bool}
     */
    public function previewFor(array $data, ?EmailTemplate $template, User $sampleUser): array
    {
        return $this->renderContent(
            $data['content_source'],
            $template,
            $data['subject'] ?? null,
            $data['content_format'] ?? null,
            $data['content_raw'] ?? null,
            $this->emailer->availableVariables($sampleUser),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, body: string, isHtml: bool}
     */
    private function renderContent(string $contentSource, ?EmailTemplate $template, ?string $subjectRaw, ?string $contentFormat, ?string $contentRaw, array $variables): array
    {
        if ($contentSource === BulkEmailSend::CONTENT_SOURCE_TEMPLATE) {
            $subject = $this->emailer->interpolateSubject($template->subject, $variables);
            $body = $this->emailer->interpolate($template->content_html, $variables);
            $isHtml = true;
        } elseif ($contentFormat === BulkEmailSend::CONTENT_FORMAT_HTML) {
            $subject = $this->emailer->interpolateSubject((string) $subjectRaw, $variables);
            // TASK-1803 — meme conversion que l'affichage de la bio
            // (`resources/views/profile/show.blade.php`), jamais une nouvelle :
            // le composant TipTap reutilise sans modification serialise en
            // Markdown, converti en HTML seulement au moment d'envoyer.
            $body = markdown(
                $this->emailer->interpolateRaw((string) $contentRaw, $variables),
                ['renderer' => ['soft_break' => "<br />\n"]],
            );
            $isHtml = true;
        } else {
            $subject = $this->emailer->interpolateSubject((string) $subjectRaw, $variables);
            $body = $this->emailer->interpolateRaw((string) $contentRaw, $variables);
            $isHtml = false;
        }

        return ['subject' => $subject, 'body' => $body, 'isHtml' => $isHtml];
    }

    /**
     * TASK-1861 (revue MASTER, correctif avant fusion) — rend `null` quand
     * le transport a ete SOLLICITE (reussi ou leve) mais que la PREUVE
     * elle-meme (`EmailLog::create()`) n'a pas pu s'ecrire. Ne laisse
     * JAMAIS une exception de persistance remonter apres le transport :
     * l'appelant (`processRecipient()`) doit pouvoir conclure sans jamais
     * risquer de rejouer un envoi peut-etre deja parti.
     */
    /**
     * TASK-1883 — cree un envoi de newsletter : une ou plusieurs organisations
     * (`$organizationIds` vide = toutes), comptes actifs abonnes, et seulement
     * dans les organisations qui ont quelque chose a montrer.
     *
     * @param  array{organization_ids: list<string>, subject: ?string, demand_count: int, offer_count: int, blog_count?: int, event_count?: int}  $data
     */
    public function createNewsletterSend(array $data, User $admin): BulkEmailSend
    {
        return DB::transaction(function () use ($data, $admin) {
            $ids = array_values(array_unique($data['organization_ids'] ?? []));

            $send = BulkEmailSend::create([
                'organization_id' => count($ids) === 1 ? $ids[0] : null,
                'member_filter' => BulkEmailSend::MEMBER_FILTER_ALL,
                'content_source' => BulkEmailSend::CONTENT_SOURCE_NEWSLETTER,
                'system_template_slug' => self::NEWSLETTER_SLUG,
                'subject' => filled($data['subject'] ?? null) ? $data['subject'] : null,
                'demand_count' => $data['demand_count'],
                'offer_count' => $data['offer_count'],
                'blog_count' => (int) ($data['blog_count'] ?? 0),
                'event_count' => (int) ($data['event_count'] ?? 0),
                'status' => BulkEmailSend::STATUS_DRAFT,
                'total_recipients' => 0,
                'sent_count' => 0,
                'failed_count' => 0,
                'created_by' => $admin->id,
            ]);

            $send->organizations()->sync($ids);

            $userIds = $this->newsletterRecipients($ids, (int) $data['demand_count'], (int) $data['offer_count'], (int) ($data['blog_count'] ?? 0), (int) ($data['event_count'] ?? 0))->pluck('id');

            $now = now();
            foreach ($userIds->chunk(500) as $lot) {
                BulkEmailSendRecipient::query()->insert($lot->map(fn (string $userId) => [
                    'id' => (string) Str::orderedUuid(),
                    'bulk_email_send_id' => $send->id,
                    'user_id' => $userId,
                    'status' => BulkEmailSendRecipient::STATUS_PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            $send->update([
                'total_recipients' => $userIds->count(),
                'status' => $userIds->isEmpty() ? BulkEmailSend::STATUS_COMPLETED : BulkEmailSend::STATUS_SENDING,
            ]);

            return $send->fresh();
        });
    }

    /**
     * Destinataires d'une newsletter : comptes actifs, abonnes, des
     * organisations visees qui ont au moins une demande ou proposition.
     *
     * @param  list<string>  $organizationIds  vide = toutes
     */
    public function newsletterRecipients(array $organizationIds, int $demandCount, int $offerCount, int $blogCount = 0, int $eventCount = 0): Builder
    {
        $blocs = new HelpDigestBlocks;
        $avecContenu = Organization::query()
            ->when($organizationIds !== [], fn ($q) => $q->whereIn('id', $organizationIds))
            ->get()
            ->filter(fn (Organization $org) => $blocs->aDuContenu($org, $demandCount, $offerCount, $blogCount, $eventCount))
            ->pluck('id')
            ->all();

        return User::query()
            ->activeAccount()
            ->whereNull('newsletter_unsubscribed_at')
            // TASK-1881 — un membre bloque a la connexion ne pourrait ouvrir aucun lien.
            ->whereNull('login_blocked_at')
            ->whereIn('organization_id', $avecContenu)
            ->orderBy('id');
    }

    /**
     * Le courriel de newsletter de CE destinataire, ou null s'il ne faut rien
     * envoyer (desabonne, organisation sans contenu, gabarit absent).
     * `$apercu` : rendu meme sans contenu, lien de desinscription inerte.
     *
     * Gabarit GLOBAL seulement : une organisation ne surcharge pas le texte
     * envoye a toutes. Langue du membre ; blocs developpes AVANT
     * l'interpolation ; habillage `emails.system-email`, comme l'apercu.
     *
     * @return array{subject: string, body: string, unsubscribe_url: string}|null
     */
    public function newsletterPour(User $user, BulkEmailSend $send, bool $apercu = false): ?array
    {
        $org = $user->organization;

        if ($org === null || ($user->newsletter_unsubscribed_at !== null && ! $apercu)) {
            return null;
        }

        $demandes = (int) ($send->demand_count ?? 3);
        $propositions = (int) ($send->offer_count ?? 3);
        $billets = (int) ($send->blog_count ?? 0);
        $evenements = (int) ($send->event_count ?? 0);
        $this->blocs ??= new HelpDigestBlocks;

        if (! $apercu && ! $this->blocs->aDuContenu($org, $demandes, $propositions, $billets, $evenements)) {
            return null;
        }

        $langue = in_array($user->preferred_locale, ['fr', 'en'], true) ? $user->preferred_locale : 'fr';
        $gabarit = app(SystemEmailTemplateResolver::class)->resolve($send->system_template_slug ?: self::NEWSLETTER_SLUG, null, $langue);

        if ($gabarit === null) {
            Log::warning('[TASK-1883] gabarit global de newsletter introuvable : rien envoye.', ['locale' => $langue]);

            return null;
        }

        // TASK-1891 (Cyril, 23/11) — l'apercu montre le MEME lien que l'envoi :
        // scope a l'organisation, signe. Le membre de l'apercu est fictif : son
        // lien a la bonne forme, mais ne mene a aucun compte (404).
        $desinscription = NewsletterSubscriptionController::urlDesinscription($user);
        $vars = $this->emailer->availableVariables($user) + [
            'recipient_name' => $user->publicDisplayName(),
            'organization_name' => (string) $org->name,
            'explorer_url' => $org->is_default ? route('explorer') : route('organization.explorer', ['organization' => $org->slug]),
            'unsubscribe_url' => $desinscription,
            'app_name' => (string) config('app.name'),
            // TASK-1891 (Cyril, 23/11) — « notre newsletter du [date du jour] ».
            'newsletter_date' => now('Europe/Paris')->locale($langue)->isoFormat($langue === 'en' ? 'MMMM D, YYYY' : 'D MMMM YYYY'),
        ];
        $cles = ['recipient_name', 'organization_name', 'explorer_url', 'unsubscribe_url', 'app_name', 'newsletter_date'];

        $html = $this->blocs->developper((string) $gabarit->content_html, $org, $langue, $demandes, $propositions, $billets, $evenements);
        $html = $this->emailer->interpolate($html, $vars, $cles);

        return [
            'subject' => $this->emailer->interpolateSubject(filled($send->subject) ? $send->subject : (string) $gabarit->subject, $vars, $cles),
            // TASK-1891 — en-tete et pied au nom de l'organisation du membre.
            'body' => (string) app(Markdown::class)->render('emails.system-email', [
                'html' => $html,
                'marque' => (string) $org->name,
                'marqueUrl' => $vars['explorer_url'],
            ]),
            'unsubscribe_url' => $desinscription,
        ];
    }

    private function sendOne(User $user, BulkEmailSend $send, ?User $sender): ?EmailLog
    {
        $newsletter = $send->estNewsletter() ? $this->newsletterPour($user, $send) : null;

        if ($newsletter !== null) {
            ['subject' => $subject, 'body' => $body] = $newsletter;
            $isHtml = true;
        } else {
            $variables = $this->emailer->availableVariables($user);

            ['subject' => $subject, 'body' => $body, 'isHtml' => $isHtml] = $this->renderContent(
                $send->content_source,
                $send->content_source === BulkEmailSend::CONTENT_SOURCE_TEMPLATE ? $send->template : null,
                $send->subject,
                $send->content_format,
                $send->content_raw,
                $variables,
            );
        }

        try {
            if ($isHtml) {
                Mail::html($body, function ($message) use ($user, $subject, $sender, $newsletter) {
                    $message->to($user->email, $user->fullName)->subject($subject);
                    if ($sender) {
                        $message->replyTo($sender->email, $sender->fullName);
                    }
                    if ($newsletter !== null) {
                        $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$newsletter['unsubscribe_url'].'>');
                    }
                });
            } else {
                Mail::raw($body, function ($message) use ($user, $subject, $sender) {
                    $message->to($user->email, $user->fullName)->subject($subject);
                    if ($sender) {
                        $message->replyTo($sender->email, $sender->fullName);
                    }
                });
            }

            $status = EmailLog::STATUS_SENT;
            $error = null;
        } catch (Throwable $e) {
            // TASK-1861 — FRONTIERE H1 (meme discipline que
            // `NotificationEmailDeliverer::envoyer()`) : a partir du moment
            // ou `Mail::html()/raw()` a ete appele, SMTP a peut-etre deja
            // accepte le message. `failed` inviterait a rejouer ce
            // destinataire, donc a l'envoyer deux fois ; `ambiguous` dit
            // l'ignorance et n'est jamais repris automatiquement.
            $status = EmailLog::STATUS_AMBIGUOUS;
            $error = $e->getMessage();
        }

        // TASK-1861 (revue MASTER) — a partir d'ICI, le transport a ete
        // SOLLICITE (reussi ou leve ci-dessus) : cette ecriture ne doit
        // JAMAIS laisser une exception remonter. Une panne de persistance
        // APRES un transport reussi ne doit surtout pas ressembler a un
        // echec PRE-transport pour l'appelant — `null` le dit sans ambiguite.
        //
        // Le bloc vit dans un POINT DE SAUVEGARDE (`DB::transaction()`),
        // meme raison que `NotificationEmailDeliverer::tracer()` : sur
        // PostgreSQL, une requete qui echoue ABANDONNE la transaction
        // courante (25P02), et toute requete suivante echoue tant qu'un
        // ROLLBACK n'a pas eu lieu — un simple `catch` PHP n'en fait pas
        // un. Capture par la CI PostgreSQL elle-meme (PR #869, shard 3/6) :
        // sans ce point de sauvegarde, jusqu'au `Schema::rename()` de
        // restauration du test de sabotage echouait a son tour.
        try {
            return DB::transaction(fn () => EmailLog::create([
                'template_id' => $send->content_source === BulkEmailSend::CONTENT_SOURCE_TEMPLATE
                    ? $send->email_template_id
                    : null,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'to_email' => $user->email,
                'subject' => $subject,
                'status' => $status,
                'error_message' => $error,
                'body_html' => $body,
                'body_hash' => hash('sha256', $body),
                'data' => $newsletter !== null ? [
                    'source' => 'newsletter',
                    'sender_id' => $sender?->id,
                    'bulk_email_send_id' => $send->id,
                    'template_slug' => $send->system_template_slug,
                    'demand_count' => $send->demand_count,
                    'offer_count' => $send->offer_count,
                    'blog_count' => $send->blog_count,
                    'event_count' => $send->event_count,
                ] : [
                    'source' => 'bulk-email',
                    'sender_id' => $sender?->id,
                    'bulk_email_send_id' => $send->id,
                    'content_format' => $send->content_source === BulkEmailSend::CONTENT_SOURCE_TEMPLATE
                        ? 'template'
                        : $send->content_format,
                    'template_slug' => $send->content_source === BulkEmailSend::CONTENT_SOURCE_TEMPLATE
                        ? $send->template?->slug
                        : null,
                ],
            ]));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
