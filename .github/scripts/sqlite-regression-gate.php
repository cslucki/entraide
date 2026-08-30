<?php

declare(strict_types=1);

/**
 * Compare les echecs SQLite observes a la reference, **par identite**.
 *
 * Usage :
 *   php .github/scripts/sqlite-regression-gate.php <rapport.xml> [<rapport2.xml> ...] <reference.txt>
 *
 * La reference est **toujours le dernier argument** ; tout ce qui la precede
 * est un rapport JUnit. Avec deux arguments, c'est exactement l'appel
 * historique — la forme d'hier reste valide, mot pour mot.
 *
 * ## Pourquoi ce script existe
 *
 * Un compte ne prouve rien. Reference `A B C` contre observe `A B D` : trois
 * echecs de part et d'autre, et pourtant `C` a ete repare pendant que `D`
 * cassait. Le gate doit rougir. C'est arrive quatre fois entre TASK-1119 et
 * TASK-1125 — a chaque fois le diff nominatif a vu ce que le compte cachait.
 *
 * ## Pourquoi du JUnit et pas la sortie du terminal
 *
 * La sortie decorative de Pest **tronque** les noms a la largeur du terminal :
 *
 *     Tests\Feature\Livewire\MessageThreadTest > buyer c…  ViewException
 *     Tests\Feature\Livewire\MessageThreadTest > buyer c…  ViewException
 *
 * Deux tests differents, une seule chaine. Une reference batie la-dessus ne
 * distingue pas le remplacement d'une methode par une autre dans une classe au
 * nom long, et sa troncature depend du terminal — donc irreproductible sur un
 * runner. Le JUnit XML porte `classname` et `name` entiers.
 *
 * ## Ce que le gate considere comme rouge
 *
 * - un echec **nouveau** : regression, c'est le cas evident ;
 * - un echec **disparu** : bonne nouvelle, mais rouge quand meme, pour que la
 *   reference soit reduite volontairement et que le progres se voie dans un
 *   diff Git. Sans cela une dette ne decroit jamais : personne ne remarque
 *   qu'elle pourrait.
 */

// ── Entrees ─────────────────────────────────────────────────────────────────

// TASK-1334 : la suite SQLite tourne desormais en quatre shards, sur quatre
// runners, et produit donc quatre rapports JUnit. Le gate les agrege avant de
// comparer — la comparaison elle-meme, par identite, est inchangee.
//
// **Chaque rapport attendu doit etre present.** Un shard dont le rapport
// manque a l'appel signifie une suite qui n'a pas demarre : ses echecs
// seraient silencieusement absents de l'ensemble observe, et le gate
// annoncerait un « progres » la ou il y a un trou. C'est le seul moyen pour
// qu'un decoupage rende un gate menteur, et il est ferme ici.

$arguments = array_slice($argv, 1);

if (count($arguments) < 2) {
    fwrite(STDERR, "usage: sqlite-regression-gate.php <rapport.xml> [<rapport2.xml> ...] <reference.txt>\n");
    exit(2);
}

$fichierReference = array_pop($arguments);
$rapports = $arguments;

foreach ($rapports as $rapport) {
    if (! is_file($rapport)) {
        fwrite(STDERR, "Rapport JUnit introuvable : {$rapport}\n");
        fwrite(STDERR, "Un shard n'a pas rendu son rapport — la suite n'a probablement pas demarre.\n");
        fwrite(STDERR, "Lire l'etape correspondante avant toute autre hypothese.\n");
        exit(2);
    }
}

// ── Lecture du rapport ──────────────────────────────────────────────────────

/**
 * Identite canonique d'un test : `Espace.De.Noms.Classe::methode`.
 *
 * PHPUnit ecrit `classname` avec des antislashs, Pest avec des points. On
 * normalise vers le point pour que la reference survive au lanceur utilise.
 */
function identite(SimpleXMLElement $cas): string
{
    $classe = str_replace('\\', '.', (string) $cas['classname']);
    $methode = (string) $cas['name'];

    return $classe.'::'.$methode;
}

$observes = [];
$total = 0;
$parRapport = [];

foreach ($rapports as $rapport) {
    $xml = @simplexml_load_file($rapport);

    if ($xml === false) {
        fwrite(STDERR, "Rapport JUnit illisible : {$rapport}\n");
        exit(2);
    }

    $comptes = 0;

    foreach ($xml->xpath('//testcase') ?: [] as $cas) {
        $comptes++;

        // `failure` = assertion fausse ; `error` = exception ou erreur PHP.
        // Les deux rendent le test rouge et doivent donc entrer dans la
        // comparaison.
        if (isset($cas->failure) || isset($cas->error)) {
            $observes[] = identite($cas);
        }
    }

    // Un rapport vide est aussi grave qu'un rapport absent : le shard s'est
    // termine sans rien executer. Le signaler par rapport, et pas seulement
    // sur le total, evite qu'un shard muet se cache derriere trois bavards.
    if ($comptes === 0) {
        fwrite(STDERR, "Aucun test dans le rapport {$rapport} : ce shard ne s'est pas execute.\n");
        exit(2);
    }

    $parRapport[$rapport] = $comptes;
    $total += $comptes;
}

if ($total === 0) {
    fwrite(STDERR, "Aucun test dans les rapports : la suite ne s'est pas executee.\n");
    exit(2);
}

sort($observes);
$observes = array_values(array_unique($observes));

// ── Lecture de la reference ─────────────────────────────────────────────────

if (! is_file($fichierReference)) {
    fwrite(STDERR, "Reference absente : {$fichierReference}\n\n");
    fwrite(STDERR, "Ensemble observe, a inscrire dans la reference apres verification :\n\n");
    fwrite(STDERR, implode("\n", $observes)."\n");
    exit(2);
}

$reference = [];

foreach (file($fichierReference, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
    $ligne = trim($ligne);

    if ($ligne === '' || str_starts_with($ligne, '#')) {
        continue;
    }

    $reference[] = $ligne;
}

sort($reference);
$reference = array_values(array_unique($reference));

// ── Comparaison ─────────────────────────────────────────────────────────────

$nouveaux = array_values(array_diff($observes, $reference));
$disparus = array_values(array_diff($reference, $observes));

echo "=====================================\n";
echo "SQLITE REGRESSION GATE\n";
echo "=====================================\n\n";
if (count($parRapport) > 1) {
    echo "  Rapports agreges :\n";

    foreach ($parRapport as $rapport => $comptes) {
        printf("    %-28s %5d tests\n", basename($rapport), $comptes);
    }

    echo "\n";
}

printf("  Tests executes   : %d\n", $total);
printf("  Echecs observes  : %d\n", count($observes));
printf("  Echecs attendus  : %d\n\n", count($reference));

if ($nouveaux === [] && $disparus === []) {
    echo "  VERT — l'ensemble des echecs est exactement celui de la reference.\n\n";
    exit(0);
}

if ($nouveaux !== []) {
    printf("  REGRESSION — %d echec(s) que la reference ne contient pas :\n\n", count($nouveaux));

    foreach ($nouveaux as $identite) {
        echo "    + {$identite}\n";
    }

    echo "\n  Reproduire en local, cible :\n";
    echo "    ./ai/scripts/safe-test.sh --filter <NomDeClasse>\n\n";
}

if ($disparus !== []) {
    printf("  PROGRES — %d echec(s) attendu(s) ne se produisent plus :\n\n", count($disparus));

    foreach ($disparus as $identite) {
        echo "    - {$identite}\n";
    }

    echo "\n  Bonne nouvelle, mais le gate reste rouge tant que la reference le\n";
    echo "  pretend rouge. Retirer ces lignes de la reference dans cette PR :\n";
    echo "  le progres doit se voir dans le diff Git.\n\n";
}

echo "  Reference : {$fichierReference}\n";
echo "  L'AUGMENTER exige une validation orchestrateur ou une tache dediee.\n";
echo "  La modifier pour faire passer une CI est interdit.\n\n";

exit(1);
