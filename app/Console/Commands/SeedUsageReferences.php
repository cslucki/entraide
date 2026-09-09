<?php

namespace App\Console\Commands;

use App\Models\UsageReference;
use Illuminate\Console\Command;

/**
 * TASK-1485 — le seeder CANONIQUE des UsageReference.
 *
 * ## Ce que cette commande ne fait pas, et c'est le point
 *
 * Elle n'ecrase RIEN. Une reference existante — publiee, brouillon ou
 * retiree — est laissee intacte et signalee comme telle. Un second passage ne
 * modifie donc aucune ligne, et la commande le DIT plutot que de le laisser
 * deviner.
 *
 * Elle ne publie RIEN non plus. Tout ce qu'elle cree nait en `draft`, donc
 * n'est servi ni au Shell membre, ni au Shell visiteur, ni a aucun modele. La
 * publication reste un geste humain explicite, comme partout ailleurs dans ce
 * produit.
 *
 * ## Pourquoi une commande et pas une migration
 *
 * Le precedent le plus proche est une migration
 * (`2026_09_08_011000_seed_shell_welcome_usage_reference.php`, TASK-1439) — mais
 * elle publie, et une migration s'execute au deploiement, donc en production,
 * sans que personne l'ait demande. Le precedent qui convient est celui de
 * `SeedChatLoopAiPrompts` et `SeedOfferMasterPrompts` : une commande qu'on lance
 * deliberement, et dont l'idempotence se MESURE en la lancant deux fois.
 *
 * ## Les textes sont verifies contre le code, pas imagines
 *
 * Chaque texte a ete ecrit apres lecture du controleur, des vues et des cles de
 * langue de la surface concernee. Aucune fonction promise qui n'existe pas :
 * c'est la seule discipline qui compte pour une couche dont le role est
 * d'ancrer un modele. Un texte qui inventerait une fonctionnalite ferait
 * exactement le contraire de ce pour quoi il existe.
 *
 * ## La colonne « lu par » n'est pas decorative
 *
 * Mesure du 2026-09-09 : sur les cinq surfaces PUBLIQUES declarees, une seule
 * est reellement demandee par du code — `shell_welcome`, ecrite EN DUR dans
 * `GuestShellResponder`. `organization_home`, `workshop`, `workshop_session` et
 * `signup` ne sont demandees par aucun appelant ; `workshop_session` n'a meme
 * pas de route (`GuestPageContextResolver`, TASK-1450).
 *
 * Les semer quand meme est le bon choix — elles sont declarees, elles seront
 * lues le jour ou un appelant les demandera, et un brouillon ne coute rien.
 * Les semer SILENCIEUSEMENT ne le serait pas : la commande affiche donc, pour
 * chaque surface, si un consommateur existe aujourd'hui.
 */
class SeedUsageReferences extends Command
{
    protected $signature = 'ai:seed-usage-references {--dry-run : Montrer ce qui serait cree, sans rien ecrire}';

    protected $description = 'Seed the canonical UsageReference drafts (12 surfaces x FR/EN). Never overwrites, never publishes.';

    public function handle(): int
    {
        $texts = require database_path('seeders/data/usage_references.php');
        $dryRun = (bool) $this->option('dry-run');

        $created = 0;
        $kept = 0;
        $missing = [];

        foreach (UsageReference::SURFACES as $surface) {
            foreach (['fr', 'en'] as $locale) {
                $existing = UsageReference::query()->forSurface($surface)->forLocale($locale)->first();

                if ($existing !== null) {
                    $kept++;
                    $this->line(sprintf('  %-18s %s  inchangee (%s v%d)', $surface, $locale, $existing->state, $existing->version));

                    continue;
                }

                if (! isset($texts[$surface][$locale])) {
                    $missing[] = $surface.'/'.$locale;
                    $this->warn(sprintf('  %-18s %s  AUCUN texte canonique', $surface, $locale));

                    continue;
                }

                $text = $texts[$surface][$locale];

                if ($dryRun) {
                    $created++;
                    $this->line(sprintf('  %-18s %s  serait creee (draft)', $surface, $locale));

                    continue;
                }

                // La version repart du maximum existant : une surface dont
                // toutes les versions auraient ete retirees ne recommence pas
                // a 1 par-dessus une histoire deja ecrite.
                $version = (int) UsageReference::query()->forSurface($surface)->forLocale($locale)->max('version') + 1;

                UsageReference::query()->create([
                    'surface_key' => $surface,
                    'locale' => $locale,
                    'title' => $text['title'],
                    'content' => implode("\n", $text['lines']),
                    'version' => $version,
                    'state' => UsageReference::STATE_DRAFT,
                    'created_by' => null,
                    'published_by' => null,
                    'published_at' => null,
                ]);

                $created++;
                $this->info(sprintf('  %-18s %s  creee en BROUILLON v%d', $surface, $locale, $version));
            }
        }

        $this->newLine();
        $this->line($dryRun
            ? sprintf('%d a creer, %d inchangees.', $created, $kept)
            : sprintf('%d creees en brouillon, %d inchangees.', $created, $kept));

        if ($missing !== []) {
            $this->warn('Sans texte canonique : '.implode(', ', $missing));
        }

        $this->newLine();
        $this->line('Aucune publication : un brouillon n\'est servi a personne tant qu\'un humain ne l\'a pas publie.');
        $this->reportConsumers();

        return self::SUCCESS;
    }

    /**
     * Qui lit reellement ces textes, aujourd'hui. Derive de
     * `SURFACES_MEMBER` — la liste que `AiShellUsageReference` accepte — et non
     * d'une liste ecrite a la main qui mentirait au premier changement.
     */
    private function reportConsumers(): void
    {
        $orphans = array_values(array_diff(
            UsageReference::SURFACES,
            UsageReference::SURFACES_MEMBER,
            [UsageReference::SURFACE_SHELL_WELCOME],
        ));

        if ($orphans === []) {
            return;
        }

        $this->newLine();
        $this->warn('Surfaces sans consommateur mesure au 2026-09-09 : '.implode(', ', $orphans).'.');
        $this->line('Leurs brouillons sont crees quand meme — ils sont declares, et seront lus');
        $this->line('le jour ou un appelant les demandera. `shell_welcome` est la seule surface');
        $this->line('publique reellement demandee (GuestShellResponder, en dur).');
    }
}
