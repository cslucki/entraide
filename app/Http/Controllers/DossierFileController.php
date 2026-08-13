<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDossierFileRequest;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $sortParam = $request->input('sort', 'date');
        $directionParam = $request->input('direction', 'desc');
        $search = $request->input('search', '');

        $column = $sortAllowlist[$sortParam] ?? 'created_at';
        $direction = in_array(strtolower($directionParam), ['asc', 'desc']) ? strtolower($directionParam) : 'desc';

        $query = DossierFile::query()
            ->where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id)
            ->with('uploader:id,organization_id,first_name,avatar,name,email,banned_at');

        if ($search !== '') {
            $searchTerm = trim($search);
            $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($searchTerm, $likeOperator) {
                $q->where('display_name', $likeOperator, "%{$searchTerm}%")
                    ->orWhere('original_name', $likeOperator, "%{$searchTerm}%");
            });
        }

        $query->orderBy($column, $direction)
            ->orderBy('created_at', 'desc');

        $files = $query->paginate(20);
        $files->getCollection()->transform(fn (DossierFile $file) => array_merge(
            $file->toArray(),
            ['uploader' => $this->publicUserPayload($file->uploader, $organization->id)]
        ));

        $usedBytes = (int) DossierFile::query()
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->sum('size_bytes');

        return response()->json([
            'files' => $files,
            'quota' => [
                'used_bytes' => $usedBytes,
                'limit_bytes' => $organization->dossierStorageQuotaBytes(),
                'remaining_bytes' => $organization->dossierStorageRemainingBytes(),
            ],
        ]);
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

        try {
            if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
                return Storage::disk($file->disk)->download($file->path, $file->original_name, [
                    'Content-Type' => $file->mime_type,
                ]);
            }

            $url = Storage::disk($file->disk)->temporaryUrl($file->path, now()->addMinutes(30));

            return redirect()->away($url);
        } catch (\Exception $e) {
            abort(404);
        }
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

        try {
            if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
                return Storage::disk($file->disk)->download($file->path, $file->original_name, [
                    'Content-Type' => $file->mime_type,
                    'Content-Disposition' => 'inline; filename="'.$file->original_name.'"',
                ]);
            }

            $url = Storage::disk($file->disk)->temporaryUrl($file->path, now()->addMinutes(30));

            return redirect()->away($url);
        } catch (\Exception $e) {
            abort(404);
        }
    }

    public function destroy(Request $request): JsonResponse
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

        try {
            Storage::disk($file->disk)->delete($file->path);
        } catch (\Exception) {
            // Storage deletion failure doesn't prevent DB cleanup
        }

        $file->delete();

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
