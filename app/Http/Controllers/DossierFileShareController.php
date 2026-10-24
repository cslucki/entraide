<?php

namespace App\Http\Controllers;

use App\Models\DossierFile;
use App\Models\DossierFileLoopShare;
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
     *
     * ## Lisible par le DESTINATAIRE, pas seulement par qui gere
     *
     * TASK-1695 (point 7 de Cyril). J'avais d'abord refuse ce panneau au
     * destinataire, en jugeant qu'une fenetre sans aucune action serait une
     * promesse vide. Arbitrage de Cyril : il veut le meme geste des deux cotes.
     *
     * La borne descend donc a `viewFiles` — qui a le fichier voit avec qui il
     * est partage, comme dans un Drive. **Consequence assumee** : un
     * destinataire apprend les noms des autres destinataires. C'est un
     * elargissement reel, limite aux noms (aucun email, voir `search()`).
     *
     * `can_manage` voyage avec la reponse : l'ecran s'en sert pour retirer
     * « Retirer » et « Ajouter des personnes ». ECRIRE reste `manageFiles` —
     * `search()`, `store()` et `destroy()` n'ont pas bouge.
     */
    public function index(Request $request): JsonResponse
    {
        $file = $this->fichierLisible($request);

        return response()->json([
            'can_manage' => $request->user()->can('manageFiles', $file),
            // TASK-1695 (5a) — la meme fenetre montre les DEUX natures de
            // partage. Les Boucles proposees sont celles dont JE suis membre
            // actif (decision de Cyril : « la liste des boucles dont on est
            // membre ») et vivantes — on ne partage pas vers une archive, qui
            // est en lecture seule.
            'loop_shares' => $file->loopShares()
                ->where('organization_id', $file->organization_id)
                ->with('loop:id,name,organization_id')
                ->get()
                ->map(fn (DossierFileLoopShare $share) => [
                    'id' => $share->id,
                    'loop_id' => $share->loop_id,
                    'name' => $share->loop?->name,
                ])
                ->values(),
            'loops' => \App\Models\Loop::query()
                ->where('organization_id', $file->organization_id)
                ->where('status', '!=', 'archived')
                ->whereHas('activeMembers', fn ($q) => $q->where('user_id', $request->user()->id))
                ->whereNotIn('id', $file->loopShares()->pluck('loop_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
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
     * TASK-1695 (5a) — partager le fichier avec une BOUCLE.
     *
     * Trois bornes, chacune refusant un chemin reel :
     *
     * - la Boucle appartient a l'Organization DU FICHIER (jamais au contexte) ;
     * - le partageur en est membre ACTIF — « la liste des boucles dont on est
     *   membre » (Cyril) : on n'expose pas un fichier a une assemblee dont on
     *   ne fait pas partie, meme en gerant le fichier ;
     * - la Boucle est vivante : une archive est en lecture seule.
     */
    public function storeLoop(Request $request): JsonResponse
    {
        $file = $this->fichierGerable($request);

        $data = $request->validate([
            'loop_id' => [
                'required',
                'uuid',
                Rule::exists('loops', 'id')
                    ->where('organization_id', $file->organization_id)
                    ->whereNot('status', 'archived'),
            ],
        ]);

        $membreActif = \App\Models\LoopMember::query()
            ->where('loop_id', $data['loop_id'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->exists();

        if (! $membreActif) {
            return response()->json(['message' => __('dossiers.loop_share_not_member')], 422);
        }

        $share = DossierFileLoopShare::query()->firstOrCreate(
            [
                'dossier_file_id' => $file->id,
                'loop_id' => $data['loop_id'],
            ],
            [
                // Du FICHIER, pas du contexte : voir le docblock de la classe.
                'organization_id' => $file->organization_id,
                'shared_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'share' => ['id' => $share->id, 'loop_id' => $share->loop_id],
            'created' => $share->wasRecentlyCreated,
        ], $share->wasRecentlyCreated ? 201 : 200);
    }

    /** Meme borne que `destroy` : la ligne doit appartenir a CE fichier. */
    public function destroyLoop(Request $request): JsonResponse
    {
        $file = $this->fichierGerable($request);

        $share = $file->loopShares()->whereKey($request->route('share'))->first();

        if (! $share) {
            abort(404);
        }

        $share->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Le fichier, une fois le droit de LE LIRE verifie.
     *
     * TASK-1695 (point 7) — `viewFiles` couvre le proprietaire, les
     * destinataires nominatifs ET les membres d'une Boucle destinataire : la
     * policy repond a la question « cette personne a-t-elle ce fichier ? ».
     */
    private function fichierLisible(Request $request): DossierFile
    {
        $file = DossierFile::query()
            ->withoutTrashed()
            ->whereKey($request->route('file'))
            ->firstOrFail();

        $this->authorize('viewFiles', $file);

        return $file;
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
