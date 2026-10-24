<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                // TASK-1694 bugs E, G puis H — CE seeder etait la racine de la
                // ligne globale qui nommait la marque : il la codait EN DUR.
                // Le slug est renomme, et le texte nomme desormais
                // l'Organization par sa variable.
                'slug' => \App\Notifications\WelcomeNotification::SLUG,
                'name' => 'Affectation points suite inscription',
                'subject' => 'Bienvenue sur {{ organisation }}, {{ name }} !',
                'content_html' => "<h1>Bienvenue sur {{ organisation }}, {{ name }} !</h1>\n<p>Votre compte a bien été créé. Vous avez reçu <strong>100 points de bienvenue</strong> pour démarrer vos premiers échanges.</p>\n<p><a href=\"{{ url }}\">Découvrir les services</a></p>",
                'variables' => ['name', 'url'],
            ],
            [
                'slug' => 'transaction-status',
                'name' => 'Mise à jour statut échange',
                'subject' => 'Mise à jour de votre échange — {{ status_label }}',
                'content_html' => "<h1>Mise à jour de votre échange</h1>\n<p>Bonjour {{ name }},</p>\n<p>Le statut de votre échange <strong>{{ title }}</strong> a changé : <strong>{{ status_label }}</strong>.</p>\n<p>Points : {{ points }} pts</p>\n<p><a href=\"{{ url }}\">Voir la conversation</a></p>",
                'variables' => ['name', 'title', 'status_label', 'points', 'url'],
            ],
        ];

        foreach ($templates as $data) {
            EmailTemplate::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
