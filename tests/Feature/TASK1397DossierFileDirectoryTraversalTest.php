<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1397 — une source citee s'ouvre reellement.
 *
 * ## Le defaut, MESURE
 *
 * Sur le banc, les 16 fichiers RAG du tenant `artscilab-en` repondaient 404 a
 * l'apercu. Ils etaient pourtant tous presents, et tous lisibles :
 *
 * | mesure | valeur |
 * |---|---|
 * | fichiers `artscilab-en` | `-rw-r--r--` — lisibles par tous |
 * | repertoires `artscilab-en` | `drwx--S---` — **0700** |
 * | proprietaire | `cyril:www-data` |
 * | processus qui SERT | `apache2` en **www-data** |
 *
 * Le fichier etait lisible ; le CHEMIN ne l'etait pas. Un repertoire sans bit
 * `x` pour le groupe ne se traverse pas, et aucune permission posee plus bas
 * ne rattrape cela.
 *
 * ## Pourquoi ce n'etait pas un accident de ce tenant
 *
 * Sonde executee sur le disque `dossier_files` avant correction : un simple
 * `put('a/b/x')` creait `a` et `b` en **0700**. La cause n'est donc pas une
 * manipulation malheureuse mais le DEFAUT de Flysystem — `defaultForDirectories`
 * vaut `private`, soit 0700 — que la configuration du disque ne contredisait
 * nulle part. Les repertoires sains du banc (43 sur 72) ne l'etaient que pour
 * avoir ete chmodes a la main un jour ; les 8 restants etaient simplement les
 * plus recents. Tout depot futur, et tout rejeu de ScenarioPack, reproduisait
 * le defaut.
 *
 * Le symptome, lui, ment : `DossierFileController::preview()` rattrape l'echec
 * de lecture en `abort(404)`. Une source citee par l'IA se presente comme
 * ABSENTE alors qu'elle n'est qu'inaccessible — c'est la pire forme du defaut,
 * parce qu'elle envoie chercher un fichier manquant qui ne manque pas.
 *
 * ## Ce que cette tranche mesure
 *
 * Le VERDICT, pas la cle de configuration : on ecrit reellement a travers le
 * disque et on regarde le mode obtenu. Une cle presente mais mal formee, ou
 * ignoree par le driver, ne passerait pas.
 */
class TASK1397DossierFileDirectoryTraversalTest extends TestCase
{
    private const DISQUE = 'task1397_probe';

    private string $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->racine = storage_path('framework/testing/task1397-'.getmypid().'-'.uniqid());

        // La configuration REELLE du disque, avec pour seule difference sa
        // racine. Recopier les permissions ici ferait mesurer au test sa
        // propre fixture au lieu du disque de production.
        config(['filesystems.disks.'.self::DISQUE => array_merge(
            config('filesystems.disks.dossier_files'),
            ['driver' => 'local', 'root' => $this->racine],
        )]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->racine);

        parent::tearDown();
    }

    /**
     * Un repertoire cree par le disque se TRAVERSE par le groupe.
     *
     * La mesure du defaut d'origine. Le processus qui ecrit (console, worker,
     * ScenarioPack) n'est pas celui qui sert : sans bit `x` de groupe, tout ce
     * que l'un depose devient invisible a l'autre.
     */
    public function test_a_directory_created_by_the_disk_is_group_traversable(): void
    {
        Storage::disk(self::DISQUE)->put('sonic_terrain/notes.md', '# probe');

        foreach (['sonic_terrain'] as $repertoire) {
            $mode = fileperms($this->racine.'/'.$repertoire) & 0777;

            $this->assertSame(
                0050,
                $mode & 0050,
                sprintf('Le repertoire %s vaut %o : le groupe ne peut pas le traverser.', $repertoire, $mode),
            );
        }
    }

    /**
     * Les repertoires INTERMEDIAIRES aussi.
     *
     * Un seul maillon sans bit `x` suffit a fermer le chemin, et c'est
     * precisement ce qui s'etait produit : la racine du tenant etait fermee
     * autant que ses sous-repertoires. Mesurer uniquement le dernier
     * repertoire laisserait passer une correction qui n'agirait que sur lui.
     */
    public function test_every_intermediate_directory_is_group_traversable(): void
    {
        Storage::disk(self::DISQUE)->put('artscilab-en/nsf_steam_bridge/outline.md', '# probe');

        foreach (['artscilab-en', 'artscilab-en/nsf_steam_bridge'] as $repertoire) {
            $mode = fileperms($this->racine.'/'.$repertoire) & 0777;

            $this->assertSame(
                0050,
                $mode & 0050,
                sprintf('Le repertoire %s vaut %o : le chemin est coupe a ce maillon.', $repertoire, $mode),
            );
        }
    }

    /**
     * Le fichier depose reste lisible par le groupe.
     *
     * L'autre moitie du chemin. Rouvrir les repertoires ne sert a rien si la
     * feuille, elle, se ferme : la mesure porte sur la promesse entiere
     * « le serveur web peut lire ce fichier », pas sur son avant-dernier metre.
     */
    public function test_the_stored_file_stays_group_readable(): void
    {
        Storage::disk(self::DISQUE)->put('consent_ethics/consent.md', '# probe');

        $mode = fileperms($this->racine.'/consent_ethics/consent.md') & 0777;

        $this->assertSame(0040, $mode & 0040, sprintf('Le fichier vaut %o : le groupe ne peut pas le lire.', $mode));
    }

    /**
     * Le disque reste PRIVE.
     *
     * Le contre-exemple, et il est indispensable : `chmod 0777` ferait passer
     * les trois mesures precedentes en transformant un defaut d'acces en
     * defaut de confidentialite. Ces fichiers sont des pieces de dossier —
     * ouvrir au reste du monde serait un echec pire que le 404.
     */
    public function test_nothing_is_opened_to_the_world(): void
    {
        Storage::disk(self::DISQUE)->put('visiting_fellows/checklist.txt', 'probe');

        $repertoire = fileperms($this->racine.'/visiting_fellows') & 0777;

        $this->assertSame(
            0,
            $repertoire & 0007,
            sprintf('Le repertoire vaut %o : il est ouvert au reste du monde.', $repertoire),
        );

        // Le fichier, lui, sort en 0644 depuis toujours : cette tranche ne
        // touche pas aux permissions de fichier et ne pretend donc pas les
        // avoir refermees. Ce qu'elle doit interdire, c'est qu'on les OUVRE
        // en ecriture au passage.
        $fichier = fileperms($this->racine.'/visiting_fellows/checklist.txt') & 0777;

        $this->assertSame(
            0,
            $fichier & 0002,
            sprintf('Le fichier vaut %o : il est modifiable par n\'importe qui.', $fichier),
        );
    }
}
