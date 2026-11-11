<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1806 (vigilance MASTER du 11/11) — reprendre le vocabulaire des
 * gabarits d'email DEJA PERSISTES.
 *
 * ## Pourquoi corriger le seeder ne suffit pas
 *
 * `SystemEmailTemplateSeeder` ne sert que les bases NEUVES : les lignes de
 * `system_email_templates` existent deja en PROD (22 FR + 22 EN mesurees sur
 * le jumeau le 11/11), et elles y gardent leur texte d'origine tant que
 * personne ne les reecrit. Modifier le seeder — ou une ancienne migration,
 * qui ne sera jamais rejouee — laisserait donc la PROD sur « echange »,
 * « annonce » et « transaction ».
 *
 * ## Et pourquoi un simple UPDATE ne suffit pas non plus
 *
 * Ces gabarits sont ADMINISTRABLES : un SuperAdmin peut les avoir reecrits
 * depuis `/admin/system-email-templates`. Un UPDATE inconditionnel ecraserait
 * son travail sans un mot. Consigne MASTER : « un mecanisme idempotent,
 * controle et non destructif, sans ecraser silencieusement des
 * personnalisations administratives ».
 *
 * ## Le mecanisme retenu : reprise par EGALITE STRICTE
 *
 * Chaque ligne n'est reecrite que si sa valeur courante est EXACTEMENT celle
 * que le seeder avait posee. Trois proprietes en decoulent :
 *
 * - **non destructif** : un gabarit personnalise ne correspond a aucun
 *   « avant », il n'est donc jamais touche ;
 * - **idempotent** : rejouee, la migration ne trouve plus aucun « avant » et
 *   n'ecrit rien ;
 * - **reversible** : `down()` fait le chemin inverse, par la meme egalite
 *   stricte.
 *
 * La contrepartie, assumee : un gabarit personnalise qui contient encore
 * « echange » le gardera. C'est le texte de son auteur, pas le notre.
 * Mesure du 11/11 sur le jumeau : une seule ligne est dans ce cas,
 * `new_message` en FR, reecrite en PROD pour y ajouter `{{ first_name }}`.
 * Elle devra etre reprise A LA MAIN depuis `/admin/system-email-templates`,
 * ou laissee telle quelle — c'est une decision humaine, pas une migration.
 *
 * Effet de bord documente : une ligne stockee en CRLF ressort en LF apres
 * reprise (la comparaison ignore le style de fin de ligne, l'ecriture pose
 * celui du gabarit de reference). Sans consequence sur un courriel HTML.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reprendre(versLeNouveau: true);
    }

    public function down(): void
    {
        $this->reprendre(versLeNouveau: false);
    }

    private function reprendre(bool $versLeNouveau): void
    {
        foreach ($this->reprises() as $reprise) {
            $cherche = $versLeNouveau ? $reprise['avant'] : $reprise['apres'];
            $pose = $versLeNouveau ? $reprise['apres'] : $reprise['avant'];

            $lignes = DB::table('system_email_templates')
                ->where('slug', $reprise['slug'])
                ->where('locale', $reprise['locale'])
                ->get(['id', $reprise['colonne']]);

            foreach ($lignes as $ligne) {
                // La comparaison ignore le STYLE de fin de ligne, jamais le
                // contenu. Mesure du 11/11 : sur le jumeau,
                // `affectation-points-suite-inscription` est stocke en CRLF
                // alors que les autres gabarits sont en LF — une egalite
                // stricte aurait saute cette ligne EN SILENCE, et la PROD
                // peut tres bien melanger les deux styles autrement.
                if ($this->normaliser($ligne->{$reprise['colonne']}) !== $this->normaliser($cherche)) {
                    continue;
                }

                DB::table('system_email_templates')
                    ->where('id', $ligne->id)
                    ->update([
                        $reprise['colonne'] => $pose,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /** Les fins de ligne ne font pas le sens d'un gabarit HTML. */
    private function normaliser(?string $valeur): string
    {
        return str_replace(["\r\n", "\r"], "\n", (string) $valeur);
    }

    /** @return list<array{slug: string, locale: string, colonne: string, avant: string, apres: string}> */
    private function reprises(): array
    {
        return [
            [
                'slug' => 'transaction_status_changed',
                'locale' => 'fr',
                'colonne' => 'name',
                'avant' => <<<'TXT'
Mise à jour statut échange
TXT,
                'apres' => <<<'TXT'
Mise à jour de votre entraide
TXT,
            ],
            [
                'slug' => 'transaction_status_changed',
                'locale' => 'en',
                'colonne' => 'name',
                'avant' => <<<'TXT'
Exchange status update
TXT,
                'apres' => <<<'TXT'
Mutual aid status update
TXT,
            ],
            [
                'slug' => 'transaction_status_changed',
                'locale' => 'fr',
                'colonne' => 'subject',
                'avant' => <<<'TXT'
Mise à jour de votre échange — {{ status_label }}
TXT,
                'apres' => <<<'TXT'
Mise à jour de votre entraide — {{ status_label }}
TXT,
            ],
            [
                'slug' => 'transaction_status_changed',
                'locale' => 'en',
                'colonne' => 'subject',
                'avant' => <<<'TXT'
Your exchange has been updated — {{ status_label }}
TXT,
                'apres' => <<<'TXT'
Your mutual aid has been updated — {{ status_label }}
TXT,
            ],
            [
                'slug' => 'transaction_status_changed',
                'locale' => 'fr',
                'colonne' => 'content_html',
                'avant' => <<<'TXT'
<h1>Mise à jour de votre échange</h1>
<p>Bonjour {{ name }},</p>
<p>Le statut de votre échange <strong>{{ title }}</strong> a changé : <strong>{{ status_label }}</strong>.</p>
<p>Points : {{ points }} pts</p>
<p><a href="{{ url }}">Voir la conversation</a></p>
TXT,
                'apres' => <<<'TXT'
<h1>Mise à jour de votre entraide</h1>
<p>Bonjour {{ name }},</p>
<p>Le statut de votre entraide <strong>{{ title }}</strong> a changé : <strong>{{ status_label }}</strong>.</p>
<p>Points : {{ points }} pts</p>
<p><a href="{{ url }}">Voir la conversation</a></p>
TXT,
            ],
            [
                'slug' => 'transaction_status_changed',
                'locale' => 'en',
                'colonne' => 'content_html',
                'avant' => <<<'TXT'
<h1>Your exchange has been updated</h1>
<p>Hello {{ name }},</p>
<p>The status of your exchange <strong>{{ title }}</strong> changed to: <strong>{{ status_label }}</strong>.</p>
<p>Points: {{ points }} pts</p>
<p><a href="{{ url }}">View conversation</a></p>
TXT,
                'apres' => <<<'TXT'
<h1>Your mutual aid has been updated</h1>
<p>Hello {{ name }},</p>
<p>The status of your mutual aid <strong>{{ title }}</strong> changed to: <strong>{{ status_label }}</strong>.</p>
<p>Points: {{ points }} pts</p>
<p><a href="{{ url }}">View conversation</a></p>
TXT,
            ],
            [
                'slug' => 'new_message',
                'locale' => 'fr',
                'colonne' => 'content_html',
                'avant' => <<<'TXT'
<h1>Nouveau message de {{ sender_name }}</h1>
<p>Bonjour {{ name }},</p>
<p><strong>{{ sender_name }}</strong> vous a envoyé un message à propos de l'échange <strong>{{ transaction_title }}</strong>.</p>
<blockquote>{{ message_preview }}</blockquote>
<p><a href="{{ url }}">Voir la conversation</a></p>
TXT,
                'apres' => <<<'TXT'
<h1>Nouveau message de {{ sender_name }}</h1>
<p>Bonjour {{ name }},</p>
<p><strong>{{ sender_name }}</strong> vous a envoyé un message à propos de l'entraide <strong>{{ transaction_title }}</strong>.</p>
<blockquote>{{ message_preview }}</blockquote>
<p><a href="{{ url }}">Voir la conversation</a></p>
TXT,
            ],
            [
                'slug' => 'transaction_proposal_created',
                'locale' => 'fr',
                'colonne' => 'name',
                'avant' => <<<'TXT'
Proposition reçue sur votre annonce
TXT,
                'apres' => <<<'TXT'
Réponse à votre demande ou proposition d’aide
TXT,
            ],
            [
                'slug' => 'transaction_proposal_created',
                'locale' => 'en',
                'colonne' => 'name',
                'avant' => <<<'TXT'
Proposal received on your listing
TXT,
                'apres' => <<<'TXT'
Reply to your help request or offer
TXT,
            ],
            [
                'slug' => 'transaction_proposal_created',
                'locale' => 'fr',
                'colonne' => 'subject',
                'avant' => <<<'TXT'
Quelqu’un répond à votre annonce « {{ subject_title }} »
TXT,
                'apres' => <<<'TXT'
Quelqu’un répond à « {{ subject_title }} »
TXT,
            ],
            [
                'slug' => 'transaction_proposal_created',
                'locale' => 'en',
                'colonne' => 'subject',
                'avant' => <<<'TXT'
Someone replied to your listing “{{ subject_title }}”
TXT,
                'apres' => <<<'TXT'
Someone replied to “{{ subject_title }}”
TXT,
            ],
            [
                'slug' => 'transaction_proposal_created',
                'locale' => 'fr',
                'colonne' => 'content_html',
                'avant' => <<<'TXT'
<p>Bonjour {{ recipient_name }},</p><p>{{ sender_name }} vient de répondre à votre annonce <strong>{{ subject_title }}</strong> au sein de {{ organization_name }}, pour {{ points_proposed }} points.</p><p>Vous pouvez accepter ou refuser cette proposition depuis la conversation.</p><p><a href="{{ exchange_url }}">Voir la proposition</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ exchange_url }}</p><p>À bientôt,<br>{{ app_name }}</p>
TXT,
                'apres' => <<<'TXT'
<p>Bonjour {{ recipient_name }},</p><p>{{ sender_name }} vient de répondre à <strong>{{ subject_title }}</strong> au sein de {{ organization_name }}, pour {{ points_proposed }} points.</p><p>Vous pouvez accepter ou refuser cette proposition depuis la conversation.</p><p><a href="{{ exchange_url }}">Voir la proposition</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ exchange_url }}</p><p>À bientôt,<br>{{ app_name }}</p>
TXT,
            ],
            [
                'slug' => 'transaction_proposal_created',
                'locale' => 'en',
                'colonne' => 'content_html',
                'avant' => <<<'TXT'
<p>Hello {{ recipient_name }},</p><p>{{ sender_name }} has just replied to your listing <strong>{{ subject_title }}</strong> in {{ organization_name }}, for {{ points_proposed }} points.</p><p>You can accept or decline this proposal from the conversation.</p><p><a href="{{ exchange_url }}">View the proposal</a></p><p>If the link does not work, copy this address into your browser:<br>{{ exchange_url }}</p><p>See you soon,<br>{{ app_name }}</p>
TXT,
                'apres' => <<<'TXT'
<p>Hello {{ recipient_name }},</p><p>{{ sender_name }} has just replied to <strong>{{ subject_title }}</strong> in {{ organization_name }}, for {{ points_proposed }} points.</p><p>You can accept or decline this proposal from the conversation.</p><p><a href="{{ exchange_url }}">View the proposal</a></p><p>If the link does not work, copy this address into your browser:<br>{{ exchange_url }}</p><p>See you soon,<br>{{ app_name }}</p>
TXT,
            ],
            [
                'slug' => 'entraide_comment_created',
                'locale' => 'fr',
                'colonne' => 'name',
                'avant' => <<<'TXT'
Commentaire sur votre annonce d'entraide
TXT,
                'apres' => <<<'TXT'
Commentaire sur votre entraide
TXT,
            ],
            [
                'slug' => 'entraide_comment_created',
                'locale' => 'en',
                'colonne' => 'name',
                'avant' => <<<'TXT'
Comment on your mutual aid listing
TXT,
                'apres' => <<<'TXT'
Comment on your mutual aid
TXT,
            ],
            [
                'slug' => 'entraide_comment_created',
                'locale' => 'fr',
                'colonne' => 'content_html',
                'avant' => <<<'TXT'
<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> a laissé un commentaire sur votre {{ listing_type }} <strong>{{ listing_title }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">Voir le commentaire</a></p><p>Un commentaire n’engage rien : ni échange, ni acceptation d’aide, ni temps promis. Vous répondrez si vous le souhaitez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>
TXT,
                'apres' => <<<'TXT'
<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> a laissé un commentaire sur votre {{ listing_type }} <strong>{{ listing_title }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">Voir le commentaire</a></p><p>Un commentaire n’engage rien : ni entraide engagée, ni acceptation d’aide, ni temps promis. Vous répondrez si vous le souhaitez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>
TXT,
            ],
            [
                'slug' => 'entraide_comment_created',
                'locale' => 'en',
                'colonne' => 'content_html',
                'avant' => <<<'TXT'
<p>Hello {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> left a comment on your {{ listing_type }} <strong>{{ listing_title }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">View the comment</a></p><p>A comment commits to nothing: no exchange, no accepted help, no promised time. You may reply if you wish.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>
TXT,
                'apres' => <<<'TXT'
<p>Hello {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> left a comment on your {{ listing_type }} <strong>{{ listing_title }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">View the comment</a></p><p>A comment commits to nothing: no mutual aid started, no accepted help, no promised time. You may reply if you wish.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>
TXT,
            ],
            [
                'slug' => 'affectation-points-suite-inscription',
                'locale' => 'fr',
                'colonne' => 'content_html',
                'avant' => <<<'TXT'
<h1>Bienvenue sur {{ organisation }}, {{ name }} !</h1>
<p>Votre compte a bien été créé. Vous avez reçu <strong>100 points de bienvenue</strong> pour démarrer vos premiers échanges.</p>
<p><a href="{{ url }}">Découvrir les services</a></p>
TXT,
                'apres' => <<<'TXT'
<h1>Bienvenue sur {{ organisation }}, {{ name }} !</h1>
<p>Votre compte a bien été créé. Vous avez reçu <strong>100 points de bienvenue</strong> pour démarrer vos premières entraides.</p>
<p><a href="{{ url }}">Découvrir les services</a></p>
TXT,
            ],
        ];
    }
};
