<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use Illuminate\Database\Seeder;

class SystemEmailTemplateSeeder extends Seeder
{
    private array $templateDefinitions = [
        'welcome' => [
            'name' => 'Bienvenue',
            'name_en' => 'Welcome',
            'subject_fr' => 'Bienvenue sur {{ organization }}, {{ name }} !',
            'subject_en' => 'Welcome to {{ organization }}, {{ name }}!',
            'content_html_fr' => '<h1>Bienvenue sur {{ organization }}, {{ name }} !</h1>
<p>Votre compte a bien été créé. Vous avez reçu <strong>100 points de bienvenue</strong> pour démarrer vos premiers échanges.</p>
<p><a href="{{ url }}">Découvrir les services</a></p>',
            'content_html_en' => '<h1>Welcome to {{ organization }}, {{ name }}!</h1>
<p>Your account has been created. You received <strong>100 welcome points</strong> to get started.</p>
<p><a href="{{ url }}">Explore services</a></p>',
            'variables' => ['name', 'url'],
        ],
        'new_message' => [
            'name_fr' => 'Nouveau message reçu',
            'name_en' => 'New message received',
            'subject_fr' => 'Nouveau message de {{ sender_name }}',
            'subject_en' => 'New message from {{ sender_name }}',
            'content_html_fr' => '<h1>Nouveau message de {{ sender_name }}</h1>
<p>Bonjour {{ name }},</p>
<p><strong>{{ sender_name }}</strong> vous a envoyé un message à propos de l\'échange <strong>{{ transaction_title }}</strong>.</p>
<blockquote>{{ message_preview }}</blockquote>
<p><a href="{{ url }}">Voir la conversation</a></p>',
            'content_html_en' => '<h1>New message from {{ sender_name }}</h1>
<p>Hello {{ name }},</p>
<p><strong>{{ sender_name }}</strong> sent you a message about <strong>{{ transaction_title }}</strong>.</p>
<blockquote>{{ message_preview }}</blockquote>
<p><a href="{{ url }}">View conversation</a></p>',
            'variables' => ['name', 'sender_name', 'transaction_title', 'message_preview', 'url'],
        ],
        'transaction_status_changed' => [
            'name_fr' => 'Mise à jour statut échange',
            'name_en' => 'Exchange status update',
            'subject_fr' => 'Mise à jour de votre échange — {{ status_label }}',
            'subject_en' => 'Your exchange has been updated — {{ status_label }}',
            'content_html_fr' => '<h1>Mise à jour de votre échange</h1>
<p>Bonjour {{ name }},</p>
<p>Le statut de votre échange <strong>{{ title }}</strong> a changé : <strong>{{ status_label }}</strong>.</p>
<p>Points : {{ points }} pts</p>
<p><a href="{{ url }}">Voir la conversation</a></p>',
            'content_html_en' => '<h1>Your exchange has been updated</h1>
<p>Hello {{ name }},</p>
<p>The status of your exchange <strong>{{ title }}</strong> changed to: <strong>{{ status_label }}</strong>.</p>
<p>Points: {{ points }} pts</p>
<p><a href="{{ url }}">View conversation</a></p>',
            'variables' => ['name', 'title', 'status_label', 'points', 'url'],
        ],
        'referral_invitation' => [
            'name_fr' => 'Invitation parrainage',
            'name_en' => 'Referral invitation',
            'subject_fr' => '{{ sender_name }} vous invite à rejoindre {{ organization }}',
            'subject_en' => '{{ sender_name }} invites you to join {{ organization }}',
            'content_html_fr' => '<h1>{{ sender_name }} vous invite à rejoindre {{ organization }}</h1>
<p>{{ sender_name }} vous a envoyé le message suivant :</p>
<blockquote>{{ sender_message }}</blockquote>
<p><a href="{{ referral_link }}">Rejoindre {{ organization }}</a></p>',
            'content_html_en' => '<h1>{{ sender_name }} invites you to join {{ organization }}</h1>
<p>{{ sender_name }} sent you the following message:</p>
<blockquote>{{ sender_message }}</blockquote>
<p><a href="{{ referral_link }}">Join {{ organization }}</a></p>',
            'variables' => ['sender_name', 'recipient_name', 'sender_message', 'referral_link'],
        ],
        'ai_budget_exceeded' => [
            'name_fr' => 'Alerte budget IA dépassé',
            'name_en' => 'AI budget exceeded alert',
            'subject_fr' => 'Alerte budget IA — {{ scenario_id }}',
            'subject_en' => 'AI budget alert — {{ scenario_id }}',
            'content_html_fr' => '<h1>Alerte budget IA</h1>
<p>Bonjour {{ name }},</p>
<p>Le scénario IA <strong>{{ scenario_id }}</strong> a dépassé son budget.</p>
<ul>
<li>Coût actuel : <strong>{{ current_cost }} €</strong></li>
<li>Limite : <strong>{{ budget_limit }} €</strong></li>
</ul>
<p><a href="{{ url }}">Voir les détails</a></p>',
            'content_html_en' => '<h1>AI Budget Alert</h1>
<p>Hello {{ name }},</p>
<p>The AI scenario <strong>{{ scenario_id }}</strong> has exceeded its budget.</p>
<ul>
<li>Current cost: <strong>{{ current_cost }} €</strong></li>
<li>Limit: <strong>{{ budget_limit }} €</strong></li>
</ul>
<p><a href="{{ url }}">See details</a></p>',
            'variables' => ['name', 'scenario_id', 'current_cost', 'budget_limit', 'url'],
        ],
        'blog_contribution_invitation' => [
            'name_fr' => 'Invitation contribuer article',
            'name_en' => 'Blog contribution invitation',
            'subject_fr' => '{{ sender_name }} vous invite à lire « {{ article_title }} »',
            'subject_en' => '{{ sender_name }} invites you to read « {{ article_title }} »',
            'content_html_fr' => '<h1>{{ sender_name }} vous invite à lire et contribuer</h1>
<p>Bonjour {{ recipient_name }},</p>
<blockquote>{{ sender_message }}</blockquote>
<p><a href="{{ article_url }}">Lire l\'article : {{ article_title }}</a></p>',
            'content_html_en' => '<h1>{{ sender_name }} invites you to read and contribute</h1>
<p>Hello {{ recipient_name }},</p>
<blockquote>{{ sender_message }}</blockquote>
<p><a href="{{ article_url }}">Read the article: {{ article_title }}</a></p>',
            'variables' => ['sender_name', 'recipient_name', 'sender_message', 'article_url', 'article_title', 'register_url'],
        ],
        'loop_invitation' => [
            'name_fr' => 'Invitation à rejoindre une Boucle',
            'name_en' => 'Invitation to join a Loop',
            'subject_fr' => '{{ sender_name }} vous invite à rejoindre la Boucle {{ loop_name }}',
            'subject_en' => '{{ sender_name }} invites you to join the {{ loop_name }} Loop',
            'content_html_fr' => '<h1>{{ sender_name }} vous invite à rejoindre {{ loop_name }}</h1>
<p>Bonjour {{ recipient_name }},</p>
<p><strong>{{ sender_name }}</strong> vous invite à rejoindre la Boucle <strong>{{ loop_name }}</strong> au sein de {{ organization_name }}.</p>
<p><em>{{ loop_tagline }}</em></p>
<blockquote>{{ personal_message }}</blockquote>
<p><a href="{{ invitation_url }}">Voir l\'invitation et rejoindre la Boucle</a></p>
<p>Cette invitation expire le {{ expires_at }}.</p>
<p>— {{ app_name }}</p>',
            'content_html_en' => '<h1>{{ sender_name }} invites you to join {{ loop_name }}</h1>
<p>Hello {{ recipient_name }},</p>
<p><strong>{{ sender_name }}</strong> invites you to join the <strong>{{ loop_name }}</strong> Loop within {{ organization_name }}.</p>
<p><em>{{ loop_tagline }}</em></p>
<blockquote>{{ personal_message }}</blockquote>
<p><a href="{{ invitation_url }}">View the invitation and join the Loop</a></p>
<p>This invitation expires on {{ expires_at }}.</p>
<p>— {{ app_name }}</p>',
            'variables' => [
                'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
                'loop_name', 'loop_tagline', 'personal_message', 'invitation_url',
                'expires_at', 'app_name',
            ],
        ],
        // TASK-1659 — "Création de comptes en masse" : contrairement a
        // loop_invitation, ce lien cree DIRECTEMENT le compte au clic (pas
        // de formulaire d'inscription intermediaire, aucun mot de passe).
        'organization_invitation' => [
            'name_fr' => 'Invitation directe a une Organization',
            'name_en' => 'Direct Organization invitation',
            'subject_fr' => 'Votre accès à {{ organization_name }}',
            'subject_en' => 'Your access to {{ organization_name }}',
            // Styles INLINE a dessein : les clients de messagerie ignorent
            // une balise <style>. Ce gabarit a d'abord ete seme en HTML nu, et
            // comme il prend le pas sur le repli Blade, les courriels sont
            // partis sans mise en forme — regression vue par Cyril le 01/10.
            // La forme reprend donc celle du repli `emails/organization-invitation`.
            'content_html_fr' => '<div style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 16px;">Vous êtes invité·e à rejoindre {{ organization_name }}</h1>
<p style="margin: 0 0 12px;">Bonjour {{ recipient_name }},</p>
<p style="margin: 0 0 12px;"><strong>{{ sender_name }}</strong> vous invite à rejoindre <strong>{{ organization_name }}</strong> sur {{ app_name }}.</p>
<p style="margin: 0 0 20px;"><a href="{{ invitation_url }}" style="display: inline-block; padding: 12px 20px; border-radius: 10px; background: #4f46e5; color: #ffffff; text-decoration: none; font-weight: 600;">Rejoindre {{ organization_name }}</a></p>
<p style="margin: 0 0 8px; font-size: 13px; color: #6b7280;">Ce lien expire le {{ expires_at }}.</p>
<p style="margin: 0; font-size: 13px; color: #9ca3af;">— {{ app_name }}</p>
</div>',
            'content_html_en' => '<div style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 16px;">You are invited to join {{ organization_name }}</h1>
<p style="margin: 0 0 12px;">Hi {{ recipient_name }},</p>
<p style="margin: 0 0 12px;"><strong>{{ sender_name }}</strong> invites you to join <strong>{{ organization_name }}</strong> on {{ app_name }}.</p>
<p style="margin: 0 0 20px;"><a href="{{ invitation_url }}" style="display: inline-block; padding: 12px 20px; border-radius: 10px; background: #4f46e5; color: #ffffff; text-decoration: none; font-weight: 600;">Join {{ organization_name }}</a></p>
<p style="margin: 0 0 8px; font-size: 13px; color: #6b7280;">This link expires on {{ expires_at }}.</p>
<p style="margin: 0; font-size: 13px; color: #9ca3af;">— {{ app_name }}</p>
</div>',
            'variables' => [
                'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
                'invitation_url', 'expires_at', 'app_name',
            ],
        ],
    ];

    public function run(): void
    {
        $organizations = Organization::where('is_active', true)->get();

        foreach ($organizations as $organization) {
            foreach ($this->templateDefinitions as $slug => $def) {
                foreach (['fr', 'en'] as $locale) {
                    SystemEmailTemplate::firstOrCreate(
                        [
                            'organization_id' => $organization->id,
                            'locale' => $locale,
                            'slug' => $slug,
                        ],
                        [
                            'name' => $locale === 'fr' ? ($def['name_fr'] ?? $def['name']) : ($def['name_en'] ?? $def['name']),
                            'subject' => $def["subject_{$locale}"] ?? $def['subject_fr'],
                            'content_html' => $def["content_html_{$locale}"] ?? $def['content_html_fr'],
                            'variables' => $def['variables'],
                            'enabled' => true,
                        ],
                    );
                }
            }
        }
    }
}
