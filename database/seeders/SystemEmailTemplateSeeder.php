<?php

namespace Database\Seeders;

use App\Models\SystemEmailTemplate;
use Illuminate\Database\Seeder;

class SystemEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'slug' => 'welcome',
                'name' => 'Bienvenue (inscription)',
                'subject' => 'Bienvenue sur Entraide, {{ name }} !',
                'content_html' => '<h1>Bienvenue sur Entraide, {{ name }} !</h1>
<p>Votre compte a bien été créé. Vous avez reçu <strong>100 points de bienvenue</strong> pour démarrer vos premiers échanges.</p>
<p><a href="{{ url }}">Découvrir les services</a></p>',
                'variables' => ['name', 'url'],
            ],
            [
                'slug' => 'new_message',
                'name' => 'Nouveau message reçu',
                'subject' => 'Nouveau message de {{ sender_name }}',
                'content_html' => '<h1>Nouveau message de {{ sender_name }}</h1>
<p>Bonjour {{ name }},</p>
<p><strong>{{ sender_name }}</strong> vous a envoyé un message à propos de l\'échange <strong>{{ transaction_title }}</strong>.</p>
<blockquote>{{ message_preview }}</blockquote>
<p><a href="{{ url }}">Voir la conversation</a></p>',
                'variables' => ['name', 'sender_name', 'transaction_title', 'message_preview', 'url'],
            ],
            [
                'slug' => 'transaction_status_changed',
                'name' => 'Mise à jour statut échange',
                'subject' => 'Mise à jour de votre échange — {{ status_label }}',
                'content_html' => '<h1>Mise à jour de votre échange</h1>
<p>Bonjour {{ name }},</p>
<p>Le statut de votre échange <strong>{{ title }}</strong> a changé : <strong>{{ status_label }}</strong>.</p>
<p>Points : {{ points }} pts</p>
<p><a href="{{ url }}">Voir la conversation</a></p>',
                'variables' => ['name', 'title', 'status_label', 'points', 'url'],
            ],
            [
                'slug' => 'ai_budget_exceeded',
                'name' => 'Alerte budget IA dépassé',
                'subject' => 'Alerte budget IA — {{ scenario_id }}',
                'content_html' => '<h1>Alerte budget IA</h1>
<p>Bonjour {{ name }},</p>
<p>Le scénario IA <strong>{{ scenario_id }}</strong> a dépassé son budget.</p>
<ul>
<li>Coût actuel : <strong>{{ current_cost }} €</strong></li>
<li>Limite : <strong>{{ budget_limit }} €</strong></li>
</ul>
<p><a href="{{ url }}">Voir les détails</a></p>',
                'variables' => ['name', 'scenario_id', 'current_cost', 'budget_limit', 'url'],
            ],
        ];

        foreach ($templates as $data) {
            SystemEmailTemplate::firstOrCreate(
                ['slug' => $data['slug']],
                $data,
            );
        }
    }
}
