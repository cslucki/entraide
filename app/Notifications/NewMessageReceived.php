<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\SystemEmailTemplate;
use App\Models\Transaction;
use App\Services\EmailerService;
use App\Support\Email\SystemEmailTemplateResolver;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

class NewMessageReceived extends Notification
{
    public function __construct(
        public readonly Transaction $transaction,
        public readonly Message $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->transaction->isDirectConversation()
            ? $this->messagePrive($notifiable)
            : $this->messageDEchange($notifiable);
    }

    /**
     * TASK-1795 correctif 2 — une DISCUSSION PRIVEE a son propre gabarit.
     *
     * ## Le defaut
     *
     * Une discussion privee est portee par une ligne `transactions` sans
     * service, sans demande et a zero point : le modele de donnees est partage
     * avec l'entraide, et c'est `Transaction::isDirectConversation()` qui les
     * distingue DEJA — la regle existe, elle n'etait simplement pas consultee
     * ici. Les deux cas recevaient donc `new_message`, dont le texte parle d'un
     * « echange » et interpole `{{ transaction_title }}` : sur une discussion
     * privee cette variable vaut « Echange », un mot invente par le repli juste
     * au-dessus pour une transaction qui n'a ni service ni demande.
     *
     * ## Ce qui n'a PAS change
     *
     * `new_message` reste le gabarit des messages d'entraide, a l'identique :
     * memes variables, meme texte, meme repli Blade. Aucun statut, aucun point,
     * aucune relation de transaction n'est touche — le choix se fait a la
     * lecture, pas a l'ecriture.
     */
    private function messagePrive(object $notifiable): MailMessage
    {
        $template = $this->resolveTemplate('private_message_received', $notifiable);

        [$prenom, $nom] = $this->nomDeLExpediteur();

        if ($template) {
            $emailer = app(EmailerService::class);

            $extraVars = [
                'sender_first_name' => $prenom,
                'sender_name' => $nom,
                'recipient_name' => $notifiable->fullName,
                'organization_name' => $this->transaction->organization?->name ?? '',
                'message_preview' => $this->apercu(),
                'message_url' => $this->urlDeLaConversation(),
                'app_name' => config('app.name'),
            ];
            $vars = array_merge($emailer->availableVariables($notifiable), $extraVars);

            return (new MailMessage)
                ->subject($this->sujetLisible(
                    $emailer->interpolateSubject($template->subject, $vars, array_keys($extraVars))
                ))
                ->markdown('emails.system-email', [
                    'html' => $emailer->interpolate($template->content_html, $vars, array_keys($extraVars)),
                ]);
        }

        return (new MailMessage)
            ->subject($this->sujetLisible('Message privé de '.$prenom.' '.$nom))
            ->markdown('emails.private_message_received', [
                'user' => $notifiable,
                'message' => $this->message,
                'organizationName' => $this->transaction->organization?->name ?? '',
                'url' => $this->urlDeLaConversation(),
            ]);
    }

    private function messageDEchange(object $notifiable): MailMessage
    {
        $template = $this->resolveTemplate('new_message', $notifiable);

        if ($template) {
            [$prenom, $nom] = $this->nomDeLExpediteur();
            $service = $this->transaction->service;
            $transactionTitle = $service ? $service->title : ($this->transaction->serviceRequest?->title ?? 'Échange');

            $emailer = app(EmailerService::class);
            $extraVars = [
                // TASK-1795 — `sender_first_name` est FOURNI ici aussi.
                //
                // Defaut mesure le 09/11/2026 sur les donnees PROD : le gabarit
                // `new_message` FR (ligne 28) a ete reecrit depuis
                // `/admin/system-email-templates` en « Nouveau message de
                // {{ sender_first_name }} {{ sender_name }} », mais ce chemin
                // n'a JAMAIS fourni `sender_first_name`. Or
                // `EmailerService::interpolateSubject()` laisse le jeton BRUT
                // quand la cle n'est ni dans l'allowlist ni `isset()` : le
                // journal d'emails du jumeau porte un sujet reellement envoye
                // « Nouveau message de {{ sender_first_name }} SLUCKI ».
                //
                // Le gabarit n'est pas en cause — c'est du contenu
                // administrable, et un SuperAdmin a le droit d'y mettre le
                // prenom. C'est la variable qui manquait. On l'ajoute sans
                // toucher au texte du gabarit, que le seeder respecte deja
                // (`firstOrCreate`).
                'sender_first_name' => $prenom,
                'sender_name' => $nom,
                'transaction_title' => $transactionTitle,
                'message_preview' => $this->apercu(),
                'url' => $this->urlDeLaConversation(),
            ];
            $vars = array_merge($emailer->availableVariables($notifiable), $extraVars);

            return (new MailMessage)
                ->subject($this->sujetLisible(
                    $emailer->interpolateSubject($template->subject, $vars, array_keys($extraVars))
                ))
                // TASK-1694 bug P — `markdown()` et non `view()` : c'est ce chemin
                // qui declenche l'habillage et l'incorporation des styles.
                ->markdown('emails.system-email', [
                    'html' => $emailer->interpolate($template->content_html, $vars, array_keys($extraVars)),
                ]);
        }

        return (new MailMessage)
            ->subject('Nouveau message de '.($this->message->sender?->name ?? 'Système'))
            ->markdown('emails.new_message', [
                'user' => $notifiable,
                'transaction' => $this->transaction,
                'message' => $this->message,
                // TASK-1795 correctif 1 — l'URL est calculee ICI, pas dans le
                // Blade. Le gabarit de repli appelait `route('messages.show')`
                // lui-meme et reintroduisait donc l'URL fautive, meme une fois
                // le chemin gabarit corrige.
                'url' => $this->urlDeLaConversation(),
            ]);
    }

    /**
     * TASK-1795 correctif 1 — l'URL porte l'Organization DE LA CONVERSATION.
     *
     * ## La 404, exactement
     *
     * `route('messages.show', $transaction)` rend `/messages/{id}`, la surface
     * COURTE. `ResolveUrlOrganization` y lie l'Organization PAR DEFAUT
     * (`messages` est dans `$defaultOrganizationRoutes`). Le scope global
     * `BelongsToOrganizationScope` filtre alors `transactions` sur `main`, et
     * le liage de modele de la route ne trouve RIEN pour une conversation
     * `ofsh` : `ModelNotFoundException`, donc 404 — avant meme la policy et
     * avant l'`abort(404)` explicite de `MessageController::show()`.
     *
     * Mesure du 09/11/2026 sur le jumeau, conversation
     * `01a1a7e7-a7bf-73e9-83e2-0268476caf0d` (Organization `ofsh`) :
     * `current_organization = main` -> `Transaction::find()` rend NULL ;
     * `current_organization = ofsh` -> rend la ligne.
     *
     * ## Pourquoi la source est la TRANSACTION, et rien d'autre
     *
     * Ni `currentOrganization()` ni l'Organization par defaut : une
     * notification part souvent hors de toute requete HTTP (file d'attente,
     * commande), ou depuis la requete de l'EXPEDITEUR. Les deux donneraient une
     * Organization qui n'est pas celle de la conversation — c'est precisement
     * le defaut qu'on ferme. `transactions.organization_id` est la seule source
     * qui ne depend pas du contexte d'envoi.
     *
     * Le lien reste une URL, pas un droit : la route `organization.messages.*`
     * garde ses middlewares et `TransactionPolicy::view()` verifie toujours que
     * la personne est partie a l'echange. Aucun scope, aucun middleware retire.
     *
     * `Route::has()` reprend l'idiome deja tenu par `NotificationTargetResolver`
     * et `MessageController::versLaConversation()`.
     */
    private function urlDeLaConversation(): string
    {
        $organization = $this->transaction->organization;

        if ($organization !== null && Route::has('organization.messages.show')) {
            return route('organization.messages.show', [
                'organization' => $organization->slug,
                'transaction' => $this->transaction->id,
            ]);
        }

        return route('messages.show', ['transaction' => $this->transaction->id]);
    }

    /**
     * Le prenom et le nom reels de l'expediteur, jamais une chaine vide seule.
     *
     * `users.name` est NOT NULL, `users.first_name` ne l'est pas : le sujet
     * « Message privé de {{ sender_first_name }} {{ sender_name }} » doit donc
     * tenir avec un prenom absent. On rend des chaines, jamais `null` :
     * `EmailerService::interpolateSubject()` laisse le jeton `{{ ... }}` BRUT
     * quand la cle n'est pas `isset()`, et un sujet d'email affichant
     * `{{ sender_first_name }}` serait pire qu'un prenom manquant.
     */
    private function nomDeLExpediteur(): array
    {
        $sender = $this->message->sender;

        $prenom = trim((string) ($sender?->first_name ?? ''));
        $nom = trim((string) ($sender?->name ?? ''));

        if ($prenom === '' && $nom === '') {
            $nom = 'Système';
        }

        return [$prenom, $nom];
    }

    /**
     * Un sujet sans espace disgracieux.
     *
     * Le gabarit colle `{{ sender_first_name }} {{ sender_name }}` : un prenom
     * absent laisserait « Message privé de  SLUCKI », deux espaces. La
     * normalisation vit ICI et non dans `EmailerService` — celui-ci sert tous
     * les gabarits, dont certains ou une suite d'espaces peut etre voulue.
     */
    private function sujetLisible(string $sujet): string
    {
        return trim(preg_replace('/\s+/u', ' ', $sujet) ?? $sujet);
    }

    /**
     * L'apercu est du TEXTE, jamais du HTML.
     *
     * `strip_tags()` d'abord, puis `EmailerService::interpolate()` passe la
     * valeur par `e()` : le corps d'un message ne peut donc pas injecter de
     * balise dans l'email, meme si l'expediteur en a ecrit une.
     */
    private function apercu(): string
    {
        return mb_strimwidth(strip_tags($this->message->body ?? ''), 0, 120, '…');
    }

    /**
     * TASK-1694 bug M — la résolution a QUITTÉ ce fichier.
     *
     * Cette méthode était l'une des sept copies de la même logique, et elle ne
     * regardait que les lignes du tenant : une ligne « Global » lui était
     * invisible.
     * `SystemEmailTemplateResolver` est désormais l'autorité unique — six crans,
     * du plus spécifique au Blade. Voir sa documentation.
     */
    private function resolveTemplate(string $slug, object $notifiable): ?SystemEmailTemplate
    {
        return app(SystemEmailTemplateResolver::class)->resolveForNotifiable($slug, $notifiable);
    }
}
