<?php

namespace App\Http\Controllers;

use App\Models\DossierFile;
use App\Models\DossierFileShare;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * TASK-1695 geste D — partager un fichier, et retirer ce partage.
 *
 * ## Qui peut partager
 *
 * `manageFiles` sur le fichier — c'est-a-dire ceux qui peuvent deja le
 * renommer, le deplacer, le supprimer. Arbitrage pose avec Cyril : PAS un
 * simple lecteur, sinon un destinataire pourrait repartager, ce que le chemin
 * « C » exclut. L'ability etant une pure delegation au Dossier (geste B), le
 * droit de partager suit exactement celui de gerer le fichier — il n'y a pas
 * une seconde regle a tenir a jour.
 *
 * ## Avec qui
 *
 * N'importe quel membre de l'Organization (decision de Cyril). C'est PLUS LARGE
 * que le partage de Dossier, qui exclut le proprietaire et les membres deja
 * poses : note comme tel dans le cadrage, parce que c'est la surface qu'un
 * audit regardera en premier.
 *
 * ## Le tenant vient du FICHIER
 *
 * Jamais de `currentOrganization()` pour ECRIRE la ligne : une autorisation
 * posee d'apres le contexte de la page serait posee d'apres le client. Le
 * contexte sert a borner l'acces (middleware, policy) ; la donnee vient de
 * l'objet (lecon TASK-1691).
 */
class DossierFileShareController extends Controller
{
    /**
     * Les partages en place, pour peupler l'ecran.
     *
     * AUCUN email dans la charge utile — voir `search()`, meme raison.
     */
    public function index(Request $request): JsonResponse
    {
        $file = $this->fichierGerable($request);

        return response()->json([
            'shares' => $file->shares()
                ->with('user:id,name,first_name,avatar,email,banned_at,organization_id')
                ->get()
                ->map(function (DossierFileShare $share) use ($file) {
                    $visible = $share->user?->isDisplayableIn($file->organization_id) ?? false;

                    return [
                        'id' => $share->id,
                        'user_id' => $share->user_id,
                        'name' => $visible ? $share->user?->name : __('profile.deactivated_user'),
                        'first_name' => $visible ? $share->user?->first_name : null,
                        // TASK-1695 (point 1 de Cyril) — le VISAGE, pour
                        // l'apercu « partage avec qui ».
                        //
                        // `null` des que la personne n'a pas depose d'avatar :
                        // `avatar_url` retombe sinon sur un service TIERS, a qui
                        // on enverrait son nom pour dessiner deux lettres qu'on
                        // sait dessiner ici. Meme precaution que
                        // `DossierFileController::publicUserPayload()`.
                        'avatar_url' => $visible && filled($share->user?->avatar)
                            ? $share->user->avatar_url
                            : null,
                    ];
                })
                ->values(),
        ]);
    }

    /**
     * Chercher QUI partager.
     *
     * Meme forme que `DossierMemberController::search` — y compris la
     * recherche sur le nom complet dans les deux ordres, parce qu'on tape
     * « Marie Dupont » aussi souvent que « Dupont Marie ».
     *
     * Deux exclusions, et deux seulement : soi-meme (se partager un fichier
     * qu'on gere deja n'ouvre rien) et les personnes DEJA destinataires
     * (proposer un partage qui existe ferait echouer l'unicite, et surtout ne
     * dirait pas pourquoi).
     *
     * ## L'email CHERCHE, il ne s'AFFICHE pas
     *
     * Signale par Cyril le 24/10, capture a l'appui : l'ecran listait l'adresse
     * de chaque personne proposee. Trois lettres tapees rendaient donc dix
     * adresses, et le gestionnaire d'un seul fichier pouvait moissonner
     * l'annuaire complet de l'Organization — un usage que rien, dans le partage
     * d'un fichier, ne demande.
     *
     * La correction est posee ICI, pas dans la vue : masquer la colonne en
     * Blade aurait laisse l'adresse partir dans la reponse JSON, lisible dans
     * l'onglet reseau du navigateur. Une donnee qu'on ne veut pas montrer ne
     * doit pas QUITTER le serveur.
     *
     * `LIKE` sur `email` reste dans la clause WHERE : on cherche toujours par
     * adresse, c'est l'arbitrage explicite de Cyril. Ce que cela laisse est
     * une VERIFICATION (« telle adresse est-elle membre ? »), une adresse a la
     * fois et connue d'avance — pas une ENUMERATION.
     */
    public function search(Request $request): JsonResponse
    {
        $file = $this->fichierGerable($request);

        $recherche = preg_replace('/\s+/', ' ', trim((string) $request->input('q', '')));
        $motif = '%'.$recherche.'%';
        $dejaPartages = $file->shares()->pluck('user_id')->all();

        $users = User::assignable()
            ->where('organization_id', $file->organization_id)
            ->where('id', '!=', $request->user()->id)
            ->whereNotIn('id', $dejaPartages)
            ->where(function ($q) use ($motif) {
                $q->whereRaw('LOWER(name) LIKE LOWER(?)', [$motif])
                    ->orWhereRaw('LOWER(first_name) LIKE LOWER(?)', [$motif])
                    ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$motif])
                    ->orWhereRaw("LOWER(first_name || ' ' || name) LIKE LOWER(?)", [$motif])
                    ->orWhereRaw("LOWER(name || ' ' || first_name) LIKE LOWER(?)", [$motif]);
            })
            ->limit(10)
            ->get(['id', 'name', 'first_name']);

        return response()->json(['users' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $file = $this->fichierGerable($request);

        $data = $request->validate([
            'user_id' => [
                'required',
                'uuid',
                // La personne doit appartenir a l'Organization DU FICHIER.
                // Borner sur l'Organization courante aurait suffi tant que les
                // deux coincident — et aurait cesse de suffire le jour ou elles
                // divergent, sans que rien ne le dise.
                Rule::exists('users', 'id')->where('organization_id', $file->organization_id),
            ],
        ]);

        if ($data['user_id'] === $request->user()->id) {
            return response()->json(['message' => __('dossiers.share_self')], 422);
        }

        $share = DossierFileShare::query()->firstOrCreate(
            [
                'dossier_file_id' => $file->id,
                'user_id' => $data['user_id'],
            ],
            [
                // Du FICHIER, pas du contexte : voir le docblock de la classe.
                'organization_id' => $file->organization_id,
                'shared_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'share' => ['id' => $share->id, 'user_id' => $share->user_id],
            'created' => $share->wasRecentlyCreated,
        ], $share->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Retirer un partage.
     *
     * La ligne doit appartenir a CE fichier : sans cette verification, un
     * identifiant de partage valide suffirait a revoquer l'acces a un fichier
     * qu'on ne gere pas.
     */
    public function destroy(Request $request): JsonResponse
    {
        $file = $this->fichierGerable($request);

        $share = $file->shares()->whereKey($request->route('share'))->first();

        if (! $share) {
            abort(404);
        }

        $share->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Le fichier, une fois le droit de LE GERER verifie — et jamais avant.
     *
     * `withoutTrashed()` : on ne partage pas, et on ne revoque pas sur, un
     * fichier supprime. Il repond 404 — « il n'y a rien la ».
     */
    private function fichierGerable(Request $request): DossierFile
    {
        $file = DossierFile::query()
            ->withoutTrashed()
            ->whereKey($request->route('file'))
            ->firstOrFail();

        $this->authorize('manageFiles', $file);

        return $file;
    }
}
