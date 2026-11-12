<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1809 §3 — « simplification finale du parcours d'evaluation » :
 * l'email d'invitation n'est plus une relance PLANIFIEE a dix minutes, c'est
 * UN SEUL email, envoye IMMEDIATEMENT par `TransactionController::complete()`
 * a la cloture. Le texte change pour le dire (« vous avez indique que votre
 * echange est termine ») et pour nommer le nombre de points transferes
 * (`{{ points }}`, nouvelle variable).
 *
 * Meme forme que `2026_11_12_140000_update_entraide_evaluation_invite_aidant_first_name`
 * (le precedent migratoire immediat sur ce meme gabarit) : `up()` ne modifie
 * une ligne QUE si son `content_html` correspond encore EXACTEMENT au texte
 * canonique precedent — un texte deja reecrit par le SuperAdmin depuis
 * `/admin/system-email-templates` n'est jamais ecrase en silence. `down()`
 * applique la meme garde, symetrique.
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
                ->where('subject', $g['ancien_sujet'])
                ->where('content_html', $g['ancien'])
                ->update([
                    'subject' => $g['nouveau_sujet'],
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
                ->where('subject', $g['nouveau_sujet'])
                ->where('content_html', $g['nouveau'])
                ->update([
                    'subject' => $g['ancien_sujet'],
                    'content_html' => $g['ancien'],
                    'variables' => json_encode([
                        'recipient_name', 'recipient_email', 'aidant_name', 'aidant_first_name',
                        'organization_name', 'evaluation_url', 'app_name',
                    ]),
                    'updated_at' => now(),
                ]);
        }
    }

    /** @return array<string, array{ancien_sujet: string, nouveau_sujet: string, ancien: string, nouveau: string, variables: list<string>}> */
    private function gabarits(): array
    {
        return [
            'fr' => [
                'ancien_sujet' => 'Un petit retour sur l’aide de {{ aidant_name }} ?',
                'nouveau_sujet' => 'Terminer et évaluer l’entraide de {{ aidant_first_name }}',
                'ancien' => '<p>Bonjour {{ recipient_name }},</p><p>Votre entraide avec <strong>{{ aidant_name }}</strong> est terminée.</p><p>Vous pouvez laisser une évaluation à {{ aidant_first_name }} pour reconnaître sa contribution et aider les autres membres de {{ organization_name }}.</p><p>Quelques étoiles suffisent. Un commentaire est facultatif.</p><p><a href="{{ evaluation_url }}">Laisser une évaluation</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ evaluation_url }}</p><p>Merci de faire vivre l’entraide sur {{ app_name }}.</p>',
                'nouveau' => '<p>Bonjour {{ recipient_name }},</p><p>Vous avez indiqué que votre échange avec {{ aidant_first_name }} est terminé et {{ points }} points lui ont été transférés depuis votre compte.</p><p>Merci d’avance de lui laisser une évaluation pour reconnaître sa contribution. Ce sera sûrement apprécié !</p><p><a href="{{ evaluation_url }}">Évaluer l’entraide avec {{ aidant_first_name }}</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ evaluation_url }}</p>',
                'variables' => [
                    'recipient_name', 'recipient_email', 'aidant_name', 'aidant_first_name',
                    'organization_name', 'evaluation_url', 'app_name', 'points',
                ],
            ],
            'en' => [
                'ancien_sujet' => 'A quick word on {{ aidant_name }}’s help?',
                'nouveau_sujet' => 'Finish and rate your exchange with {{ aidant_first_name }}',
                'ancien' => '<p>Hello {{ recipient_name }},</p><p>Your mutual aid with <strong>{{ aidant_name }}</strong> is complete.</p><p>You can leave a review for {{ aidant_first_name }} to recognize their contribution and help other members of {{ organization_name }}.</p><p>A few stars are enough. A comment is optional.</p><p><a href="{{ evaluation_url }}">Leave a review</a></p><p>If the link does not work, copy this address into your browser:<br>{{ evaluation_url }}</p><p>Thank you for keeping mutual aid alive on {{ app_name }}.</p>',
                'nouveau' => '<p>Hello {{ recipient_name }},</p><p>You’ve marked your exchange with {{ aidant_first_name }} as complete, and {{ points }} points have been transferred from your account.</p><p>We’d really appreciate it if you could leave them a review to recognize their contribution!</p><p><a href="{{ evaluation_url }}">Rate your exchange with {{ aidant_first_name }}</a></p><p>If the link does not work, copy this address into your browser:<br>{{ evaluation_url }}</p>',
                'variables' => [
                    'recipient_name', 'recipient_email', 'aidant_name', 'aidant_first_name',
                    'organization_name', 'evaluation_url', 'app_name', 'points',
                ],
            ],
        ];
    }
};
