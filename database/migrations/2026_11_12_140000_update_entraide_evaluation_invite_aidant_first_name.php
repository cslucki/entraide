<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1809 — demande Cyril (12/11) : le gabarit « invitation a evaluer
 * l'aidant » nomme l'aidant par son PRENOM dans la phrase d'invitation
 * (`{{ aidant_first_name }}`), pas par le pronom « lui ». Le fait de la
 * cloture et le nom complet a l'ouverture restent inchanges.
 *
 * Meme forme que `2026_11_12_110000_add_entraide_evaluation_invite_email_template`
 * (T1808 §6) : un gabarit `system_email_templates` a deux points d'entree —
 * le catalogue du seeder (bases fraiches, deja mis a jour) et cette
 * migration (bases deja installees, qu'un seeder ne revisite pas).
 *
 * Idempotente et NON destructive de toute personnalisation : `up()` ne
 * modifie une ligne QUE si son `content_html` correspond encore EXACTEMENT
 * au texte canonique precedent (celui pose par la migration T1808 §6) — un
 * texte deja reecrit par le SuperAdmin depuis `/admin/system-email-templates`
 * (meme regle que `new_message` en PROD) n'est jamais ecrase en silence.
 * `down()` applique la meme garde, symetrique, pour rester reversible sans
 * jamais reecraser une edition humaine faite entre les deux.
 */
return new class extends Migration
{
    private const SLUG = 'entraide_evaluation_invite';

    public function up(): void
    {
        foreach ($this->gabarits() as $locale => $g) {
            DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->where('content_html', $g['ancien'])
                ->update([
                    'content_html' => $g['nouveau'],
                    'variables' => json_encode($g['variables']),
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
                ->where('content_html', $g['nouveau'])
                ->update([
                    'content_html' => $g['ancien'],
                    'variables' => json_encode([
                        'recipient_name', 'recipient_email', 'aidant_name',
                        'organization_name', 'evaluation_url', 'app_name',
                    ]),
                    'updated_at' => now(),
                ]);
        }
    }

    /** @return array<string, array{ancien: string, nouveau: string, variables: list<string>}> */
    private function gabarits(): array
    {
        return [
            'fr' => [
                'ancien' => '<p>Bonjour {{ recipient_name }},</p><p>Votre entraide avec <strong>{{ aidant_name }}</strong> est terminée.</p><p>Vous pouvez lui laisser une évaluation pour reconnaître sa contribution et aider les autres membres de {{ organization_name }}.</p><p>Quelques étoiles suffisent. Un commentaire est facultatif.</p><p><a href="{{ evaluation_url }}">Laisser une évaluation</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ evaluation_url }}</p><p>Merci de faire vivre l’entraide sur {{ app_name }}.</p>',
                'nouveau' => '<p>Bonjour {{ recipient_name }},</p><p>Votre entraide avec <strong>{{ aidant_name }}</strong> est terminée.</p><p>Vous pouvez laisser une évaluation à {{ aidant_first_name }} pour reconnaître sa contribution et aider les autres membres de {{ organization_name }}.</p><p>Quelques étoiles suffisent. Un commentaire est facultatif.</p><p><a href="{{ evaluation_url }}">Laisser une évaluation</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ evaluation_url }}</p><p>Merci de faire vivre l’entraide sur {{ app_name }}.</p>',
                'variables' => [
                    'recipient_name', 'recipient_email', 'aidant_name', 'aidant_first_name',
                    'organization_name', 'evaluation_url', 'app_name',
                ],
            ],
            'en' => [
                'ancien' => '<p>Hello {{ recipient_name }},</p><p>Your mutual aid with <strong>{{ aidant_name }}</strong> is complete.</p><p>You can leave them a review to recognize their contribution and help other members of {{ organization_name }}.</p><p>A few stars are enough. A comment is optional.</p><p><a href="{{ evaluation_url }}">Leave a review</a></p><p>If the link does not work, copy this address into your browser:<br>{{ evaluation_url }}</p><p>Thank you for keeping mutual aid alive on {{ app_name }}.</p>',
                'nouveau' => '<p>Hello {{ recipient_name }},</p><p>Your mutual aid with <strong>{{ aidant_name }}</strong> is complete.</p><p>You can leave a review for {{ aidant_first_name }} to recognize their contribution and help other members of {{ organization_name }}.</p><p>A few stars are enough. A comment is optional.</p><p><a href="{{ evaluation_url }}">Leave a review</a></p><p>If the link does not work, copy this address into your browser:<br>{{ evaluation_url }}</p><p>Thank you for keeping mutual aid alive on {{ app_name }}.</p>',
                'variables' => [
                    'recipient_name', 'recipient_email', 'aidant_name', 'aidant_first_name',
                    'organization_name', 'evaluation_url', 'app_name',
                ],
            ],
        ];
    }
};
