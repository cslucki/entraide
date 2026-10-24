<?php

namespace App\Http\Controllers;

use App\Models\DossierFile;
use App\Services\Dossiers\DossierFileTransport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TASK-1695 geste C — lire un fichier qu'on vous a PARTAGE.
 *
 * ## Pourquoi des routes a part, et pas celles du Dossier
 *
 * C'est la decision de conception de cette TASK, et la raison tient en une
 * phrase : **le destinataire n'emprunte jamais l'URL du Dossier d'autrui.**
 *
 * Les routes existantes sont imbriquees sous
 * `/dossiers/{dossier}/files/{file}` et autorisent sur le DOSSIER. Y faire
 * accepter un partage de fichier aurait oblige a masquer tout le reste du
 * Dossier au destinataire — chaque voisin, chaque sous-dossier, chaque Article.
 * C'est la que les fuites se logent.
 *
 * Ici, le Dossier n'apparait ni dans l'URL ni dans le code. La policy du
 * Dossier n'est pas touchee.
 *
 * ## Ce que ces routes servent, et rien d'autre
 *
 * Telecharger et previsualiser. Arbitrage de Cyril (chemin « C ») : LECTURE
 * SEULE. Il n'y a deliberement ni renommage, ni deplacement, ni suppression —
 * et ce n'est pas un oubli a combler plus tard : `manageFiles` et `deleteFile`
 * restent une pure delegation au Dossier, ce qui rend cette absence
 * demontrable.
 *
 * ## L'autorisation porte sur le FICHIER
 *
 * `authorize('viewFiles', $file)` — l'objet est un `DossierFile`, donc
 * `DossierFilePolicy` repond : acces par le Dossier OU partage explicite. Un
 * membre qui a deja acces par son Dossier passe aussi par ici sans erreur, ce
 * qui evite d'avoir a savoir, cote vue, PAR QUEL chemin on a le droit.
 *
 * ## Le tenant, deux fois
 *
 * La route vit sous `/org/{organization}` (middlewares `auth` +
 * `organization.member`), et la policy reverifie que le fichier et la personne
 * appartiennent a l'Organization courante. Une frontiere qui ne se verifie
 * qu'au routage n'est pas une frontiere (lecon TASK-1691).
 */
class SharedDossierFileController extends Controller
{
    public function __construct(private readonly DossierFileTransport $transport) {}

    public function show(Request $request): RedirectResponse|StreamedResponse
    {
        return $this->transport->download($this->fichierAutorise($request));
    }

    public function preview(Request $request): RedirectResponse|StreamedResponse
    {
        return $this->transport->preview($this->fichierAutorise($request));
    }

    /**
     * Le fichier, une fois l'acces decide — et jamais avant.
     *
     * `withoutTrashed()` : un fichier supprime en douceur n'existe plus pour ce
     * chemin. La policy le refuse deja (elle teste `trashed()`), mais le dire
     * ici rend la reponse 404 plutot que 403 — « il n'y a rien la » est la
     * verite, et c'est aussi ce qui n'apprend rien a qui sonde des
     * identifiants.
     */
    private function fichierAutorise(Request $request): DossierFile
    {
        $file = DossierFile::query()
            ->withoutTrashed()
            ->whereKey($request->route('file'))
            ->firstOrFail();

        $this->authorize('viewFiles', $file);

        return $file;
    }
}
