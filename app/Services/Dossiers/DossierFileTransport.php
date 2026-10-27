<?php

namespace App\Services\Dossiers;

use App\Models\DossierFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TASK-1695 geste C — COMMENT un fichier est servi, une seule fois.
 *
 * ## Pourquoi cette classe existe
 *
 * Le partage de fichier (TASK-1695) a besoin de ses PROPRES routes de lecture :
 * le destinataire ne doit jamais emprunter l'URL du Dossier d'autrui. Mais
 * servir le fichier — disque local ou distant, type affichable, disposition —
 * est exactement le meme geste des deux cotes.
 *
 * Le recopier aurait donne deux regles pour une idee, et c'est la copie qu'on
 * oublie de corriger. Le code est donc EXTRAIT de `DossierFileController`, a
 * l'identique, et les deux controleurs l'appellent.
 *
 * ## Ce que cette classe ne fait PAS
 *
 * Elle n'autorise rien. Elle ne connait ni Dossier, ni partage, ni policy : on
 * ne l'atteint qu'une fois l'acces decide. Melanger transport et autorisation
 * ferait du premier un endroit ou l'on peut se tromper sur le second.
 */
class DossierFileTransport
{
    /** Telechargement : le fichier arrive en piece jointe. */
    public function download(DossierFile $file): RedirectResponse|StreamedResponse
    {
        return $this->servir($file, [
            'Content-Type' => $file->mime_type,
        ]);
    }

    /** Apercu : le fichier s'affiche dans l'onglet quand le navigateur sait. */
    public function preview(DossierFile $file): RedirectResponse|StreamedResponse
    {
        return $this->servir($file, [
            'Content-Type' => $this->typeAffichable($file->mime_type),
            'Content-Disposition' => 'inline; filename="'.$file->original_name.'"',
        ]);
    }

    /**
     * TASK-1704 — le meme transport, pour un fichier qui n'est pas un
     * `DossierFile`.
     *
     * La messagerie accepte desormais des pieces jointes, posees sur un disque
     * prive et servies par une route gardee. Le geste de SERVIR y est
     * identique : meme branchement local / distant, meme duree d'URL signee,
     * meme repli en 404. Le recopier aurait donne deux regles pour une idee —
     * ce que l'extraction de TASK-1695 avait justement supprime.
     *
     * Les methodes `DossierFile` ci-dessus delaissent leur corps au profit de
     * celle-ci : leurs appelants sont donc inchanges par construction, et non
     * par relecture.
     *
     * DETTE NOMMEE : cette classe n'a plus rien de specifique aux Dossiers et
     * devrait vivre sous `App\Services\Files`. La deplacer toucherait les
     * deux controleurs de Dossiers, hors du perimetre du bug corrige ici.
     */
    public function servirDepuis(
        string $disque,
        string $chemin,
        string $nom,
        array $entetes,
    ): RedirectResponse|StreamedResponse {
        try {
            if (config("filesystems.disks.{$disque}.driver") === 'local') {
                return Storage::disk($disque)->download($chemin, $nom, $entetes);
            }

            return redirect()->away(
                Storage::disk($disque)->temporaryUrl($chemin, now()->addMinutes(30)),
            );
        } catch (\Exception $e) {
            abort(404);
        }
    }

    /** Telechargement d'un fichier quelconque : il arrive en piece jointe. */
    public function telechargerDepuis(string $disque, string $chemin, string $nom, ?string $mime): RedirectResponse|StreamedResponse
    {
        return $this->servirDepuis($disque, $chemin, $nom, [
            'Content-Type' => $mime ?: 'application/octet-stream',
        ]);
    }

    /** Apercu d'un fichier quelconque, quand le navigateur sait le rendre. */
    public function apercuDepuis(string $disque, string $chemin, string $nom, ?string $mime): RedirectResponse|StreamedResponse
    {
        return $this->servirDepuis($disque, $chemin, $nom, [
            'Content-Type' => $this->typeAffichable($mime),
            'Content-Disposition' => 'inline; filename="'.$this->nomPourEntete($nom).'"',
        ]);
    }

    /**
     * Un nom de fichier rendu inoffensif dans un en-tete.
     *
     * Un guillemet droit referme `filename="` et laisse injecter une directive
     * derriere ; un retour a la ligne COUPE l'en-tete et permet d'en ajouter un
     * autre. Les deux sont retires. Cote Dossiers, `original_name` est
     * construit par le produit ; cote messagerie, il vient de celui qui depose
     * — c'est la que cette precaution compte.
     */
    private function nomPourEntete(string $nom): string
    {
        return trim(preg_replace('/["\\\\\x00-\x1F\x7F]/u', '', $nom) ?? '');
    }

    private function servir(DossierFile $file, array $entetes): RedirectResponse|StreamedResponse
    {
        return $this->servirDepuis($file->disk, $file->path, $file->original_name, $entetes);
    }

    /**
     * Le type sous lequel un apercu s'affiche REELLEMENT dans un navigateur.
     *
     * Repris mot pour mot de `DossierFileController` (TASK-1659) :
     * `Content-Disposition: inline` demande l'affichage ; encore faut-il que le
     * navigateur sache rendre le type. Aucun n'embarque de moteur pour
     * `text/markdown` : servi tel quel, le fichier se telecharge malgre
     * l'en-tete, et la source d'une reponse IA devient invisible au moment meme
     * ou le membre veut la verifier.
     *
     * Le Markdown est du texte : l'annoncer comme tel le rend lisible dans
     * l'onglet, sans convertir quoi que ce soit. Le type STOCKE n'est pas
     * modifie — c'est une decision de transport, pas de donnee.
     *
     * Ne concerne QUE le disque local : sur un disque distant, la reponse est
     * une redirection vers une URL signee, et la disposition depend alors des
     * metadonnees de l'objet, pas de ce code. La dette etait deja signalee la,
     * elle l'est encore ici — elle n'a pas bouge.
     */
    public function typeAffichable(?string $mimeType): string
    {
        if (in_array($mimeType, ['text/markdown', 'text/x-markdown'], true)) {
            return 'text/plain; charset=UTF-8';
        }

        return $mimeType ?? 'application/octet-stream';
    }
}
