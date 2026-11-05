<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1751 — le gabarit du courriel « quelqu'un repond a votre annonce ».
 *
 * ## Pourquoi une migration, et pas un fichier de langue
 *
 * Cyril a demande que le modele soit visible et modifiable dans
 * `/admin/system-email-templates`. L'autorite du contenu des emails est
 * `SystemEmailTemplate` : ecrire le texte en fichier de langue aurait installe
 * une seconde autorite, invisible au SuperAdmin. Le fichier de langue existe
 * tout de meme, mais comme REPLI — et la suite de coherence l'exige des que le
 * canal `email` est declare.
 *
 * ## UN gabarit, pour les deux sens de l'echange
 *
 * « Demander cette aide » sur un service et « Proposer mon aide » sur une
 * demande passent par le meme `store()`, et les roles s'y inversent. Mais le
 * fait vu du destinataire ne change pas : on repond a l'annonce qu'il a
 * publiee. `{{ subject_title }}` rend le titre du service OU de la demande
 * sans que le texte ait a savoir lequel — c'est ce qui permet un seul gabarit
 * au lieu de deux.
 *
 * ## Ce gabarit n'est PAS `transaction_status_changed`
 *
 * Celui-la existe deja et sert le chemin HERITE (`approve`/`refuse`/
 * `complete`/`confirm`), hors catalogue et hors preferences. Le reutiliser
 * aurait prive cette cle des preferences et du Centre, et aurait force un
 * SuperAdmin a ecrire le meme texte pour une proposition recue et pour un
 * changement de statut — deux moments qui n'ont rien a voir.
 *
 * ## `down()` ne detruit jamais un texte reecrit
 *
 * Ces lignes sont REGENERABLES a l'identique par le `up()` : ce sont des
 * textes par defaut, pas des decisions humaines. Le `down()` ne supprime donc
 * que les lignes restees identiques au defaut — une personnalisation du
 * SuperAdmin survit au rollback (doctrine TASK-1711).
 */
return new class extends Migration
{
    private const SLUG = 'transaction_proposal_created';

    /** @return array<string, array{name: string, subject: string, html: string}> */
    private function gabarits(): array
    {
        return [
            'fr' => [
                'name' => 'Proposition reçue sur votre annonce',
                'subject' => 'Quelqu’un répond à votre annonce « {{ subject_title }} »',
                'html' => '<p>Bonjour {{ recipient_name }},</p>'
                    .'<p>{{ sender_name }} vient de répondre à votre annonce '
                    .'<strong>{{ subject_title }}</strong> au sein de {{ organization_name }}, '
                    .'pour {{ points_proposed }} points.</p>'
                    .'<p>Vous pouvez accepter ou refuser cette proposition depuis la conversation.</p>'
                    .'<p><a href="{{ exchange_url }}">Voir la proposition</a></p>'
                    .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>'
                    .'{{ exchange_url }}</p>'
                    .'<p>À bientôt,<br>{{ app_name }}</p>',
            ],
            'en' => [
                'name' => 'Proposal received on your listing',
                'subject' => 'Someone replied to your listing “{{ subject_title }}”',
                'html' => '<p>Hello {{ recipient_name }},</p>'
                    .'<p>{{ sender_name }} has just replied to your listing '
                    .'<strong>{{ subject_title }}</strong> in {{ organization_name }}, '
                    .'for {{ points_proposed }} points.</p>'
                    .'<p>You can accept or decline this proposal from the conversation.</p>'
                    .'<p><a href="{{ exchange_url }}">View the proposal</a></p>'
                    .'<p>If the link does not work, copy this address into your browser:<br>'
                    .'{{ exchange_url }}</p>'
                    .'<p>See you soon,<br>{{ app_name }}</p>',
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->gabarits() as $locale => $g) {
            $existe = DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->exists();

            // Idempotent : une ligne deja posee — par un rejeu, ou reecrite par
            // le SuperAdmin — n'est jamais ecrasee.
            if ($existe) {
                continue;
            }

            // Pas d'`id` explicite : `system_email_templates.id` est un BIGINT
            // auto-incremente, pas un UUID (PostgreSQL refuse un UUID avec
            // `22P02`, defaut paye en TASK-1720).
            DB::table('system_email_templates')->insert([
                'slug' => self::SLUG,
                'locale' => $locale,
                'organization_id' => null,
                'name' => $g['name'],
                'subject' => $g['subject'],
                'content_html' => $g['html'],
                'variables' => json_encode([
                    'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
                    'subject_title', 'points_proposed', 'exchange_url', 'app_name',
                ]),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->gabarits() as $locale => $g) {
            DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->where('subject', $g['subject'])
                ->where('content_html', $g['html'])
                ->delete();
        }
    }
};
