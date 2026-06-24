<?php

namespace Database\Seeders;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DashboardDemoSeeder extends Seeder
{
    private Organization $main;

    private Organization $launchpals;

    private array $users = [];

    private array $categories = [];

    private function fallbackCategory(): Category
    {
        $cat = Category::first();
        if (! $cat) {
            throw new RuntimeException('No categories available for DashboardDemoSeeder');
        }

        return $cat;
    }

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('DashboardDemoSeeder skipped: production environment.');

            return;
        }

        $this->main = Organization::where('slug', 'main')->first();
        $this->launchpals = Organization::where('slug', 'launchpals')->first();

        if (! $this->main) {
            $this->command->error('Organisation "main" introuvable.');

            return;
        }

        $this->loadUsers();
        $this->loadCategories();

        DB::transaction(fn () => $this->seedMainOrg());
        if ($this->launchpals) {
            DB::transaction(fn () => $this->seedLaunchPalsOrg());
        }

        $this->updateOrgConfig();

        $this->command->info('DashboardDemoSeeder terminé avec succès !');
    }

    private function loadUsers(): void
    {
        $emails = [
            'admin@bouclepro.test',
            'main.member1@bouclepro.test',
            'main.member2@bouclepro.test',
            'launchpals.member1@bouclepro.test',
            'launchpals.member2@bouclepro.test',
        ];

        $existing = User::whereIn('email', $emails)->get()->keyBy('email');

        $this->users = $existing->all();
    }

    private function loadCategories(): void
    {
        $this->categories = Category::all()->keyBy('slug')->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  MAIN ORGANIZATION (French)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedMainOrg(): void
    {
        $org = $this->main;
        $u = $this->users;

        $org->platform_name = 'BouclePro';
        $org->platform_tagline = "L'entraide professionnelle qui fait la différence";
        $org->locale = 'fr';
        $org->loop_mode = 'multi';
        $org->is_default = true;
        $org->save();

        // ── 1. Services ────────────────────────────────────────
        $svcDesign = $this->createService($u['main.member1@bouclepro.test'], $org, [
            'title' => 'Conception de logos',
            'description' => 'Création de logo professionnel pour votre activité. Trois propositions au choix, fichiers sources livrés (AI, PNG, SVG).',
            'category_id' => ($this->categories['visibilite-clients'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'points_cost' => 150,
        ]);

        $svcCoaching = $this->createService($u['main.member2@bouclepro.test'], $org, [
            'title' => 'Coaching en organisation personnelle',
            'description' => 'Séance individuelle pour mieux gérer votre temps et prioriser vos tâches. Méthodes adaptées aux indépendants et télétravailleurs.',
            'category_id' => ($this->categories['bien-etre-equilibre'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'points_cost' => 80,
        ]);

        $svcSite = $this->createService($u['admin@bouclepro.test'], $org, [
            'title' => 'Création de site vitrine',
            'description' => 'Site web vitrine complet, responsive et optimisé SEO. Livraison sous 5 jours ouvrés.',
            'category_id' => ($this->categories['creer-des-supports'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'points_cost' => 200,
        ]);

        // ── 2. Service Requests ─────────────────────────────────
        $this->createRequest($u['main.member1@bouclepro.test'], $org, [
            'title' => 'Recherche traducteur anglais-français',
            'description' => 'J\'ai besoin de traduire mon site vitrine (5 pages) de l\'anglais vers le français. Contenu technique modéré.',
            'category_id' => ($this->categories['ecrire-communiquer'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'budget_min' => 50,
            'budget_max' => 100,
            'deadline' => now()->addDays(14),
        ]);

        $this->createRequest($u['main.member2@bouclepro.test'], $org, [
            'title' => 'Aide à la création d\'entreprise',
            'description' => 'Je cherche un accompagnement pour les démarches administratives de création d\'entreprise : statuts, immatriculation, prévisionnel.',
            'category_id' => ($this->categories['lancer-son-activite'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'budget_min' => 80,
            'budget_max' => 150,
            'deadline' => now()->addDays(21),
        ]);

        // ── 3. Transaction: completed ──
        $tx1 = Transaction::withoutGlobalScopes()->create([
            'buyer_id' => $u['main.member1@bouclepro.test']->id,
            'seller_id' => $u['admin@bouclepro.test']->id,
            'service_id' => $svcSite->id,
            'organization_id' => $org->id,
            'points_proposed' => 200,
            'points_agreed' => 200,
            'status' => 'completed',
            'buyer_confirmed_at' => now()->subDays(3)->subHour(),
            'seller_confirmed_at' => now()->subDays(3),
            'completed_at' => now()->subDays(3),
        ]);

        $this->addPoints($u['main.member1@bouclepro.test'], $tx1, -200, 'exchange_spent');
        $this->addPoints($u['admin@bouclepro.test'], $tx1, 200, 'exchange_earned');

        $this->transactionMessages($tx1, [
            [$u['main.member1@bouclepro.test'], 'Bonjour, je souhaite un site vitrine pour présenter mon activité de design graphique.'],
            [$u['admin@bouclepro.test'], 'Bonjour, avec plaisir ! Je vous propose une maquette d\'ici deux jours pour valider la direction artistique.'],
            [$u['main.member1@bouclepro.test'], 'Parfait, le site est en ligne et correspond exactement à ce que je voulais. Merci !'],
        ]);

        Review::create([
            'transaction_id' => $tx1->id,
            'reviewer_id' => $u['main.member1@bouclepro.test']->id,
            'reviewed_id' => $u['admin@bouclepro.test']->id,
            'rating' => 5,
            'comment' => 'Travail de qualité, livré dans les délais. Je recommande !',
            'organization_id' => $org->id,
        ]);

        // ── 4. Loop "Entraide Création" ──
        $loopCreation = Loop::create([
            'organization_id' => $org->id,
            'name' => 'Entraide Création',
            'slug' => 'entraide-creation',
            'description' => 'Groupe d\'entraide pour les porteurs de projet et créateurs d\'entreprise. Partage de conseils, retours d\'expérience et coups de pouce.',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => $u['admin@bouclepro.test']->id,
        ]);

        LoopMember::create(['loop_id' => $loopCreation->id, 'user_id' => $u['admin@bouclepro.test']->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()->subDays(30), 'organization_id' => $org->id]);
        LoopMember::create(['loop_id' => $loopCreation->id, 'user_id' => $u['main.member1@bouclepro.test']->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()->subDays(28), 'organization_id' => $org->id]);
        LoopMember::create(['loop_id' => $loopCreation->id, 'user_id' => $u['main.member2@bouclepro.test']->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()->subDays(25), 'organization_id' => $org->id]);

        $this->loopMessages($loopCreation, [
            [$u['admin@bouclepro.test'], 'Bienvenue dans le groupe Entraide Création ! Partagez vos questions et avancements.'],
            [$u['main.member1@bouclepro.test'], 'Merci ! Je viens de lancer mon activité en design graphique, des conseils pour trouver mes premiers clients ?'],
            [$u['admin@bouclepro.test'], 'Félicitations ! Je te conseille de soigner ton profil et de proposer une offre de lancement.'],
            [$u['main.member2@bouclepro.test'], 'Excellent groupe ! Je suis en pleine création et les échanges sont très utiles.'],
        ]);

        // ── 5. Blog Posts ───────────────────────────────────────
        BlogPost::create([
            'user_id' => $u['admin@bouclepro.test']->id,
            'organization_id' => $org->id,
            'title' => 'Bien débuter sur la plateforme',
            'slug' => 'bien-debuter-sur-la-plateforme',
            'summary' => 'Conseils pour bien démarrer et tirer le meilleur parti de l\'entraide entre membres.',
            'content' => "<p>Vous venez de rejoindre la plateforme ? Voici quelques conseils pour bien débuter :</p><h2>Complétez votre profil</h2><p>Un profil complet inspire confiance et augmente vos chances d'échanges.</p><h2>Proposez vos services</h2><p>Que vous soyez expert ou débutant, vous avez des compétences à partager.</p><h2>Participez aux boucles</h2><p>Les groupes d'entraide sont un excellent moyen d'échanger et d'apprendre.</p>",
            'status' => 'published',
            'published_at' => now()->subDays(7),
            'views_count' => 42,
        ]);

        BlogPost::create([
            'user_id' => $u['main.member1@bouclepro.test']->id,
            'organization_id' => $org->id,
            'title' => 'Les avantages de l\'entraide professionnelle',
            'slug' => 'avantages-entraide-professionnelle',
            'summary' => 'Comment l\'entraide entre professionnels peut booster votre activité.',
            'content' => "<p>L'entraide professionnelle est un levier puissant pour développer son réseau et ses compétences.</p><h2>Gagner en visibilité</h2><p>En proposant votre aide, vous vous faites connaître auprès d'autres professionnels.</p><h2>Développer vos compétences</h2><p>En aidant les autres, vous consolidez et approfondissez vos propres connaissances.</p><h2>Créer des opportunités</h2><p>Les échanges débouchent souvent sur des collaborations durables.</p>",
            'status' => 'published',
            'published_at' => now()->subDays(3),
            'views_count' => 25,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  LAUNCHPALS ORGANIZATION (English — mono-loop with LaunchPalsCircle)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedLaunchPalsOrg(): void
    {
        $org = $this->launchpals;
        $u = $this->users;

        $org->platform_name = 'LaunchPals';
        $org->platform_tagline = 'Where art, science, and technology launch together';
        $org->locale = 'en';
        $org->loop_mode = 'mono';
        $org->is_default = false;
        $org->blog_naming = 'b2c';
        $org->save();

        // ── 1. Services (art, science & tech themed) ────────────
        $svcPortfolio = $this->createService($u['launchpals.member1@bouclepro.test'], $org, [
            'title' => 'Artist Portfolio Website',
            'description' => 'Custom portfolio website for artists and creators. Responsive design, gallery integration, and SEO-optimized. Built with modern web technologies.',
            'category_id' => ($this->categories['creer-des-supports'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'points_cost' => 250,
        ]);

        $svcDataViz = $this->createService($u['launchpals.member2@bouclepro.test'], $org, [
            'title' => 'Data Visualization Consulting',
            'description' => 'Turn your research data into compelling visual stories. Specialized in scientific data visualization, infographics, and interactive dashboards.',
            'category_id' => ($this->categories['outils-numeriques'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'points_cost' => 180,
        ]);

        $svcWorkshop = $this->createService($u['launchpals.member1@bouclepro.test'], $org, [
            'title' => 'Creative Coding Workshop',
            'description' => 'hands-on workshop exploring the intersection of art and code. Learn generative art, interactive installations, and creative expression through programming.',
            'category_id' => ($this->categories['formation'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'in_person',
            'points_cost' => 120,
        ]);

        // ── 2. Service Requests ─────────────────────────────────
        $this->createRequest($u['launchpals.member2@bouclepro.test'], $org, [
            'title' => 'Looking for a Science Illustrator',
            'description' => 'I need illustrations for a research paper on neural networks. Looking for someone who can translate complex concepts into clear visuals.',
            'category_id' => ($this->categories['visibilite-clients'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'budget_min' => 100,
            'budget_max' => 200,
            'deadline' => now()->addDays(21),
        ]);

        $this->createRequest($u['launchpals.member1@bouclepro.test'], $org, [
            'title' => 'Seeking AR/VR Developer for Art Installation',
            'description' => 'Planning an interactive art installation using augmented reality. Need help with Unity development and motion tracking integration.',
            'category_id' => ($this->categories['depannage-informatique'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'both',
            'budget_min' => 200,
            'budget_max' => 400,
            'deadline' => now()->addDays(30),
        ]);

        // ── 3. Transaction: completed (Member 1 buys Data Viz from Member 2) ──
        $txLp = Transaction::withoutGlobalScopes()->create([
            'buyer_id' => $u['launchpals.member1@bouclepro.test']->id,
            'seller_id' => $u['launchpals.member2@bouclepro.test']->id,
            'service_id' => $svcDataViz->id,
            'organization_id' => $org->id,
            'points_proposed' => 180,
            'points_agreed' => 180,
            'status' => 'completed',
            'buyer_confirmed_at' => now()->subDays(2)->subHour(),
            'seller_confirmed_at' => now()->subDays(2),
            'completed_at' => now()->subDays(2),
        ]);

        $this->addPoints($u['launchpals.member1@bouclepro.test'], $txLp, -180, 'exchange_spent');
        $this->addPoints($u['launchpals.member2@bouclepro.test'], $txLp, 180, 'exchange_earned');

        $this->transactionMessages($txLp, [
            [$u['launchpals.member1@bouclepro.test'], 'Hi! I need a visualization for my community engagement data. Can you help me make sense of the survey results?'],
            [$u['launchpals.member2@bouclepro.test'], 'Absolutely! I love working with community data. Let me show you a few concepts for interactive dashboards.'],
            [$u['launchpals.member1@bouclepro.test'], 'The dashboard looks incredible! Members will love exploring their impact through this. Thank you!'],
        ]);

        Review::create([
            'transaction_id' => $txLp->id,
            'reviewer_id' => $u['launchpals.member1@bouclepro.test']->id,
            'reviewed_id' => $u['launchpals.member2@bouclepro.test']->id,
            'rating' => 5,
            'comment' => 'Beautiful work, very professional and insightful. Exceeded expectations!',
            'organization_id' => $org->id,
        ]);

        // ── 4. LaunchPalsCircle — monoboucle, the primary loop ──
        $loopLp = Loop::create([
            'organization_id' => $org->id,
            'name' => 'LaunchPalsCircle',
            'slug' => 'launchpalscircle',
            'description' => 'The main community loop for LaunchPals. A space where artists, scientists, and technologists connect, collaborate, and launch projects together.',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => $u['launchpals.member1@bouclepro.test']->id,
        ]);

        LoopMember::create(['loop_id' => $loopLp->id, 'user_id' => $u['launchpals.member1@bouclepro.test']->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()->subDays(30), 'organization_id' => $org->id]);
        LoopMember::create(['loop_id' => $loopLp->id, 'user_id' => $u['launchpals.member2@bouclepro.test']->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()->subDays(28), 'organization_id' => $org->id]);

        // Chatloop discussion: member2 discovers LaunchPals, admin explains its potential
        $this->loopMessages($loopLp, [
            [$u['launchpals.member2@bouclepro.test'], 'Hey! I just joined LaunchPals. The concept sounds amazing — a community where art, science, and tech come together. Can you tell me more about the vision?'],
            [$u['launchpals.member1@bouclepro.test'], 'Welcome! The idea is simple but powerful: we believe the most innovative projects happen at the intersection of disciplines. A painter collaborating with a data scientist. A musician working with an AI researcher. LaunchPals is the space where these connections happen naturally.'],
            [$u['launchpals.member2@bouclepro.test'], 'That resonates with me a lot! I\'ve been doing data visualization for scientific papers but always wanted to work on more creative projects. What\'s the first step?'],
            [$u['launchpals.member1@bouclepro.test'], 'Start by exploring the services available — you\'ll find everything from creative coding workshops to scientific illustration. And feel free to post your own request! The beauty of LaunchPals is that every member brings a unique perspective.'],
            [$u['launchpals.member2@bouclepro.test'], 'I already see so much potential. I can imagine artists using data to create interactive installations, or scientists communicating their research through visual art. This could really change how we think about collaboration!'],
            [$u['launchpals.member1@bouclepro.test'], 'That\'s exactly the vision! LaunchPals isn\'t just a platform — it\'s a movement to break down the silos between disciplines. Together we can create projects that none of us could build alone.'],
        ]);

        // ── 5. Blog Posts (English — inspired by artscilab.utdallas.edu) ──
        BlogPost::create([
            'user_id' => $u['launchpals.member1@bouclepro.test']->id,
            'organization_id' => $org->id,
            'title' => 'CONLI: Proud to Be Nomads — Collaborating Across Non-Local Intelligences',
            'slug' => 'conli-proud-to-be-nomads',
            'summary' => 'Exploring the CONLI initiative — reimagining intelligence as distributed, hybrid, and nomadic across human, animal, vegetal, and artificial forms.',
            'content' => "<p>The CONLI Initiative (Collaboration of Non-Local Intelligences) reimagines intelligence as distributed, hybrid, and nomadic — spanning human, animal, vegetal, and artificial forms.</p><h2>Beyond Local Thinking</h2><p>Rooted in a deep critique of the historical marginalization of nomadic peoples, CONLI calls for embracing mobile, adaptive ways of knowing. In the digital age, humanity is becoming nomadic again.</p><h2>Non-Local Collaboration</h2><p>The initiative seeks to orchestrate interactions among intelligences that transcend spatial, temporal, and disciplinary boundaries. Guided by ecological longevity and poetic vision, CONLI rejects fixedness in favor of movement, multiplicity, and mutual transformation.</p><h2>Why It Matters</h2><p>As global challenges accelerate, reliance solely on local human intelligence is insufficient. Collaborating across non-local intelligences opens new frontiers of problem-solving and system-level innovation.</p><p>Read more on <a href=\"https://artscilab.utdallas.edu/2025/11/05/conli-manyfisto-proud-to-be-nomads/\">ArtSciLab's original post</a>.</p>",
            'status' => 'published',
            'published_at' => now()->subDays(10),
            'views_count' => 67,
        ]);

        BlogPost::create([
            'user_id' => $u['launchpals.member2@bouclepro.test']->id,
            'organization_id' => $org->id,
            'title' => 'Extreme Weather Soundscape — Listening to Climate Change Through Art',
            'slug' => 'extreme-weather-soundscape',
            'summary' => 'A sonic exploration of extreme weather phenomena, translating climate data into immersive auditory experiences.',
            'content' => "<p>What does climate change sound like? The Extreme Weather Soundscape project translates meteorological data into sonic compositions that reveal the patterns and rhythms of our changing planet.</p><h2>Data Sonification</h2><p>By converting weather data — temperature shifts, wind patterns, precipitation intensity — into sound, the project makes abstract climate metrics emotionally tangible. Listening to a soundscape of extreme weather creates an intuitive understanding that raw numbers cannot convey.</p><h2>Art Meets Climate Science</h2><p>This collaboration between sound artists and climate scientists demonstrates how interdisciplinary work can bridge the gap between data and human experience.</p><p>Learn more on <a href=\"https://artscilab.utdallas.edu/2026/02/17/extreme-weather-soundscape/\">ArtSciLab's original post</a>.</p>",
            'status' => 'published',
            'published_at' => now()->subDays(5),
            'views_count' => 34,
        ]);

        BlogPost::create([
            'user_id' => $u['launchpals.member1@bouclepro.test']->id,
            'organization_id' => $org->id,
            'title' => 'Announcing Aperio — An Open-Source AI Companion for Troubling Times',
            'slug' => 'announcing-aperio-aiperitif',
            'summary' => 'Introducing Aperio, a poetic AI companion born from the collaboration between Roger Malina, Fred the Heretic, and the ArtSciLab community.',
            'content' => "<p>We are pleased to announce Aperio — an open-source AI companion for worrying times. Rooted in the Latin <em>aperire</em> (to open), Aperio is designed to open minds rather than simply process data.</p><h2>What Makes Aperio Different</h2><p>Aperio draws on everything Roger Malina and Aperio have discussed — exploring the idea that limited intelligence sometimes serves better than augmented intelligence. From moral judgment in grey zones to the power of creative constraints, Aperio embodies a different philosophy of AI.</p><h2>Fred the Heretic</h2><p>Alongside Aperio, the project includes Fred the Heretic (FTH), a poetry-generating AI that answers only in verse, using words and concepts from the poet Fred Turner. Together they explore the intersection of poetry, code, and human meaning.</p><p>Discover more on <a href=\"https://artscilab.utdallas.edu/2025/06/16/announcing-the-aperio-aiperitif/\">ArtSciLab's original post</a>.</p>",
            'status' => 'published',
            'published_at' => now()->subDays(1),
            'views_count' => 52,
        ]);
    }

    private function updateOrgConfig(): void
    {
        $u = $this->users;

        $this->main->admin_id = $u['admin@bouclepro.test']->id;
        $this->main->save();

        if ($this->launchpals) {
            $this->launchpals->admin_id = $u['launchpals.member1@bouclepro.test']->id;

            $circle = Loop::where('slug', 'launchpalscircle')
                ->where('organization_id', $this->launchpals->id)
                ->first();

            if ($circle) {
                $this->launchpals->primary_loop_id = $circle->id;
            }

            $this->launchpals->save();
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private function createService(User $user, Organization $org, array $data): Service
    {
        return Service::withoutGlobalScopes()->create(array_merge($data, [
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => 'active',
        ]));
    }

    private function createRequest(User $user, Organization $org, array $data): ServiceRequest
    {
        return ServiceRequest::withoutGlobalScopes()->create(array_merge($data, [
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => 'open',
        ]));
    }

    private function addPoints(User $user, Transaction $tx, int $delta, string $reason): void
    {
        PointLedger::create([
            'user_id' => $user->id,
            'transaction_id' => $tx->id,
            'organization_id' => $tx->organization_id,
            'delta' => $delta,
            'reason' => $reason,
        ]);
        if ($delta > 0) {
            $user->increment('points_balance', $delta);
        } else {
            $user->decrement('points_balance', abs($delta));
        }
    }

    private function transactionMessages(Transaction $tx, array $messages): void
    {
        foreach ($messages as [$sender, $body]) {
            Message::create([
                'transaction_id' => $tx->id,
                'sender_id' => $sender->id,
                'organization_id' => $tx->organization_id,
                'body' => $body,
                'type' => 'system',
                'read_at' => now()->subMinutes(rand(10, 1440)),
            ]);
        }
    }

    private function loopMessages(Loop $loop, array $messages): void
    {
        foreach ($messages as [$sender, $body]) {
            LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'organization_id' => $loop->organization_id,
                'body' => $body,
                'type' => 'system',
            ]);
        }
    }
}
