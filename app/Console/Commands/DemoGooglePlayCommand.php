<?php

namespace App\Console\Commands;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TASK-1900 — organisation FICTIVE « Collectif Horizon » pour la revue Google
 * Play : 6 membres inventes, 3 Boucles, demandes et propositions d'entraide,
 * conversations, articles, annonces et evenements, plus UN compte reserve aux
 * testeurs de Google.
 *
 * Garde-fous :
 * - aucune donnee reelle : noms inventes, emails en `.invalid` (domaine qui
 *   n'existe jamais, RFC 2606), telephones dans la plage reservee a la fiction ;
 * - inscriptions fermees, sans administrateur, non publique sauf `--public` : le
 *   compte testeur est un MEMBRE SIMPLE, rattache a cette seule organisation ;
 * - ecritures directes en base, comme un jeu de donnees : aucun controleur,
 *   donc aucune notification, aucun email, aucun appel IA ;
 * - le mot de passe du compte testeur est ALEATOIRE et n'est jamais affiche :
 *   Cyril le choisit par « Mot de passe oublie » depuis la boite du testeur
 *   (`--reviewer-email`, une adresse qu'il controle) ;
 * - rejouable : si l'organisation existe deja, rien n'est ecrit ;
 * - `--purge` bloque la connexion de tous ses comptes puis met l'organisation
 *   a la corbeille (suppression douce, reversible), et ne touche qu'a une
 *   organisation qui porte le slug ET le marqueur de cette commande.
 */
class DemoGooglePlayCommand extends Command
{
    public const SLUG = 'collectif-horizon-demo';

    /** Marqueur pose dans la description : `--purge` refuse toute autre organisation. */
    public const MARQUEUR = '[demo-google-play]';

    public const DOMAINE = 'demo-bouclepro.invalid';

    protected $signature = 'demo:google-play
        {--reviewer-email= : Adresse du compte testeur Google (boite controlee par Cyril)}
        {--public : Rend l organisation choisissable sur la page de connexion de l application (necessaire au testeur Google)}
        {--dry-run : Affiche ce qui serait cree, sans rien ecrire}
        {--purge : Bloque les comptes et met l organisation de demonstration a la corbeille}';

    protected $description = 'Cree (ou retire) l organisation fictive de demonstration pour la revue Google Play';

    public function handle(): int
    {
        return $this->option('purge') ? $this->purger() : $this->creer();
    }

    private function creer(): int
    {
        $email = Str::lower(trim((string) $this->option('reviewer-email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('--reviewer-email est obligatoire (adresse valide, boite controlee par Cyril).');

            return self::FAILURE;
        }

        if (Organization::withTrashed()->where('slug', self::SLUG)->exists()) {
            $this->warn('L organisation « '.self::SLUG.' » existe deja : rien n est ecrit.');

            return self::SUCCESS;
        }

        if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
            $this->error("Un compte {$email} existe deja : choisir une adresse dediee au testeur.");

            return self::FAILURE;
        }

        $donnees = self::donnees();

        if ($this->option('dry-run')) {
            $this->info('Simulation : rien n est ecrit.');
            $this->line('Organisation : Collectif Horizon ('.self::SLUG.'), '.($this->option('public') ? 'publique' : 'non publique').', inscriptions fermees');
            $this->line('Membres fictifs : '.count($donnees['membres']).' ; compte testeur : '.$email.' (membre simple)');
            $this->line('Boucles : '.count($donnees['boucles']).' ; articles : '.count($donnees['articles']).' ; annonces : '.count($donnees['annonces']).' ; evenements : '.count($donnees['evenements']));
            $this->line('Demandes : '.count($donnees['demandes']).' ; propositions : '.count($donnees['propositions']));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($donnees, $email) {
            $org = new Organization;
            $org->forceFill([
                'name' => 'Collectif Horizon',
                'slug' => self::SLUG,
                'description' => 'Organisation fictive de démonstration. '.self::MARQUEUR,
                'is_active' => true,
                // Sans `--public`, l'organisation n'apparait pas dans le choix
                // de la page de connexion (`LoginAccountResolver::
                // organizationsProposables()`) : le testeur ne l'atteint alors
                // que par `/org/{slug}/login?org={slug}`.
                'is_public' => (bool) $this->option('public'),
                'registration_disabled' => true,
                'admin_id' => null,
                'locale' => 'fr',
            ])->save();

            app()->instance('current_organization', $org);

            $membres = [];
            foreach ($donnees['membres'] as $cle => $m) {
                $membres[$cle] = $this->membre($org, $m + ['email' => "{$cle}@".self::DOMAINE]);
            }

            $membres['testeur'] = $this->membre($org, [
                'first_name' => 'Testeur', 'name' => 'Google Play', 'email' => $email,
                'slogan' => 'Compte de démonstration', 'bio' => 'Compte réservé à la revue de l’application.',
                'city' => 'Lyon', 'phone' => '+33 6 39 98 00 99',
            ]);

            $boucles = [];
            foreach ($donnees['boucles'] as $slug => $b) {
                $boucle = new Loop;
                $boucle->forceFill([
                    'organization_id' => $org->id, 'name' => $b['nom'], 'slug' => $slug, 'description' => $b['description'],
                    'type' => 'general', 'status' => 'active', 'visibility' => 'public', 'created_by' => $membres['camille']->id,
                ])->save();
                $boucles[$slug] = $boucle;

                foreach (array_keys($membres) as $i => $cle) {
                    (new LoopMember)->forceFill([
                        'loop_id' => $boucle->id, 'user_id' => $membres[$cle]->id, 'organization_id' => $org->id,
                        'role' => $cle === 'camille' ? 'owner' : 'member', 'status' => 'active', 'joined_at' => now()->subDays(20 - $i),
                    ])->save();
                }

                foreach ($b['messages'] as $n => [$cle, $texte]) {
                    (new LoopMessage)->forceFill([
                        'loop_id' => $boucle->id, 'sender_id' => $membres[$cle]->id, 'organization_id' => $org->id,
                        'body' => $texte, 'type' => 'user', 'created_at' => now()->subMinutes(60 - $n * 9),
                    ])->save();
                }
            }

            foreach ($donnees['articles'] as [$cle, $titre, $slug, $resume, $contenu, $jours]) {
                (new BlogPost)->forceFill([
                    'user_id' => $membres[$cle]->id, 'organization_id' => $org->id, 'title' => $titre, 'slug' => $slug,
                    'summary' => $resume, 'content' => $contenu, 'status' => 'published', 'published_at' => now()->subDays($jours),
                ])->saveQuietly();
            }

            foreach ($donnees['annonces'] as [$cle, $titre, $contenu, $minutes]) {
                (new FeedPost)->forceFill([
                    'organization_id' => $org->id, 'user_id' => $membres[$cle]->id, 'type' => FeedPost::TYPE_ANNOUNCEMENT,
                    'title' => $titre, 'content' => $contenu, 'status' => FeedPost::STATUS_PUBLISHED,
                    'published_at' => now()->subMinutes($minutes), 'visibility' => 'organization',
                ])->save();
            }

            foreach ($donnees['evenements'] as [$slug, $titre, $description, $format, $jours, $heure, $lieu]) {
                (new LoopEvent)->forceFill([
                    'organization_id' => $org->id, 'loop_id' => $boucles[$slug]->id, 'created_by' => $membres['camille']->id,
                    'title' => $titre, 'description' => $description, 'format' => $format,
                    'starts_at' => now()->addDays($jours)->setTime($heure, 0), 'ends_at' => now()->addDays($jours)->setTime($heure + 2, 0),
                    'timezone' => 'Europe/Paris', 'location' => $lieu,
                    'meeting_url' => $format === 'online' ? 'https://example.org/visio' : null,
                    'visibility' => 'organization', 'status' => 'scheduled',
                ])->save();
            }

            // Demandes et propositions : rattachees a une categorie GLOBALE
            // existante ; sans categorie globale, on s'abstient (et on le dit).
            $categorie = Category::query()->whereNull('organization_id')->orderBy('created_at')->first();

            if ($categorie === null) {
                $this->warn('Aucune categorie globale : demandes et propositions non creees.');

                return;
            }

            foreach ($donnees['demandes'] as [$cle, $titre, $description]) {
                (new ServiceRequest)->forceFill([
                    'user_id' => $membres[$cle]->id, 'organization_id' => $org->id, 'category_id' => $categorie->id,
                    'title' => $titre, 'description' => $description, 'delivery_mode' => 'both', 'budget_min' => 0, 'status' => 'open',
                ])->save();
            }

            foreach ($donnees['propositions'] as [$cle, $titre, $description]) {
                (new Service)->forceFill([
                    'user_id' => $membres[$cle]->id, 'organization_id' => $org->id, 'category_id' => $categorie->id,
                    'title' => $titre, 'description' => $description, 'delivery_mode' => 'both', 'points_cost' => 0, 'status' => 'active',
                ])->save();
            }
        });

        $this->info('Organisation de demonstration creee : /org/'.self::SLUG.'/login?org='.self::SLUG);
        $this->line("Compte testeur : {$email} (membre simple). Mot de passe : a choisir par « Mot de passe oublie ».");

        return self::SUCCESS;
    }

    /** @param  array<string, string>  $m */
    private function membre(Organization $org, array $m): User
    {
        $user = new User;
        $user->forceFill([
            'organization_id' => $org->id,
            'first_name' => $m['first_name'],
            'name' => $m['name'],
            'email' => $m['email'],
            // Aleatoire et jamais affiche : personne ne se connecte aux comptes
            // fictifs ; le testeur choisit le sien par « Mot de passe oublie ».
            'password' => Hash::make(Str::random(48)),
            'email_verified_at' => now(),
            'approved_at' => now(),
            'slogan' => $m['slogan'],
            'bio' => $m['bio'],
            'city' => $m['city'],
            'country_code' => 'FR',
            'phone' => $m['phone'],
            'preferred_locale' => 'fr',
            'is_admin' => false,
            'is_available' => true,
        ])->save();

        return $user;
    }

    private function purger(): int
    {
        $org = Organization::withTrashed()->where('slug', self::SLUG)->first();

        if ($org === null) {
            $this->warn('Aucune organisation « '.self::SLUG.' » : rien a retirer.');

            return self::SUCCESS;
        }

        if (! str_contains((string) $org->description, self::MARQUEUR)) {
            $this->error('Cette organisation ne porte pas le marqueur de demonstration : refus.');

            return self::FAILURE;
        }

        $comptes = User::withoutGlobalScopes()->where('organization_id', $org->id);
        $n = (clone $comptes)->count();

        if ($this->option('dry-run')) {
            $this->info("Simulation : {$n} compte(s) seraient bloques, l organisation mise a la corbeille.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($comptes, $org) {
            $comptes->whereNull('login_blocked_at')->update(['login_blocked_at' => now()]);
            $org->delete();
        });

        $this->info("{$n} compte(s) bloques ; organisation de demonstration mise a la corbeille.");

        return self::SUCCESS;
    }

    /**
     * Contenu 100 % fictif. Telephones : plage 06 39 98 reservee a la fiction.
     *
     * @return array<string, mixed>
     */
    public static function donnees(): array
    {
        return [
            'membres' => [
                'camille' => ['first_name' => 'Camille', 'name' => 'Moreau', 'slogan' => 'Facilitatrice d’ateliers numériques', 'bio' => 'J’anime des ateliers pour rendre le numérique accessible à tous.', 'city' => 'Lyon', 'phone' => '+33 6 39 98 00 01'],
                'julien' => ['first_name' => 'Julien', 'name' => 'Lefèvre', 'slogan' => 'Développeur web indépendant', 'bio' => 'Je crée des sites web simples et accessibles pour les associations.', 'city' => 'Lyon', 'phone' => '+33 6 39 98 00 02'],
                'amina' => ['first_name' => 'Amina', 'name' => 'Benali', 'slogan' => 'Chargée de communication associative', 'bio' => 'Je raconte les projets de l’association et j’aide à les faire connaître.', 'city' => 'Villeurbanne', 'phone' => '+33 6 39 98 00 03'],
                'lucas' => ['first_name' => 'Lucas', 'name' => 'Martin', 'slogan' => 'Formateur en bureautique', 'bio' => 'Je forme aux outils bureautiques, du débutant au confirmé.', 'city' => 'Grenoble', 'phone' => '+33 6 39 98 00 04'],
                'sofia' => ['first_name' => 'Sofia', 'name' => 'Rossi', 'slogan' => 'Graphiste et illustratrice', 'bio' => 'J’illustre affiches, livrets et réseaux sociaux.', 'city' => 'Lyon', 'phone' => '+33 6 39 98 00 05'],
                'hugo' => ['first_name' => 'Hugo', 'name' => 'Bernard', 'slogan' => 'Comptable bénévole', 'bio' => 'J’accompagne les petites associations dans leur comptabilité.', 'city' => 'Saint-Étienne', 'phone' => '+33 6 39 98 00 06'],
            ],
            'boucles' => [
                'atelier-numerique' => ['nom' => 'Atelier numérique du quartier', 'description' => 'Organiser un atelier mensuel pour aider les habitants à prendre en main leurs outils numériques.', 'messages' => [
                    ['camille', 'Bienvenue à toutes et à tous ! Objectif : un premier atelier le mois prochain.'],
                    ['julien', 'Je peux préparer une séance sur les mots de passe et la sécurité.'],
                    ['amina', 'Je m’occupe de l’affiche et de la diffusion dans le quartier.'],
                    ['lucas', 'J’ai une salle disponible le samedi matin, 12 places.'],
                    ['camille', 'Parfait, on valide samedi 10 h. Je crée l’événement.'],
                ]],
                'site-web' => ['nom' => 'Site web de l’association', 'description' => 'Refaire le site de l’association en quelques semaines, à plusieurs.', 'messages' => [
                    ['julien', 'Première maquette en ligne, vos retours ?'],
                    ['sofia', 'Je propose une palette plus lumineuse.'],
                    ['amina', 'Pensez à une page « Nous rejoindre » bien visible.'],
                ]],
                'bilan-2026' => ['nom' => 'Bilan comptable 2026', 'description' => 'Préparer le bilan annuel avec l’aide d’un comptable bénévole.', 'messages' => [
                    ['hugo', 'Envoyez-moi les justificatifs avant la fin du mois.'],
                    ['camille', 'C’est noté, je regroupe ceux de l’atelier.'],
                ]],
            ],
            'articles' => [
                ['amina', 'Retour sur notre premier atelier numérique', 'retour-premier-atelier', 'Douze habitants, trois bénévoles, et beaucoup de questions sur les mots de passe.', '<p>Samedi dernier, douze habitants sont venus apprendre à sécuriser leurs comptes.</p><h2>Ce qui a marché</h2><p>Des petits groupes, et du temps pour pratiquer.</p>', 2],
                ['julien', 'Cinq conseils pour un site associatif qui marche', 'cinq-conseils-site-associatif', 'Des pages claires, des mots simples et un bouton d’adhésion facile à trouver.', '<p>Un bon site répond à trois questions en quelques secondes.</p>', 5],
            ],
            'annonces' => [
                ['amina', 'Affiche de l’atelier numérique', 'L’affiche de l’atelier de samedi est prête ! Partagez-la autour de vous : 12 places, inscription dans la Boucle.', 60],
                ['julien', 'Nouveau site en préparation', 'La première maquette du site de l’association est en ligne dans la Boucle « Site web ». Vos retours sont les bienvenus.', 180],
                ['hugo', 'Bilan 2026', 'Pensez à déposer vos justificatifs avant la fin du mois. Merci à toutes et à tous !', 600],
            ],
            'evenements' => [
                ['atelier-numerique', 'Atelier : sécuriser ses comptes', 'Séance pratique sur les mots de passe et la double authentification.', 'in_person', 3, 10, 'Maison des associations, salle 2'],
                ['site-web', 'Point d’étape du nouveau site', 'Revue de la maquette et répartition des pages.', 'online', 5, 18, null],
                ['atelier-numerique', 'Atelier : trier ses photos', 'Classer et sauvegarder ses photos de téléphone.', 'in_person', 10, 10, 'Médiathèque du quartier'],
            ],
            'demandes' => [
                ['lucas', 'Besoin d’un regard sur ma présentation de formation', 'Je cherche une personne pour relire et améliorer les supports d’une formation bureautique de deux heures.'],
                ['sofia', 'Conseils pour fixer mes tarifs d’illustration', 'Je débute en indépendante et j’aimerais échanger avec quelqu’un qui a déjà fixé ses prix.'],
            ],
            'propositions' => [
                ['julien', 'Relecture de votre site web', 'Je relis votre site et vous propose trois améliorations simples, en une heure.'],
                ['hugo', 'Aide pour votre première comptabilité', 'Je vous aide à mettre en place un tableau de suivi simple pour votre association.'],
            ],
        ];
    }
}
