<?php

namespace App\Services;

use App\Models\BulkEmailSend;
use App\Models\BulkEmailSendRecipient;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * TASK-1803 — l'outil « Envoi emails » du superadmin.
 *
 * Pas de vraie file d'attente dans cette application (`QUEUE_CONNECTION=sync`,
 * voir `.env`) : un envoi se fait donc en LOTS synchrones, l'admin cliquant
 * "lot suivant" entre chacun — meme principe de securite que
 * `AdminEmailTemplatesController::sendExecute`, plafonne a 50 destinataires
 * par requete. La cible (Organization x filtre membres) est resolue UNE
 * FOIS a la creation et figee en lignes `bulk_email_send_recipients` : voir
 * le docblock de la migration pour pourquoi (re-resoudre le meme filtre a
 * chaque lot decalerait silencieusement "le lot suivant" si un membre
 * s'inscrit, change d'Organization ou remplit sa bio entre deux clics).
 */
class BulkEmailSendService
{
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
     * @return array{sent: int, failed: int, remaining: int}
     */
    public function sendNextBatch(BulkEmailSend $send, int $batchSize = 50): array
    {
        $recipients = $send->recipients()
            ->where('status', BulkEmailSendRecipient::STATUS_PENDING)
            ->with('user')
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            if ($recipient->user === null) {
                // Le membre a ete supprime pendant l'envoi : la ligne reste comme
                // trace, mais il n'y a plus personne a qui ecrire.
                $recipient->update(['status' => BulkEmailSendRecipient::STATUS_FAILED]);
                $failed++;

                continue;
            }

            $log = $this->sendOne($recipient->user, $send, $send->creator);

            $recipient->update([
                'status' => $log->status === EmailLog::STATUS_SENT
                    ? BulkEmailSendRecipient::STATUS_SENT
                    : BulkEmailSendRecipient::STATUS_FAILED,
                'email_log_id' => $log->id,
            ]);

            if ($log->status === EmailLog::STATUS_SENT) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $send->increment('sent_count', $sent);
        $send->increment('failed_count', $failed);

        $remaining = $send->recipients()->where('status', BulkEmailSendRecipient::STATUS_PENDING)->count();

        if ($remaining === 0) {
            $send->update(['status' => BulkEmailSend::STATUS_COMPLETED]);
        }

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining];
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

    private function sendOne(User $user, BulkEmailSend $send, ?User $sender): EmailLog
    {
        $variables = $this->emailer->availableVariables($user);

        ['subject' => $subject, 'body' => $body, 'isHtml' => $isHtml] = $this->renderContent(
            $send->content_source,
            $send->content_source === BulkEmailSend::CONTENT_SOURCE_TEMPLATE ? $send->template : null,
            $send->subject,
            $send->content_format,
            $send->content_raw,
            $variables,
        );

        try {
            if ($isHtml) {
                Mail::html($body, function ($message) use ($user, $subject, $sender) {
                    $message->to($user->email, $user->fullName)->subject($subject);
                    if ($sender) {
                        $message->replyTo($sender->email, $sender->fullName);
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
            $status = EmailLog::STATUS_FAILED;
            $error = $e->getMessage();
        }

        return EmailLog::create([
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
            'data' => [
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
        ]);
    }
}
