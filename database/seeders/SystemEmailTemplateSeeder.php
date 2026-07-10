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
