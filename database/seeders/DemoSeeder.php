<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Community;
use App\Models\Message;
use App\Models\PointLedger;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Scopes\BelongsToTenantScope;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Communauté de démo : CPME ────────────────────────────────────────
        $community = Community::where('slug', 'cpme')->first();
        if (! $community) {
            $this->command->error('Communauté "cpme" introuvable. Lancez d\'abord : php artisan db:seed --class=CommunitySeeder');
            return;
        }

        // ── Récupérer ou créer Cyril ─────────────────────────────────────────
        $cyril = User::where('email', 'cslucki@gmail.com')->first();
        if (! $cyril) {
            $cyril = User::create([
                'community_id'   => $community->id,
                'name'           => 'Cyril',
                'email'          => 'cslucki@gmail.com',
                'password'       => Hash::make('ChangeMe2026!'),
                'points_balance' => 0,
                'is_available'   => true,
                'is_admin'       => true,
            ]);
            $cyril->forceFill(['email_verified_at' => now()])->save();
            $this->command->info('Utilisateur Cyril créé. Mot de passe : ChangeMe2026!');
        } else {
            $cyril->update([
                'community_id'   => $community->id,
                'points_balance' => 350,
                'is_available'   => true,
                'is_admin'       => true,
            ]);
            $cyril->forceFill(['email_verified_at' => now()])->save();
        }

        // ── Catégories ───────────────────────────────────────────────────────
        $tech       = Category::where('slug', 'tech-digital')->first();
        $design     = Category::where('slug', 'design')->first();
        $marketing  = Category::where('slug', 'marketing')->first();
        $redaction  = Category::where('slug', 'redaction')->first();
        $conseil    = Category::where('slug', 'conseil')->first();
        $formation  = Category::where('slug', 'formation')->first();
        $traduction = Category::where('slug', 'traduction')->first();

        if (! $tech) {
            $this->command->error('Catégories introuvables. Lancez : php artisan db:seed --class=CategorySeeder');
            return;
        }

        // ── Créer Alice, Bob, Carol, Dave ────────────────────────────────────
        $alice = $this->createOrUpdateUser([
            'email'         => 'alice@bouclepro.com',
            'name'          => 'Alice Moreau',
            'community_id'  => $community->id,
            'points_balance'=> 220,
            'bio'           => 'Designer UI/UX freelance avec 8 ans d\'expérience. Spécialisée identité visuelle et Figma.',
            'location'      => 'Paris (75)',
        ]);

        $bob = $this->createOrUpdateUser([
            'email'         => 'bob@bouclepro.com',
            'name'          => 'Bob Leclerc',
            'community_id'  => $community->id,
            'points_balance'=> 180,
            'bio'           => 'Consultant marketing digital. J\'aide les indépendants à développer leur présence en ligne.',
            'location'      => 'Lyon (69)',
        ]);

        $carol = $this->createOrUpdateUser([
            'email'         => 'carol@bouclepro.com',
            'name'          => 'Carol Petit',
            'community_id'  => $community->id,
            'points_balance'=> 90,
            'bio'           => 'Ex-DG de PME, maintenant coach et consultante en stratégie d\'entreprise.',
            'location'      => 'Bordeaux (33)',
        ]);

        $dave = $this->createOrUpdateUser([
            'email'         => 'dave@bouclepro.com',
            'name'          => 'Dave Nguyen',
            'community_id'  => $community->id,
            'points_balance'=> 130,
            'bio'           => 'Traducteur FR/EN certifié. Relecture de documents techniques et juridiques.',
            'location'      => 'Nantes (44)',
        ]);

        // ── Services de Cyril ────────────────────────────────────────────────
        $svcCyril1 = $this->service($cyril, $community, [
            'title'         => 'Audit de sécurité WordPress',
            'description'   => "Je réalise un audit complet de votre site WordPress : plugins vulnérables, configuration serveur, permissions fichiers. Livraison d'un rapport PDF actionnable sous 48h.",
            'category_id'   => $tech->id,
            'delivery_mode' => 'remote',
            'points_cost'   => 150,
        ]);

        $svcCyril2 = $this->service($cyril, $community, [
            'title'         => 'Formation Git & GitHub pour débutants',
            'description'   => "Session de 2h en visio pour maîtriser les bases de Git : commits, branches, pull requests, résolution de conflits. Idéal pour les freelances qui veulent versionner leur travail.",
            'category_id'   => $formation->id,
            'delivery_mode' => 'remote',
            'points_cost'   => 80,
        ]);

        $this->service($cyril, $community, [
            'title'         => 'Mise en place d\'un espace Notion tout-en-un',
            'description'   => "Je configure votre Notion : pipeline commercial, tableaux de bord, automatisations et templates sur-mesure. Prise en main garantie.",
            'category_id'   => $tech->id,
            'delivery_mode' => 'remote',
            'points_cost'   => 120,
        ]);

        // ── Services d'Alice ─────────────────────────────────────────────────
        $svcAlice1 = $this->service($alice, $community, [
            'title'         => 'Création de logo professionnel',
            'description'   => "Identité visuelle de A à Z : 3 propositions de logo, révisions illimitées, fichiers sources livrés (AI, SVG, PNG). Délai : 5 jours ouvrés.",
            'category_id'   => $design->id,
            'delivery_mode' => 'remote',
            'points_cost'   => 200,
        ]);

        $this->service($alice, $community, [
            'title'         => 'Maquettes UI Figma — landing page',
            'description'   => "Conception de votre landing page sur Figma : wireframe, maquette desktop + mobile, composants réutilisables et guide de style livré.",
            'category_id'   => $design->id,
            'delivery_mode' => 'remote',
            'points_cost'   => 120,
        ]);

        // ── Services de Bob ──────────────────────────────────────────────────
        $svcBob1 = $this->service($bob, $community, [
            'title'         => 'Stratégie LinkedIn pour indépendants',
            'description'   => "Audit de profil, ligne éditoriale, plan de contenu 4 semaines et 3 posts rédigés clés en main. Sortez enfin de l'invisibilité LinkedIn.",
            'category_id'   => $marketing->id,
            'delivery_mode' => 'remote',
            'points_cost'   => 100,
        ]);

        $this->service($bob, $community, [
            'title'         => 'Rédaction de newsletter mensuelle',
            'description'   => "Je rédige votre newsletter : angle éditorial, corps de texte, call-to-action, objet optimisé. Livraison sous 3 jours avec 1 révision incluse.",
            'category_id'   => $redaction->id,
            'delivery_mode' => 'remote',
            'points_cost'   => 60,
        ]);

        // ── Services de Carol ────────────────────────────────────────────────
        $this->service($carol, $community, [
            'title'         => 'Session stratégie — définir ses offres freelance',
            'description'   => "Atelier 1h30 pour clarifier votre positionnement, structurer vos offres et fixer vos tarifs. Vous repartez avec un plan d'action concret.",
            'category_id'   => $conseil->id,
            'delivery_mode' => 'both',
            'points_cost'   => 180,
        ]);

        // ── Services de Dave ─────────────────────────────────────────────────
        $svcDave1 = $this->service($dave, $community, [
            'title'         => 'Traduction FR → EN de documents professionnels',
            'description'   => "Traduction certifiée de contrats, CV, présentations. 90 points par tranche de 500 mots. Délai standard : 48h.",
            'category_id'   => $traduction->id,
            'delivery_mode' => 'remote',
            'points_cost'   => 90,
        ]);

        $this->service($dave, $community, [
            'title'         => 'Relecture et correction de textes (FR)',
            'description'   => "Relecture orthographique, grammaticale et stylistique. Idéal avant publication d'un article, d'une proposition commerciale ou d'un rapport.",
            'category_id'   => $redaction->id,
            'delivery_mode' => 'remote',
            'points_cost'   => 50,
        ]);

        // ── Demandes ouvertes ────────────────────────────────────────────────
        $this->request($cyril, $community, [
            'title'       => 'Recherche coach pour préparer une levée de fonds',
            'description' => "Je prépare une présentation investisseurs pour un projet SaaS B2B. Cherche quelqu'un ayant déjà accompagné des entrepreneurs dans ce processus pour relire mon pitch deck.",
            'category_id' => $conseil->id,
            'budget_min'  => 100,
            'budget_max'  => 200,
            'deadline'    => now()->addDays(14),
        ]);

        $this->request($alice, $community, [
            'title'       => 'Besoin d\'un développeur pour intégrer Stripe',
            'description' => "J'ai un site Laravel 11 et dois intégrer Stripe Connect pour gérer des paiements entre utilisateurs. Cherche quelqu'un d'expérimenté pour du pair-programming.",
            'category_id' => $tech->id,
            'budget_min'  => 80,
            'budget_max'  => 150,
            'deadline'    => now()->addDays(7),
        ]);

        $this->request($bob, $community, [
            'title'       => 'Traduction de mes CGV en anglais',
            'description' => "Mes conditions générales de vente (3 pages, ~1500 mots) doivent être traduites en anglais pour mes clients internationaux.",
            'category_id' => $traduction->id,
            'budget_min'  => 90,
            'budget_max'  => 120,
            'deadline'    => now()->addDays(10),
        ]);

        // ── Transaction 1 : Alice achète "Audit WordPress" à Cyril — COMPLETED ──
        $tx1 = $this->findOrCreateTransaction([
            'buyer_id'  => $alice->id,
            'seller_id' => $cyril->id,
            'service_id'=> $svcCyril1->id,
        ], [
            'community_id'        => $community->id,
            'points_proposed'     => 150,
            'points_agreed'       => 150,
            'status'              => 'completed',
            'buyer_confirmed_at'  => now()->subDays(5)->subHour(),
            'seller_confirmed_at' => now()->subDays(5),
            'completed_at'        => now()->subDays(5),
        ]);

        if ($tx1->wasRecentlyCreated) {
            $this->addPoints($alice,  $tx1, -150, 'exchange_spent');
            $this->addPoints($cyril, $tx1, +150, 'exchange_earned');

            $this->messages($tx1, [
                [$alice,  'Bonjour Cyril ! Je suis intéressée par votre audit WordPress, j\'ai un site e-commerce assez sensible.', true],
                [$cyril, 'Bonjour Alice, avec plaisir ! Pouvez-vous me donner accès en lecture seule à votre hébergement pour commencer l\'analyse ?', true],
                [$alice,  'C\'est fait, je vous ai envoyé les accès par email. Merci de votre réactivité !', true],
                [$cyril, 'Rapport envoyé ! En résumé : 3 plugins à mettre à jour en urgence et passage en HTTPS strict recommandé. Le PDF détaille tout.', true],
                [$alice,  'Excellent travail, rapport très clair et actionnable. Je valide l\'échange !', true],
            ]);

            Review::create(['transaction_id' => $tx1->id, 'reviewer_id' => $alice->id,  'reviewed_id' => $cyril->id, 'rating' => 5, 'comment' => 'Audit très professionnel, rapport détaillé et livré avant le délai. Je recommande vivement !']);
            Review::create(['transaction_id' => $tx1->id, 'reviewer_id' => $cyril->id, 'reviewed_id' => $alice->id,  'rating' => 5, 'comment' => 'Échange fluide, Alice est très réactive et bien organisée. Super collaboration.']);
            $cyril->recalculateRating();
            $alice->recalculateRating();
        }

        // ── Transaction 2 : Bob achète "Formation Git" à Cyril — COMPLETED ──
        $tx2 = $this->findOrCreateTransaction([
            'buyer_id'  => $bob->id,
            'seller_id' => $cyril->id,
            'service_id'=> $svcCyril2->id,
        ], [
            'community_id'        => $community->id,
            'points_proposed'     => 80,
            'points_agreed'       => 80,
            'status'              => 'completed',
            'buyer_confirmed_at'  => now()->subDays(2)->subHour(),
            'seller_confirmed_at' => now()->subDays(2),
            'completed_at'        => now()->subDays(2),
        ]);

        if ($tx2->wasRecentlyCreated) {
            $this->addPoints($bob,   $tx2, -80, 'exchange_spent');
            $this->addPoints($cyril, $tx2, +80, 'exchange_earned');

            $this->messages($tx2, [
                [$bob,   'Salut Cyril, j\'utilise Git depuis 6 mois mais les branches me font peur. Ta formation c\'est pour quel niveau ?', true],
                [$cyril, 'C\'est parfait pour toi ! On part de zéro sur les branches et on finit par simuler un vrai workflow de projet. Quand es-tu dispo ?', true],
                [$bob,   'Jeudi 14h ça te va ?', true],
                [$cyril, 'Parfait, je t\'envoie le lien Zoom.', true],
                [$bob,   'Très bonne session ! Je comprends enfin ce que je faisais de travers avec les merges. Merci !', true],
            ]);

            Review::create(['transaction_id' => $tx2->id, 'reviewer_id' => $bob->id,   'reviewed_id' => $cyril->id, 'rating' => 5, 'comment' => 'Pédagogue et patient, il adapte son discours à ton niveau. La 2h est passée en 20 minutes tellement c\'est bien expliqué.']);
            Review::create(['transaction_id' => $tx2->id, 'reviewer_id' => $cyril->id, 'reviewed_id' => $bob->id,   'rating' => 4, 'comment' => 'Bob est curieux et motivé, agréable à former. Bonne progression sur la session.']);
            $cyril->recalculateRating();
            $bob->recalculateRating();
        }

        // ── Transaction 3 : Cyril achète "Logo" à Alice — EN COURS (accepted) ──
        $tx3 = $this->findOrCreateTransaction([
            'buyer_id'  => $cyril->id,
            'seller_id' => $alice->id,
            'service_id'=> $svcAlice1->id,
        ], [
            'community_id'    => $community->id,
            'points_proposed' => 200,
            'points_agreed'   => 200,
            'status'          => 'accepted',
        ]);

        if ($tx3->wasRecentlyCreated) {
            $this->messages($tx3, [
                [$cyril, 'Bonjour Alice ! Je lance BouclePro et j\'ai besoin d\'un logo qui reflète l\'idée de réseau et de réciprocité. Des tons bleu/vert m\'inspirent.', true],
                [$alice,  'Super projet, j\'adore le concept ! Je te prépare 3 directions créatives d\'ici vendredi. Tu as des refs visuelles qui t\'inspirent ?', true],
                [$cyril, 'Quelque chose entre Notion et Airbnb dans l\'esprit — épuré, moderne, mémorable. Je t\'envoie un board Miro.', false],
            ]);
        }

        // ── Transaction 4 : Carol demande "Formation Git" à Cyril — EN ATTENTE ──
        $tx4 = $this->findOrCreateTransaction([
            'buyer_id'  => $carol->id,
            'seller_id' => $cyril->id,
            'service_id'=> $svcCyril2->id,
        ], [
            'community_id'    => $community->id,
            'points_proposed' => 80,
            'status'          => 'pending',
        ]);

        if ($tx4->wasRecentlyCreated) {
            $this->messages($tx4, [
                [$carol, 'Bonjour Cyril, j\'accompagne des dirigeants qui me demandent souvent des conseils sur Git. J\'aimerais mieux comprendre l\'outil pour en parler avec eux.', false],
            ]);
        }

        // ── Transaction 5 : Bob achète "Traduction CGV" à Dave — BUYER_DONE ──
        $tx5 = $this->findOrCreateTransaction([
            'buyer_id'  => $bob->id,
            'seller_id' => $dave->id,
            'service_id'=> $svcDave1->id,
        ], [
            'community_id'       => $community->id,
            'points_proposed'    => 90,
            'points_agreed'      => 90,
            'status'             => 'buyer_done',
            'buyer_confirmed_at' => now()->subHours(6),
        ]);

        if ($tx5->wasRecentlyCreated) {
            $this->messages($tx5, [
                [$bob,  'Bonjour Dave, j\'ai 3 pages de CGV à traduire en anglais, c\'est urgent pour un client US.', true],
                [$dave, 'Aucun problème, envoyez-moi le document. Délai : 48h maximum.', true],
                [$dave, 'Traduction terminée et relue ! Je vous envoie le fichier Word par email.', true],
                [$bob,  'Reçu, c\'est parfait ! J\'ai validé la prestation de mon côté. Merci Dave !', false],
            ]);
        }

        $this->command->info('DemoSeeder terminé avec succès !');
        $this->command->info('Communauté : bouclepro.com/cpme');
        $this->command->info('Comptes demo (mdp: demo2026) : alice@bouclepro.com · bob@bouclepro.com · carol@bouclepro.com · dave@bouclepro.com');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createOrUpdateUser(array $data): User
    {
        $user = User::where('email', $data['email'])->first();
        if ($user) {
            $user->update($data);
        } else {
            $user = User::create(array_merge($data, [
                'password'       => Hash::make('demo2026'),
                'is_available'   => true,
            ]));
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        return $user;
    }

    private function service(User $user, Community $community, array $data): Service
    {
        return Service::firstOrCreate(
            ['title' => $data['title'], 'user_id' => $user->id],
            array_merge($data, ['community_id' => $community->id, 'status' => 'active'])
        );
    }

    private function request(User $user, Community $community, array $data): ServiceRequest
    {
        return ServiceRequest::firstOrCreate(
            ['title' => $data['title'], 'user_id' => $user->id],
            array_merge($data, ['community_id' => $community->id, 'status' => 'open'])
        );
    }

    private function findOrCreateTransaction(array $match, array $data): Transaction
    {
        $existing = Transaction::withoutGlobalScopes()->where($match)->first();
        if ($existing) {
            return $existing;
        }
        $tx = Transaction::withoutGlobalScopes()->create(array_merge($match, $data));
        $tx->wasRecentlyCreated = true;
        return $tx;
    }

    private function addPoints(User $user, Transaction $tx, int $delta, string $reason): void
    {
        PointLedger::create(['user_id' => $user->id, 'transaction_id' => $tx->id, 'delta' => $delta, 'reason' => $reason]);
        if ($delta > 0) {
            $user->increment('points_balance', $delta);
        } else {
            $user->decrement('points_balance', abs($delta));
        }
    }

    private function messages(Transaction $tx, array $messages): void
    {
        foreach ($messages as [$sender, $body, $isRead]) {
            Message::create([
                'transaction_id' => $tx->id,
                'sender_id'      => $sender->id,
                'body'           => $body,
                'read_at'        => $isRead ? now()->subMinutes(rand(10, 1440)) : null,
            ]);
        }
    }
}
