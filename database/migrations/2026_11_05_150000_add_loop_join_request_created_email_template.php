<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1761 point 1 — le gabarit administrable de « quelqu'un demande a
 * rejoindre votre Boucle ».
 *
 * ## Pourquoi une migration et pas seulement le seeder
 *
 * Demande explicite de Cyril : « ceci sera donc un nouveau template d'email a
 * creer dans /admin/email-templates ». Le seeder ne tourne pas sur un
 * environnement deja deploye ; la migration, si. Sans elle, la PROD aurait la
 * cle au catalogue et aucun gabarit a rendre.
 *
 * Le seeder porte AUSSI l'entree — un gabarit pose par migration doit entrer au
 * catalogue du seeder, sinon une base reconstruite de zero ne l'aurait pas.
 *
 * ## Additive et idempotente
 *
 * Une ligne deja posee — par un rejeu, ou reecrite par le SuperAdmin depuis
 * `/admin/email-templates` — n'est **jamais** ecrasee. C'est ce qui rend ce
 * `up()` rejouable sans risque pour les personnalisations.
 *
 * Pas d'`id` explicite : `system_email_templates.id` est un BIGINT
 * auto-incremente, pas un UUID (PostgreSQL refuse un UUID avec `22P02`, defaut
 * paye en TASK-1720).
 *
 * ## Le `down()` ne retire QUE ce que ce `up()` a pose
 *
 * Il borne sur le slug et `organization_id IS NULL` : un gabarit qu'une
 * Organization se serait ecrit pour elle-meme n'est pas de son ressort.
 *
 * ## Correctif TASK-1764 — le `down()` contredisait ce contrat
 *
 * Tel qu'il etait ecrit, il supprimait TOUTE ligne globale du slug. Or le
 * `up()` passe son tour quand une ligne existe deja : la ligne epargnee a
 * l'aller etait donc detruite au retour. Deux pertes reelles possibles sur une
 * base deployee — un gabarit pose par le seeder avant la migration, et une
 * reecriture du SuperAdmin depuis `/admin/email-templates`.
 *
 * La borne est desormais celle de la doctrine T1711, deja appliquee par
 * `add_loop_member_added_email_template`, `..._join_request_accepted...` et
 * `..._transaction_proposal_created...` : ne repartent que les lignes restees
 * IDENTIQUES au defaut (meme `subject` ET meme `content_html`). Une
 * personnalisation survit au rollback — la detruire serait perdre une decision
 * humaine que le `up()` ne sait pas rendre.
 *
 * Reste un cas sans perte d'information : une ligne preexistante qui serait
 * octet pour octet celle de ce defaut repartirait. Rien n'est perdu puisque
 * rien ne la distingue de ce que le `up()` reecrit.
 */
return new class extends Migration
{
    private const SLUG = 'loop_join_request_created';

    public function up(): void
    {
        foreach ($this->gabarits() as $locale => $g) {
            $existe = DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->exists();

            if ($existe) {
                continue;
            }

            DB::table('system_email_templates')->insert([
                'slug' => self::SLUG,
                'locale' => $locale,
                'organization_id' => null,
                'name' => $g['name'],
                'subject' => $g['subject'],
                'content_html' => $g['html'],
                'variables' => json_encode([
                    'recipient_name', 'recipient_email', 'organization_name',
                    'loop_name', 'requester_name', 'request_url', 'app_name',
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
            // Seules les lignes restees IDENTIQUES au defaut repartent
            // (doctrine T1711). Une personnalisation du SuperAdmin, ou un
            // gabarit que ce `up()` a epargne parce qu'il preexistait, survit.
            DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->where('subject', $g['subject'])
                ->where('content_html', $g['html'])
                ->delete();
        }
    }

    /** @return array<string, array{name: string, subject: string, html: string}> */
    private function gabarits(): array
    {
        return [
            'fr' => [
                'name' => 'Demande pour rejoindre une Boucle',
                'subject' => 'Quelqu’un demande à rejoindre « {{ loop_name }} »',
                'html' => '<p>Bonjour {{ recipient_name }},</p>'
                    .'<p><strong>{{ requester_name }}</strong> demande à rejoindre la Boucle '
                    .'<strong>{{ loop_name }}</strong>, dont vous êtes responsable.</p>'
                    .'<p><a href="{{ request_url }}">Voir la demande et répondre</a></p>'
                    .'<p>Tant que vous n’avez pas répondu, cette personne reste en attente.</p>'
                    .'<p>À bientôt,<br>L’équipe {{ app_name }}</p>',
            ],
            'en' => [
                'name' => 'Request to join a Loop',
                'subject' => 'Someone is asking to join “{{ loop_name }}”',
                'html' => '<p>Hello {{ recipient_name }},</p>'
                    .'<p><strong>{{ requester_name }}</strong> is asking to join the Loop '
                    .'<strong>{{ loop_name }}</strong>, which you are responsible for.</p>'
                    .'<p><a href="{{ request_url }}">View the request and reply</a></p>'
                    .'<p>Until you reply, this person stays on hold.</p>'
                    .'<p>See you soon,<br>The {{ app_name }} team</p>',
            ],
        ];
    }
};
