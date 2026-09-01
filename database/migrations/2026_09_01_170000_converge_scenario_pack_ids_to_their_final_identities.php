<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1354 — convergence des `pack_id` vers leurs identites FINALES.
 *
 * ## Pourquoi une SECONDE migration
 *
 * `2026_09_01_140000_rename_scenario_pack_ids_without_a_person_name` a ete
 * ecrite avec `artscilab-en-test` au SINGULIER, puis executee. L'arbitrage de
 * 14h55 a fixe le PLURIEL, `artscilab-en-tests`. Laravel ne rejoue jamais une
 * migration deja enregistree : modifier son contenu ne repare donc AUCUNE base
 * qui l'a deja passee. Elle est traitee ici comme HISTORIQUE et FROZEN.
 *
 * Sur la machine ou cela s'est produit, l'ecart est visible : le code attend le
 * pluriel, la ligne porte le singulier, le pack se declare « jamais charge »
 * alors que ses 88 entites sont bien la — et un rechargement les dupliquerait.
 *
 * Cette migration-ci ne renomme pas : elle CONVERGE. Elle amene tout etat connu
 * — historique ou intermediaire — vers l'identite finale, et ne fait rien
 * lorsque tout est deja en place.
 *
 * ## Ce qu'elle ne touche pas
 *
 * Ni `scenario_pack_entities`, ni le moteur Scenario Pack, ni aucun slug
 * d'organisation. Elle deplace une CHAINE dans `scenario_pack_loads.pack_id` :
 * la ligne garde son `id`, sa `pack_version`, son `organization_created_by_pack`
 * et donc toutes ses entites, qui la referencent par `load_id`.
 */
return new class extends Migration
{
    /**
     * Tout etat connu -> identite finale.
     *
     * Les trois premieres entrees rattrapent une base qui n'aurait jamais passe
     * la migration du 14h ; la quatrieme rattrape celle qui l'a passee AVANT
     * l'arbitrage. Les deux cas coexistent dans la nature, et cette table les
     * traite de la meme facon.
     */
    private const CONVERGENCES = [
        'artscilab-roger-demo' => 'artscilab-demo-test',
        'artscilab-en-dogfooding' => 'artscilab-en-tests',
        // L'etat INTERMEDIAIRE, ne d'un renommage fige entre deux arbitrages.
        'artscilab-en-test' => 'artscilab-en-tests',
        'test20260822-dogfooding' => 'test20260822',
    ];

    /**
     * Ce que `down()` sait defaire, et RIEN d'autre.
     *
     * Une seule origine par identite finale : l'identite canonique HISTORIQUE.
     * `artscilab-en-test` n'y figure pas volontairement — ce n'etait pas une
     * identite, c'etait un accident de sequencement. Y revenir recreerait le
     * defaut que cette migration corrige, et laisserait une base dans un etat
     * qu'aucune version du code n'a jamais attendu.
     */
    private const REVERSALS = [
        'artscilab-demo-test' => 'artscilab-roger-demo',
        'artscilab-en-tests' => 'artscilab-en-dogfooding',
        'test20260822' => 'test20260822-dogfooding',
    ];

    /**
     * Le nom AFFICHE de l'Organization du pack anglais.
     *
     * Meme garde que la migration du 14h, et pour la meme raison : on ne
     * renomme que si le slug ET le nom correspondent a une variante historique
     * CONNUE. Un nom qu'un humain a modifie ne correspond a aucune de ces
     * variantes, donc rien ne le touche. Aucun slug n'est modifie ici : le
     * changement de slug appartient a une TASK dediee.
     *
     * @var array<string, array{final: string, historical: list<string>}>
     */
    private const ORGANIZATION_NAMES = [
        'artscilab-en' => [
            'final' => 'ArtSciLab — Test anglais',
            'historical' => ['ArtSciLab — English dogfooding'],
        ],
    ];

    public function up(): void
    {
        if (! $this->tableExists()) {
            return;
        }

        // PREFLIGHT COMPLET, avant la moindre ecriture.
        //
        // Une organisation qui porterait DEJA les deux identites — l'ancienne et
        // la finale — ne peut pas etre convergee : l'index unique
        // (organization_id, pack_id) l'interdit, et il n'existe aucune facon
        // honnete de choisir. Fusionner perdrait des entites, supprimer
        // perdrait un chargement, trancher au hasard perdrait la confiance.
        //
        // On leve donc AVANT d'avoir rien modifie. Une migration qui echoue a
        // mi-chemin laisserait une base partiellement convergee, c'est-a-dire
        // le probleme meme qu'on est en train de reparer.
        $collisions = $this->collisions();

        if ($collisions !== []) {
            throw new RuntimeException(
                'TASK-1354 : convergence des pack_id impossible, '.count($collisions).' collision(s) '
                .'(l ancien ET le nouveau identifiant coexistent pour la meme organisation). '
                .'Aucune ligne n a ete modifiee. Arbitrer a la main lequel des deux chargements fait foi, '
                .'puis rejouer : '.implode(' ; ', $collisions)
            );
        }

        foreach (self::CONVERGENCES as $from => $to) {
            DB::table('scenario_pack_loads')
                ->where('pack_id', $from)
                ->update(['pack_id' => $to]);
        }

        foreach (self::ORGANIZATION_NAMES as $slug => $names) {
            DB::table('organizations')
                ->where('slug', $slug)
                ->whereIn('name', $names['historical'])
                ->update(['name' => $names['final']]);
        }
    }

    public function down(): void
    {
        if (! $this->tableExists()) {
            return;
        }

        foreach (self::REVERSALS as $from => $to) {
            DB::table('scenario_pack_loads')
                ->where('pack_id', $from)
                ->update(['pack_id' => $to]);
        }

        foreach (self::ORGANIZATION_NAMES as $slug => $names) {
            DB::table('organizations')
                ->where('slug', $slug)
                ->where('name', $names['final'])
                ->update(['name' => $names['historical'][0]]);
        }
    }

    /**
     * Les couples (organisation, ancien -> nouveau) ou les DEUX identites
     * existent deja. Une seule requete par entree de table, aucune ecriture.
     *
     * @return list<string>
     */
    private function collisions(): array
    {
        $collisions = [];

        foreach (self::CONVERGENCES as $from => $to) {
            $organizationIds = DB::table('scenario_pack_loads')
                ->where('pack_id', $from)
                ->pluck('organization_id');

            if ($organizationIds->isEmpty()) {
                continue;
            }

            $blocked = DB::table('scenario_pack_loads')
                ->where('pack_id', $to)
                ->whereIn('organization_id', $organizationIds)
                ->pluck('organization_id');

            foreach ($blocked as $organizationId) {
                $collisions[] = "organization {$organizationId} : {$from} -> {$to}";
            }
        }

        return $collisions;
    }

    /**
     * Une base ou la table n'existe pas encore n'a rien a converger. Le garde
     * evite aussi de dependre de l'ordre des migrations sur une base neuve.
     */
    private function tableExists(): bool
    {
        return DB::getSchemaBuilder()->hasTable('scenario_pack_loads');
    }
};
