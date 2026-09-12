<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * TASK-1539 — la connaissance derivee ne se lit QUE derriere son autorite.
 *
 * `DerivedChunkEligibility` porte une garde de confidentialite :
 *
 *     visibilite(derive) ⊆ visibilite(Boucle source) ∩ visibilite(Dossier)
 *
 * evaluee a la lecture, dans le SQL, et fermee par defaut. Tant qu'elle est le
 * SEUL chemin vers `dossier_chunks.derived_knowledge_note_id`, cette garde est
 * structurelle. Le jour ou un service interroge cette colonne directement,
 * elle redevient une convention — et une convention ne protege rien.
 *
 * Ce test remplace une Policy formelle, qui n'aurait rien ajoute ici : il n'y a
 * pas d'objet « note derivee » a autoriser cote produit, il y a une CLAUSE a ne
 * pas contourner. C'est donc le contournement qu'on interdit.
 *
 * Ajouter un fichier a la liste ci-dessous est une decision : elle se prend en
 * revue, avec sa raison, jamais par accident.
 */
class DerivedKnowledgeStaysBehindItsAuthorityTest extends TestCase
{
    /**
     * Les seuls fichiers de production autorises a nommer la troisieme famille.
     *
     * @var list<string>
     */
    private const AUTORISES = [
        // L'autorite elle-meme : la clause, la jointure, les colonnes.
        'app/Services/Dossiers/DerivedChunkEligibility.php',
        // Les deux chemins documentaires, qui l'APPELLENT et lisent ses lignes.
        'app/Services/Dossiers/DossierSemanticSearchService.php',
        // Le cote WRITE : il ecrit les notes et leurs vecteurs.
        'app/Services/Knowledge/DerivedKnowledgeNoteIndexer.php',
        'app/Services/Knowledge/LoopConversationKnowledgeDeriver.php',
        // TASK-1540 : le cote WRITE claim-level. `ClaimMemory` ecrit les
        // enonces et arbitre leur concurrence ; `LoopClaimCompiler` lit les
        // enonces actifs pour les presenter au modele. Aucun des deux ne SERT
        // de connaissance a un lecteur — c'est ce que cette garde protege —
        // et l'un comme l'autre laissent `DerivedChunkEligibility` seule
        // maitresse du retrieval.
        'app/Services/Knowledge/ClaimMemory.php',
        'app/Services/Knowledge/LoopClaimCompiler.php',
        // Le modele qui porte la colonne.
        'app/Models/DossierChunk.php',
        'app/Models/DerivedKnowledgeNote.php',
    ];

    public function test_aucun_service_ne_court_circuite_l_autorite_d_eligibilite(): void
    {
        $racine = dirname(__DIR__, 3);
        $fautifs = [];

        $fichiers = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($racine.'/app', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($fichiers as $fichier) {
            if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
                continue;
            }

            $relatif = str_replace($racine.'/', '', $fichier->getPathname());

            if (in_array($relatif, self::AUTORISES, true)) {
                continue;
            }

            $contenu = (string) file_get_contents($fichier->getPathname());

            if (str_contains($contenu, 'derived_knowledge_note_id')
                || str_contains($contenu, 'derived_knowledge_notes')) {
                $fautifs[] = $relatif;
            }
        }

        sort($fautifs);

        $this->assertSame([], $fautifs,
            "La connaissance derivee doit rester derriere `DerivedChunkEligibility` : sa garde d'ACL "
            ."vit dans cette clause, et un acces direct la contournerait en silence.\n"
            .'Fichiers concernes : '.implode(', ', $fautifs));
    }

    public function test_la_liste_des_autorises_decrit_des_fichiers_qui_existent(): void
    {
        // Une allowlist qui pointe vers des fichiers disparus s'elargit toute
        // seule : le fichier renomme n'est plus couvert, et personne ne le voit.
        $racine = dirname(__DIR__, 3);

        foreach (self::AUTORISES as $relatif) {
            $this->assertFileExists($racine.'/'.$relatif,
                "L'allowlist nomme un fichier qui n'existe plus : elle doit etre relue, pas contournee.");
        }
    }
}
