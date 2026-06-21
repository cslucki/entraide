<?php

namespace Database\Seeders;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Report;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DashboardDemoSeeder extends Seeder
{
    private Organization $main;

    private Organization $cpme;

    private array $users = [];

    private array $categories = [];

    private ?Category $_fallback = null;

    private function fallbackCategory(): Category
    {
        if (! $this->_fallback) {
            $this->_fallback = Category::first();
            if (! $this->_fallback) {
                $this->command->error('Aucune catégorie trouvée dans la base.');
                throw new \RuntimeException('No categories available for DashboardDemoSeeder');
            }
        }

        return $this->_fallback;
    }

    public function run(): void
    {
        $this->main = Organization::where('slug', 'main')->first();
        $this->cpme = Organization::where('slug', 'cpme')->first();

        if (! $this->main) {
            $this->command->error('Organisation "main" introuvable.');

            return;
        }

        $this->loadUsers();
        $this->loadCategories();

        DB::transaction(fn () => $this->seedMainOrg());
        DB::transaction(fn () => $this->seedCpmeOrg());

        $this->command->info('DashboardDemoSeeder terminé avec succès !');
    }

    private function loadUsers(): void
    {
        $emails = [
            'cslucki@gmail.com',
            'secretariat-assistance@proton.me',
            'jmhoussay@orange.fr',
            'mr.gassan@gmail.com',
            'vin.100.gay@gmail.com',
            'helena.ds@icloud.com',
            'antoine.bidet@bmsinvest.fr',
            'contact@franceportugal-traductions.com',
            'ericyas@gmail.com',
            'julieborgettopro@gmail.com',
            'qa-admin@bouclepro.local',
            'qa-member1@bouclepro.local',
            'qa-member2@bouclepro.local',
            'qa-cpme1@bouclepro.local',
            'qa-cpme2@bouclepro.local',
        ];

        $existing = User::whereIn('email', $emails)->get()->keyBy('email');

        foreach ($emails as $email) {
            if (! isset($existing[$email])) {
                $existing[$email] = User::create([
                    'email' => $email,
                    'name' => explode('@', $email)[0],
                    'password' => Hash::make('password'),
                    'is_available' => true,
                    'email_verified_at' => now(),
                    'organization_id' => $this->main->id,
                ]);
            }
        }

        $this->users = $existing->all();
    }

    private function loadCategories(): void
    {
        $this->categories = Category::all()->keyBy('slug')->all();
    }

    private function seedMainOrg(): void
    {
        $org = $this->main;
        $u = $this->users;

        // ── 1. Services ────────────────────────────────────────
        $svcCoaching = $this->createService($u['cslucki@gmail.com'], $org, [
            'title' => 'Coaching LinkedIn',
            'description' => 'Optimisation complète de votre profil LinkedIn, stratégie de contenu et techniques de networking pour développer votre activité. Séance individuelle de 1h30 en visio.',
            'category_id' => $this->categories['visibilite-clients']->id,
            'delivery_mode' => 'remote',
            'points_cost' => 120,
        ]);

        $svcCreation = $this->createService($u['secretariat-assistance@proton.me'], $org, [
            'title' => 'Accompagnement création entreprise',
            'description' => 'De l\'idée au business plan, je vous accompagne dans toutes les étapes de la création de votre entreprise : études de marché, statuts juridiques, prévisionnel financier.',
            'category_id' => ($this->categories['lancer-son-activite'] ?? $this->categories['conseil'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'both',
            'points_cost' => 200,
        ]);

        $svcImpots = $this->createService($u['jmhoussay@orange.fr'], $org, [
            'title' => 'Aide déclaration impôts',
            'description' => 'Je vous aide à remplir votre déclaration de revenus, vérifier les crédits d\'impôt et optimiser votre fiscalité. Service accessible aux débutants.',
            'category_id' => ($this->categories['aides-demarches'] ?? $this->categories['conseil'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'points_cost' => 60,
        ]);

        $svcGuitare = $this->createService($u['mr.gassan@gmail.com'], $org, [
            'title' => 'Cours de guitare débutant',
            'description' => 'Apprenez les bases de la guitare : accords, rythmique, premières chansons. Cours particulier d\'1h adapté à votre niveau et vos goûts musicaux.',
            'category_id' => ($this->categories['bien-etre-equilibre'] ?? $this->categories['formation'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'points_cost' => 40,
        ]);

        $svcElectro = $this->createService($u['vin.100.gay@gmail.com'], $org, [
            'title' => 'Réparation petit électroménager',
            'description' => 'Je répare vos petits appareils électroménagers : cafetière, grille-pain, aspirateur, fer à repasser. Diagnostic offert, devis avant réparation.',
            'category_id' => ($this->categories['bricolage-projets-perso'] ?? $this->categories['autre'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'points_cost' => 50,
        ]);

        // ── 2. Service Requests ─────────────────────────────────
        $this->createRequest($u['helena.ds@icloud.com'], $org, [
            'title' => 'Recherche coach sportif à domicile',
            'description' => 'Je cherche un coach sportif pour des séances à domicile 2x par semaine dans le 15e. Objectif : remise en forme générale.',
            'category_id' => ($this->categories['bien-etre-equilibre'] ?? $this->categories['formation'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'budget_min' => 30,
            'budget_max' => 60,
            'deadline' => now()->addDays(30),
        ]);

        $this->createRequest($u['antoine.bidet@bmsinvest.fr'], $org, [
            'title' => 'Besoin d\'aide pour montage vidéo',
            'description' => 'J\'ai tourné une vidéo de présentation pour mon activité (5 min) et j\'ai besoin d\'aide pour le montage : coupes, habillage, sous-titres.',
            'category_id' => ($this->categories['creer-des-supports'] ?? $this->categories['tech-digital'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'budget_min' => 50,
            'budget_max' => 100,
            'deadline' => now()->addDays(14),
        ]);

        $this->createRequest($u['contact@franceportugal-traductions.com'], $org, [
            'title' => 'Traduction site web EN→FR',
            'description' => 'Mon site vitrine (5 pages) est en anglais, je souhaite le traduire en français. Contenu technique modéré, environ 2000 mots.',
            'category_id' => ($this->categories['ecrire-communiquer'] ?? $this->categories['traduction'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'budget_min' => 80,
            'budget_max' => 150,
            'deadline' => now()->addDays(21),
        ]);

        $this->createRequest($u['ericyas@gmail.com'], $org, [
            'title' => 'Relooking site vitrine',
            'description' => 'J\'ai un site WordPress que je trouve vieillot. Je cherche quelqu\'un pour me conseiller et m\'aider à le moderniser (nouveau thème, mise en page).',
            'category_id' => ($this->categories['creer-des-supports'] ?? $this->categories['design'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'budget_min' => 100,
            'budget_max' => 200,
            'deadline' => now()->addDays(20),
        ]);

        $this->createRequest($u['julieborgettopro@gmail.com'], $org, [
            'title' => 'Jardinage : taise haies et élagage',
            'description' => 'Je cherche un coup de main pour tailler mes haies et élaguer deux arbres dans mon jardin. Matériel disponible sur place.',
            'category_id' => ($this->categories['bricolage-projets-perso'] ?? $this->categories['autre'] ?? $this->fallbackCategory())->id,
            'delivery_mode' => 'remote',
            'budget_min' => 20,
            'budget_max' => 50,
            'deadline' => now()->addDays(10),
        ]);

        // ── 3. Transactions ────────────────────────────────────

        // 3a. COMPLETED: Héléna achète "Coaching LinkedIn" à Cyril
        $tx1 = Transaction::withoutGlobalScopes()->create([
            'buyer_id' => $u['helena.ds@icloud.com']->id,
            'seller_id' => $u['cslucki@gmail.com']->id,
            'service_id' => $svcCoaching->id,
            'organization_id' => $org->id,
            'points_proposed' => 120,
            'points_agreed' => 120,
            'status' => 'completed',
            'buyer_confirmed_at' => now()->subDays(3)->subHour(),
            'seller_confirmed_at' => now()->subDays(3),
            'completed_at' => now()->subDays(3),
        ]);

        $this->addPoints($u['helena.ds@icloud.com'], $tx1, -120, 'exchange_spent');
        $this->addPoints($u['cslucki@gmail.com'], $tx1, 120, 'exchange_earned');

        $this->transactionMessages($tx1, [
            [$u['helena.ds@icloud.com'], 'Bonjour Cyril, je souhaite améliorer mon profil LinkedIn pour attirer plus de clients. Pouvez-vous m\'aider ?'],
            [$u['cslucki@gmail.com'], 'Bonjour Héléna, avec plaisir ! Je vous propose un audit complet de votre profil suivi d\'une séance de coaching.'],
            [$u['helena.ds@icloud.com'], 'Parfait, je valide ! Quand pouvons-nous planifier la séance ?'],
            [$u['cslucki@gmail.com'], 'Je suis disponible jeudi après-midi. Je vous envoie le lien Zoom.'],
            [$u['helena.ds@icloud.com'], 'Super séance ! J\'ai déjà mis en pratique vos conseils et je vois la différence. Merci beaucoup !'],
        ]);

        Review::create(['transaction_id' => $tx1->id, 'reviewer_id' => $u['helena.ds@icloud.com']->id, 'reviewed_id' => $u['cslucki@gmail.com']->id, 'rating' => 5, 'comment' => 'Cyril est très professionnel et pédagogue. Mon profil LinkedIn a déjà gagné en visibilité !', 'organization_id' => $org->id]);
        Review::create(['transaction_id' => $tx1->id, 'reviewer_id' => $u['cslucki@gmail.com']->id, 'reviewed_id' => $u['helena.ds@icloud.com']->id, 'rating' => 5, 'comment' => 'Héléna est très motivée et appliquée. Un plaisir de l\'accompagner !', 'organization_id' => $org->id]);

        // 3b. COMPLETED: Antoine achète "Cours de guitare" à Eric GASSAN
        $tx2 = Transaction::withoutGlobalScopes()->create([
            'buyer_id' => $u['antoine.bidet@bmsinvest.fr']->id,
            'seller_id' => $u['mr.gassan@gmail.com']->id,
            'service_id' => $svcGuitare->id,
            'organization_id' => $org->id,
            'points_proposed' => 40,
            'points_agreed' => 40,
            'status' => 'completed',
            'buyer_confirmed_at' => now()->subDays(1)->subHour(),
            'seller_confirmed_at' => now()->subDays(1),
            'completed_at' => now()->subDays(1),
        ]);

        $this->addPoints($u['antoine.bidet@bmsinvest.fr'], $tx2, -40, 'exchange_spent');
        $this->addPoints($u['mr.gassan@gmail.com'], $tx2, 40, 'exchange_earned');

        $this->transactionMessages($tx2, [
            [$u['antoine.bidet@bmsinvest.fr'], 'Bonjour Eric, j\'ai toujours voulu apprendre la guitare mais je n\'ai jamais pris le temps. Je suis grand débutant !'],
            [$u['mr.gassan@gmail.com'], 'Bonjour Antoine, aucun souci ! Je commence toujours par les bases, tu verras c\'est plus simple qu\'il n\'y paraît.'],
            [$u['antoine.bidet@bmsinvest.fr'], 'Génial ! La séance était top, j\'ai déjà appris mes premiers accords. Merci Eric !'],
        ]);

        Review::create(['transaction_id' => $tx2->id, 'reviewer_id' => $u['antoine.bidet@bmsinvest.fr']->id, 'reviewed_id' => $u['mr.gassan@gmail.com']->id, 'rating' => 5, 'comment' => 'Excellent professeur, très patient et encourageant. J\'ai hâte à la prochaine séance !', 'organization_id' => $org->id]);
        Review::create(['transaction_id' => $tx2->id, 'reviewer_id' => $u['mr.gassan@gmail.com']->id, 'reviewed_id' => $u['antoine.bidet@bmsinvest.fr']->id, 'rating' => 5, 'comment' => 'Antoine est un élève attentif et motivé. Il progresse vite !', 'organization_id' => $org->id]);

        // 3c. ACCEPTED: Vincent achète "Réparation petit électroménager" — EN COURS
        $tx3 = Transaction::withoutGlobalScopes()->create([
            'buyer_id' => $u['julieborgettopro@gmail.com']->id,
            'seller_id' => $u['vin.100.gay@gmail.com']->id,
            'service_id' => $svcElectro->id,
            'organization_id' => $org->id,
            'points_proposed' => 50,
            'points_agreed' => 50,
            'status' => 'accepted',
        ]);

        $this->transactionMessages($tx3, [
            [$u['julieborgettopro@gmail.com'], 'Bonjour Vincent, ma cafetière ne fonctionne plus, pourriez-vous jeter un coup d\'œil ?'],
            [$u['vin.100.gay@gmail.com'], 'Bonjour Julie, oui pas de problème ! Pouvez-vous me décrire le problème ?'],
            [$u['julieborgettopro@gmail.com'], 'Elle chauffe mais ne fait plus couler l\'eau correctement.'],
            [$u['vin.100.gay@gmail.com'], 'Probablement un joint ou du calcaire. Je passe la voir jeudi si vous voulez.'],
        ]);

        // 3d. PENDING: Jean-Michel demande "Coaching LinkedIn"
        $tx4 = Transaction::withoutGlobalScopes()->create([
            'buyer_id' => $u['jmhoussay@orange.fr']->id,
            'seller_id' => $u['cslucki@gmail.com']->id,
            'service_id' => $svcCoaching->id,
            'organization_id' => $org->id,
            'points_proposed' => 120,
            'status' => 'pending',
        ]);

        $this->transactionMessages($tx4, [
            [$u['jmhoussay@orange.fr'], 'Bonjour Cyril, je suis expert-comptable et je souhaite développer ma présence sur LinkedIn. Votre service m\'intéresse.'],
        ]);

        // 3e. BUYER_DONE: Nathalie achète "Accompagnement création entreprise"
        $tx5 = Transaction::withoutGlobalScopes()->create([
            'buyer_id' => $u['cslucki@gmail.com']->id,
            'seller_id' => $u['secretariat-assistance@proton.me']->id,
            'service_id' => $svcCreation->id,
            'organization_id' => $org->id,
            'points_proposed' => 200,
            'points_agreed' => 200,
            'status' => 'buyer_done',
            'buyer_confirmed_at' => now()->subHours(12),
        ]);

        $this->transactionMessages($tx5, [
            [$u['cslucki@gmail.com'], 'Bonjour Nathalie, j\'ai un projet de création d\'entreprise et j\'aurais besoin d\'être accompagné sur les aspects juridiques et financiers.'],
            [$u['secretariat-assistance@proton.me'], 'Bonjour Cyril, avec plaisir ! Je vous propose qu\'on commence par un point sur votre situation et vos besoins.'],
            [$u['cslucki@gmail.com'], 'Très bien, je vous remercie pour votre accompagnement. Le business plan est clair et complet. Je valide !'],
        ]);

        // 3f. CANCELLED: Carla demande "Réparation électroménager"
        Transaction::withoutGlobalScopes()->create([
            'buyer_id' => $u['contact@franceportugal-traductions.com']->id,
            'seller_id' => $u['vin.100.gay@gmail.com']->id,
            'service_id' => $svcElectro->id,
            'organization_id' => $org->id,
            'points_proposed' => 50,
            'points_agreed' => 50,
            'status' => 'cancelled',
        ]);

        // ── 4. Loops ────────────────────────────────────────────

        // 4a. "Entraide Tech" — publique
        $loopTech = Loop::create([
            'organization_id' => $org->id,
            'name' => 'Entraide Tech',
            'slug' => 'entraide-tech',
            'description' => 'Groupe d\'entraide pour les passionnés de tech et de numérique. Partage de connaissances, veille technologique et entraide sur les projets.',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => $u['cslucki@gmail.com']->id,
        ]);

        LoopMember::create(['loop_id' => $loopTech->id, 'user_id' => $u['cslucki@gmail.com']->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()->subDays(30), 'organization_id' => $org->id]);
        LoopMember::create(['loop_id' => $loopTech->id, 'user_id' => $u['qa-member1@bouclepro.local']->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()->subDays(28), 'organization_id' => $org->id]);
        LoopMember::create(['loop_id' => $loopTech->id, 'user_id' => $u['qa-member2@bouclepro.local']->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()->subDays(25), 'organization_id' => $org->id]);

        $this->loopMessages($loopTech, [
            [$u['cslucki@gmail.com'], 'Bienvenue dans le groupe Entraide Tech ! N\'hésitez pas à partager vos questions et découvertes.'],
            [$u['qa-member1@bouclepro.local'], 'Merci Cyril ! Je viens de découvrir Laravel 12, les nouvelles fonctionnalités sont impressionnantes.'],
            [$u['cslucki@gmail.com'], 'Oui, les améliorations sur la gestion des files d\'attente sont top ! Qui d\'autre utilise Laravel ici ?'],
            [$u['qa-member2@bouclepro.local'], 'Moi ! Je suis en train de migrer un projet de Symfony vers Laravel.'],
            [$u['cslucki@gmail.com'], 'Bonne initiative ! Si tu as besoin d\'aide sur la migration, n\'hésite pas.'],
            [$u['qa-member1@bouclepro.local'], 'Concernant les performances, vous utilisez quoi comme solution de cache ?'],
        ]);

        // 4b. "Covoiturage Express" — privée
        $loopCovoit = Loop::create([
            'organization_id' => $org->id,
            'name' => 'Covoiturage Express',
            'slug' => 'covoiturage-express',
            'description' => 'Organisation de covoiturages entre membres pour les trajets quotidiens domicile-travail et les déplacements ponctuels.',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => $u['mr.gassan@gmail.com']->id,
        ]);

        LoopMember::create(['loop_id' => $loopCovoit->id, 'user_id' => $u['mr.gassan@gmail.com']->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()->subDays(20), 'organization_id' => $org->id]);
        LoopMember::create(['loop_id' => $loopCovoit->id, 'user_id' => $u['vin.100.gay@gmail.com']->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()->subDays(18), 'organization_id' => $org->id]);
        LoopMember::create(['loop_id' => $loopCovoit->id, 'user_id' => $u['ericyas@gmail.com']->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()->subDays(15), 'organization_id' => $org->id]);

        $this->loopMessages($loopCovoit, [
            [$u['mr.gassan@gmail.com'], 'Bonjour à tous ! Qui serait intéressé par un covoiturage pour le prochain meetup jeudi soir ?'],
            [$u['vin.100.gay@gmail.com'], 'Je suis partant ! Je suis dans le 12e, on peut s\'organiser.'],
            [$u['ericyas@gmail.com'], 'Moi aussi, je peux prendre quelqu\'un si besoin. J\'ai une place libre.'],
            [$u['mr.gassan@gmail.com'], 'Parfait ! On se fixe un point de rendez-vous vers 18h30 ?'],
        ]);

        // ── 5. Blog Posts ───────────────────────────────────────

        BlogPost::create([
            'user_id' => $u['cslucki@gmail.com']->id,
            'organization_id' => $org->id,
            'title' => 'Comment bien rédiger une demande d\'aide',
            'slug' => 'comment-bien-rediger-demande-aide',
            'summary' => 'Les clés pour formuler une demande d\'aide claire et efficace qui obtiendra des réponses.',
            'content' => "<p>L'entraide fonctionne mieux quand les demandes sont bien formulées. Voici nos conseils :</p><h2>Soyez précis</h2><p>Au lieu de dire \"J'ai besoin d'aide en informatique\", dites \"Je cherche quelqu'un pour m'aider à configurer mon serveur domestique\".</p><h2>Indiquez votre budget en points</h2><p>Précisez combien de points vous êtes prêt à consacrer à cet échange.</p><h2>Fixez un délai</h2><p>Donnez une date butoir pour que les membres sachent si ils peuvent vous aider à temps.</p>",
            'status' => 'published',
            'published_at' => now()->subDays(7),
            'views_count' => 45,
        ]);

        BlogPost::create([
            'user_id' => $u['helena.ds@icloud.com']->id,
            'organization_id' => $org->id,
            'title' => 'Les bienfaits de l\'entraide locale',
            'slug' => 'bienfaits-entraide-locale',
            'summary' => 'Comment l\'entraide de proximité renforce les liens et crée des opportunités.',
            'content' => "<p>L'entraide locale n'est pas seulement un échange de services, c'est un véritable moteur de lien social.</p><h2>Créer du lien</h2><p>Quand on échange avec ses voisins, on tisse des relations de confiance qui dépassent le simple cadre de l'échange.</p><h2>Développer ses compétences</h2><p>En aidant les autres, on consolide ses propres connaissances et on en acquiert de nouvelles.</p><h2>Gagner en visibilité</h2><p>Proposer son aide est aussi un excellent moyen de se faire connaître localement.</p>",
            'status' => 'published',
            'published_at' => now()->subDays(3),
            'views_count' => 28,
        ]);

        // ── 6. Reports ──────────────────────────────────────────

        Report::create([
            'reporter_id' => $u['cslucki@gmail.com']->id,
            'reportable_type' => 'App\Models\Service',
            'reportable_id' => $svcGuitare->id,
            'reason' => 'spam',
            'details' => 'Le service semble être une copie d\'une autre annonce.',
            'status' => 'pending',
            'organization_id' => $org->id,
        ]);

        $someUser = $u['helena.ds@icloud.com'];
        Report::create([
            'reporter_id' => $u['antoine.bidet@bmsinvest.fr']->id,
            'reportable_type' => 'App\Models\User',
            'reportable_id' => $someUser->id,
            'reason' => 'inappropriate',
            'details' => 'Comportement inapproprié dans les échanges.',
            'status' => 'pending',
            'organization_id' => $org->id,
        ]);

        // ── 7. Referrals ────────────────────────────────────────

        $ref1 = Referral::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'referrer_user_id' => $u['cslucki@gmail.com']->id,
            'referred_user_id' => $u['secretariat-assistance@proton.me']->id,
            'depth' => 0,
            'status' => 'activated',
            'activated_at' => now()->subDays(20),
        ]);

        ReferralReward::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'referral_id' => $ref1->id,
            'user_id' => $u['cslucki@gmail.com']->id,
            'source_user_id' => $u['secretariat-assistance@proton.me']->id,
            'event_type' => 'referral_activated',
            'level' => 1,
            'points' => 50,
        ]);

        $ref2 = Referral::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'referrer_user_id' => $u['mr.gassan@gmail.com']->id,
            'referred_user_id' => $u['julieborgettopro@gmail.com']->id,
            'depth' => 0,
            'status' => 'activated',
            'activated_at' => now()->subDays(10),
        ]);

        ReferralReward::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'referral_id' => $ref2->id,
            'user_id' => $u['mr.gassan@gmail.com']->id,
            'source_user_id' => $u['julieborgettopro@gmail.com']->id,
            'event_type' => 'referral_activated',
            'level' => 1,
            'points' => 50,
        ]);

        // ── 8. Email Templates ──────────────────────────────────

        $templateWelcome = EmailTemplate::create([
            'slug' => 'dashboard-demo-welcome',
            'name' => 'Bienvenue',
            'subject' => 'Bienvenue sur Entraide, {{ name }} !',
            'content_html' => "<h1>Bienvenue sur Entraide !</h1>\n<p>Bonjour {{ name }},</p>\n<p>Nous sommes ravis de vous compter parmi nos membres. Découvrez les services disponibles près de chez vous et commencez à échanger !</p>\n<p><a href=\"{{ url }}\">Explorer les services</a></p>",
            'variables' => ['name', 'url'],
            'organization_id' => $org->id,
        ]);

        $templateTx = EmailTemplate::create([
            'slug' => 'dashboard-demo-transaction-confirmed',
            'name' => 'Transaction confirmée',
            'subject' => 'Votre échange {{ title }} a été confirmé !',
            'content_html' => "<h1>Échange confirmé</h1>\n<p>Bonjour {{ name }},</p>\n<p>Votre échange <strong>{{ title }}</strong> est maintenant confirmé. Les points ont été crédités sur votre compte.</p>\n<p>Points : {{ points }} pts</p>\n<p><a href=\"{{ url }}\">Voir les détails</a></p>",
            'variables' => ['name', 'title', 'points', 'url'],
            'organization_id' => $org->id,
        ]);

        // ── 9. Email Logs ───────────────────────────────────────

        EmailLog::create([
            'template_id' => $templateWelcome->id,
            'user_id' => $u['helena.ds@icloud.com']->id,
            'to_email' => $u['helena.ds@icloud.com']->email,
            'subject' => 'Bienvenue sur Entraide, Héléna !',
            'status' => 'sent',
            'data' => ['name' => 'Héléna DOS SANTOS', 'url' => 'https://entraide.app/explorer'],
            'organization_id' => $org->id,
        ]);

        EmailLog::create([
            'template_id' => $templateWelcome->id,
            'user_id' => $u['antoine.bidet@bmsinvest.fr']->id,
            'to_email' => $u['antoine.bidet@bmsinvest.fr']->email,
            'subject' => 'Bienvenue sur Entraide, Antoine !',
            'status' => 'sent',
            'data' => ['name' => 'Antoine BIDET', 'url' => 'https://entraide.app/explorer'],
            'organization_id' => $org->id,
        ]);

        EmailLog::create([
            'template_id' => $templateTx->id,
            'user_id' => $u['helena.ds@icloud.com']->id,
            'to_email' => $u['helena.ds@icloud.com']->email,
            'subject' => 'Votre échange Coaching LinkedIn a été confirmé !',
            'status' => 'sent',
            'data' => ['name' => 'Héléna DOS SANTOS', 'title' => 'Coaching LinkedIn', 'points' => 120, 'url' => 'https://entraide.app/transactions'],
            'organization_id' => $org->id,
        ]);

        EmailLog::create([
            'template_id' => $templateTx->id,
            'user_id' => $u['mr.gassan@gmail.com']->id,
            'to_email' => $u['mr.gassan@gmail.com']->email,
            'subject' => 'Votre échange Cours de guitare a été confirmé !',
            'status' => 'failed',
            'error_message' => 'SMTP connection timeout after 30 seconds',
            'data' => ['name' => 'Eric GASSAN', 'title' => 'Cours de guitare débutant', 'points' => 40, 'url' => 'https://entraide.app/transactions'],
            'organization_id' => $org->id,
        ]);
    }

    private function seedCpmeOrg(): void
    {
        $org = $this->cpme;
        $u = $this->users;

        // ── Transaction: completed ──────────────────────────────
        $txCpme1 = Transaction::withoutGlobalScopes()->create([
            'buyer_id' => $u['qa-cpme1@bouclepro.local']->id,
            'seller_id' => $u['qa-cpme2@bouclepro.local']->id,
            'organization_id' => $org->id,
            'points_proposed' => 30,
            'points_agreed' => 30,
            'status' => 'completed',
            'buyer_confirmed_at' => now()->subDays(4)->subHour(),
            'seller_confirmed_at' => now()->subDays(4),
            'completed_at' => now()->subDays(4),
        ]);

        $this->addPoints($u['qa-cpme1@bouclepro.local'], $txCpme1, -30, 'exchange_spent');
        $this->addPoints($u['qa-cpme2@bouclepro.local'], $txCpme1, 30, 'exchange_earned');

        $this->transactionMessages($txCpme1, [
            [$u['qa-cpme1@bouclepro.local'], 'Bonjour, j\'aurais besoin d\'aide pour préparer une présentation.'],
            [$u['qa-cpme2@bouclepro.local'], 'Bonjour, avec plaisir ! Je vous aide à structurer votre slide deck.'],
            [$u['qa-cpme1@bouclepro.local'], 'Merci, la présentation était parfaite !'],
        ]);

        Review::create(['transaction_id' => $txCpme1->id, 'reviewer_id' => $u['qa-cpme1@bouclepro.local']->id, 'reviewed_id' => $u['qa-cpme2@bouclepro.local']->id, 'rating' => 5, 'comment' => 'Excellent travail, très professionnel !', 'organization_id' => $org->id]);
        Review::create(['transaction_id' => $txCpme1->id, 'reviewer_id' => $u['qa-cpme2@bouclepro.local']->id, 'reviewed_id' => $u['qa-cpme1@bouclepro.local']->id, 'rating' => 4, 'comment' => 'Client très organisé, collaboration agréable.', 'organization_id' => $org->id]);

        // ── Transaction: pending ─────────────────────────────────
        Transaction::withoutGlobalScopes()->create([
            'buyer_id' => $u['qa-cpme2@bouclepro.local']->id,
            'seller_id' => $u['qa-cpme1@bouclepro.local']->id,
            'organization_id' => $org->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);

        // ── Loop "CPME Entraide" ─────────────────────────────────
        $loopCpme = Loop::create([
            'organization_id' => $org->id,
            'name' => 'CPME Entraide',
            'slug' => 'cpme-entraide',
            'description' => 'Groupe d\'entraide interne CPME pour échanger conseils et services entre adhérents.',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => $u['qa-cpme1@bouclepro.local']->id,
        ]);

        LoopMember::create(['loop_id' => $loopCpme->id, 'user_id' => $u['qa-cpme1@bouclepro.local']->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()->subDays(15), 'organization_id' => $org->id]);
        LoopMember::create(['loop_id' => $loopCpme->id, 'user_id' => $u['qa-cpme2@bouclepro.local']->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()->subDays(12), 'organization_id' => $org->id]);

        $this->loopMessages($loopCpme, [
            [$u['qa-cpme1@bouclepro.local'], 'Bienvenue dans le groupe CPME ! Partageons nos bonnes pratiques et entraidons-nous.'],
            [$u['qa-cpme2@bouclepro.local'], 'Super initiative ! Je propose un webinaire sur la gestion de trésorerie la semaine prochaine.'],
            [$u['qa-cpme1@bouclepro.local'], 'Très bonne idée, je suis partant !'],
        ]);

        // ── Blog post (draft) ────────────────────────────────────
        BlogPost::create([
            'user_id' => $u['qa-cpme1@bouclepro.local']->id,
            'organization_id' => $org->id,
            'title' => 'Actualités CPME',
            'slug' => 'actualites-cpme',
            'summary' => 'Les dernières actualités et événements de la CPME.',
            'content' => '<p>Retrouvez ici les actualités de la CPME : prochains rendez-vous, ateliers et informations utiles pour les adhérents.</p>',
            'status' => 'draft',
            'views_count' => 0,
        ]);
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
