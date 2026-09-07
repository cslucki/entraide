<?php

use App\Models\UsageReference;
use Illuminate\Database\Migrations\Migration;

/**
 * TASK-1439 — la seule UsageReference REELLEMENT requise aujourd'hui (MASTER
 * Q66) : `shell_welcome`, FR et EN, version 1 publiee. Texte cure, verifie
 * contre les routes reelles (`/org/{slug}/register`) : aucun atelier, aucune
 * primitive absente. Idempotente : ne publie rien si une version existe deja
 * pour (surface, locale).
 */
return new class extends Migration
{
    public function up(): void
    {
        $texts = [
            'fr' => [
                'title' => 'Accueil public d\'une organisation',
                'content' => implode("\n", [
                    "Cet espace est l'accueil PUBLIC d'une organisation sur BouclePro : il s'adresse a un visiteur qui n'a pas de compte.",
                    "Ce qu'on peut y faire : comprendre ce que fait cette organisation et ce qu'elle propose, poser une question a son assistant IA public, et decider si l'on souhaite creer un compte pour la rejoindre.",
                    "Comment s'en servir : ecrire sa question ou son intention en quelques mots ; l'assistant repond a partir des seules informations publiques de l'organisation et oriente vers la prochaine etape utile.",
                    "Ce que cet espace ne fait pas : il n'inscrit personne, n'envoie rien, ne publie rien et n'a acces a aucun contenu prive (membres, boucles, dossiers, messages). Il ne connait pas les autres visiteurs.",
                    "Pour participer (rejoindre des boucles, echanger avec les membres, partager des dossiers), il faut creer un compte depuis la page d'inscription de l'organisation.",
                ]),
            ],
            'en' => [
                'title' => 'Public welcome of an organization',
                'content' => implode("\n", [
                    'This space is the PUBLIC welcome of an organization on BouclePro: it is meant for a visitor who has no account.',
                    'What you can do here: understand what this organization does and offers, ask its public AI assistant a question, and decide whether you want to create an account to join it.',
                    'How to use it: write your question or your intention in a few words; the assistant answers from the public information of the organization only, and points to the next useful step.',
                    'What this space does not do: it registers nobody, sends nothing, publishes nothing and has no access to any private content (members, loops, folders, messages). It does not know other visitors.',
                    'To take part (join loops, talk with members, share folders), create an account from the registration page of the organization.',
                ]),
            ],
        ];

        foreach ($texts as $locale => $text) {
            if (UsageReference::query()->forSurface(UsageReference::SURFACE_SHELL_WELCOME)->forLocale($locale)->exists()) {
                continue;
            }

            UsageReference::query()->create([
                'surface_key' => UsageReference::SURFACE_SHELL_WELCOME,
                'locale' => $locale,
                'title' => $text['title'],
                'content' => $text['content'],
                'version' => 1,
                'state' => UsageReference::STATE_PUBLISHED,
                'created_by' => null,
                'published_by' => null,
                'published_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        UsageReference::query()->forSurface(UsageReference::SURFACE_SHELL_WELCOME)->where('version', 1)->whereNull('created_by')->delete();
    }
};
