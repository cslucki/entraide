<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDossierFileRequest;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\User;
use App\Services\Dossiers\DossierFileRemover;
use App\Services\Dossiers\DossierFileTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DossierFileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('viewFiles', $dossier);

        $sortAllowlist = [
            'name' => 'display_name',
            'size' => 'size_bytes',
            'date' => 'created_at',
        ];

        // TASK-1697 (2) — trier sur « Type », c'est-a-dire sur l'EXTENSION.
        //
        // La colonne affiche l'extension du nom lu (`typeDeFichier()` dans
        // `app.js`), pas le type MIME. Trier sur `mime_type` aurait rendu une
        // colonne visiblement desordonnee — « PDF » avant « DOCX », parce que
        // `application/pdf` precede `application/vnd.openxml…`. On trie donc
        // sur ce qui est AFFICHE.
        //
        // L'expression depend du moteur : il n'existe pas de fonction portable
        // pour la derniere extension. Meme forme de branchement que le `ilike`
        // de la recherche, quelques lignes plus bas.
        $nomLu = "COALESCE(NULLIF(display_name, ''), original_name)";
        $extension = DB::connection()->getDriverName() === 'pgsql'
            ? "LOWER(SUBSTRING($nomLu FROM '\\.([^.]*)$'))"
            // SQLite n'a ni SUBSTRING-regex ni SPLIT_PART : on retire du nom
            // tout ce qui precede le dernier point. `rtrim(nom, nom_sans_points)`
            // rend le prefixe jusqu'au dernier point inclus.
            : "LOWER(REPLACE($nomLu, RTRIM($nomLu, REPLACE($nomLu, '.', '')), ''))";
        $sortParam = $request->input('sort', 'date');
        $directionParam = $request->input('direction', 'desc');
        $search = $request->input('search', '');

        $column = $sortAllowlist[$sortParam] ?? 'created_at';
        $triParExtension = $sortParam === 'type';
        $direction = in_array(strtolower($directionParam), ['asc', 'desc']) ? strtolower($directionParam) : 'desc';

        $query = DossierFile::query()
            // TASK-1695 (recette de Cyril, 24/10) — les fichiers PARTAGES avec
            // cette Boucle entrent dans LA MEME liste.
            //
            // J'avais d'abord pose une section a part, sous le tableau. Cyril :
            // « ce ne sont pas des fichiers a part ». Il a raison — une seconde
            // surface donnait a croire a un second type de partage, alors que
            // le composant qui sait lister, trier, chercher et paginer des
            // fichiers existait deja.
            //
            // La clause reste UNE SEULE requete : tri, recherche et pagination
            // continuent de fonctionner sans rien de nouveau. Un `union` ou une
            // fusion en PHP aurait casse les trois.
            ->where(function ($q) use ($dossier) {
                $q->where('dossier_id', $dossier->id);

                // `isLoopDossier()` EST `loop_id !== null` — je l'avais ecrit
                // deux fois. Mesure du 24/10 : lever la garde nommee ne fait
                // rougir aucun test, parce qu'un `loop_id` NULL ne matche
                // jamais. La forme nommee reste, seule : elle dit l'intention.
                if ($dossier->isLoopDossier()) {
                    $q->orWhereHas('loopShares', fn ($s) => $s
                        ->where('loop_id', $dossier->loop_id)
                        ->where('organization_id', $dossier->organization_id));
                }
            })
            ->where('organization_id', $organization->id)
            ->with('uploader:id,organization_id,first_name,avatar,name,email,banned_at')
            // TASK-1695 (point 1 de Cyril) — la colonne « Partage » doit dire
            // l'etat DE CETTE LIGNE. Elle affichait `$partageHerite`, une
            // valeur calculee une seule fois pour tout le Dossier : un fichier
            // partage y lisait « Prive ». Le compte voyage donc avec le
            // fichier, puisque les lignes sont rendues en Alpine depuis ce
            // JSON.
            // Les DEUX natures : « Partage » doit s'allumer aussi pour un
            // fichier partage seulement vers une Boucle (5a).
            ->withCount(['shares', 'loopShares']);

        if ($search !== '') {
            $searchTerm = trim($search);
            $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($searchTerm, $likeOperator) {
                $q->where('display_name', $likeOperator, "%{$searchTerm}%")
                    ->orWhere('original_name', $likeOperator, "%{$searchTerm}%");
            });
        }

        if ($triParExtension) {
            $query->orderByRaw("$extension $direction");
        } else {
            $query->orderBy($column, $direction);
        }

        // Le second critere, toujours : deux fichiers de meme type ou de meme
        // taille gardent sinon un ordre arbitraire, qui change d'une page a
        // l'autre et fait « sauter » des lignes a la pagination.
        $query->orderBy('created_at', 'desc');

        $files = $query->paginate(20);
        $files->getCollection()->transform(fn (DossierFile $file) => array_merge(
            $file->toArray(),
            // `shares_count` n'est PAS recopie ici : `withCount('shares')`
            // l'expose deja via `toArray()`. Mesure du 24/10 — retirer la
            // recopie ne fait rougir aucun test, retirer le `withCount()` en
            // fait rougir un. La garde est le `withCount()`, pas la recopie.
            [
                'uploader' => $this->publicUserPayload($file->uploader, $organization->id),
                // Une ligne venue d'ailleurs : elle se LIT ici, elle ne s'y
                // administre pas. L'ecran s'en sert pour retirer les gestes
                // que le serveur refuserait de toute facon.
                'is_shared_here' => $file->dossier_id !== $dossier->id,
            ]
        ));

        $usedBytes = (int) DossierFile::query()
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->sum('size_bytes');

        // `no-store`, et pas seulement le `no-cache, private` par defaut de
        // Laravel : cette liste change a chaque import, deplacement ou
        // suppression, et `no-cache` autorise le navigateur a CONSERVER la
        // reponse. Chromium la resservait telle quelle a la relecture qui suit
        // une ecriture — meme URL, meme en-tete `Date` — et l'ecran affichait
        // l'etat d'avant : les fichiers importes restaient invisibles jusqu'a
        // un rafraichissement, et ceux qu'on venait de deplacer revenaient.
        // L'option `cache: 'no-store'` posee cote client ne suffisait pas ; la
        // regle appartient a la reponse, qui seule sait qu'elle est perissable.
        return response()->json([
            'files' => $files,
            'quota' => [
                'used_bytes' => $usedBytes,
                'limit_bytes' => $organization->dossierStorageQuotaBytes(),
                'remaining_bytes' => $organization->dossierStorageRemainingBytes(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreDossierFileRequest $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        $uploadedFiles = $request->file('files');
        $disk = 'dossier_files';
        $createdFiles = [];
        $storedPaths = [];

        DB::beginTransaction();

        try {
            $quota = $organization->dossierStorageQuotaBytes();
            $incomingFiles = collect($uploadedFiles)->map(fn ($file) => [
                'file' => $file,
                'name' => $file->getClientOriginalName(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
            ]);

            if ($incomingFiles->pluck('name')->duplicates()->isNotEmpty()) {
                DB::rollBack();

                return response()->json([
                    'message' => __('dossiers.file_duplicate_name'),
                ], 422);
            }

            if ($incomingFiles->pluck('checksum')->duplicates()->isNotEmpty()) {
                DB::rollBack();

                return response()->json([
                    'message' => __('dossiers.file_duplicate_content'),
                ], 422);
            }

            $duplicateByName = DossierFile::query()
                ->where('organization_id', $organization->id)
                ->where('dossier_id', $dossier->id)
                ->whereIn('original_name', $incomingFiles->pluck('name')->all())
                ->lockForUpdate()
                ->exists();

            if ($duplicateByName) {
                DB::rollBack();

                return response()->json([
                    'message' => __('dossiers.file_duplicate_name'),
                ], 422);
            }

            $duplicateByChecksum = DossierFile::query()
                ->where('organization_id', $organization->id)
                ->where('dossier_id', $dossier->id)
                ->whereIn('checksum_sha256', $incomingFiles->pluck('checksum')->all())
                ->lockForUpdate()
                ->exists();

            if ($duplicateByChecksum) {
                DB::rollBack();

                return response()->json([
                    'message' => __('dossiers.file_duplicate_content'),
                ], 422);
            }

            if ($quota !== null) {
                $usedBytes = (int) DossierFile::query()
                    ->where('organization_id', $organization->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->sum('size_bytes');

                $newTotalBytes = $usedBytes;
                foreach ($incomingFiles as $incomingFile) {
                    $newTotalBytes += $incomingFile['file']->getSize();
                }

                if ($newTotalBytes > $quota) {
                    DB::rollBack();

                    return response()->json([
                        'message' => __('dossiers.storage_quota_exceeded'),
                    ], 422);
                }
            }

            foreach ($incomingFiles as $incomingFile) {
                $file = $incomingFile['file'];
                $path = $file->store('dossier-files/'.$dossier->id, $disk);
                $storedPaths[] = $path;

                $dossierFile = DossierFile::create([
                    'organization_id' => $organization->id,
                    'dossier_id' => $dossier->id,
                    'uploaded_by' => $request->user()->id,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'display_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'checksum_sha256' => $incomingFile['checksum'],
                    'source' => 'upload',
                ]);

                $dossierFile->load('uploader:id,organization_id,first_name,avatar,name,email,banned_at');
                $createdFiles[] = array_merge(
                    $dossierFile->toArray(),
                    ['uploader' => $this->publicUserPayload($dossierFile->uploader, $organization->id)]
                );
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            foreach ($storedPaths as $storedPath) {
                try {
                    Storage::disk($disk)->delete($storedPath);
                } catch (\Exception) {
                    // Storage cleanup failure logged but not rethrown
                }
            }

            return response()->json([
                'message' => __('dossiers.file_upload_failed'),
            ], 500);
        }

        return response()->json([
            'message' => __('dossiers.file_uploaded'),
            'files' => $createdFiles,
        ], 201);
    }

    public function show(Request $request): RedirectResponse|StreamedResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $file = $this->resolveFile($request->route('file'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        if ($file->dossier_id !== $dossier->id || $file->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('viewFiles', $dossier);

        // TASK-1695 geste C — le transport a ete EXTRAIT dans
        // `DossierFileTransport` : le partage de fichier a ses propres routes de
        // lecture, et servir le fichier est exactement le meme geste des deux
        // cotes. Le recopier aurait donne deux regles pour une idee.
        return app(DossierFileTransport::class)->download($file);
    }

    public function preview(Request $request): RedirectResponse|StreamedResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $file = $this->resolveFile($request->route('file'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        if ($file->dossier_id !== $dossier->id || $file->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('viewFiles', $dossier);

        // TASK-1695 geste C — meme transport partage (voir `show()`).
        return app(DossierFileTransport::class)->preview($file);
    }

    // TASK-1695 geste C — `typeAffichable()` vit desormais dans
    // `DossierFileTransport`, aupres du seul code qui s'en sert.

    public function destroy(Request $request, DossierFileRemover $remover): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $file = $this->resolveFile($request->route('file'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        if ($file->dossier_id !== $dossier->id || $file->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('deleteFile', $dossier);

        // TASK-1515 : le geste lui-meme vit dans `DossierFileRemover`. La
        // console de plateforme (`/admin/drives`) appelle EXACTEMENT le meme
        // service : deux implementations divergeraient au premier changement.
        $remover->remove($file);

        return response()->json([
            'message' => __('dossiers.file_deleted'),
        ]);
    }

    /**
     * Deplacer un fichier vers un autre Dossier (TASK-1130 passe 4).
     *
     * Deux droits distincts, pas un seul : `deleteFile` sur la source (retirer
     * le fichier d'ici est le meme geste que le supprimer d'ici) et
     * `manageFiles` sur la cible (y deposer un fichier est le meme geste que
     * l'y importer). Un utilisateur qui peut vider un Dossier mais pas
     * remplir l'autre ne doit reussir qu'a moitie le geste, jamais silencieusement.
     */
    /**
     * Renommer un fichier — son libelle, jamais son fichier sur le disque.
     *
     * `display_name` est ce que la personne lit ; `original_name` reste la
     * trace de ce qui a ete depose, et `path` n'est pas touche : renommer ne
     * doit pas pouvoir casser un telechargement. L'extension d'origine est
     * conservee, pour que le fichier reste ouvrable par le bon logiciel.
     */
    public function rename(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $file = $this->resolveFile($request->route('file'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        if ($file->dossier_id !== $dossier->id || $file->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('manageFiles', $dossier);

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
        ]);

        $extension = pathinfo($file->original_name, PATHINFO_EXTENSION);
        $nom = trim($data['display_name']);

        if ($extension !== '' && ! Str::endsWith(Str::lower($nom), '.'.Str::lower($extension))) {
            $nom .= '.'.$extension;
        }

        $doublon = DossierFile::query()
            ->where('organization_id', $organization->id)
            ->where('dossier_id', $dossier->id)
            ->where('display_name', $nom)
            ->whereKeyNot($file->getKey())
            ->exists();

        if ($doublon) {
            return response()->json(['message' => __('dossiers.file_duplicate_name')], 422);
        }

        $file->update(['display_name' => $nom]);

        return response()->json([
            'file' => $file->fresh(),
            'message' => __('dossiers.file_renamed'),
        ]);
    }

    /**
     * Lire le contenu d'une note Markdown, pour le rouvrir dans l'editeur.
     *
     * Distinct de `preview`, qui renvoie un telechargement `inline` : ici on
     * rend du JSON, et **seulement** pour du Markdown. Le reste du Drive n'a
     * pas a devenir lisible en texte par un endpoint generique.
     */
    public function markdown(Request $request): JsonResponse
    {
        [$dossier, $file] = $this->resolveMarkdownFile($request);

        $this->authorize('viewFiles', $dossier);

        try {
            $contenu = Storage::disk($file->disk)->get($file->path);
        } catch (\Exception $e) {
            abort(404);
        }

        return response()->json([
            'content' => $contenu ?? '',
            'display_name' => $file->display_name,
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Reecrire le contenu d'une note Markdown, dans le MEME DossierFile.
     *
     * Ce que cet endpoint ne fait pas, et qui est tout l'enjeu : il ne cree
     * aucune seconde ligne, ne touche ni `dossier_id`, ni `id`, ni le nom, ni
     * l'appartenance a une Serie, ni le tenant. Il ecrit sur le meme `path`,
     * puis recalcule ce qui decrit le contenu — taille, empreinte — sans quoi
     * la ligne mentirait sur le fichier qu'elle designe.
     *
     * Borne au Markdown : `resolveMarkdownFile()` refuse tout autre type. Un
     * endpoint generique d'ecriture de contenu serait une autre decision.
     */
    public function updateMarkdown(Request $request): JsonResponse
    {
        [$dossier, $file, $organization] = $this->resolveMarkdownFile($request, avecOrganisation: true);

        $this->authorize('manageFiles', $dossier);

        $data = $request->validate([
            // Une note peut legitimement etre videe. `present` plutot que
            // `required`, qui refuserait le vide — et `nullable` parce que
            // `ConvertEmptyStringsToNull`, actif sur toute l'application,
            // transforme la chaine vide en null avant d'arriver ici.
            'content' => ['present', 'nullable', 'string', 'max:1048576'],
        ]);

        $contenu = (string) ($data['content'] ?? '');
        $taille = strlen($contenu);
        $empreinte = hash('sha256', $contenu);

        if ($empreinte === $file->checksum_sha256) {
            // Rien n'a change : ne pas reecrire le disque ni toucher
            // `updated_at`, qui ferait mentir la colonne « Modifie le ».
            return response()->json([
                'file' => $this->publicFilePayload($file, $organization),
                'message' => __('dossiers.markdown_updated'),
            ]);
        }

        // Le meme garde que l'import : deux fichiers de contenu identique dans
        // un meme Dossier n'ont pas de raison d'exister.
        $doublon = DossierFile::query()
            ->where('organization_id', $organization->id)
            ->where('dossier_id', $dossier->id)
            ->where('checksum_sha256', $empreinte)
            ->whereKeyNot($file->getKey())
            ->exists();

        if ($doublon) {
            return response()->json(['message' => __('dossiers.file_duplicate_content')], 422);
        }

        // Le quota ne compte que le DELTA : une note qui maigrit ne doit pas
        // pouvoir etre refusee parce que l'Organization est pleine.
        $quota = $organization->dossierStorageQuotaBytes();
        $delta = $taille - (int) $file->size_bytes;

        if ($quota !== null && $delta > 0) {
            $utilise = (int) DossierFile::query()
                ->where('organization_id', $organization->id)
                ->whereNull('deleted_at')
                ->sum('size_bytes');

            if ($utilise + $delta > $quota) {
                return response()->json(['message' => __('dossiers.storage_quota_exceeded')], 422);
            }
        }

        try {
            Storage::disk($file->disk)->put($file->path, $contenu);
        } catch (\Exception $e) {
            return response()->json(['message' => __('dossiers.markdown_update_failed')], 500);
        }

        $file->update([
            'size_bytes' => $taille,
            'checksum_sha256' => $empreinte,
        ]);

        return response()->json([
            'file' => $this->publicFilePayload($file->fresh(), $organization),
            'message' => __('dossiers.markdown_updated'),
        ]);
    }

    /**
     * Le Dossier, le fichier, et la garantie que ce fichier est du Markdown
     * de CE Dossier dans CE tenant. Les deux endpoints Markdown partagent
     * exactement les memes refus.
     *
     * @return array{0: Dossier, 1: DossierFile, 2?: mixed}
     */
    private function resolveMarkdownFile(Request $request, bool $avecOrganisation = false): array
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $file = $this->resolveFile($request->route('file'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        if ($file->dossier_id !== $dossier->id || $file->organization_id !== $organization->id) {
            abort(404);
        }

        // Le type se lit sur le MIME et sur l'extension : un fichier importe
        // depuis un poste Windows arrive parfois en `text/plain`, et l'ecran
        // le presente pourtant comme une note.
        $extension = Str::lower(pathinfo((string) $file->original_name, PATHINFO_EXTENSION));

        if ($file->mime_type !== 'text/markdown' && ! in_array($extension, ['md', 'markdown'], true)) {
            abort(404);
        }

        return $avecOrganisation ? [$dossier, $file, $organization] : [$dossier, $file];
    }

    private function publicFilePayload(DossierFile $file, $organization): array
    {
        $file->loadMissing('uploader:id,organization_id,first_name,avatar,name,email,banned_at');

        return array_merge(
            $file->toArray(),
            ['uploader' => $this->publicUserPayload($file->uploader, $organization->id)]
        );
    }

    public function move(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $file = $this->resolveFile($request->route('file'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        if ($file->dossier_id !== $dossier->id || $file->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('deleteFile', $dossier);

        $data = $request->validate([
            'target_dossier_id' => ['required', 'string'],
        ]);

        $target = Dossier::where('id', $data['target_dossier_id'])
            ->where('organization_id', $organization->id)
            ->first();

        if (! $target) {
            // Hors du tenant courant ou inexistant : meme reponse, aucune fuite
            // d'information sur ce qui existe ailleurs.
            return response()->json(['message' => __('dossiers.move_cross_organization_refused')], 404);
        }

        $this->authorize('manageFiles', $target);

        if ($target->id === $dossier->id) {
            return response()->json(['message' => __('dossiers.file_move_same_dossier')], 422);
        }

        $duplicate = DossierFile::query()
            ->where('organization_id', $organization->id)
            ->where('dossier_id', $target->id)
            ->where('original_name', $file->original_name)
            ->exists();

        if ($duplicate) {
            return response()->json(['message' => __('dossiers.file_duplicate_name')], 422);
        }

        $file->update(['dossier_id' => $target->id]);

        return response()->json([
            'message' => __('dossiers.file_moved'),
            'file' => ['id' => $file->id, 'dossier_id' => $target->id],
        ]);
    }

    private function resolveDossier(string $dossier): Dossier
    {
        return Dossier::query()->whereKey($dossier)->firstOrFail();
    }

    private function resolveFile(string $file): DossierFile
    {
        return DossierFile::query()->whereKey($file)->firstOrFail();
    }

    private function currentOrganizationOrFail()
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        return $organization;
    }

    private function ensureDossierBelongsToCurrentOrganization(Dossier $dossier): void
    {
        $organization = $this->currentOrganizationOrFail();

        if ($dossier->organization_id !== $organization->id) {
            abort(404);
        }
    }

    private function ensureCurrentUserBelongsToCurrentOrganization(): void
    {
        $organization = currentOrganization();
        $user = auth()->user();

        if (! $organization || ! $user || $user->organization_id !== $organization->id) {
            abort(404);
        }
    }

    private function publicUserPayload(?User $user, ?string $organizationId): ?array
    {
        if (! $user) {
            return null;
        }

        if (! $user->isDisplayableIn($organizationId)) {
            return [
                'id' => null,
                'name' => __('profile.deactivated_user'),
                'email' => null,
            ];
        }

        return [
            'id' => $user->id,
            'name' => $user->fullName,
            'email' => $user->email,
            // Le visage seulement s'il existe vraiment : `avatar_url` retombe
            // sinon sur un service tiers, a qui on enverrait le nom de la
            // personne pour dessiner deux lettres qu'on sait dessiner ici.
            'avatar_url' => filled($user->avatar) ? $user->avatar_url : null,
        ];
    }
}
