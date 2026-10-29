<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1725 (demande Cyril, 29/10) — quand le proprietaire accepte une demande
 * d'adhesion, le demandeur recoit un EMAIL.
 *
 * ## Ce qui manquait exactement
 *
 * La notification existait deja depuis TASK-1381, et tous les chemins
 * d'acceptation l'emettent : la carte Membres de la Boucle, l'ecran admin, et
 * l'ajout direct qui solde une demande en attente. Mais le catalogue ne portait
 * que le canal IN_APP sur `loop.join_request.accepted`. Le fait arrivait donc au
 * Centre de notifications, et nulle part ailleurs : qui ne revenait pas de
 * lui-meme n'apprenait jamais qu'on avait dit oui.
 *
 * TASK-1381 avait documente la raison de cette absence, et elle etait juste :
 * l'autorite du contenu des emails est `SystemEmailTemplate`, filtre par
 * Organization et locale, et aucun gabarit n'existait pour ce fait. En fabriquer
 * un en fichier de langue aurait installe une seconde autorite metier. La meme
 * note annoncait que l'ajouter plus tard n'exigerait aucune autre modification
 * — c'est ce que fait cette migration, en posant le gabarit qui manquait.
 *
 * ## Son PROPRE gabarit, pas `loop_member_added`
 *
 * `loop_member_added` dit « X vous a ajoute a la Boucle ». Ici la personne a
 * FAIT la demarche : la nouvelle n'est pas qu'on l'ait mise quelque part, c'est
 * qu'on ait dit oui a sa demande. Emprunter l'autre gabarit effacerait sa
 * demande de l'histoire, et priverait le SuperAdmin d'ecrire a un demandeur ce
 * qu'il ne veut pas ecrire a une personne ajoutee d'office. Meme doctrine que
 * TASK-1720 volet C, qui avait separe `loop_member_added` de `loop_invitation`
 * pour cette raison exacte.
 *
 * ## Le defaut ne nomme PAS le decideur
 *
 * `sender_name` reste declaree — un SuperAdmin qui veut citer le decideur peut
 * l'ecrire — mais le texte par defaut ne l'emploie pas : une acceptation peut
 * n'avoir aucun acteur identifie (adhesion soldee par un ajout direct), et le
 * gabarit afficherait alors un nom vide en tete de phrase.
 *
 * `loop_tagline` et `expires_at` sont declarees pour l'homogeneite de la
 * famille, non employees par le defaut : une acceptation n'expire pas, et une
 * accroche n'apprend rien a qui vient d'entrer (lecon de recette TASK-1720).
 *
 * ## `down()` ne detruit jamais un texte reecrit
 *
 * Ces lignes sont REGENERABLES a l'identique par le `up()` : ce sont des textes
 * par defaut, pas des decisions humaines. Le `down()` ne supprime donc que les
 * lignes restees identiques au defaut — une personnalisation du SuperAdmin
 * survit au rollback (doctrine TASK-1711).
 */
return new class extends Migration
{
    private const SLUG = 'loop_join_request_accepted';

    /** @return array<string, array{name: string, subject: string, html: string}> */
    private function gabarits(): array
    {
        return [
            'fr' => [
                'name' => 'Demande d\'adhésion acceptée',
                'subject' => 'Votre demande pour rejoindre la Boucle {{ loop_name }} a été acceptée',
                'html' => '<p>Bonjour {{ recipient_name }},</p>'
                    .'<p>Votre demande pour rejoindre la Boucle <strong>{{ loop_name }}</strong> '
                    .'au sein de {{ organization_name }} a été acceptée. Vous en êtes désormais membre.</p>'
                    .'<p><a href="{{ invitation_url }}">Ouvrir la Boucle</a></p>'
                    .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>'
                    .'{{ invitation_url }}</p>'
                    .'<p>À bientôt,<br>{{ app_name }}</p>',
            ],
            'en' => [
                'name' => 'Join request accepted',
                'subject' => 'Your request to join the {{ loop_name }} Loop has been accepted',
                'html' => '<p>Hello {{ recipient_name }},</p>'
                    .'<p>Your request to join the <strong>{{ loop_name }}</strong> Loop '
                    .'in {{ organization_name }} has been accepted. You are now a member.</p>'
                    .'<p><a href="{{ invitation_url }}">Open the Loop</a></p>'
                    .'<p>If the link does not work, copy this address into your browser:<br>'
                    .'{{ invitation_url }}</p>'
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
                    'loop_name', 'loop_tagline', 'personal_message', 'invitation_url',
                    'expires_at', 'app_name',
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
