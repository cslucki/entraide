<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1354 — renommer deux `pack_id`, sans perdre les chargements existants.
 *
 * ## Pourquoi renommer
 *
 * `artscilab-roger-demo` portait le PRENOM d'une personne reelle dans un
 * identifiant affiche a l'ecran d'administration. Decision de Cyril : les packs
 * de demonstration se nomment par leur langue, pas par quelqu'un.
 *
 *   artscilab-roger-demo    -> artscilab-demo-test
 *   artscilab-en-dogfooding -> artscilab-en-test
 *
 * `test20260822-dogfooding`, la demonstration historique, ne change pas.
 *
 * ## Pourquoi une migration, et pas seulement un renommage de code
 *
 * `scenario_pack_loads.pack_id` est un identifiant PERSISTE : le couple
 * (organization_id, pack_id) est unique et c'est lui qui relie un chargement a
 * ses entites. Renommer la constante sans toucher aux lignes laisserait le
 * chargement existant orphelin — le pack se declarerait « jamais charge » alors
 * que ses entites sont bien la, et un rechargement les dupliquerait.
 *
 * Au moment de l'ecriture, `artscilab-en-dogfooding` compte un chargement reel
 * (88 entites) et `artscilab-roger-demo` aucun. La migration traite les deux de
 * la meme facon : elle renomme ce qui existe, et ne fait rien la ou il n'y a
 * rien.
 *
 * Reversible : `down()` remet les identifiants d'origine.
 */
return new class extends Migration
{
    private const RENAMES = [
        // Arbitrage MASTER : PAS « fr ». Le contenu de ce pack est ANGLAIS ; un
        // identifiant durable ne doit pas embarquer une information fausse, et
        // `artscilab-demo-test` est reserve a un futur VRAI scenario francais.
        'artscilab-roger-demo' => 'artscilab-demo-test',
        'artscilab-en-dogfooding' => 'artscilab-en-test',
        // « dogfooding » decrit une intention d'usage, pas ce qu'est le jeu de
        // donnees — et il devient faux des qu'un pack sert a preparer du reel.
        // Decision de Cyril : ces packs sont des packs de TEST.
        'test20260822-dogfooding' => 'test20260822',
    ];

    /** Le nom AFFICHE de l'Organization creee par le pack EN. */
    private const ORGANIZATION_RENAMES = [
        'artscilab-en' => ['ArtSciLab — English dogfooding', 'ArtSciLab — Test anglais'],
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            DB::table('scenario_pack_loads')->where('pack_id', $old)->update(['pack_id' => $new]);
        }

        foreach (self::ORGANIZATION_RENAMES as $slug => [$old, $new]) {
            DB::table('organizations')->where('slug', $slug)->where('name', $old)->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $old => $new) {
            DB::table('scenario_pack_loads')->where('pack_id', $new)->update(['pack_id' => $old]);
        }

        foreach (self::ORGANIZATION_RENAMES as $slug => [$old, $new]) {
            DB::table('organizations')->where('slug', $slug)->where('name', $new)->update(['name' => $old]);
        }
    }
};
