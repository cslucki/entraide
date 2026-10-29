<?php

namespace App\Support\Loops;

use App\Models\Loop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * TASK-1725 (demande Cyril, 29/10) — l'image de couverture d'une Boucle, en UN
 * seul endroit.
 *
 * ## Pourquoi une extraction et pas une copie
 *
 * `storeCoverImage()` et `deleteCoverImage()` vivaient en `private` dans
 * `LoopController`. L'admin d'une Organization doit pouvoir changer la
 * couverture depuis son propre ecran de modification : il fallait donc ou bien
 * recopier les deux methodes, ou bien les sortir.
 *
 * Recopier aurait garanti qu'elles divergent — au premier format d'image
 * ajoute, au premier changement de disque, a la premiere correction de la garde
 * de chemin. Cyril, 29/10 : « surtout ne pas les dupliquer ».
 *
 * Le code est repris A L'IDENTIQUE, sans en profiter pour l'ameliorer : une
 * extraction qui change aussi le comportement ne se relit pas, et on ne sait
 * plus lequel des deux gestes a casse quoi.
 *
 * ## Les deux garanties qu'il porte
 *
 * - L'ANCIENNE image est supprimee avant d'en poser une nouvelle. Sans cela
 *   chaque changement laisserait un fichier orphelin sur le disque public.
 * - `deleteCoverImage()` refuse tout chemin qui ne commence pas par
 *   `loop-covers/`. La garde n'est pas decorative : le chemin vient de la base,
 *   et une colonne qui porterait autre chose — migration bancale, reprise de
 *   donnees — ferait supprimer un fichier quelconque du disque public.
 *
 * ## Le nom de fichier n'emprunte RIEN a celui televerse
 *
 * `Str::random(32)` plus l'extension. Le nom d'origine est choisi par la
 * personne qui televerse : le reprendre exposerait un chemin devinable et ferait
 * entrer sa ponctuation dans une URL publique.
 */
trait CouvertureDeBoucle
{
    /**
     * Les regles de validation du champ, identiques aux deux chemins existants.
     *
     * Exposees ici pour que les appelants ne les reecrivent pas : c'est le
     * second endroit ou une divergence se serait installee.
     *
     * @return array<string, array<int, string>|string>
     */
    protected function reglesDeCouverture(bool $avecRetrait = true): array
    {
        $regles = ['cover_image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048'];

        if ($avecRetrait) {
            $regles['remove_cover_image'] = 'nullable|boolean';
        }

        return $regles;
    }

    /**
     * Applique ce que la requete demande : retrait, remplacement, ou rien.
     *
     * Rend le chemin a ecrire, ou `null` pour un retrait — et `false` quand il
     * n'y a RIEN a faire, ce que l'appelant distingue d'un retrait. Un `null`
     * seul confondrait « efface la couverture » et « n'y touche pas », et le
     * second cas effacerait alors l'image a chaque enregistrement du formulaire.
     */
    protected function couvertureDemandee(Request $request, Loop $loop): string|null|false
    {
        if ($request->boolean('remove_cover_image') && $loop->cover_image_path) {
            $this->supprimerCouverture($loop->cover_image_path);

            return null;
        }

        if ($request->hasFile('cover_image')) {
            return $this->rangerCouverture($request, $loop);
        }

        return false;
    }

    protected function rangerCouverture(Request $request, Loop $loop): string
    {
        if ($loop->cover_image_path) {
            $this->supprimerCouverture($loop->cover_image_path);
        }

        $filename = Str::random(32).'.'.$request->file('cover_image')->extension();

        return $request->file('cover_image')->storeAs(
            'loop-covers/'.$loop->id,
            $filename,
            'public',
        );
    }

    protected function supprimerCouverture(string $path): void
    {
        if (str_starts_with($path, 'loop-covers/') && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
